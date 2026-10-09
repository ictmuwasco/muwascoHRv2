<?php

declare(strict_types=1);

/**
 * policy_parse_worker.php - CLI worker: extracts HR policy section trees.
 *
 * WHY THIS EXISTS
 *   Uploading the MUWASCO manual used to 500 on production with no log line
 *   and no audit row. That signature is a PHP FATAL, not an exception:
 *   DocumentParser::parse() ran inside the web request, where Plesk pins
 *   memory_limit=128M and max_execution_time=30 via php_admin_value, so
 *   DocumentParser::ensureParseHeadroom() could not raise them. A fatal is not
 *   a Throwable, so the controller's catch never ran - nothing was logged, no
 *   cleanup happened, and HR got a bare 500. Each retry also left another
 *   orphaned file in private storage.
 *
 *   Verified on the production host: CLI PHP runs memory_limit=-1 and
 *   max_execution_time=0. Moving the parse here is what makes it work.
 *   The upload endpoint now stores the file and returns 201 immediately with
 *   parse_status='pending'; this worker does the heavy part.
 *
 * INSTALL
 *   Every minute is enough for a handful of uploads:
 *
 *     * * * * * php /var/www/vhosts/muwascoerp.co.ke/app.muwascoerp.co.ke/backend/cron/policy_parse_worker.php --quiet
 *
 *   Or after each upload, immediately (best latency, also fine):
 *
 *     php backend/cron/policy_parse_worker.php --id=123
 *
 *   Windows Task Scheduler (local dev):
 *     Program : C:\xampp\php\php.exe
 *     Args    : C:\xampp\htdocs\hrdemo\backend\cron\policy_parse_worker.php --quiet
 *     Trigger : Daily, repeat every 1 minute, indefinite duration
 *
 * USAGE
 *   php backend/cron/policy_parse_worker.php              # drain due rows
 *   php backend/cron/policy_parse_worker.php --id=123     # one document
 *   php backend/cron/policy_parse_worker.php --limit=10   # batch size
 *   php backend/cron/policy_parse_worker.php --dry-run    # report only
 *   php backend/cron/policy_parse_worker.php --quiet      # cron-friendly
 *
 *   Overlapping runs are safe: each document is claimed with a conditional
 *   UPDATE, so a row another run already took is simply not seen by this one.
 *   A file that failed 3 times is left alone rather than retried forever.
 *
 * EXIT CODES
 *   0 = ran successfully (including "nothing to do")
 *   1 = failed
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Models\HrPolicyDocument;
use App\Services\HrPolicy\PolicyService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

// Belt and braces. CLI already runs unlimited here, but an operator may pin
// these in php.ini, and a fatal in a worker is silent by definition. Raising
// them explicitly keeps a slow parse from dying with no explanation.
@ini_set('memory_limit', '-1');
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

$argv = $_SERVER['argv'] ?? [];
$args = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/i', $arg, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$dryRun  = isset($args['dry-run']);
$quiet   = isset($args['quiet']);
$idOnly  = isset($args['id']) ? (int) $args['id'] : null;
$limit   = isset($args['limit']) ? max(1, min(50, (int) $args['limit'])) : 5;

if (isset($args['help'])) {
    echo "Usage: policy_parse_worker.php [--id=N] [--limit=N] [--dry-run] [--quiet]\n";
    exit(0);
}

$start = microtime(true);
$line  = static function (string $text) use ($quiet): void {
    if (!$quiet) {
        echo $text . "\n";
    }
};

try {
    if ($idOnly !== null) {
        $docs = [HrPolicyDocument::find($idOnly)];
        $docs = array_values(array_filter($docs, static fn($d) => $d !== null));
    } else {
        $docs = HrPolicyDocument::pendingParsing($limit);
    }

    if (empty($docs)) {
        $line('No policies awaiting section extraction.');
        exit(0);
    }

    $line(sprintf('policy_parse_worker: %d document(s) to process.', count($docs)));

    $done = 0;
    $skipped = 0;
    $failed = 0;

    foreach ($docs as $doc) {
        $id = (int) $doc['id'];
        $label = sprintf(
            '#%d "%s" v%s',
            $id,
            (string) $doc['title'],
            (string) $doc['version']
        );

        // An explicit --id= is a targeted retry: the caller has already decided
        // this document should be processed. The drain path must CLAIM instead,
        // or two overlapping cron runs would both parse the same document.
        if ($idOnly !== null && !$dryRun) {
            // Already parsed is NOT an error - it is the normal result of
            // re-running the worker or clicking retry twice. Report it and move
            // on rather than throwing, so a stray cron line raises no false alarm.
            $current = (string) ($doc['parse_status'] ?? '');
            if ($current === HrPolicyDocument::PARSE_DONE && (int) $doc['section_count'] > 0) {
                $done++;
                $line(sprintf(
                    '  [ok]   %s - already extracted, %d sections. Nothing to do.',
                    $label,
                    (int) $doc['section_count']
                ));
                continue;
            }

            // Extract directly rather than via retryExtraction(): that helper
            // resets parse_attempts to 0, which would erase the record of how
            // many times a permanently-broken file has already been tried and
            // let a doomed file be retried forever. Bump the counter here so
            // the PARSE_MAX_ATTEMPTS cap still bites on this path.
            \db()->query(
                'UPDATE hr_policy_documents
                    SET parse_status = ?, parse_attempts = parse_attempts + 1
                  WHERE id = ?',
                'si',
                [HrPolicyDocument::PARSE_PROCESSING, $id]
            );

            $result = PolicyService::extractSections($id);
        } else {
            if ($dryRun) {
                $line(sprintf('  [dry-run] %s (parse_status=%s, attempts=%d)', $label, $doc['parse_status'], (int) $doc['parse_attempts']));
                continue;
            }

            if (!HrPolicyDocument::claimForParsing($id)) {
                $skipped++;
                $line(sprintf('  [skip] %s - already claimed or out of attempts.', $label));
                continue;
            }

            $result = PolicyService::extractSections($id);
        }

        // Per-document duration is the useful number when tuning the worker:
        // a single 150-page manual is exactly the case that used to die here.
        $elapsed = microtime(true) - $start;

        if ($result['status'] === HrPolicyDocument::PARSE_DONE) {
            $done++;
            $line(sprintf('  [ok]   %s - %d sections in %.1fs.', $label, $result['sections'], $elapsed));
        } else {
            $failed++;
            $line(sprintf('  [fail] %s - %s (%.1fs)', $label, (string) $result['error'], $elapsed));
        }
    }

    $line(sprintf(
        'policy_parse_worker: done=%d failed=%d skipped=%d in %.2fs',
        $done,
        $failed,
        $skipped,
        microtime(true) - $start
    ));

    exit(0);
} catch (\Throwable $e) {
    // A worker crash must be visible, never silent.
    fwrite(STDERR, sprintf(
        "policy_parse_worker: %s: %s in %s:%d\n",
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));
    error_log('[policy_parse_worker] ' . $e->getMessage());
    exit(1);
}
