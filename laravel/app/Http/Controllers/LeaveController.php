<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LeaveApplication;
use App\Models\LeaveHistory;
use App\Services\Contracts\LeaveServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaveController extends Controller
{
    public function __construct(private LeaveServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['employee_id', 'status', 'leave_type_id']);
        $leaveApplications = $this->service->list($filters);

        return response()->json($leaveApplications);
    }

    public function show(LeaveApplication $leaveApplication): JsonResponse
    {
        $leaveApplication = $this->service->get($leaveApplication->id);

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

        $leaveApplication = $this->service->create($validated);

        LeaveHistory::create([
            'leave_application_id' => $leaveApplication->id,
            'action' => 'applied',
            'performed_by' => auth()->id(),
            'comments' => 'Leave application submitted for ' . $leaveApplication->days_requested . ' days',
            'performed_at' => now(),
        ]);

        return response()->json($leaveApplication, 201);
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

        $leaveApplication = $this->service->update($leaveApplication, $validated);

        return response()->json($leaveApplication);
    }

    public function destroy(LeaveApplication $leaveApplication): JsonResponse
    {
        $this->service->delete($leaveApplication);

        return response()->json(null, 204);
    }

    public function approve(Request $request, LeaveApplication $leaveApplication): JsonResponse
    {
        $validated = $request->validate([
            'comments' => 'nullable|string',
        ]);

        $leaveApplication = $this->service->update($leaveApplication, [
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
            'rejection_reason' => 'required|string',
        ]);

        $leaveApplication = $this->service->update($leaveApplication, [
            'status' => 'rejected',
            'rejection_reason' => $validated['rejection_reason'],
            'hr_processed_by' => auth()->id(),
            'hr_processed_at' => now(),
        ]);

        LeaveHistory::create([
            'leave_application_id' => $leaveApplication->id,
            'action' => 'rejected',
            'performed_by' => auth()->id(),
            'comments' => $validated['rejection_reason'],
            'performed_at' => now(),
        ]);

        return response()->json($leaveApplication);
    }
}
