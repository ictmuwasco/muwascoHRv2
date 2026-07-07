<?php

declare(strict_types=1);

// Load bootstrap
require_once __DIR__ . '/backend/bootstrap.php';

// Load the router class explicitly because it lives outside the main app namespace tree
require_once __DIR__ . '/backend/routes/Router.php';

// Load the router
$router = new App\Router();

// Dispatch the request
$router->dispatch();