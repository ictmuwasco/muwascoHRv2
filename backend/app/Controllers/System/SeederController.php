<?php

declare(strict_types=1);

namespace App\Controllers\System;

use App\Controllers\BaseController;
use App\Helpers\ApiResponse;
use App\Services\AuditService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\LeaveTypeSeeder;
use Database\Seeders\EmployeeSeeder;
use Database\Seeders\MeetingSeeder;
use Database\Seeders\UserSeeder;
use Database\Seeders\AttendanceSeeder;

class SeederController extends BaseController
{
    /**
     * Get available seeders
     * GET /api/system/seeders
     */
    public function index(): void
    {
        $this->requirePermission('system', 'view');

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
            [
                'name' => 'AttendanceSeeder',
                'description' => 'Backfills realistic recent attendance (last 14 workdays) for the live dashboard widgets',
                'table' => 'attendance',
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
        $this->requirePermission('system', 'manage');

        try {
            $seeder = new DatabaseSeeder();
            $seeder->run();

            AuditService::getInstance()->log(
                AuditService::MODULE_SYSTEM,
                AuditService::ACTION_RUN_SEEDER,
                'All seeders executed via system seeders dashboard',
                ['target_type' => 'Database', 'target_name' => 'DatabaseSeeder']
            );

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
        $this->requirePermission('system', 'manage');

        $seederClass = match ($name) {
            'departments' => DepartmentSeeder::class,
            'leave_types' => LeaveTypeSeeder::class,
            'employees' => EmployeeSeeder::class,
            'users' => UserSeeder::class,
            'meetings' => MeetingSeeder::class,
            'attendance' => AttendanceSeeder::class,
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
        $this->requirePermission('system', 'manage');

        $allowedTables = ['users', 'employees', 'departments', 'leave_types'];

        if (!in_array($table, $allowedTables)) {
            ApiResponse::error("Table '{$table}' is not allowed for truncation", 400);
            return;
        }

        try {
            $seeder = new DatabaseSeeder();
            $seeder->truncate($table);

            AuditService::getInstance()->log(
                AuditService::MODULE_SYSTEM,
                AuditService::ACTION_TRUNCATE,
                "Table '{$table}' truncated via system seeders dashboard",
                ['target_type' => 'Table', 'target_name' => $table]
            );

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
        $this->requirePermission('system', 'view');

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