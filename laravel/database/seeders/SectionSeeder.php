<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SectionSeeder extends Seeder
{
    public function run(): void
    {
        $hrDept = DB::table('departments')->where('name', 'Human Resources')->value('id');
        $itDept = DB::table('departments')->where('name', 'Information Technology')->value('id');

        $sections = [
            ['name' => 'Recruitment', 'description' => 'Recruitment section', 'department_id' => $hrDept],
            ['name' => 'Payroll', 'description' => 'Payroll section', 'department_id' => $hrDept],
            ['name' => 'Software Development', 'description' => 'Software development section', 'department_id' => $itDept],
            ['name' => 'Infrastructure', 'description' => 'IT infrastructure section', 'department_id' => $itDept],
        ];

        foreach ($sections as $section) {
            DB::table('sections')->updateOrInsert(
                ['name' => $section['name']],
                array_merge($section, ['is_active' => 1, 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}