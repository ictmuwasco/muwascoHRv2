<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LeaveTypeSeeder extends Seeder
{
    public function run(): void
    {
        $leaveTypes = [
            ['name' => 'Annual Leave', 'code' => 'ANNUAL', 'days_per_year' => 30, 'is_paid' => 1],
            ['name' => 'Sick Leave', 'code' => 'SICK', 'days_per_year' => 15, 'is_paid' => 1],
            ['name' => 'Maternity Leave', 'code' => 'MATERNITY', 'days_per_year' => 90, 'is_paid' => 1],
            ['name' => 'Paternity Leave', 'code' => 'PATERNITY', 'days_per_year' => 14, 'is_paid' => 1],
            ['name' => 'Compassionate Leave', 'code' => 'COMPASSIONATE', 'days_per_year' => 5, 'is_paid' => 1],
            ['name' => 'Unpaid Leave', 'code' => 'UNPAID', 'days_per_year' => 0, 'is_paid' => 0],
        ];

        foreach ($leaveTypes as $type) {
            DB::table('leave_types')->updateOrInsert(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}