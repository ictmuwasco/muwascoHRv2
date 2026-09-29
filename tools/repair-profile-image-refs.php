<?php
/**
 * Repair dangling employees.profile_image_url values.
 *
 * WHY: 36 of the 39 employees carrying an image path point at a file that is
 * not on disk. The header renders <img src="/profile/profile-image"> for any
 * employee whose column is set, so every one of those staff produced a 404 in
 * the browser console on every page load - and the avatar still showed initials,
 * because the frontend's onError fallback was already doing the right thing.
 *
 * The rows are lying rather than merely broken: the column claims an image that
 * no longer exists. Clearing it makes the data honest and lets the existing
 * initials fallback take over.
 *
 * SAFETY:
 *   - Only paths that resolve to NO file are cleared. A resolvable path is
 *     never touched, so nobody loses an image that actually works.
 *   - Every cleared value is written to a JSON backup first, so the change is
 *     reversible with --restore.
 *   - Files present on disk but unreferenced are REPORTED, never deleted: the
 *     DB may simply be stale rather than the files being wrong.
 *
 * Usage:  php tools/repair-profile-image-refs.php [--dry-run] [--restore]
 */
require __DIR__ . '/../backend/bootstrap.php';

$backupFile = __DIR__ . '/.profile-image-backup.json';
$dryRun = in_array('--dry-run', $argv, true);
$restore = in_array('--restore', $argv, true);

$db = \App\Helpers\Database::getInstance()->getConnection();

// Mirror EmployeeController::streamProfileImage() EXACTLY. An earlier check
// guessed `backend/public` and wrongly reported every file as missing; the
// controller resolves `backend/app/Controllers/Employee/../../public`.
$controllerDir = realpath(__DIR__ . '/../backend/app/Controllers/Employee');
$publicBase = $controllerDir . '/../../public/';
$imageDir = $publicBase . 'uploads/profile_images/';

/** Does this stored path resolve to a real file, via either lookup? */
$resolves = static function (string $relative) use ($publicBase): bool {
    return file_exists($publicBase . $relative)
        || file_exists(STORAGE_PATH . '/' . $relative);
};

if ($restore) {
    if (!is_file($backupFile)) {
        fwrite(STDERR, "No backup at {$backupFile} - nothing to restore.\n");
        exit(1);
    }
    $rows = json_decode((string) file_get_contents($backupFile), true);
    $stmt = $db->prepare('UPDATE employees SET profile_image_url = ? WHERE id = ?');
    $restored = 0;
    foreach ($rows as $row) {
        $value = $row['profile_image_url'];
        $id = (int) $row['id'];
        $stmt->bind_param('si', $value, $id);
        $stmt->execute();
        $restored += $stmt->affected_rows > 0 ? 1 : 0;
    }
    $stmt->close();
    echo "Restored {$restored} profile_image_url value(s) from backup.\n";
    exit(0);
}

$select = $db->query(
    "SELECT id, first_name, last_name, profile_image_url
     FROM employees
     WHERE profile_image_url IS NOT NULL AND profile_image_url <> ''"
);
$rows = $select->fetch_all(MYSQLI_ASSOC);
$select->close();

$dangling = [];
$referenced = [];
foreach ($rows as $row) {
    if ($resolves((string) $row['profile_image_url'])) {
        $referenced[] = (string) $row['profile_image_url'];
    } else {
        $dangling[] = [
            'id' => (int) $row['id'],
            'name' => trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')),
            'profile_image_url' => (string) $row['profile_image_url'],
        ];
    }
}

printf("employees with a stored path : %d\n", count($rows));
printf("  resolvable on disk         : %d\n", count($rows) - count($dangling));
printf("  DANGLING (file missing)    : %d\n", count($dangling));

// Orphaned files: present but no employee points at them. Reported only.
$onDisk = glob($imageDir . '*') ?: [];
$orphans = array_values(array_filter(
    array_map('basename', $onDisk),
    static fn (string $file): bool => !in_array('uploads/profile_images/' . $file, $referenced, true)
));
printf("files on disk                : %d\n", count($onDisk));
printf("  unreferenced (orphaned)    : %d  (left alone)\n", count($orphans));

if ($dryRun) {
    echo "\nDRY RUN - nothing was changed. Would clear:\n";
    foreach ($dangling as $d) {
        printf("  #%-4d %-28s %s\n", $d['id'], $d['name'], $d['profile_image_url']);
    }
    exit(0);
}

if ($dangling === []) {
    echo "\nNothing to repair.\n";
    exit(0);
}

// Back up BEFORE writing anything.
file_put_contents($backupFile, json_encode($dangling, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

$stmt = $db->prepare('UPDATE employees SET profile_image_url = NULL WHERE id = ?');
$cleared = 0;
foreach ($dangling as $d) {
    $id = $d['id'];
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $cleared += $stmt->affected_rows > 0 ? 1 : 0;
}
$stmt->close();

printf("\nCleared %d dangling value(s).\n", $cleared);
printf("Backup written to %s (restore with --restore).\n", $backupFile);

// Prove the repair from the database's own point of view.
$check = $db->query(
    "SELECT COUNT(*) AS c FROM employees
     WHERE profile_image_url IS NOT NULL AND profile_image_url <> ''"
);
$remaining = (int) ($check->fetch_assoc()['c'] ?? 0);
$check->close();
printf("employees still holding a path: %d (all resolve to a real file)\n", $remaining);
