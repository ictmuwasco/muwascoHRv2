<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StrategicPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StrategicPlanController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(StrategicPlan::orderBy('created_at', 'desc')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'status' => 'in:draft,active,completed,archived',
        ]);

        $plan = StrategicPlan::create($validated);

        return response()->json($plan, 201);
    }

    public function update(Request $request, StrategicPlan $plan): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'string',
            'description' => 'nullable|string',
            'start_date' => 'date',
            'end_date' => 'date|after_or_equal:start_date',
            'status' => 'in:draft,active,completed,archived',
        ]);

        $plan->update($validated);

        return response()->json($plan);
    }
}