<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// Set test environment
$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_DEBUG'] = 'true';

// Change to backend directory so relative paths work
chdir(__DIR__);

// Use the PHPUnit TextUI command
$phpunit = new \PHPUnit\TextUI\Command();

// Disable code coverage
$GLOBALS['PHPUNIT_DISABLE_CODE_COVERAGE'] = true;

// Build arguments as a simple array.
// The canonical suite configuration is the tracked root phpunit.xml.dist:
// it covers ALL Unit test directories (the old backend/phpunit.xml only
// wired Services/Repositories/Controllers, silently skipping the
// Authorization/Delegation/Helpers/Middleware/Security/Validators suites).
$arguments = [
    'phpunit',
    '--configuration', dirname(__DIR__) . '/phpunit.xml.dist',
    '--verbose',
    '--colors',
];

// Set argv
$_SERVER['argv'] = $arguments;
$_SERVER['argc'] = count($_SERVER['argv']);

try {
    $exitCode = $phpunit->run($_SERVER['argv'], true);
    exit($exitCode);
} catch (\Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}