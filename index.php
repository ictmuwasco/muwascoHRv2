<?php
/**
 * HR Management System - Entry Point
 * Place: index.php (root)
 */

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Load Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

// Load bootstrap (defines env() function and other helpers)
require_once __DIR__ . '/backend/bootstrap.php';

// Load configuration (uses env() function)
require_once __DIR__ . '/backend/config/database.php';

// Load authentication helper
require_once __DIR__ . '/backend/app/Helpers/Auth.php';

// Initialize the application
$app = new App\Core\Application();

// Route the request
$app->route($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);