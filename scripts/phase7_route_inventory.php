<?php
// One-off Phase 7 helper: generate backend/docs/security/ROUTE_INVENTORY.md
// from api.php route registrations (method, path, permission, throttle).
// Run: php scripts/phase7_route_inventory.php
$source = file_get_contents(__DIR__ . '/../api.php');

// NOTE: \\\$ keeps a literal backslash-dollar in the pattern ($router).
$routePattern = "/\\\$router->add\(\s*'([A-Z]+)'\s*,\s*'([^']+)'/";

$rows = [];
foreach (preg_split('/\R/', $source) as $line) {
    if (!preg_match($routePattern, $line, $head)) {
        continue;
    }
    $permission = null;
    $throttle = null;
    if (preg_match("/,\s*'([a-z_]+:[a-z_]+)'\s*,\s*'(\d+:\d+)'\s*\)/", $line, $m)) {
        $permission = $m[1];
        $throttle = $m[2];
    } elseif (preg_match("/,\s*'([a-z_]+:[a-z_]+)'\s*\)/", $line, $m)) {
        $permission = $m[1];
    } elseif (preg_match("/,\s*null,\s*'(\d+:\d+)'\s*\)/", $line, $m)) {
        $throttle = $m[1];
    }
    $rows[] = [$head[1], $head[2], $permission, $throttle];
}

$public = [
    'POST /auth/login' => 'credential exchange (rate limited in controller, 5/15min per IP+account)',
    'POST /system/client-errors' => 'pre-login browser error collector',
    'GET /consent/status' => 'pre-session consent status',
];

// Build "METHOD /path" => group map from the reviewed allowlist config.
$allowlistMap = [];
$allowlistConfig = require __DIR__ . '/../backend/config/authz_allowlist.php';
foreach ($allowlistConfig as $group => $entries) {
    foreach ($entries as $entry) {
        $allowlistMap[$entry] = $group;
    }
}

$out = [];
$out[] = '# Route Security Inventory (Phase 7, generated)';
$out[] = '';
$out[] = 'Generated from `api.php` registrations + `backend/config/authz_allowlist.php`.';
$out[] = 'Every route requires authentication **except** the three public';
$out[] = 'allowlist routes below. Permissions are the server-defined catalog pairs';
$out[] = 'enforced by `AuthorizationMiddleware`; routes without a permission are';
$out[] = 'categorized by their reviewed allowlist group (see `authz_allowlist.php`';
$out[] = 'for the per-entry rationale).';
$out[] = '';
$out[] = '| Method | URI | Permission | Throttle | Access |';
$out[] = '|---|---|---|---|---|';
foreach ($rows as [$method, $path, $permission, $throttle]) {
    $routeKey = "$method $path";
    if (isset($public[$routeKey])) {
        $access = 'PUBLIC — ' . $public[$routeKey];
    } elseif ($permission !== null) {
        [$module] = explode(':', $permission, 2);
        $access = $permission . ' (RBAC: ' . $module . ')';
    } elseif (isset($allowlistMap[$routeKey])) {
        $access = 'authenticated-only — allowlist group: ' . $allowlistMap[$routeKey];
    } else {
        $access = 'authenticated-only (self-service/scope-enforced)';
    }
    $out[] = sprintf('| %s | `%s` | %s | %s | %s |', $method, $path, $permission ?? '—', $throttle ?? '—', $access);
}
$out[] = '';

$out[] = '## Notes';
$out[] = '';
$out[] = '- Public surface is exactly **3 routes** (pinned by `AuthenticationGateTest`).';
$out[] = '- `authenticated-only` self-service routes still enforce per-record scope';
$out[] = '  in the controller/service (IDOR pins in `IdorOwnershipEnforcementTest`).';
$out[] = '- Throttle format `max:windowSeconds`, enforced per authenticated user + IP';
$out[] = '  after the permission gate; governance list `backend/config/rate_limits.php`';
$out[] = '  is enforced by `RoutePermissionMapTest`.';
$out[] = '- Sensitive-data & audit requirements per endpoint: see module docs in';
$out[] = '  `docs/` (leave.md, attendance.md, meetings.md, reports.md, payroll.md,';
$out[] = '  audit) and `AUDIT_LOGGING.md`.';
$out[] = '';

file_put_contents(__DIR__ . '/../backend/docs/security/ROUTE_INVENTORY.md', implode("\n", $out));
echo 'Wrote ' . count($rows) . " routes to backend/docs/security/ROUTE_INVENTORY.md\n";