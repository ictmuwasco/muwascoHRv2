<?php

declare(strict_types=1);

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Helpers\ApiResponse;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\UserSeeder;

class SeederController extends BaseController
{
    /**
     * Get available seeders
     * GET /api/system/seeders
     */
    public function index(): void
    {
        $this->authorize('system:view');

        $seeders = [
            [
                'name' => 'DepartmentSeeder',
                'description' => 'Seeds departments table with reference data',
                'table' => 'departments',
            ],
            [
                'name' => 'LeaveTypeSeeder',
                'description' => 'Seeds leave types table with reference data',
                'table' => 'leave_types',
            ],
            [
                'name' => 'EmployeeSeeder',
                'description' => 'Seeds employees table with test data',
                'table' => 'employees',
            ],
            [
                'name' => 'UserSeeder',
                'description' => 'Seeds users table with test data',
                'table' => 'users',
            ],
        ];

        ApiResponse::success($seeders, 'Available seeders retrieved successfully');
    }

    /**
     * Run all seeders
     * POST /api/system/seeders/run
     */
    public function runAll(): void
    {
        $this->authorize('system:view');

        try {
            $seeder = new DatabaseSeeder();
            $seeder->run();

            ApiResponse::success(null, 'All seeders executed successfully');
        } catch (\Exception $e) {
            ApiResponse::error('Seeding failed: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Run a specific seeder
     * POST /api/system/seeders/run/{name}
     */
    public function run(string $name): void
    {
        $this->authorize('system:view');

        $seederClass = match ($name) {
            'departments' => DepartmentSeeder::class,
            'leave_types' => LeaveTypeSeeder::class,
            'employees' => EmployeeSeeder::class,
            'users' => UserSeeder::class,
            default => null,
        };

        if ($seederClass === null) {
            ApiResponse::error("Seeder '{$name}' not found", 404);
            return;
        }

        try {
            $seeder = new $seederClass();
            $seeder->run();

            ApiResponse::success(null, "Seeder '{$name}' executed successfully");
        } catch (\Exception $e) {
            ApiResponse::error("Seeding failed: " . $e->getMessage(), 500);
        }
    }

    /**
     * Truncate a table
     * POST /api/system/seeders/truncate/{table}
     */
    public function truncate(string $table): void
    {
        $this->authorize('system:view');

        $allowedTables = ['users', 'employees', 'departments', 'leave_types'];

        if (!in_array($table, $allowedTables)) {
            ApiResponse::error("Table '{$table}' is not allowed for truncation", 400);
            return;
        }

        try {
            $seeder = new DatabaseSeeder();
            $seeder->truncate($table);

            ApiResponse::success(null, "Table '{$table}' truncated successfully");
        } catch (\Exception $e) {
            ApiResponse::error("Truncate failed: " . $e->getMessage(), 500);
        }
    }

    /**
     * Get table status (count, is empty)
     * GET /api/system/seeders/status/{table}
     */
    public function status(string $table): void
    {
        $this->authorize('system:view');

        try {
            $seeder = new DatabaseSeeder();
            $count = $seeder->count($table);
            $isEmpty = $seeder->isEmpty($table);

            ApiResponse::success([
                'table' => $table,
                'count' => $count,
                'is_empty' => $isEmpty,
            ], 'Table status retrieved successfully');
        } catch (\Exception $e) {
            ApiResponse::error("Failed to get status: " . $e->getMessage(), 500);
        }
    }
}