<?php

namespace CRM\Services;

use CRM\Database;

class MobilePushNotificationService
{
    public function sendForNotification(int $notificationId): void
    {
        $notification = Database::queryOne('SELECT * FROM notifications WHERE id = ? LIMIT 1', [$notificationId]);
        if (!$notification) {
            return;
        }

        $tokens = Database::query(
            "SELECT *
             FROM mobile_auth_tokens
             WHERE user_id = ?
               AND revoked_at IS NULL
               AND refresh_expires_at > NOW()
               AND push_token IS NOT NULL
               AND push_token <> ''
               AND COALESCE(push_provider, 'fcm') = 'fcm'
               AND push_disabled_at IS NULL",
            [(int) $notification['user_id']]
        );

        foreach ($tokens as $token) {
            $this->sendToToken($notification, $token);
        }
    }

    private function sendToToken(array $notification, array $token): void
    {
        $route = $this->resolveRoute($notification);
        $payload = [
            'message' => [
                'token' => (string) ($token['push_token'] ?? ''),
                'notification' => [
                    'title' => (string) ($notification['title'] ?? ''),
                    'body' => (string) ($notification['message'] ?? ''),
                ],
                'data' => array_filter([
                    'notification_id' => (string) ((int) ($notification['id'] ?? 0)),
                    'route' => $route,
                    'entity_type' => (string) ($notification['entity_type'] ?? ''),
                    'entity_id' => !empty($notification['entity_id']) ? (string) ((int) $notification['entity_id']) : null,
                    'link' => !empty($notification['link']) ? (string) $notification['link'] : null,
                ], static fn ($value) => $value !== null && $value !== ''),
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                    ],
                ],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => 1,
                        ],
                    ],
                ],
            ],
        ];

        $logId = $this->createDeliveryLog($notification, $token, $route, $payload);

        try {
            $accessTokens = new FirebaseAccessTokenService();
            $projectId = $accessTokens->projectId();
            if ($projectId === '') {
                throw new \RuntimeException('FCM project id is not configured.');
            }

            $accessToken = $accessTokens->getAccessToken();
            $response = $this->postJson(
                sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', rawurlencode($projectId)),
                $payload,
                [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json; charset=utf-8',
                ]
            );

            $responsePayload = json_decode($response, true);
            Database::execute(
                "UPDATE mobile_push_deliveries
                 SET status = 'sent',
                     provider_message_id = ?,
                     attempted_at = NOW()
                 WHERE id = ?",
                [
                    is_array($responsePayload) ? (string) ($responsePayload['name'] ?? '') : '',
                    $logId,
                ]
            );
            Database::execute(
                "UPDATE mobile_auth_tokens
                 SET last_push_sent_at = NOW(),
                     last_push_error = NULL
                 WHERE id = ?",
                [(int) $token['id']]
            );
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $status = $this->isInvalidTokenError($message) ? 'invalid_token' : 'failed';
            Database::execute(
                "UPDATE mobile_push_deliveries
                 SET status = ?,
                     provider_error_message = ?,
                     attempted_at = NOW()
                 WHERE id = ?",
                [$status, $message, $logId]
            );
            Database::execute(
                "UPDATE mobile_auth_tokens
                 SET last_push_error = ?,
                     push_disabled_at = CASE WHEN ? = 'invalid_token' THEN NOW() ELSE push_disabled_at END
                 WHERE id = ?",
                [$message, $status, (int) $token['id']]
            );
            error_log('Mobile push delivery failed for notification_id=' . (int) ($notification['id'] ?? 0) . ' token_id=' . (int) ($token['id'] ?? 0) . ': ' . $message);
        }
    }

    private function createDeliveryLog(array $notification, array $token, ?string $route, array $payload): int
    {
        Database::execute(
            "INSERT INTO mobile_push_deliveries
                (user_id, token_id, notification_id, provider, target_route, title, body, payload_json, status)
             VALUES (?, ?, ?, 'fcm', ?, ?, ?, ?, 'pending')",
            [
                (int) ($notification['user_id'] ?? 0),
                (int) ($token['id'] ?? 0),
                (int) ($notification['id'] ?? 0),
                $route,
                (string) ($notification['title'] ?? ''),
                (string) ($notification['message'] ?? ''),
                json_encode($payload, JSON_UNESCAPED_SLASHES),
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function resolveRoute(array $notification): ?string
    {
        $entityType = (string) ($notification['entity_type'] ?? '');
        $entityId = !empty($notification['entity_id']) ? (int) $notification['entity_id'] : 0;
        if ($entityId > 0) {
            return match ($entityType) {
                'deal' => '/deals/' . $entityId,
                'task' => '/tasks/' . $entityId,
                'contact' => '/contacts/' . $entityId,
                'communication', 'conversation' => '/inbox/' . $entityId,
                'voice_call' => '/calls/' . $entityId,
                default => null,
            };
        }

        $link = (string) ($notification['link'] ?? '');
        if ($link !== '') {
            if (preg_match('/deal_view\.php\?id=(\d+)/i', $link, $matches)) {
                return '/deals/' . (int) $matches[1];
            }
            if (preg_match('/task_view\.php\?id=(\d+)/i', $link, $matches)) {
                return '/tasks/' . (int) $matches[1];
            }
            if (preg_match('/contact_view\.php\?id=(\d+)/i', $link, $matches)) {
                return '/contacts/' . (int) $matches[1];
            }
            if (preg_match('/conversation\.php\?id=(\d+)/i', $link, $matches)) {
                return '/inbox/' . (int) $matches[1];
            }
            if (preg_match('/call_center\.php\?(?:[^#]*&)?call_id=(\d+)/i', $link, $matches)) {
                return '/calls/' . (int) $matches[1];
            }
        }

        return '/notifications';
    }

    private function postJson(string $url, array $payload, array $headers): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Could not initialize FCM request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('FCM push request failed: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException('FCM push request failed with status ' . $httpCode . ': ' . $response);
        }

        return (string) $response;
    }

    private function isInvalidTokenError(string $message): bool
    {
        $normalized = strtolower($message);
        return str_contains($normalized, 'registration-token-not-registered')
            || str_contains($normalized, 'unregistered')
            || str_contains($normalized, 'invalid registration token');
    }
}
