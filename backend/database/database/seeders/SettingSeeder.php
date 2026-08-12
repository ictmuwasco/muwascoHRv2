<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'company_name', 'value' => 'MUWASCO'],
            ['key' => 'company_address', 'value' => 'Mombasa, Kenya'],
            ['key' => 'company_email', 'value' => 'info@muwasco.co.ke'],
            ['key' => 'company_phone', 'value' => '+254 000 000 000'],
            ['key' => 'working_hours_start', 'value' => '08:00'],
            ['key' => 'working_hours_end', 'value' => '17:00'],
            ['key' => 'annual_leave_days', 'value' => '30'],
            ['key' => 'sick_leave_days', 'value' => '15'],
        ];

        foreach ($settings as $setting) {
            DB::table('settings')->updateOrInsert(
                ['key' => $setting['key']],
                array_merge($setting, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}