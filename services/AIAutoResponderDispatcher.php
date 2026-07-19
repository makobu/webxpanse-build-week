<?php
/**
 * AI Auto-responder Dispatcher
 * Sends generated replies through existing channel services.
 */

namespace CRM\Services;

use CRM\Database;

class AIAutoResponderDispatcher
{
    public function dispatch(string $channel, int $contactId, array $sourceCommunication, array $reply): array
    {
        return match ($channel) {
            'email' => $this->sendEmail($contactId, $sourceCommunication, $reply),
            'whatsapp' => $this->sendWhatsApp($contactId, $sourceCommunication, $reply),
            'sms' => $this->sendSms($contactId, $sourceCommunication, $reply),
            default => ['success' => false, 'error' => "Unsupported channel: {$channel}"],
        };
    }

    private function sendEmail(int $contactId, array $sourceCommunication, array $reply): array
    {
        $contact = Database::queryOne("SELECT email FROM contacts WHERE id = ?", [$contactId]);
        $to = trim((string) ($contact['email'] ?? ''));
        if ($to === '') {
            return ['success' => false, 'error' => 'Missing contact email'];
        }

        $subject = trim((string) ($reply['subject'] ?? ''));
        if ($subject === '') {
            $sourceSubject = trim((string) ($sourceCommunication['subject'] ?? 'Message'));
            $subject = str_starts_with(strtolower($sourceSubject), 're:') ? $sourceSubject : "Re: {$sourceSubject}";
        }

        $bodyText = trim((string) ($reply['body_text'] ?? $reply['reply_text'] ?? ''));
        $bodyHtml = trim((string) ($reply['body_html'] ?? ''));
        if ($bodyText === '' && $bodyHtml !== '') {
            $bodyText = strip_tags($bodyHtml);
        }
        if ($bodyHtml === '' && $bodyText !== '') {
            $bodyHtml = nl2br(htmlspecialchars($bodyText));
        }
        if ($bodyText === '' && $bodyHtml === '') {
            return ['success' => false, 'error' => 'Generated email reply is empty'];
        }

        $emailService = new EmailService();
        $senderProfile = in_array((string) ($sourceCommunication['sender_profile'] ?? ''), ['nurture', 'nurture_email'], true) ? 'nurture' : 'outreach';
        $options = [
            'body_html' => $bodyHtml,
            'sender_profile' => $senderProfile,
        ];
        if (!empty($reply['sender_user_id'])) {
            $options['user_id'] = (int) $reply['sender_user_id'];
        }
        if (trim((string) ($reply['sender_name'] ?? '')) !== '') {
            $options['from_name'] = trim((string) $reply['sender_name']);
        }
        try {
            $emailService->sendImmediate($contactId, $to, $subject, $bodyText, $options);
            return [
                'success' => true,
                'subject' => $subject,
                'body_text' => $bodyText,
                'body_html' => $bodyHtml,
                'error' => null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'subject' => $subject,
                'body_text' => $bodyText,
                'body_html' => $bodyHtml,
                'error' => $e->getMessage() ?: 'Email send failed',
            ];
        }
    }

    private function sendWhatsApp(int $contactId, array $sourceCommunication, array $reply): array
    {
        $contact = Database::queryOne("SELECT phone FROM contacts WHERE id = ?", [$contactId]);
        $phone = trim((string) ($contact['phone'] ?? ''));
        if ($phone === '') {
            return ['success' => false, 'error' => 'Missing contact phone'];
        }

        $message = trim((string) ($reply['reply_text'] ?? $reply['body_text'] ?? $reply['message'] ?? ''));
        if ($message === '') {
            return ['success' => false, 'error' => 'Generated WhatsApp reply is empty'];
        }

        try {
            $whatsApp = new WhatsAppService();
            if (!$whatsApp->isWithin24HourWindow($contactId)) {
                return ['success' => false, 'error' => '24-hour WhatsApp customer care window is closed'];
            }

            $to = $whatsApp->normalizePhoneNumber($phone);
            $result = $whatsApp->sendTextMessage($to, $message);
            $whatsappMessageId = $result['messages'][0]['id'] ?? null;
            $whatsApp->storeMessage($contactId, $to, 'text', $message, [
                'whatsapp_message_id' => $whatsappMessageId,
            ]);

            return [
                'success' => true,
                'message' => $message,
                'provider_message_id' => $whatsappMessageId,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function sendSms(int $contactId, array $sourceCommunication, array $reply): array
    {
        $contact = Database::queryOne("SELECT workspace_id, phone FROM contacts WHERE id = ?", [$contactId]);
        $workspaceId = (int) ($sourceCommunication['workspace_id'] ?? $contact['workspace_id'] ?? WorkspaceContext::currentWorkspaceId() ?? 0);
        $phone = trim((string) ($contact['phone'] ?? ''));
        if ($phone === '') {
            return ['success' => false, 'error' => 'Missing contact phone'];
        }

        $message = trim((string) ($reply['reply_text'] ?? $reply['body_text'] ?? $reply['message'] ?? ''));
        if ($message === '') {
            return ['success' => false, 'error' => 'Generated SMS reply is empty'];
        }

        try {
            $smsConfig = new WorkspaceSmsChannelConfigService();
            $smsService = new SMSService($smsConfig);
            $result = $smsService->sendSMS($phone, $message, ['workspace_id' => $workspaceId]);
            $providerSid = (string) ($result['sid'] ?? '');
            $uuid = uuid_v4();
            $allowLegacyFallback = $workspaceId <= 0;
            try {
                $allowLegacyFallback = $allowLegacyFallback || (new DefaultWorkspaceService())->isDefaultWorkspace($workspaceId);
            } catch (\Throwable $e) {
                $allowLegacyFallback = $allowLegacyFallback || $workspaceId <= 0;
            }
            $runtimeConfig = $smsConfig->runtimeConfig($workspaceId, $allowLegacyFallback);
            $fromNumber = (string) ($runtimeConfig['from_number'] ?? '');

            Database::execute(
                "INSERT INTO sms_messages (workspace_id, uuid, contact_id, to_number, from_number, message_body, status, direction, provider, provider_message_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'sent', 'outbound', 'twilio', ?, NOW())",
                [$workspaceId > 0 ? $workspaceId : null, $uuid, $contactId, $phone, $fromNumber, $message, $providerSid]
            );
            Database::execute(
                "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, metadata, created_at)
                 VALUES (?, ?, ?, 'sms', 'outbound', 'SMS Reply', ?, 'sent', ?, NOW())",
                [
                    $workspaceId > 0 ? $workspaceId : null,
                    uuid_v4(),
                    $contactId,
                    $message,
                    json_encode([
                        'provider' => 'twilio',
                        'provider_message_id' => $providerSid,
                        'ai_autoresponder' => true,
                    ]),
                ]
            );

            return [
                'success' => true,
                'message' => $message,
                'provider_message_id' => $providerSid,
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
