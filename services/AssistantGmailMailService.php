<?php
/**
 * Personal Gmail API integration for the email assistant mailbox.
 */

namespace CRM\Services;

class AssistantGmailMailService extends GoogleOAuthMailService
{
    public function __construct()
    {
        parent::__construct([
            'provider_key' => EmailIntegrationService::PROVIDER_GMAIL_OAUTH,
            'provider_label' => 'Assistant Gmail',
            'client_id_env_key' => 'GMAIL_ASSISTANT_MAIL_CLIENT_ID',
            'client_secret_env_key' => 'GMAIL_ASSISTANT_MAIL_CLIENT_SECRET',
            'redirect_env_key' => 'GMAIL_ASSISTANT_MAIL_REDIRECT_URI',
            'client_id_fallback_env_key' => 'GMAIL_MAIL_CLIENT_ID',
            'client_secret_fallback_env_key' => 'GMAIL_MAIL_CLIENT_SECRET',
            'default_callback_path' => '/api/email/assistant_gmail/callback.php',
            'state_session_key' => 'assistant_gmail_mail_state',
            'user_session_key' => 'assistant_gmail_mail_user_id',
            'default_grant_type' => GoogleOAuthScopeCatalog::GRANT_ASSISTANT_GMAIL,
        ]);
    }
}
