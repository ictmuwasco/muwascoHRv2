<?php

declare(strict_types=1);

/**
 * adopt_legacy_uploads.php - relocate files from the PREVIOUS application
 * into this application's canonical storage tree.
 *
 * THE PROBLEM THIS SOLVES
 *   employee_documents.file_name stores only a BARE FILENAME
 *   (`6a2680b01ac1d_maryID.pdf`), and employees.profile_image_url stores a
 *   path RELATIVE to the old webroot (`uploads/profile_images/x.webp`). The
 *   previous application wrote those files under ITS OWN `uploads/` tree; this
 *   application looks under `backend/storage/uploads/`. Same names, different
 *   folders, so every document 404s until the bytes are moved.
 *
 *   Because only the basename is stored, no database rewrite is required for
 *   documents - the file simply has to exist where FileLocator looks first.
 *   That is the entire point: this is a FILE migration, not a data migration.
 *
 * WHAT IT DOES, PER FILE
 *   1. finds the file in any known source root (canonical or legacy)
 *   2. copies it to the canonical directory
 *   3. compares SHA-256 of source vs destination
 *   4. only then records the move in its manifest
 *
 * Step 3 is the safety property. A copy that cannot be proven byte-identical
 * is reported as FAILED and the destination is removed, so a truncated write
 * or a full disk never leaves a corrupt file that the application would
 * happily serve as a valid document.
 *
 * NOTHING IS EVER DELETED FROM THE SOURCE
 *   The legacy tree is left completely intact. Deleting the old copy is a
 *   separate, manual decision taken after the migration is verified - the one
 *   irreversible step in this whole procedure is deliberately not automated.
 *
 * USAGE
 *   php scripts/storage/adopt_legacy_uploads.php --dry-run
 *       Report what would happen. Writes nothing. Run this first, always.
 *   php scripts/storage/adopt_legacy_uploads.php --execute
 *       Perform the copy.
 *
 * OPTIONS
 *   --source=DIR      Extra directory to import from, repeatable. Use this to
 *                     point at the old application's `uploads/` folder when it
 *                     is not one of the auto-detected roots.
 *   --only=documents|images|both   Narrow the run (default: both).
 *   --limit=N         Process at most N files (default: all).
 *   --reset           Discard resume state and start over.
 *   --state=FILE      Resume/manifest file (default:
 *                     backend/storage/cache/legacy_upload_adoption.json).
 *
 * IDEMPOTENCY
 *   A file already present in the canonical directory is skipped, so re-running
 *   after an interruption resumes rather than repeating. Verified moves are
 *   recorded by name, not by index, so a partial run is safe to continue.
 *
 * THEN ENCRYPT
 *   Once the files are in place and serving correctly, the at-rest encryption
 *   pass is a separate command and is NOT run here:
 *       php scripts/storage/encrypt_existing_files.php --dry-run
 *       php scripts/storage/encrypt_existing_files.php --execute
 *
 * BACK UP FIRST. Take a copy of the database and of the legacy uploads tree
 * before running --execute.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Helpers\FileLocator;

$options = array_slice($argv, 1);
$execute = in_array('--execute', $options, true);
$dryRun  = in_array('--dry-run', $options, true);
$reset   = in_array('--reset', $options, true);
$limit   = null;
$only    = 'both';
$stateFile = null;
$sources = [];

foreach ($options as $opt) {
    if (preg_match('/^--limit=(\d+)$/', $opt, $m) === 1) {
        $limit = (int) $m[1];
    } elseif (preg_match('/^--only=(documents|images|both)$/', $opt, $m) === 1) {
        $only = $m[1];
    } elseif (preg_match('/^--state=(.+)$/', $opt, $m) === 1) {
        $stateFile = $m[1];
    } elseif (preg_match('/^--source=(.+)$/', $opt, $m) === 1) {
        $sources[] = $m[1];
    }
}

if (!$execute && !$dryRun && !$reset) {
    fwrite(STDERR, "Usage: php scripts/storage/adopt_legacy_uploads.php "
        . "[--dry-run|--execute] [--source=DIR] [--only=documents|images|both] "
        . "[--limit=N] [--reset]\n");
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

$stateFile = $stateFile ?? (STORAGE_PATH . '/cache/legacy_upload_adoption.json');

if ($reset && is_file($stateFile)) {
    @unlink($stateFile);
    out('Resume state cleared.');
    if (!$execute && !$dryRun) {
        exit(0);
    }
}

$state = ['moved' => [], 'failed' => []];
if (is_file($stateFile)) {
    $decoded = json_decode((string) file_get_contents($stateFile), true);
    if (is_array($decoded) && isset($decoded['moved']) && is_array($decoded['moved'])) {
        $state = $decoded;
    }
}

/**
 * Every directory a legacy file might be sitting in.
 *
 * The three FileLocator candidates come first so a file already visible to the
 * application is never treated as an external import. `--source` entries are
 * appended, which is how the old application's own `uploads/` folder is
 * brought in when it lives outside this repository.
 *
 * @param list<string> $extra
 * @return list<string>
 */
function documentSourceRoots(array $extra): array
{
    $roots = FileLocator::documentCandidates('__probe__');
    // Strip the trailing probe filename, leaving the directories.
    $dirs = array_map(static fn(string $p): string => dirname($p), $roots);
    $dirs[] = dirname(STORAGE_PATH) . '/public/uploads/employee_documents';

    return dedupe($dirs, $extra);
}

/**
 * @param list<string> $extra
 * @return list<string>
 */
function imageSourceRoots(array $extra): array
{
    $roots = FileLocator::profileImageCandidates('__probe__');
    $dirs = array_map(static fn(string $p): string => dirname($p), $roots);
    $dirs[] = BASE_PATH . '/uploads/profile_images';

    return dedupe($dirs, $extra);
}

/**
 * @param list<string> $dirs
 * @param list<string> $extra
 * @return list<string>
 */
function dedupe(array $dirs, array $extra): array
{
    $out = [];
    foreach (array_merge($dirs, $extra) as $dir) {
        $real = realpath($dir);
        if ($real === false) {
            continue;
        }
        $out[$real] = true;
    }

    return array_keys($out);
}

$conn = \db()->getConnection();

/**
 * Copy one file into the canonical directory and prove it arrived intact.
 *
 * The destination is written to a temporary sibling first and only renamed
 * into place once the hashes match, so the canonical directory never contains
 * a partially written document - not even for the microseconds a reader would
 * need to catch it.
 *
 * @return array{status:string,bytes:int,source:string,dest:?string}
 */
function adoptOne(string $source, string $destDir, string $fileName): array
{
    $dest = $destDir . '/' . $fileName;

    if (is_file($dest)) {
        // Already canonical. Confirm it is a real file rather than assume it.
        $bytes = (int) filesize($dest);
        if ($bytes === (int) filesize($source) && hash_equals(
            (string) hash_file('sha256', $source),
            (string) hash_file('sha256', $dest)
        )) {
            return ['status' => 'present', 'bytes' => $bytes, 'source' => $source, 'dest' => $dest];
        }

        // Same name, different content. Never overwrite: the existing file is
        // what the application is currently serving, and silently replacing a
        // live document is not a decision a migration script gets to make.
        return ['status' => 'conflict', 'bytes' => $bytes, 'source' => $source, 'dest' => $dest];
    }

    if (!is_dir($destDir) && !mkdir($destDir, 0750, true) && !is_dir($destDir)) {
        return ['status' => 'failed', 'bytes' => 0, 'source' => $source, 'dest' => null];
    }

    $tmp = $destDir . '/.adopt.' . bin2hex(random_bytes(8)) . '.part';

    try {
        if (!copy($source, $tmp)) {
            throw new \RuntimeException('copy() failed');
        }

        $srcHash = hash_file('sha256', $source);
        $dstHash = hash_file('sha256', $tmp);

        if (!hash_equals((string) $srcHash, (string) $dstHash)) {
            throw new \RuntimeException('SHA-256 mismatch after copy');
        }

        if (!rename($tmp, $dest)) {
            throw new \RuntimeException('could not move staged file into place');
        }
        $tmp = null;

        @chmod($dest, 0640);

        return ['status' => 'copied', 'bytes' => (int) filesize($dest), 'source' => $source, 'dest' => $dest];
    } catch (\Throwable $e) {
        // Remove the partial file. The SOURCE is untouched in every branch
        // here, so a failure costs nothing but a re-run.
        if ($tmp !== null && is_file($tmp)) {
            @unlink($tmp);
        }
        return ['status' => 'failed', 'bytes' => 0, 'source' => $source, 'dest' => null];
    }
}

// ---------------------------------------------------------------------------
// Documents: driven by the DATABASE, not by the folder.
//
// Iterating the folder would import orphans nobody references; iterating the
// table reports exactly which employee records are unopenable, which is the
// question actually being asked.
// ---------------------------------------------------------------------------

$counters = ['copied' => 0, 'present' => 0, 'conflict' => 0, 'failed' => 0, 'missing' => 0];
$processed = 0;
$misses = [];

$docRoots = documentSourceRoots($sources);
$imgRoots = imageSourceRoots($sources);

out('');
out(($execute ? 'MIGRATING' : 'DRY RUN -') . ' legacy upload adoption');
out('  canonical documents : ' . FileLocator::documentsDir());
out('  canonical images    : ' . FileLocator::profileImagesDir());
out('  source roots        : ' . count($docRoots) . ' (documents), ' . count($imgRoots) . ' (images)');
foreach (array_merge($docRoots, $imgRoots) as $root) {
    out('      ' . $root);
}
out('');

/**
 * Locate a file across the source roots.
 *
 * The canonical directory is excluded from the search: a file already there is
 * 'present', not something to import from somewhere else.
 *
 * @param list<string> $roots
 */
function findIn(array $roots, string $fileName, string $canonicalDir): ?string
{
    $safe = FileLocator::safeBasename($fileName);
    if ($safe === null) {
        return null;
    }

    foreach ($roots as $root) {
        $path = $root . '/' . $safe;
        if (is_file($path) && rtrim($root, '/') !== rtrim($canonicalDir, '/')) {
            return $path;
        }
    }

    return null;
}

if ($only === 'documents' || $only === 'both') {
    out('--- documents (employee_documents) ---');

    $sql = 'SELECT id, employee_id, document_name, file_name FROM employee_documents ORDER BY id';
    $res = $conn->query($sql);

    if ($res === false) {
        out('  cannot read employee_documents: ' . $conn->error);
        exit(1);
    }

    while ($row = $res->fetch_assoc()) {
        if ($limit !== null && $processed >= $limit) {
            out('  ... --limit=' . $limit . ' reached; re-run to continue');
            break;
        }
        $processed++;

        $fileName = (string) $row['file_name'];
        $key = 'doc#' . $row['id'] . ':' . $fileName;

        if (FileLocator::safeBasename($fileName) === null) {
            $counters['failed']++;
            $misses[] = $key . ' (unsafe file_name)';
            continue;
        }

        // Already canonical and untouched by this run.
        $canonical = FileLocator::documentsDir() . '/' . $fileName;
        if (is_file($canonical) && !in_array($key, $state['failed'], true)) {
            $counters['present']++;
            continue;
        }

        $source = findIn($docRoots, $fileName, FileLocator::documentsDir());

        if ($source === null) {
            $counters['missing']++;
            // Listing missing rows is the actionable output: each one is a
            // document an employee can currently not open.
            $misses[] = $key;
            if (count($misses) <= 40) {
                out(sprintf('  MISS  %-58s not found in any source root', $key));
            } elseif (count($misses) === 41) {
                out('  ... (further missing files omitted)');
            }
            continue;
        }

        if (!$execute) {
            out(sprintf('  plan  %-58s %8d bytes  from %s', $key, (int) filesize($source), basename(dirname($source))));
            $counters['copied']++;
            continue;
        }

        $result = adoptOne($source, FileLocator::documentsDir(), $fileName);
        $counters[$result['status']] = ($counters[$result['status']] ?? 0) + 1;

        if ($result['status'] === 'copied') {
            $state['moved'][] = $key;
            $state['failed'] = array_values(array_diff($state['failed'], [$key]));
        } elseif ($result['status'] === 'failed') {
            $state['failed'][] = $key;
        }

        if ($result['status'] !== 'present') {
            out(sprintf('  %-5s %-58s %8d bytes', $result['status'], $key, $result['bytes']));
        }

        @file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);
    }

    $res->free();
    out('');
}

// ---------------------------------------------------------------------------
// Profile images
//
// profile_image_url holds a RELATIVE path, so the basename is what identifies
// the file. Only employees whose stored value points at a real image are
// touched - no UPDATE is issued at all, because the canonical location is
// resolved from that same stored value and therefore already correct.
// ---------------------------------------------------------------------------

if ($only === 'images' || $only === 'both') {
    out('--- profile images (employees.profile_image_url) ---');

    $sql = "SELECT id, employee_id, first_name, profile_image_url
            FROM employees
            WHERE profile_image_url IS NOT NULL AND profile_image_url <> ''
            ORDER BY id";
    $res = $conn->query($sql);

    if ($res === false) {
        out('  cannot read employees: ' . $conn->error);
        exit(1);
    }

    while ($row = $res->fetch_assoc()) {
        $processed++;
        $stored = (string) $row['profile_image_url'];
        // profile_image_url is a RELATIVE path (uploads/profile_images/x.webp),
        // not a bare filename, so it must go through the prefix-stripping
        // normalizer rather than safeBasename() - which correctly rejects any
        // value containing a separator.
        $safe = FileLocator::normalizeProfileImageName($stored);
        $key = 'img#' . $row['id'] . ':' . ($safe ?? basename(str_replace('\\', '/', $stored)));

        if ($safe === null) {
            $counters['failed']++;
            out(sprintf('  FAIL  %-58s unsafe profile_image_url', $key));
            continue;
        }

        // Resolve exactly as the application would. If this succeeds the
        // employee can already see their picture - nothing to do.
        if (FileLocator::resolve(FileLocator::profileImageCandidates($stored)) !== null) {
            $counters['present']++;
            continue;
        }

        $source = findIn($imgRoots, $safe, FileLocator::profileImagesDir());

        if ($source === null) {
            $counters['missing']++;
            out(sprintf('  MISS  %-58s no image file in any source root', $key));
            continue;
        }

        if (!$execute) {
            out(sprintf('  plan  %-58s %8d bytes  from %s', $key, (int) filesize($source), basename(dirname($source))));
            $counters['copied']++;
            continue;
        }

        $result = adoptOne($source, FileLocator::profileImagesDir(), $safe);
        $counters[$result['status']] = ($counters[$result['status']] ?? 0) + 1;

        if ($result['status'] === 'copied') {
            $state['moved'][] = $key;
            $state['failed'] = array_values(array_diff($state['failed'], [$key]));
        } elseif ($result['status'] === 'failed') {
            $state['failed'][] = $key;
        }

        out(sprintf('  %-5s %-58s %8d bytes', $result['status'], $key, $result['bytes']));
    }

    $res->free();
    out('');
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------

out('summary');
out(sprintf('  copied/present : %d', $counters['copied'] + $counters['present']));
out(sprintf('  missing        : %d  (no file anywhere - restore from backup)', $counters['missing']));
out(sprintf('  conflicts      : %d  (name exists with different content - NOT overwritten)', $counters['conflict']));
out(sprintf('  failed         : %d', $counters['failed']));

if (!$execute) {
    out('');
    out($yellow . 'DRY RUN - nothing was written.' . $nc);
    out('Re-run with --execute to perform the migration.');
    if ($counters['missing'] > 0) {
        out('');
        out($yellow . 'Missing files cannot be recovered by this script.' . $nc);
        out('They exist only in a backup of the old uploads folder. Point --source=');
        out('at that backup and re-run, or accept that those documents are gone.');
    }
    exit(0);
}

@file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);

out('');
if ($counters['failed'] > 0 || $counters['conflict'] > 0) {
    out($yellow . 'Completed with failures.' . $nc);
    out('Review the lines above. Re-running retries what failed.');
    exit(1);
}

out($green . 'All discovered files are in canonical storage.' . $nc);
out('');
out('The legacy folders were NOT modified - nothing was deleted from them.');
out('Next steps:');
out('  1. Verify in the browser that documents and photos open.');
out('  2. Encrypt at rest:');
out('       php scripts/storage/encrypt_existing_files.php --dry-run');
out('       php scripts/storage/encrypt_existing_files.php --execute');
out('  3. Only once step 2 verifies, archive the old uploads folder.');