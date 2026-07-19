<?php

namespace CRM\Services;

class WorkspaceInviteMailer
{
    public function sendInvite(array $invite, string $inviteUrl): array
    {
        $email = strtolower(trim((string) ($invite['email'] ?? '')));
        if ($email === '') {
            throw new \RuntimeException('Invite email is required before delivery can start.');
        }

        $workspaceName = trim((string) ($invite['workspace_name'] ?? 'Workspace'));
        $roleSlug = trim((string) ($invite['role_slug'] ?? 'viewer'));
        $expiresAt = trim((string) ($invite['expires_at'] ?? ''));
        $smtp = $this->smtpClient();
        $fromEmail = $smtp->getPreferredFromEmail('noreply@example.com') ?? 'noreply@example.com';
        $fromName = $smtp->getPreferredFromName(brandProductName()) ?? brandProductName();

        if ($this->shouldSimulateDelivery()) {
            return [
                'success' => true,
                'method' => 'simulated',
                'provider' => $smtp->getActiveProviderKey(),
                'to' => $email,
            ];
        }

        $subject = sprintf("You're invited to join %s", $workspaceName !== '' ? $workspaceName : brandProductName());
        $body = $this->buildPlainBody($workspaceName, $roleSlug, $expiresAt, $inviteUrl);
        $bodyHtml = $this->buildHtmlBody($workspaceName, $roleSlug, $expiresAt, $inviteUrl);

        $smtp->send($email, $fromEmail, $fromName, $subject, $body, [], $bodyHtml);

        return [
            'success' => true,
            'method' => $smtp->getLastMethodUsed() ?? 'smtp',
            'provider' => $smtp->getLastProviderKey() ?? $smtp->getActiveProviderKey(),
            'to' => $email,
        ];
    }

    protected function smtpClient(): SMTPClient
    {
        return new SMTPClient();
    }

    protected function shouldSimulateDelivery(): bool
    {
        $appEnv = strtolower((string) (getenv('APP_ENV') !== false ? getenv('APP_ENV') : ($_ENV['APP_ENV'] ?? '')));

        return defined('PHPUNIT_COMPOSER_INSTALL')
            || getenv('PHPUNIT_COMPOSER_INSTALL') !== false
            || $appEnv === 'testing'
            || $appEnv === 'test';
    }

    protected function buildPlainBody(string $workspaceName, string $roleSlug, string $expiresAt, string $inviteUrl): string
    {
        $workspaceLabel = $workspaceName !== '' ? $workspaceName : brandProductName();
        $roleLabel = ucfirst($roleSlug);
        $expiresLabel = $expiresAt !== '' ? $expiresAt : 'soon';

        return implode("\n\n", [
            "You've been invited to join {$workspaceLabel}.",
            "Role: {$roleLabel}",
            "Expires: {$expiresLabel}",
            "Accept your invite: {$inviteUrl}",
            'If you did not expect this invitation, you can ignore this email.',
        ]);
    }

    protected function buildHtmlBody(string $workspaceName, string $roleSlug, string $expiresAt, string $inviteUrl): string
    {
        $brandName = htmlspecialchars(brandProductName(), ENT_QUOTES, 'UTF-8');
        $workspaceLabel = htmlspecialchars($workspaceName !== '' ? $workspaceName : brandProductName(), ENT_QUOTES, 'UTF-8');
        $roleLabel = htmlspecialchars(ucfirst($roleSlug), ENT_QUOTES, 'UTF-8');
        $expiresLabel = htmlspecialchars($expiresAt !== '' ? $expiresAt : 'soon', ENT_QUOTES, 'UTF-8');
        $url = htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Workspace Invite</title>
</head>
<body style="margin:0;padding:24px;background:#f8fafc;font-family:Arial,sans-serif;color:#0f172a;">
    <div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:24px;">
        <div style="font-size:14px;color:#64748b;margin-bottom:12px;">{$brandName}</div>
        <h1 style="margin:0 0 12px 0;font-size:24px;">You're invited to join {$workspaceLabel}</h1>
        <p style="margin:0 0 12px 0;line-height:1.6;">A workspace owner or admin invited you to collaborate inside this workspace.</p>
        <div style="padding:16px;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;margin:16px 0;">
            <div style="margin-bottom:8px;"><strong>Role:</strong> {$roleLabel}</div>
            <div><strong>Expires:</strong> {$expiresLabel}</div>
        </div>
        <p style="margin:0 0 16px 0;">
            <a href="{$url}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:600;">Accept Invite</a>
        </p>
        <p style="margin:0;line-height:1.6;">If the button does not work, copy and paste this link into your browser:</p>
        <p style="margin:8px 0 0 0;word-break:break-all;"><a href="{$url}" style="color:#2563eb;">{$url}</a></p>
    </div>
</body>
</html>
HTML;
    }
}
