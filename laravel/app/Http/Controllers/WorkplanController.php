<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StrategicPlan;
use App\Models\Workplan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkplanController extends Controller
{
    public function index(StrategicPlan $strategicPlan): JsonResponse
    {
        $workplans = $strategicPlan->workplans()->get();

        return response()->json($workplans);
    }

    public function store(Request $request, StrategicPlan $strategicPlan): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'status' => 'in:draft,in_progress,completed,cancelled',
        ]);

        $workplan = $strategicPlan->workplans()->create($validated);

        return response()->json($workplan, 201);
    }
}