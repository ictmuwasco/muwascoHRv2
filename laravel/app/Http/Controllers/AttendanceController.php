<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Attendance::query();
        
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->has('date')) {
            $query->where('attendance_date', $request->date);
        }

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('attendance_date', [$request->from_date, $request->to_date]);
        }

        $attendances = $query->with(['employee'])->orderBy('attendance_date', 'desc')->paginate(15);

        return response()->json($attendances);
    }

    public function show(Attendance $attendance): JsonResponse
    {
        $attendance->load('employee');
        return response()->json($attendance);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'attendance_date' => 'required|date',
            'clock_in' => 'nullable|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i',
            'remarks' => 'nullable|string',
        ]);

        // Check if attendance already exists for this employee on this date
        $existing = Attendance::where('employee_id', $validated['employee_id'])
            ->where('attendance_date', $validated['attendance_date'])
            ->first();

        if ($existing) {
            return response()->json(['error' => 'Attendance already recorded for this date'], 422);
        }

        $attendance = Attendance::create($validated);

        return response()->json($attendance->load('employee'), 201);
    }

    public function update(Request $request, Attendance $attendance): JsonResponse
    {
        $validated = $request->validate([
            'clock_in' => 'nullable|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i',
            'remarks' => 'nullable|string',
        ]);

        $attendance->update($validated);

        return response()->json($attendance);
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $attendance->delete();

        return response()->json(null, 204);
    }

    public function today(): JsonResponse
    {
        $attendances = Attendance::where('attendance_date', now()->toDateString())
            ->with('employee')
            ->get();

        return response()->json($attendances);
    }

    public function byEmployee(int $employeeId): JsonResponse
    {
        $attendances = Attendance::where('employee_id', $employeeId)
            ->orderBy('attendance_date', 'desc')
            ->get();

        return response()->json($attendances);
    }

    public function dashboard(): JsonResponse
    {
        $today = now()->toDateString();
        $totalEmployees = Employee::where('employee_status', 'active')->count();

        $present = Attendance::where('attendance_date', $today)->count();
        $late = Attendance::where('attendance_date', $today)
            ->where('clock_in', '>', '08:30')
            ->count();

        return response()->json([
            'present' => $present,
            'absent' => max(0, $totalEmployees - $present),
            'late' => $late,
            'total' => $totalEmployees,
        ]);
    }
}