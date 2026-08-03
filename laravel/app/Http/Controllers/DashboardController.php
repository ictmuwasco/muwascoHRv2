<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\Attendance;
use App\Models\Complaint;
use App\Models\Appraisal;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $totalEmployees = Employee::count();
        $activeEmployees = Employee::where('employee_status', 'active')->count();
        $pendingLeaves = LeaveApplication::where('status', 'pending')->count();
        $approvedLeaves = LeaveApplication::where('status', 'approved')->count();
        $todayAttendance = Attendance::where('attendance_date', now()->toDateString())->count();
        $pendingComplaints = Complaint::where('status', 'pending')->count();
        $pendingAppraisals = Appraisal::where('status', 'pending')->count();

        return response()->json([
            'total_employees' => $totalEmployees,
            'active_employees' => $activeEmployees,
            'pending_leaves' => $pendingLeaves,
            'approved_leaves' => $approvedLeaves,
            'today_attendance' => $todayAttendance,
            'pending_complaints' => $pendingComplaints,
            'pending_appraisals' => $pendingAppraisals,
        ]);
    }
}