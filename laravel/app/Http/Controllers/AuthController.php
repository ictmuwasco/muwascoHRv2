<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    protected function jwtSecret(): string
    {
        return config('jwt.secret');
    }

    protected function jwtTtl(): int
    {
        return config('jwt.ttl', 60);
    }

    protected function jwtAlgo(): string
    {
        return config('jwt.algo', 'HS256');
    }

    protected function createToken(User $user): string
    {
        $now = now()->timestamp;

        $payload = [
            'iss' => url('/'),
            'sub' => $user->id,
            'email' => $user->email,
            'role' => $user->role_id ?? null,
            'iat' => $now,
            'exp' => $now + ($this->jwtTtl() * 60),
            'jti' => (string) Str::uuid(),
        ];

        return JWT::encode($payload, $this->jwtSecret(), $this->jwtAlgo());
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');

        $validator = Validator::make($credentials, [
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $user = User::where('email', $credentials['email'])->first();

        if (!$user || !Hash::check($credentials['password'], $user->password)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        $token = $this->createToken($user);

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->bearerToken();

        if (!$token) {
            return response()->json(['error' => 'Authorization token not provided'], 401);
        }

        try {
            $payload = JWT::decode($token, new Key($this->jwtSecret(), $this->jwtAlgo()));
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Invalid token'], 401);
        }

        if (isset($payload->jti)) {
            cache()->put('jwt_blacklist:' . $payload->jti, true, now()->addSeconds($this->jwtTtl() * 60));
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function user(): JsonResponse
    {
        return response()->json(auth()->user());
    }

    public function refresh(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        return response()->json([
            'user' => $user,
            'token' => $this->createToken($user),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = auth()->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['error' => 'Current password is incorrect'], 403);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        return response()->json(['message' => 'Password changed successfully']);
    }
}
