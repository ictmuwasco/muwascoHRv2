<?php
/**
 * Consolidated health check. Run: php tools/verify-hr-health.php
 *
 * The guard rail matters: a PHP class file truncated to 0 bytes by a bad edit
 * passes `php -l` (an empty file is valid) and passes string-matching tests,
 * but fatals at runtime with "Class not found" -> a 500 in the browser. That
 * happened here, so every file this feature depends on is size-checked and
 * class-verified here.
 */
$root = dirname(__DIR__);
require_once $root . '/backend/bootstrap.php';

$ok = true;
$check = static function (string $label, bool $pass) use (&$ok): void {
    printf("  %-56s %s\n", $label, $pass ? 'PASS' : 'FAIL');
    $ok = $ok && $pass;
};

echo "File integrity (a 0-byte PHP file still passes php -l)\n";
$files = [
    'backend/app/Services/Appraisal/AppraisalReportService.php',
    'backend/app/Services/Appraisal/AppraisalWorkflowService.php',
    'backend/app/Controllers/HR/AppraisalReportController.php',
    'backend/app/Services/AuthService.php',
    'backend/app/Controllers/Auth/AuthController.php',
    'backend/app/Services/Contracts/AuthServiceInterface.php',
    'api.php',
];
foreach ($files as $rel) {
    $path = $root . '/' . $rel;
    $bytes = is_file($path) ? filesize($path) : 0;
    $check(sprintf('%s (%d bytes)', basename($rel), $bytes), $bytes > 500);
}

echo "\nClasses autoload\n";
foreach ([
    'App\Services\Appraisal\AppraisalReportService',
    'App\Services\Appraisal\AppraisalWorkflowService',
    'App\Controllers\HR\AppraisalReportController',
    'Dompdf\Dompdf',
    'PhpOffice\PhpWord\PhpWord',
] as $class) {
    $check($class, class_exists($class));
}

echo "\nService contract\n";
$rc = new ReflectionClass(\App\Services\Appraisal\AppraisalWorkflowService::class);
$check('mayReviewRow() public', $rc->getMethod('mayReviewRow')->isPublic());
$check('canReviewTarget() still private', !$rc->getMethod('canReviewTarget')->isPublic());
$check(
    'AppraisalReportService uses mayReviewRow (not the private one)',
    str_contains(
        (string) file_get_contents($root . '/backend/app/Services/Appraisal/AppraisalReportService.php'),
        '$this->workflow->mayReviewRow($row)'
    )
);

echo "\nRoute gates (officers need feedback, filters stay supervise-only)\n";
$api = file_get_contents($root . '/api.php');
preg_match("~'/appraisals/completed',\s*AppraisalReportController::class, 'completedAction', '([^']+)'~", $api, $g1);
preg_match("~'/appraisals/completed/filters',\s*AppraisalReportController::class, 'filtersAction',\s*'([^']+)'~", $api, $g2);
$check('/completed gated on performance:feedback', ($g1[1] ?? '') === 'performance:feedback');
$check('/filters gated on performance:supervise', ($g2[1] ?? '') === 'performance:supervise');

echo "\nSession lifetimes\n";
$env = file_get_contents($root . '/.env');
$check('access token 8h (28800)', str_contains($env, 'JWT_ACCESS_TOKEN_EXPIRY=28800'));
$check('refresh token 30d (2592000)', str_contains($env, 'JWT_REFRESH_TOKEN_EXPIRY=2592000'));

echo "\nRuntime prerequisites\n";
$check('ext-gd loaded (required for the PDF logo)', extension_loaded('gd'));
$check('refresh_tokens table exists', (bool) \App\Helpers\Database::getInstance()
    ->getConnection()->query("SHOW TABLES LIKE 'refresh_tokens'")->num_rows);

echo $ok ? "\nHEALTH CHECK PASSED\n" : "\nHEALTH CHECK FAILED\n";
exit($ok ? 0 : 1);
