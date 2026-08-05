<?php

namespace App\\Http\\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Section;
use App\Services\Contracts\SectionServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SectionController extends Controller
{
    public function __construct(private SectionServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['department_id', 'is_active']);
        $sections = $this->service->list($filters);

        return response()->json($sections);
    }

    public function show(Section $section): JsonResponse
    {
        $section = $this->service->get($section->id);

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

        $section = $this->service->create($validated);

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

        $section = $this->service->update($section, $validated);

        return response()->json($section);
    }

    public function destroy(Section $section): JsonResponse
    {
        $this->service->delete($section);

        return response()->json(null, 204);
    }
}

