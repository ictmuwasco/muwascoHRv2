<?php

declare(strict_types=1);

namespace Database\Seeders;

// The base Seeder lives in backend/database/Seeder.php (namespace Database\).
// An earlier "App\Database\Seeder" import made DatabaseSeeder un-instantiable,
// breaking the "run ALL seeders" path (php backend/database/seed.php without
// arguments and POST /api/system/seeders/run) while individual seeder runs
// kept working — which is why it went unnoticed.
use Database\Seeder;

/**
 * Main database seeder that runs all seeders in order
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Seeders to run in order
     */
    private array $seeders = [
        DepartmentSeeder::class,
        LeaveTypeSeeder::class,
        EmployeeSeeder::class,
        UserSeeder::class,
        SecurityPermissionsSeeder::class,
        MeetingSeeder::class,
        AttendanceSeeder::class,
    ];

    /**
     * Run all seeders
     */
    public function run(): void
    {
        $this->callMany($this->seeders);
    }

    /**
     * Run a specific seeder
     */
    public function runSeeder(string $seederClass): void
    {
        $this->call($seederClass);
    }

    /**
     * Get list of available seeders
     */
    public function getAvailableSeeders(): array
    {
        return $this->seeders;
    }
}