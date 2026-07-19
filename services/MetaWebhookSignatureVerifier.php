<?php

namespace CRM\Services;

class MetaWebhookSignatureVerifier
{
    public function verify(string $rawPayload, string $signatureHeader, ?string $appSecret = null): bool
    {
        $secret = $this->normalizeSecret($appSecret ?? (string) ($_ENV['META_APP_SECRET'] ?? ''));
        $signatureHeader = strtolower(trim($signatureHeader));

        if ($secret === '' || $rawPayload === '' || !str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $provided = substr($signatureHeader, 7);
        if ($provided === '' || !preg_match('/^[a-f0-9]{64}$/', $provided)) {
            return false;
        }

        $expected = hash_hmac('sha256', $rawPayload, $secret);
        return hash_equals($expected, $provided);
    }

    private function normalizeSecret(string $secret): string
    {
        $secret = trim($secret);
        if (strlen($secret) >= 2) {
            $first = $secret[0];
            $last = $secret[strlen($secret) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $secret = substr($secret, 1, -1);
            }
        }

        return trim($secret);
    }
}
