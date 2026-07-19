<?php
/**
 * WhatsApp Cloud API registration and On-Premises to Cloud API migration support.
 */

namespace CRM\Services;

use CRM\Database;

class WhatsAppMigrationService
{
    private string $cloudApiBaseUrl = 'https://graph.facebook.com/v24.0';
    private int $workspaceId = 0;
    private int $userId = 0;
    private string $phoneNumberId = '';
    private string $accessToken = '';
    private string $whatsappBusinessAccountId = '';
    private string $onPremApiUrl = '';

    /**
     * @param array<string,mixed>|null $credentials
     */
    public function __construct(?int $workspaceId = null, ?int $userId = null, ?array $credentials = null)
    {
        $this->workspaceId = max(0, (int) ($workspaceId ?? 0));
        $this->userId = max(0, (int) ($userId ?? 0));
        $this->onPremApiUrl = rtrim(trim((string) ($_ENV['WHATSAPP_ONPREM_API_URL'] ?? '')), '/');

        if ($credentials !== null) {
            $this->applyCredentials($credentials);
        } elseif ($this->workspaceId > 0) {
            $this->loadWorkspaceCredentials($this->workspaceId);
        } else {
            $this->loadEnvCredentials();
        }

        $this->ensureMigrationTable();
    }

    public static function buildRegistrationPayload(string $pin, ?string $password = null, ?string $metadata = null, ?string $dataLocalizationRegion = null): array
    {
        $data = [
            'messaging_product' => 'whatsapp',
            'pin' => $pin,
        ];

        $password = trim((string) $password);
        $metadata = trim((string) $metadata);
        if ($password !== '' || $metadata !== '') {
            if ($password === '' || $metadata === '') {
                throw new \RuntimeException('Password and metadata must be provided together for On-Premises migration backup.');
            }
            $data['backup'] = [
                'password' => $password,
                'data' => $metadata,
            ];
        }

        $region = strtoupper(trim((string) $dataLocalizationRegion));
        if ($region !== '') {
            $data['data_localization_region'] = $region;
        }

        return $data;
    }

    public function generateMetadata(string $password): array
    {
        $password = trim($password);
        if ($password === '') {
            throw new \RuntimeException('Password is required.');
        }
        if (strlen($password) < 8) {
            throw new \RuntimeException('Password must be at least 8 characters long.');
        }
        if ($this->onPremApiUrl === '') {
            throw new \RuntimeException('On-Premises API URL is not configured. Set WHATSAPP_ONPREM_API_URL before generating migration metadata.');
        }

        $response = $this->makeOnPremRequest('POST', $this->onPremApiUrl . '/v1/settings/backup', [
            'password' => $password,
        ]);
        $settings = $response['settings'] ?? [];
        $meta = (array) ($response['meta'] ?? []);
        $metadata = '';
        if (is_array($settings)) {
            $metadata = trim((string) ($settings['data'] ?? ''));
        } else {
            $metadata = trim((string) $settings);
        }
        if ($metadata === '') {
            $metadata = trim((string) ($response['data'] ?? ''));
        }
        if ($metadata === '') {
            throw new \RuntimeException('On-Premises API did not return migration metadata.');
        }

        $this->logMigrationStep('generate_metadata', [
            'metadata_hash' => hash('sha256', $metadata),
            'api_status' => (string) ($meta['api_status'] ?? $response['api_status'] ?? ''),
            'api_version' => (string) ($meta['version'] ?? $response['version'] ?? ''),
        ], true);

        return [
            'success' => true,
            'metadata' => $metadata,
            'api_status' => (string) ($meta['api_status'] ?? $response['api_status'] ?? ''),
            'api_version' => (string) ($meta['version'] ?? $response['version'] ?? ''),
            'message' => 'Metadata generated successfully. Save the metadata string and password securely.',
        ];
    }

    public function registerNumber(string $pin, ?string $password = null, ?string $metadata = null, ?string $dataLocalizationRegion = null): array
    {
        if (!preg_match('/^\d{6}$/', $pin)) {
            throw new \RuntimeException('PIN must be exactly 6 digits.');
        }
        $region = strtoupper(trim((string) $dataLocalizationRegion));
        if ($region !== '' && !preg_match('/^[A-Z]{2}$/', $region)) {
            throw new \RuntimeException('Data localization region must be a 2-letter ISO country code.');
        }

        $this->requireCloudCredentials();
        $data = self::buildRegistrationPayload($pin, $password, $metadata, $region);
        $url = rtrim($this->cloudApiBaseUrl, '/') . '/' . rawurlencode($this->phoneNumberId) . '/register';

        try {
            $response = $this->makeCloudApiRequest('POST', $url, $data);
            $this->logMigrationStep('register_number', [
                'data_localization_region' => $region,
            ], true);

            return [
                'success' => true,
                'message' => 'Phone number registered successfully with Cloud API.',
                'response' => $response,
            ];
        } catch (\Throwable $e) {
            $this->logMigrationStep('register_number', [
                'data_localization_region' => $region,
                'error_message' => $e->getMessage(),
            ], false);

            if (strpos($e->getMessage(), '133016') !== false) {
                throw new \RuntimeException('Rate limit exceeded. You can only make 10 registration requests per phone number in a 72-hour period. Please wait 72 hours before trying again.');
            }

            throw $e;
        }
    }

    public function deregisterNumber(): array
    {
        $this->requireCloudCredentials();
        $url = rtrim($this->cloudApiBaseUrl, '/') . '/' . rawurlencode($this->phoneNumberId) . '/deregister';

        try {
            $response = $this->makeCloudApiRequest('POST', $url);
            $this->logMigrationStep('deregister_number', [], true);

            return [
                'success' => true,
                'message' => 'Phone number deregistered successfully.',
                'response' => $response,
            ];
        } catch (\Throwable $e) {
            $this->logMigrationStep('deregister_number', [
                'error_message' => $e->getMessage(),
            ], false);

            if (strpos($e->getMessage(), '133016') !== false) {
                throw new \RuntimeException('Rate limit exceeded. You can only make 10 deregistration requests per phone number in a 72-hour period. Please wait 72 hours before trying again.');
            }
            if (str_contains(strtolower($e->getMessage()), 'business app') || str_contains(strtolower($e->getMessage()), 'in use')) {
                throw new \RuntimeException('Cannot deregister: the phone number is currently in use with another WhatsApp client. Disconnect it there first.');
            }

            throw $e;
        }
    }

    public function checkHealthStatus(): array
    {
        $this->requireCloudCredentials();
        $fields = 'display_phone_number,verified_name,quality_rating,code_verification_status,platform_type,health_status';
        $url = rtrim($this->cloudApiBaseUrl, '/') . '/' . rawurlencode($this->phoneNumberId) . '?fields=' . rawurlencode($fields);

        try {
            $response = $this->makeCloudApiRequest('GET', $url);
            $qualityRating = (string) ($response['quality_rating'] ?? 'UNKNOWN');
            $healthStatus = (string) ($response['health_status'] ?? $qualityRating);
            $codeVerificationStatus = (string) ($response['code_verification_status'] ?? '');
            $platformType = (string) ($response['platform_type'] ?? '');
            $canSendMessage = $codeVerificationStatus === 'VERIFIED'
                && $platformType === 'CLOUD_API'
                && $qualityRating !== 'RED';
            $statusColor = $qualityRating === 'UNKNOWN' && $codeVerificationStatus === 'VERIFIED'
                ? 'GREEN'
                : $qualityRating;

            $this->logMigrationStep('health_check', [
                'api_status' => $healthStatus,
            ], true);

            return [
                'success' => true,
                'health_status' => $healthStatus,
                'status_color' => $statusColor,
                'can_send_message' => $canSendMessage,
                'details' => $response,
            ];
        } catch (\Throwable $e) {
            $this->logMigrationStep('health_check', [
                'error_message' => $e->getMessage(),
            ], false);

            throw $e;
        }
    }

    public function getMigrationStatus(): ?array
    {
        if (!Database::tableExists('whatsapp_migration_log')) {
            return null;
        }

        try {
            [$where, $params] = $this->migrationScope();
            $latest = Database::queryOne(
                "SELECT *
                 FROM whatsapp_migration_log
                 {$where}
                 ORDER BY created_at DESC, id DESC
                 LIMIT 1",
                $params
            );

            if (!$latest) {
                return null;
            }

            $steps = Database::query(
                "SELECT step, success, created_at, error_message, phone_number_id, whatsapp_business_account_id
                 FROM whatsapp_migration_log
                 {$where}
                 ORDER BY created_at ASC, id ASC",
                $params
            );

            return [
                'latest' => $latest,
                'steps' => $steps,
                'has_metadata' => !empty($latest['metadata_hash']),
                'is_registered' => (string) ($latest['step'] ?? '') === 'register_number' && (int) ($latest['success'] ?? 0) === 1,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function applyCredentials(array $credentials): void
    {
        $this->phoneNumberId = preg_replace('/\s+/', '', trim((string) ($credentials['phone_number_id'] ?? ''))) ?? '';
        $this->accessToken = trim((string) ($credentials['access_token'] ?? ''));
        $this->whatsappBusinessAccountId = trim((string) ($credentials['whatsapp_business_account_id'] ?? $credentials['waba_id'] ?? ''));
    }

    private function loadWorkspaceCredentials(int $workspaceId): void
    {
        try {
            $active = (new WorkspaceConnectService())->getActiveWhatsAppIntegration($workspaceId) ?: [];
        } catch (\Throwable $e) {
            $active = [];
        }

        $this->applyCredentials($active);
    }

    private function loadEnvCredentials(): void
    {
        $this->applyCredentials([
            'phone_number_id' => $_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? '',
            'access_token' => $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '',
            'whatsapp_business_account_id' => $_ENV['WHATSAPP_BUSINESS_ACCOUNT_ID'] ?? '',
        ]);
    }

    private function requireCloudCredentials(): void
    {
        if ($this->phoneNumberId === '') {
            throw new \RuntimeException('WhatsApp Phone Number ID is not configured. Save manual WhatsApp setup for this workspace first.');
        }
        if ($this->accessToken === '') {
            throw new \RuntimeException('WhatsApp Access Token is not configured. Save manual WhatsApp setup for this workspace first.');
        }
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function migrationScope(): array
    {
        if ($this->workspaceId > 0 && Database::columnExists('whatsapp_migration_log', 'workspace_id')) {
            return ['WHERE workspace_id = ?', [$this->workspaceId]];
        }

        return ['', []];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function logMigrationStep(string $step, array $data, bool $success): void
    {
        try {
            if (!Database::tableExists('whatsapp_migration_log')) {
                return;
            }

            $columns = ['step', 'metadata_hash', 'api_status', 'api_version', 'success', 'error_message', 'data_localization_region'];
            $values = [
                $step,
                $data['metadata_hash'] ?? null,
                $data['api_status'] ?? null,
                $data['api_version'] ?? null,
                $success ? 1 : 0,
                $data['error_message'] ?? null,
                $data['data_localization_region'] ?? null,
            ];

            foreach ([
                'workspace_id' => $this->workspaceId > 0 ? $this->workspaceId : null,
                'user_id' => $this->userId > 0 ? $this->userId : null,
                'phone_number_id' => $this->phoneNumberId !== '' ? $this->phoneNumberId : null,
                'whatsapp_business_account_id' => $this->whatsappBusinessAccountId !== '' ? $this->whatsappBusinessAccountId : null,
            ] as $column => $value) {
                if (Database::columnExists('whatsapp_migration_log', $column)) {
                    $columns[] = $column;
                    $values[] = $value;
                }
            }

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $columnList = implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns));
            Database::execute(
                "INSERT INTO whatsapp_migration_log ({$columnList}) VALUES ({$placeholders})",
                $values
            );
        } catch (\Throwable $e) {
            error_log('Failed to log WhatsApp migration step: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function makeOnPremRequest(string $method, string $url, array $data = []): array
    {
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        $token = trim((string) ($_ENV['WHATSAPP_ONPREM_API_TOKEN'] ?? ''));
        if ($token !== '') {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($data !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_SLASHES));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('On-Premises API request failed: ' . ($curlError ?: 'Unknown cURL error'));
        }
        if ($httpCode >= 400) {
            throw new \RuntimeException("On-Premises API returned error. HTTP Code: {$httpCode}");
        }

        $result = json_decode((string) $response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('On-Premises API returned invalid JSON.');
        }

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function makeCloudApiRequest(string $method, string $url, array $data = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($data !== []) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_SLASHES));
        }

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Cloud API request failed: ' . ($curlError ?: 'Unknown cURL error'));
        }

        $result = json_decode((string) $response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Cloud API returned invalid JSON. Response: ' . substr((string) $response, 0, 200));
        }

        if ($httpCode >= 400) {
            $errorMessage = $result['error']['message'] ?? ($result['error']['error_user_msg'] ?? 'Unknown error');
            $errorCode = $result['error']['code'] ?? $httpCode;
            $errorType = $result['error']['type'] ?? 'API_ERROR';

            throw new \RuntimeException("Cloud API error ({$errorCode}): {$errorMessage} [Type: {$errorType}]");
        }

        return is_array($result) ? $result : [];
    }

    private function ensureMigrationTable(): void
    {
        try {
            Database::execute("
                CREATE TABLE IF NOT EXISTS whatsapp_migration_log (
                    id INT PRIMARY KEY AUTO_INCREMENT,
                    workspace_id INT NULL,
                    user_id INT NULL,
                    phone_number_id VARCHAR(191) NULL,
                    whatsapp_business_account_id VARCHAR(191) NULL,
                    step VARCHAR(50) NOT NULL,
                    metadata_hash VARCHAR(255),
                    api_status VARCHAR(50),
                    api_version VARCHAR(20),
                    success BOOLEAN DEFAULT FALSE,
                    error_message TEXT,
                    data_localization_region VARCHAR(2),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_whatsapp_migration_workspace (workspace_id, created_at),
                    INDEX idx_whatsapp_migration_user (user_id),
                    INDEX idx_whatsapp_migration_phone (phone_number_id),
                    INDEX idx_step (step),
                    INDEX idx_success (success),
                    INDEX idx_created_at (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (\Throwable $e) {
            error_log('WhatsApp migration table check: ' . $e->getMessage());
        }
    }
}
