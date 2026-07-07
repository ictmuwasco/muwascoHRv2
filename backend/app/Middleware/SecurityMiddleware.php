<?php

declare(strict_types=1);

namespace App\Middleware;

/**
 * Security Middleware - Implements OWASP Top 10 security recommendations.
 * 
 * Handles security headers, CSRF protection, input validation,
 * SQL injection prevention, XSS protection, and rate limiting.
 */
class SecurityMiddleware
{
    /**
     * Apply all security headers to the response.
     */
    public static function applySecurityHeaders(): void
    {
        // Prevent MIME-type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Enable XSS filter in older browsers
        header('X-XSS-Protection: 1; mode=block');
        
        // Control frame embedding (Clickjacking protection)
        header('X-Frame-Options: DENY');
        
        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Content Security Policy
        $csp = "default-src 'self'; "
             . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
             . "style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; "
             . "img-src 'self' data:; "
             . "font-src 'self' https://cdnjs.cloudflare.com https://cdn.jsdelivr.net; "
             . "connect-src 'self'; "
             . "frame-ancestors 'none';";
        header("Content-Security-Policy: {$csp}");
        
        // HTTP Strict Transport Security (only on HTTPS)
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        
        // Permissions Policy
        header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');
    }

    /**
     * Validate and sanitize input data.
     */
    public static function sanitizeInput(array $data): array
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitizeInput($value);
            } elseif (is_string($value)) {
                // Remove null bytes
                $value = str_replace("\0", '', $value);
                
                // Strip HTML tags (configurable)
                $value = strip_tags($value);
                
                // Trim whitespace
                $value = trim($value);
                
                $sanitized[$key] = $value;
            } else {
                $sanitized[$key] = $value;
            }
        }
        
        return $sanitized;
    }

    /**
     * Validate CSRF token from request.
     */
    public static function validateCsrfToken(): bool
    {
        $token = $_POST['csrf_token'] 
            ?? $_SERVER['HTTP_X_CSRF_TOKEN'] 
            ?? $_SERVER['HTTP_X_XSRF_TOKEN'] 
            ?? '';
        
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate a CSRF token if not exists.
     */
    public static function ensureCsrfToken(): void
    {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    /**
     * Check rate limit for an action.
     */
    public static function checkRateLimit(string $action, int $maxAttempts = 5, int $windowSeconds = 900): bool
    {
        $key = 'rate_limit_' . $action . '_' . ($_SERVER['REMOTE_ADDR'] ?? '');
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'count' => 1,
                'first_attempt' => time(),
            ];
            return true;
        }
        
        $data = $_SESSION[$key];
        
        // Reset if window has expired
        if (time() - $data['first_attempt'] > $windowSeconds) {
            $_SESSION[$key] = [
                'count' => 1,
                'first_attempt' => time(),
            ];
            return true;
        }
        
        // Check if max attempts exceeded
        if ($data['count'] >= $maxAttempts) {
            \logger()->warning('Rate limit exceeded', [
                'action' => $action,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                'count' => $data['count'],
            ]);
            return false;
        }
        
        $_SESSION[$key]['count']++;
        return true;
    }

    /**
     * Validate file upload for security.
     */
    public static function validateFileUpload(array $file, array $allowedTypes = []): array
    {
        $errors = [];
        
        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'File upload failed with error code: ' . $file['error'];
            return ['valid' => false, 'errors' => $errors];
        }
        
        // Check file size (default 10MB)
        $maxSize = (int) \env('UPLOAD_MAX_SIZE', 10485760);
        if ($file['size'] > $maxSize) {
            $errors[] = 'File size exceeds maximum allowed size of ' . ($maxSize / 1048576) . 'MB';
        }
        
        // Validate file extension
        if (empty($allowedTypes)) {
            $allowedTypes = explode(',', \env('ALLOWED_FILE_TYPES', 'jpg,jpeg,png,gif,pdf,doc,docx,xls,xlsx'));
        }
        
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, $allowedTypes, true)) {
            $errors[] = 'File type "' . $extension . '" is not allowed. Allowed types: ' . implode(', ', $allowedTypes);
        }
        
        // Validate MIME type for images
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            $imageInfo = getimagesize($file['tmp_name']);
            if ($imageInfo === false) {
                $errors[] = 'Uploaded file is not a valid image.';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Output sanitization for safe HTML rendering.
     */
    public static function e(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8', false);
    }
}