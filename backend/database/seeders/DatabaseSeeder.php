<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Database\Seeder;

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