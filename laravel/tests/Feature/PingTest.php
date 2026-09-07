<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L1 contract test: the Laravel API serves the unified envelope the React
 * SPA expects, with request-id correlation.
 */
class PingTest extends TestCase
{
    public function test_ping_returns_the_unified_envelope(): void
    {
        $response = $this->getJson('/api/v1/ping');

        $response->assertOk()
            ->assertHeader('x-request-id')
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Laravel API is reachable.')
            ->assertJsonStructure([
                'success',
                'message',
                'data' => ['service', 'version', 'time', 'framework'],
            ]);
    }

    public function test_ping_honours_an_incoming_request_id(): void
    {
        $response = $this->getJson('/api/v1/ping', ['X-Request-Id' => 'test-correlation-id-1234']);

        $response->assertOk()->assertHeader('x-request-id', 'test-correlation-id-1234');
    }

    public function test_unknown_api_route_returns_a_json_envelope_not_html(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response->assertStatus(404)->assertHeader('x-request-id');
        $this->assertStringContainsString('json', (string) $response->headers->get('Content-Type'));
    }
}
