<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Database\Seeder;

class EmployeeSeeder extends Seeder
{
    /**
     * Seed employees table with test data
     */
    public function run(): void
    {
        // Skip if employees already exist
        if (!$this->isEmpty('employees')) {
            return;
        }

        // Admin employee
        $this->seed('employees', [
            'employee_id' => 'EMP001',
            'first_name' => 'System',
            'last_name' => 'Administrator',
            'surname' => 'Admin',
            'email' => 'admin@muwasco.org',
            'phone' => '255712345001',
            'gender' => 'male',
            'date_of_birth' => '1985-01-15',
            'hire_date' => '2020-01-01',
            'department_id' => 1,
            'section_id' => 1,
            'office_id' => 1,
            'designation' => 'System Administrator',
            'employment_type' => 'permanent',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // HR Manager
        $this->seed('employees', [
            'employee_id' => 'EMP002',
            'first_name' => 'Human',
            'last_name' => 'Resources',
            'surname' => 'Manager',
            'email' => 'hr.manager@muwasco.org',
            'phone' => '255712345002',
            'gender' => 'female',
            'date_of_birth' => '1988-03-20',
            'hire_date' => '2020-02-01',
            'department_id' => 2,
            'section_id' => 2,
            'office_id' => 1,
            'designation' => 'HR Manager',
            'employment_type' => 'permanent',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Department Head
        $this->seed('employees', [
            'employee_id' => 'EMP003',
            'first_name' => 'Department',
            'last_name' => 'Head',
            'surname' => 'Head',
            'email' => 'dept.head@muwasco.org',
            'phone' => '255712345003',
            'gender' => 'male',
            'date_of_birth' => '1982-07-10',
            'hire_date' => '2019-06-01',
            'department_id' => 3,
            'section_id' => 3,
            'office_id' => 2,
            'designation' => 'Department Head',
            'employment_type' => 'permanent',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Regular Employee
        $this->seed('employees', [
            'employee_id' => 'EMP004',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'surname' => 'Doe',
            'email' => 'employee@muwasco.org',
            'phone' => '255712345004',
            'gender' => 'male',
            'date_of_birth' => '1990-11-05',
            'hire_date' => '2021-03-15',
            'department_id' => 4,
            'section_id' => 4,
            'office_id' => 2,
            'designation' => 'Software Developer',
            'employment_type' => 'permanent',
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        // Generate additional test employees
        $this->generateTestEmployees();
    }

    /**
     * Generate additional test employees
     */
    private function generateTestEmployees(): void
    {
        $firstNames = ['Alice', 'Bob', 'Charlie', 'Diana', 'Edward', 'Fiona', 'George', 'Hannah', 'Ivan', 'Julia', 'Kevin', 'Laura', 'Michael', 'Nancy', 'Oscar'];
        $lastNames = ['Smith', 'Johnson', 'Williams', 'Brown', 'Jones', 'Garcia', 'Miller', 'Davis', 'Rodriguez', 'Martinez', 'Hernandez', 'Lopez', 'Gonzalez', 'Wilson', 'Anderson'];
        $genders = ['male', 'female'];
        $employmentTypes = ['permanent', 'contract', 'temporary', 'intern'];
        $statuses = ['active', 'active', 'active', 'active', 'inactive', 'on_leave']; // weighted
        $designations = [
            'Software Developer', 'System Analyst', 'Database Administrator', 'Network Engineer',
            'Accountant', 'Finance Officer', 'HR Officer', 'Administrative Officer',
            'Procurement Officer', 'Legal Officer', 'Public Relations Officer', 'Project Manager'
        ];

        for ($i = 5; $i <= 20; $i++) {
            $firstName = $firstNames[array_rand($firstNames)];
            $lastName = $lastNames[array_rand($lastNames)];
            $gender = $genders[array_rand($genders)];

            $this->seed('employees', [
                'employee_id' => 'EMP' . str_pad((string)$i, 3, '0', STR_PAD_LEFT),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'surname' => $lastName,
                'email' => strtolower($firstName . '.' . $lastName . '@muwasco.org'),
                'phone' => '255712345' . str_pad((string)$i, 3, '0', STR_PAD_LEFT),
                'gender' => $gender,
                'date_of_birth' => date('Y-m-d', strtotime("-".rand(22, 60)." years")),
                'hire_date' => date('Y-m-d', strtotime("-".rand(1, 5)." years")),
                'department_id' => rand(1, 6),
                'section_id' => rand(1, 10),
                'office_id' => rand(1, 3),
                'designation' => $designations[array_rand($designations)],
                'employment_type' => $employmentTypes[array_rand($employmentTypes)],
                'status' => $statuses[array_rand($statuses)],
                'created_at' => date('Y-m-d H:i:s', strtotime("-".rand(1, 365)." days")),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }
}