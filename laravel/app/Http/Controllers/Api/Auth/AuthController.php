<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordFormRequest;
use App\Http\Requests\Auth\LoginFormRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * L2: Authentication controller.
 *
 * Replaces the legacy backend/app/Controllers/Auth/AuthController.php with a
 * thin Laravel controller backed by Sanctum SPA-cookie authentication.
 *
 * Business rules (ported from AuthService):
 *  - email is normalized (lowercased, trimmed) before lookup
 *  - password verified BEFORE account-status check (no user enumeration)
 *  - inactive accounts receive the same "Invalid credentials" message
 *  - login rate-limited per IP + per account (throttle middleware)
 *  - every login/logout is audit-logged
 *  - /auth/user returns only required fields (no leave/attendance history)
  */

class AuthController extends Controller
{
    use ApiResponse;

    /**
     * POST /api/v1/auth/login
     *
     * Authenticates the user via email + password and issues a Sanctum API token.
     * The password is verified BEFORE the is_active check so that the
     * failure response is indistinguishable — no user enumeration.
     */
    public function login(LoginFormRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        // Business rule: find the user by normalized email.
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], (string) $user->password)) {
            // Audit: failed login (never reveals whether the account exists).
            if ($user) {
                logger()->warning('auth.login_failed', ['user_id' => $user->id]);
            } else {
                logger()->warning('auth.login_failed_unknown_account', ['email' => $credentials['email']]);
            }

            return $this->failure(
                'Invalid credentials',
                401,
                'AUTH_INVALID_CREDENTIALS',
                [],
            );
        }

        // Business rule: account-status check (must be active).
        if (! $user->is_active) {
            logger()->warning('auth.login_inactive_account', ['user_id' => $user->id]);

            return $this->failure(
                'Invalid credentials',
                401,
                'AUTH_INVALID_CREDENTIALS',
                [],
            );
        }

        // Issue a Sanctum API token.
        $token = $user->createToken('api-token')->plainTextToken;

        // Audit: successful login.
        logger()->info('auth.login_success', [
            'user_id' => $user->id,
            'ip'      => $request->ip(),
        ]);

        return $this->success(
            data: array_merge($this->safeUserPayload($user), ['token' => $token]),
            message: 'Login successful.',
        );
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revokes the current Sanctum token.
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            // Revoke the current token (the one used to authenticate this request).
            $user->currentAccessToken()->delete();
            logger()->info('auth.logout', ['user_id' => $user->id]);
        }

        return $this->success(null, 'Logout successful.');
    }

    /**
     * GET /api/v1/auth/user
     *
     * Returns only the data required by the frontend permission context.
     * Does NOT load the user's leave history, attendance, meetings, etc.
     */
    public function user(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return $this->failure('Not authenticated', 401, 'AUTH_NOT_AUTHENTICATED');
        }

        return $this->success($this->safeUserPayload($user));
    }

    /**
     * POST /api/v1/auth/change-password
     *
     * Admin-driven password change (legacy AuthService::updatePassword port).
     */
    public function changePassword(ChangePasswordFormRequest $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validated();

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($validated['new_password']),
        ]);

        $user->tokens()->delete();
        logger()->info('auth.password_changed', ['user_id' => $user->id]);

        return $this->success(null, 'Password changed successfully.');
    }

    /**
     * Build the minimal user payload for /auth/user and login response.
     * Only the fields the React SPA needs are included.
     */
    private function safeUserPayload(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id'          => (int) $user->id,
            'email'       => $user->email,
            'first_name'  => $user->first_name,
            'last_name'   => $user->last_name,
            'surname'     => $user->surname,
            'role'        => $user->role,
            'is_active'   => (bool) $user->is_active,
            'employee_id' => $user->employee_id,
        ];
    }
}