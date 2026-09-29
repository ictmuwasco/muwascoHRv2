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
// ---------------------------------------------------------------------------
// 2. Detection patterns.
//
// NOTE: separators use [ \t] only (never \s) so that an empty `KEY=`
// assignment cannot grab the next line's token as its value.
//
// THE KEY-LEFT-BOUNDARY IS `(?:^|[^A-Za-z0-9_])`, NOT `\b`.
//
// Why: in a COMPOUND name the character before the key is `_`, which is itself
// a word character, so `\b` never fires there. The old `\b(API_KEY|...)\b`
// matched `HTTPSMS_API_KEY` but silently MISSED `AI_NVIDIA_API_KEY` - the
// highest-value credential in this repo went undetected by the very gate
// meant to catch it. `(?:^|[^A-Za-z0-9_])` matches at line start or after any
// non-identifier character, so bare AND compound names are both caught.
// ---------------------------------------------------------------------------
$patterns = [
    'database password'   => '/(?:^|[^A-Za-z0-9_])([A-Za-z0-9_]*(?:DB_PASS|DB_PASSWORD|MYSQL_PASSWORD|DB_PWD))[ \t]*[=:][ \t]*([^\s\'"]+)/i',
    'JWT secret'          => '/(?:^|[^A-Za-z0-9_])([A-Za-z0-9_]*(?:JWT_SECRET|JWT_SECRET_KEY))[ \t]*[=:][ \t]*([^\s\'"]+)/i',
    'SMTP/mail password'  => '/(?:^|[^A-Za-z0-9_])([A-Za-z0-9_]*(?:MAIL_PASSWORD|SMTP_PASSWORD|SMTP_PASS|MAIL_PASS))[ \t]*[=:][ \t]*([^\s\'"]+)/i',
    'API key / secret'    => '/(?:^|[^A-Za-z0-9_])([A-Za-z0-9_]*(?:API_KEY|API_SECRET|SECRET_KEY|ACCESS_TOKEN_SECRET|AUTH_TOKEN|HTTPSMS_API_KEY|VAPID_PRIVATE_KEY|SENTRY_DSN))[ \t]*[=:][ \t]*([^\s\'"]+)/i',
    'cloud credentials'   => '/(?:^|[^A-Za-z0-9_])([A-Za-z0-9_]*(?:AWS_SECRET_ACCESS_KEY|AWS_ACCESS_KEY_ID))[ \t]*[=:][ \t]*([^\s\'"]+)/i',
    // Seeder bootstrap credentials (SEED_ADMIN_PASSWORD, ...). These are
    // unquoted, so they were invisible to the quoted-password rule below.
    'seed password'       => '/(?:^|[^A-Za-z0-9_])(SEED_[A-Z0-9_]*PASSWORD)[ \t]*[=:][ \t]*([^\s\'"]+)/i',
    'quoted password'     => '/(?:^|[^A-Za-z0-9_])([A-Za-z0-9_]*(?:password|passwd|pwd))[ \t]*[=:][ \t]*[\'"]([^\'"\\s]{8,})[\'"]/i',
    'private key block'   => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
];

// Known-leaked literals discovered during the Phase 1 audit. Never re-add.
// (The scanner itself is exempt from this check - see selfScan below.)
//
// `ADMIN001` was REMOVED. It is not a credential - it is a real employees.
// employee_id BUSINESS CODE, and the bare-literal search matched it inside an
// explanatory comment in LeaveController, failing CI on a non-secret. Business
// codes cannot be distinguished from passwords by substring alone, so the
// false positive is removed rather than worked around.
//
// The Phase 1 DB-password literal was also removed here, and that removal is
// self-inflicted in a way worth recording: the 2026-09-29 git-filter-repo
// history scrub rewrote that literal to the marker REDACTED_ROTATE *everywhere
// it appeared - including on this line*. The denylist then matched its own
// placeholder and failed CI on JWT.php and SECURITY_AUDIT.md, which contain
// the marker but no credential. The underlying secret is purged from history
// and rotated, so the entry has nothing left to protect. The guard below
// (skipping placeholder-shaped entries) stops that class of self-match
// recurring if this file is ever scrubbed again.
$knownLeaks = [
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

    // Strip COMMENT-ONLY lines before applying the assignment patterns.
    //
    // A commented-out `# VITE_SENTRY_DSN=  Sentry DSN if migrating ...` is
    // documentation, not a credential, and matching it fails CI on prose. The
    // compound-key fix made the scanner correctly see compound names like
    // VITE_SENTRY_DSN, which is exactly what surfaced this. The known-leak
    // substring check below still runs over the FULL content, so a genuine
    // leak hidden in a comment is still caught.
    $scannable = preg_replace('/^[ \t]*(?:#|\/\/).*$/m', '', $content);

    foreach ($patterns as $label => $regex) {
        if (!preg_match_all($regex, $scannable, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            continue;
        }
        foreach ($m as $hit) {
            $value = isset($hit[2]) ? $hit[2][0] : '';
            if ($label !== 'private key block' && isPlaceholderValue($value)) {
                continue;
            }
            $line = substr_count(substr($scannable, 0, (int) $hit[0][1]), "\n") + 1;
            $findings[] = sprintf('%s:%d  [%s]', $rel, $line, $label);
        }
    }

    if (!$selfScan) {
        foreach ($knownLeaks as $leak) {
            // Guard: a placeholder-shaped entry is not a credential. This file
            // has been rewritten by git-filter-repo more than once, and a scrub
            // marker landing in $knownLeaks made the scanner fail CI on its own
            // redaction output. Skip such entries rather than self-matching.
            if ($leak === '' || isPlaceholderValue($leak)) {
                continue;
            }
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
