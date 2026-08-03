<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Services\AuthService;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Helpers\Hash;
use App\Helpers\Session;
use Mockery;

class AuthServiceTest extends TestCase
{
    private AuthService $service;
    private $mockUserRepo;
    private $mockHash;
    private $mockSession;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockUserRepo = Mockery::mock(UserRepositoryInterface::class);
        $this->mockHash = Mockery::mock(Hash::class);
        $this->mockSession = Mockery::mock(Session::class);
        
        $this->service = new AuthService(
            $this->mockUserRepo,
            $this->mockHash,
            $this->mockSession
        );
    }

    public function test_can_login_with_valid_credentials(): void
    {
        $email = 'test@example.com';
        $password = 'password123';
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

        $user = [
            'id' => 1,
            'email' => $email,
            'password' => $hashedPassword,
            'first_name' => 'Test',
            'last_name' => 'User',
            'role' => 'admin',
            'is_active' => true
        ];

        $this->mockUserRepo
            ->shouldReceive('findByEmail')
            ->once()
            ->with($email)
            ->andReturn($user);

        $this->mockHash
            ->shouldReceive('verify')
            ->once()
            ->with($password, $hashedPassword)
            ->andReturn(true);

        $this->mockSession
            ->shouldReceive('set')
            ->once();

        $result = $this->service->login($email, $password);

        $this->assertTrue($result['success']);
        $this->assertEquals('Test', $result['user']['first_name']);
    }

    public function test_cannot_login_with_invalid_password(): void
    {
        $email = 'test@example.com';
        $password = 'wrongpassword';
        $hashedPassword = password_hash('password123', PASSWORD_DEFAULT);

        $user = [
            'id' => 1,
            'email' => $email,
            'password' => $hashedPassword,
            'is_active' => true
        ];

        $this->mockUserRepo
            ->shouldReceive('findByEmail')
            ->once()
            ->andReturn($user);

        $this->mockHash
            ->shouldReceive('verify')
            ->once()
            ->andReturn(false);

        $result = $this->service->login($email, $password);

        $this->assertFalse($result['success']);
        $this->assertEquals('Invalid credentials', $result['message']);
    }

    public function test_can_logout(): void
    {
        $this->mockSession
            ->shouldReceive('destroy')
            ->once();

        $result = $this->service->logout();

        $this->assertTrue($result['success']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}