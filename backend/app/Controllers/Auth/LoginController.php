<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Core\Controller;
use App\Services\Contracts\AuthServiceInterface;
use App\Services\AuthService;
use App\Repositories\UserRepository;
use App\Repositories\EmployeeRepository;
use App\Models\Consent;

class LoginController extends Controller
{
    private AuthServiceInterface $authService;
    private Consent $consentModel;

    public function __construct()
    {
        // Dependency injection
        $this->authService = new AuthService();
        $this->authService->setUserRepository(new UserRepository());
        $this->authService->setEmployeeRepository(new EmployeeRepository());
        
        $this->consentModel = new Consent();
    }

    /**
     * Display the login form
     * GET /login
     */
    public function indexAction(): void
    {
        // Already logged in → go to dashboard
        if ($this->isAuthenticated()) {
            $this->redirect('dashboard');
            return;
        }

        $this->view('auth/login/index', [
            'csrf_token' => $this->generateCsrfToken(),
            'error'      => $_SESSION['flash_error'] ?? '',
        ]);

        unset($_SESSION['flash_error']);
    }

   
    public function authenticateAction(): void
    {
        // ── POST guard ────────────────────────────────────────────────────────
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->redirect('login');
            return;
        }

        // ── CSRF ──────────────────────────────────────────────────────────────
        if (!$this->validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Security token invalid. Please refresh and try again.';
            $this->logSecurityEvent('csrf_validation_failed');
            $this->redirect('login');
            return;
        }

        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        // ── Input validation ──────────────────────────────────────────────────
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Please enter a valid email address.';
            $this->redirect('login');
            return;
        }
        if (empty($password)) {
            $_SESSION['flash_error'] = 'Please enter your password.';
            $this->redirect('login');
            return;
        }

        // ── Rate limiting ─────────────────────────────────────────────────────
        if (!$this->checkLoginRateLimit($email)) {
            $_SESSION['flash_error'] = 'Too many attempts. Wait 15 minutes.';
            $this->logSecurityEvent('rate_limit_exceeded', 0, ['email' => $email]);
            $this->redirect('login');
            return;
        }

        // ── Authenticate using service ─────────────────────────────────────────
        try {
            $user = $this->authService->getUserByEmail($email);
            
            if (!$user || !$this->authService->validateCredentials($email, $password)) {
                $_SESSION['flash_error'] = 'The email or password you entered is incorrect.';
                $this->trackFailedLogin($email);
                $this->redirect('login');
                return;
            }

            // ── Account active? ───────────────────────────────────────────────────
            if (!$this->authService->isUserActive((int)$user['id'])) {
                $_SESSION['flash_error'] = 'Your account is inactive. Contact HR.';
                $this->logSecurityEvent('login_attempt_inactive_account', (int)$user['id'], $email);
                $this->redirect('login');
                return;
            }

            // ── Upgrade legacy plain-text password ────────────────────────────────
            if ($password === $user['password']) {
                $this->authService->updatePassword((int)$user['id'], $password);
                $this->logSecurityEvent('password_upgraded', (int)$user['id'], $email);
            }
        } catch (\InvalidArgumentException $e) {
            $_SESSION['flash_error'] = 'The email or password you entered is incorrect.';
            $this->trackFailedLogin($email);
            $this->redirect('login');
            return;
        }

        $this->clearLoginAttempts($email);

        // ── Consent gate ──────────────────────────────────────────────────────
        if (!$this->consentModel->hasConsent((int)$user['id'])) {
            $_SESSION['pending_user_id']       = (int)$user['id'];
            $_SESSION['pending_user_email']    = $user['email'];
            $_SESSION['pending_user_name']     = trim($user['first_name'] . ' ' . $user['last_name']);
            $_SESSION['pending_user_role']     = $user['role'];
            $_SESSION['pending_designation']   = $user['designation'];
            $_SESSION['pending_auth_time']     = time();
            $this->logSecurityEvent('consent_required_redirect', (int)$user['id'], $email);
            $this->redirect('login/consent');
            return;
        }

        // ── Full login ────────────────────────────────────────────────────────
        $this->completeLogin($user);
        $this->redirect('dashboard');
    }

    /**
     * Complete the login: set session variables + generate JWT tokens
     */
    private function completeLogin(array $user): void
    {
        $userId = (int)$user['id'];

        // Resolve employee name
        $employeeName = trim($user['first_name'] . ' ' . $user['last_name']);
        if ($employeeName === '') {
            $emp = $this->authService->getUserByEmail($user['email']);
            if ($emp && !empty($emp['employee'])) {
                $employeeName = trim($emp['employee']['first_name'] . ' ' . $emp['employee']['last_name']);
            }
        }

        // Create device session (db_sessions upsert)
        $token = $this->createDeviceSession($userId);

        // Generate JWT tokens (access + refresh) and set as HTTP-only cookies
        $this->generateJwtTokens($user);

        $_SESSION['user_id']            = $userId;
        $_SESSION['user_email']         = $user['email'];
        $_SESSION['user_name']          = $employeeName;
        $_SESSION['user_role']          = $user['role'];
        $_SESSION['designation']        = $user['designation'];
        $_SESSION['employee_id']        = $user['employee_id'] ?? null;
        $_SESSION['login_time']         = time();
        $_SESSION['login_identifier']   = bin2hex(random_bytes(16));
        $_SESSION['ip_address']         = $_SERVER['REMOTE_ADDR'] ?? '';
        $_SESSION['browser_fingerprint']= $this->generateDeviceFingerprint();
        $_SESSION['session_valid']      = true;
        $_SESSION['ag_token']           = $token;
        $_SESSION['session_token']      = $token;
        $_SESSION['last_activity']      = time();

        // Backward-compat aliases
        $_SESSION['hr_system_user_id']   = $userId;
        $_SESSION['hr_system_username']  = $employeeName;
        $_SESSION['hr_system_user_role'] = $user['role'];

        $this->logSecurityEvent('login_success', $userId, $user['email']);
        $this->trackAction('LOGIN_SUCCESS', 'User logged in successfully', [
            'username' => $employeeName,
            'user_id'  => $userId,
        ]);
    }

    /**
     * Logout: destroy session, clear JWT tokens, and redirect
     * GET /login/logout or GET /api/auth/logout
     */
    public function logoutAction(): void
    {
        $userId = $_SESSION['user_id'] ?? 0;
        $this->logSecurityEvent('logout', $userId);
        $this->trackAction('LOGOUT', 'User logged out', ['user_id' => $userId]);

        // Clear JWT tokens
        $this->clearJwtTokens();

        // Revoke all refresh tokens for this user
        if ($userId > 0) {
            $this->jwt->revokeAllTokens($userId);
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();

        // Return JSON for API requests, redirect for web requests
        if ($this->isApiRequest()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
            exit();
        }

        $this->redirect('login');
    }
}
