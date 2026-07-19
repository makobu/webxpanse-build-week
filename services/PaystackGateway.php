<?php

namespace CRM\Services;

class PaystackGateway
{
    private string $secretKey;

    public function __construct(string $secretKey)
    {
        $this->secretKey = trim($secretKey);
    }

    public function isConfigured(): bool
    {
        return $this->secretKey !== '';
    }

    public function initializeTransaction(array $payload): array
    {
        return $this->request('POST', '/transaction/initialize', $payload);
    }

    public function createCharge(array $payload): array
    {
        return $this->request('POST', '/charge', $payload);
    }

    public function createPlan(array $payload): array
    {
        return $this->request('POST', '/plan', $payload);
    }

    public function createSubscription(array $payload): array
    {
        return $this->request('POST', '/subscription', $payload);
    }

    public function disableSubscription(string $code, string $token): array
    {
        return $this->request('POST', '/subscription/disable', [
            'code' => $code,
            'token' => $token,
        ]);
    }

    public function enableSubscription(string $code, string $token): array
    {
        return $this->request('POST', '/subscription/enable', [
            'code' => $code,
            'token' => $token,
        ]);
    }

    public function verifyTransaction(string $reference): array
    {
        return $this->request('GET', '/transaction/verify/' . rawurlencode($reference));
    }

    public function verifyWebhookSignature(string $rawPayload, ?string $signature): bool
    {
        if ($this->isFakeModeEnabled()) {
            return $this->isConfigured() && is_string($signature) && trim($signature) !== ''
                && hash_equals(hash_hmac('sha512', $rawPayload, $this->secretKey), trim($signature));
        }

        if (!$this->isConfigured() || !is_string($signature) || trim($signature) === '') {
            return false;
        }

        $expected = hash_hmac('sha512', $rawPayload, $this->secretKey);
        return hash_equals($expected, trim($signature));
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Paystack secret key is not configured.');
        }

        if ($this->isFakeModeEnabled()) {
            return $this->fakeRequest($method, $path, $payload);
        }

        $ch = curl_init();
        $url = 'https://api.paystack.co' . $path;
        $headers = [
            'Authorization: Bearer ' . $this->secretKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlError !== '') {
            throw new \RuntimeException('Paystack request failed: ' . $curlError);
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Paystack returned an invalid JSON response.');
        }

        if ($httpCode >= 400 || empty($decoded['status'])) {
            $message = (string) ($decoded['message'] ?? 'Paystack request failed.');
            throw new \RuntimeException($message);
        }

        return $decoded;
    }

    private function isFakeModeEnabled(): bool
    {
        $value = getenv('PAYSTACK_FAKE_MODE');
        if ($value === false) {
            $value = $_ENV['PAYSTACK_FAKE_MODE'] ?? 'false';
        }

        return strtolower(trim((string) $value)) === 'true';
    }

    private function fakeRequest(string $method, string $path, array $payload = []): array
    {
        if (strtoupper($method) === 'POST' && $path === '/transaction/initialize') {
            $reference = (string) ($payload['reference'] ?? ('fake_ref_' . bin2hex(random_bytes(6))));
            $baseUrl = trim((string) (getenv('PAYSTACK_FAKE_AUTH_BASE_URL') ?: ($_ENV['PAYSTACK_FAKE_AUTH_BASE_URL'] ?? 'https://paystack.example/authorize/')));

            return [
                'status' => true,
                'data' => [
                    'authorization_url' => rtrim($baseUrl, '/') . '/' . $reference,
                    'access_code' => 'access_' . $reference,
                    'reference' => $reference,
                ],
            ];
        }

        if (strtoupper($method) === 'POST' && $path === '/charge') {
            $reference = (string) ($payload['reference'] ?? ('fake_charge_' . bin2hex(random_bytes(6))));
            $mode = 'card';
            if (isset($payload['mobile_money'])) {
                $mode = 'mpesa';
            } elseif (isset($payload['bank_transfer']) || isset($payload['bank'])) {
                $mode = 'bank_transfer';
            }

            $status = strtolower(trim((string) (getenv('PAYSTACK_FAKE_CHARGE_STATUS') ?: ($_ENV['PAYSTACK_FAKE_CHARGE_STATUS'] ?? 'pending'))));
            $displayText = match ($mode) {
                'mpesa' => 'Approve the M-Pesa prompt on the selected phone to complete payment.',
                'bank_transfer' => 'Use the generated bank transfer instructions to complete this payment.',
                default => 'Complete the payment using the requested Paystack charge flow.',
            };
            $instructions = $mode === 'bank_transfer'
                ? [
                    'bank_name' => 'Paystack Test Bank',
                    'account_number' => '1234567890',
                    'account_name' => 'Workspace Billing',
                    'expires_at' => date('c', strtotime('+30 minutes')),
                ]
                : [];

            return [
                'status' => true,
                'data' => [
                    'reference' => $reference,
                    'status' => $status,
                    'display_text' => $displayText,
                    'gateway_response' => $displayText,
                    'instructions' => $instructions,
                    'expires_at' => $instructions['expires_at'] ?? null,
                ],
            ];
        }

        if (strtoupper($method) === 'POST' && $path === '/plan') {
            $name = trim((string) ($payload['name'] ?? 'Launch Plan'));
            $hash = substr(hash('sha256', $name . '|' . (string) ($payload['amount'] ?? '') . '|' . (string) ($payload['interval'] ?? '')), 0, 14);

            return [
                'status' => true,
                'message' => 'Plan created',
                'data' => [
                    'id' => hexdec(substr($hash, 0, 6)),
                    'name' => $name,
                    'amount' => (int) ($payload['amount'] ?? 0),
                    'interval' => (string) ($payload['interval'] ?? 'monthly'),
                    'currency' => (string) ($payload['currency'] ?? 'KES'),
                    'plan_code' => 'PLN_' . $hash,
                    'status' => 'active',
                ],
            ];
        }

        if (strtoupper($method) === 'POST' && $path === '/subscription') {
            $customer = trim((string) ($payload['customer'] ?? 'customer'));
            $plan = trim((string) ($payload['plan'] ?? 'plan'));
            $hash = substr(hash('sha256', $customer . '|' . $plan . '|' . (string) ($payload['start_date'] ?? '')), 0, 14);

            return [
                'status' => true,
                'message' => 'Subscription successfully created',
                'data' => [
                    'customer' => $customer,
                    'plan' => $plan,
                    'status' => 'active',
                    'subscription_code' => 'SUB_' . $hash,
                    'email_token' => 'email_' . substr($hash, 0, 10),
                    'next_payment_date' => (string) ($payload['start_date'] ?? date('c', strtotime('+1 month'))),
                ],
            ];
        }

        if (strtoupper($method) === 'POST' && in_array($path, ['/subscription/disable', '/subscription/enable'], true)) {
            return [
                'status' => true,
                'message' => $path === '/subscription/disable'
                    ? 'Subscription disabled successfully'
                    : 'Subscription enabled successfully',
            ];
        }

        if (strtoupper($method) === 'GET' && str_starts_with($path, '/transaction/verify/')) {
            $reference = rawurldecode((string) substr($path, strlen('/transaction/verify/')));
            $status = strtolower(trim((string) (getenv('PAYSTACK_FAKE_VERIFY_STATUS') ?: ($_ENV['PAYSTACK_FAKE_VERIFY_STATUS'] ?? 'success'))));
            $gatewayResponse = trim((string) (getenv('PAYSTACK_FAKE_GATEWAY_RESPONSE') ?: ($_ENV['PAYSTACK_FAKE_GATEWAY_RESPONSE'] ?? 'Approved')));

            return [
                'status' => true,
                'data' => [
                    'reference' => $reference,
                    'status' => $status,
                    'paid_at' => date('c'),
                    'gateway_response' => $gatewayResponse !== '' ? $gatewayResponse : $status,
                ],
            ];
        }

        throw new \RuntimeException('Unsupported fake Paystack request: ' . strtoupper($method) . ' ' . $path);
    }
}
