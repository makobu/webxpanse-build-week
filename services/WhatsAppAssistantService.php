<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\EmailAssistantHandler;

class WhatsAppAssistantService
{
    private WhatsAppAssistantConfig $config;
    private WhatsAppAssistantFormatter $formatter;
    private WhatsAppService $whatsApp;
    private EmailAssistantHandler $handler;
    private WhatsAppAssistantSessionService $sessionService;

    public function __construct()
    {
        $this->config = new WhatsAppAssistantConfig();
        $this->formatter = new WhatsAppAssistantFormatter();
        $this->whatsApp = new WhatsAppService('assistant');
        $this->handler = new EmailAssistantHandler('whatsapp');
        $this->sessionService = new WhatsAppAssistantSessionService();
    }

    public function handleWebhookMessage(array $message, array $metadata = [], array $contactInfo = []): bool
    {
        if (!$this->config->isAssistantWebhookTarget($metadata)) {
            return false;
        }

        $from = $this->config->normalizePhoneNumber((string) ($message['from'] ?? ''));
        $messageId = trim((string) ($message['id'] ?? ''));
        $body = $this->extractInstructionBody($message);
        $messageType = (string) ($message['type'] ?? 'text');

        if ($from === '') {
            return true;
        }

        $authorized = $this->config->findAuthorizedNumber($from);

        if (!$authorized) {
            return false;
        }

        $inboundLogId = $this->logMessage([
            'user_id' => (int) ($authorized['user_id'] ?? 0),
            'authorized_number_id' => (int) ($authorized['id'] ?? 0),
            'phone_number' => $from,
            'direction' => 'inbound',
            'message_type' => $messageType,
            'message_body' => $body,
            'intent' => null,
            'command_result_json' => null,
            'whatsapp_message_id' => $messageId,
            'status' => 'received',
        ]);

        // The unique provider-message claim is the execution boundary. Meta may
        // retry the same webhook concurrently; only the first insert may mutate CRM data.
        if ($messageId !== '' && $inboundLogId <= 0) {
            return true;
        }

        $this->sessionService->recordInboundMessage($authorized);

        if ($body === '') {
            $this->sendAndLogResponse(
                $from,
                ['Please send text instructions to the Personal Assistant. Media-only assistant commands are not supported yet.'],
                (int) $authorized['user_id'],
                (int) $authorized['id'],
                'unsupported'
            );
            $this->updateInboundStatus($inboundLogId, 'unsupported');
            return true;
        }

        $processed = $this->handler->processAssistantMessage($body, (int) $authorized['user_id']);
        $replyBody = (string) ($processed['reply_body'] ?? '');
        $messages = $this->formatter->formatReply($replyBody);
        $this->sendAndLogResponse(
            $from,
            $messages,
            (int) $authorized['user_id'],
            (int) $authorized['id'],
            'processed',
            (string) ($processed['primary_intent'] ?? null),
            $processed
        );
        $this->updateInboundStatus($inboundLogId, 'processed', (string) ($processed['primary_intent'] ?? ''));
        $this->config->touchAuthorizedNumber((int) $authorized['id']);

        return true;
    }

    private function extractInstructionBody(array $message): string
    {
        $type = (string) ($message['type'] ?? 'text');
        return match ($type) {
            'text' => trim((string) ($message['text']['body'] ?? '')),
            'interactive' => trim((string) ($message['interactive']['button_reply']['title'] ?? $message['interactive']['list_reply']['title'] ?? '')),
            default => '',
        };
    }

    private function sendAndLogResponse(
        string $phoneNumber,
        array $messages,
        int $userId,
        int $authorizedNumberId,
        string $status,
        ?string $intent = null,
        ?array $commandResult = null
    ): void {
        foreach ($messages as $messageBody) {
            $providerMessageId = null;
            $sendStatus = $status;
            $error = null;
            try {
                $result = $this->whatsApp->sendTextMessage($phoneNumber, $messageBody);
                $providerMessageId = (string) ($result['messages'][0]['id'] ?? '');
            } catch (\Throwable $e) {
                $sendStatus = 'failed';
                $error = $e->getMessage();
            }

            $payload = $commandResult !== null ? json_encode($commandResult) : null;
            if ($error !== null) {
                $payload = json_encode(['error' => $error] + ($commandResult ?? []));
            } elseif ($authorizedNumberId > 0) {
                $this->sessionService->recordOutboundMessage([
                    'id' => $authorizedNumberId,
                    'user_id' => $userId,
                    'phone_number' => $phoneNumber,
                ]);
            }

            $this->logMessage([
                'user_id' => $userId,
                'authorized_number_id' => $authorizedNumberId,
                'phone_number' => $phoneNumber,
                'direction' => 'outbound',
                'message_type' => 'text',
                'message_body' => $messageBody,
                'intent' => $intent,
                'command_result_json' => $payload,
                'whatsapp_message_id' => $providerMessageId,
                'status' => $sendStatus,
            ]);
        }
    }

    private function updateInboundStatus(int $id, string $status, string $intent = ''): void
    {
        if ($id <= 0) {
            return;
        }

        Database::execute(
            "UPDATE whatsapp_assistant_messages
             SET status = ?, intent = CASE WHEN ? != '' THEN ? ELSE intent END
             WHERE id = ?",
            [$status, $intent, $intent, $id]
        );
    }

    private function logMessage(array $data): int
    {
        $uuid = function_exists('uuid_v4') ? uuid_v4() : $this->generateUuid();
        $columns = ['uuid', 'user_id', 'authorized_number_id', 'phone_number', 'direction', 'message_type', 'message_body', 'intent', 'command_result_json', 'whatsapp_message_id', 'status', 'created_at'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', 'NOW()'];
        $params = [
            $uuid,
            $data['user_id'] ?: null,
            $data['authorized_number_id'] ?: null,
            $data['phone_number'],
            $data['direction'],
            $data['message_type'],
            $data['message_body'],
            $data['intent'],
            $data['command_result_json'],
            $data['whatsapp_message_id'],
            $data['status'],
        ];

        if (Database::columnExists('whatsapp_assistant_messages', 'workspace_id')) {
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
            if ($workspaceId <= 0) {
                $workspaceId = (int) ($data['workspace_id'] ?? 0);
            }
            array_splice($columns, 1, 0, 'workspace_id');
            array_splice($placeholders, 1, 0, '?');
            array_splice($params, 1, 0, [$workspaceId > 0 ? $workspaceId : null]);
        }

        try {
            Database::execute(
                "INSERT INTO whatsapp_assistant_messages (" . implode(', ', $columns) . ")
                 VALUES (" . implode(', ', $placeholders) . ")",
                $params
            );
        } catch (\PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($driverCode === 1062 && trim((string) ($data['whatsapp_message_id'] ?? '')) !== '') {
                return 0;
            }
            throw $e;
        }

        return (int) Database::lastInsertId();
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
