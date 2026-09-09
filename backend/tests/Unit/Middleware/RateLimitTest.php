<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Tests\TestCase;
use App\Middleware\SecurityMiddleware;

/**
 * Phase 1 rate-limit tests (file-backed brute-force protection).
 */
class RateLimitTest extends TestCase
{
    private string $action;
    private array $buckets = [];
    private string $remoteAddrBackup = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->remoteAddrBackup = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        $this->action = 'test_action_' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach ($this->buckets as $key) {
            @unlink(SecurityMiddleware::rateLimitPath($key));
        }
        $_SERVER['REMOTE_ADDR'] = $this->remoteAddrBackup;

        parent::tearDown();
    }

    private function bucket(?string $identifier = null): string
    {
        $key = SecurityMiddleware::rateLimitKey($this->action, $identifier);
        $this->buckets[] = $key;

        return $key;
    }

    public function test_blocks_after_max_attempts(): void
    {
        $max = 3;
        $key = $this->bucket();

        $this->assertTrue(SecurityMiddleware::checkRateLimit($this->action, $max, 900));
        $this->assertTrue(SecurityMiddleware::checkRateLimit($this->action, $max, 900));
        $this->assertTrue(SecurityMiddleware::checkRateLimit($this->action, $max, 900));
        $this->assertFalse(SecurityMiddleware::checkRateLimit($this->action, $max, 900));

        // The bucket must have persisted to disk.
        $this->assertFileExists(SecurityMiddleware::rateLimitPath($key));
    }

    public function test_identifier_isolation(): void
    {
        $this->bucket('victim@example.com');
        $this->bucket('other@example.com');
        $this->bucket(null);

        // Exhaust the bucket for one account...
        $this->assertTrue(SecurityMiddleware::checkRateLimit($this->action, 1, 900, 'victim@example.com'));
        $this->assertFalse(SecurityMiddleware::checkRateLimit($this->action, 1, 900, 'victim@example.com'));

        // ...other accounts and identifiers stay unaffected.
        $this->assertTrue(SecurityMiddleware::checkRateLimit($this->action, 1, 900, 'other@example.com'));
        $this->assertTrue(SecurityMiddleware::checkRateLimit($this->action, 1, 900));
    }

    public function test_window_expiry_resets_the_counter(): void
    {
        $this->bucket();

        $this->assertTrue(SecurityMiddleware::checkRateLimit($this->action, 2, 900));
        $this->assertTrue(SecurityMiddleware::checkRateLimit($this->action, 2, 900));
        $this->assertFalse(SecurityMiddleware::checkRateLimit($this->action, 2, 900));

        // Simulate an old bucket from a previous window.
        $path = SecurityMiddleware::rateLimitPath(SecurityMiddleware::rateLimitKey($this->action, null));
        file_put_contents($path, json_encode(['count' => 999, 'first' => time() - 100000]));

        $this->assertTrue(SecurityMiddleware::checkRateLimit($this->action, 2, 900));
    }
}