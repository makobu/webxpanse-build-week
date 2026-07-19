<?php
/**
 * Webhooks Module
 * 
 * Handles webhook management and execution
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Auth;
use CRM\Modules\DemoModeManager;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;

class Webhooks
{
    /**
     * Available webhook events
     */
    private array $availableEvents = [
        'contact.created',
        'contact.updated',
        'contact.deleted',
        'deal.created',
        'deal.updated',
        'deal.won',
        'deal.lost',
        'task.created',
        'task.completed',
        'task.overdue',
        'event.created',
        'event.updated',
        'email.sent',
        'email.opened',
        'email.clicked',
        'form.submitted',
        'workflow.triggered'
    ];
    
    /**
     * Create a new webhook
     */
    public function create(array $data): int
    {
        if (empty($data['name']) || empty($data['url'])) {
            throw new \Exception("Name and URL are required");
        }
        
        $userId = Auth::userId();
        if (!$userId) {
            throw new \Exception("User must be logged in");
        }
        $workspaceId = $this->requireWorkspaceId();
        
        $name = Security::sanitizeInput($data['name'], 'string');
        $url = $this->validateWebhookUrl((string) $data['url']);
        
        $method = strtoupper($data['method'] ?? 'POST');
        if (!in_array($method, ['GET', 'POST', 'PUT', 'PATCH'])) {
            $method = 'POST';
        }
        
        $secret = !empty($data['secret']) ? Security::sanitizeInput($data['secret'], 'string') : null;
        $events = !empty($data['events']) && is_array($data['events']) ? json_encode($data['events']) : json_encode([]);
        $headers = !empty($data['headers']) && is_array($data['headers']) ? json_encode($data['headers']) : null;
        $isActive = isset($data['is_active']) && $data['is_active'] ? 1 : 0;
        
        Database::execute(
            "INSERT INTO webhooks (workspace_id, user_id, name, url, method, secret, events, headers, is_active) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $userId, $name, $url, $method, $secret, $events, $headers, $isActive]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get all webhooks for current user
     */
    public function getUserWebhooks(): array
    {
        $userId = Auth::userId();
        if (!$userId) {
            return [];
        }
        $workspaceId = $this->requireWorkspaceId();
        
        try {
            $webhooks = Database::query(
                "SELECT w.*, 
                        (SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = w.id) as total_calls,
                        (SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = w.id AND response_status >= 200 AND response_status < 300) as success_calls,
                        (SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = w.id AND (response_status >= 400 OR error_message IS NOT NULL)) as failed_calls
                 FROM webhooks w 
                 WHERE w.workspace_id = ? 
                 ORDER BY w.created_at DESC",
                [$workspaceId]
            );
        } catch (\PDOException $e) {
            // If table doesn't exist, try to create it and retry
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                Database::reset();
                Database::init(require __DIR__ . '/../config/database.php');
                $this->ensureTablesExist();
                // Retry the query
                $webhooks = Database::query(
                    "SELECT w.*, 
                            (SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = w.id) as total_calls,
                            (SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = w.id AND response_status >= 200 AND response_status < 300) as success_calls,
                            (SELECT COUNT(*) FROM webhook_logs WHERE webhook_id = w.id AND (response_status >= 400 OR error_message IS NOT NULL)) as failed_calls
                     FROM webhooks w 
                     WHERE w.workspace_id = ? 
                     ORDER BY w.created_at DESC",
                    [$workspaceId]
                );
            } else {
                throw $e;
            }
        }
        
        foreach ($webhooks as &$webhook) {
            $webhook['events'] = !empty($webhook['events']) ? json_decode($webhook['events'], true) : [];
            $webhook['headers'] = !empty($webhook['headers']) ? json_decode($webhook['headers'], true) : [];
        }
        
        return $webhooks;
    }
    
    /**
     * Get webhook by ID (must belong to current user)
     */
    public function getById(int $id): ?array
    {
        $userId = Auth::userId();
        if (!$userId) {
            return null;
        }
        $workspaceId = $this->requireWorkspaceId();
        
        $webhook = Database::queryOne(
            "SELECT * FROM webhooks WHERE id = ? AND workspace_id = ?",
            [$id, $workspaceId]
        );
        
        if ($webhook) {
            $webhook['events'] = !empty($webhook['events']) ? json_decode($webhook['events'], true) : [];
            $webhook['headers'] = !empty($webhook['headers']) ? json_decode($webhook['headers'], true) : [];
        }
        
        return $webhook;
    }
    
    /**
     * Update webhook
     */
    public function update(int $id, array $data): bool
    {
        $userId = Auth::userId();
        if (!$userId) {
            return false;
        }
        
        // Verify webhook belongs to user
        $webhook = $this->getById($id);
        if (!$webhook) {
            return false;
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['name'])) {
            $updates[] = "name = ?";
            $params[] = Security::sanitizeInput($data['name'], 'string');
        }
        
        if (isset($data['url'])) {
            $url = $this->validateWebhookUrl((string) $data['url']);
            $updates[] = "url = ?";
            $params[] = $url;
        }
        
        if (isset($data['method'])) {
            $method = strtoupper($data['method']);
            if (in_array($method, ['GET', 'POST', 'PUT', 'PATCH'])) {
                $updates[] = "method = ?";
                $params[] = $method;
            }
        }
        
        if (array_key_exists('secret', $data)) {
            $updates[] = "secret = ?";
            $params[] = $data['secret'] ? Security::sanitizeInput($data['secret'], 'string') : null;
        }
        
        if (isset($data['events']) && is_array($data['events'])) {
            $updates[] = "events = ?";
            $params[] = json_encode($data['events']);
        }
        
        if (isset($data['headers']) && is_array($data['headers'])) {
            $updates[] = "headers = ?";
            $params[] = json_encode($data['headers']);
        }
        
        if (isset($data['is_active'])) {
            $updates[] = "is_active = ?";
            $params[] = $data['is_active'] ? 1 : 0;
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $id;
        $params[] = $webhook['workspace_id'];
        
        Database::execute(
            "UPDATE webhooks SET " . implode(', ', $updates) . " WHERE id = ? AND workspace_id = ?",
            $params
        );
        
        return true;
    }
    
    /**
     * Delete webhook
     */
    public function delete(int $id): bool
    {
        $userId = Auth::userId();
        if (!$userId) {
            return false;
        }
        $workspaceId = $this->requireWorkspaceId();
        
        Database::execute(
            "DELETE FROM webhooks WHERE id = ? AND workspace_id = ?",
            [$id, $workspaceId]
        );
        
        return true;
    }
    
    /**
     * Get available events
     */
    public function getAvailableEvents(): array
    {
        return $this->availableEvents;
    }
    
    /**
     * Trigger webhook for an event
     */
    public function trigger(string $eventType, array $payload): void
    {
        $workspaceId = (int) ($payload['workspace_id'] ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        $where = ["is_active = 1"];
        $params = [];
        if ($workspaceId > 0) {
            $where[] = "workspace_id = ?";
            $params[] = $workspaceId;
        }

        $webhooks = Database::query(
            "SELECT * FROM webhooks WHERE " . implode(' AND ', $where),
            $params
        );
        
        foreach ($webhooks as $webhook) {
            $events = !empty($webhook['events']) ? json_decode($webhook['events'], true) : [];
            
            // Check if webhook subscribes to this event
            if (in_array($eventType, $events)) {
                // Outbound delivery is synchronous in this stabilization pass.
                try {
                    $this->executeWebhook($webhook, $eventType, $payload);
                } catch (\Exception $e) {
                    // Log error but don't fail the main operation
                    error_log("Webhook execution error: " . $e->getMessage());
                }
            }
        }
    }
    
    /**
     * Execute a webhook
     */
    private function executeWebhook(array $webhook, string $eventType, array $payload): array
    {
        $demoSimulation = false;
        try {
            $demoMode = new DemoModeManager();
            $demoSimulation = $demoMode->isEnabled() && $demoMode->isSimulationOnly();
        } catch (\Throwable $demoError) {
            $demoSimulation = false;
        }

        if ($demoSimulation) {
            $webhookPayload = [
                'event' => $eventType,
                'timestamp' => date('c'),
                'data' => $payload,
                'simulated' => true,
                'reason' => 'Demo mode simulation',
            ];
            $logId = $this->logWebhook($webhook['id'], $eventType, $webhookPayload, 202, 'Simulated webhook delivery', null);
            return [
                'success' => true,
                'event_type' => $eventType,
                'response_status' => 202,
                'response_preview' => 'Simulated webhook delivery',
                'error_message' => null,
                'log_id' => $logId,
                'synchronous' => true,
            ];
        }

        $url = $webhook['url'];
        $method = $webhook['method'] ?? 'POST';
        $headers = !empty($webhook['headers']) ? json_decode($webhook['headers'], true) : [];
        $secret = $webhook['secret'] ?? null;
        
        // Prepare payload
        $webhookPayload = [
            'event' => $eventType,
            'timestamp' => date('c'),
            'data' => $payload
        ];
        
        // Add signature if secret is provided
        if ($secret) {
            $signature = hash_hmac('sha256', json_encode($webhookPayload), $secret);
            $webhookPayload['signature'] = $signature;
        }
        
        // Set default headers
        $defaultHeaders = [
            'Content-Type: application/json',
            'User-Agent: CRM-Webhook/1.0'
        ];
        
        if ($secret) {
            $defaultHeaders[] = 'X-Webhook-Signature: ' . ($webhookPayload['signature'] ?? '');
        }
        
        $allHeaders = array_merge($defaultHeaders, $this->formatHeaders($headers));
        
        // Execute webhook
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        
        // Only send payload for POST, PUT, PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($webhookPayload));
        } elseif ($method === 'GET' && !empty($webhookPayload)) {
            // For GET, append as query string
            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query(['payload' => json_encode($webhookPayload)]);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $allHeaders);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $responseBody = is_string($response) ? $response : null;
        $errorMessage = $error !== '' ? $error : null;
        
        // Log webhook execution
        $logId = $this->logWebhook($webhook['id'], $eventType, $webhookPayload, $httpCode ?: null, $responseBody, $errorMessage);
        $success = $errorMessage === null && $httpCode >= 200 && $httpCode < 300;

        return [
            'success' => $success,
            'event_type' => $eventType,
            'response_status' => $httpCode ?: null,
            'response_preview' => $responseBody !== null ? mb_substr($responseBody, 0, 1000) : null,
            'error_message' => $errorMessage,
            'log_id' => $logId,
            'synchronous' => true,
        ];
    }
    
    /**
     * Format headers array for cURL
     */
    private function formatHeaders(array $headers): array
    {
        $formatted = [];
        foreach ($headers as $key => $value) {
            $formatted[] = "$key: $value";
        }
        return $formatted;
    }
    
    /**
     * Log webhook execution
     */
    private function logWebhook(int $webhookId, string $eventType, array $payload, ?int $responseStatus, ?string $responseBody, ?string $error): int
    {
        Database::execute(
            "INSERT INTO webhook_logs (webhook_id, event_type, payload, response_status, response_body, error_message) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $webhookId,
                $eventType,
                json_encode($payload),
                $responseStatus,
                $responseBody,
                $error
            ]
        );

        return (int) Database::lastInsertId();
    }
    
    /**
     * Get webhook logs
     */
    public function getLogs(int $webhookId, int $limit = 50, int $offset = 0): array
    {
        $userId = Auth::userId();
        if (!$userId) {
            return [];
        }
        
        // Verify webhook belongs to user
        $webhook = $this->getById($webhookId);
        if (!$webhook) {
            return [];
        }
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        
        $logs = Database::query(
            "SELECT * FROM webhook_logs 
             WHERE webhook_id = ? 
             ORDER BY executed_at DESC 
             LIMIT {$limit} OFFSET {$offset}",
            [$webhookId]
        );
        
        foreach ($logs as &$log) {
            $log['payload'] = !empty($log['payload']) ? json_decode($log['payload'], true) : [];
        }
        
        return $logs;
    }
    
    /**
     * Test webhook (manual trigger)
     */
    public function test(int $id): array
    {
        $webhook = $this->getById($id);
        if (!$webhook) {
            throw new \Exception("Webhook not found");
        }
        
        $testPayload = [
            'test' => true,
            'message' => 'This is a test webhook from CRM'
        ];
        
        try {
            $result = $this->executeWebhook($webhook, 'webhook.test', $testPayload);
            $result['message'] = $result['success']
                ? 'Webhook test delivered successfully.'
                : 'Webhook test sent, but the target did not return a successful response.';
            return $result;
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Ensure webhooks tables exist
     */
    private function ensureTablesExist(): void
    {
        $pdo = Database::getInstance();

        // Create/repair webhooks table first.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS webhooks (
                id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                name VARCHAR(255) NOT NULL,
                url VARCHAR(500) NOT NULL,
                method VARCHAR(10) DEFAULT 'POST',
                secret VARCHAR(255) DEFAULT NULL,
                events JSON NOT NULL,
                headers JSON DEFAULT NULL,
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_user_id (user_id),
                INDEX idx_is_active (is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // Normalize legacy/broken schema where id is not PK or auto_increment.
        $hasPrimaryKey = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'webhooks'
               AND CONSTRAINT_TYPE = 'PRIMARY KEY'"
        )['c'] ?? 0);

        if ($hasPrimaryKey === 0) {
            $pdo->exec("ALTER TABLE webhooks MODIFY id INT NOT NULL");
            $pdo->exec("ALTER TABLE webhooks ADD PRIMARY KEY (id)");
        }

        $idExtra = (string) (Database::queryOne(
            "SELECT EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'webhooks'
               AND COLUMN_NAME = 'id'"
        )['EXTRA'] ?? '');

        if (stripos($idExtra, 'auto_increment') === false) {
            $pdo->exec("ALTER TABLE webhooks MODIFY id INT NOT NULL AUTO_INCREMENT");
        }

        // Ensure expected indexes exist.
        try {
            $pdo->exec("ALTER TABLE webhooks ADD INDEX idx_user_id (user_id)");
        } catch (\Throwable $e) {
            // Ignore if index already exists.
        }
        try {
            $pdo->exec("ALTER TABLE webhooks ADD INDEX idx_is_active (is_active)");
        } catch (\Throwable $e) {
            // Ignore if index already exists.
        }

        // Ensure FK from webhooks.user_id -> users.id.
        $hasUserFk = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'webhooks'
               AND COLUMN_NAME = 'user_id'
               AND REFERENCED_TABLE_NAME = 'users'
               AND REFERENCED_COLUMN_NAME = 'id'"
        )['c'] ?? 0);

        if ($hasUserFk === 0) {
            try {
                $pdo->exec("ALTER TABLE webhooks ADD CONSTRAINT fk_webhooks_user_id FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE");
            } catch (\Throwable $e) {
                error_log('Webhooks ensureTablesExist: unable to add webhooks.user_id FK - ' . $e->getMessage());
            }
        }

        // Create webhook logs table (without FK first), then add FK in a guarded step.
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS webhook_logs (
                id INT NOT NULL AUTO_INCREMENT,
                webhook_id INT NOT NULL,
                event_type VARCHAR(100) NOT NULL,
                payload JSON DEFAULT NULL,
                response_status INT DEFAULT NULL,
                response_body TEXT DEFAULT NULL,
                error_message TEXT DEFAULT NULL,
                executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_webhook_id (webhook_id),
                INDEX idx_event_type (event_type),
                INDEX idx_executed_at (executed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $hasWebhookLogsFk = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'webhook_logs'
               AND COLUMN_NAME = 'webhook_id'
               AND REFERENCED_TABLE_NAME = 'webhooks'
               AND REFERENCED_COLUMN_NAME = 'id'"
        )['c'] ?? 0);

        if ($hasWebhookLogsFk === 0) {
            try {
                $pdo->exec("ALTER TABLE webhook_logs ADD CONSTRAINT fk_webhook_logs_webhook_id FOREIGN KEY (webhook_id) REFERENCES webhooks(id) ON DELETE CASCADE");
            } catch (\Throwable $e) {
                error_log('Webhooks ensureTablesExist: unable to add webhook_logs.webhook_id FK - ' . $e->getMessage());
            }
        }
    }

    private function requireWorkspaceId(): int
    {
        return (new WorkspaceScopeService())->requireActiveWorkspaceId();
    }

    private function validateWebhookUrl(string $url): string
    {
        $url = trim($url);
        $validated = filter_var($url, FILTER_VALIDATE_URL);
        if (!$validated) {
            throw new \Exception("Invalid URL format");
        }

        $scheme = strtolower((string) parse_url($validated, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \Exception("Webhook URL must use http or https");
        }

        return $validated;
    }
}
