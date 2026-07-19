<?php

namespace CRM\Services;

class AIProviderProbeService
{
    public function getReadiness(): array
    {
        return AIRuntimeConfig::validate();
    }

    public function probe(bool $live = false): array
    {
        $readiness = AIRuntimeConfig::validate();
        $provider = $readiness['provider'];

        $result = [
            'success' => false,
            'live_probe_attempted' => $live,
            'core_ai_provider_ready' => $readiness['core_ai_provider_ready'],
            'fallback_only_mode' => $readiness['fallback_only_mode'],
            'provider' => $provider,
            'message' => $readiness['message'],
            'http_code' => 0,
            'response_excerpt' => '',
        ];

        if (!$live) {
            $result['success'] = $readiness['core_ai_provider_ready'];
            $result['message'] = $readiness['core_ai_provider_ready']
                ? 'AI provider configuration is ready for a live probe.'
                : $provider['message'];
            return $result;
        }

        if (!$readiness['core_ai_provider_ready']) {
            $result['message'] = $provider['message'];
            return $result;
        }

        $probeUrl = (string) ($provider['normalized_probe_url'] ?? '');
        $apiKey = trim((string) ($_ENV['AI_API_KEY'] ?? ''));
        if ($probeUrl === '' || $apiKey === '') {
            $result['message'] = 'AI provider is missing live probe prerequisites.';
            return $result;
        }

        $ch = curl_init($probeUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        $result['http_code'] = $httpCode;
        $result['response_excerpt'] = is_string($response) ? substr(trim($response), 0, 200) : '';

        if ($curlError !== '') {
            $result['message'] = 'Connection error: ' . $curlError;
            return $result;
        }

        $payload = is_string($response) ? json_decode($response, true) : null;
        if ($httpCode !== 200) {
            $result['message'] = is_array($payload) && isset($payload['error']['message'])
                ? (string) $payload['error']['message']
                : 'HTTP Error ' . $httpCode;
            return $result;
        }

        $models = [];
        foreach (array_slice((array) ($payload['data'] ?? []), 0, 5) as $row) {
            if (!empty($row['id'])) {
                $models[] = (string) $row['id'];
            }
        }

        $result['success'] = true;
        $result['message'] = 'AI API connection successful.';
        $result['models_sample'] = $models;

        return $result;
    }
}
