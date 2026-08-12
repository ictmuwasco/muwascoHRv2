<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'super_admin', 'description' => 'Full system access'],
            ['name' => 'admin', 'description' => 'Administrative access'],
            ['name' => 'hr_manager', 'description' => 'HR management access'],
            ['name' => 'dept_head', 'description' => 'Department head access'],
            ['name' => 'section_head', 'description' => 'Section head access'],
            ['name' => 'officer', 'description' => 'Regular employee access'],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                $role
            );
        }
    }
}