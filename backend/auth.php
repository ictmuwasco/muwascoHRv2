<?php

/**
 * Backward-compatible auth.php loader
 * 
 * This file is required by various controllers and views that check
 * for the existence of the hasPermission() function.
 * It loads the actual Auth helper which defines the function.
 */

require_once __DIR__ . '/app/Helpers/Auth.php';