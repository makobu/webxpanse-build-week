<?php
/**
 * Safe channel setup health summaries for onboarding and settings.
 */

namespace CRM\Services;

class WorkspaceChannelHealthService
{
    private EmailIntegrationService $emailIntegrations;
    private WorkspaceConnectService $connectService;
    private WorkspaceSmsChannelConfigService $smsConfig;

    public function __construct(?EmailIntegrationService $emailIntegrations = null, ?WorkspaceConnectService $connectService = null, ?WorkspaceSmsChannelConfigService $smsConfig = null)
    {
        $this->emailIntegrations = $emailIntegrations ?: new EmailIntegrationService();
        $this->connectService = $connectService ?: new WorkspaceConnectService();
        $this->smsConfig = $smsConfig ?: new WorkspaceSmsChannelConfigService();
    }

    public function summarize(int $workspaceId, ?array $user = null): array
    {
        $hub = $this->connectService->buildHubState($user, $workspaceId);

        return [
            'main_email' => $this->emailHealth($this->emailIntegrations->getMainProviderSummary($workspaceId), false, (array) ($hub['platform'] ?? [])),
            'outreach_email' => $this->emailHealth($this->emailIntegrations->getOutreachProviderSummary($workspaceId), false, (array) ($hub['platform'] ?? [])),
            'nurture_email' => $this->emailHealth($this->emailIntegrations->getNurtureProviderSummary($workspaceId), false, (array) ($hub['platform'] ?? [])),
            'assistant_email' => $this->emailHealth($this->emailIntegrations->getAssistantProviderSummary($workspaceId), true, (array) ($hub['platform'] ?? []), $workspaceId),
            'whatsapp' => $this->whatsappHealth((array) ($hub['whatsapp'] ?? []), (array) ($hub['platform'] ?? [])),
            'sms' => $this->smsHealth($workspaceId),
        ];
    }

    private function emailHealth(array $summary, bool $assistant, array $platform, ?int $workspaceId = null): array
    {
        $badges = [];
        $issues = [];
        $actions = [];
        $connectedEmail = trim((string) ($summary['connected_email'] ?? ''));
        $providerKey = (string) ($summary['provider_key'] ?? '');
        $readiness = (string) ($summary['readiness'] ?? 'blocked');
        $lastFailure = trim((string) ($summary['last_failure'] ?? ''));

        $oauthMailboxConnected = $connectedEmail !== ''
            && in_array($providerKey, [EmailIntegrationService::PROVIDER_GMAIL_OAUTH, EmailIntegrationService::PROVIDER_GOOGLE_WORKSPACE], true);

        if ($oauthMailboxConnected) {
            $badges[] = ($assistant ? 'Assistant ' : '') . (($providerKey === EmailIntegrationService::PROVIDER_GMAIL_OAUTH) ? 'Gmail connected' : 'Mailbox connected');
        }

        $smtpReady = (bool) ($summary['smtp_fallback_configured'] ?? false);
        $imapReady = (bool) ($summary['incoming_fallback_configured'] ?? false);
        if ($connectedEmail !== '' && in_array($providerKey, [EmailIntegrationService::PROVIDER_GMAIL_OAUTH, EmailIntegrationService::PROVIDER_GOOGLE_WORKSPACE], true)) {
            $smtpReady = true;
            $imapReady = true;
        }

        if ($assistant) {
            [$assistantSmtpReady, $assistantImapReady, $hasWorkspaceAssistantConfig] = $this->assistantConfigReadiness((int) $workspaceId);
            if ($hasWorkspaceAssistantConfig) {
                $smtpReady = $oauthMailboxConnected || $assistantSmtpReady;
                $imapReady = $oauthMailboxConnected || $assistantImapReady;
            } else {
                $smtpReady = $smtpReady || $assistantSmtpReady;
                $imapReady = $imapReady || $assistantImapReady;
            }
        }

        if ($smtpReady) {
            $badges[] = $assistant ? 'Assistant SMTP ready' : 'SMTP ready';
        }
        $badges[] = $imapReady ? 'IMAP ready' : 'IMAP missing';

        foreach ((array) ($summary['issues'] ?? []) as $issue) {
            $message = trim((string) ($issue['message'] ?? ''));
            if ($message !== '') {
                $issues[] = $message;
            }
        }
        if ($lastFailure !== '') {
            $badges[] = 'Last failure recorded';
            $issues[] = 'Last failure: ' . $lastFailure;
        }

        if (!$smtpReady) {
            $badges[] = $assistant ? 'Assistant email missing' : 'Email missing';
        }

        if (!$smtpReady) {
            $actions[] = $assistant ? 'Finish assistant SMTP setup.' : 'Finish SMTP setup.';
        }

        if (!$imapReady) {
            $actions[] = $assistant ? 'Enable IMAP and save assistant inbound settings.' : 'Enable IMAP and save inbound settings.';
        }

        $status = match ($readiness) {
            'ready' => 'ready',
            'warning' => 'warning',
            default => ($smtpReady ? 'warning' : 'not_connected'),
        };
        if ($assistant && $smtpReady && $imapReady) {
            $status = 'ready';
        }

        return [
            'status' => $status,
            'label' => $this->emailLabel($assistant, $connectedEmail, $providerKey, $smtpReady, $imapReady, $status),
            'badges' => array_values(array_unique($badges)),
            'issues' => array_values(array_unique($issues)),
            'actions' => array_values(array_unique($actions)),
            'connected_email' => $connectedEmail,
            'provider' => $providerKey,
            'outbound_ready' => $smtpReady,
            'inbound_ready' => $imapReady,
        ];
    }

    private function emailLabel(bool $assistant, string $connectedEmail, string $providerKey, bool $smtpReady, bool $imapReady, string $status): string
    {
        if ($connectedEmail !== '' && in_array($providerKey, [EmailIntegrationService::PROVIDER_GMAIL_OAUTH, EmailIntegrationService::PROVIDER_GOOGLE_WORKSPACE], true)) {
            $label = $providerKey === EmailIntegrationService::PROVIDER_GMAIL_OAUTH ? 'Gmail' : 'Mailbox';
            return ($assistant ? 'Assistant ' : '') . $label . ' connected' . ($imapReady ? '' : ', IMAP missing');
        }
        if ($smtpReady && !$imapReady) {
            return 'Sending ready, inbox not connected';
        }
        if ($smtpReady && $imapReady) {
            return 'SMTP and IMAP connected';
        }
        if ($status === 'disabled') {
            return 'Disabled by platform setup';
        }
        return $assistant ? 'Assistant Gmail not connected' : 'Email not connected';
    }

    private function assistantConfigReadiness(int $workspaceId): array
    {
        $smtp = [];
        $config = [];
        $settings = [];
        if ($workspaceId > 0) {
            $service = new WorkspaceAssistantConfigService();
            $smtp = $service->emailSmtpConfig($workspaceId);
            $config = $service->get($workspaceId, 'email', true);
            $settings = (array) ($config['settings'] ?? []);
        }

        $hasWorkspaceConfig = $config !== [];
        if ($hasWorkspaceConfig) {
            $smtpReady = trim((string) ($smtp['host'] ?? '')) !== ''
                && trim((string) ($smtp['username'] ?? '')) !== ''
                && trim((string) ($smtp['password'] ?? '')) !== '';
            $imapReady = !empty($settings['imap_enabled'])
                && trim((string) ($settings['imap_host'] ?? '')) !== ''
                && trim((string) ($settings['imap_username'] ?? '')) !== ''
                && (string) ($settings['imap_password'] ?? '') !== '';

            return [$smtpReady, $imapReady, true];
        }

        return [false, false, false];
    }

    private function whatsappHealth(array $state, array $platform): array
    {
        $status = (string) ($state['status'] ?? WorkspaceConnectService::STATUS_NOT_CONNECTED);
        $badges = [];
        $issues = [];
        $actions = [];
        $label = 'WhatsApp not connected';
        $phoneNumberId = trim((string) ($state['phone_number_id'] ?? ''));
        $displayNumber = trim((string) ($state['display_phone_number'] ?? ''));
        $accessTokenSaved = !empty($state['access_token_saved']);
        $webhookTokenReady = trim((string) ($state['webhook_token'] ?? '')) !== '';
        $verifyTokenReady = !empty($state['webhook_verify_token_saved']);
        $embeddedSignupReady = !empty($platform['whatsapp_embedded_signup']);
        $manualOutboundReady = $phoneNumberId !== '' && $accessTokenSaved;
        $manualInboundReady = $webhookTokenReady && $verifyTokenReady;

        if ($displayNumber !== '') {
            $badges[] = 'Number connected';
            $label = 'WhatsApp number connected';
        }

        if ($manualOutboundReady) {
            $badges[] = 'Manual API credentials saved';
        } elseif ($phoneNumberId !== '') {
            $badges[] = 'Phone number ID saved';
            $actions[] = 'Save a WhatsApp Cloud API access token.';
        }

        if ($verifyTokenReady) {
            $badges[] = 'Workspace webhook ready';
        } else {
            $actions[] = 'Save WhatsApp settings to generate a workspace webhook token.';
        }

        if ($embeddedSignupReady) {
            $badges[] = 'Embedded signup available';
        }

        if ($status === WorkspaceConnectService::STATUS_CONNECTED && $manualOutboundReady && $manualInboundReady) {
            $status = 'ready';
            $label = 'WhatsApp number connected';
        } elseif ($status === WorkspaceConnectService::STATUS_CONNECTED && $manualOutboundReady) {
            $status = 'warning';
            $label = 'Sending ready, webhook needs setup';
        } elseif ($status === WorkspaceConnectService::STATUS_NEEDS_ATTENTION || $phoneNumberId !== '' || $accessTokenSaved) {
            $status = 'warning';
            $label = 'WhatsApp setup needs attention';
            if (!$manualOutboundReady) {
                $actions[] = 'Complete the manual WhatsApp phone number ID and access token setup.';
            }
        } else {
            $status = 'not_connected';
            $actions[] = 'Save manual WhatsApp Business API credentials in Marketplace.';
        }

        $lastError = trim((string) ($state['last_error'] ?? ''));
        if ($lastError !== '') {
            $badges[] = 'Last error recorded';
            $issues[] = 'Last error: ' . $lastError;
        }

        return [
            'status' => $status,
            'label' => $label,
            'badges' => array_values(array_unique($badges)),
            'issues' => array_values(array_unique($issues)),
            'actions' => array_values(array_unique($actions)),
            'phone_number_id' => $phoneNumberId,
            'display_phone_number' => $displayNumber,
            'outbound_ready' => $manualOutboundReady,
            'inbound_ready' => $manualInboundReady,
            'embedded_signup_configured' => $embeddedSignupReady,
        ];
    }

    private function smsHealth(int $workspaceId): array
    {
        $readiness = $this->smsConfig->readiness($workspaceId);
        $badges = [];
        $issues = [];
        $actions = [];

        if (!empty($readiness['enabled'])) {
            $badges[] = 'SMS enabled';
        } else {
            $actions[] = 'Enable SMS Channel for this workspace.';
        }

        foreach ((array) ($readiness['checks'] ?? []) as $check) {
            $label = (string) ($check['label'] ?? '');
            if ($label === '') {
                continue;
            }
            if (!empty($check['ok'])) {
                if (in_array($label, ['Twilio account SID', 'Twilio auth token', 'Sender number'], true)) {
                    $badges[] = $label . ' saved';
                }
                continue;
            }
            if (!empty($check['required'])) {
                $issues[] = $label . ' missing';
            }
        }

        if ((int) ($readiness['failed_messages'] ?? 0) > 0) {
            $badges[] = 'Failed queue items';
            $issues[] = (int) ($readiness['failed_messages'] ?? 0) . ' failed SMS queue items.';
        }

        $lastError = trim((string) ($readiness['last_error'] ?? ''));
        if ($lastError !== '') {
            $badges[] = 'Last error recorded';
            $issues[] = 'Last error: ' . $lastError;
        }

        if (empty($readiness['ready'])) {
            $actions[] = 'Save workspace Twilio credentials and sender number in Marketplace.';
        }

        return [
            'status' => !empty($readiness['ready']) ? 'ready' : 'not_connected',
            'label' => !empty($readiness['ready']) ? 'SMS sender ready' : 'SMS not connected',
            'badges' => array_values(array_unique($badges)),
            'issues' => array_values(array_unique($issues)),
            'actions' => array_values(array_unique($actions)),
            'outbound_ready' => !empty($readiness['outbound_ready']),
            'inbound_ready' => !empty($readiness['inbound_ready']),
            'queued_messages' => (int) ($readiness['queued_messages'] ?? 0),
            'failed_messages' => (int) ($readiness['failed_messages'] ?? 0),
        ];
    }
}
