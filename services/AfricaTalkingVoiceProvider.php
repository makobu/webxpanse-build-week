<?php

namespace CRM\Services;

class AfricaTalkingVoiceProvider implements VoiceProviderInterface
{
    private const LIVE_VOICE_BASE_URL = 'https://voice.africastalking.com';
    private const SANDBOX_VOICE_BASE_URL = 'https://voice.sandbox.africastalking.com';
    private const LIVE_API_BASE_URL = 'https://api.africastalking.com';
    private const SANDBOX_API_BASE_URL = 'https://api.sandbox.africastalking.com';
    /** @var callable|null */
    private $transport;

    public function __construct(?callable $transport = null)
    {
        $this->transport = $transport;
    }

    public function capabilities(): array
    {
        return ['remote_end' => false, 'transfer' => true, 'queue_status' => true, 'outbound_prebridge_consent' => false];
    }

    public function verifyConfiguration(array $config): array
    {
        foreach (['account_username', 'api_key', 'virtual_number'] as $field) {
            if (trim((string) ($config[$field] ?? '')) === '') {
                return ['ok' => false, 'message' => 'Missing provider setting: ' . $field];
            }
        }
        if (!$this->validPhone((string) $config['virtual_number'])) {
            return ['ok' => false, 'message' => 'The provider virtual number is invalid.'];
        }
        try {
            $username = trim((string) $config['account_username']);
            $response = $this->requestUrl(
                'GET',
                $this->apiBaseUrl($username) . '/version1/user',
                $config,
                ['username' => $username]
            );
            $balance = trim((string) ($response['balance'] ?? $response['UserData']['balance'] ?? ''));
            return [
                'ok' => true,
                'message' => 'Provider credentials authenticated. Run a controlled call to verify that the virtual number is assigned and routable.',
                'metadata' => ['balance' => $balance, 'environment' => $this->isSandbox($username) ? 'sandbox' : 'live'],
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Provider authentication failed. Check the Africa\'s Talking username and API key.'];
        }
    }

    public function initiateAgentFirstOutboundBridge(array $config, string $agentEndpoint, string $callbackUrl): array
    {
        if (!$this->validPhone($agentEndpoint) && !$this->validSip($agentEndpoint)) {
            throw new \InvalidArgumentException('Agent endpoint is not a valid phone number or SIP URI.');
        }
        return $this->request('/call', $config, [
            'username' => (string) $config['account_username'],
            'from' => (string) $config['virtual_number'],
            'to' => $agentEndpoint,
            'clientRequestId' => $this->callbackReference($callbackUrl),
        ]);
    }

    public function renderInboundInstructions(array $route): string
    {
        $notice = trim((string) ($route['consent_notice'] ?? ''));
        $consentMode = (string) ($route['consent_mode'] ?? 'disabled');
        $destination = trim((string) ($route['destination'] ?? ''));
        $fallback = trim((string) ($route['fallback'] ?? ''));
        $xml = ['<?xml version="1.0" encoding="UTF-8"?>', '<Response>'];

        if ($notice !== '') {
            $xml[] = '<Say>' . $this->xml($notice) . '</Say>';
        }
        if ($consentMode === 'explicit_keypress') {
            $callback = (string) ($route['consent_callback_url'] ?? '');
            $xml[] = '<GetDigits timeout="10" finishOnKey="#" callbackUrl="' . $this->xmlAttribute($callback) . '"><Say>Press 1 to consent to recording, or press 2 to continue without recording.</Say></GetDigits>';
            $xml[] = '</Response>';
            return implode('', $xml);
        }
        if ($destination !== '') {
            $xml[] = '<Dial phoneNumbers="' . $this->xmlAttribute($destination) . '" record="' . (!empty($route['record']) && $consentMode !== 'explicit_keypress' ? 'true' : 'false') . '" maxDuration="' . max(60, min(14400, (int) ($route['max_duration'] ?? 3600))) . '" />';
        } elseif ($fallback !== '') {
            $xml[] = '<Dial phoneNumbers="' . $this->xmlAttribute($fallback) . '" record="false" />';
        } else {
            $xml[] = '<Say>All agents are currently unavailable. Please try again later.</Say>';
            $xml[] = '<Reject />';
        }
        $xml[] = '</Response>';
        return implode('', $xml);
    }

    public function renderOutboundBridgeInstructions(array $route): string
    {
        $destination = trim((string) ($route['destination'] ?? ''));
        if (!$this->validPhone($destination)) {
            return '<?xml version="1.0" encoding="UTF-8"?><Response><Say>The destination is unavailable.</Say><Reject /></Response>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><Response><Dial phoneNumbers="'
            . $this->xmlAttribute($destination) . '" record="false'
            . '" maxDuration="' . max(60, min(14400, (int) ($route['max_duration'] ?? 3600))) . '" /></Response>';
    }

    public function renderEnqueueInstructions(string $queueName, string $holdMusicUrl = ''): string
    {
        $queueName = trim($queueName);
        if ($queueName === '' || !preg_match('/^[A-Za-z0-9._-]{1,80}$/', $queueName)) {
            throw new \InvalidArgumentException('A provider-safe queue name is required.');
        }
        $attributes = ' name="' . $this->xmlAttribute($queueName) . '"';
        if ($holdMusicUrl !== '') {
            if (!filter_var($holdMusicUrl, FILTER_VALIDATE_URL) || parse_url($holdMusicUrl, PHP_URL_SCHEME) !== 'https') {
                throw new \InvalidArgumentException('Queue hold music must use a valid HTTPS URL.');
            }
            $attributes .= ' holdMusic="' . $this->xmlAttribute($holdMusicUrl) . '"';
        }
        return '<?xml version="1.0" encoding="UTF-8"?><Response><Enqueue' . $attributes . ' /></Response>';
    }

    public function renderDequeueInstructions(string $virtualNumber, string $queueName): string
    {
        if (!$this->validPhone($virtualNumber) || !preg_match('/^[A-Za-z0-9._-]{1,80}$/', trim($queueName))) {
            throw new \InvalidArgumentException('A valid virtual number and provider-safe queue name are required.');
        }
        return '<?xml version="1.0" encoding="UTF-8"?><Response><Dequeue phoneNumber="'
            . $this->xmlAttribute($virtualNumber) . '" name="' . $this->xmlAttribute(trim($queueName)) . '" /></Response>';
    }

    public function transfer(array $config, string $sessionId, string $destination, string $callbackUrl = ''): array
    {
        $fields = [
            'username' => (string) $config['account_username'],
            'sessionId' => $sessionId,
            'phoneNumber' => $destination,
            'callLeg' => 'callee',
        ];
        if (trim($callbackUrl) !== '') {
            $fields['holdMusicUrl'] = trim($callbackUrl);
        }
        return $this->request('/callTransfer', $config, $fields);
    }

    public function queueStatus(array $config, array $phoneNumbers): array
    {
        $numbers = array_values(array_unique(array_filter(array_map(
            static fn($number): string => trim((string) $number),
            $phoneNumbers
        ), fn(string $number): bool => $this->validPhone($number))));
        if ($numbers === []) {
            throw new \InvalidArgumentException('At least one valid provider phone number is required.');
        }
        return $this->request('/queueStatus', $config, [
            'username' => (string) $config['account_username'],
            'phoneNumbers' => implode(',', $numbers),
        ]);
    }

    public function endCall(array $config, string $sessionId): array
    {
        throw new \RuntimeException('Africa\'s Talking does not expose a documented remote hangup operation. End the call from the connected phone or SIP endpoint.');
    }

    public function requestRecordingMetadata(array $config, string $sessionId): array
    {
        return ['session_id' => $sessionId, 'available' => false, 'provider' => 'africastalking'];
    }

    public function normalizeEvent(array $payload): array
    {
        $sessionId = trim((string) ($payload['sessionId'] ?? $payload['SessionId'] ?? ''));
        $status = strtolower(trim((string) ($payload['status'] ?? $payload['callSessionState'] ?? $payload['CallSessionState'] ?? 'received')));
        $eventType = strtolower(trim((string) ($payload['eventType'] ?? $payload['callSessionState'] ?? $status)));
        $stateMap = [
            'queued' => 'queued', 'ringing' => 'ringing', 'answered' => 'in_progress', 'active' => 'in_progress',
            'bridged' => 'in_progress', 'dialing' => 'ringing', 'enqueued' => 'queued', 'dequeued' => 'ringing',
            'inprogress' => 'in_progress', 'completed' => 'completed', 'busy' => 'busy', 'noanswer' => 'no_answer', 'notanswered' => 'no_answer',
            'no_answer' => 'no_answer', 'failed' => 'provider_failed', 'rejected' => 'rejected',
        ];
        $normalizedStatus = preg_replace('/[^a-z_]/', '', str_replace(['-', ' '], '_', $status)) ?? '';
        $normalizedState = $stateMap[$normalizedStatus] ?? null;
        $recordingUrl = trim((string) ($payload['recordingUrl'] ?? $payload['RecordingUrl'] ?? ''));
        $clientRequestId = trim((string) ($payload['clientRequestId'] ?? $payload['ClientRequestId'] ?? ''));
        return [
            'provider' => 'africastalking', 'session_id' => $sessionId,
            'client_request_id' => substr($clientRequestId, 0, 100),
            'event_type' => preg_replace('/[^a-z0-9_.-]/', '_', $eventType) ?: 'unknown',
            'state' => $normalizedState, 'direction' => strtolower((string) ($payload['direction'] ?? '')),
            'from' => (string) ($payload['callerNumber'] ?? $payload['from'] ?? ''),
            'to' => (string) ($payload['destinationNumber'] ?? $payload['to'] ?? ''),
            'duration_seconds' => max(0, (int) ($payload['durationInSeconds'] ?? $payload['duration'] ?? 0)),
            'recording_url' => $recordingUrl, 'recording_id' => (string) ($payload['recordingId'] ?? ''),
            'failure_category' => substr(strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) ($payload['hangupCause'] ?? $payload['errorMessage'] ?? '')) ?? ''), 0, 120),
            'occurred_at' => (string) ($payload['timestamp'] ?? date('Y-m-d H:i:s')),
        ];
    }

    public function estimateUsage(string $direction, int $durationSeconds, bool $sipAgent = false): array
    {
        $minutes = $durationSeconds > 0 ? (int) ceil($durationSeconds / 60) : 0;
        $rate = $direction === 'inbound' ? 0.50 : 2.50;
        if ($direction === 'outbound' && $sipAgent) {
            $rate += 0.25;
        }
        return ['currency' => 'KES', 'amount' => round($minutes * $rate, 4), 'billable_minutes' => $minutes];
    }

    public function scoreCallbackAuthenticity(array $config, array $payload, string $sourceIp, array $headers = []): array
    {
        $score = 0;
        $reasons = [];
        $normalizer = new WorkspaceVoiceConfigService();
        $expectedNumber = $normalizer->normalizePhone((string) ($config['virtual_number'] ?? ''));
        $payloadNumbers = array_values(array_filter(array_map(
            static fn(string $number): string => $normalizer->normalizePhone($number),
            [
                (string) ($payload['destinationNumber'] ?? ''),
                (string) ($payload['callerNumber'] ?? ''),
                (string) ($payload['from'] ?? ''),
                (string) ($payload['to'] ?? ''),
            ]
        )));
        if ($expectedNumber !== '' && in_array($expectedNumber, $payloadNumbers, true)) {
            $score += 50;
        } else {
            $reasons[] = 'virtual_number_mismatch';
        }
        if (trim((string) ($payload['sessionId'] ?? $payload['SessionId'] ?? '')) !== '') {
            $score += 25;
        } else {
            $reasons[] = 'missing_session_id';
        }
        if ($sourceIp !== '') {
            $score += 5;
        }
        $allowedIps = (array) (($config['settings']['callback_ip_allowlist'] ?? []));
        if ($allowedIps !== []) {
            if (in_array($sourceIp, $allowedIps, true)) {
                $score += 20;
            } else {
                $reasons[] = 'source_ip_not_allowlisted';
            }
        } else {
            $score += 10;
            $reasons[] = 'provider_ip_allowlist_not_configured';
        }
        return ['accepted' => $score >= 75 && !in_array('virtual_number_mismatch', $reasons, true), 'score' => $score, 'reasons' => $reasons];
    }

    private function request(string $path, array $config, array $fields): array
    {
        return $this->requestUrl('POST', $this->voiceBaseUrl((string) ($config['account_username'] ?? '')) . $path, $config, $fields);
    }

    private function requestUrl(string $method, string $url, array $config, array $fields): array
    {
        $headers = ['Accept: application/json', 'apiKey: ' . (string) ($config['api_key'] ?? '')];
        if ($this->transport !== null) {
            return (array) call_user_func($this->transport, $method, $url, $fields, $headers);
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('cURL is required for Africa\'s Talking voice requests.');
        }
        $requestUrl = $method === 'GET' ? $url . '?' . http_build_query($fields) : $url;
        $handle = curl_init($requestUrl);
        $options = [
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($fields);
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($body === false || $error !== '') {
            throw new \RuntimeException('Voice provider connection failed.');
        }
        $result = $this->decodeResponse((string) $body);
        $result['http_status'] = $status;
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Voice provider rejected the request (HTTP ' . $status . ').');
        }
        return $result;
    }

    private function decodeResponse(string $body): array
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/<balance>\s*([^<]+)\s*<\/balance>/i', $body, $match)) {
            return ['balance' => trim($match[1]), 'UserData' => ['balance' => trim($match[1])]];
        }
        return ['raw' => substr($body, 0, 2000)];
    }

    private function voiceBaseUrl(string $username): string
    {
        return $this->isSandbox($username) ? self::SANDBOX_VOICE_BASE_URL : self::LIVE_VOICE_BASE_URL;
    }

    private function apiBaseUrl(string $username): string
    {
        return $this->isSandbox($username) ? self::SANDBOX_API_BASE_URL : self::LIVE_API_BASE_URL;
    }

    private function isSandbox(string $username): bool
    {
        return strtolower(trim($username)) === 'sandbox';
    }

    private function callbackReference(string $callbackUrl): string
    {
        $query = parse_url($callbackUrl, PHP_URL_QUERY);
        parse_str((string) $query, $values);
        return substr((string) ($values['call'] ?? ''), 0, 100);
    }

    private function validPhone(string $value): bool
    {
        return (bool) preg_match('/^\+[1-9][0-9]{7,14}$/', trim($value));
    }

    private function validSip(string $value): bool
    {
        return (bool) preg_match('/^sip:[^@\s]+@[^\s]+$/i', trim($value));
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xmlAttribute(string $value): string
    {
        return $this->xml($value);
    }
}
