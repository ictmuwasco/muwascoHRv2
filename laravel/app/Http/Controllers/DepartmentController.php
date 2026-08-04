<?php

namespace App\\Http\\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        $departments = Department::with('head')->get();
        return response()->json($departments);
    }

    public function show(Department $department): JsonResponse
    {
        $department->load(['head', 'sections', 'employees']);
        return response()->json($department);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:departments',
            'description' => 'nullable|string',
            'head_of_department_id' => 'nullable|exists:users,id',
            'is_active' => 'boolean',
        ]);

        $department = Department::create($validated);

        return response()->json($department, 201);
    }

    public function update(Request $request, Department $department): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|unique:departments,name,' . $department->id,
            'description' => 'nullable|string',
            'head_of_department_id' => 'nullable|exists:users,id',
            'is_active' => 'boolean',
        ]);

        $department->update($validated);

        return response()->json($department);
    }

    public function destroy(Department $department): JsonResponse
    {
        $department->delete();

        return response()->json(null, 204);
    }
}

