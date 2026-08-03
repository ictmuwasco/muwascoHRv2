<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Attendance;
use App\Models\LeaveApplication;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $stats = $this->getStats();
        return response()->json($stats);
    }

    public function stats(): JsonResponse
    {
        $stats = $this->getStats();
        return response()->json($stats);
    }

    private function getStats(): array
    {
        $totalEmployees = Employee::where('employee_status', 'active')->count();
        $totalDepartments = Department::count();
        $todayAttendance = Attendance::where('attendance_date', now()->toDateString())->count();
        $pendingLeaves = LeaveApplication::where('status', 'pending')->count();
        $lateToday = Attendance::where('attendance_date', now()->toDateString())
            ->where('clock_in', '>', '08:30')
            ->count();

        return [
            'total_employees' => $totalEmployees,
            'total_departments' => $totalDepartments,
            'today_attendance' => $todayAttendance,
            'pending_leaves' => $pendingLeaves,
            'late_today' => $lateToday,
        ];
    }

    public function chartsAttendance(Request $request): JsonResponse
    {
        $period = $request->get('period', 'week');
        $labels = [];
        $present = [];
        $absent = [];

        if ($period === 'week') {
            for ($i = 6; $i >= 0; $i--) {
                $date = now()->subDays($i)->toDateString();
                $labels[] = now()->subDays($i)->format('D');
                $present[] = Attendance::where('attendance_date', $date)->count();
                $absent[] = max(0, Employee::where('employee_status', 'active')->count() - Attendance::where('attendance_date', $date)->count());
            }
        } elseif ($period === 'month') {
            for ($i = 29; $i >= 0; $i -= 3) {
                $date = now()->subDays($i)->toDateString();
                $labels[] = now()->subDays($i)->format('M dd');
                $present[] = Attendance::where('attendance_date', '>=', now()->subDays($i)->toDateString())
                    ->where('attendance_date', '<=', now()->subDays($i + 2)->toDateString())
                    ->count();
                $absent[] = 0;
            }
        }

        return response()->json([
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Present', 'data' => $present],
                ['label' => 'Absent', 'data' => $absent],
            ],
        ]);
    }

    public function chartsDepartments(): JsonResponse
    {
        $departments = Department::withCount('employees')->get();
        $labels = $departments->pluck('name');
        $data = $departments->pluck('employees_count');

        return response()->json([
            'labels' => $labels,
            'datasets' => [
                ['label' => 'Employees', 'data' => $data],
            ],
        ]);
    }

    public function chartsLeave(): JsonResponse
    {
        $pending = LeaveApplication::where('status', 'pending')->count();
        $approved = LeaveApplication::where('status', 'approved')->count();
        $rejected = LeaveApplication::where('status', 'rejected')->count();
        $cancelled = LeaveApplication::where('status', 'cancelled')->count();

        return response()->json([
            'labels' => ['Pending', 'Approved', 'Rejected', 'Cancelled'],
            'datasets' => [
                [
                    'label' => 'Leave Requests',
                    'data' => [$pending, $approved, $rejected, $cancelled],
                ],
            ],
        ]);
    }

    public function __construct()
    {
        // routes are assigned below in the routes file
    }
}
