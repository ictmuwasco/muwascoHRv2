<?php

namespace App\\Http\\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Complaint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplaintController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Complaint::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        return response()->json($query->with('employee')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'subject' => 'required|string',
            'description' => 'required|string',
            'category' => 'nullable|string',
        ]);

        $validated['status'] = 'pending';
        $validated['submitted_by'] = auth()->id();

        $complaint = Complaint::create($validated);

        return response()->json($complaint, 201);
    }

    public function update(Request $request, Complaint $complaint): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'in:pending,in_review,resolved,rejected',
            'resolution_notes' => 'nullable|string',
        ]);

        $complaint->update($validated);

        return response()->json($complaint);
    }
}

