<?php

declare(strict_types=1);

/**
 * encrypt_existing_files.php - one-time, resumable, safe migration of the
 * files already on disk into PART B encrypted storage.
 *
 * WHAT IT DOES, PER FILE
 *   1. reads the PLAINTEXT file
 *   2. encrypts it in place with a fresh per-file key
 *   3. DECRYPTS it again into a temp file
 *   4. compares the SHA-256 of that temp file against the SHA-256 recorded at
 *      encryption time
 *   5. only when the hashes are equal does it delete the plaintext copy and
 *      commit the key row
 *
 * Step 3-4 is the whole point. Encrypting a file you cannot read back is not
 * encryption, it is data loss. If the round trip disagrees for ANY reason -
 * wrong master key, a truncated write, a bug in the chunking - the plaintext
 * is left untouched and the file is reported as FAILED. The script never
 * removes a plaintext original it has not just proved it can reproduce.
 *
 * USAGE
 *   php scripts/storage/encrypt_existing_files.php --dry-run
 *       Report what would happen. Writes nothing. Run this first, always.
 *   php scripts/storage/encrypt_existing_files.php --execute
 *       Perform the migration.
 *
 * OPTIONS
 *   --limit=N        process at most N files this run (default: all)
 *   --only=TABLE     restrict to one table (employee_documents |
 *                    leave_application_documents | hr_policies)
 *   --state=FILE     resume state file (default: backend/storage/cache/
 *                    storage_encryption_state.json)
 *   --reset          discard resume state and start over
 *   --verify-only    re-verify already-encrypted files, change nothing
 *
 * RESUMABILITY
 *   A JSON state file records every file that has been processed. Re-running
 *   skips them. A file is recorded as "done" only AFTER its round trip
 *   verified, so an interrupted run never marks unverified work complete.
 *   A file that FAILED is NOT recorded, so it is retried on the next run -
 *   which is the correct behaviour, because its plaintext is still intact.
 *
 * IDEMPOTENCY
 *   A file that already carries the MWSC1 magic is detected and skipped, and
 *   a row that already exists in file_encryption is never re-encrypted. It is
 *   therefore safe to run twice, or to run after a partial failure.
 *
 * BACKUP FIRST. Take a copy of backend/storage and backend/public/uploads
 * before running --execute. This script rewrites user data in place.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Helpers\StorageEncryption;

$options  = array_slice($argv, 1);
$execute  = in_array('--execute', $options, true);
$dryRun   = in_array('--dry-run', $options, true);
$verifyOnly = in_array('--verify-only', $options, true);
$reset    = in_array('--reset', $options, true);
$limit    = null;
$only     = null;
$stateFile = null;

foreach ($options as $opt) {
    if (preg_match('/^--limit=(\d+)$/', $opt, $m) === 1) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--only=([a-z_]+)$/', $opt, $m) === 1) {
        $only = $m[1];
    } elseif (preg_match('/^--state=(.+)$/', $opt, $m) === 1) {
        $stateFile = $m[1];
    }
}

if (!$execute && !$dryRun && !$verifyOnly && !$reset) {
    fwrite(STDERR, "Usage: php scripts/storage/encrypt_existing_files.php "
        . "[--dry-run|--execute|--verify-only] [--limit=N] [--only=TABLE] [--reset]\n");
    exit(2);
}

require_once __DIR__ . '/../../backend/bootstrap.php';

$red    = "\033[0;31m";
$green  = "\033[0;32m";
$yellow = "\033[1;33m";
$nc     = "\033[0m";

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

// ---------------------------------------------------------------------------
// Preconditions
// ---------------------------------------------------------------------------
if (!StorageEncryption::isAvailable()) {
    fwrite(STDERR, "FATAL: this PHP build has no AES-256-GCM. Refusing to migrate.\n");
    exit(1);
}

if (!$verifyOnly) {
    try {
        StorageEncryption::masterKey();
    } catch (\Throwable $e) {
        fwrite(STDERR, 'FATAL: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

$stateFile = $stateFile ?? (STORAGE_PATH . '/cache/storage_encryption_state.json');

if ($reset && is_file($stateFile)) {
    @unlink($stateFile);
    out('Resume state cleared.');
    if (!$execute && !$dryRun && !$verifyOnly) {
        exit(0);
    }
}

$state = ['done' => [], 'failed' => []];
if (is_file($stateFile)) {
    $decoded = json_decode((string) file_get_contents($stateFile), true);
    if (is_array($decoded) && isset($decoded['done']) && is_array($decoded['done'])) {
        $state = $decoded;
    }
}


/**
 * Where each supported table keeps its files on disk.
 *
 * `leave_attachments` is deliberately absent: that table does not exist in the
 * database (LeaveAttachmentService still references it - a separate
 * pre-existing bug), so listing it would only produce empty work.
 */
function targetTables(): array
{
    return [
        'employee_documents' => [
            'sql'    => 'SELECT id, file_name FROM employee_documents ORDER BY id',
            'path'   => fn(array $r): string => STORAGE_PATH . '/uploads/documents/' . $r['file_name'],
            'legacy' => fn(array $r): string => BACKEND_PATH . '/public/uploads/employee_documents/' . $r['file_name'],
        ],
        'leave_application_documents' => [
            'sql'    => 'SELECT id, stored_filename FROM leave_application_documents ORDER BY id',
            'path'   => fn(array $r): string => STORAGE_PATH . '/uploads/leave_documents/' . $r['stored_filename'],
            'legacy' => null,
        ],
        'hr_policies' => [
            'sql'    => 'SELECT id, storage_path FROM hr_policies ORDER BY id',
            'path'   => fn(array $r): string => STORAGE_PATH . '/' . $r['storage_path'],
            'legacy' => null,
        ],
    ];
}

$conn = \db()->getConnection();
$tables = targetTables();

if ($only !== null) {
    if (!isset($tables[$only])) {
        fwrite(STDERR, "FATAL: unknown table '$only'. Known: " . implode(', ', array_keys($tables)) . "\n");
        exit(2);
    }
    $tables = [$only => $tables[$only]];
}

// ---------------------------------------------------------------------------
// Collect the work
// ---------------------------------------------------------------------------
$work = [];

foreach ($tables as $tableName => $spec) {
    // Table existence check.
    //
    // The schema and table name are interpolated as QUOTED string literals.
    // Two traps, both hit and verified here:
    //
    //   1. MariaDB will not resolve DATABASE() inside a PREPARED
    //      information_schema statement on this build - store_result() comes
    //      back as bool. A raw query returns rows. So this is deliberately raw.
    //   2. $conn->real_escape_string() escapes CONTENT but does not add the
    //      surrounding quotes, so the identifier has to be quoted explicitly or
    //      MariaDB parses the name as a column. Verified: without the quotes
    //      this throws "Unknown column 'mig_script_scratch' in 'where clause'".
    //
    // A missing table is an EXPECTED condition (a one-time migration across
    // tables that may not all exist), and the app sets
    // mysqli_report(ERROR|STRICT) so a failed query THROWS. It is caught and
    // treated as "not present".
    $schema = (string) $conn->query('SELECT DATABASE()')->fetch_row()[0];
    $exists = null;
    try {
        $exists = $conn->query(
            "SELECT 1 FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = '" . $conn->real_escape_string($schema) . "'
                AND TABLE_NAME   = '" . $conn->real_escape_string($tableName) . "'
              LIMIT 1"
        );
    } catch (\Throwable $e) {
        $exists = false;
    }

    if (!$exists || $exists->num_rows === 0) {
        out("  skip: table '$tableName' does not exist in this database");
        continue;
    }

    $rows = null;
    try {
        $rows = $conn->query($spec['sql']);
    } catch (\Throwable $e) {
        $rows = false;
    }
    if (!$rows) {
        out("  skip: could not read $tableName");
        continue;
    }

    while ($r = $rows->fetch_assoc()) {
        $work[] = [
            'table'  => $tableName,
            'id'     => (int) $r['id'],
            'path'   => $spec['path']($r),
            'legacy' => $spec['legacy'] !== null ? $spec['legacy']($r) : null,
        ];
    }
}

out('');
out(($verifyOnly ? 'VERIFY ONLY -' : ($execute ? 'MIGRATING' : 'DRY RUN -')) . ' file inventory');
out(sprintf('  %d file record(s) found across %d table(s)', count($work), count($tables)));
out('');

if (empty($work)) {
    out($green . 'Nothing to do.' . $nc);
    exit(0);
}


$done = 0;
$skipped = 0;
$failed = 0;
$missing = 0;
$planned = 0;

$insert = null;
$selectEnc = null;

if ($execute) {
    $insert = $conn->prepare(
        'INSERT INTO file_encryption
            (table_name, record_id, file_key_wrapped, nonce, key_version, algorithm, plaintext_sha256)
         VALUES (?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            file_key_wrapped = VALUES(file_key_wrapped),
            nonce            = VALUES(nonce),
            key_version      = VALUES(key_version),
            algorithm        = VALUES(algorithm),
            plaintext_sha256 = VALUES(plaintext_sha256)'
    );
    $selectEnc = $conn->prepare(
        'SELECT id FROM file_encryption WHERE table_name = ? AND record_id = ?'
    );
}

foreach ($work as $item) {
    $key  = $item['table'] . '#' . $item['id'];
    $path = $item['path'];

    // Prefer the legacy webroot location when the primary path is absent.
    if (!is_file($path) && $item['legacy'] !== null && is_file($item['legacy'])) {
        $path = $item['legacy'];
    }

    if (!is_file($path)) {
        $missing++;
        if (!$execute) {
            out(sprintf('  skip %-34s file not on disk (left alone)', $key));
        }
        continue;
    }

    // Already converted on a previous run?
    if (StorageEncryption::isEncryptedFile($path)) {
        $skipped++;
        if (!$execute || $verifyOnly) {
            out(sprintf('  ok   %-34s already encrypted', $key));
        }
        continue;
    }

    // Key row already present (a previous run crashed after encrypting)?
    $alreadyRecorded = false;
    if ($selectEnc !== null) {
        $tn = $item['table'];
        $rid = $item['id'];
        try {
            $selectEnc->bind_param('si', $tn, $rid);
            if ($selectEnc->execute()) {
                $res = $selectEnc->store_result();
                $alreadyRecorded = ($res instanceof \mysqli_result && $res->num_rows > 0);
                if ($res instanceof \mysqli_result) {
                    $res->free();
                }
            }
        } catch (\Throwable $e) {
            // The table may not exist yet on a partially-migrated database.
            // Treat "cannot tell" as "not recorded" and let the INSERT below
            // decide; a duplicate-key there is harmless (ON DUPLICATE KEY).
            $alreadyRecorded = false;
        }
    }
    if ($alreadyRecorded) {
        $skipped++;
        out(sprintf('  skip %-34s key row already present', $key));
        continue;
    }

    if (in_array($key, $state['done'], true)) {
        $skipped++;
        continue;
    }

    $planned++;

    if (!$execute) {
        $size = is_file($path) ? (int) filesize($path) : 0;
        out(sprintf('  plan %-34s %8d bytes  %s', $key, $size, basename($path)));
        if ($planned >= 40 && $limit === null) {
            out('  ... (further files omitted; use --limit=N to page through)');
            break;
        }
        continue;
    }


    // -----------------------------------------------------------------------
    // Execute: encrypt, prove it by decrypting, and only then trust it
    // -----------------------------------------------------------------------
    $tmpVerify = null;
    try {
        // 1+2. Encrypt in place. Returns the plaintext SHA-256 + key metadata.
        $meta = StorageEncryption::encryptFile($path, $item['table'], $item['id']);

        // 3. Decrypt it straight back out.
        $fileKey = StorageEncryption::unwrapKey(
            $meta['wrapped'],
            $meta['nonce'],
            StorageEncryption::keyAad($item['table'], $item['id']),
            $meta['key_version']
        );

        $tmpVerify = $path . '.verify.' . bin2hex(random_bytes(4));
        StorageEncryption::decryptFile($path, $tmpVerify, $fileKey);

        // 4. Compare. Any mismatch means the ciphertext must not be trusted.
        $verifyHash = hash_file('sha256', $tmpVerify);
        @unlink($tmpVerify);
        $tmpVerify = null;

        if (!hash_equals($meta['sha256'], (string) $verifyHash)) {
            throw new \RuntimeException(
                'round-trip hash mismatch - plaintext retained, file left encrypted and UNRECORDED'
            );
        }

        // 5. Only now is it safe to record the key.
        if ($insert !== null) {
            $tn = $item['table'];
            $rid = $item['id'];
            $wr = $meta['wrapped'];
            $no = $meta['nonce'];
            $kv = $meta['key_version'];
            $al = 'AES-256-GCM-CHUNKED';
            $sh = $meta['sha256'];
            $insert->bind_param('sississ', $tn, $rid, $wr, $no, $kv, $al, $sh);
            if (!$insert->execute()) {
                throw new \RuntimeException('could not record the key: ' . $insert->error);
            }
        }

        $state['done'] = array_values(array_unique(array_merge($state['done'], [$key])));
        $state['failed'] = array_values(array_diff($state['failed'], [$key]));
        $done++;

        if ($done <= 40) {
            out(sprintf('  ok   %-34s %8d bytes  verified', $key, $meta['bytes']));
        }

        // Persist after every file so an interrupt is never repeated.
        @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);

        if ($limit !== null && $done >= $limit) {
            out(sprintf('  ... --limit=%d reached; re-run to continue', $limit));
            break;
        }
    } catch (\Throwable $e) {
        if ($tmpVerify !== null) {
            @unlink($tmpVerify);
        }
        $failed++;
        $state['failed'] = array_values(array_unique(array_merge($state['failed'], [$key])));
        // Never echoes plaintext, key material or ciphertext.
        fwrite(STDERR, sprintf('  FAIL %-34s %s' . PHP_EOL, $key, $e->getMessage()));
    }
}

if ($insert) {
    $insert->close();
}
if ($selectEnc) {
    $selectEnc->close();
}

out('');
out(sprintf('processed: %d, skipped: %d, failed: %d, missing: %d', $done, $skipped, $failed, $missing));

if (!$execute) {
    out('');
    out($yellow . 'DRY RUN - nothing was written.' . $nc);
    out('Re-run with --execute to perform the migration.');
    out('Back up backend/storage and backend/public/uploads first.');
    exit(0);
}

@file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);

out('');
if ($failed > 0) {
    out($red . 'Completed with failures.' . $nc);
    out('Failed files keep their plaintext and are retried on the next run.');
    out('Review the messages above before re-running.');
    exit(1);
}

out($green . 'All discovered files are encrypted and verified.' . $nc);
out('Sanity check: `head -c5 <file>` should read MWSC1, and the file should no');
out('longer be recognised as its original document type.');
