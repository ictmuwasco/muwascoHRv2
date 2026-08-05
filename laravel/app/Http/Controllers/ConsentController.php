<?php

namespace App\\Http\\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Consent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsentController extends Controller
{
    public function index(): JsonResponse
    {
        $consents = Consent::all()->map(function (Consent $consent) {
            return [
                'id' => $consent->id,
                'user_id' => $consent->user_id,
                'type' => $consent->type ?? 'Data Protection',
                'status' => (bool) $consent->consent_given,
                'agreed_at' => $consent->consent_date?->toDateTimeString(),
                'ip_address' => $consent->ip_address,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Consents loaded',
            'data' => $consents,
        ]);
    }

    public function update(Request $request, Consent $consent): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|boolean',
        ]);

        $consent->update([
            'consent_given' => $validated['status'],
            'consent_date' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Consent updated',
            'data' => [
                'id' => $consent->id,
                'user_id' => $consent->user_id,
                'type' => $consent->type ?? 'Data Protection',
                'status' => (bool) $consent->consent_given,
                'agreed_at' => $consent->consent_date?->toDateTimeString(),
                'ip_address' => $consent->ip_address,
            ],
        ]);
    }
}

