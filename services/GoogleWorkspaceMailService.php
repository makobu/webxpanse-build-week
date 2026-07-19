<?php
/**
 * Google Workspace Gmail API integration for the main mailbox.
 */

namespace CRM\Services;

class GoogleWorkspaceMailService extends GoogleOAuthMailService
{
    public function __construct()
    {
        parent::__construct([
            'provider_key' => EmailIntegrationService::PROVIDER_GOOGLE_WORKSPACE,
            'provider_label' => 'Google Workspace',
            'client_id_env_key' => 'GOOGLE_WORKSPACE_MAIL_CLIENT_ID',
            'client_secret_env_key' => 'GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET',
            'redirect_env_key' => 'GOOGLE_WORKSPACE_MAIL_REDIRECT_URI',
            'default_callback_path' => '/api/email/google/callback.php',
            'state_session_key' => 'google_workspace_mail_state',
            'user_session_key' => 'google_workspace_mail_user_id',
            'default_grant_type' => GoogleOAuthScopeCatalog::GRANT_GMAIL_SEND,
        ]);
    }
}
