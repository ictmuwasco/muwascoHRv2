<?php

/**
 * Read an encrypted log/stream back to plaintext on stdout.
 *
 *   php scripts/storage/read_log.php backend/storage/logs/2026-10-01.log
 *   php scripts/storage/read_log.php backend/storage/logs/2026-10-01.log --tail 50
 *   php scripts/storage/read_log.php backend/storage/logs/2026-10-01.log --check
 *
 * Since the app switched to SealedStream, `cat`/`type` on a log shows ciphertext.
 * This is the supported way to read one. Requires STORAGE_ENCRYPTION_KEY to be
 * set in the environment or the .env file, the same key the app writes with.
 *
 * Plaintext logs (written before the switch) still work, so this doubles as the
 * verification step during the migration: run it over a file, diff the output
 * against the original, and only then delete the plaintext.
 *
 * --check   verify integrity and exit, print nothing. Detects tampering and a
 *           torn tail (interrupted writer). Exit 1 on authentication failure.
 * --tail N  print only the last N records.
 */

declare(strict_types=1);

// The Composer autoloader is required, not just SealedStream.php: the stream
// delegates key wrapping to App\Helpers\StorageEncryption, and requiring the
// single file by hand leaves that class undefined at unwrap time.
$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoloader not found at {$autoload}.\n");
    exit(1);
}
require_once $autoload;
require_once __DIR__ . '/../../backend/app/Helpers/SealedStream.php';

use App\Helpers\SealedStream;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script is CLI-only.\n");
    exit(1);
}

// env() is a global defined in backend/bootstrap.php, which a standalone CLI
// script does not load. SealedStream -> StorageEncryption -> currentKeyVersion()
// calls it, so without this the script dies with "Call to undefined function
// env()". Declared only if absent, so running under the real bootstrap is
// unaffected. Kept byte-identical to the app's definition on purpose.
if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}

$args    = array_slice($argv, 1);
$path    = null;
$tail    = 0;
$check   = false;

for ($i = 0; $i < count($args); $i++) {
    switch ($args[$i]) {
        case '--check':
            $check = true;
            break;
        case '--tail':
            $tail = (int) ($args[++$i] ?? 0);
            break;
        case '-h':
        case '--help':
            fwrite(STDOUT, "Usage: read_log.php <file> [--tail N] [--check]\n");
            exit(0);
        default:
            if (strpos($args[$i], '-') === 0) {
                fwrite(STDERR, "Unknown option: {$args[$i]}\n");
                exit(1);
            }
            $path = $args[$i];
    }
}

if ($path === null) {
    fwrite(STDERR, "Usage: read_log.php <file> [--tail N] [--check]\n");
    exit(1);
}

if (!is_file($path)) {
    fwrite(STDERR, "No such file: {$path}\n");
    exit(1);
}

// Load .env if present, so the script works from a shell without exporting the
// key by hand. The app does the same in bootstrap.php.
foreach ([getcwd() . '/.env', dirname(__DIR__, 2) . '/.env'] as $envFile) {
    if (is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k);
            $v = trim($v, " \t\"'");
            if (getenv($k) === false) {
                putenv("{$k}={$v}");
                $_ENV[$k] = $v;
            }
        }
    }
}

if (!SealedStream::isAvailable()) {
    fwrite(
        STDERR,
        "Storage encryption is unavailable. Set STORAGE_ENCRYPTION_KEY (base64, 32 bytes)\n"
        . "in the environment or .env, using the SAME key the application writes with.\n"
    );
    exit(1);
}

try {
    $result = SealedStream::readOrPlaintext($path);
} catch (\RuntimeException $e) {
    // Authentication failure, or a stream that is not an MWSS1 container.
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    exit(1);
}

$sealed = SealedStream::isSealed($path);

if ($check) {
    fwrite(STDOUT, "records:  {$result['records']}\n");
    fwrite(STDOUT, "bytes:    {$result['bytes']}\n");
    fwrite(STDOUT, "format:   " . ($sealed ? 'MWSS1 (encrypted)' : 'plaintext (not yet migrated)') . "\n");
    if ($result['truncated']) {
        fwrite(
            STDOUT,
            "WARNING:  torn tail - the last record is incomplete, which means a\n"
            . "          writer was killed mid-append. Earlier records are intact.\n"
        );
        exit(2);
    }
    fwrite(STDOUT, "OK: all records authenticated.\n");
    exit(0);
}

$out = $result['plaintext'];

if ($tail > 0) {
    $lines = explode("\n", $out);
    if (end($lines) === '') {
        array_pop($lines);
    }
    $lines  = array_slice($lines, -$tail);
    $out    = implode("\n", $lines) . "\n";
}

fwrite(STDOUT, $out);

if ($result['truncated']) {
    fwrite(STDERR, "warning: torn tail, trailing partial record was skipped\n");
}
