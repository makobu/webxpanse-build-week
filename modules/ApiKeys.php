<?php
/**
 * API Keys Module
 * 
 * Handles API key management and authentication
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Auth;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;

class ApiKeys
{
    /**
     * Create a new API key
     */
    public function create(array $data): array
    {
        if (empty($data['name'])) {
            throw new \Exception("API key name is required");
        }
        
        $userId = Auth::userId();
        if (!$userId) {
            throw new \Exception("User must be logged in");
        }
        $workspaceId = $this->requireWorkspaceId();
        
        $name = Security::sanitizeInput($data['name'], 'string');
        $expiresAt = !empty($data['expires_at']) ? $data['expires_at'] : null;
        $permissions = !empty($data['permissions']) ? json_encode($data['permissions']) : null;
        $rateLimit = !empty($data['rate_limit_per_minute']) ? (int) $data['rate_limit_per_minute'] : 60;
        
        // Generate API key
        $apiKey = $this->generateApiKey();
        $keyHash = password_hash($apiKey, PASSWORD_DEFAULT);
        $keyPrefix = substr($apiKey, 0, 10);
        
        Database::execute(
            "INSERT INTO api_keys (workspace_id, user_id, name, key_hash, key_prefix, expires_at, permissions, rate_limit_per_minute) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $userId, $name, $keyHash, $keyPrefix, $expiresAt, $permissions, $rateLimit]
        );
        
        $apiKeyId = (int) Database::lastInsertId();
        
        // Return the full key (only shown once)
        return [
            'id' => $apiKeyId,
            'name' => $name,
            'api_key' => $apiKey, // Full key - only shown once
            'key_prefix' => $keyPrefix,
            'expires_at' => $expiresAt,
            'rate_limit_per_minute' => $rateLimit
        ];
    }
    
    /**
     * Get API key by ID
     */
    public function getById(int $id): ?array
    {
        $userId = Auth::userId();
        if (!$userId) {
            return null;
        }
        $workspaceId = $this->requireWorkspaceId();
        
        $key = Database::queryOne(
            "SELECT id, workspace_id, user_id, name, key_prefix, last_used_at, expires_at, is_active, 
                    permissions, rate_limit_per_minute, created_at, updated_at
             FROM api_keys 
             WHERE id = ? AND workspace_id = ?",
            [$id, $workspaceId]
        );
        
        if ($key) {
            $key['permissions'] = !empty($key['permissions']) ? json_decode($key['permissions'], true) : [];
            $key['usage_stats'] = $this->getUsageStats($id);
        }
        
        return $key;
    }
    
    /**
     * Get all API keys for current user
     */
    public function getUserKeys(): array
    {
        $userId = Auth::userId();
        if (!$userId) {
            return [];
        }
        $workspaceId = $this->requireWorkspaceId();
        
        try {
            $keys = Database::query(
                "SELECT id, workspace_id, user_id, name, key_prefix, last_used_at, expires_at, is_active, 
                        permissions, rate_limit_per_minute, created_at, updated_at
                 FROM api_keys 
                 WHERE workspace_id = ? 
                 ORDER BY created_at DESC",
                [$workspaceId]
            );
        } catch (\PDOException $e) {
            // If table doesn't exist, try to create it and retry
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                Database::reset();
                $this->ensureTablesExist();
                // Retry the query
                $keys = Database::query(
                    "SELECT id, workspace_id, user_id, name, key_prefix, last_used_at, expires_at, is_active, 
                            permissions, rate_limit_per_minute, created_at, updated_at
                     FROM api_keys 
                     WHERE workspace_id = ? 
                     ORDER BY created_at DESC",
                    [$workspaceId]
                );
            } else {
                throw $e;
            }
        }
        
        foreach ($keys as &$key) {
            $key['permissions'] = !empty($key['permissions']) ? json_decode($key['permissions'], true) : [];
            $key['usage_stats'] = $this->getUsageStats($key['id']);
        }
        
        return $keys;
    }
    
    /**
     * Update API key
     */
    public function update(int $id, array $data): bool
    {
        $userId = Auth::userId();
        if (!$userId) {
            return false;
        }
        $workspaceId = $this->requireWorkspaceId();
        
        $key = Database::queryOne(
            "SELECT id FROM api_keys WHERE id = ? AND workspace_id = ?",
            [$id, $workspaceId]
        );
        
        if (!$key) {
            throw new \Exception("API key not found");
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $params[] = Security::sanitizeInput($data['name'], 'string');
        }
        
        if (isset($data['is_active'])) {
            $updates[] = "is_active = ?";
            $params[] = (int) $data['is_active'];
        }
        
        if (isset($data['expires_at'])) {
            $updates[] = "expires_at = ?";
            $params[] = $data['expires_at'] ?: null;
        }
        
        if (isset($data['permissions'])) {
            $updates[] = "permissions = ?";
            $params[] = !empty($data['permissions']) ? json_encode($data['permissions']) : null;
        }
        
        if (isset($data['rate_limit_per_minute'])) {
            $updates[] = "rate_limit_per_minute = ?";
            $params[] = (int) $data['rate_limit_per_minute'];
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $id;
        $params[] = $workspaceId;
        
        Database::execute(
            "UPDATE api_keys SET " . implode(', ', $updates) . " WHERE id = ? AND workspace_id = ?",
            $params
        );
        
        return true;
    }
    
    /**
     * Delete API key
     */
    public function delete(int $id): bool
    {
        $userId = Auth::userId();
        if (!$userId) {
            return false;
        }
        $workspaceId = $this->requireWorkspaceId();
        
        Database::execute(
            "DELETE FROM api_keys WHERE id = ? AND workspace_id = ?",
            [$id, $workspaceId]
        );
        
        return true;
    }
    
    /**
     * Authenticate API key
     */
    public function authenticate(string $apiKey): ?array
    {
        // Get all active API keys (we need to check each one)
        $keys = Database::query(
            "SELECT * FROM api_keys WHERE is_active = 1",
            []
        );
        
        foreach ($keys as $key) {
            if (password_verify($apiKey, $key['key_hash'])) {
                // Check if expired
                if ($key['expires_at'] && strtotime($key['expires_at']) < time()) {
                    return null;
                }
                
                // Update last used
                Database::execute(
                    "UPDATE api_keys SET last_used_at = NOW() WHERE id = ?",
                    [$key['id']]
                );
                
                // Get user info
                $user = Database::queryOne(
                    "SELECT id, email, role FROM users WHERE id = ?",
                    [$key['user_id']]
                );
                
                return [
                    'api_key_id' => $key['id'],
                    'workspace_id' => (int) ($key['workspace_id'] ?? 0),
                    'user_id' => $key['user_id'],
                    'user' => $user,
                    'permissions' => !empty($key['permissions']) ? json_decode($key['permissions'], true) : [],
                    'rate_limit' => $key['rate_limit_per_minute']
                ];
            }
        }
        
        return null;
    }
    
    /**
     * Log API key usage
     */
    public function logUsage(int $apiKeyId, string $endpoint, string $method, string $ipAddress, ?string $userAgent = null, ?int $responseStatus = null, ?int $responseTimeMs = null): void
    {
        Database::execute(
            "INSERT INTO api_key_logs (api_key_id, endpoint, method, ip_address, user_agent, response_status, response_time_ms) 
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$apiKeyId, $endpoint, $method, $ipAddress, $userAgent, $responseStatus, $responseTimeMs]
        );
    }
    
    /**
     * Get usage statistics for an API key
     */
    public function getUsageStats(int $apiKeyId, int $days = 30): array
    {
        if (!$this->apiKeyIsInActiveWorkspace($apiKeyId)) {
            return [
                'total_requests' => 0,
                'successful_requests' => 0,
                'failed_requests' => 0,
                'avg_response_time_ms' => 0,
                'last_request_at' => null
            ];
        }

        $since = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        $stats = Database::queryOne(
            "SELECT 
                COUNT(*) as total_requests,
                COUNT(CASE WHEN response_status >= 200 AND response_status < 300 THEN 1 END) as successful_requests,
                COUNT(CASE WHEN response_status >= 400 THEN 1 END) as failed_requests,
                AVG(response_time_ms) as avg_response_time,
                MAX(created_at) as last_request_at
             FROM api_key_logs 
             WHERE api_key_id = ? AND created_at >= ?",
            [$apiKeyId, $since]
        );
        
        return [
            'total_requests' => (int) ($stats['total_requests'] ?? 0),
            'successful_requests' => (int) ($stats['successful_requests'] ?? 0),
            'failed_requests' => (int) ($stats['failed_requests'] ?? 0),
            'avg_response_time_ms' => $stats['avg_response_time'] ? round((float) $stats['avg_response_time'], 2) : 0,
            'last_request_at' => $stats['last_request_at'] ?? null
        ];
    }
    
    /**
     * Get usage logs for an API key
     */
    public function getUsageLogs(int $apiKeyId, int $limit = 50, int $offset = 0): array
    {
        if (!$this->apiKeyIsInActiveWorkspace($apiKeyId)) {
            return [];
        }

        $limit = max(1, $limit);
        $offset = max(0, $offset);

        return Database::query(
            "SELECT * FROM api_key_logs 
             WHERE api_key_id = ? 
             ORDER BY created_at DESC 
             LIMIT {$limit} OFFSET {$offset}",
            [$apiKeyId]
        );
    }
    
    /**
     * Check rate limit for API key
     */
    public function checkRateLimit(int $apiKeyId): bool
    {
        $key = Database::queryOne(
            "SELECT rate_limit_per_minute FROM api_keys WHERE id = ?",
            [$apiKeyId]
        );
        
        if (!$key) {
            return false;
        }
        
        $rateLimit = (int) $key['rate_limit_per_minute'];
        
        // Count requests in last minute
        $count = Database::queryOne(
            "SELECT COUNT(*) as count FROM api_key_logs 
             WHERE api_key_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
            [$apiKeyId]
        );
        
        return (int) ($count['count'] ?? 0) < $rateLimit;
    }
    
    /**
     * Generate a secure API key
     */
    private function generateApiKey(): string
    {
        // Generate a 64-character random string
        $bytes = random_bytes(32);
        return 'crm_' . bin2hex($bytes);
    }
    
    /**
     * Ensure api_keys tables exist
     */
    private function ensureTablesExist(): void
    {
        $migrationFile = __DIR__ . '/../database/migrations/042_create_api_keys_table.sql';
        if (file_exists($migrationFile)) {
            $sql = file_get_contents($migrationFile);
            $pdo = Database::getInstance();
            $pdo->exec($sql);
        }
    }

    private function requireWorkspaceId(): int
    {
        return (new WorkspaceScopeService())->requireActiveWorkspaceId();
    }

    private function apiKeyIsInActiveWorkspace(int $apiKeyId): bool
    {
        try {
            $workspaceId = $this->requireWorkspaceId();
        } catch (\Throwable $e) {
            return false;
        }

        return Database::queryOne(
            "SELECT id FROM api_keys WHERE id = ? AND workspace_id = ? LIMIT 1",
            [$apiKeyId, $workspaceId]
        ) !== null;
    }
}
