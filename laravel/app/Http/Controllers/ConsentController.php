<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsentController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Consent::all());
    }

    public function update(Request $request, Consent $consent): JsonResponse
    {
        $validated = $request->validate([
            'is_accepted' => 'required|boolean',
            'accepted_at' => 'nullable|date',
        ]);

        $consent->update($validated);

        return response()->json($consent);
    }
}