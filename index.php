<?php
/**
 * HR Management System - Web Entry Point
 * Place: index.php (root)
 *
 * All bootstrapping (env, config, error handling, session, helpers) lives in
 * backend/bootstrap.php. This file just loads the bootstrap and dispatches
 * the request through the SPA router.
 */

require_once __DIR__ . '/backend/bootstrap.php';

$app = new App\Core\Application();
$app->route($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
