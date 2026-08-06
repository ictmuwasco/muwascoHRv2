<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Auth;
use App\Helpers\Session;

/**
 * Authentication Middleware
 *
 * Ensures the user is authenticated before accessing the route.
 */
class AuthenticationMiddleware extends BaseMiddleware
{
    private Auth $auth;
    private Session $session;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->session = Session::getInstance();
    }

    /**
     * Handle the incoming request.
     */
    public function handle(callable $next): mixed
    {
        // Check if user is authenticated (session or JWT token)
        if (!$this->auth->check()) {
            // For API requests, return JSON
            if ($this->isApiRequest()) {
                return $this->json(['error' => 'Unauthenticated'], 401);
            }

            // For web requests, redirect to login
            $this->redirect('login');
        }

        // Update last activity
        $this->session->set('last_activity', time());

        // Continue to the next middleware/controller
        return $next();
    }

    /**
     * Check if the current request is an API request.
     */
    private function isApiRequest(): bool
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';

        return str_starts_with($uri, '/api') || 
               str_contains($accept, 'application/json') ||
               isset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }
}