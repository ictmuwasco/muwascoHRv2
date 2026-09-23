<?php
/**
 * Front-controller stub (was a leftover Laravel skeleton that does not belong
 * to this non-Laravel codebase — it referenced Illuminate\Foundation\Application
 * and a non-existent backend/bootstrap/app.php, which made any direct HTTP
 * hit produce a PHP Warning leaking the absolute filesystem path).
 *
 * The application has exactly two real entry points:
 *   - SPA shell : backend/public/index.html  (served via DirectoryIndex)
 *   - JSON API  : docroot-root api.php        (routes /api/*)
 *
 * This stub keeps a direct hit on index.php from ever leaking paths: it
 * forces error display off and serves the SPA shell, identical to what the
 * browser receives from index.html. (The backend/public/.htaccess fallback
 * RewriteRule ^ index.php would otherwise land here and 500.)
 */
@ini_set('display_errors', '0');

$shell = __DIR__ . '/index.html';
if (is_file($shell)) {
    header('Content-Type: text/html; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    readfile($shell);
    exit;
}

http_response_code(404);
exit;

