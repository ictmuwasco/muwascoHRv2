<?php
/**
 * Fix accounts whose `users.role` is empty/NULL.
 *
 * WHY: authorization (AuthorizationService) resolves permissions from the
 * `users.role` column ONLY. A blank role falls through to the final
 * "no rule matched -> DEFAULT DENY" branch, so the account receives ZERO
 * permissions and every guarded page renders "Access denied".
 *
 * `designation` (e.g. "ICT Officer") is a cosmetic job title and is never
 * consulted by the authorization engine — three other users with the exact
 * same "ICT Officer" designation work fine because their role is 'officer'.
 *
 * Usage:
 *   php tools/fix-roleless-roles.php                 # dry run, report only
 *   php tools/fix-roleless-roles.php --user=276 --apply
 *   php tools/fix-roleless-roles.php --all --apply
 *
 * Uses UserService::updateUserRole() — the same validated path as the admin
 * UI (assertValidRole against the roles table), never raw SQL.
 */
declare(strict_types=1);

require_once __DIR__ . '/../backend/bootstrap.php';

use App\Services\UserService;
use App\Repositories\UserRepository;
use App\Repositories\EmployeeRepository;
use App\Helpers\AuthorizationService;
use App\Helpers\Database;

$argApply = in_array('--apply', $argv, true);
$argAll   = in_array('--all', $argv, true);
$targetId = null;
foreach ($argv as $a) {
    if (preg_match('/^--user=(\d+)$/', $a, $m)) {
        $targetId = (int) $m[1];
    }
}

$db   = Database::getInstance()->getConnection();
$auth = AuthorizationService::getInstance();

$userService = new UserService();
$userService->setUserRepository(new UserRepository());
$userService->setEmployeeRepository(new EmployeeRepository());

/** Which catalog role to assign. 'officer' = baseline employee access,
 *  identical to the other 133 officers (incl. the 3 other ICT Officers). */
const FIX_ROLE = 'officer';

echo "=== Roleless accounts: plan ===\n";
echo "  mode      : " . ($argApply ? 'APPLY (will write)' : 'DRY RUN (no writes)') . "\n";
echo "  target    : " . ($argAll ? 'ALL roleless accounts' : ($targetId ? "user #{$targetId}" : 'report only')) . "\n";
echo "  fix role  : " . FIX_ROLE . "\n\n";

$sql = "SELECT id, first_name, last_name, designation, role, is_active
        FROM users WHERE role IS NULL OR TRIM(role) = ''";
if ($targetId) $sql .= " AND id = " . (int) $targetId;
$sql .= " ORDER BY id";

$res  = $db->query($sql);
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;

if (!$rows) {
    echo "  No roleless accounts matched. Nothing to do.\n";
    exit(0);
}

printf("  %-6s %-24s %-26s %-10s %s\n", 'id', 'name', 'designation', 'permissions', 'action');
echo '  ' . str_repeat('-', 96) . "\n";

$applied = 0;
$failed  = 0;
foreach ($rows as $u) {
    $uid = (int) $u['id'];
    $auth->clearCache();
    $before = count($auth->getEffectivePermissionStrings($uid));

    $action = 'skip (dry run)';
    if ($argApply) {
        try {
            $userService->updateUserRole($uid, FIX_ROLE);
            $action = "SET role='" . FIX_ROLE . "'";
            $applied++;
        } catch (\Throwable $e) {
            $action = 'FAILED: ' . $e->getMessage();
            $failed++;
        }
    } else {
        $action = "would SET role='" . FIX_ROLE . "'";
    }

    $auth->clearCache();
    $after = count($auth->getEffectivePermissionStrings($uid));

    printf("  %-6s %-24s %-26s %-10s %s\n",
        $uid,
        trim($u['first_name'] . ' ' . $u['last_name']),
        $u['designation'] ?: '-',
        $before . ' -> ' . $after,
        $action);
}

echo "\n  total matched = " . count($rows) . ", applied = {$applied}, failed = {$failed}\n";

echo "\n=== Post-fix dashboard:view verification ===\n";
foreach ($rows as $u) {
    $uid = (int) $u['id'];
    $auth->clearCache();
    $eff = $auth->getEffectivePermission($uid, 'dashboard', 'view');
    printf("  #%-5s %-24s dashboard:view allowed=%-6s source=%s\n",
        $uid,
        trim($u['first_name'] . ' ' . $u['last_name']),
        var_export($eff['allowed'], true),
        $eff['source']);
}

echo "\n  Remaining ACTIVE roleless accounts: ";
$r = $db->query("SELECT COUNT(*) c FROM users WHERE (role IS NULL OR TRIM(role)='') AND is_active=1");
echo $r->fetch_assoc()['c'] . "\n";
