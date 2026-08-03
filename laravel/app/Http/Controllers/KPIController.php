<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workplan;
use App\Models\KPI;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KPIController extends Controller
{
    public function index(Workplan $workplan): JsonResponse
    {
        $kpis = $workplan->kpis()->get();

        return response()->json($kpis);
    }

    public function store(Request $request, Workplan $workplan): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string',
            'description' => 'nullable|string',
            'target_value' => 'nullable|numeric',
            'actual_value' => 'nullable|numeric',
            'unit' => 'nullable|string',
            'due_date' => 'nullable|date',
            'status' => 'in:pending,in_progress,achieved,missed',
        ]);

        $kpi = $workplan->kpis()->create($validated);

        return response()->json($kpi, 201);
    }

    public function update(Request $request, KPI $kpi): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|string',
            'description' => 'nullable|string',
            'target_value' => 'nullable|numeric',
            'actual_value' => 'nullable|numeric',
            'unit' => 'nullable|string',
            'due_date' => 'nullable|date',
            'status' => 'in:pending,in_progress,achieved,missed',
        ]);

        $kpi->update($validated);

        return response()->json($kpi);
    }
}