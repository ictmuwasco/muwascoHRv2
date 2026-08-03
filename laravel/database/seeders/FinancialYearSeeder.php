<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FinancialYearSeeder extends Seeder
{
    public function run(): void
    {
        $currentYear = date('Y');
        $startDate = $currentYear . '-07-01';
        $endDate = ($currentYear + 1) . '-06-30';

        DB::table('financial_years')->updateOrInsert(
            ['name' => $currentYear . '/' . ($currentYear + 1)],
            [
                'name' => $currentYear . '/' . ($currentYear + 1),
                'start_date' => $startDate,
                'end_date' => $endDate,
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }
}