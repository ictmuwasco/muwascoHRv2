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

        $appraisals = $query->with(['employee', 'appraiser'])->paginate(15);

        return response()->json($appraisals);
    }

    public function show(Appraisal $appraisal): JsonResponse
    {
        $appraisal->load(['employee', 'appraiser']);
        return response()->json($appraisal);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'appraiser_id' => 'required|exists:users,id',
            'period' => 'required|string',
            'rating' => 'nullable|numeric',
            'comments' => 'nullable|string',
            'goals' => 'nullable|json',
            'status' => 'in:draft,submitted,approved,reviewed',
        ]);

        $appraisal = Appraisal::create($validated);

        return response()->json($appraisal->load(['employee', 'appraiser']), 201);
    }

    public function update(Request $request, Appraisal $appraisal): JsonResponse
    {
        $validated = $request->validate([
            'rating' => 'nullable|numeric',
            'comments' => 'nullable|string',
            'goals' => 'nullable|json',
            'status' => 'in:draft,submitted,approved,reviewed',
        ]);

        $appraisal->update($validated);

        return response()->json($appraisal);
    }

    public function submit(Appraisal $appraisal): JsonResponse
    {
        $appraisal->update(['status' => 'submitted']);

        return response()->json($appraisal);
    }

    public function approve(Appraisal $appraisal): JsonResponse
    {
        $appraisal->update(['status' => 'approved']);

        return response()->json($appraisal);
    }

    public function pending(): JsonResponse
    {
        $appraisals = Appraisal::where('status', 'submitted')
            ->with(['employee', 'appraiser'])
            ->get();

        return response()->json($appraisals);
    }

    public function byEmployee(int $employeeId): JsonResponse
    {
        $appraisals = Appraisal::where('employee_id', $employeeId)
            ->with(['employee', 'appraiser'])
            ->get();

        return response()->json($appraisals);
    }
}