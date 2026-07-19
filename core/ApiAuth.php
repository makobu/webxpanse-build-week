<?php
/**
 * API Authentication Middleware
 * 
 * Handles API key authentication for API endpoints
 */

namespace CRM;

use CRM\Modules\ApiKeys;
use CRM\Services\WorkspaceContext;

class ApiAuth
{
    /**
     * Authenticate API request using API key
     * 
     * @return array|null User data if authenticated, null otherwise
     */
    public static function authenticate(): ?array
    {
        // Get API key from header
        $apiKey = self::getApiKeyFromRequest();
        
        if (!$apiKey) {
            return null;
        }
        
        $apiKeysModule = new ApiKeys();
        $authResult = $apiKeysModule->authenticate($apiKey);
        
        if ($authResult) {
            $workspaceId = (int) ($authResult['workspace_id'] ?? 0);
            if ($workspaceId > 0) {
                WorkspaceContext::activateRuntimeWorkspace($workspaceId, (int) ($authResult['user_id'] ?? 0));
            }

            // Check rate limit
            if (!$apiKeysModule->checkRateLimit($authResult['api_key_id'])) {
                http_response_code(429);
                header('Content-Type: application/json');
                echo json_encode([
                    'error' => 'Rate limit exceeded',
                    'message' => 'Too many requests. Please try again later.'
                ]);
                exit;
            }
        }
        
        return $authResult;
    }
    
    /**
     * Require API authentication
     * 
     * @return array User data
     * @throws \Exception If authentication fails
     */
    public static function requireAuth(): array
    {
        $user = self::authenticate();
        
        if (!$user) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'Unauthorized',
                'message' => 'Valid API key is required'
            ]);
            exit;
        }
        
        return $user;
    }
    
    /**
     * Get API key from request
     * Checks Authorization header and X-API-Key header
     */
    private static function getApiKeyFromRequest(): ?string
    {
        // Check Authorization header (Bearer token)
        $headers = getallheaders();
        if (isset($headers['Authorization'])) {
            $authHeader = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
                return $matches[1];
            }
        }
        
        // Check X-API-Key header
        if (isset($headers['X-API-Key'])) {
            return $headers['X-API-Key'];
        }
        
        // Check query parameter (less secure, but sometimes needed)
        if (isset($_GET['api_key'])) {
            return $_GET['api_key'];
        }
        
        return null;
    }
    
    /**
     * Log API request
     */
    public static function logRequest(int $apiKeyId, string $endpoint, string $method, ?int $responseStatus = null, ?int $responseTimeMs = null): void
    {
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $apiKeysModule = new ApiKeys();
        $apiKeysModule->logUsage($apiKeyId, $endpoint, $method, $ipAddress, $userAgent, $responseStatus, $responseTimeMs);
    }
}
