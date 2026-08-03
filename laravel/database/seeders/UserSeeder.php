<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $superAdminRole = DB::table('roles')->where('name', 'super_admin')->value('id');

        DB::table('users')->updateOrInsert(
            ['email' => 'admin@muwasco.co.ke'],
            [
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'email' => 'admin@muwasco.co.ke',
                'password' => Hash::make('Admin@123'),
                'role' => 'super_admin',
                'role_id' => $superAdminRole,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}