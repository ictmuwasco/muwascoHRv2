<?php
// Standalone .env validator - needs no Composer autoloader, so it can run on
// the production host BEFORE the first deploy (vendor/ does not exist yet).
//
// Checks the failure modes that actually break phpdotenv v5 at boot:
//   1. A line that is not KEY=VALUE
//   2. An UNQUOTED value containing whitespace -> InvalidFileException, and
//      that kills the WHOLE file, not just that one key
//   3. A duplicate key (last one silently wins)
//   4. A trailing-whitespace-only value
//
// Also reports the key count and flags near-miss key names, which is how
// VAPID_SUBJEC (missing T) shipped once.

$path = $argv[1] ?? '/var/www/private/hrdemo.env';

if (!is_readable($path)) {
    fwrite(STDERR, "UNREADABLE: $path\n");
    exit(1);
}

$errors  = [];
$seen    = [];
$count   = 0;

foreach (file($path, FILE_IGNORE_NEW_LINES) as $i => $line) {
    $ln = $i + 1;
    if (trim($line) === '' || str_starts_with(trim($line), '#')) {
        continue;
    }
    if (!preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $line, $m)) {
        $errors[] = "line $ln: not KEY=VALUE -> " . substr(trim($line), 0, 40);
        continue;
    }
    [$_, $key, $val] = $m;
    $count++;

    if (isset($seen[$key])) {
        $errors[] = "line $ln: duplicate key $key (first seen line {$seen[$key]})";
    }
    $seen[$key] = $ln;

    $v = trim($val);
    $q = $v === '' ? '' : $v[0];
    $quoted = $q === '"' || $q === "'";

    if (!$quoted && preg_match('/\s/', $v)) {
        $errors[] = "line $ln: $key has UNQUOTED whitespace -> wraps in quotes";
    }
    if (!$quoted && str_ends_with($val, ' ')) {
        $errors[] = "line $ln: $key has trailing whitespace after the value";
    }
    if ($quoted && !preg_match('/^([\'"]).*\1$/s', $v)) {
        $errors[] = "line $ln: $key opens a quote it never closes";
    }
}

// Near-miss detection: a key that differs from a known one by one character.
$expected = [
    'VAPID_SUBJECT', 'VAPID_PUBLIC_KEY', 'VAPID_PRIVATE_KEY',
    'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
    'JWT_SECRET', 'JWT_ACCESS_TOKEN_EXPIRY', 'JWT_REFRESH_TOKEN_EXPIRY',
    'APP_ENV', 'APP_DEBUG', 'APP_URL', 'SESSION_SECURE_COOKIE',
    'CORS_ALLOWED_ORIGINS', 'CSRF_ORIGIN_ENFORCE',
    'AI_NVIDIA_API_KEY', 'AI_NVIDIA_EXTRA_BODY', 'MAIL_FROM_NAME',
];
$missing = array_values(array_diff($expected, array_keys($seen)));

printf("keys found: %d\n", $count);
if ($missing) {
    echo "MISSING (may be optional): " . implode(', ', $missing) . "\n";
}
if ($errors) {
    echo "PROBLEMS:\n - " . implode("\n - ", $errors) . "\n";
    exit(1);
}
echo "OK - no parse-breaking problems detected\n";
