<?php
/**
 * SMS Service (Twilio Integration)
 */

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\DemoModeManager;
use CRM\Services\WorkspaceContext;

class SMSService
{
    private string $apiUrl = 'https://api.twilio.com/2010-04-01';
    private WorkspaceSmsChannelConfigService $configService;
    private WorkspaceSmsRuntimeGateService $runtimeGate;
    private SmsComplianceService $compliance;
    private SmsRateLimitService $rateLimits;
    
    public function __construct(
        ?WorkspaceSmsChannelConfigService $configService = null,
        ?WorkspaceSmsRuntimeGateService $runtimeGate = null,
        ?SmsComplianceService $compliance = null,
        ?SmsRateLimitService $rateLimits = null
    )
    {
        $this->configService = $configService ?? new WorkspaceSmsChannelConfigService();
        $this->runtimeGate = $runtimeGate ?? new WorkspaceSmsRuntimeGateService($this->configService);
        $this->compliance = $compliance ?? new SmsComplianceService();
        $this->rateLimits = $rateLimits ?? new SmsRateLimitService();
    }
    
    /**
     * Send SMS message
     */
    public function sendSMS(string $to, string $message, array $options = []): array
    {
        $demoSimulation = false;
        try {
            $demoMode = new DemoModeManager();
            $demoSimulation = $demoMode->isEnabled() && $demoMode->isSimulationOnly();
        } catch (\Throwable $demoError) {
            $demoSimulation = false;
        }

        if ($demoSimulation) {
            return [
                'sid' => 'demo-sms-' . substr(hash('sha256', $to . $message . microtime(true)), 0, 20),
                'status' => 'sent',
                'simulated' => true,
                'to' => $this->formatPhoneNumber($to),
                'body' => $message,
            ];
        }

        $workspaceId = $this->workspaceIdFromOptions($options);
        $runtimeConfig = $this->resolveRuntimeConfig(array_merge($options, ['workspace_id' => $workspaceId]));
        if (empty($runtimeConfig['account_sid']) || empty($runtimeConfig['auth_token']) || empty($runtimeConfig['from_number'])) {
            throw new \Exception("Twilio credentials not configured");
        }

        $formattedTo = $this->compliance->assertCanSend($workspaceId, $to);
        if (empty($options['rate_reserved'])) {
            $this->rateLimits->reserve($workspaceId);
        }
        
        $url = "{$this->apiUrl}/Accounts/{$runtimeConfig['account_sid']}/Messages.json";
        
        $data = [
            'From' => (string) $runtimeConfig['from_number'],
            'To' => $formattedTo,
            'Body' => $message
        ];
        
        if (!empty($options['media_url'])) {
            $data['MediaUrl'] = $options['media_url'];
        }
        if (!empty($runtimeConfig['status_callbacks_enabled']) && !empty($runtimeConfig['status_callback_url'])) {
            $data['StatusCallback'] = (string) $runtimeConfig['status_callback_url'];
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_USERPWD => "{$runtimeConfig['account_sid']}:{$runtimeConfig['auth_token']}",
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new \RuntimeException("Twilio API error: {$error}");
        }
        
        $result = json_decode($response, true);
        
        if ($httpCode >= 400) {
            $errorMsg = $result['message'] ?? 'Unknown error';
            throw new \RuntimeException("Twilio API error: {$errorMsg}");
        }
        
        return $result;
    }
    
    /**
     * Store SMS message in database
     */
    public function storeMessage(int $contactId, string $to, string $message, array $options = []): string
    {
        $workspaceId = $this->resolveWorkspaceId($contactId, isset($options['workspace_id']) ? (int) $options['workspace_id'] : null);
        $runtimeConfig = $this->resolveRuntimeConfig(array_merge($options, ['workspace_id' => $workspaceId]));
        $formattedTo = $this->compliance->assertCanSend($workspaceId, $to);
        $idempotencyKey = $this->normalizeIdempotencyKey((string) ($options['idempotency_key'] ?? ''));
        if ($idempotencyKey !== null) {
            $existing = Database::queryOne(
                "SELECT uuid FROM sms_messages WHERE workspace_id = ? AND idempotency_key = ? LIMIT 1",
                [$workspaceId, $idempotencyKey]
            );
            if ($existing) {
                return (string) $existing['uuid'];
            }
        }
        $this->rateLimits->reserve($workspaceId);
        $uuid = $this->generateUuid();
        $userId = $options['user_id'] ?? ($_SESSION['user_id'] ?? null);
        $messageType = !empty($options['media_url']) ? 'media' : 'text';
        
        Database::execute(
            "INSERT INTO sms_messages
                (workspace_id, uuid, contact_id, user_id, to_number, from_number, message_body, message_type,
                 media_url, status, direction, provider, idempotency_key, consent_confirmed_at, consent_source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'outbound', 'twilio', ?, ?, ?)",
            [
                $workspaceId,
                $uuid,
                $contactId,
                $userId,
                $formattedTo,
                (string) $runtimeConfig['from_number'],
                $message,
                $messageType,
                $options['media_url'] ?? null,
                $idempotencyKey,
                $options['consent_confirmed_at'] ?? null,
                isset($options['consent_source']) ? substr((string) $options['consent_source'], 0, 120) : null,
            ]
        );
        
        $messageId = (int) Database::lastInsertId();
        
        // Add to queue
        $queue = new SMSQueue();
        $queue->push($messageId, $options['priority'] ?? 0, $options['scheduled_at'] ?? null);
        
        return $uuid;
    }

    private function resolveWorkspaceId(int $contactId, ?int $workspaceId = null): int
    {
        $contact = Database::queryOne(
            "SELECT workspace_id
             FROM contacts
             WHERE id = ?
             LIMIT 1",
            [$contactId]
        );

        $resolvedWorkspaceId = (int) ($contact['workspace_id'] ?? 0);
        if ($resolvedWorkspaceId <= 0) {
            throw new \RuntimeException('Unable to resolve workspace for SMS message.');
        }

        $requestedWorkspaceId = $workspaceId !== null && $workspaceId > 0
            ? $workspaceId
            : (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($requestedWorkspaceId > 0 && $requestedWorkspaceId !== $resolvedWorkspaceId) {
            throw new \RuntimeException('SMS contact does not belong to the requested workspace.');
        }

        return $resolvedWorkspaceId;
    }

    private function resolveRuntimeConfig(array $options = []): array
    {
        $workspaceId = (int) ($options['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        }

        return $this->runtimeGate->requireOutboundConfig($workspaceId, $this->allowLegacyEnvFallback($workspaceId));
    }

    private function workspaceIdFromOptions(array $options): int
    {
        $workspaceId = (int) ($options['workspace_id'] ?? (WorkspaceContext::currentWorkspaceId() ?? 0));
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required to send SMS.');
        }
        return $workspaceId;
    }

    private function allowLegacyEnvFallback(int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return true;
        }

        try {
            if ((new DefaultWorkspaceService())->isDefaultWorkspace($workspaceId)) {
                return true;
            }
        } catch (\Throwable $e) {
            // Fall through to role-based fallback.
        }

        try {
            return Authorization::isSuperAdmin(Auth::user());
        } catch (\Throwable $e) {
            return false;
        }
    }
    
    /**
     * Format phone number for Twilio (E.164 format)
     */
    private function formatPhoneNumber(string $number): string
    {
        return $this->compliance->normalizePhone($number);
    }

    private function normalizeIdempotencyKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return null;
        }
        if (strlen($key) < 8 || strlen($key) > 255) {
            throw new \InvalidArgumentException('SMS idempotency key must be between 8 and 255 characters.');
        }
        return hash('sha256', $key);
    }
    
    /**
     * Generate UUID
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
    
    /**
     * Update message status
     */
    public function updateMessageStatus(string $providerMessageId, string $status, array $data = []): void
    {
        $updateFields = ['status = ?'];
        $params = [$status];
        
        if (isset($data['error_message'])) {
            $updateFields[] = 'error_message = ?';
            $params[] = substr((string) $data['error_message'], 0, 500);
        }
        
        if (isset($data['cost'])) {
            $updateFields[] = 'cost = ?';
            $params[] = $data['cost'];
        }
        
        $requestedWorkspaceId = (int) ($data['workspace_id'] ?? 0);
        $message = Database::queryOne(
            "SELECT workspace_id
             FROM sms_messages
             WHERE provider_message_id = ?
             " . ($requestedWorkspaceId > 0 ? "AND workspace_id = ?" : "") . "
             LIMIT 1",
            $requestedWorkspaceId > 0 ? [$providerMessageId, $requestedWorkspaceId] : [$providerMessageId]
        );
        $workspaceId = (int) ($message['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            return;
        }

        $params[] = $providerMessageId;
        $params[] = $workspaceId;
        Database::execute(
            "UPDATE sms_messages SET " . implode(', ', $updateFields) . " WHERE provider_message_id = ? AND workspace_id = ?",
            $params
        );
    }
}
