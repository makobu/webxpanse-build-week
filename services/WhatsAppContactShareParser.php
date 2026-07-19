<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Contacts;

class WhatsAppContactShareParser
{
    private Contacts $contacts;

    public function __construct(?Contacts $contacts = null)
    {
        $this->contacts = $contacts ?? new Contacts();
    }

    public function parse(array $message): ?array
    {
        $type = strtolower(trim((string) ($message['type'] ?? '')));

        if ($type === 'contacts') {
            return $this->parseMetaContactsPayload($message);
        }

        if ($type !== 'text') {
            return null;
        }

        $body = trim((string) ($message['text']['body'] ?? ''));
        if ($body === '') {
            return null;
        }

        if (stripos($body, 'BEGIN:VCARD') !== false) {
            return $this->parseTextVcard($body);
        }

        return $this->parseConservativePlainText($body);
    }

    public function resolveOrCreateContact(array $share, int $senderContactId = 0): array
    {
        $candidate = $share['contact'] ?? [];
        if (!is_array($candidate)) {
            return ['contact_id' => 0, 'created' => false];
        }

        $normalizedPhone = $this->normalizePhone((string) ($candidate['phone'] ?? ''));
        $email = $this->normalizeEmail((string) ($candidate['email'] ?? ''));

        $existing = null;
        if ($normalizedPhone !== '') {
            $existing = $this->findContactByNormalizedPhone($normalizedPhone);
        }
        if (!$existing && $email !== '') {
            $existing = Database::queryOne(
                "SELECT id
                 FROM contacts
                 WHERE LOWER(TRIM(email)) = ?
                 LIMIT 1",
                [$email]
            );
        }

        if ($existing) {
            return [
                'contact_id' => (int) ($existing['id'] ?? 0),
                'created' => false,
            ];
        }

        $firstName = trim((string) ($candidate['first_name'] ?? ''));
        $lastName = trim((string) ($candidate['last_name'] ?? ''));
        if ($firstName === '') {
            [$firstName, $lastName] = $this->splitName((string) ($candidate['full_name'] ?? ''));
        }
        if ($firstName === '') {
            $firstName = 'WhatsApp Contact';
        }

        $phoneForStorage = $normalizedPhone !== '' ? $normalizedPhone : trim((string) ($candidate['phone'] ?? ''));
        $emailForStorage = $email !== '' ? $email : $this->placeholderEmail($normalizedPhone !== '' ? $normalizedPhone : $phoneForStorage, $firstName, $senderContactId);

        $result = $this->contacts->create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $emailForStorage,
            'phone' => $phoneForStorage,
            'company' => trim((string) ($candidate['company'] ?? '')),
            'job_title' => trim((string) ($candidate['job_title'] ?? '')),
            'lead_source' => 'whatsapp',
        ]);

        if (($result['status'] ?? '') === 'success') {
            return [
                'contact_id' => (int) ($result['id'] ?? 0),
                'created' => true,
            ];
        }

        if (($result['status'] ?? '') === 'duplicate') {
            $matched = $result['matches']['phone'][0] ?? $result['matches']['email'][0] ?? null;
            if ($matched) {
                return [
                    'contact_id' => (int) ($matched['id'] ?? 0),
                    'created' => false,
                ];
            }
        }

        throw new \RuntimeException('Failed to create shared WhatsApp contact.');
    }

    public function buildSharedContactActivityDescription(array $share, string $senderPhone): string
    {
        $name = trim((string) ($share['contact']['display_name'] ?? $share['contact']['full_name'] ?? 'Shared contact'));
        $senderPhone = trim($senderPhone);
        $source = $senderPhone !== '' ? $senderPhone : 'unknown sender';

        return 'Added from inbound WhatsApp contact share by ' . $source . ': ' . $name;
    }

    private function parseMetaContactsPayload(array $message): ?array
    {
        $contacts = $message['contacts'] ?? [];
        if (!is_array($contacts) || $contacts === []) {
            return null;
        }

        $raw = $contacts[0];
        if (!is_array($raw)) {
            return null;
        }

        $nameBlock = is_array($raw['name'] ?? null) ? $raw['name'] : [];
        $phones = is_array($raw['phones'] ?? null) ? $raw['phones'] : [];
        $emails = is_array($raw['emails'] ?? null) ? $raw['emails'] : [];
        $org = is_array($raw['org'] ?? null) ? $raw['org'] : [];

        $fullName = trim((string) ($nameBlock['formatted_name'] ?? ''));
        $firstName = trim((string) ($nameBlock['first_name'] ?? ''));
        $lastName = trim((string) ($nameBlock['last_name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim($firstName . ' ' . $lastName);
        }
        if ($firstName === '') {
            [$firstName, $lastName] = $this->splitName($fullName);
        }

        $primaryPhone = '';
        $allPhones = [];
        foreach ($phones as $phone) {
            if (!is_array($phone)) {
                continue;
            }
            $value = trim((string) ($phone['phone'] ?? $phone['wa_id'] ?? ''));
            if ($value === '') {
                continue;
            }
            $normalized = $this->normalizePhone($value);
            if ($normalized === '') {
                continue;
            }
            if ($primaryPhone === '') {
                $primaryPhone = $normalized;
            }
            $allPhones[] = $normalized;
        }

        $primaryEmail = '';
        $allEmails = [];
        foreach ($emails as $email) {
            if (!is_array($email)) {
                continue;
            }
            $value = $this->normalizeEmail((string) ($email['email'] ?? ''));
            if ($value === '') {
                continue;
            }
            if ($primaryEmail === '') {
                $primaryEmail = $value;
            }
            $allEmails[] = $value;
        }

        if ($fullName === '' && $primaryPhone === '' && $primaryEmail === '') {
            return null;
        }

        $displayName = $fullName !== '' ? $fullName : 'Shared contact';
        $summary = 'Shared contact: ' . $displayName;
        if ($primaryPhone !== '') {
            $summary .= ' (' . $primaryPhone . ')';
        } elseif ($primaryEmail !== '') {
            $summary .= ' (' . $primaryEmail . ')';
        }

        return [
            'message_type' => 'contacts',
            'detection_source' => 'meta_contacts',
            'confidence' => 'high',
            'body' => $summary,
            'contact' => [
                'display_name' => $displayName,
                'full_name' => $fullName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $primaryPhone,
                'email' => $primaryEmail,
                'phones' => array_values(array_unique($allPhones)),
                'emails' => array_values(array_unique($allEmails)),
                'company' => trim((string) ($org['company'] ?? '')),
                'job_title' => trim((string) ($org['title'] ?? '')),
                'raw_payload' => $raw,
            ],
        ];
    }

    private function parseTextVcard(string $body): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        if (!is_array($lines) || $lines === []) {
            return null;
        }

        $fullName = '';
        $firstName = '';
        $lastName = '';
        $primaryPhone = '';
        $primaryEmail = '';
        $company = '';
        $jobTitle = '';
        $phones = [];
        $emails = [];

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '') {
                continue;
            }

            if (preg_match('/^FN:(.+)$/i', $line, $matches)) {
                $fullName = trim($matches[1]);
                continue;
            }
            if (preg_match('/^N:([^;]*);([^;]*)/i', $line, $matches)) {
                $lastName = trim($matches[1]);
                $firstName = trim($matches[2]);
                continue;
            }
            if (preg_match('/^TEL[^:]*:(.+)$/i', $line, $matches)) {
                $phone = $this->normalizePhone($matches[1]);
                if ($phone !== '') {
                    if ($primaryPhone === '') {
                        $primaryPhone = $phone;
                    }
                    $phones[] = $phone;
                }
                continue;
            }
            if (preg_match('/^EMAIL[^:]*:(.+)$/i', $line, $matches)) {
                $email = $this->normalizeEmail($matches[1]);
                if ($email !== '') {
                    if ($primaryEmail === '') {
                        $primaryEmail = $email;
                    }
                    $emails[] = $email;
                }
                continue;
            }
            if (preg_match('/^ORG:(.+)$/i', $line, $matches)) {
                $company = trim($matches[1]);
                continue;
            }
            if (preg_match('/^TITLE:(.+)$/i', $line, $matches)) {
                $jobTitle = trim($matches[1]);
            }
        }

        if ($fullName === '') {
            $fullName = trim($firstName . ' ' . $lastName);
        }
        if ($firstName === '') {
            [$firstName, $lastName] = $this->splitName($fullName);
        }
        if ($fullName === '' && $primaryPhone === '' && $primaryEmail === '') {
            return null;
        }

        $displayName = $fullName !== '' ? $fullName : 'Shared contact';
        $summary = 'Shared contact: ' . $displayName;
        if ($primaryPhone !== '') {
            $summary .= ' (' . $primaryPhone . ')';
        } elseif ($primaryEmail !== '') {
            $summary .= ' (' . $primaryEmail . ')';
        }

        return [
            'message_type' => 'contact_share',
            'detection_source' => 'plain_text_inferred',
            'confidence' => 'high',
            'body' => $summary,
            'contact' => [
                'display_name' => $displayName,
                'full_name' => $fullName,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $primaryPhone,
                'email' => $primaryEmail,
                'phones' => array_values(array_unique($phones)),
                'emails' => array_values(array_unique($emails)),
                'company' => $company,
                'job_title' => $jobTitle,
                'raw_payload' => ['vcard' => $body],
            ],
        ];
    }

    private function parseConservativePlainText(string $body): ?array
    {
        $trimmed = trim($body);
        if ($trimmed === '' || strlen($trimmed) > 320) {
            return null;
        }

        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $trimmed) ?: []), static fn ($line) => $line !== ''));
        if (count($lines) < 2 || count($lines) > 6) {
            return null;
        }

        if (preg_match_all('/[.!?](?:\s|$)/', $trimmed) > 1) {
            return null;
        }

        $phoneMatches = [];
        preg_match_all('/(?:\+?\d[\d\s\-\(\)]{7,}\d)/', $trimmed, $phoneMatches);
        $phones = array_values(array_filter(array_map([$this, 'normalizePhone'], $phoneMatches[0] ?? [])));

        $emailMatches = [];
        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $trimmed, $emailMatches);
        $emails = array_values(array_filter(array_map([$this, 'normalizeEmail'], $emailMatches[0] ?? [])));

        if ($phones === [] && $emails === []) {
            return null;
        }

        $nameLine = $lines[0];
        $nameLine = preg_replace('/^(name|contact)\s*:\s*/i', '', $nameLine);
        if (!preg_match('/[A-Za-z]/', $nameLine)) {
            return null;
        }

        $alphaWords = preg_split('/\s+/', trim((string) preg_replace('/[^A-Za-z\s]/', ' ', $nameLine))) ?: [];
        $alphaWords = array_values(array_filter($alphaWords, static fn ($part) => $part !== ''));
        if (count($alphaWords) < 2 && !preg_match('/^[A-Za-z][A-Za-z\s\'\-]{2,}$/', $nameLine)) {
            return null;
        }

        [$firstName, $lastName] = $this->splitName($nameLine);
        $company = '';
        $jobTitle = '';

        foreach ($lines as $line) {
            if (preg_match('/^(company|org|organization)\s*:\s*(.+)$/i', $line, $matches)) {
                $company = trim($matches[2]);
            } elseif (preg_match('/^(title|job|role)\s*:\s*(.+)$/i', $line, $matches)) {
                $jobTitle = trim($matches[2]);
            }
        }

        $primaryPhone = $phones[0] ?? '';
        $primaryEmail = $emails[0] ?? '';
        $displayName = trim($firstName . ' ' . $lastName);
        if ($displayName === '') {
            $displayName = trim($nameLine);
        }

        $summary = 'Shared contact: ' . $displayName;
        if ($primaryPhone !== '') {
            $summary .= ' (' . $primaryPhone . ')';
        } elseif ($primaryEmail !== '') {
            $summary .= ' (' . $primaryEmail . ')';
        }

        return [
            'message_type' => 'contact_share',
            'detection_source' => 'plain_text_inferred',
            'confidence' => 'medium',
            'body' => $summary,
            'contact' => [
                'display_name' => $displayName,
                'full_name' => trim($nameLine),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => $primaryPhone,
                'email' => $primaryEmail,
                'phones' => array_values(array_unique($phones)),
                'emails' => array_values(array_unique($emails)),
                'company' => $company,
                'job_title' => $jobTitle,
                'raw_payload' => ['text' => $body],
            ],
        ];
    }

    private function splitName(string $fullName): array
    {
        $fullName = trim((string) preg_replace('/\s+/', ' ', $fullName));
        if ($fullName === '') {
            return ['', ''];
        }

        $parts = preg_split('/\s+/', $fullName) ?: [];
        if (count($parts) <= 1) {
            return [$fullName, ''];
        }

        $firstName = array_shift($parts);
        return [$firstName, implode(' ', $parts)];
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/[^\d]/', '', trim($value)) ?: '';
    }

    private function normalizeEmail(string $value): string
    {
        $email = strtolower(trim($value));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function findContactByNormalizedPhone(string $normalizedPhone): ?array
    {
        if ($normalizedPhone === '') {
            return null;
        }

        $contact = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') = ?
             LIMIT 1",
            [$normalizedPhone]
        );
        if ($contact) {
            return $contact;
        }

        if (strlen($normalizedPhone) === 12 && substr($normalizedPhone, 0, 3) === '254') {
            $local = '0' . substr($normalizedPhone, 3);
            return Database::queryOne(
                "SELECT id
                 FROM contacts
                 WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') = ?
                 LIMIT 1",
                [$local]
            ) ?: null;
        }

        if (strlen($normalizedPhone) === 10 && $normalizedPhone[0] === '0') {
            $intl = '254' . substr($normalizedPhone, 1);
            return Database::queryOne(
                "SELECT id
                 FROM contacts
                 WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') = ?
                 LIMIT 1",
                [$intl]
            ) ?: null;
        }

        return null;
    }

    private function placeholderEmail(string $phoneSeed, string $nameSeed, int $senderContactId): string
    {
        $seed = $phoneSeed !== '' ? $phoneSeed : strtolower(preg_replace('/[^a-z0-9]+/i', '', $nameSeed));
        if ($seed === '') {
            $seed = 'contact_' . max(1, $senderContactId);
        }

        return 'whatsapp_' . $seed . '@whatsapp.local';
    }
}
