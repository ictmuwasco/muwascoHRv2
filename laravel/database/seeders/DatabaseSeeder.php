<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            UserSeeder::class,
            DepartmentSeeder::class,
            SectionSeeder::class,
            LeaveTypeSeeder::class,
            FinancialYearSeeder::class,
            SettingSeeder::class,
        ]);
    }
}