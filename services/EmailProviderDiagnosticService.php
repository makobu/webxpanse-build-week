<?php

namespace CRM\Services;

class EmailProviderDiagnosticService
{
    /**
     * @param array<string,mixed> $providerSummary
     * @return array<string,mixed>
     */
    public function diagnose(?string $technicalError, array $providerSummary = []): array
    {
        $technicalError = trim((string) $technicalError);
        $needle = strtolower($technicalError);
        $providerLabel = trim((string) ($providerSummary['provider_label'] ?? 'System Mail'));

        if ($technicalError === '' || str_contains($needle, 'no configured mail provider') || str_contains($needle, 'no viable outbound mail provider')) {
            return $this->payload(
                'system_mail_missing',
                'System Mail is not configured',
                'Configure System Mail SMTP/IMAP, or copy the active Outreach Email SMTP settings into System Mail before sending workspace invites.',
                $technicalError,
                $providerSummary
            );
        }

        if (
            str_contains($needle, 'mail from')
            || str_contains($needle, 'not allowed to use')
            || str_contains($needle, 'sender address rejected')
            || str_contains($needle, 'not owned on this server')
            || str_contains($needle, 'not hosted on this server')
            || str_contains($needle, '550-')
            || str_contains($needle, ' rcp to failed')
        ) {
            return $this->payload(
                'sender_mismatch',
                'From email is not allowed by the mail server',
                'Set the System Mail From Email to the authenticated mailbox or an alias explicitly allowed by that SMTP account.',
                $technicalError,
                $providerSummary
            );
        }

        if (
            str_contains($needle, 'authentication')
            || str_contains($needle, 'authenticate')
            || str_contains($needle, 'auth failed')
            || str_contains($needle, '535')
            || str_contains($needle, 'invalid credentials')
            || str_contains($needle, 'password')
        ) {
            return $this->payload(
                'smtp_auth_failed',
                $providerLabel . ' authentication failed',
                'Check the SMTP username and password. For Gmail/Google Workspace, use an app password or OAuth direct-connect instead of the account password.',
                $technicalError,
                $providerSummary
            );
        }

        if (
            str_contains($needle, 'connection')
            || str_contains($needle, 'could not connect')
            || str_contains($needle, 'timed out')
            || str_contains($needle, 'timeout')
            || str_contains($needle, 'tls')
            || str_contains($needle, 'ssl')
            || str_contains($needle, 'certificate')
            || str_contains($needle, 'stream_socket')
        ) {
            return $this->payload(
                'smtp_connection_failed',
                'Could not connect to the mail server',
                'Verify the SMTP host, port, and encryption. Typical choices are 587 with TLS or 465 with SSL.',
                $technicalError,
                $providerSummary
            );
        }

        return $this->payload(
            'email_send_failed',
            'System Mail test failed',
            'Review the technical details, then verify the active System Mail provider and mailbox permissions.',
            $technicalError,
            $providerSummary
        );
    }

    /**
     * @param array<string,mixed> $providerSummary
     * @return array<string,mixed>
     */
    private function payload(string $code, string $title, string $action, string $technicalError, array $providerSummary): array
    {
        return [
            'diagnostic_code' => $code,
            'diagnostic_title' => $title,
            'recommended_action' => $action,
            'technical_error' => $technicalError,
            'provider_summary' => $this->providerSummary($providerSummary),
        ];
    }

    /**
     * @param array<string,mixed> $providerSummary
     * @return array<string,mixed>
     */
    private function providerSummary(array $providerSummary): array
    {
        return [
            'provider_key' => (string) ($providerSummary['provider_key'] ?? ''),
            'provider_label' => (string) ($providerSummary['provider_label'] ?? ''),
            'readiness' => (string) ($providerSummary['readiness'] ?? ''),
            'connected_email' => (string) ($providerSummary['connected_email'] ?? ''),
            'smtp_fallback_configured' => !empty($providerSummary['smtp_fallback_configured']),
            'incoming_fallback_configured' => !empty($providerSummary['incoming_fallback_configured']),
        ];
    }
}
