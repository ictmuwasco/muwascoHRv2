<?php

declare(strict_types=1);

/**
 * rotate_master_key.php - PART B master-key rotation.
 *
 * WHAT IT DOES
 *   Re-wraps every per-file key in `file_encryption` from the current master
 *   key to a new one. It NEVER touches file content: the per-file key is
 *   unchanged, only the envelope around it. That is the entire reason the
 *   design uses a per-file key, and it is what makes rotation safe to run
 *   against a live system - no document is read, decrypted or re-encrypted.
 *
 *   Cost is one small UPDATE per row, not a full re-encryption pass.
 *
 * USAGE
 *   php scripts/storage/rotate_master_key.php --dry-run
 *       Report what WOULD change. Writes nothing. Always run this first.
 *   php scripts/storage/rotate_master_key.php --execute
 *       Perform the rotation. Requires STORAGE_ENCRYPTION_KEY_PREVIOUS to be
 *       set to the OUTGOING key, so rows not yet migrated stay readable.
 *   php scripts/storage/rotate_master_key.php --status
 *       Show how many rows sit at each key version.
 *
 * REQUIRED ENVIRONMENT (set BEFORE --execute)
 *   STORAGE_ENCRYPTION_KEY           the NEW key
 *   STORAGE_ENCRYPTION_KEY_PREVIOUS  the OLD key
 *   STORAGE_ENCRYPTION_KEY_VERSION   the NEW version number (old + 1)
 *
 * SAFE ORDER OF OPERATIONS
 *   1. add the new key to the env, keep the old one, do NOT bump the version
 *   2. php scripts/storage/rotate_master_key.php --dry-run
 *   3. set STORAGE_ENCRYPTION_KEY_VERSION=<new>
 *   4. php scripts/storage/rotate_master_key.php --execute
 *   5. confirm "0 rows remaining at the old version" via --status
 *   6. only then remove STORAGE_ENCRYPTION_KEY_PREVIOUS
 *
 *   If the process dies mid-rotation, steps 1-3 are unchanged, so re-running
 *   --execute simply picks up the remaining rows. Already-migrated rows are
 *   skipped, so the operation is idempotent and safely resumable.
 *
 * BACKUP FIRST. Losing a master key loses every file it ever wrapped.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Helpers\StorageEncryption;

$options = array_slice($argv, 1);
$execute = in_array('--execute', $options, true);
$dryRun  = in_array('--dry-run', $options, true);
$status  = in_array('--status', $options, true);

if (!$execute && !$dryRun && !$status) {
    fwrite(STDERR, "Usage: php scripts/storage/rotate_master_key.php [--dry-run|--execute|--status]\n");
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
    fwrite(STDERR, "FATAL: this PHP build has no AES-256-GCM. Refusing to rotate.\n");
    exit(1);
}

$newVersion = StorageEncryption::currentKeyVersion();

try {
    $newMaster = StorageEncryption::masterKey($newVersion);
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: cannot load the new master key: " . $e->getMessage() . "\n");
    exit(1);
}

$previousEnv = trim((string) \env('STORAGE_ENCRYPTION_KEY_PREVIOUS', ''));
$hasPrevious = $previousEnv !== '';

if ($execute && !$hasPrevious) {
    fwrite(STDERR, "FATAL: STORAGE_ENCRYPTION_KEY_PREVIOUS is not set.\n"
        . "Rotation without the outgoing key would make every not-yet-migrated file\n"
        . "unreadable. Set it, re-run, and only clear it once the script reports\n"
        . "0 rows remaining at the old version.\n");
    exit(1);
}

if ($hasPrevious) {
    try {
        $oldMaster = StorageEncryption::masterKey($newVersion - 1);
    } catch (\Throwable $e) {
        fwrite(STDERR, "FATAL: cannot load STORAGE_ENCRYPTION_KEY_PREVIOUS: " . $e->getMessage() . "\n");
        exit(1);
    }
    if (hash_equals($newMaster, $oldMaster)) {
        fwrite(STDERR, "FATAL: STORAGE_ENCRYPTION_KEY and STORAGE_ENCRYPTION_KEY_PREVIOUS are\n"
            . "identical. That is not a rotation - the old key must be different.\n");
        exit(1);
    }
} else {
    $oldMaster = $newMaster;
}

$conn = \db()->getConnection();
$oldVersion = $newVersion - 1;

// ---------------------------------------------------------------------------
// Status
// ---------------------------------------------------------------------------
if ($status) {
    out("Master key version: " . $newVersion);
    out(str_repeat('-', 60));
    $rows = $conn->query(
        'SELECT key_version, COUNT(*) AS c FROM file_encryption GROUP BY key_version ORDER BY key_version'
    );
    $total = 0;
    while ($r = $rows->fetch_assoc()) {
        $total += (int) $r['c'];
        out(sprintf('  version %-4d %6d file(s)', $r['key_version'], $r['c']));
    }
    out(str_repeat('-', 60));
    out("  total: $total");
    exit(0);
}

$stmt = $conn->prepare(
    'SELECT id, table_name, record_id, file_key_wrapped, nonce, key_version
     FROM file_encryption
     WHERE key_version < ?
     ORDER BY id'
);
$stmt->bind_param('i', $oldVersion);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($r = $result->fetch_assoc()) {
    $rows[] = $r;
}
$stmt->close();

out(($execute ? 'Rotating' : 'DRY RUN -') . " master key: version $oldVersion -> $newVersion");
out(sprintf('%d file key(s) would be re-wrapped.', count($rows)));
out('');

if (empty($rows)) {

$update = null;
if ($execute) {
    $update = $conn->prepare(
        'UPDATE file_encryption
            SET file_key_wrapped = ?, nonce = ?, key_version = ?
          WHERE id = ?'
    );
    if (!$update) {
        fwrite(STDERR, "FATAL: could not prepare the UPDATE statement.\n");
        exit(1);
    }
}

$ok = 0;
$failed = 0;

foreach ($rows as $row) {
    $id    = (int) $row['id'];
    $label = $row['table_name'] . '#' . $row['record_id'];

    try {
        // Unwrap with the key this row was actually written under (the row
        // carries its own key_version, so this stays correct even if several
        // versions are in flight), then re-wrap with the NEW key. The
        // per-file key itself never changes - that is why no file is touched.
        $rowVersion = (int) $row['key_version'];
        $aad = StorageEncryption::keyAad($row['table_name'], (int) $row['record_id']);

        $fileKey = StorageEncryption::unwrapKey(
            $row['file_key_wrapped'],
            $row['nonce'],
            $aad,
            $rowVersion
        );

        $new = StorageEncryption::wrapKey($fileKey, $aad, $newVersion);

        if ($execute) {
            $w = $new['wrapped'];
            $n = $new['nonce'];
            $v = $newVersion;
            $update->bind_param('ssii', $w, $n, $v, $id);
            if (!$update->execute()) {
                throw new \RuntimeException('update failed: ' . $update->error);
            }
        }

        $ok++;
        if (!$execute || $ok <= 20) {
            out(sprintf('  %s %-32s v%d -> v%d', $execute ? 'ok  ' : 'plan', $label, $rowVersion, $newVersion));
        }
    } catch (\Throwable $e) {
        $failed++;
        // Deliberately does not echo key material or ciphertext.
        fwrite(STDERR, sprintf('  FAIL %-32s %s' . PHP_EOL, $label, $e->getMessage()));
    }
}

if ($execute && $update) {
    $update->close();
}

out('');
out(sprintf('re-wrapped: %d, failed: %d', $ok, $failed));

if (!$execute) {
    out('');
    out($yellow . 'DRY RUN - nothing was written.' . $nc);
    out('Re-run with --execute to apply. See the header of this file for the full');
    out('safe order of operations (set PREVIOUS, dry-run, bump VERSION, execute).');
    exit(0);
}

if ($failed > 0) {
    fwrite(STDERR, "\n" . $red . 'Rotation finished with failures. Do NOT clear' . PHP_EOL);
    fwrite(STDERR, 'STORAGE_ENCRYPTION_KEY_PREVIOUS - the un-rotated rows still need it.' . $nc . PHP_EOL);
    exit(1);
}

out('');
out($green . 'Rotation complete.' . $nc);
out('Verify with:  php scripts/storage/rotate_master_key.php --status');
out('Once it reports 0 rows at the old version, remove STORAGE_ENCRYPTION_KEY_PREVIOUS');
out('and restart the application.');

    out($green . 'Nothing to do: every file is already at version ' . $newVersion . '.' . $nc);
    exit(0);
}
