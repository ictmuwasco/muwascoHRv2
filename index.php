<?php

declare(strict_types=1);

/**
 * SPA fallback for Apache deployments where the DocumentRoot is the
 * repository root.
 *
 * WHY THIS FILE EXISTS
 *   The root .htaccess sends every non-file, non-directory request to
 *   index.php:
 *
 *       RewriteCond %{REQUEST_FILENAME} !-f
 *       RewriteCond %{REQUEST_FILENAME} !-d
 *       RewriteRule ^(.*)$ index.php [QSA,L]
 *
 *   But no index.php existed at the repository root, so every client-side
 *   route (/login, /employees, /attendance, ...) produced a 404/500 and the
 *   application was unusable on any Apache host pointed at the repo root.
 *   backend/public/index.php filled that role only when the DocumentRoot was
 *   set to backend/public, which does not expose api.php and therefore breaks
 *   the JSON API.
 *
 * WHAT IT DOES
 *   Serves the built SPA shell (index.html). It is intentionally NOT a PHP
 *   framework front controller: there is no server-side routing to perform,
 *   because the API is reached through /api/* -> api.php above.
 *
 * The shell is emitted with no-cache so a deploy is picked up immediately;
 * the fingerprinted bundles under assets/ keep their own one-year immutable
 * policy in backend/public/assets/.htaccess.
 */

@ini_set('display_errors', '0');

$shell = __DIR__ . '/index.html';
if (!is_file($shell)) {
    // The frontend has not been built yet (fresh clone, or a failed build).
    // Say so explicitly rather than returning a blank page, and never leak a
    // filesystem path.
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    header('Retry-After: 60');
    echo "The application has not been built yet. Run 'npm ci && npm run build' in frontend/.\n";
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, must-revalidate');
readfile($shell);
