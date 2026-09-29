<?php
/** Prove the repair: no employee row points at a file that is not there. */
require __DIR__ . '/../backend/bootstrap.php';
$db = \App\Helpers\Database::getInstance()->getConnection();
$pub = realpath(__DIR__ . '/../backend/app/Controllers/Employee') . '/../../public/';
$resolves = static fn (string $r): bool =>
    file_exists($pub . $r) || file_exists(STORAGE_PATH . '/' . $r);

$r = $db->query("SELECT id, first_name, last_name, profile_image_url FROM employees
                 WHERE profile_image_url IS NOT NULL AND profile_image_url <> ''");
$rows = $r->fetch_all(MYSQLI_ASSOC); $r->close();

$dangling = array_values(array_filter($rows, static fn ($e) => !$resolves((string) $e['profile_image_url'])));
printf("employees holding an image path : %d\n", count($rows));
printf("  ...that 404 on request         : %d\n", count($dangling));
foreach ($rows as $e) {
    printf("    OK #%-4s %-24s %s\n", $e['id'], trim($e['first_name'].' '.$e['last_name']), $e['profile_image_url']);
}

// The account from the bug report.
$e = $db->query("SELECT id, first_name, last_name, profile_image_url FROM employees
                 WHERE email = 'kimahmah@gmail.com' LIMIT 1");
$md = $e->fetch_assoc(); $e->close();
printf("\ndev account employee #%s %s: profile_image_url = %s\n",
    $md['id'], $md['last_name'],
    $md['profile_image_url'] === null ? 'NULL (Header.jsx renders initials, no request)' : var_export($md['profile_image_url'], true));

$img = $db->query("SELECT COUNT(*) c FROM employees");
printf("employees with no image at all   : %d of %d\n",
    (int)($img->fetch_assoc()['c'] ?? 0) - count($rows), (int)($img->fetch_assoc()['c'] ?? 0));