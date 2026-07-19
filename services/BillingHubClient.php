<?php

namespace CRM\Services;

class BillingHubClient implements BillingHubClientInterface
{
    public function createCheckout(array $settings, array $payload): array
    {
        $workspaceKey = trim((string) ($settings['billing_workspace_key'] ?? ''));
        $pathTemplate = (string) ($settings['billing_hub_checkout_path'] ?? '/api/billing/workspaces/{workspace_key}/checkout');
        $path = str_replace('{workspace_key}', rawurlencode($workspaceKey), $pathTemplate);

        return $this->request('POST', $settings, $path, $payload);
    }

    public function fetchStatus(array $settings): array
    {
        $workspaceKey = trim((string) ($settings['billing_workspace_key'] ?? ''));
        $pathTemplate = (string) ($settings['billing_hub_status_path'] ?? '/api/billing/workspaces/{workspace_key}/status');
        $path = str_replace('{workspace_key}', rawurlencode($workspaceKey), $pathTemplate);

        return $this->request('GET', $settings, $path);
    }

    private function request(string $method, array $settings, string $path, array $payload = []): array
    {
        $baseUrl = rtrim((string) ($settings['billing_hub_base_url'] ?? ''), '/');
        $workspaceKey = trim((string) ($settings['billing_workspace_key'] ?? ''));
        $secret = (string) ($settings['billing_hub_signing_secret'] ?? '');
        if ($baseUrl === '' || $workspaceKey === '' || $secret === '') {
            throw new \RuntimeException('Billing hub configuration is incomplete.');
        }

        $body = strtoupper($method) === 'GET' ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Billing hub payload could not be encoded.');
        }

        $timestamp = gmdate('c');
        $signature = hash_hmac('sha256', strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $body, $secret);
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-Billing-Workspace: ' . $workspaceKey,
            'X-Billing-Timestamp: ' . $timestamp,
            'X-Billing-Signature: ' . $signature,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlError !== '') {
            throw new \RuntimeException('Billing hub request failed: ' . $curlError);
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Billing hub returned an invalid JSON response.');
        }

        if ($httpCode >= 400) {
            $message = (string) ($decoded['message'] ?? $decoded['error'] ?? 'Billing hub request failed.');
            throw new \RuntimeException($message);
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return $decoded['data'];
        }

        return $decoded;
    }
}
