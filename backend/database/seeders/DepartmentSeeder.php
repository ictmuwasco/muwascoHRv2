<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeder;

/**
 * Department Seeder
 * 
 * Seeds reference data for departments and sections.
 */
class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        if (!$this->isEmpty('departments')) {
            return; // Already seeded
        }

        $this->seed('departments', [
            [
                'name' => 'Administration',
                'description' => 'General administration and management',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Human Resources',
                'description' => 'Human resource management',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Finance',
                'description' => 'Financial management and accounting',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Engineering',
                'description' => 'Water and sanitation engineering',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Operations',
                'description' => 'Day-to-day operations',
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Customer Service',
                'description' => 'Customer relations and support',
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ]);
    }
}
