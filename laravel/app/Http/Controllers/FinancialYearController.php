<?php

namespace App\\Http\\Controllers;

use App\Http\Controllers\Controller;
use App\Models\FinancialYear;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialYearController extends Controller
{
    public function index(): JsonResponse
    {
        $financialYears = FinancialYear::orderBy('is_active', 'desc')
            ->orderBy('start_date', 'desc')
            ->get()
            ->map(function (FinancialYear $year) {
                return [
                    'id' => $year->id,
                    'year' => $year->name,
                    'start_date' => $year->start_date?->toDateString(),
                    'end_date' => $year->end_date?->toDateString(),
                    'days' => $year->total_days,
                    'status' => $year->is_active ? 'Active' : 'Inactive',
                    'period' => sprintf('%s - %s', $year->start_date?->toDateString(), $year->end_date?->toDateString()),
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Financial years loaded',
            'data' => $financialYears,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'is_active' => 'boolean',
        ]);

        $startDate = Carbon::parse($validated['start_date']);
        $endDate = Carbon::parse($validated['end_date']);
        $yearName = sprintf('%s/%s', $startDate->format('Y'), $endDate->format('y'));
        $totalDays = $startDate->diffInDays($endDate) + 1;

        $financialYear = FinancialYear::create([
            'name' => $yearName,
            'start_date' => $startDate->toDateString(),
            'end_date' => $endDate->toDateString(),
            'total_days' => $totalDays,
            'is_active' => $request->boolean('is_active', true),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Financial year created',
            'data' => [
                'id' => $financialYear->id,
                'year' => $financialYear->name,
                'start_date' => $financialYear->start_date?->toDateString(),
                'end_date' => $financialYear->end_date?->toDateString(),
                'days' => $financialYear->total_days,
                'status' => $financialYear->is_active ? 'Active' : 'Inactive',
                'period' => sprintf('%s - %s', $financialYear->start_date?->toDateString(), $financialYear->end_date?->toDateString()),
            ],
        ], 201);
    }
}

