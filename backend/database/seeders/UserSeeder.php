<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Database\Seeder;
use App\Helpers\Hash;

class UserSeeder extends Seeder
{
    /**
     * Seed users table with test data
     */
    public function run(): void
    {
        // Skip if users already exist
        if (!$this->isEmpty('users')) {
            return;
        }

        // Admin user
        $this->seed('users', [
            'employee_id' => 1,
            'email' => 'admin@muwasco.org',
            'password' => Hash::make($_ENV['SEED_ADMIN_PASSWORD'] ?? 'ChangeMe!'.random_int(1000, 9999)),
            'role' => 'admin',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // HR Manager
        $this->seed('users', [
            'employee_id' => 2,
            'email' => 'hr.manager@muwasco.org',
            'password' => Hash::make($_ENV['SEED_HR_PASSWORD'] ?? 'ChangeMe!'.random_int(1000, 9999)),
            'role' => 'hr_manager',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Department Head
        $this->seed('users', [
            'employee_id' => 3,
            'email' => 'dept.head@muwasco.org',
            'password' => Hash::make($_ENV['SEED_DEPT_PASSWORD'] ?? 'ChangeMe!'.random_int(1000, 9999)),
            'role' => 'department_head',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Regular Employee
        $this->seed('users', [
            'employee_id' => 4,
            'email' => 'employee@muwasco.org',
            'password' => Hash::make($_ENV['SEED_EMP_PASSWORD'] ?? 'ChangeMe!'.random_int(1000, 9999)),
            'role' => 'employee',
            'is_active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Generate additional test users
        $this->generateTestUsers();
    }

    /**
     * Generate additional test users
     */
    private function generateTestUsers(): void
    {
        $roles = ['employee', 'department_head', 'hr_manager'];
        $domains = ['muwasco.org', 'test.muwasco.org'];

        for ($i = 5; $i <= 20; $i++) {
            $role = $roles[array_rand($roles)];
            $domain = $domains[array_rand($domains)];

            $this->seed('users', [
                'employee_id' => $i,
                'email' => "user{$i}@{$domain}",
                'password' => Hash::make($_ENV['SEED_TEST_PASSWORD'] ?? 'ChangeMe!'.random_int(1000, 9999)),
                'role' => $role,
                'is_active' => rand(0, 10) > 2 ? 1 : 0, // 80% active
                'created_at' => date('Y-m-d H:i:s', strtotime("-".rand(1, 365)." days")),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}