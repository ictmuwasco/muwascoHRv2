<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Services\Contracts\AttendanceServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(private AttendanceServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['employee_id', 'date', 'from_date', 'to_date']);
        $attendances = $this->service->list($filters);

        return response()->json($attendances);
    }

    public function show(Attendance $attendance): JsonResponse
    {
        $attendance = $this->service->get($attendance->id);
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

        $attendance = $this->service->create($validated);

        return response()->json($attendance, 201);
    }

    public function update(Request $request, Attendance $attendance): JsonResponse
    {
        $validated = $request->validate([
            'clock_in' => 'nullable|date_format:H:i',
            'clock_out' => 'nullable|date_format:H:i',
            'remarks' => 'nullable|string',
        ]);

        $attendance = $this->service->update($attendance, $validated);

        return response()->json($attendance);
    }

    public function destroy(Attendance $attendance): JsonResponse
    {
        $this->service->delete($attendance);

        return response()->json(null, 204);
    }
}
