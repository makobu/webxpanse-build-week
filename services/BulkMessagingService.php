<?php
/**
 * Bulk Messaging Service
 *
 * Orchestrates bulk email, SMS, and WhatsApp sends via existing services.
 * Does not modify EmailService, SMSService, or WhatsAppService.
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\EmailDraftGenerator;

class BulkMessagingService
{
    private Contacts $contacts;
    private EmailService $emailService;
    private SMSService $smsService;
    private WhatsAppService $whatsappService;
    private EmailDraftGenerator $emailDraftGenerator;
    private ColdOutreachGovernanceService $coldOutreachGovernance;

    /** Max contacts per bulk send to avoid abuse */
    private const MAX_CONTACTS = 500;
    private const MAX_AI_CUSTOMIZED_CONTACTS = 100;
    private const BULK_SAFE_TEMPLATE_VARIABLES = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'company',
        'contact_id',
    ];

    public function __construct()
    {
        $this->contacts = new Contacts();
        $this->emailService = new EmailService();
        $this->smsService = new SMSService();
        $this->whatsappService = new WhatsAppService();
        $this->emailDraftGenerator = new EmailDraftGenerator();
        $this->coldOutreachGovernance = new ColdOutreachGovernanceService();
    }

    /**
     * Resolve contacts from filters (tag, stage, search, assigned_to, contact_ids)
     */
    public function resolveContacts(array $filters, int $limit = self::MAX_CONTACTS, int $offset = 0): array
    {
        return $this->contacts->getByFilters($filters, $limit, $offset);
    }

    /**
     * Count contacts matching filters
     */
    public function countContacts(array $filters, ?string $recipientChannel = null): int
    {
        return $this->contacts->countByFilters($filters, $recipientChannel);
    }

    /**
     * Bulk send email - queues one email per contact with valid email address
     */
    public function bulkSendEmail(array $contactIds, string $subject, string $body, array $options = []): BulkResult
    {
        $result = new BulkResult();
        $userId = $options['user_id'] ?? ($_SESSION['user_id'] ?? null);
        $bodyHtml = $options['body_html'] ?? $body;
        $scheduledAt = $options['scheduled_at'] ?? null;
        $aiCustomize = !empty($options['ai_customize']);
        $aiPurpose = trim((string) ($options['ai_purpose'] ?? 'follow_up')) ?: 'follow_up';
        $aiTone = trim((string) ($options['ai_tone'] ?? 'professional')) ?: 'professional';
        $aiInstructions = trim((string) ($options['ai_instructions'] ?? ''));
        $senderProfile = strtolower(trim((string) ($options['sender_profile'] ?? $options['smtp_profile'] ?? '')));

        if (count($contactIds) > self::MAX_CONTACTS) {
            $result->errors[0] = 'Maximum ' . self::MAX_CONTACTS . ' contacts per bulk send';
            return $result;
        }
        if ($aiCustomize && count($contactIds) > self::MAX_AI_CUSTOMIZED_CONTACTS) {
            $result->errors[0] = 'AI customization supports up to ' . self::MAX_AI_CUSTOMIZED_CONTACTS . ' contacts per send';
            return $result;
        }

        $contacts = $this->getContactsByIds($contactIds);

        foreach ($contacts as $contact) {
            $contactId = (int) $contact['id'];
            $email = trim($contact['email'] ?? '');

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result->skipped++;
                $result->errors[$contactId] = 'No valid email address';
                continue;
            }

            $templateVariables = $this->buildTemplateVariables($contact);
            $personalizedSubject = $this->replaceVariables($subject, $templateVariables);
            $personalizedBody = $this->replaceVariables($body, $templateVariables);
            $personalizedBodyHtml = $this->replaceVariables($bodyHtml, $templateVariables);
            $aiFallback = false;

            if ($aiCustomize) {
                try {
                    $draftInstructions = "You are customizing a bulk outreach email. Keep the core campaign intent.\n"
                        . "Subject template: {$subject}\n"
                        . "Body template:\n{$body}\n";
                    if ($aiInstructions !== '') {
                        $draftInstructions .= "Additional campaign instructions: {$aiInstructions}\n";
                    }
                    $draftInstructions .= "Generate one personalized email for this contact using system context.";

                    $draft = $this->emailDraftGenerator->generateDraft(
                        $contactId,
                        $aiPurpose,
                        $aiTone,
                        [
                            'custom_instructions' => $draftInstructions,
                            'length' => 'medium',
                            'style' => 'paragraph',
                        ]
                    );

                    $aiSubject = trim((string) ($draft['subject'] ?? ''));
                    $aiBodyHtml = trim((string) ($draft['body_html'] ?? ''));
                    $aiBodyText = trim((string) ($draft['body_text'] ?? ''));

                    if ($aiSubject !== '') {
                        $personalizedSubject = $aiSubject;
                    }
                    if ($aiBodyHtml !== '') {
                        $personalizedBodyHtml = $aiBodyHtml;
                        $personalizedBody = $aiBodyText !== '' ? $aiBodyText : strip_tags($aiBodyHtml);
                    }
                } catch (\Throwable $e) {
                    $aiFallback = true;
                    $result->errors['ai_' . $contactId] = 'AI customization failed, used template fallback';
                }
            }

            try {
                $dispatch = $this->coldOutreachGovernance->planDispatch(
                    'email',
                    $contactId,
                    $scheduledAt,
                    'bulk_email',
                    ['contact_id' => $contactId]
                );
                $sendOptions = [
                    'user_id' => $userId,
                    'body_html' => $personalizedBodyHtml,
                    'scheduled_at' => $dispatch['scheduled_at'] ?? $scheduledAt,
                    'sender_profile' => 'outreach',
                ];
                if (in_array($senderProfile, ['nurture', 'nurture_email'], true)) {
                    $sendOptions['sender_profile'] = 'nurture';
                }
                $uuid = $this->emailService->send($contactId, $email, $personalizedSubject, $personalizedBody, $sendOptions);
                if (!empty($dispatch['reservation_id'])) {
                    $emailRow = Database::queryOne("SELECT id FROM emails WHERE uuid = ? LIMIT 1", [$uuid]);
                    $this->coldOutreachGovernance->attachReservation(
                        (int) ($dispatch['reservation_id'] ?? 0),
                        !empty($emailRow['id']) ? (int) $emailRow['id'] : null,
                        $uuid
                    );
                    if (!empty($dispatch['was_deferred'])) {
                        $result->deferred++;
                    }
                }
                $result->queued++;
                if ($aiFallback) {
                    // Do not count AI fallback as a skipped send.
                }
            } catch (\Exception $e) {
                $result->skipped++;
                $result->errors[$contactId] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Bulk send SMS - queues one SMS per contact with valid phone number
     */
    public function bulkSendSMS(array $contactIds, string $message, array $options = []): BulkResult
    {
        $result = new BulkResult();
        $userId = $options['user_id'] ?? ($_SESSION['user_id'] ?? null);

        if (count($contactIds) > self::MAX_CONTACTS) {
            $result->errors[0] = 'Maximum ' . self::MAX_CONTACTS . ' contacts per bulk send';
            return $result;
        }

        $contacts = $this->getContactsByIds($contactIds);
        $batchKey = trim((string) ($options['idempotency_key'] ?? ''));
        if ($batchKey === '') {
            $result->errors[0] = 'A bulk SMS idempotency key is required';
            return $result;
        }
        $seenPhones = [];

        foreach ($contacts as $contact) {
            $contactId = (int) $contact['id'];
            $phone = trim($contact['phone'] ?? '');

            if (empty($phone) || strlen(preg_replace('/[^0-9+]/', '', $phone)) < 10) {
                $result->skipped++;
                $result->errors[$contactId] = 'No valid phone number';
                continue;
            }

            $phoneKey = preg_replace('/\D/', '', $phone) ?? '';
            if (isset($seenPhones[$phoneKey])) {
                $result->skipped++;
                $result->errors[$contactId] = 'Duplicate phone - already queued for this number';
                continue;
            }
            $seenPhones[$phoneKey] = true;

            $personalizedMessage = $this->replaceVariables($message, $this->buildTemplateVariables($contact));

            try {
                $sendOptions = [
                    'user_id' => $userId,
                    'workspace_id' => $options['workspace_id'] ?? null,
                    'scheduled_at' => $options['scheduled_at'] ?? null,
                    'idempotency_key' => $batchKey . ':' . $contactId,
                    'consent_confirmed_at' => $options['consent_confirmed_at'] ?? null,
                    'consent_source' => $options['consent_source'] ?? null,
                ];
                $this->smsService->storeMessage($contactId, $phone, $personalizedMessage, $sendOptions);
                $result->queued++;
            } catch (\Exception $e) {
                $result->skipped++;
                $result->errors[$contactId] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Bulk send WhatsApp - queues one message per contact with valid phone number
     */
    public function bulkSendWhatsApp(array $contactIds, string $message, string $messageType = 'text', array $options = []): BulkResult
    {
        $result = new BulkResult();
        $userId = $options['user_id'] ?? ($_SESSION['user_id'] ?? null);

        if (count($contactIds) > self::MAX_CONTACTS) {
            $result->errors[0] = 'Maximum ' . self::MAX_CONTACTS . ' contacts per bulk send';
            return $result;
        }

        $contacts = $this->getContactsByIds($contactIds);
        $seenPhones = [];

        foreach ($contacts as $contact) {
            $contactId = (int) $contact['id'];
            $phone = trim($contact['phone'] ?? '');
            $phone = preg_replace('/[^\d+]/', '', $phone);
            $phone = ltrim($phone, '+0');

            if (empty($phone) || strlen($phone) < 10) {
                $result->skipped++;
                $result->errors[$contactId] = 'No valid phone number';
                continue;
            }

            if (isset($seenPhones[$phone])) {
                $result->skipped++;
                $result->errors[$contactId] = 'Duplicate phone - already queued for this number';
                continue;
            }
            $seenPhones[$phone] = true;

            $personalizedBody = $this->replaceVariables($message, $this->buildTemplateVariables($contact));

            try {
                $dispatch = $this->coldOutreachGovernance->planDispatch(
                    'whatsapp',
                    $contactId,
                    $options['scheduled_at'] ?? null,
                    'bulk_whatsapp',
                    ['contact_id' => $contactId]
                );
                $sendOptions = [
                    'user_id' => $userId,
                    'scheduled_at' => $dispatch['scheduled_at'] ?? ($options['scheduled_at'] ?? null),
                    'template_name' => $options['template_name'] ?? null,
                    'template_params' => $options['template_params'] ?? null,
                ];
                $uuid = $this->whatsappService->storeMessage($contactId, $phone, $messageType, $personalizedBody, $sendOptions);
                if (!empty($dispatch['reservation_id'])) {
                    $messageRow = Database::queryOne("SELECT id FROM whatsapp_messages WHERE uuid = ? LIMIT 1", [$uuid]);
                    $this->coldOutreachGovernance->attachReservation(
                        (int) ($dispatch['reservation_id'] ?? 0),
                        !empty($messageRow['id']) ? (int) $messageRow['id'] : null,
                        $uuid
                    );
                    if (!empty($dispatch['was_deferred'])) {
                        $result->deferred++;
                    }
                }
                $result->queued++;
            } catch (\Exception $e) {
                $result->skipped++;
                $result->errors[$contactId] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Get contacts by IDs with email and phone
     */
    private function getContactsByIds(array $contactIds): array
    {
        if (empty($contactIds)) {
            return [];
        }
        $ids = array_map('intval', array_filter($contactIds));
        if (empty($ids)) {
            return [];
        }
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        return Database::query(
            "SELECT id, first_name, last_name, email, phone, company
             FROM contacts
             WHERE workspace_id = ? AND id IN ($placeholders)",
            array_merge([$workspaceId], $ids)
        );
    }

    private function buildTemplateVariables(array $contact): array
    {
        return [
            'first_name' => (string) ($contact['first_name'] ?? ''),
            'last_name' => (string) ($contact['last_name'] ?? ''),
            'email' => (string) ($contact['email'] ?? ''),
            'phone' => (string) ($contact['phone'] ?? ''),
            'company' => (string) ($contact['company'] ?? ''),
            'contact_id' => (string) ((int) ($contact['id'] ?? 0)),
        ];
    }

    private function replaceVariables(string $text, array $variables): string
    {
        foreach (self::BULK_SAFE_TEMPLATE_VARIABLES as $key) {
            $value = htmlspecialchars((string) ($variables[$key] ?? ''), ENT_QUOTES, 'UTF-8');
            $text = str_replace('{' . $key . '}', $value, $text);
        }

        return preg_replace('/\{[^}]+\}/', '', $text) ?? $text;
    }
}

/**
 * Bulk send result DTO
 */
class BulkResult
{
    public int $queued = 0;
    public int $skipped = 0;
    public int $deferred = 0;
    /** @var array<int|string, string> contact_id => error reason */
    public array $errors = [];

    public function toArray(): array
    {
        return [
            'queued' => $this->queued,
            'skipped' => $this->skipped,
            'deferred' => $this->deferred,
            'errors' => $this->errors,
        ];
    }
}
