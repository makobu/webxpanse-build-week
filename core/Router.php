<?php
/**
 * Simple Router
 * 
 * Basic routing system for the application
 */

namespace CRM;

class Router
{
    private static array $routes = [];
    private static array $middleware = [];
    
    /**
     * Add GET route
     */
    public static function get(string $path, callable $handler, array $middleware = []): void
    {
        self::addRoute('GET', $path, $handler, $middleware);
    }
    
    /**
     * Add POST route
     */
    public static function post(string $path, callable $handler, array $middleware = []): void
    {
        self::addRoute('POST', $path, $handler, $middleware);
    }
    
    /**
     * Add route
     */
    private static function addRoute(string $method, string $path, callable $handler, array $middleware): void
    {
        self::$routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler,
            'middleware' => $middleware
        ];
    }
    
    /**
     * Dispatch request
     */
    public static function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        
        foreach (self::$routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            
            $pattern = self::convertPathToRegex($route['path']);
            
            if (preg_match($pattern, $path, $matches)) {
                // Execute middleware
                foreach ($route['middleware'] as $mw) {
                    if (is_callable($mw)) {
                        $mw();
                    }
                }
                
                // Extract parameters
                array_shift($matches);
                call_user_func_array($route['handler'], $matches);
                return;
            }
        }
        
        // 404 Not Found
        http_response_code(404);
        echo "404 Not Found";
    }
    
    /**
     * Convert path pattern to regex
     */
    private static function convertPathToRegex(string $path): string
    {
        $pattern = preg_replace('/\{(\w+)\}/', '([^/]+)', $path);
        return '#^' . $pattern . '$#';
    }
}
