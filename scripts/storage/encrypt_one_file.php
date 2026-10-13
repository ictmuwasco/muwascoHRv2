<?php

declare(strict_types=1);

/**
 * encrypt_one_file.php - encrypt (or decrypt) ONE arbitrary local file with the
 * same AES-256-GCM container the application uses for documents at rest.
 *
 * WHY THIS EXISTS
 *   encrypt_existing_files.php migrates files that belong to DB rows
 *   (employee_documents / leave_application_documents / hr_policies): the key
 *   envelope lives in the `file_encryption` table and the owning row is stamped
 *   is_encrypted=1. It cannot touch a file that has no row - a PDF sitting on
 *   your desktop, a contract you want to rehearse with, a backup you are about
 *   to copy onto a USB stick.
 *
 *   This script runs the SAME pipeline for one arbitrary file, so what you
 *   prove locally is exactly what runs in production:
 *     1. read the plaintext
 *     2. encrypt to a sibling STAGING file (source untouched)
 *     3. decrypt the staging file back and compare SHA-256
 *     4. only on a match: write the key envelope, then commit the ciphertext
 *   A failure at ANY step leaves the plaintext exactly as it was.
 *
 *   The key envelope normally lives in the `file_encryption` table. There is
 *   no DB row for an arbitrary file, so it is written beside the ciphertext as
 *   `<ciphertext>.key.json`. That file holds the WRAPPED per-file key (sealed
 *   under STORAGE_ENCRYPTION_KEY) - useless without the master key, but still
 *   keep it out of any copy that an attacker could read alongside the backup
 *   it protects.
 *
 * USAGE
 *   php scripts/storage/encrypt_one_file.php <file>
 *       Encrypt <file> to <file>.enc. The original is left untouched.
 *   php scripts/storage/encrypt_one_file.php <file> --in-place
 *       Replace the original with its ciphertext - what production does to a
 *       freshly uploaded document. BACK UP the file first.
 *   php scripts/storage/encrypt_one_file.php --decrypt <file.enc>
 *       Restore the plaintext. Default target: the ciphertext path minus the
 *       .enc suffix (otherwise <file>.dec).
 *   php scripts/storage/encrypt_one_file.php --decrypt <file.enc> --out=<path>
 *
 * OPTIONS
 *   --in-place     overwrite the source with ciphertext (encrypt mode only)
 *   --out=PATH     write the result to PATH instead of the default target
 *   --key=PATH     key envelope (default: <ciphertext>.key.json)
 *
 * EXITS
 *   0 success, 1 failure (plaintext retained on encrypt failures), 2 usage.
 */

use App\Helpers\StorageEncryption;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoloader not found at {$autoload}.\n");
    exit(1);
}
require_once $autoload;

// The app bootstrap, same as encrypt_existing_files.php: it loads .env through
// phpdotenv, which is where STORAGE_ENCRYPTION_KEY comes from on a dev box.
require_once __DIR__ . '/../../backend/bootstrap.php';

// -----------------------------------------------------------------------
// Arguments
// -----------------------------------------------------------------------

$decrypt  = false;
$inPlace  = false;
$out      = null;
$keyPath  = null;
$source   = null;
$unknown  = [];

foreach (array_slice($argv, 1) as $a) {
    if ($a === '--decrypt') {
        $decrypt = true;
    } elseif ($a === '--in-place') {
        $inPlace = true;
    } elseif (strpos($a, '--out=') === 0) {
        $out = substr($a, 6);
    } elseif (strpos($a, '--key=') === 0) {
        $keyPath = substr($a, 6);
    } elseif ($a === '-h' || $a === '--help') {
        fwrite(STDOUT, "Usage:\n"
            . "  php scripts/storage/encrypt_one_file.php <file> [--in-place] [--out=PATH]\n"
            . "  php scripts/storage/encrypt_one_file.php --decrypt <file> [--out=PATH] [--key=PATH]\n");
        exit(0);
    } elseif ($source === null && substr($a, 0, 1) !== '-') {
        $source = $a;
    } else {
        $unknown[] = $a;
    }
}

if ($source === null || $unknown !== []) {
    fwrite(STDERR, ($unknown !== [] ? 'Unknown argument(s): ' . implode(' ', $unknown) . "\n" : '')
        . "Usage: php scripts/storage/encrypt_one_file.php <file> [--in-place] [--out=PATH]\n"
        . "       php scripts/storage/encrypt_one_file.php --decrypt <file> [--out=PATH] [--key=PATH]\n");
    exit(2);
}

function fatal(string $msg): void
{
    fwrite(STDERR, 'FATAL: ' . $msg . "\n");
    exit(1);
}

// -----------------------------------------------------------------------
// Preconditions - fail loudly BEFORE touching anything
// -----------------------------------------------------------------------

if (!StorageEncryption::isAvailable()) {
    fatal('this PHP build has no AES-256-GCM (openssl extension missing).');
}

try {
    StorageEncryption::masterKey();
} catch (Throwable $e) {
    fatal($e->getMessage() . ' Set STORAGE_ENCRYPTION_KEY in .env '
        . '(generate: php -r "echo bin2hex(random_bytes(32));").');
}

// -----------------------------------------------------------------------
// Decrypt mode
// -----------------------------------------------------------------------

if ($decrypt) {
    if (!StorageEncryption::isEncryptedFile($source)) {
        fatal('not an encrypted storage container (bad magic). Nothing to decrypt - '
            . 'the file may never have been encrypted.');
    }

    $sidecar = $keyPath ?? ($source . '.key.json');
    if (!is_file($sidecar)) {
        fatal("key envelope not found: {$sidecar}\n"
            . "  (the wrapped per-file key is required to decrypt; in production it\n"
            . "   lives in the file_encryption table - losing it loses the document)");
    }

    $meta = json_decode((string) file_get_contents($sidecar), true);
    foreach (['wrapped', 'nonce', 'aad', 'key_version', 'sha256'] as $field) {
        if (!is_array($meta) || !isset($meta[$field]) || $meta[$field] === '') {
            fatal("key envelope is malformed (missing '{$field}'): {$sidecar}");
        }
    }

    $target = $out;
    if ($target === null) {
        $target = substr($source, -4) === '.enc'
            ? substr($source, 0, -4)
            : $source . '.dec';
    }
    if ($target === $source) {
        fatal('--out would overwrite the ciphertext; choose another target path.');
    }
    if (is_file($target)) {
        fatal("refusing to overwrite existing file: {$target} (delete it or use --out=)");
    }

    try {
        $fileKey = StorageEncryption::unwrapKey(
            (string) $meta['wrapped'],
            (string) $meta['nonce'],
            (string) $meta['aad'],
            (int) $meta['key_version']
        );
    } catch (Throwable $e) {
        fatal('cannot unwrap the file key: ' . $e->getMessage()
            . "\n  (wrong STORAGE_ENCRYPTION_KEY, or the envelope was tampered with)");
    }

    // Decrypt to a temp file, verify against the hash recorded at encryption
    // time, and only then put the plaintext at the requested path. A wrong key
    // fails inside decryptFile() (GCM tag) rather than yielding garbage.
    $tmp = $target . '.part.' . bin2hex(random_bytes(4));
    try {
        StorageEncryption::decryptFile($source, $tmp, $fileKey);
    } catch (Throwable $e) {
        @unlink($tmp);
        fatal('decryption failed: ' . $e->getMessage());
    }

    $actual = (string) hash_file('sha256', $tmp);
    if (!hash_equals((string) $meta['sha256'], $actual)) {
        @unlink($tmp);
        fatal('round-trip hash mismatch - recovered bytes do not match the hash recorded '
            . 'at encryption time. Deleted the partial output; ciphertext untouched.');
    }

    if (!@rename($tmp, $target)) {
        // rename() cannot cross filesystems (e.g. --out on another drive).
        $copied = @copy($tmp, $target);
        @unlink($tmp);
        if (!$copied) {
            fatal("could not move the recovered plaintext into place: {$target}");
        }
    }

    fwrite(STDOUT, "DECRYPTED\n"
        . "  ciphertext : {$source}\n"
        . "  plaintext  : {$target}\n"
        . "  sha256     : {$actual} (matches the encryption-time hash)\n");
    exit(0);
}


if (!is_file($source) || !is_readable($source)) {
    fatal("source file not readable: {$source}");
}


// -----------------------------------------------------------------------
// Encrypt mode
// -----------------------------------------------------------------------

if (StorageEncryption::isEncryptedFile($source)) {
    fatal('file is already encrypted (MWSC1 container). Nothing to do.');
}

$target = $inPlace ? $source : ($out ?? ($source . '.enc'));
if ($target !== $source && is_file($target)) {
    fatal("refusing to overwrite existing file: {$target} (delete it or use --out=)");
}

// Synthetic ownership for the AAD binding - there is no DB row, so the table
// name and record id are fabricated per file. They only need to be STABLE:
// unwrapKey() must see the same AAD wrapKey() used, and the sidecar records it.
$table     = 'local_documents';
$recordId  = abs(crc32($source)) ?: 1;
$sidecar   = $keyPath ?? ($target . '.key.json');
$staged    = null;
$committed = false;

try {
    $meta = StorageEncryption::encryptFileStaged($source, $table, $recordId);
    $staged = $meta['staged_path'];

    // 3. Prove the ciphertext reads back BEFORE anything is replaced.
    $fileKey = StorageEncryption::unwrapKey(
        $meta['wrapped'],
        $meta['nonce'],
        StorageEncryption::keyAad($table, $recordId),
        $meta['key_version']
    );

    $tmpVerify = $source . '.verify.' . bin2hex(random_bytes(4));
    StorageEncryption::decryptFile($staged, $tmpVerify, $fileKey);
    $verifyHash = (string) hash_file('sha256', $tmpVerify);
    @unlink($tmpVerify);

    if (!hash_equals($meta['sha256'], $verifyHash)) {
        throw new RuntimeException('round-trip hash mismatch - PLAINTEXT RETAINED, '
            . 'nothing was written');
    }

    // 4a. Key envelope BEFORE the commit, mirroring the production order
    //     (file_encryption row first, ciphertext swap second). If the commit
    //     then fails the envelope is simply orphaned - harmless; the reverse
    //     order would leave ciphertext with no key.
    $payload = [
        'table'        => $table,
        'record_id'    => $recordId,
        'aad'          => StorageEncryption::keyAad($table, $recordId),
        'wrapped'      => $meta['wrapped'],
        'nonce'        => $meta['nonce'],
        'key_version'  => $meta['key_version'],
        'algorithm'    => 'AES-256-GCM-CHUNKED',
        'sha256'       => $meta['sha256'],
        'bytes'        => $meta['bytes'],
        'chunk_size'   => $meta['chunk_size'],
        'ciphertext'   => basename($target),
        'encrypted_at' => gmdate('c'),
    ];
    if (file_put_contents($sidecar, json_encode($payload, JSON_PRETTY_PRINT) . "\n", LOCK_EX) === false) {
        throw new RuntimeException("could not write the key envelope: {$sidecar}");
    }

    // 4b. Swap: rename the verified staging file onto the target. The source
    //     is only replaced here, and only with --in-place.
    StorageEncryption::commitStagedFile($staged, $target);
    $staged = null;
    $committed = true;
} catch (Throwable $e) {
    StorageEncryption::discardStagedFile($staged);
    if ($committed === false && is_file($sidecar)) {
        @unlink($sidecar); // no ciphertext exists - do not leave a stray envelope
    }
    fatal($e->getMessage() . "\n  The original file was left untouched.");
}

$size = (int) filesize($target);
fwrite(STDOUT, "ENCRYPTED\n"
    . "  source      : {$source}"
    . ($inPlace ? ' (replaced in place)' : ' (original kept)') . "\n"
    . "  ciphertext  : {$target} ({$size} bytes, MWSC1 container)\n"
    . "  key envelope: {$sidecar}\n"
    . "  sha256      : {$meta['sha256']} over {$meta['bytes']} plaintext bytes\n\n"
    . "Restore with:\n"
    . "  php scripts/storage/encrypt_one_file.php --decrypt \"{$target}\"\n");
exit(0);
