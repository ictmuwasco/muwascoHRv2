<?php

namespace App\\Http\\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PayrollPeriod;
use App\Models\PayrollRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PayrollController extends Controller
{
    public function periods(): JsonResponse
    {
        return response()->json(PayrollPeriod::orderBy('start_date', 'desc')->get());
    }

    public function storePeriod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'payment_date' => 'nullable|date',
        ]);

        $period = PayrollPeriod::create($validated);

        return response()->json($period, 201);
    }

    public function records(Request $request): JsonResponse
    {
        $query = PayrollRecord::query();

        if ($request->has('payroll_period_id')) {
            $query->where('payroll_period_id', $request->payroll_period_id);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        return response()->json($query->with(['employee', 'period'])->paginate(15));
    }

    public function storeRecord(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payroll_period_id' => 'required|exists:payroll_periods,id',
            'employee_id' => 'required|exists:employees,id',
            'basic_salary' => 'required|numeric|min:0',
            'gross_salary' => 'required|numeric|min:0',
            'net_salary' => 'required|numeric|min:0',
            'status' => 'in:pending,processed,paid',
        ]);

        $record = PayrollRecord::create($validated);

        return response()->json($record, 201);
    }
}

