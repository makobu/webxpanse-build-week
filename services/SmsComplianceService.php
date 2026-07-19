<?php

namespace CRM\Services;

use CRM\Database;

final class SmsComplianceService
{
    private const OPT_OUT_WORDS = ['stop', 'stopall', 'unsubscribe', 'cancel', 'end', 'quit'];

    public function assertCanSend(int $workspaceId, string $phone): string
    {
        $normalized = $this->normalizePhone($phone);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required for SMS compliance checks.');
        }
        if ($this->isSuppressed($workspaceId, $normalized)) {
            throw new \RuntimeException('Recipient is suppressed from SMS in this workspace.');
        }
        return $normalized;
    }

    public function isSuppressed(int $workspaceId, string $phone): bool
    {
        if ($workspaceId <= 0 || !Database::tableExists('marketing_suppression_entries')) {
            return false;
        }
        $identifier = $this->normalizePhone($phone);
        $channelHash = hash('sha256', 'sms:' . strtolower($identifier));
        $allHash = hash('sha256', 'all:' . strtolower($identifier));
        $rawHash = hash('sha256', strtolower($identifier));

        return (bool) Database::queryOne(
            "SELECT id
             FROM marketing_suppression_entries
             WHERE workspace_id = ?
               AND status = 'active'
               AND channel IN ('sms', 'all')
               AND (identifier_hash IN (?, ?, ?) OR LOWER(identifier) = LOWER(?))
             LIMIT 1",
            [$workspaceId, $channelHash, $allHash, $rawHash, $identifier]
        );
    }

    public function isOptOutMessage(string $body): bool
    {
        $word = strtolower(trim(preg_replace('/\s+/', ' ', $body) ?? ''));
        return in_array($word, self::OPT_OUT_WORDS, true);
    }

    public function recordInboundOptOut(int $workspaceId, string $phone, string $providerMessageId = ''): bool
    {
        if ($workspaceId <= 0 || !Database::tableExists('marketing_suppression_entries')) {
            return false;
        }
        $identifier = $this->normalizePhone($phone);
        Database::execute(
            "INSERT INTO marketing_suppression_entries
                (workspace_id, uuid, channel, identifier, identifier_hash, reason, status, source, metadata_json, created_by)
             VALUES (?, ?, 'sms', ?, ?, 'Recipient opted out by SMS reply.', 'active', 'sms_webhook', ?, NULL)
             ON DUPLICATE KEY UPDATE
                reason = VALUES(reason), status = 'active', source = VALUES(source),
                metadata_json = VALUES(metadata_json), updated_at = NOW()",
            [
                $workspaceId,
                $this->uuid(),
                $identifier,
                hash('sha256', 'sms:' . strtolower($identifier)),
                json_encode(['provider_message_id' => $providerMessageId], JSON_UNESCAPED_SLASHES),
            ]
        );
        return true;
    }

    public function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', trim($phone)) ?? '';
        if (strlen($digits) === 10) {
            $digits = '1' . $digits;
        }
        if (strlen($digits) < 8 || strlen($digits) > 15 || $digits[0] === '0') {
            throw new \InvalidArgumentException('Phone number must be a valid E.164 number.');
        }
        return '+' . $digits;
    }

    private function uuid(): string
    {
        if (function_exists('uuid_v4')) {
            return uuid_v4();
        }
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
