<?php

namespace CRM\Services;

class MpesaDarajaGateway
{
    private const URLS = [
        'sandbox' => [
            'oauth_url' => 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
            'stk_push_url' => 'https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
            'stk_query_url' => 'https://sandbox.safaricom.co.ke/mpesa/stkpushquery/v1/query',
        ],
        'live' => [
            'oauth_url' => 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
            'stk_push_url' => 'https://api.safaricom.co.ke/mpesa/stkpush/v1/processrequest',
            'stk_query_url' => 'https://api.safaricom.co.ke/mpesa/stkpushquery/v1/query',
        ],
    ];

    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(array $config)
    {
        $environment = strtolower(trim((string) ($config['environment'] ?? 'sandbox')));
        if ($environment === 'production') {
            $environment = 'live';
        }
        if (!isset(self::URLS[$environment])) {
            $environment = 'sandbox';
        }

        $this->config = [
            'enabled' => !empty($config['enabled']),
            'environment' => $environment,
            'consumer_key' => trim((string) ($config['consumer_key'] ?? '')),
            'consumer_secret' => trim((string) ($config['consumer_secret'] ?? '')),
            'business_short_code' => trim((string) ($config['business_short_code'] ?? '')),
            'passkey' => trim((string) ($config['passkey'] ?? '')),
            'callback_url' => trim((string) ($config['callback_url'] ?? '')),
        ];
    }

    public function isConfigured(): bool
    {
        if ($this->isFakeModeEnabled()) {
            return true;
        }

        if (empty($this->config['enabled'])) {
            return false;
        }

        foreach (['consumer_key', 'consumer_secret', 'business_short_code', 'passkey', 'callback_url'] as $field) {
            if (trim((string) ($this->config[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    public function normalizePhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone) ?: '';
        if (str_starts_with($phone, '254')) {
            return $phone;
        }
        if (str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        return '254' . $phone;
    }

    public function stkPush(string $phone, float $amount, string $accountReference, string $transactionDesc, ?string $callbackUrl = null): array
    {
        $this->validateConfiguration($callbackUrl);

        if ($this->isFakeModeEnabled()) {
            return $this->fakeStkPush($phone, $amount, $accountReference);
        }

        $timestamp = date('YmdHis');
        $password = base64_encode((string) $this->config['business_short_code'] . (string) $this->config['passkey'] . $timestamp);
        $phone = $this->normalizePhoneNumber($phone);
        $requestAmount = max(1, (int) round($amount));

        $payload = [
            'BusinessShortCode' => (string) $this->config['business_short_code'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $requestAmount,
            'PartyA' => $phone,
            'PartyB' => (string) $this->config['business_short_code'],
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl !== null && trim($callbackUrl) !== ''
                ? trim($callbackUrl)
                : (string) $this->config['callback_url'],
            'AccountReference' => substr($accountReference, 0, 12),
            'TransactionDesc' => substr($transactionDesc, 0, 100),
        ];

        return $this->request('POST', self::URLS[(string) $this->config['environment']]['stk_push_url'], $payload, [
            'Authorization: Bearer ' . $this->generateAccessToken(),
            'Content-Type: application/json',
        ]);
    }

    public function queryStkPush(string $checkoutRequestId): array
    {
        $this->validateConfiguration();

        if ($this->isFakeModeEnabled()) {
            return $this->fakeQueryStkPush($checkoutRequestId);
        }

        $timestamp = date('YmdHis');
        $password = base64_encode((string) $this->config['business_short_code'] . (string) $this->config['passkey'] . $timestamp);

        return $this->request('POST', self::URLS[(string) $this->config['environment']]['stk_query_url'], [
            'BusinessShortCode' => (string) $this->config['business_short_code'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'CheckoutRequestID' => trim($checkoutRequestId),
        ], [
            'Authorization: Bearer ' . $this->generateAccessToken(),
            'Content-Type: application/json',
        ]);
    }

    public function generateAccessToken(): string
    {
        $this->validateConfiguration();

        if ($this->isFakeModeEnabled()) {
            return 'fake_mpesa_access_token';
        }

        $credentials = base64_encode((string) $this->config['consumer_key'] . ':' . (string) $this->config['consumer_secret']);
        $response = $this->request('GET', self::URLS[(string) $this->config['environment']]['oauth_url'], [], [
            'Authorization: Basic ' . $credentials,
            'Content-Type: application/json',
        ]);

        $token = trim((string) ($response['access_token'] ?? ''));
        if ($token === '') {
            throw new \RuntimeException('M-Pesa access token was not returned.');
        }

        return $token;
    }

    private function validateConfiguration(?string $callbackUrl = null): void
    {
        if ($this->isFakeModeEnabled()) {
            return;
        }

        if (!$this->isConfigured()) {
            throw new \RuntimeException('M-Pesa is not configured.');
        }

        $callbackUrl = trim((string) ($callbackUrl ?? $this->config['callback_url']));
        if ($callbackUrl === '' || !filter_var($callbackUrl, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('M-Pesa callback URL is invalid.');
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $headers
     * @return array<string,mixed>
     */
    private function request(string $method, string $url, array $payload = [], array $headers = []): array
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'CRM-Daraja/1.0');

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
        }

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false || $curlError !== '') {
            throw new \RuntimeException('M-Pesa request failed: ' . $curlError);
        }

        $decoded = json_decode($responseBody, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('M-Pesa returned an invalid JSON response.');
        }

        if ($httpCode >= 400) {
            $message = (string) ($decoded['errorMessage'] ?? $decoded['ResponseDescription'] ?? 'M-Pesa request failed.');
            if (stripos($message, 'invalid access token') !== false) {
                $message = 'M-Pesa rejected the access token. Verify the M-Pesa environment, consumer key, and consumer secret match the saved shortcode and passkey.';
            }
            throw new \RuntimeException($message);
        }

        return $decoded;
    }

    private function isFakeModeEnabled(): bool
    {
        $value = getenv('MPESA_FAKE_MODE');
        if ($value === false) {
            $value = $_ENV['MPESA_FAKE_MODE'] ?? 'false';
        }

        return strtolower(trim((string) $value)) === 'true';
    }

    private function fakeStkPush(string $phone, float $amount, string $accountReference): array
    {
        $responseCode = trim((string) (getenv('MPESA_FAKE_STK_RESPONSE_CODE') ?: ($_ENV['MPESA_FAKE_STK_RESPONSE_CODE'] ?? '0')));
        $checkoutRequestId = 'ws_FAKE_' . substr(hash('sha256', $accountReference . '|' . $phone . '|' . microtime(true)), 0, 24);
        $merchantRequestId = 'mr_FAKE_' . substr(hash('sha256', $checkoutRequestId), 0, 24);

        return [
            'MerchantRequestID' => $merchantRequestId,
            'CheckoutRequestID' => $checkoutRequestId,
            'ResponseCode' => $responseCode,
            'ResponseDescription' => $responseCode === '0' ? 'Success. Request accepted for processing' : 'Failed to initiate STK push',
            'CustomerMessage' => $responseCode === '0'
                ? 'Approve the M-Pesa prompt on the selected phone to complete payment.'
                : 'M-Pesa prompt could not be sent.',
            'Amount' => max(1, (int) round($amount)),
            'PhoneNumber' => $this->normalizePhoneNumber($phone),
        ];
    }

    private function fakeQueryStkPush(string $checkoutRequestId): array
    {
        $resultCode = trim((string) (getenv('MPESA_FAKE_QUERY_RESULT_CODE') ?: ($_ENV['MPESA_FAKE_QUERY_RESULT_CODE'] ?? '0')));
        $receipt = trim((string) (getenv('MPESA_FAKE_RECEIPT_NUMBER') ?: ($_ENV['MPESA_FAKE_RECEIPT_NUMBER'] ?? 'CRMTEST123')));

        return [
            'ResponseCode' => '0',
            'ResultCode' => $resultCode,
            'ResultDesc' => $resultCode === '0' ? 'The service request is processed successfully.' : 'M-Pesa payment was not completed.',
            'CheckoutRequestID' => $checkoutRequestId,
            'CallbackMetadata' => [
                'Item' => [
                    ['Name' => 'Amount', 'Value' => 1],
                    ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                    ['Name' => 'TransactionDate', 'Value' => date('YmdHis')],
                    ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                ],
            ],
        ];
    }
}
