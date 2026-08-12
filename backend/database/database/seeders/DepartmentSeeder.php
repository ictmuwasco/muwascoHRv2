<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [
            ['name' => 'Human Resources', 'description' => 'HR department'],
            ['name' => 'Finance', 'description' => 'Finance department'],
            ['name' => 'Information Technology', 'description' => 'IT department'],
            ['name' => 'Operations', 'description' => 'Operations department'],
            ['name' => 'Marketing', 'description' => 'Marketing department'],
        ];

        foreach ($departments as $dept) {
            DB::table('departments')->updateOrInsert(
                ['name' => $dept['name']],
                array_merge($dept, ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}