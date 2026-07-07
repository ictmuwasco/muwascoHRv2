<?php

declare(strict_types=1);

namespace App\Core;

use App\Helpers\JWT;
use App\Helpers\RBAC;

/**
 * Base Controller
 *
 * Provides shared functionality for all controllers:
 *   - JWT authentication (token-based) + session fallback
 *   - RBAC permission checking via App\Helpers\RBAC
 *   - View rendering
 *   - CSRF token generation & validation
 *   - Rate limiting
 *   - Device session management (db_sessions)
 *   - Security event logging
 *   - Audit trail (trackAction)
 *
 * Place: backend/app/Core/Controller.php
 */
abstract class Controller
{
    protected ?JWT $jwt = null;
    protected ?RBAC $rbac = null;
    protected ?array $currentUser = null;

    public function __construct()
    {
        $this->jwt  = JWT::getInstance();
        $this->rbac = RBAC::getInstance();
        $this->autoAuthenticate();
    }

    /**
     * Auto-authenticate from JWT token (Bearer header or cookie).
     * Falls back to session-based auth.
     */
    private function autoAuthenticate(): void
    {
        // Try JWT first
        $user = $this->jwt->getAuthenticatedUser();
        if ($user) {
            $this->currentUser = $user;
            if (!isset($_SESSION['user_id'])) {
                $_SESSION['user_id']       = (int)$user['id'];
                $_SESSION['user_email']    = $user['email'] ?? '';
                $_SESSION['user_name']     = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
                $_SESSION['user_role']     = $user['role'] ?? '';
                $_SESSION['designation']   = $user['designation'] ?? '';
                $_SESSION['session_valid'] = true;
            }
            return;
        }

        // Fallback: session-based auth
        if (isset($_SESSION['user_id']) && isset($_SESSION['session_valid']) && $_SESSION['session_valid'] === true) {
            $this->currentUser = [
                'id'          => $_SESSION['user_id'],
                'email'       => $_SESSION['user_email'] ?? '',
                'name'        => $_SESSION['user_name'] ?? '',
                'role'        => $_SESSION['user_role'] ?? '',
                'designation' => $_SESSION['designation'] ?? '',
            ];
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  JWT Authentication
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Generate JWT tokens (access + refresh) and set as HTTP-only cookies.
     */
    protected function generateJwtTokens(array $user): array
    {
        $accessToken  = $this->jwt->generateAccessToken($user);
        $refreshToken = $this->jwt->generateRefreshToken($user);

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || $_SERVER['SERVER_PORT'] == 443;

        setcookie('access_token', $accessToken, [
            'expires'  => time() + 3600,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        setcookie('refresh_token', $refreshToken, [
            'expires'  => time() + 604800,
            'path'     => '/',
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return ['access_token' => $accessToken, 'refresh_token' => $refreshToken];
    }

    /**
     * Clear JWT tokens (logout).
     */
    protected function clearJwtTokens(): void
    {
        setcookie('access_token', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
        setcookie('refresh_token', '', ['expires' => time() - 3600, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  RBAC Permission Checking
    // ──────────────────────────────────────────────────────────────────────────

    protected function can(string $module, string $action): bool
    {
        return $this->rbac->hasPermission($this->currentUser['role'] ?? '', $module, $action);
    }

    protected function requirePermission(string $module, string $action): void
    {
        $this->rbac->requirePermission($module, $action);
    }

    protected function requireRole(string $requiredRole): void
    {
        $hierarchy = [
            'super_admin' => 8, 'managing_director' => 7, 'hr_manager' => 6,
            'bod_chair' => 5, 'dept_head' => 4, 'manager' => 3,
            'section_head' => 2, 'sub_section_head' => 1, 'employee' => 0,
        ];
        $userLevel    = $hierarchy[$this->currentUser['role'] ?? ''] ?? 0;
        $requiredLevel = $hierarchy[$requiredRole] ?? 0;

        if ($userLevel < $requiredLevel) {
            if ($this->isApiRequest()) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode(['error' => 'Forbidden: Insufficient role privileges.']);
                exit();
            }
            $_SESSION['flash_error'] = 'You do not have permission to access this resource.';
            $this->redirect('dashboard');
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Authentication helpers
    // ──────────────────────────────────────────────────────────────────────────

    protected function isAuthenticated(): bool
    {
        return $this->currentUser !== null;
    }

    protected function getUserId(): int
    {
        return (int)($this->currentUser['id'] ?? 0);
    }

    protected function getUserRole(): string
    {
        return $this->currentUser['role'] ?? '';
    }

    protected function getUser(): ?array
    {
        return $this->currentUser;
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  CSRF
    // ──────────────────────────────────────────────────────────────────────────

    protected function generateCsrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    protected function validateCsrfToken(string $token): bool
    {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Rate limiting
    // ──────────────────────────────────────────────────────────────────────────

    protected function checkLoginRateLimit(string $email): bool
    {
        $key = 'login_attempts_' . md5($email . ($_SERVER['REMOTE_ADDR'] ?? ''));
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 1, 'first_attempt' => time()];
            return true;
        }
        $a = $_SESSION[$key];
        if (time() - $a['first_attempt'] > 900) {
            $_SESSION[$key] = ['count' => 1, 'first_attempt' => time()];
            return true;
        }
        if ($a['count'] >= 5) return false;
        $a['count']++;
        $_SESSION[$key] = $a;
        return true;
    }

    protected function clearLoginAttempts(string $email): void
    {
        unset($_SESSION['login_attempts_' . md5($email . ($_SERVER['REMOTE_ADDR'] ?? ''))]);
    }

    protected function trackFailedLogin(string $email): void
    {
        $this->logSecurityEvent('login_failed', 0, ['email' => $email]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Device session (db_sessions)
    // ──────────────────────────────────────────────────────────────────────────

    protected function generateDeviceFingerprint(): string
    {
        return hash('sha256',
            ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' .
            ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '') . '|' .
            ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')
        );
    }

    protected function ensureSessionTable(\mysqli $conn): void
    {
        $conn->query("
            CREATE TABLE IF NOT EXISTS `db_sessions` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `user_id` INT NOT NULL, `device_fp` CHAR(64) NOT NULL,
                `session_token` CHAR(64) NOT NULL, `ip_address` VARCHAR(45) NOT NULL DEFAULT '',
                `user_agent` VARCHAR(512) NOT NULL DEFAULT '', `last_activity` DATETIME NOT NULL,
                `displaced_at` DATETIME DEFAULT NULL, `displaced_by` VARCHAR(120) DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_user_device` (`user_id`, `device_fp`),
                INDEX `idx_token` (`session_token`), INDEX `idx_user` (`user_id`),
                INDEX `idx_activity` (`last_activity`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    protected function createDeviceSession(int $userId): string
    {
        $conn = $this->getDbConnection();
        $this->ensureSessionTable($conn);
        $token = bin2hex(random_bytes(32));
        $fp    = $this->generateDeviceFingerprint();
        $ip    = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ?: '';
        $ua    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512);

        $st = $conn->prepare("UPDATE db_sessions SET displaced_at = CASE WHEN displaced_at IS NULL THEN NOW() ELSE displaced_at END, displaced_by = CASE WHEN displaced_by IS NULL THEN ? ELSE displaced_by END WHERE user_id = ? AND device_fp != ? AND displaced_at IS NULL");
        $st->bind_param("sis", $ip, $userId, $fp); $st->execute(); $st->close();

        $st = $conn->prepare("INSERT INTO db_sessions (user_id, device_fp, session_token, ip_address, user_agent, last_activity, displaced_at, displaced_by, created_at) VALUES (?, ?, ?, ?, ?, NOW(), NULL, NULL, NOW()) ON DUPLICATE KEY UPDATE session_token = VALUES(session_token), ip_address = VALUES(ip_address), user_agent = VALUES(user_agent), last_activity = NOW(), displaced_at = NULL, displaced_by = NULL");
        $st->bind_param("issss", $userId, $fp, $token, $ip, $ua); $st->execute(); $st->close();

        $st = $conn->prepare("UPDATE users SET session_token = ?, last_activity = NOW() WHERE id = ?");
        if ($st) { $st->bind_param("si", $token, $userId); $st->execute(); $st->close(); }
        return $token;
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Employee status messages
    // ──────────────────────────────────────────────────────────────────────────

    protected function getEmployeeStatusMessage(string $status): string
    {
        $status = strtolower($status);
        
        switch ($status) {
            case 'inactive':
                return 'Your account is currently inactive. Please contact HR for assistance.';
            case 'terminated':
            case 'fired':
            case 'dismissed':
                return 'Your employment has been terminated. Access is no longer available.';
            case 'retired':
                return 'You have retired. System access is no longer available.';
            case 'resigned':
                return 'You have resigned. System access has been revoked.';
            case 'suspended':
                return 'Your account is temporarily suspended. Contact HR.';
            case 'on_leave':
                return 'You are currently on leave. System access may be restricted.';
            default:
                return 'Your account is not active. Please contact HR.';
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Security logging
    // ──────────────────────────────────────────────────────────────────────────

    protected function logSecurityEvent(string $eventType, int $userId = 0, mixed $extra = []): void
    {
        $conn = $this->getDbConnection();
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $details = json_encode(['script' => $_SERVER['PHP_SELF'] ?? '', 'referrer' => $_SERVER['HTTP_REFERER'] ?? '', 'extra' => $extra]);
        try {
            $stmt = $conn->prepare("INSERT INTO security_logs (user_id, event_type, ip_address, user_agent, details) VALUES (?, ?, ?, ?, ?)");
            $uid = $userId ?: null;
            $stmt->bind_param("issss", $uid, $eventType, $ipAddress, $userAgent, $details);
            $stmt->execute(); $stmt->close();
        } catch (\Exception $e) {
            error_log("Security logging failed: " . $e->getMessage());
        }
    }


    protected function trackAction(string $action, string $description, array $details = []): void
    {
        if (function_exists('trackAction')) {
            trackAction($action, $description, $details);
        }
    }

    protected function view(string $view, array $data = []): void
    {
        $viewPath = dirname(__DIR__) . '/Views/' . str_replace('.', '/', $view) . '.php';
        if (!file_exists($viewPath)) {
            throw new \RuntimeException("View not found: {$viewPath}");
        }
        extract($data, EXTR_SKIP);
        require $viewPath;
    }

    /**
     * Render a partial view and return as string.
     */
    protected function renderPartial(string $view, array $data = []): string
    {
        $viewPath = dirname(__DIR__) . '/Views/' . str_replace('.', '/', $view) . '.php';
        if (!file_exists($viewPath)) {
            throw new \RuntimeException("Partial view not found: {$viewPath}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        require $viewPath;
        return ob_get_clean();
    }

    /**
     * Send JSON response and exit.
     */
    protected function json(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit();
    }

    protected function redirect(string $path): void
    {
        // Ensure path is absolute to avoid browser relative-path resolution
        $path = '/' . ltrim($path, '/');
        
        // Prepend subdirectory if app is not in web root
        if (defined('BASE_URL') && BASE_URL !== '') {
            $path = rtrim(BASE_URL, '/') . $path;
        }
        
        header('Location: ' . $path);
        exit();
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Database connection
    // ──────────────────────────────────────────────────────────────────────────

    protected function getDbConnection(): \mysqli
    {
        return \App\Helpers\Database::getInstance()->getConnection();
    }

    // ──────────────────────────────────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────────────────────────────────

    protected function isApiRequest(): bool
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        $httpAccept = $_SERVER['HTTP_ACCEPT'] ?? '';
        return (strpos($requestUri, '/api/') === 0)
            || (strpos($httpAccept, 'application/json') !== false);
    }
}