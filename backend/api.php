<?php

declare(strict_types=1);

// Load bootstrap
require_once __DIR__ . '/bootstrap.php';

// Load the router
$router = new App\Router();

// Dispatch the request
$router->dispatch();