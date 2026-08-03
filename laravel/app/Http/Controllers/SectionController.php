<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Section;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function index(): JsonResponse
    {
        $sections = Section::with(['department', 'head'])->get();
        return response()->json($sections);
    }

    public function show(Section $section): JsonResponse
    {
        $section->load(['department', 'head', 'employees']);
        return response()->json($section);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'department_id' => 'nullable|exists:departments,id',
            'head_of_section_id' => 'nullable|exists:users,id',
            'is_active' => 'boolean',
        ]);

        $section = Section::create($validated);

        return response()->json($section, 201);
    }

    public function update(Request $request, Section $section): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'description' => 'nullable|string',
            'department_id' => 'nullable|exists:departments,id',
            'head_of_section_id' => 'nullable|exists:users,id',
            'is_active' => 'boolean',
        ]);

        $section->update($validated);

        return response()->json($section);
    }

    public function destroy(Section $section): JsonResponse
    {
        $section->delete();

        return response()->json(null, 204);
    }
}