<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\AuthService;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Helpers\Hash;
use App\Helpers\Session;
use App\Helpers\JWT;
use Mockery;

/**
 * Phase 1 authentication tests for AuthService.
 *
 * Verifies the login lifecycle (credential check -> account status -> JWT
 * issuance), anti-enumeration message parity, and the password policy +
 * token behaviour of password mutations.
 */
class AuthServiceTest extends TestCase
{
    private const TEST_SECRET = 'phase1-test-secret-0123456789abcdef0123456789abcdef';

    private AuthService $service;
    private $mockUserRepo;
    private $mockEmployeeRepo;
    private $mockHash;
    private $mockSession;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic, strong JWT secret so login() can mint real tokens.
        putenv('JWT_SECRET=' . self::TEST_SECRET);
        $_ENV['JWT_SECRET'] = self::TEST_SECRET;
        $_SERVER['JWT_SECRET'] = self::TEST_SECRET;
        JWT::resetInstance();

        $this->mockUserRepo = Mockery::mock(UserRepositoryInterface::class);
        $this->mockEmployeeRepo = Mockery::mock(EmployeeRepositoryInterface::class);
        $this->mockHash = Mockery::mock(Hash::class);
        $this->mockSession = Mockery::mock(Session::class);

        $this->service = new AuthService($this->mockUserRepo, $this->mockHash, $this->mockSession);
        $this->service->setEmployeeRepository($this->mockEmployeeRepo);
    }

    protected function tearDown(): void
    {
        JWT::resetInstance();
        Mockery::close();
        parent::tearDown();
    }

    private function user(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'email' => 'test@example.com',
            'password' => password_hash('Sup3rSecret!pass', PASSWORD_DEFAULT),
            'first_name' => 'Test',
            'last_name' => 'User',
            'role' => 'admin',
            'is_active' => 1,
        ], $overrides);
    }

    public function test_login_with_valid_credentials_returns_user_and_signed_token(): void
    {
        $user = $this->user();
        $employee = ['id' => 42, 'first_name' => 'Test', 'last_name' => 'User'];

        $this->mockUserRepo->shouldReceive('findByEmail')->once()->with('test@example.com')->andReturn($user);
        $this->mockHash->shouldReceive('verify')->once()->andReturn(true);
        $this->mockUserRepo->shouldReceive('findById')->once()->with(1)->andReturn($user); // isUserActive
        $this->mockEmployeeRepo->shouldReceive('findByEmail')->once()->andReturn($employee);
        $this->mockUserRepo->shouldReceive('update')->once()->with(1, Mockery::on(fn ($v) => isset($v['last_activity'])));
        $this->mockSession->shouldReceive('set')->zeroOrMoreTimes();

        $result = $this->service->login('Test@Example.com', 'Sup3rSecret!pass');

        $this->assertSame(1, $result['user']['id']);
        $this->assertSame('test@example.com', $result['user']['email']);
        $this->assertSame(42, $result['user']['employee']['id']);
        $this->assertArrayHasKey('token', $result);
        $this->assertNotEmpty($result['token']);
        // JWT shape: header.payload.signature
        $this->assertSame(2, substr_count((string) $result['token'], '.'));

        // The issued token must cryptographically validate as an ACCESS token.
        $claims = JWT::getInstance()->validateAccessToken((string) $result['token']);
        $this->assertNotNull($claims);
        $this->assertSame(1, (int) $claims->sub);
        $this->assertSame('access', $claims->type);
    }

    public function test_login_with_unknown_user_fails_with_generic_message(): void
    {
        $this->mockUserRepo->shouldReceive('findByEmail')->once()->andReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $this->service->login('nobody@example.com', 'whatever-password');
    }

    public function test_login_with_wrong_password_fails_with_generic_message(): void
    {
        $user = $this->user();

        $this->mockUserRepo->shouldReceive('findByEmail')->once()->andReturn($user);
        $this->mockHash->shouldReceive('verify')->once()->andReturn(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $this->service->login('test@example.com', 'wrong-password');
    }

    public function test_login_with_inactive_account_is_indistinguishable_from_bad_password(): void
    {
        $user = $this->user(['is_active' => 0]);

        $this->mockUserRepo->shouldReceive('findByEmail')->once()->andReturn($user);
        $this->mockHash->shouldReceive('verify')->once()->andReturn(true);
        $this->mockUserRepo->shouldReceive('findById')->once()->with(1)->andReturn($user); // isUserActive

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $this->service->login('test@example.com', 'Sup3rSecret!pass');
    }

    public function test_update_password_rejects_short_passwords(): void
    {
        $user = $this->user();
        $this->mockUserRepo->shouldReceive('findById')->once()->with(1)->andReturn($user);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Password must be at least 8 characters');

        $this->service->updatePassword(1, 'short');
    }

    public function test_update_password_hashes_and_stores_the_new_password(): void
    {
        $user = $this->user();
        $this->mockUserRepo->shouldReceive('findById')->once()->with(1)->andReturn($user);
        $this->mockHash->shouldReceive('make')->once()->with('LongEnough1!')->andReturn('argon2id-hash');
        $this->mockUserRepo->shouldReceive('updatePassword')->once()->with(1, 'argon2id-hash')->andReturn(true);

        $this->assertTrue($this->service->updatePassword(1, 'LongEnough1!'));
    }

    public function test_verify_token_rejects_garbage_and_accepts_valid_access_tokens(): void
    {
        $this->assertNull($this->service->verifyToken('not-a-jwt'));

        $token = JWT::getInstance()->generateAccessToken(['id' => 5, 'email' => 'v@example.com', 'role' => 'hr']);
        $claims = $this->service->verifyToken($token);

        $this->assertNotNull($claims);
        $this->assertSame(5, $claims['sub']);
        $this->assertSame('access', $claims['type']);
    }
}