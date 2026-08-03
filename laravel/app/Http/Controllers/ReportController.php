<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\Attendance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function employees(Request $request): JsonResponse
    {
        $query = Employee::query();

        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->has('employee_status')) {
            $query->where('employee_status', $request->employee_status);
        }

        $employees = $query->with(['department', 'section'])->get();

        return response()->json([
            'total' => $employees->count(),
            'data' => $employees,
        ]);
    }

    public function leave(Request $request): JsonResponse
    {
        $query = LeaveApplication::query();

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('applied_at', [$request->from_date, $request->to_date]);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $leaves = $query->with(['employee', 'leaveType'])->get();

        return response()->json([
            'total' => $leaves->count(),
            'data' => $leaves,
        ]);
    }

    public function attendance(Request $request): JsonResponse
    {
        $query = Attendance::query();

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('attendance_date', [$request->from_date, $request->to_date]);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $attendances = $query->with('employee')->get();

        return response()->json([
            'total' => $attendances->count(),
            'data' => $attendances,
        ]);
    }
}