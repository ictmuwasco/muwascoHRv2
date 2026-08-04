<?php

namespace App\\Http\\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Employee::query();
        
        if ($request->has('search')) {
            $search = $request->get('search');
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('employee_id', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('department_id')) {
            $query->where('department_id', $request->department_id);
        }

        if ($request->has('section_id')) {
            $query->where('section_id', $request->section_id);
        }

        if ($request->has('employee_type')) {
            $query->where('employee_type', $request->employee_type);
        }

        if ($request->has('employee_status')) {
            $query->where('employee_status', $request->employee_status);
        }

        $employees = $query->with(['department', 'section', 'user'])->paginate(15);

        return response()->json($employees);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->get('q', '');

        $employees = Employee::where(function ($q) use ($query) {
            $q->where('first_name', 'like', "%{$query}%")
              ->orWhere('last_name', 'like', "%{$query}%")
              ->orWhere('employee_id', 'like', "%{$query}%")
              ->orWhere('email', 'like', "%{$query}%");
        })->with(['department', 'section'])->get();

        return response()->json($employees);
    }

    public function show(Employee $employee): JsonResponse
    {
        $employee->load(['department', 'section', 'user', 'leaveApplications', 'attendances']);
        
        return response()->json($employee);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|unique:employees',
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'surname' => 'nullable|string',
            'gender' => 'nullable|in:male,female',
            'national_id' => 'nullable|integer',
            'email' => 'nullable|email',
            'designation' => 'nullable|string',
            'phone' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'address' => 'nullable|string',
            'department_id' => 'nullable|exists:departments,id',
            'section_id' => 'nullable|exists:sections,id',
            'position' => 'nullable|string',
            'salary' => 'nullable|numeric',
            'hire_date' => 'nullable|date',
            'employment_type' => 'nullable|in:permanent,contract,intern',
            'employee_type' => 'nullable|in:officer,dept_head,section_head,sub_section_head,managing_director',
            'profile_image_url' => 'nullable|string',
            'employee_status' => 'nullable|in:active,inactive,resigned,terminated',
            'scale_id' => 'nullable|string',
            'next_of_kin' => 'nullable|json',
            'subsection_id' => 'nullable|exists:sections,id',
            'office_id' => 'nullable|exists:offices,id',
        ]);

        $employee = Employee::create($validated);

        return response()->json($employee, 201);
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|unique:employees,employee_id,' . $employee->id,
            'first_name' => 'required|string',
            'last_name' => 'required|string',
            'surname' => 'nullable|string',
            'gender' => 'nullable|in:male,female',
            'national_id' => 'nullable|integer',
            'email' => 'nullable|email',
            'designation' => 'nullable|string',
            'phone' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'address' => 'nullable|string',
            'department_id' => 'nullable|exists:departments,id',
            'section_id' => 'nullable|exists:sections,id',
            'position' => 'nullable|string',
            'salary' => 'nullable|numeric',
            'hire_date' => 'nullable|date',
            'employment_type' => 'nullable|in:permanent,contract,intern',
            'employee_type' => 'nullable|in:officer,dept_head,section_head,sub_section_head,managing_director',
            'profile_image_url' => 'nullable|string',
            'employee_status' => 'nullable|in:active,inactive,resigned,terminated',
            'scale_id' => 'nullable|string',
            'next_of_kin' => 'nullable|json',
            'subsection_id' => 'nullable|exists:sections,id',
            'office_id' => 'nullable|exists:offices,id',
        ]);

        $employee->update($validated);

        return response()->json($employee);
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $employee->delete();

        return response()->json(null, 204);
    }
}

