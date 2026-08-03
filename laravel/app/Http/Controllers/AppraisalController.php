<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appraisal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppraisalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Appraisal::query();

        if ($request->has('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        return response()->json($query->with('employee')->paginate(15));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'appraisal_period' => 'required|string',
            'rating' => 'nullable|numeric|min:1|max:5',
            'comments' => 'nullable|string',
        ]);

        $validated['status'] = 'pending';
        $validated['created_by'] = auth()->id();

        $appraisal = Appraisal::create($validated);

        return response()->json($appraisal, 201);
    }

    public function update(Request $request, Appraisal $appraisal): JsonResponse
    {
        $validated = $request->validate([
            'rating' => 'nullable|numeric|min:1|max:5',
            'comments' => 'nullable|string',
            'status' => 'in:pending,completed',
        ]);

        $appraisal->update($validated);

        return response()->json($appraisal);
    }
}