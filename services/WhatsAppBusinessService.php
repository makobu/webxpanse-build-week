<?php
/**
 * WhatsApp Business Management Service
 * 
 * Handles business account management, webhook subscriptions, and app configuration
 */

namespace CRM\Services;

class WhatsAppBusinessService
{
    private string $baseUrl = 'https://graph.facebook.com/v24.0';
    /**
     * Token used for user/business/WABA operations (e.g. /me, /{waba-id}).
     */
    private string $userAccessToken;

    /**
     * Token used for app-level operations (e.g. /{app-id}/subscriptions, app config).
     * If not explicitly configured, uses app_id|app_secret before falling back to the user token.
     */
    private ?string $appAccessToken = null;

    private ?string $appId = null;
    private ?string $appSecret = null;
    
    public function __construct()
    {
        // Primary token for user/business/WABA operations
        $userToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '';
        $this->userAccessToken = trim($userToken);

        $appId = $_ENV['META_APP_ID'] ?? '';
        $this->appId = !empty($appId) ? trim($appId) : null;

        $appSecret = $_ENV['META_APP_SECRET'] ?? '';
        $appSecret = trim($appSecret);
        // Strip surrounding quotes (e.g. from .env: META_APP_SECRET="value")
        if ($appSecret !== '' && (($appSecret[0] === '"' && substr($appSecret, -1) === '"') || ($appSecret[0] === "'" && substr($appSecret, -1) === "'"))) {
            $appSecret = substr($appSecret, 1, -1);
        }
        // Remove any newlines/carriage returns (e.g. from copy-paste)
        $appSecret = str_replace(["\r", "\n"], '', $appSecret);
        $this->appSecret = $appSecret !== '' ? $appSecret : null;

        // Optional dedicated app access token for app-level endpoints
        $appToken = $_ENV['META_APP_ACCESS_TOKEN'] ?? '';
        $appToken = trim($appToken);
        if ($appToken === '' && $this->appId !== null && $this->appSecret !== null) {
            $appToken = $this->appId . '|' . $this->appSecret;
        }
        if ($appToken === '') {
            // Fallback: reuse user token if a separate app token is not configured
            $appToken = $this->userAccessToken;
        }
        $this->appAccessToken = $appToken !== '' ? $appToken : null;
    }

    public function hasAppWebhookAuth(): bool
    {
        return $this->appId !== null && $this->appAccessToken !== null;
    }
    
    /**
     * Make Graph API request
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], bool $useAppToken = false): array
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        // Choose token based on endpoint type
        $token = $useAppToken && !empty($this->appAccessToken)
            ? $this->appAccessToken
            : $this->userAccessToken;

        if (empty($token)) {
            throw new \RuntimeException($useAppToken ? 'Meta app access token is not configured.' : 'WhatsApp access token is not configured.');
        }

        // Add appsecret_proof when app secret is available (for apps with "Require App Secret" enabled)
        if (!empty($this->appSecret)) {
            $tokenForProof = str_replace(["\r", "\n"], '', $token);
            $appsecretProof = hash_hmac('sha256', $tokenForProof, $this->appSecret);
            $separator = (strpos($url, '?') === false) ? '?' : '&';
            $url .= $separator . 'appsecret_proof=' . $appsecretProof;
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2
        ]);
        
        if (!empty($data) && in_array($method, ['POST', 'PUT', 'PATCH'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($response === false) {
            throw new \RuntimeException("Graph API request failed: " . ($curlError ?: 'Unknown cURL error'));
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException("Invalid JSON response: " . substr($response, 0, 200));
        }
        
        if ($httpCode >= 400) {
            $errorMessage = $result['error']['message'] ?? 'Unknown error';
            $errorCode = $result['error']['code'] ?? $httpCode;
            throw new \RuntimeException("Graph API error ({$errorCode}): {$errorMessage}");
        }
        
        return is_array($result) ? $result : [];
    }
    
    /**
     * Subscribe to webhook events (whatsapp_business_manage_events)
     */
    public function subscribeWebhook(string $object, string $callbackUrl, array $fields = ['messages']): array
    {
        if (empty($this->appId)) {
            throw new \RuntimeException('META_APP_ID is required for webhook subscription');
        }

        $verifyToken = trim((string) ($_ENV['WHATSAPP_VERIFY_TOKEN'] ?? ''));
        if ($verifyToken === '') {
            throw new \RuntimeException('WHATSAPP_VERIFY_TOKEN is required for webhook subscription');
        }
        
        $data = [
            'object' => $object,
            'callback_url' => $callbackUrl,
            'fields' => $fields,
            'verify_token' => $verifyToken
        ];
        
        // App-level endpoint – use app token
        return $this->makeRequest('POST', $this->appId . '/subscriptions', $data, true);
    }
    
    /**
     * List webhook subscriptions (whatsapp_business_manage_events)
     */
    public function listWebhooks(): array
    {
        if (empty($this->appId)) {
            throw new \RuntimeException('META_APP_ID is required to list webhooks');
        }
        
        // App-level endpoint – use app token
        return $this->makeRequest('GET', $this->appId . '/subscriptions', [], true);
    }
    
    /**
     * Unsubscribe from webhook events (whatsapp_business_manage_events)
     */
    public function unsubscribeWebhook(string $object): array
    {
        if (empty($this->appId)) {
            throw new \RuntimeException('META_APP_ID is required to unsubscribe from webhooks');
        }
        
        // For DELETE requests, Meta expects the object as a query parameter
        $endpoint = $this->appId . '/subscriptions?object=' . urlencode($object);
        // App-level endpoint – use app token
        $result = $this->makeRequest('DELETE', $endpoint, [], true);

        return !empty($result) ? $result : ['success' => true];
    }
    
    /**
     * Get app configuration (manage_app_solution)
     */
    public function getAppConfig(array $fields = ['name', 'category', 'link', 'privacy_policy_url']): array
    {
        if (empty($this->appId)) {
            throw new \RuntimeException('META_APP_ID is required to get app configuration');
        }
        
        $fieldsStr = implode(',', $fields);
        // App-level endpoint – use app token
        return $this->makeRequest('GET', $this->appId . '?fields=' . urlencode($fieldsStr), [], true);
    }
    
    /**
     * Update app configuration (manage_app_solution)
     */
    public function updateAppConfig(array $data): array
    {
        if (empty($this->appId)) {
            throw new \RuntimeException('META_APP_ID is required to update app configuration');
        }
        
        // App-level endpoint – use app token
        return $this->makeRequest('POST', $this->appId, $data, true);
    }
    
    /**
     * Get user email (email permission)
     */
    public function getUserEmail(): array
    {
        return $this->makeRequest('GET', 'me?fields=email');
    }
    
    /**
     * List business accounts (business_management)
     */
    public function listBusinesses(): array
    {
        return $this->makeRequest('GET', 'me/businesses?fields=id,name,timezone_id');
    }
    
    /**
     * Get Business details (business_management)
     *
     * Note: This expects a Meta Business ID (Business Manager), not a WABA ID.
     */
    public function getBusiness(string $businessId, array $fields = ['id', 'name', 'timezone_id']): array
    {
        $fieldsStr = implode(',', $fields);
        return $this->makeRequest('GET', $businessId . '?fields=' . urlencode($fieldsStr));
    }

    /**
     * List WhatsApp Business Accounts (WABAs) owned by a Business (business_management)
     *
     * Graph: GET /{business-id}/owned_whatsapp_business_accounts?fields=...
     */
    public function listOwnedWhatsappBusinessAccounts(string $businessId, array $fields = ['id', 'name', 'timezone_id']): array
    {
        $fieldsStr = implode(',', $fields);
        return $this->makeRequest(
            'GET',
            $businessId . '/owned_whatsapp_business_accounts?fields=' . urlencode($fieldsStr)
        );
    }

    /**
     * Get WhatsApp Business Account (WABA) details (business_management)
     *
     * Note: This expects a WABA ID (WhatsAppBusinessAccount node).
     */
    public function getWaba(string $wabaId, array $fields = ['id', 'name', 'timezone_id']): array
    {
        $fieldsStr = implode(',', $fields);
        return $this->makeRequest('GET', $wabaId . '?fields=' . urlencode($fieldsStr));
    }
    
    /**
     * List phone numbers for WABA (business_management)
     */
    public function listPhoneNumbers(string $wabaId): array
    {
        return $this->makeRequest('GET', $wabaId . '/phone_numbers?fields=id,display_phone_number,verified_name,quality_rating,code_verification_status,platform_type');
    }

    /**
     * Get WhatsApp Business Profile (whatsapp_business_messaging / whatsapp_business_management)
     * Requires phone number ID. Uses /whatsapp_business_profile sub-resource.
     */
    public function getBusinessProfile(string $phoneNumberId): array
    {
        return $this->makeRequest('GET', $phoneNumberId . '/whatsapp_business_profile');
    }

    /**
     * List message templates for WABA (whatsapp_business_management)
     */
    public function listMessageTemplates(string $wabaId, int $limit = 10): array
    {
        return $this->makeRequest('GET', $wabaId . '/message_templates?limit=' . (int) $limit);
    }
}
