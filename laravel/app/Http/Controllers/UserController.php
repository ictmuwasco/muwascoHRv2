<?php

namespace App\\Http\\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Contracts\UserServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function __construct(private UserServiceInterface $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['email', 'role_id']);
        $users = $this->service->list($filters);

        return response()->json([
            'success' => true,
            'message' => 'Users loaded',
            'data' => $users,
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user = $this->service->get($user->id);
        return response()->json([
            'success' => true,
            'message' => 'User loaded',
            'data' => $user,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role_id' => 'nullable|exists:roles,id',
            'department_id' => 'nullable|exists:departments,id',
            'employee_id' => 'nullable|exists:employees,id',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $validated['password'] = Hash::make($validated['password']);
        $user = $this->service->create($validated);

        return response()->json($user, 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|unique:users,email,' . $user->id,
            'role_id' => 'nullable|exists:roles,id',
            'department_id' => 'nullable|exists:departments,id',
            'employee_id' => 'nullable|exists:employees,id',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'status' => 'nullable|in:active,inactive',
        ]);

        $user = $this->service->update($user, $validated);

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully',
            'data' => $user,
        ]);
    }

    public function toggleStatus(User $user): JsonResponse
    {
        $user->status = $user->status === 'active' ? 'inactive' : 'active';
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'User status updated',
            'data' => $user,
        ]);
    }

    public function changePassword(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        $this->service->update($user, [
            'password' => Hash::make($validated['password']),
        ]);

        return response()->json(['message' => 'Password changed successfully']);
    }
}

