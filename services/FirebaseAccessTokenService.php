<?php

namespace CRM\Services;

class FirebaseAccessTokenService
{
    public function projectId(): string
    {
        $credentials = $this->serviceAccountCredentials();
        return (string) ($credentials['project_id'] ?? ($_ENV['FCM_PROJECT_ID'] ?? ''));
    }

    public function getAccessToken(): string
    {
        $credentials = $this->serviceAccountCredentials();
        $clientEmail = (string) ($credentials['client_email'] ?? '');
        $privateKey = (string) ($credentials['private_key'] ?? '');

        if ($clientEmail === '' || $privateKey === '') {
            throw new \RuntimeException('FCM service account credentials are incomplete.');
        }

        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $claims = $this->base64UrlEncode(json_encode([
            'iss' => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_UNESCAPED_SLASHES));

        $unsignedJwt = $header . '.' . $claims;
        if (!openssl_sign($unsignedJwt, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Could not sign FCM OAuth assertion.');
        }

        $assertion = $unsignedJwt . '.' . $this->base64UrlEncode($signature);
        $response = $this->postForm('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $assertion,
        ]);

        $payload = json_decode($response, true);
        $accessToken = is_array($payload) ? (string) ($payload['access_token'] ?? '') : '';
        if ($accessToken === '') {
            throw new \RuntimeException('FCM OAuth token response did not include an access token.');
        }

        return $accessToken;
    }

    private function serviceAccountCredentials(): array
    {
        $json = trim((string) ($_ENV['FCM_SERVICE_ACCOUNT_JSON'] ?? ''));
        if ($json === '') {
            $path = trim((string) ($_ENV['FCM_SERVICE_ACCOUNT_PATH'] ?? ''));
            if ($path !== '' && file_exists($path)) {
                $json = (string) file_get_contents($path);
            }
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('FCM service account credentials are not configured.');
        }

        return $decoded;
    }

    private function postForm(string $url, array $form): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialize HTTP client for FCM OAuth.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('FCM OAuth request failed: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException('FCM OAuth request failed with status ' . $httpCode . ': ' . $response);
        }

        return (string) $response;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
