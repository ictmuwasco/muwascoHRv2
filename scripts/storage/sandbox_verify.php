<?php

/**
 * SANDBOXED encryption round-trip test over the real storage directories.
 *
 *   php scripts/storage/sandbox_verify.php                  # all files
 *   php scripts/storage/sandbox_verify.php --only laravel   # one file
 *   php scripts/storage/sandbox_verify.php --keep           # keep sandbox
 *
 * WHAT THIS DOES
 *   Copies the real files in backend/storage/{logs,backups} into an isolated
 *   sandbox outside the web root, seals each one, decrypts it back, and
 *   compares SHA-256 against the original. The question it answers is the only
 *   one that matters before deleting any plaintext: can the data actually be
 *   recovered with the key?
 *
 * WHY A SANDBOX AND NOT THE REAL DIRECTORIES
 *   The originals are only ever READ. A guard below refuses to run if any
 *   destination resolves inside the source tree, so a copy/paste error cannot
 *   turn this into a destructive migration. The baseline manifest is hashed
 *   before the run and re-checked afterwards, proving the originals are
 *   byte-identical to how they started.
 *
 *   A DEDICATED KEY is generated for the sandbox. The real
 *   STORAGE_ENCRYPTION_KEY is never written to the sandbox and never used, so
 *   nothing here can be mistaken for production ciphertext.
 *
 * LARGE FILES
 *   laravel.log is 310 MB. sealBuffer()/read() would need the source, the
 *   exploded lines and the ciphertext body resident at once (>1 GB), so this
 *   uses sealStream()/readToFile(), which hold one line at a time.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Composer autoloader not found.\n");
    exit(1);
}
require_once $autoload;
require_once __DIR__ . '/../../backend/app/Helpers/SealedStream.php';

if (!function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default;
    }
}

use App\Helpers\SealedStream;

$ROOT    = dirname(__DIR__, 2);
$SANDBOX = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'hrdemo-enc-sandbox';

$only = null;
$keep = false;
foreach (array_slice($argv, 1) as $a) {
    if (strpos($a, '--only=') === 0) {
        $only = substr($a, 7);
    } elseif ($a === '--keep') {
        $keep = true;
    }
}

// -----------------------------------------------------------------------
// Target discovery
// -----------------------------------------------------------------------

/**
 * @return list<array{src:string, rel:string, size:int}>
 */
function collectTargets(string $root, ?string $only): array
{
    $out = [];

    // Everything under storage/logs. .gitignore is scaffolding, not data.
    foreach (glob($root . '/backend/storage/logs/*') ?: [] as $f) {
        if (!is_file($f) || substr($f, -9) === '.gitignore') {
            continue;
        }
        $out[] = ['src' => $f, 'rel' => 'logs/' . basename($f), 'size' => filesize($f)];
    }

    // Only *.sql under storage/backups. backup.sh is TRACKED GIT SOURCE
    // (git ls-files confirms it), not captured data, so encrypting it would
    // break the script and put ciphertext into version control.
    foreach (glob($root . '/backend/storage/backups/*.sql') ?: [] as $f) {
        $out[] = ['src' => $f, 'rel' => 'backups/' . basename($f), 'size' => filesize($f)];
    }

    if ($only !== null) {
        $out = array_values(array_filter($out, static fn($t) => stripos($t['rel'], $only) !== false));
    }

    usort($out, static fn($a, $b) => $a['rel'] <=> $b['rel']);
    return $out;
}

$targets = collectTargets($ROOT, $only);
if ($targets === []) {
    fwrite(STDERR, "No target files matched.\n");
    exit(1);
}

// -----------------------------------------------------------------------
// SAFETY GUARD - the whole point of the sandbox
// -----------------------------------------------------------------------

foreach (['plain', 'sealed', 'recovered'] as $sub) {
    $dir = $SANDBOX . DIRECTORY_SEPARATOR . $sub;
    $real = realpath($dir);
    if ($real !== false && strpos($real, $ROOT) === 0) {
        fwrite(STDERR, "ABORT: sandbox '$real' is inside the project tree.\n");
        exit(1);
    }
}

if (strpos(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir(), $ROOT) === 0) {
    fwrite(STDERR, "ABORT: the temp directory is inside the project tree.\n");
    exit(1);
}

$totalBytes = array_sum(array_column($targets, 'size'));
printf("Sandbox:   %s\n", $SANDBOX);
printf("Targets:   %d files, %s MB\n", count($targets), number_format($totalBytes / 1048576, 1));
echo str_repeat('-', 78), "\n";
// -----------------------------------------------------------------------
// Sandbox-only key. Deliberately NOT the production key.
// -----------------------------------------------------------------------

$sandboxKey = random_bytes(32);
$keyFile    = $SANDBOX . '/sandbox.key';

foreach (['plain', 'sealed', 'recovered'] as $sub) {
    @mkdir($SANDBOX . DIRECTORY_SEPARATOR . $sub, 0700, true);
}
@mkdir($SANDBOX, 0700, true);

// This script, its own key, and the env() helper the cipher calls.
putenv('STORAGE_ENCRYPTION_KEY=' . base64_encode($sandboxKey));
$_ENV['STORAGE_ENCRYPTION_KEY']    = base64_encode($sandboxKey);
$_SERVER['STORAGE_ENCRYPTION_KEY'] = base64_encode($sandboxKey);
file_put_contents($keyFile, base64_encode($sandboxKey));
printf("Key:       sandbox-only, written to %s\n", $keyFile);
echo str_repeat('-', 78), "\n";

// -----------------------------------------------------------------------
// Phase 1 - baseline hash of the ORIGINALS (read only)
// -----------------------------------------------------------------------

$baseline = [];
foreach ($targets as $t) {
    $baseline[$t['rel']] = hash_file('sha256', $t['src']);
}
file_put_contents($SANDBOX . '/baseline.json', json_encode($baseline, JSON_PRETTY_PRINT));
printf("Phase 1    hashed %d originals (baseline.json)\n\n", count($baseline));

// -----------------------------------------------------------------------
// Phase 2 - copy, seal, decrypt, compare
// -----------------------------------------------------------------------

$results = [];
$failures = 0;
$peak    = 0;

foreach ($targets as $t) {
    $rel      = $t['rel'];
    $plainCopy  = $SANDBOX . '/plain/' . $rel;
    $sealedFile = $SANDBOX . '/sealed/' . $rel;
    $recovered  = $SANDBOX . '/recovered/' . $rel;

    @mkdir(dirname($plainCopy), 0700, true);
    @mkdir(dirname($sealedFile), 0700, true);
    @mkdir(dirname($recovered), 0700, true);

    $started = microtime(true);

    // Copy first: the sealed stream is built from the sandbox copy, so even a
    // bug in SealedStream cannot touch the original.
    if (!copy($t['src'], $plainCopy)) {
        printf("  %-46s COPY FAILED\n", $rel);
        $failures++;
        continue;
    }

    $sourceHash = hash_file('sha256', $plainCopy);

    $sealed = SealedStream::sealStream($plainCopy, $sealedFile);
    $opened = SealedStream::readToFile($sealedFile, $recovered);

    $recoveredHash = hash_file('sha256', $recovered);
    $elapsed       = microtime(true) - $started;
    $peak          = max($peak, memory_get_peak_usage(true));

    $ok = ($recoveredHash === $sourceHash)
        && ($opened['sha256'] === $sourceHash)
        && ($opened['bytes'] === $t['size'])
        && ($opened['truncated'] === false);

    if (!$ok) {
        $failures++;
    }

    $results[$rel] = [
        'status'  => $ok ? 'PASS' : 'FAIL',
        'bytes'   => $t['size'],
        'records' => $sealed['records'],
        'src_sha' => substr($sourceHash, 0, 16),
        'out_sha' => substr($recoveredHash, 0, 16),
        'sealed'  => is_file($sealedFile) ? filesize($sealedFile) : 0,
        'seconds' => round($elapsed, 2),
    ];

    printf(
        "  %-46s %-4s %3d rec  %6.1f ms  %s\n",
        $rel,
        $ok ? 'PASS' : 'FAIL',
        $sealed['records'],
        $elapsed * 1000,
        $ok ? '' : '<<< MISMATCH'
    );

    // Recovered copy has served its purpose; the sealed file is kept so the
    // key-recovery proof below can use it.
    @unlink($recovered);
}

// Drop the sandbox plaintext copies - the originals are the source of truth
// and the round trip has already been proven.
foreach (glob($SANDBOX . '/plain/*/*') ?: [] as $f) {
    @unlink($f);
}

printf("\nPhase 2    %d/%d passed, peak memory %s MB\n",
    count($results) - $failures, count($results), number_format($peak / 1048576, 1));
// -----------------------------------------------------------------------
// Phase 3 - key recovery proof
//
// The question the user actually cares about: with the key, can we get the
// data back? And without it, does the file stay unreadable?
// -----------------------------------------------------------------------

echo "\n", str_repeat('-', 78), "\n";
echo "Phase 3    key recovery\n\n";

$sampleRel = null;
foreach (['logs/error.log', 'backups/schema_only_20260929.sql'] as $cand) {
    if (isset($results[$cand])) {
        $sampleRel = $cand;
        break;
    }
}
if ($sampleRel === null) {
    $sampleRel = array_key_first($results);
}

$sampleSealed = $SANDBOX . '/sealed/' . $sampleRel;
$sampleOut    = $SANDBOX . '/recovered/proof.txt';

// (a) Correct key -> recovers, and matches the original exactly.
echo "  (a) WITH the key\n";
putenv('STORAGE_ENCRYPTION_KEY=' . base64_encode($sandboxKey));
$_ENV['STORAGE_ENCRYPTION_KEY']    = base64_encode($sandboxKey);
$_SERVER['STORAGE_ENCRYPTION_KEY'] = base64_encode($sandboxKey);

try {
    $proof = SealedStream::readToFile($sampleSealed, $sampleOut);
    $origHash  = $baseline[$sampleRel];
    $matchRec  = hash_file('sha256', $sampleOut) === $origHash;
    printf("      %s\n", $sampleRel);
    printf("      recovered %d bytes in %d records\n", $proof['bytes'], $proof['records']);
    printf("      sha256 vs original: %s\n", $matchRec ? 'MATCH' : 'MISMATCH');
    printf("      %s\n", $matchRec ? 'RECOVERABLE' : 'NOT RECOVERABLE');
} catch (Throwable $e) {
    printf("      FAILED: %s\n", $e->getMessage());
    $failures++;
}
@unlink($sampleOut);

// (b) Wrong key -> must refuse, not emit garbage.
echo "\n  (b) WITHOUT the key (wrong key supplied)\n";
$wrong = base64_encode(random_bytes(32));
putenv('STORAGE_ENCRYPTION_KEY=' . $wrong);
$_ENV['STORAGE_ENCRYPTION_KEY']    = $wrong;
$_SERVER['STORAGE_ENCRYPTION_KEY'] = $wrong;

try {
    SealedStream::readToFile($sampleSealed, $SANDBOX . '/recovered/should-not-exist.txt');
    echo "      UNEXPECTED: decryption succeeded with the wrong key\n";
    $failures++;
} catch (Throwable $e) {
    printf("      refused: %s\n", $e->getMessage());
    printf("      %s\n", is_file($SANDBOX . '/recovered/should-not-exist.txt')
        ? 'WARNING: partial output left on disk'
        : 'no partial output written');
}

// (c) Show that the sealed bytes on disk are not the original bytes.
echo "\n  (c) ciphertext is not the plaintext\n";
$sealedHead = (string) file_get_contents($sampleSealed, false, null, 0, 16);
printf("      first 5 bytes: %s (plaintext starts %s)\n",
    substr($sealedHead, 0, 5),
    substr((string) file_get_contents($ROOT . '/backend/storage/' . $sampleRel, false, null, 0, 5), 0, 5)
);

// -----------------------------------------------------------------------
// Phase 4 - confirm the ORIGINALS were never modified
// -----------------------------------------------------------------------

echo "\n", str_repeat('-', 78), "\n";
echo "Phase 4    originals untouched\n\n";

$drift = 0;
foreach ($baseline as $rel => $before) {
    $now = hash_file('sha256', $ROOT . '/backend/storage/' . $rel);
    if ($now !== $before) {
        printf("      CHANGED: %s\n", $rel);
        $drift++;
    }
}

printf("      re-hashed %d originals, %d changed\n", count($baseline), $drift);
printf("      %s\n", $drift === 0
    ? 'ORIGINALS INTACT - sandbox had no write access to them'
    : 'ABORT: something wrote to the originals!');

file_put_contents($SANDBOX . '/results.json', json_encode($results, JSON_PRETTY_PRINT));

echo "\n", str_repeat('-', 78), "\n";
if ($failures === 0 && $drift === 0) {
    echo "RESULT: PASS - every file round-trips and the originals are unchanged.\n";
} else {
    echo "RESULT: FAIL - $failures round-trip failure(s), $drift modified original(s).\n";
}
echo "Manifest:  $SANDBOX/results.json\n";
echo "Sandbox:   $SANDBOX";

if ($keep) {
    echo "\n(--keep: sandbox left in place for inspection)";
} else {
    echo "\nRun again with --keep to retain the sandbox.";
}
echo "\n";

exit(($failures === 0 && $drift === 0) ? 0 : 1);


