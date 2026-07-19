<?php
/**
 * Shared Gmail API OAuth transport for personal Gmail and Google Workspace mailboxes.
 */

namespace CRM\Services;

class GoogleOAuthMailService
{
    private string $providerKey;
    private string $providerLabel;
    private string $legacyClientIdEnvKey;
    private string $legacyClientSecretEnvKey;
    private string $legacyRedirectEnvKey;
    private string $fallbackClientIdEnvKey;
    private string $fallbackClientSecretEnvKey;
    private string $fallbackRedirectEnvKey;
    private string $defaultCallbackPath;
    private string $defaultGrantType;
    private string $stateSessionKey;
    private string $userSessionKey;
    private string $gmailApiBase = 'https://gmail.googleapis.com/gmail/v1/users/me';

    /**
     * @param array<string,string> $config
     */
    public function __construct(array $config)
    {
        $this->providerKey = trim((string) ($config['provider_key'] ?? 'google_oauth'));
        $this->providerLabel = trim((string) ($config['provider_label'] ?? 'Google mail'));

        $this->legacyClientIdEnvKey = trim((string) ($config['client_id_env_key'] ?? ''));
        $this->legacyClientSecretEnvKey = trim((string) ($config['client_secret_env_key'] ?? ''));
        $this->legacyRedirectEnvKey = trim((string) ($config['redirect_env_key'] ?? ''));
        $this->fallbackClientIdEnvKey = trim((string) ($config['client_id_fallback_env_key'] ?? ''));
        $this->fallbackClientSecretEnvKey = trim((string) ($config['client_secret_fallback_env_key'] ?? ''));
        $this->fallbackRedirectEnvKey = trim((string) ($config['redirect_fallback_env_key'] ?? ''));
        $this->defaultCallbackPath = trim((string) ($config['default_callback_path'] ?? ''));
        $this->defaultGrantType = GoogleOAuthScopeCatalog::normalizeGrantType((string) ($config['default_grant_type'] ?? GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND));

        $this->stateSessionKey = trim((string) ($config['state_session_key'] ?? 'google_mail_state'));
        $this->userSessionKey = trim((string) ($config['user_session_key'] ?? 'google_mail_user_id'));
    }

    public function getProviderKey(): string
    {
        return $this->providerKey;
    }

    public function getProviderLabel(): string
    {
        return $this->providerLabel;
    }

    public function isConfigured(?string $grantType = null): bool
    {
        $client = $this->clientForGrant($grantType ?? $this->defaultGrantType);
        return $client['client_id'] !== '' && $client['client_secret'] !== '';
    }

    public function getRedirectUri(?string $grantType = null): string
    {
        return $this->clientForGrant($grantType ?? $this->defaultGrantType)['redirect_uri'];
    }

    public function getAuthUrl(int $userId, string $grantType = GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND): string
    {
        $grantType = GoogleOAuthScopeCatalog::normalizeGrantType($grantType);
        $catalog = GoogleOAuthScopeCatalog::forGrant($grantType);
        $client = $this->clientForGrant($grantType);
        if ($client['client_id'] === '' || $client['client_secret'] === '') {
            throw new \RuntimeException($this->providerLabel . ' OAuth is not configured.');
        }

        $state = bin2hex(random_bytes(16));
        $_SESSION[$this->stateSessionKey] = [
            'state' => $state,
            'user_id' => $userId,
            'grant_type' => $catalog['grant_type'],
            'requested_scopes' => $catalog['scopes'],
            'oauth_client_key' => $catalog['client_key'],
        ];
        $_SESSION[$this->userSessionKey] = $userId;

        $params = [
            'client_id' => $client['client_id'],
            'redirect_uri' => $client['redirect_uri'],
            'response_type' => 'code',
            'scope' => implode(' ', $catalog['scopes']),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * @return array{user_id:int,grant_type:string,requested_scopes:array<int,string>,oauth_client_key:string}
     */
    public function consumeAuthorizedContext(string $state): array
    {
        $state = trim($state);
        $stored = $_SESSION[$this->stateSessionKey] ?? null;
        $legacyUserId = (int) ($_SESSION[$this->userSessionKey] ?? 0);
        unset($_SESSION[$this->stateSessionKey], $_SESSION[$this->userSessionKey]);

        if (is_array($stored)) {
            $expectedState = trim((string) ($stored['state'] ?? ''));
            $userId = (int) ($stored['user_id'] ?? $legacyUserId);
            $grantType = GoogleOAuthScopeCatalog::normalizeGrantType((string) ($stored['grant_type'] ?? GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND));
            $catalog = GoogleOAuthScopeCatalog::forGrant($grantType);

            if ($state === '' || $state !== $expectedState) {
                throw new \RuntimeException('Invalid OAuth state for ' . $this->providerLabel . '.');
            }
            if ($userId <= 0) {
                throw new \RuntimeException('The OAuth user context for ' . $this->providerLabel . ' is missing.');
            }

            return [
                'user_id' => $userId,
                'grant_type' => $grantType,
                'requested_scopes' => GoogleOAuthScopeCatalog::parseScopes($stored['requested_scopes'] ?? $catalog['scopes']),
                'oauth_client_key' => trim((string) ($stored['oauth_client_key'] ?? $catalog['client_key'])),
            ];
        }

        $expectedState = trim((string) $stored);
        if ($state === '' || $state !== $expectedState) {
            throw new \RuntimeException('Invalid OAuth state for ' . $this->providerLabel . '.');
        }
        if ($legacyUserId <= 0) {
            throw new \RuntimeException('The OAuth user context for ' . $this->providerLabel . ' is missing.');
        }

        $catalog = GoogleOAuthScopeCatalog::forGrant(GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND);
        return [
            'user_id' => $legacyUserId,
            'grant_type' => GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND,
            'requested_scopes' => $catalog['scopes'],
            'oauth_client_key' => $catalog['client_key'],
        ];
    }

    public function consumeAuthorizedUserId(string $state): int
    {
        return $this->consumeAuthorizedContext($state)['user_id'];
    }

    public function exchangeCodeForTokens(string $code, string $grantType = GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND): array
    {
        $client = $this->clientForGrant($grantType);
        return $this->requestToken([
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $client['redirect_uri'],
        ]);
    }

    public function refreshToken(string $refreshToken, string $grantType = GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND): array
    {
        $client = $this->clientForGrant($grantType);
        return $this->requestToken([
            'client_id' => $client['client_id'],
            'client_secret' => $client['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);
    }

    public function getAuthenticatedProfile(string $accessToken): array
    {
        try {
            return $this->apiRequest('GET', $this->gmailApiBase . '/profile', $accessToken);
        } catch (\RuntimeException $e) {
            $profile = $this->apiRequest('GET', 'https://www.googleapis.com/oauth2/v3/userinfo', $accessToken);
            $email = trim((string) ($profile['email'] ?? ''));
            if ($email === '') {
                throw $e;
            }

            return [
                'emailAddress' => $email,
                'messagesTotal' => 0,
                'threadsTotal' => 0,
                'historyId' => '',
            ];
        }
    }

    public function sendMessage(string $accessToken, array $message): array
    {
        $rawMessage = $this->buildRawMimeMessage($message);

        return $this->apiRequest(
            'POST',
            $this->gmailApiBase . '/messages/send',
            $accessToken,
            ['raw' => $this->base64UrlEncode($rawMessage)]
        );
    }

    public function listHistory(string $accessToken, string $startHistoryId, ?string $pageToken = null): array
    {
        $query = [
            'startHistoryId' => $startHistoryId,
            'historyTypes' => 'messageAdded',
            'maxResults' => 100,
        ];
        if ($pageToken) {
            $query['pageToken'] = $pageToken;
        }

        return $this->apiRequest(
            'GET',
            $this->gmailApiBase . '/history?' . http_build_query($query),
            $accessToken
        );
    }

    public function listInboxMessages(string $accessToken, ?string $pageToken = null, int $maxResults = 25): array
    {
        $query = [
            'labelIds' => 'INBOX',
            'maxResults' => max(1, min(100, $maxResults)),
        ];
        if ($pageToken) {
            $query['pageToken'] = $pageToken;
        }

        return $this->apiRequest(
            'GET',
            $this->gmailApiBase . '/messages?' . http_build_query($query),
            $accessToken
        );
    }

    public function getMessage(string $accessToken, string $messageId, string $format = 'full'): array
    {
        $query = ['format' => $format];
        return $this->apiRequest(
            'GET',
            $this->gmailApiBase . '/messages/' . rawurlencode($messageId) . '?' . http_build_query($query),
            $accessToken
        );
    }

    public function parseMessageToEmailData(array $message): ?array
    {
        $payload = $message['payload'] ?? [];
        if (!is_array($payload)) {
            return null;
        }

        $headers = $this->extractHeaders($payload['headers'] ?? []);
        $messageId = trim((string) ($headers['message-id'] ?? ''));
        $fromHeader = trim((string) ($headers['from'] ?? ''));
        $toHeader = trim((string) ($headers['to'] ?? ''));

        $fromEmail = $this->extractEmailAddress($fromHeader);
        if ($fromEmail === '') {
            return null;
        }

        [$htmlBody, $plainBody] = $this->extractBodies($payload);
        if ($htmlBody === '' && $plainBody === '') {
            $plainBody = trim((string) ($message['snippet'] ?? ''));
            $htmlBody = $plainBody !== '' ? nl2br(htmlspecialchars($plainBody, ENT_QUOTES, 'UTF-8')) : '';
        }

        if ($plainBody === '' && $htmlBody !== '') {
            $plainBody = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($htmlBody), ENT_QUOTES, 'UTF-8')));
        }
        if ($htmlBody === '' && $plainBody !== '') {
            $htmlBody = nl2br(htmlspecialchars($plainBody, ENT_QUOTES, 'UTF-8'));
        }

        $date = date('Y-m-d H:i:s');
        $dateHeader = trim((string) ($headers['date'] ?? ''));
        if ($dateHeader !== '') {
            try {
                $dateObject = new \DateTimeImmutable($dateHeader);
                $date = $dateObject->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                $timestamp = strtotime($dateHeader);
                if ($timestamp !== false) {
                    $date = date('Y-m-d H:i:s', $timestamp);
                }
            }
        }

        return [
            'message_id' => $messageId !== '' ? $messageId : null,
            'from_email' => strtolower($fromEmail),
            'to_email' => strtolower($this->extractEmailAddress($toHeader)),
            'from_name' => $this->extractDisplayName($fromHeader) ?: $fromEmail,
            'subject' => (string) ($headers['subject'] ?? ''),
            'body' => $plainBody,
            'body_html' => $htmlBody,
            'in_reply_to' => $this->normalizeMessageReference((string) ($headers['in-reply-to'] ?? '')),
            'date' => $date,
            'uid' => null,
            'internal_ts' => isset($message['internalDate']) ? (int) floor(((int) $message['internalDate']) / 1000) : time(),
            'gmail_message_id' => (string) ($message['id'] ?? ''),
            'gmail_thread_id' => (string) ($message['threadId'] ?? ''),
        ];
    }

    private function requestToken(array $data): array
    {
        if (trim((string) ($data['client_id'] ?? '')) === '' || trim((string) ($data['client_secret'] ?? '')) === '') {
            throw new \RuntimeException($this->providerLabel . ' OAuth is not configured.');
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new \RuntimeException($this->providerLabel . ' token request failed: ' . ($curlError ?: 'Unknown cURL error'));
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $error = is_array($decoded) ? json_encode($decoded) : $response;
            throw new \RuntimeException($this->providerLabel . ' OAuth error: ' . $error);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{client_id:string,client_secret:string,redirect_uri:string,client_key:string}
     */
    private function clientForGrant(string $grantType): array
    {
        $grantType = GoogleOAuthScopeCatalog::normalizeGrantType($grantType);
        $allowLegacyFallback = trim((string) ($_ENV['ALLOW_LEGACY_GOOGLE_OAUTH_FALLBACK'] ?? '')) === '1';

        if ($grantType === GoogleOAuthScopeCatalog::GRANT_LEGACY_COMBINED) {
            return $this->legacyClient();
        }

        $catalog = GoogleOAuthScopeCatalog::forGrant($grantType);
        $clientKey = (string) $catalog['client_key'];
        $clientId = $this->envValue($clientKey . '_CLIENT_ID');
        $clientSecret = $this->envValue($clientKey . '_CLIENT_SECRET');
        $redirectUri = $this->envValue($clientKey . '_REDIRECT_URI');

        if (($clientId === '' || $clientSecret === '') && $allowLegacyFallback) {
            $legacy = $this->legacyClient();
            $clientId = $clientId !== '' ? $clientId : $legacy['client_id'];
            $clientSecret = $clientSecret !== '' ? $clientSecret : $legacy['client_secret'];
            $redirectUri = $redirectUri !== '' ? $redirectUri : $legacy['redirect_uri'];
        }

        if ($redirectUri === '') {
            $legacyRedirect = $this->envValue($this->legacyRedirectEnvKey);
            if ($legacyRedirect !== '') {
                $redirectUri = $legacyRedirect;
            }
        }
        if ($redirectUri === '') {
            $redirectUri = $this->defaultRedirectUri();
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'client_key' => $clientKey,
        ];
    }

    /**
     * @return array{client_id:string,client_secret:string,redirect_uri:string,client_key:string}
     */
    private function legacyClient(): array
    {
        $clientId = $this->envValue($this->legacyClientIdEnvKey);
        if ($clientId === '' && $this->fallbackClientIdEnvKey !== '') {
            $clientId = $this->envValue($this->fallbackClientIdEnvKey);
        }
        $clientSecret = $this->envValue($this->legacyClientSecretEnvKey);
        if ($clientSecret === '' && $this->fallbackClientSecretEnvKey !== '') {
            $clientSecret = $this->envValue($this->fallbackClientSecretEnvKey);
        }
        $redirectUri = $this->envValue($this->legacyRedirectEnvKey);
        if ($redirectUri === '' && $this->fallbackRedirectEnvKey !== '') {
            $redirectUri = $this->envValue($this->fallbackRedirectEnvKey);
        }
        if ($redirectUri === '') {
            $redirectUri = $this->defaultRedirectUri();
        }

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'client_key' => $this->legacyClientIdEnvKey !== '' ? preg_replace('/_CLIENT_ID$/', '', $this->legacyClientIdEnvKey) : 'LEGACY_GOOGLE_MAIL',
        ];
    }

    private function envValue(string $key): string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }

        return trim((string) ($_ENV[$key] ?? getenv($key) ?: ''));
    }

    private function defaultRedirectUri(): string
    {
        $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: 'http://localhost/crm'), '/');
        return $appUrl . $this->defaultCallbackPath;
    }

    private function apiRequest(string $method, string $url, string $accessToken, ?array $jsonPayload = null): array
    {
        $headers = [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($jsonPayload !== null) {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = json_encode($jsonPayload, JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            throw new \RuntimeException($this->providerLabel . ' API request failed: ' . ($curlError ?: 'Unknown cURL error'));
        }

        $decoded = json_decode($response, true);
        if ($httpCode >= 400) {
            $error = is_array($decoded) ? json_encode($decoded) : $response;
            throw new \RuntimeException($this->providerLabel . ' API error: ' . $error);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function buildRawMimeMessage(array $message): string
    {
        $to = trim((string) ($message['to'] ?? ''));
        $fromEmail = trim((string) ($message['authenticated_email'] ?? $message['from_email'] ?? ''));
        $fromName = trim((string) ($message['from_name'] ?? ''));
        $subject = trim((string) ($message['subject'] ?? ''));
        $bodyText = (string) ($message['body_text'] ?? '');
        $bodyHtml = (string) ($message['body_html'] ?? '');
        $attachments = is_array($message['attachments'] ?? null) ? $message['attachments'] : [];
        $customHeaders = is_array($message['headers'] ?? null) ? $message['headers'] : [];

        $boundaryMixed = 'mixed-' . bin2hex(random_bytes(8));
        $boundaryAlternative = 'alt-' . bin2hex(random_bytes(8));

        $headers = [
            'MIME-Version: 1.0',
            sprintf('To: %s', $to),
            sprintf('From: %s <%s>', $this->encodeHeader($fromName !== '' ? $fromName : $fromEmail), $fromEmail),
            sprintf('Subject: %s', $this->encodeHeader($subject)),
        ];

        foreach ($customHeaders as $headerName => $headerValue) {
            $headerName = trim((string) $headerName);
            $headerValue = trim((string) $headerValue);
            if ($headerName !== '' && $headerValue !== '') {
                $headers[] = $headerName . ': ' . $headerValue;
            }
        }

        $parts = [];

        if ($attachments !== []) {
            $headers[] = sprintf('Content-Type: multipart/mixed; boundary="%s"', $boundaryMixed);
            $parts[] = '--' . $boundaryMixed;
            $parts[] = sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundaryAlternative);
            $parts[] = '';
        }

        if ($bodyText !== '') {
            $parts[] = '--' . $boundaryAlternative;
            $parts[] = 'Content-Type: text/plain; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $bodyText;
            $parts[] = '';
        }

        if ($bodyHtml !== '') {
            $parts[] = '--' . $boundaryAlternative;
            $parts[] = 'Content-Type: text/html; charset=UTF-8';
            $parts[] = 'Content-Transfer-Encoding: 8bit';
            $parts[] = '';
            $parts[] = $bodyHtml;
            $parts[] = '';
        }

        $parts[] = '--' . $boundaryAlternative . '--';

        if ($attachments !== []) {
            foreach ($attachments as $attachmentPath) {
                if (!is_string($attachmentPath) || $attachmentPath === '' || !is_file($attachmentPath)) {
                    continue;
                }

                $filename = basename($attachmentPath);
                $mimeType = mime_content_type($attachmentPath) ?: 'application/octet-stream';
                $content = chunk_split(base64_encode((string) file_get_contents($attachmentPath)));

                $parts[] = '--' . $boundaryMixed;
                $parts[] = sprintf('Content-Type: %s; name="%s"', $mimeType, $filename);
                $parts[] = 'Content-Transfer-Encoding: base64';
                $parts[] = sprintf('Content-Disposition: attachment; filename="%s"', $filename);
                $parts[] = '';
                $parts[] = $content;
                $parts[] = '';
            }

            $parts[] = '--' . $boundaryMixed . '--';
        } else {
            $headers[] = sprintf('Content-Type: multipart/alternative; boundary="%s"', $boundaryAlternative);
        }

        return implode("\r\n", array_merge($headers, [''], $parts));
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param array<int,array<string,mixed>> $headers
     * @return array<string,string>
     */
    private function extractHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $header) {
            if (!is_array($header)) {
                continue;
            }
            $name = strtolower(trim((string) ($header['name'] ?? '')));
            $value = trim((string) ($header['value'] ?? ''));
            if ($name !== '') {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    /**
     * @return array{0:string,1:string}
     */
    private function extractBodies(array $payload): array
    {
        $html = '';
        $plain = '';

        $mimeType = strtolower((string) ($payload['mimeType'] ?? ''));
        $body = (array) ($payload['body'] ?? []);
        $parts = is_array($payload['parts'] ?? null) ? $payload['parts'] : [];

        if ($mimeType === 'text/plain') {
            $plain = $this->decodeBodyData((string) ($body['data'] ?? ''));
        } elseif ($mimeType === 'text/html') {
            $html = $this->decodeBodyData((string) ($body['data'] ?? ''));
        }

        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            [$partHtml, $partPlain] = $this->extractBodies($part);
            if ($html === '' && $partHtml !== '') {
                $html = $partHtml;
            }
            if ($plain === '' && $partPlain !== '') {
                $plain = $partPlain;
            }
        }

        return [$html, $plain];
    }

    private function decodeBodyData(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'));
        return $decoded !== false ? $decoded : '';
    }

    private function extractEmailAddress(string $value): string
    {
        if (preg_match('/<([^>]+)>/', $value, $matches)) {
            return trim((string) $matches[1]);
        }

        return trim($value);
    }

    private function extractDisplayName(string $value): string
    {
        if (preg_match('/^(.*)<[^>]+>$/', $value, $matches)) {
            return trim(trim((string) $matches[1]), '" ');
        }

        return '';
    }

    private function normalizeMessageReference(string $value): ?string
    {
        $value = trim($value);
        return $value !== '' ? $value : null;
    }
}
