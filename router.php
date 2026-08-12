<?php
/**
 * Router script for PHP's built-in development server.
 *
 * The PHP built-in server ignores .htaccess files, so this router
 * replicates the routing logic from .htaccess:
 *   - Serve existing files directly (static assets)
 *   - Route /api/* requests to api.php
 *   - Route all other requests to index.php (React SPA)
 *
 * Usage:
 *   php -S localhost:8000 -t . router.php
 */

declare(strict_types=1);

$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?: '/';

// Remove /hrdemo prefix if present (subdirectory installation)
if (strpos($requestPath, '/hrdemo') === 0) {
    $requestPath = substr($requestPath, 7);
    if ($requestPath === '') {
        $requestPath = '/';
    }
}

// 1. If the requested file actually exists, serve it directly (static files)
$filePath = __DIR__ . $requestPath;
if ($requestPath !== '/' && is_file($filePath)) {
    // Determine the content type based on file extension
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $contentTypes = [
        'js'    => 'application/javascript',
        'mjs'   => 'application/javascript',
        'css'   => 'text/css',
        'html'  => 'text/html',
        'htm'   => 'text/html',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'webp'  => 'image/webp',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'json'  => 'application/json',
        'txt'   => 'text/plain',
        'pdf'   => 'application/pdf',
        'xml'   => 'application/xml',
    ];

    if (isset($contentTypes[$ext])) {
        header('Content-Type: ' . $contentTypes[$ext]);
    }

    // Only return false for the PHP built-in server to serve static files
    // natively when the file exists and is not a PHP file.
    if ($ext !== 'php') {
        return false;
    }

    // For PHP files, execute them directly
    require $filePath;
    return true;
}

// 2. API routes go to api.php
if (strpos($requestPath, '/api') === 0) {
    require __DIR__ . '/api.php';
    return true;
}

// 3. Pass the Authorization header (needed for JWT token authentication)
//    PHP built-in server does not always pass this header automatically
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    // Already available
} elseif (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $_SERVER['HTTP_AUTHORIZATION'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
} elseif (function_exists('getallheaders')) {
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $_SERVER['HTTP_AUTHORIZATION'] = $headers['Authorization'];
    }
}

// 4. All other routes go to index.php (React SPA routing)
require __DIR__ . '/index.php';
return true;