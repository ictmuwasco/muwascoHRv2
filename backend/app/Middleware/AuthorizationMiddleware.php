<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Helpers\Auth;
use App\Helpers\Session;
use App\Helpers\AuthorizationService;

/**
 * Authorization Middleware
 *
 * Ensures the user has the required permissions before accessing the route.
 */
class AuthorizationMiddleware extends BaseMiddleware
{
    private Auth $auth;
    private Session $session;
    private AuthorizationService $authorizationService;

    public function __construct()
    {
        $this->auth = Auth::getInstance();
        $this->session = Session::getInstance();
        $this->authorizationService = new AuthorizationService();
    }

    /**
     * Handle the incoming request.
     */
    public function handle(callable $next): mixed
    {
        $requiredPermission = $this->getRequiredPermission();
        
        if (!$requiredPermission) {
            return $next();
        }

        $userId = $this->session->get('user_id');
        $userRole = $this->session->get('user_role');

        if (!$userId || !$userRole) {
            if ($this->isApiRequest()) {
                return $this->json(['error' => 'Unauthorized'], 403);
            }
            $this->redirect('login');
        }

        // Check if user has the required permission
        if (!$this->authorizationService->hasPermission($userRole, $requiredPermission['resource'], $requiredPermission['action'])) {
            if ($this->isApiRequest()) {
                return $this->json(['error' => 'Forbidden - Insufficient permissions'], 403);
            }

            $_SESSION['flash_error'] = 'You do not have permission to access this resource.';
            $this->redirect('dashboard');
        }

        return $next();
    }

    /**
     * Get the required permission from the request.
     */
    private function getRequiredPermission(): ?array
    {
        // Get permission from route attributes or query parameters
        $resource = $_GET['permission_resource'] ?? $_POST['permission_resource'] ?? null;
        $action = $_GET['permission_action'] ?? $_POST['permission_action'] ?? 'view';

        if (!$resource) {
            return null;
        }

        return [
            'resource' => $resource,
            'action' => $action,
        ];
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