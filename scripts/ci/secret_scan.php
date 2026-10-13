<?php

declare(strict_types=1);

/**
 * Secret scanner (Phase 1 CI gate).
 *
 * Scans every git-tracked file for committed credentials: database passwords,
 * JWT secrets, SMTP credentials, API/cloud keys, private key material and
 * known-leaked values. Exits non-zero when a probable real secret is found so
 * CI can block the merge/deployment.
 *
 * Usage: php scripts/ci/secret_scan.php
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}

$repoRoot = dirname(__DIR__, 2);

// ---------------------------------------------------------------------------
// 1. Collect files to scan: git-tracked files (fallback: directory walk).
// ---------------------------------------------------------------------------
$files = [];
exec('git -C ' . escapeshellarg($repoRoot) . ' ls-files', $out, $code);

if ($code === 0 && !empty($out)) {
    $files = $out;
} else {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repoRoot, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            $files[] = str_replace('\\', '/', substr($f->getPathname(), strlen($repoRoot) + 1));
        }
    }
}

// Runtime/build artifacts that cannot contain source secrets.
$excludePrefixes = [
    'vendor/', 'node_modules/', '.git/', 'storage/logs/', 'storage/cache/',
    'frontend/dist/', 'backend/public/assets/',
];
$excludeNames = [
    'package-lock.json', 'composer.lock', '.phpunit.result.cache',
];

// ---------------------------------------------------------------------------
// 2. Detection patterns.
//
// NOTE: separators use [ \t] only (never \s) so that an empty `KEY=`
// assignment cannot grab the next line's token as its value.
// ---------------------------------------------------------------------------
$patterns = [
    'database password'   => '/\b(DB_PASS|DB_PASSWORD|MYSQL_PASSWORD|DB_PWD)\b[ \t]*[=:][ \t]*([^\s\'\"]+)/i',
    'JWT secret'          => '/\b(JWT_SECRET|JWT_SECRET_KEY)\b[ \t]*[=:][ \t]*([^\s\'\"]+)/i',
    'SMTP/mail password'  => '/\b(MAIL_PASSWORD|SMTP_PASSWORD|SMTP_PASS)\b[ \t]*[=:][ \t]*([^\s\'\"]+)/i',
    'API key / secret'    => '/\b(API_KEY|API_SECRET|SECRET_KEY|ACCESS_TOKEN_SECRET|AUTH_TOKEN|HTTPSMS_API_KEY|VAPID_PRIVATE_KEY|SENTRY_DSN)\b[ \t]*[=:][ \t]*([^\s\'\"]+)/i',
    'cloud credentials'   => '/\b(AWS_SECRET_ACCESS_KEY|AWS_ACCESS_KEY_ID)\b[ \t]*[=:][ \t]*([^\s\'\"]+)/i',
    'quoted password'     => '/\b(password|passwd|pwd)\b[ \t]*[=:][ \t]*[\'\"]([^\'\"\\s]{8,})[\'\"]/i',
    'private key block'   => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
];

// Known-leaked literals discovered during the Phase 1 audit. Never re-add.
// (The scanner itself is exempt from this check - see selfScan below.)
$knownLeaks = [
    'Jmwkah198',   // DB password committed in .env.example / backup.sh (Phase 1)
    'ADMIN001',    // default admin password committed in setup scripts
    'Admin@123',   // default admin password committed in setup scripts
];

/**
 * Values that look like placeholders / variable references, not secrets.
 */
function isPlaceholderValue(string $value): bool
{
    $v = trim($value, " \t\'\";,");
    if ($v === '' || strlen($v) < 6) {
        return true; // too short to be a real secret
    }

    $lower = strtolower($v);
    foreach ([
        'your-', 'your_', 'placeholder', 'changeme', 'change-me', 'change_me',
        'example', 'sample', 'dummy', 'xxx', 'todo', 'fixme', 'insert-',
        'replace-', 'redacted', 'password', 'secret', 'null', 'none', 'empty',
    ] as $needle) {
        if (str_contains($lower, $needle)) {
            return true;
        }
    }

    // Variable/templated references: ${...}, {{...}}, (<%...%>), env() calls,
    // array/parenthesised expressions, shell defaults.
    if (preg_match('/[\$\{\(\[<%]/', $v)) {
        return true;
    }

    return false;
}

$findings = [];
$scanned = 0;

foreach ($files as $rel) {
    $rel = str_replace('\\', '/', trim((string) $rel));

    if ($rel === '') {
        continue;
    }

    $skip = false;
    foreach ($excludePrefixes as $prefix) {
        if (str_starts_with($rel, $prefix)) { $skip = true; break; }
    }
    foreach ($excludeNames as $name) {
        if (basename($rel) === $name) { $skip = true; break; }
    }
    if ($skip) {
        continue;
    }

    $abs = $repoRoot . '/' . $rel;
    if (!is_file($abs)) {
        continue;
    }

    $content = file_get_contents($abs);
    if ($content === false || str_contains($content, "\0")) {
        continue; // unreadable or binary
    }
    $scanned++;

    // The scanner must contain the known-leak literals to detect them, so it
    // is exempt from its own known-leak check (patterns still apply).
    $selfScan = ($rel === 'scripts/ci/secret_scan.php');

    foreach ($patterns as $label => $regex) {
        if (!preg_match_all($regex, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m as $hit) {
            $value = isset($hit[2]) ? $hit[2][0] : '';
            if ($label !== 'private key block' && isPlaceholderValue($value)) {
                continue;
            }
            $line = substr_count(substr($content, 0, (int) $hit[0][1]), "\n") + 1;
            $findings[] = sprintf('%s:%d  [%s]', $rel, $line, $label);
        }
    }

    if (!$selfScan) {
        foreach ($knownLeaks as $leak) {
            $offset = 0;
            while (($pos = stripos($content, $leak, $offset)) !== false) {
                $line = substr_count(substr($content, 0, $pos), "\n") + 1;
                $findings[] = sprintf('%s:%d  [known leaked credential]', $rel, $line);
                $offset = $pos + strlen($leak);
            }
        }
    }
}

// ---------------------------------------------------------------------------
// 3. Report.
// ---------------------------------------------------------------------------
if (empty($findings)) {
    echo "SECRET SCAN PASSED: no committed credentials detected ({$scanned} files scanned)\n";
    exit(0);
}

fwrite(STDERR, "SECRET SCAN FAILED - possible committed credentials:\n");
foreach ($findings as $finding) {
    fwrite(STDERR, "  {$finding}\n");
}
fwrite(STDERR, "\nRotate any exposed credential and remove it from source control.\n");
exit(1);