<?php

namespace CRM\Services;

final class TwilioWebhookSignatureValidator
{
    public function isValid(string $requestUrl, array $parameters, string $signature, string $authToken): bool
    {
        $requestUrl = trim($requestUrl);
        $signature = trim($signature);
        $authToken = trim($authToken);
        if ($requestUrl === '' || $signature === '' || $authToken === '') {
            return false;
        }

        ksort($parameters, SORT_STRING);
        $payload = $requestUrl;
        foreach ($parameters as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                return false;
            }
            $payload .= (string) $key . (string) $value;
        }

        $expected = base64_encode(hash_hmac('sha1', $payload, $authToken, true));
        return hash_equals($expected, $signature);
    }

    public function sign(string $requestUrl, array $parameters, string $authToken): string
    {
        ksort($parameters, SORT_STRING);
        $payload = $requestUrl;
        foreach ($parameters as $key => $value) {
            if (!is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('Twilio webhook parameters must be scalar.');
            }
            $payload .= (string) $key . (string) $value;
        }

        return base64_encode(hash_hmac('sha1', $payload, $authToken, true));
    }
}
