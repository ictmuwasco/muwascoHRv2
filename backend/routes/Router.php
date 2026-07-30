<?php

declare(strict_types=1);

namespace App;


class Router
{
    private array $routes;
    private string $controllerNamespace = 'App\\Controllers\\';

    public function __construct()
    {
        $this->routes = require __DIR__ . '/api.php';
    }

    /**
     * Dispatch the current request to the appropriate handler.
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = \App\Core\Request::normalizeUri();
        
        // Handle CORS preflight
        if ($method === 'OPTIONS') {
            $this->handleCors();
            return;
        }

        // Find matching route
        $route = $this->matchRoute($method, $uri);
        
        if ($route === null) {
            http_response_code(404);
            echo json_encode(['error' => 'Route not found']);
            return;
        }

        // Check authentication requirement
        if ($route['auth'] ?? true) {
            $jwt = \App\Helpers\JWT::getInstance();
            $user = $jwt->getAuthenticatedUser();
            
            if (!$user && !$this->isSessionAuthenticated()) {
                http_response_code(401);
                echo json_encode(['error' => 'Authentication required']);
                return;
            }
        }

        // Resolve controller and method
        $controllerName = $this->controllerNamespace . $route['controller'];
        $methodName = $route['method'] . 'Action';
        
        if (!class_exists($controllerName)) {
            http_response_code(500);
            echo json_encode(['error' => "Controller {$route['controller']} not found"]);
            return;
        }

        $controller = new $controllerName();
        
        if (!method_exists($controller, $methodName)) {
            http_response_code(500);
            echo json_encode(['error' => "Method {$methodName} not found in {$route['controller']}"]);
            return;
        }

        // Extract route parameters
        $params = $this->extractParams($uri, $route['path']);

        // Dispatch - use array_values to avoid PHP 8+ named parameter issues
        try {
            call_user_func_array([$controller, $methodName], array_values($params));
        } catch (\Exception $e) {
            \logger()->error('Route dispatch error', [
                'uri' => $uri,
                'controller' => $route['controller'],
                'method' => $methodName,
                'error' => $e->getMessage(),
            ]);
            
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }

    /**
     * Match a request to a route definition.
     */
    private function matchRoute(string $method, string $uri): ?array
    {
        if (!isset($this->routes[$method])) {
            return null;
        }

        // Sort routes: literal routes first (fewer params), then by length for specificity
        $routes = $this->routes[$method];
        uksort($routes, function($a, $b) {
            $aParams = preg_match_all('/\{([a-zA-Z_]+)\}/', $a);
            $bParams = preg_match_all('/\{([a-zA-Z_]+)\}/', $b);
            if ($aParams !== $bParams) {
                return $aParams - $bParams;
            }
            return strlen($b) - strlen($a);
        });

        foreach ($routes as $path => $handler) {
            if ($this->matches($uri, $path)) {
                $handler['path'] = $path;
                return $handler;
            }
        }

        return null;
    }

    /**
     * Check if a URI matches a route pattern.
     */
    private function matches(string $uri, string $pattern): bool
    {
        // Convert route pattern to regex
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        
        return (bool) preg_match($regex, $uri, $matches);
    }

    /**
     * Extract parameters from a matched route.
     */
    private function extractParams(string $uri, string $pattern): array
    {
        $regex = preg_replace('/\{([a-zA-Z_]+)\}/', '(?P<$1>[^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        
        preg_match($regex, $uri, $matches);
        
        // Return only named parameters
        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }

    /**
     * Check if user is authenticated via session.
     */
    private function isSessionAuthenticated(): bool
    {
        return isset($_SESSION['user_id']) 
            && isset($_SESSION['session_valid']) 
            && $_SESSION['session_valid'] === true;
    }

    /**
     * Handle CORS preflight requests.
     */
    private function handleCors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-TOKEN');
        header('Access-Control-Max-Age: 86400');
        http_response_code(204);
    }
}