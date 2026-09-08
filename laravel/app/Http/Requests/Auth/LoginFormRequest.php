<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * L2: Login validation. Mirrors the legacy contract:
 * email (required|string|email), password (required|string).
 * Uses the legacy API response shape for the front-end (errors.detail).
 */
class LoginFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Login is public.
    }

    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
        ];
    }

    /**
     * The API contract requires email normalization (lowercased, trimmed)
     * — this is where the legacy AuthService applied it before the DB lookup.
     */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated($key, $default);
        $data['email'] = strtolower(trim($data['email']));
        return $data;
    }
}