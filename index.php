<?php

declare(strict_types=1);

// Frontend Entry Point
// This file serves the React SPA

require_once __DIR__ . '/backend/bootstrap.php';

// If the request is for an API endpoint, let api.php handle it
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Remove /hrdemo prefix if present
if (strpos($requestPath, '/hrdemo') === 0) {
    $requestPath = substr($requestPath, 7);
}

// Redirect API requests to api.php
if (strpos($requestPath, '/api') === 0) {
    require_once __DIR__ . '/api.php';
    exit;
}

// Serve the React app
$frontendPath = __DIR__ . '/frontend/dist/index.html';

// If the frontend is built, serve it
if (file_exists($frontendPath)) {
    // Serve static files from frontend/dist
    if ($requestPath !== '/' && file_exists(__DIR__ . '/frontend/dist' . $requestPath)) {
        $file = __DIR__ . '/frontend/dist' . $requestPath;
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        
        // Set appropriate content type
        switch ($ext) {
            case 'js':
                header('Content-Type: application/javascript');
                break;
            case 'css':
                header('Content-Type: text/css');
                break;
            case 'png':
                header('Content-Type: image/png');
                break;
            case 'jpg':
            case 'jpeg':
                header('Content-Type: image/jpeg');
                break;
            case 'svg':
                header('Content-Type: image/svg+xml');
                break;
            case 'ico':
                header('Content-Type: image/x-icon');
                break;
        }
        
        readfile($file);
        exit;
    }
    
    // Serve index.html for all other routes (SPA routing)
    header('Content-Type: text/html');
    readfile($frontendPath);
    exit;
}

// If frontend is not built, show development mode message
if (env('APP_DEBUG', false)) {
    header('Content-Type: text/html');
    echo '<!DOCTYPE html>';
    echo '<html>';
    echo '<head>';
    echo '<title>MUWASCO HR System - Development Mode</title>';
    echo '<style>';
    echo 'body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }';
    echo '.container { background: #f5f5f5; padding: 30px; border-radius: 8px; }';
    echo 'h1 { color: #333; }';
    echo 'code { background: #e0e0e0; padding: 2px 6px; border-radius: 3px; }';
    echo '.command { background: #2d2d2d; color: #f8f8f2; padding: 15px; border-radius: 4px; margin: 10px 0; }';
    echo '</style>';
    echo '</head>';
    echo '<body>';
    echo '<div class="container">';
    echo '<h1>🚀 MUWASCO HR System</h1>';
    echo '<h2>Development Mode</h2>';
    echo '<p>The frontend has not been built yet. Please run the following commands to start the development server:</p>';
    echo '<div class="command">cd frontend && npm install && npm run dev</div>';
    echo '<p>Then access the application at: <code>http://localhost:5173</code></p>';
    echo '<hr>';
    echo '<h3>Or build for production:</h3>';
    echo '<div class="command">cd frontend && npm install && npm run build</div>';
    echo '<p>After building, refresh this page.</p>';
    echo '</div>';
    echo '</body>';
    echo '</html>';
    exit;
}

// If not in debug mode, show 404
http_response_code(404);
echo json_encode(['error' => 'Not found']);