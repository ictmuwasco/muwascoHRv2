<?php

namespace App\\Http\\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\Attendance;
use App\Models\Appraisal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ReportController extends Controller
{
    private function wrapData(array $payload, string $message = 'Report loaded'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $payload,
        ]);
    }

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

        return $this->wrapData([
            'total' => $employees->count(),
            'data' => $employees,
        ], 'Employee report loaded');
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

        return $this->wrapData([
            'total' => $leaves->count(),
            'data' => $leaves,
        ], 'Leave report loaded');
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

        return $this->wrapData([
            'total' => $attendances->count(),
            'data' => $attendances,
        ], 'Attendance report loaded');
    }

    public function appraisal(Request $request): JsonResponse
    {
        $query = Appraisal::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('created_at', [$request->from_date, $request->to_date]);
        }

        $appraisals = $query->with('employee')->get();

        return $this->wrapData([
            'total' => $appraisals->count(),
            'data' => $appraisals,
        ], 'Appraisal report loaded');
    }

    public function documentation(Request $request): JsonResponse
    {
        $documentation = [
            [
                'id' => 1,
                'title' => 'Employee Management Guide',
                'description' => 'How to manage employee records, search, and update staff details.',
                'category' => 'Employees',
            ],
            [
                'id' => 2,
                'title' => 'Attendance Tracking',
                'description' => 'Overview of attendance reports, daily checks, and status definitions.',
                'category' => 'Attendance',
            ],
            [
                'id' => 3,
                'title' => 'Leave Management',
                'description' => 'Leave application flow, approvals, and holiday lookups.',
                'category' => 'Leave',
            ],
            [
                'id' => 4,
                'title' => 'Appraisal Process',
                'description' => 'Submit, review, and approve staff appraisals.',
                'category' => 'Appraisals',
            ],
        ];

        return $this->wrapData([
            'total' => count($documentation),
            'data' => $documentation,
        ], 'Documentation loaded');
    }

    public function export(string $type, string $format, Request $request): Response
    {
        $format = strtolower($format);
        $supportedFormats = ['pdf', 'csv', 'excel'];

        if (! in_array($format, $supportedFormats, true)) {
            return response('Unsupported export format', 400);
        }

        $report = [];

        switch ($type) {
            case 'employees':
                $report = $this->buildEmployeeReport($request);
                break;
            case 'leave':
                $report = $this->buildLeaveReport($request);
                break;
            case 'attendance':
                $report = $this->buildAttendanceReport($request);
                break;
            case 'appraisal':
                $report = $this->buildAppraisalReport($request);
                break;
            case 'documentation':
                $report = $this->buildDocumentationReport();
                break;
            default:
                return response('Unsupported report type', 400);
        }

        $filename = sprintf('%s-report.%s', $type, $format);
        $content = $this->formatExportContent($report, $format, $type);
        $contentType = $this->contentTypeForFormat($format);

        return response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    private function buildEmployeeReport(Request $request): array
    {
        $query = Employee::query();

        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->has('employee_status')) {
            $query->where('employee_status', $request->employee_status);
        }

        return $query->with(['department', 'section'])
            ->get()
            ->map(function (Employee $employee) {
                return [
                    'ID' => $employee->id,
                    'Name' => trim("{$employee->first_name} {$employee->last_name}"),
                    'Email' => $employee->email,
                    'Department' => optional($employee->department)->name,
                    'Section' => optional($employee->section)->name,
                    'Status' => $employee->employee_status,
                ];
            })
            ->toArray();
    }

    private function buildLeaveReport(Request $request): array
    {
        $query = LeaveApplication::query();

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('applied_at', [$request->from_date, $request->to_date]);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return $query->with(['employee', 'leaveType'])
            ->get()
            ->map(function (LeaveApplication $leave) {
                return [
                    'ID' => $leave->id,
                    'Employee' => optional($leave->employee)->first_name . ' ' . optional($leave->employee)->last_name,
                    'Leave Type' => optional($leave->leaveType)->name,
                    'Start' => $leave->start_date,
                    'End' => $leave->end_date,
                    'Status' => $leave->status,
                ];
            })
            ->toArray();
    }

    private function buildAttendanceReport(Request $request): array
    {
        $query = Attendance::query();

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('attendance_date', [$request->from_date, $request->to_date]);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        return $query->with('employee')
            ->get()
            ->map(function (Attendance $attendance) {
                return [
                    'ID' => $attendance->id,
                    'Employee' => optional($attendance->employee)->first_name . ' ' . optional($attendance->employee)->last_name,
                    'Date' => $attendance->attendance_date,
                    'Status' => $attendance->status,
                ];
            })
            ->toArray();
    }

    private function buildAppraisalReport(Request $request): array
    {
        $query = Appraisal::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('from_date') && $request->has('to_date')) {
            $query->whereBetween('created_at', [$request->from_date, $request->to_date]);
        }

        return $query->with('employee')
            ->get()
            ->map(function (Appraisal $appraisal) {
                return [
                    'ID' => $appraisal->id,
                    'Employee' => optional($appraisal->employee)->first_name . ' ' . optional($appraisal->employee)->last_name,
                    'Status' => $appraisal->status,
                    'Rating' => $appraisal->rating ?? 'N/A',
                    'Created At' => $appraisal->created_at,
                ];
            })
            ->toArray();
    }

    private function buildDocumentationReport(): array
    {
        return [
            ['Title' => 'Employee Management Guide', 'Category' => 'Employees', 'Status' => 'Available'],
            ['Title' => 'Attendance Tracking', 'Category' => 'Attendance', 'Status' => 'Available'],
            ['Title' => 'Leave Management', 'Category' => 'Leave', 'Status' => 'Available'],
            ['Title' => 'Appraisal Process', 'Category' => 'Appraisals', 'Status' => 'Available'],
        ];
    }

    private function formatExportContent(array $report, string $format, string $type): string
    {
        if ($format === 'csv' || $format === 'excel') {
            return $this->convertToCsv($report);
        }

        if ($format === 'pdf') {
            $lines = ["{$type} report", str_repeat('=', 40)];

            foreach ($report as $row) {
                foreach ($row as $key => $value) {
                    $lines[] = "{$key}: {$value}";
                }
                $lines[] = str_repeat('-', 40);
            }

            return implode("\n", $lines);
        }

        return '';
    }

    private function contentTypeForFormat(string $format): string
    {
        return match ($format) {
            'csv' => 'text/csv; charset=utf-8',
            'excel' => 'application/vnd.ms-excel; charset=utf-8',
            'pdf' => 'application/pdf; charset=utf-8',
            default => 'application/octet-stream',
        };
    }

    private function convertToCsv(array $report): string
    {
        if (empty($report)) {
            return '';
        }

        $headers = array_keys((array) reset($report));
        $rows = array_map(function ($row) {
            return implode(',', array_map([$this, 'escapeCsvValue'], array_values((array) $row)));
        }, $report);

        return implode(',', array_map([$this, 'escapeCsvValue'], $headers)) . "\n" . implode("\n", $rows);
    }

    private function escapeCsvValue($value): string
    {
        if (is_null($value)) {
            return '';
        }

        $value = (string) $value;

        if (str_contains($value, ',') || str_contains($value, '"') || str_contains($value, "\n")) {
            $value = '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }
}

