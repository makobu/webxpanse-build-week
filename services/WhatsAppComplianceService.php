<?php

namespace CRM\Services;

use CRM\Database;

class WhatsAppComplianceService
{
    public function canSendToContact(int $workspaceId, int $contactId, string $category = 'utility'): array
    {
        if ($workspaceId <= 0 || $contactId <= 0) {
            return ['allowed' => false, 'reason' => 'missing_contact'];
        }

        $category = strtolower(trim($category));
        if (!in_array($category, ['marketing', 'utility', 'authentication', 'service'], true)) {
            $category = 'utility';
        }

        $contact = Database::queryOne(
            "SELECT id, phone, whatsapp_opt_in_status, whatsapp_allowed_categories, whatsapp_opt_out_at
             FROM contacts
             WHERE workspace_id = ? AND id = ?
             LIMIT 1",
            [$workspaceId, $contactId]
        );
        if (!$contact) {
            return ['allowed' => false, 'reason' => 'contact_not_found'];
        }

        $phone = $this->normalizePhone((string) ($contact['phone'] ?? ''));
        if ($phone === '') {
            return ['allowed' => false, 'reason' => 'missing_phone'];
        }

        if ($this->isSuppressed($workspaceId, $phone)) {
            return ['allowed' => false, 'reason' => 'suppressed'];
        }

        if ((string) ($contact['whatsapp_opt_in_status'] ?? 'unknown') === 'opted_out' || !empty($contact['whatsapp_opt_out_at'])) {
            return ['allowed' => false, 'reason' => 'opted_out'];
        }

        if ($category === 'marketing') {
            $allowedCategories = json_decode((string) ($contact['whatsapp_allowed_categories'] ?? '[]'), true);
            $allowedCategories = is_array($allowedCategories) ? array_map('strtolower', $allowedCategories) : [];
            $hasMarketingConsent = (string) ($contact['whatsapp_opt_in_status'] ?? 'unknown') === 'opted_in'
                && ($allowedCategories === [] || in_array('marketing', $allowedCategories, true));
            if (!$hasMarketingConsent) {
                return ['allowed' => false, 'reason' => 'marketing_opt_in_required'];
            }
        }

        return ['allowed' => true, 'reason' => 'allowed', 'phone' => $phone, 'category' => $category];
    }

    public function assertCanSend(int $workspaceId, int $contactId, string $category = 'utility'): void
    {
        $result = $this->canSendToContact($workspaceId, $contactId, $category);
        if (empty($result['allowed'])) {
            throw new \RuntimeException($this->messageForReason((string) ($result['reason'] ?? 'blocked')));
        }
    }

    public function optInContact(int $workspaceId, int $contactId, string $source, array $categories = ['utility', 'authentication', 'marketing'], string $evidence = ''): void
    {
        if ($workspaceId <= 0 || $contactId <= 0 || !Database::columnExists('contacts', 'whatsapp_opt_in_status')) {
            return;
        }

        Database::execute(
            "UPDATE contacts
             SET whatsapp_opt_in_status = 'opted_in',
                 whatsapp_opt_in_source = ?,
                 whatsapp_opt_in_at = NOW(),
                 whatsapp_allowed_categories = ?,
                 whatsapp_opt_out_at = NULL,
                 whatsapp_consent_evidence = ?
             WHERE workspace_id = ? AND id = ?",
            [
                substr($source, 0, 100),
                json_encode(array_values(array_unique(array_map('strtolower', $categories))), JSON_UNESCAPED_SLASHES),
                $evidence !== '' ? $evidence : null,
                $workspaceId,
                $contactId,
            ]
        );
    }

    public function optOutContact(int $workspaceId, int $contactId, string $source = 'inbound_keyword', string $evidence = ''): void
    {
        if ($workspaceId <= 0 || $contactId <= 0 || !Database::columnExists('contacts', 'whatsapp_opt_in_status')) {
            return;
        }

        $contact = Database::queryOne(
            "SELECT phone FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $contactId]
        );
        $phone = $this->normalizePhone((string) ($contact['phone'] ?? ''));

        Database::execute(
            "UPDATE contacts
             SET whatsapp_opt_in_status = 'opted_out',
                 whatsapp_opt_out_at = NOW(),
                 whatsapp_consent_evidence = ?
             WHERE workspace_id = ? AND id = ?",
            [$evidence !== '' ? $evidence : null, $workspaceId, $contactId]
        );
        if ($phone !== '') {
            $this->suppressPhone($workspaceId, $phone, 'Contact opted out via ' . $source, $source, null, ['contact_id' => $contactId, 'evidence' => $evidence]);
        }
    }

    public function detectAndApplyInboundOptOut(int $workspaceId, int $contactId, string $body): bool
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', $body) ?? $body));
        if ($normalized === '') {
            return false;
        }
        $keywords = ['stop', 'unsubscribe', 'opt out', 'opt-out', 'cancel messages', 'no more messages'];
        foreach ($keywords as $keyword) {
            if ($normalized === $keyword || str_starts_with($normalized, $keyword . ' ')) {
                $this->optOutContact($workspaceId, $contactId, 'inbound_keyword', $body);
                return true;
            }
        }

        return false;
    }

    public function suppressPhone(int $workspaceId, string $phone, string $reason, string $source = 'manual', ?int $userId = null, array $metadata = []): void
    {
        if (!Database::tableExists('workspace_whatsapp_suppression_list')) {
            return;
        }
        $phone = $this->normalizePhone($phone);
        if ($workspaceId <= 0 || $phone === '') {
            return;
        }

        if (Database::columnExists('workspace_whatsapp_suppression_list', 'active_phone_key')) {
            Database::execute(
                "INSERT INTO workspace_whatsapp_suppression_list
                    (workspace_id, phone_number, active_phone_key, reason, source, suppressed_by_user_id, suppressed_at, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                 ON DUPLICATE KEY UPDATE
                    phone_number = VALUES(phone_number),
                    reason = VALUES(reason),
                    source = VALUES(source),
                    suppressed_by_user_id = VALUES(suppressed_by_user_id),
                    suppressed_at = NOW(),
                    released_at = NULL,
                    active_phone_key = VALUES(active_phone_key),
                    metadata_json = VALUES(metadata_json)",
                [
                    $workspaceId,
                    $phone,
                    $phone,
                    substr($reason, 0, 191),
                    substr($source, 0, 100),
                    $userId && $userId > 0 ? $userId : null,
                    $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
                ]
            );
            return;
        }

        Database::execute(
            "INSERT INTO workspace_whatsapp_suppression_list
                (workspace_id, phone_number, reason, source, suppressed_by_user_id, suppressed_at, metadata_json)
             VALUES (?, ?, ?, ?, ?, NOW(), ?)
             ON DUPLICATE KEY UPDATE
                reason = VALUES(reason),
                source = VALUES(source),
                suppressed_by_user_id = VALUES(suppressed_by_user_id),
                suppressed_at = NOW(),
                released_at = NULL,
                metadata_json = VALUES(metadata_json)",
            [
                $workspaceId,
                $phone,
                substr($reason, 0, 191),
                substr($source, 0, 100),
                $userId && $userId > 0 ? $userId : null,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    public function releaseSuppression(int $workspaceId, string $phone, string $reason = 'manual_release'): void
    {
        if (!Database::tableExists('workspace_whatsapp_suppression_list')) {
            return;
        }
        $phone = $this->normalizePhone($phone);
        if ($workspaceId <= 0 || $phone === '') {
            return;
        }

        $metadata = json_encode([
            'release_reason' => substr($reason, 0, 191),
            'released_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES);

        if (Database::columnExists('workspace_whatsapp_suppression_list', 'active_phone_key')) {
            Database::execute(
                "UPDATE workspace_whatsapp_suppression_list
                 SET released_at = NOW(),
                     active_phone_key = NULL,
                     metadata_json = JSON_MERGE_PATCH(COALESCE(metadata_json, JSON_OBJECT()), ?)
                 WHERE workspace_id = ?
                   AND active_phone_key = ?",
                [$metadata, $workspaceId, $phone]
            );
            return;
        }

        Database::execute(
            "UPDATE workspace_whatsapp_suppression_list
             SET released_at = NOW(),
                 metadata_json = JSON_MERGE_PATCH(COALESCE(metadata_json, JSON_OBJECT()), ?)
             WHERE workspace_id = ?
               AND phone_number = ?
               AND released_at IS NULL",
            [$metadata, $workspaceId, $phone]
        );
    }

    public function isSuppressed(int $workspaceId, string $phone): bool
    {
        if (!Database::tableExists('workspace_whatsapp_suppression_list')) {
            return false;
        }
        $phone = $this->normalizePhone($phone);
        if ($workspaceId <= 0 || $phone === '') {
            return false;
        }

        if (Database::columnExists('workspace_whatsapp_suppression_list', 'active_phone_key')) {
            return (bool) Database::queryOne(
                "SELECT 1
                 FROM workspace_whatsapp_suppression_list
                 WHERE workspace_id = ?
                   AND active_phone_key = ?
                 LIMIT 1",
                [$workspaceId, $phone]
            );
        }

        return (bool) Database::queryOne(
            "SELECT 1
             FROM workspace_whatsapp_suppression_list
             WHERE workspace_id = ?
               AND phone_number = ?
               AND released_at IS NULL
             LIMIT 1",
            [$workspaceId, $phone]
        );
    }

    public function messageForReason(string $reason): string
    {
        return match ($reason) {
            'suppressed' => 'This contact is on the WhatsApp suppression list.',
            'opted_out' => 'This contact has opted out of WhatsApp messages.',
            'marketing_opt_in_required' => 'Marketing WhatsApp templates require recorded opt-in consent.',
            'missing_phone' => 'This contact does not have a WhatsApp-capable phone number.',
            'contact_not_found' => 'Contact was not found in this workspace.',
            default => 'WhatsApp send is blocked by workspace guardrails.',
        };
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }
}
