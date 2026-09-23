<?php

declare(strict_types=1);

// CLI-only (S-SEC-07): runs database seeders and must never run over HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit('Not Found');
}

/**
 * Database Seeder Runner
 *
 * Complements the composer "seed" script entry:
 *   php backend/database/seed.php                        # run ALL seeders (idempotent)
 *   php backend/database/seed.php MeetingSeeder          # run one seeder by class name
 *   php backend/database/seed.php MeetingSeeder --fresh  # wipe + reseed (seeder-dependent)
 *
 * Boots through the production bootstrap (autoload, env, config, DB) exactly
 * like backend/database/run.php does for migrations.
 */

require_once __DIR__ . '/../bootstrap.php';

use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\MeetingSeeder;
use Database\Seeders\SecurityPermissionsSeeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\AttendanceSeeder;

$args = array_slice($argv ?? [], 1);
$fresh = in_array('--fresh', $args, true);
$targets = array_values(array_filter($args, static fn (string $a): bool => $a !== '--fresh'));

$registry = [
    'DatabaseSeeder'             => DatabaseSeeder::class,
    'DepartmentSeeder'           => DepartmentSeeder::class,
    'LeaveTypeSeeder'            => LeaveTypeSeeder::class,
    'EmployeeSeeder'             => EmployeeSeeder::class,
    'UserSeeder'                 => UserSeeder::class,
    'SecurityPermissionsSeeder'  => SecurityPermissionsSeeder::class,
    'MeetingSeeder'              => MeetingSeeder::class,
    'AttendanceSeeder'           => AttendanceSeeder::class,
];

echo "MUWASCO HR Database Seeder\n";
echo str_repeat('=', 50) . "\n\n";

try {
    if ($targets === []) {
        echo "Running ALL seeders (idempotent)...\n";
        (new DatabaseSeeder())->run();
    } else {
        foreach ($targets as $name) {
            $class = $registry[$name] ?? null;
            if ($class === null) {
                fwrite(STDERR, "Unknown seeder '{$name}'. Available: " . implode(', ', array_keys($registry)) . "\n");
                exit(1);
            }
            echo "Running {$name}...\n";
            $seeder = new $class();
            if ($seeder instanceof MeetingSeeder && $fresh) {
                $seeder->fresh();
            }
            $seeder->run();
        }
    }
    echo "\n" . str_repeat('=', 50) . "\nSeeding completed successfully.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'SEEDING FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
