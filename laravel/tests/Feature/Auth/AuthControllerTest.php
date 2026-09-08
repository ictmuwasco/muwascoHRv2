<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_valid_login_returns_envelope_and_user(): void
    {
        $user = User::factory()->create([
            'email'     => 'test@example.com',
            'password'  => Hash::make('password123'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk()
            ->assertHeader('x-request-id')
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonStructure([
                'success', 'message', 'data' => [
                    'id', 'email', 'first_name', 'last_name',
                    'surname', 'role', 'is_active', 'employee_id', 'token',
                ],
            ])
            ->assertJsonPath('data.id', $user->id);
    }

    public function test_invalid_login_returns_401(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'nobody@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Invalid credentials')
            ->assertJsonPath('errors.code', 'AUTH_INVALID_CREDENTIALS');
    }

    public function test_login_no_user_enumeration(): void
    {
        User::factory()->create([
            'email'    => 'exists@example.com',
            'password' => Hash::make('password123'),
        ]);

        $resp1 = $this->postJson('/api/v1/auth/login', [
            'email'    => 'exists@example.com',
            'password' => 'wrongpassword',
        ]);

        $resp2 = $this->postJson('/api/v1/auth/login', [
            'email'    => 'missing@example.com',
            'password' => 'whateverpassword',
        ]);

        $this->assertSame(
            $resp1->json('message'),
            $resp2->json('message'),
            'Both failures must return the same message.'
        );
    }

    public function test_inactive_account_returns_401(): void
    {
        User::factory()->inactive()->create([
            'email'    => 'inactive@example.com',
            'password' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email'    => 'inactive@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJsonPath('message', 'Invalid credentials');
    }

    public function test_auth_user_endpoint_returns_minimal_payload(): void
    {
        $user = User::factory()->create([
            'email'    => 'me@example.com',
            'password' => Hash::make('password123'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/auth/user');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message', 'data' => [
                    'id', 'email', 'first_name', 'last_name',
                    'surname', 'role', 'is_active', 'employee_id',
                ],
            ])
            ->assertJsonMissing(['data' => ['password']]);
    }

    public function test_auth_user_requires_authentication(): void
    {
        $response = $this->getJson('/api/v1/auth/user');
        $response->assertStatus(401)
            ->assertJsonPath('success', false);
    }

    public function test_logout_revokes_token(): void
    {
        $user = User::factory()->create([
            'email'    => 'bye@example.com',
            'password' => Hash::make('password123'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logout successful.');
    }

    public function test_login_throttle_triggers_429(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $resp = $this->postJson('/api/v1/auth/login', [
                'email'    => 'throttle@example.com',
                'password' => 'wrong',
            ]);
        }

        $resp->assertStatus(429);
    }
}