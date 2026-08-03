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
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'token' => $token,
            ],
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

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found', 'data' => null], 404);
        }

        $token = Str::random(64);

        \DB::table('password_resets')->updateOrInsert(
            ['email' => $user->email],
            ['token' => $token, 'created_at' => now()]
        );

        return response()->json(['success' => true, 'message' => 'Password reset token generated', 'data' => ['token' => $token]]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $reset = \DB::table('password_resets')->where('token', $validated['token'])->first();

        if (!$reset) {
            return response()->json(['success' => false, 'message' => 'Invalid or expired token', 'data' => null], 400);
        }

        $user = User::where('email', $reset->email)->first();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'User not found', 'data' => null], 404);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        \DB::table('password_resets')->where('email', $user->email)->delete();

        return response()->json(['success' => true, 'message' => 'Password reset successfully', 'data' => null]);
    }

    public function profile(): JsonResponse
    {
        $user = auth()->user();
        $employee = $user->employee()->with(['department', 'section', 'office'])->first();
        $nextOfKin = $employee?->next_of_kin ? json_decode($employee->next_of_kin, true) : [];

        $profile = [
            'personal' => [
                'first_name' => $employee->first_name ?? $user->name,
                'last_name' => $employee->last_name ?? '',
                'surname' => $employee->surname ?? '',
                'email' => $user->email,
                'phone' => $employee->phone ?? $user->phone,
                'national_id' => $employee->national_id ?? null,
                'gender' => $employee->gender ?? null,
                'marital_status' => $employee->marital_status ?? null,
                'address' => $employee->address ?? $user->address,
            ],
            'employment' => [
                'department' => $employee?->department?->name ?? null,
                'section' => $employee?->section?->name ?? null,
                'office' => $employee?->office?->name ?? null,
                'designation' => $employee->designation ?? null,
                'employee_type' => $employee->employee_type ?? null,
                'employee_status' => $employee->employee_status ?? null,
                'employment_date' => optional($employee->hire_date)->toDateString(),
            ],
            'next_of_kin' => [
                'name' => $nextOfKin['name'] ?? '',
                'relationship' => $nextOfKin['relationship'] ?? '',
                'phone' => $nextOfKin['phone'] ?? '',
                'email' => $nextOfKin['email'] ?? '',
                'address' => $nextOfKin['address'] ?? '',
            ],
            'documents' => [],
        ];

        return response()->json(['success' => true, 'message' => 'Profile loaded', 'data' => $profile]);
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
