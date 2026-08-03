<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Models\Employee;
use App\Models\LeaveHistory;
use App\Models\EmployeeLeaveBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = LeaveApplication::query();
        
        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('leave_type_id')) {
            $query->where('leave_type_id', $request->leave_type_id);
        }

        $leaveApplications = $query->with(['employee', 'leaveType', 'appliedBy'])
            ->orderBy('applied_at', 'desc')
            ->paginate(15);

        return response()->json($leaveApplications);
    }

    public function show(LeaveApplication $leaveApplication): JsonResponse
    {
        $leaveApplication->load(['employee', 'leaveType', 'appliedBy', 'approver', 'sectionHead', 'deptHead']);
        
        return response()->json($leaveApplication);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'leave_type_id' => 'required|exists:leave_types,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'days_requested' => 'required|integer|min:1',
            'reason' => 'nullable|string',
            'deduction_details' => 'nullable|json',
            'primary_days' => 'integer|min:0',
            'annual_days' => 'integer|min:0',
            'unpaid_days' => 'integer|min:0',
        ]);

        $validated['applied_by_user_id'] = auth()->id();
        $validated['status'] = 'pending';
        $validated['applied_at'] = now();

        $leaveApplication = LeaveApplication::create($validated);

        LeaveHistory::create([
            'leave_application_id' => $leaveApplication->id,
            'action' => 'applied',
            'performed_by' => auth()->id(),
            'comments' => 'Leave application submitted for ' . $leaveApplication->days_requested . ' days',
            'performed_at' => now(),
        ]);

        return response()->json($leaveApplication->load(['employee', 'leaveType']), 201);
    }

    public function apply(Request $request): JsonResponse
    {
        return $this->store($request);
    }

    public function update(Request $request, LeaveApplication $leaveApplication): JsonResponse
    {
        $validated = $request->validate([
            'start_date' => 'date',
            'end_date' => 'date|after_or_equal:start_date',
            'days_requested' => 'integer|min:1',
            'reason' => 'nullable|string',
            'status' => 'in:pending,approved,rejected,cancelled',
        ]);

        $leaveApplication->update($validated);

        return response()->json($leaveApplication);
    }

    public function destroy(LeaveApplication $leaveApplication): JsonResponse
    {
        $leaveApplication->delete();

        return response()->json(null, 204);
    }

    public function approve(Request $request, LeaveApplication $leaveApplication): JsonResponse
    {
        $validated = $request->validate([
            'comments' => 'nullable|string',
        ]);

        $leaveApplication->update([
            'status' => 'approved',
            'hr_approved_by' => auth()->id(),
            'hr_approved_at' => now(),
            'hr_comments' => $validated['comments'] ?? null,
        ]);

        LeaveHistory::create([
            'leave_application_id' => $leaveApplication->id,
            'action' => 'approved',
            'performed_by' => auth()->id(),
            'comments' => $validated['comments'] ?? 'Leave application approved',
            'performed_at' => now(),
        ]);

        return response()->json($leaveApplication);
    }

    public function reject(Request $request, LeaveApplication $leaveApplication): JsonResponse
    {
        $validated = $request->validate([
            'reason' => 'required|string',
            'rejection_reason' => 'nullable|string',
        ]);

        $reason = $validated['rejection_reason'] ?? $validated['reason'];

        $leaveApplication->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'hr_processed_by' => auth()->id(),
            'hr_processed_at' => now(),
        ]);

        LeaveHistory::create([
            'leave_application_id' => $leaveApplication->id,
            'action' => 'rejected',
            'performed_by' => auth()->id(),
            'comments' => $reason,
            'performed_at' => now(),
        ]);

        return response()->json($leaveApplication);
    }

    public function cancel(Request $request, LeaveApplication $leaveApplication): JsonResponse
    {
        $leaveApplication->update([
            'status' => 'cancelled',
        ]);

        LeaveHistory::create([
            'leave_application_id' => $leaveApplication->id,
            'action' => 'cancelled',
            'performed_by' => auth()->id(),
            'comments' => 'Leave application cancelled',
            'performed_at' => now(),
        ]);

        return response()->json($leaveApplication);
    }

    public function types(): JsonResponse
    {
        return response()->json(LeaveType::where('is_active', true)->get());
    }

    public function balance(int $employeeId): JsonResponse
    {
        return response()->json(EmployeeLeaveBalance::where('employee_id', $employeeId)->with('leaveType')->get());
    }

    public function holidays(): JsonResponse
    {
        return response()->json([]);
    }

    public function pending(): JsonResponse
    {
        $leaves = LeaveApplication::where('status', 'pending')
            ->with(['employee', 'leaveType'])
            ->orderBy('applied_at', 'desc')
            ->get();

        return response()->json($leaves);
    }

    public function byEmployee(int $employeeId): JsonResponse
    {
        $leaves = LeaveApplication::where('employee_id', $employeeId)
            ->with(['employee', 'leaveType'])
            ->orderBy('applied_at', 'desc')
            ->get();

        return response()->json($leaves);
    }
}