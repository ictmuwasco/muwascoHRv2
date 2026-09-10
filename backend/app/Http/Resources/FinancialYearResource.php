<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FinancialYear;

/**
 * FinancialYearResource
 * 
 * Transforms FinancialYear model data for API responses.
 */
class FinancialYearResource
{
    /**
     * Transform the resource into an array.
     */
    public static function toArray(FinancialYear|array $fy): array
    {
        if ($fy instanceof FinancialYear) {
            $fy = $fy->toArray();
        }
        
        return [
            'id' => $fy['id'] ?? null,
            'year_name' => $fy['year_name'] ?? null,
            'start_date' => $fy['start_date'] ?? null,
            'end_date' => $fy['end_date'] ?? null,
            'total_days' => $fy['total_days'] ?? 0,
            'is_active' => (bool)($fy['is_active'] ?? false),
            'period_status' => $fy['period_status'] ?? self::computePeriodStatus($fy),
            'created_at' => !empty($fy['created_at']) ? date('Y-m-d H:i:s', strtotime($fy['created_at'])) : null,
            'updated_at' => !empty($fy['updated_at']) ? date('Y-m-d H:i:s', strtotime($fy['updated_at'])) : null,
        ];
    }

    /**
     * Compute the period status (current/future/past) based on the
     * financial year's start/end dates relative to today.
     */
    private static function computePeriodStatus(array $fy): ?string
    {
        $startDate = $fy['start_date'] ?? null;
        $endDate = $fy['end_date'] ?? null;

        if (empty($startDate) || empty($endDate)) {
            return null;
        }

        $today = date('Y-m-d');

        if ($today >= $startDate && $today <= $endDate) {
            return 'current';
        }

        if ($today < $startDate) {
            return 'future';
        }

        return 'past';
    }

    /**
     * Transform a collection of financial years.
     */
    public static function collection(array $financialYears): array
    {
        return array_map([self::class, 'toArray'], $financialYears);
    }
}