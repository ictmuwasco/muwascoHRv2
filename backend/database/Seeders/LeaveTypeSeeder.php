<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeder;

/**
 * Leave Type Seeder
 * 
 * Seeds reference data for leave types.
 */
class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        if (!$this->isEmpty('leave_types')) {
            return; // Already seeded
        }

        $this->seed('leave_types', [
            [
                'name' => 'Annual Leave',
                'description' => 'Annual vacation leave',
                'days_allowed' => 21,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Sick Leave',
                'description' => 'Medical sick leave',
                'days_allowed' => 14,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Maternity Leave',
                'description' => 'Maternity leave',
                'days_allowed' => 90,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Paternity Leave',
                'description' => 'Paternity leave',
                'days_allowed' => 14,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Study Leave',
                'description' => 'Educational study leave',
                'days_allowed' => 7,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Compassionate Leave',
                'description' => 'Bereavement and emergency leave',
                'days_allowed' => 5,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Unpaid Leave',
                'description' => 'Leave without pay',
                'days_allowed' => 30,
                'is_active' => 1,
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }
}
