<?php

declare(strict_types=1);

/**
 * API Entry Point
 *
 * The .htaccess rewrite routes any /api/* request to this file. It loads
 * the shared bootstrap and dispatches via the JSON router.
 */

require_once __DIR__ . '/backend/bootstrap.php';

// API router is in App\Router (separate from the SPA Application class).
require_once __DIR__ . '/backend/routes/Router.php';

$router = new App\Router();
$router->dispatch();
