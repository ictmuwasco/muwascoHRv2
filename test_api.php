<?php

declare(strict_types=1);

// Simple API endpoint tester
require_once __DIR__ . '/backend/bootstrap.php';

$endpoints = [
    '/api/dashboard/stats',
    '/api/dashboard/charts/attendance',
    '/api/dashboard/charts/departments',
    '/api/dashboard/charts/leave',
    '/api/attendance/dashboard',
    '/api/notifications',
];

// Mock session for testing
$_SESSION['user_id'] = 1;
$_SESSION['user_role'] = 'employee';

echo "Testing API Endpoints\n";
echo str_repeat('=', 50) . "\n\n";

foreach ($endpoints as $endpoint) {
    echo "Testing: $endpoint\n";
    
    // Set up request globals
    $_SERVER['REQUEST_URI'] = $endpoint;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    
    // Capture output
    ob_start();
    
    try {
        // Include the router
        require_once __DIR__ . '/api.php';
    } catch (\Throwable $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    
    $output = ob_get_clean();
    $httpCode = http_response_code();
    
    echo "HTTP Code: $httpCode\n";
    echo "Response: " . substr($output, 0, 200) . "\n";
    echo str_repeat('-', 50) . "\n\n";
}

echo "Testing complete!\n";