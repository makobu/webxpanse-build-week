<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\WorkspaceBillingSettings;
use PDO;

class IntegrationReadinessService
{
    private const EXPIRING_CREDENTIAL_DAYS = 14;
    private const QUEUE_STALE_HOURS = 2;
    private const JOB_STALE_HOURS = 25;

    /** @var array<int,array<string,mixed>> */
    private const QUEUE_TABLES = [
        [
            'key' => 'email_queue',
            'label' => 'Email Queue',
            'table' => 'email_queue',
            'status_column' => 'status',
            'time_columns' => ['scheduled_at', 'created_at'],
            'pending_statuses' => ['pending', 'processing'],
            'failed_statuses' => ['failed'],
        ],
        [
            'key' => 'whatsapp_queue',
            'label' => 'WhatsApp Queue',
            'table' => 'whatsapp_queue',
            'status_column' => 'status',
            'time_columns' => ['scheduled_at', 'created_at'],
            'pending_statuses' => ['pending', 'processing'],
            'failed_statuses' => ['failed'],
        ],
        [
            'key' => 'sms_queue',
            'label' => 'SMS Queue',
            'table' => 'sms_queue',
            'status_column' => 'status',
            'time_columns' => ['scheduled_at', 'created_at'],
            'pending_statuses' => ['pending', 'queued', 'processing'],
            'failed_statuses' => ['failed', 'undelivered'],
        ],
        [
            'key' => 'workflow_retry_queue',
            'label' => 'Workflow Retry Queue',
            'table' => 'workflow_retry_queue',
            'status_column' => 'status',
            'time_columns' => ['retry_after', 'created_at'],
            'pending_statuses' => ['pending', 'processing'],
            'failed_statuses' => ['failed'],
        ],
        [
            'key' => 'scheduled_workflow_actions',
            'label' => 'Scheduled Workflow Actions',
            'table' => 'scheduled_workflow_actions',
            'status_column' => 'status',
            'time_columns' => ['scheduled_for', 'created_at'],
            'pending_statuses' => ['pending'],
            'failed_statuses' => ['failed'],
        ],
        [
            'key' => 'campaign_queue',
            'label' => 'Campaign Queue',
            'table' => 'campaign_queue',
            'status_column' => 'status',
            'time_columns' => ['execute_at', 'created_at'],
            'pending_statuses' => ['pending', 'processing'],
            'failed_statuses' => ['failed'],
        ],
        [
            'key' => 'ai_autoresponder_queue',
            'label' => 'AI Autoresponder Queue',
            'table' => 'ai_autoresponder_queue',
            'status_column' => 'status',
            'time_columns' => ['available_at', 'created_at'],
            'pending_statuses' => ['pending', 'processing'],
            'failed_statuses' => ['failed'],
        ],
        [
            'key' => 'marketing_execution_queue',
            'label' => 'Marketing Execution Queue',
            'table' => 'marketing_execution_queue',
            'status_column' => 'status',
            'time_columns' => ['scheduled_at', 'execute_at', 'updated_at', 'created_at'],
            'pending_statuses' => ['queued', 'pending', 'processing'],
            'failed_statuses' => ['failed', 'blocked'],
        ],
    ];

    public function __construct(
        private ?PDO $pdo = null,
        private ?string $rootPath = null
    ) {
        $this->rootPath = rtrim($rootPath ?: dirname(__DIR__), "\\/");
    }

    /**
     * @return array<string,mixed>
     */
    public function check(): array
    {
        $domains = [
            $this->checkSmtpEmail(),
            $this->checkWhatsApp(),
            $this->checkCalendar(),
            $this->checkPaymentProviders(),
            $this->checkAIProvider(),
            $this->checkCronJobs(),
            $this->checkQueues(),
        ];

        $findings = [];
        $summary = [
            'domains_checked' => count($domains),
            'ready' => 0,
            'warning' => 0,
            'critical' => 0,
            'unsupported' => 0,
            'findings' => 0,
            'by_domain' => [],
            'by_rule' => [],
        ];

        foreach ($domains as $domain) {
            $status = (string) ($domain['status'] ?? 'unknown');
            $key = (string) ($domain['key'] ?? 'unknown');
            if ($status === 'ok') {
                $summary['ready']++;
            } elseif ($status === 'critical') {
                $summary['critical']++;
            } elseif ($status === 'warning') {
                $summary['warning']++;
            }
            if (empty($domain['supported'])) {
                $summary['unsupported']++;
            }
            $summary['by_domain'][$key] = $status;

            foreach ((array) ($domain['findings'] ?? []) as $finding) {
                if (!is_array($finding)) {
                    continue;
                }
                $findings[] = $finding;
                $summary['findings']++;
                $rule = (string) ($finding['rule'] ?? 'unknown');
                $summary['by_rule'][$rule] = (int) ($summary['by_rule'][$rule] ?? 0) + 1;
            }
        }

        return [
            'status' => $summary['critical'] > 0 ? 'critical' : ($summary['warning'] > 0 ? 'warning' : 'ok'),
            'summary' => $summary,
            'domains' => $domains,
            'findings' => $findings,
            'checked_at' => date('c'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSmtpEmail(): array
    {
        $findings = [];
        $env = [
            'host' => $this->envString(['SMTP_HOST', 'EMAIL_SMTP_HOST']),
            'username' => $this->envString(['SMTP_USER', 'SMTP_USERNAME', 'EMAIL_SMTP_USER']),
            'password_present' => $this->envString(['SMTP_PASS', 'SMTP_PASSWORD', 'EMAIL_SMTP_PASS']) !== '',
            'from_email' => $this->envString(['SMTP_FROM_EMAIL', 'EMAIL_FROM_EMAIL']),
        ];
        $envReady = $env['host'] !== ''
            && $env['username'] !== ''
            && $env['password_present']
            && filter_var($env['from_email'], FILTER_VALIDATE_EMAIL) !== false;

        $platformRows = $this->platformEmailDefaultReadiness($findings);
        $oauth = $this->emailOAuthReadiness($findings);
        $platformReady = (int) ($platformRows['ready_count'] ?? 0) > 0;
        $oauthReady = (int) ($oauth['ready_count'] ?? 0) > 0;
        $sendRouteReady = $envReady || $platformReady || $oauthReady;

        if (!$sendRouteReady) {
            $this->addFinding($findings, 'warning', 'smtp_send_route_missing', 'No usable SMTP or OAuth mail send route is configured.', [
                'domain' => 'smtp_email',
                'target' => 'email',
                'evidence' => [
                    'env_smtp_ready' => $envReady,
                    'platform_default_ready_count' => (int) ($platformRows['ready_count'] ?? 0),
                    'active_oauth_ready_count' => (int) ($oauth['ready_count'] ?? 0),
                ],
                'recommendation' => 'Configure platform SMTP defaults or an active OAuth email integration before live outbound email.',
            ]);
        }

        return $this->domain('smtp_email', 'SMTP / Email', true, [
            'env_smtp_ready' => $envReady,
            'env_host_present' => $env['host'] !== '',
            'env_from_email_present' => $env['from_email'] !== '',
            'platform_defaults' => $platformRows,
            'oauth_integrations' => $oauth,
            'send_route_ready' => $sendRouteReady,
        ], $findings);
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function platformEmailDefaultReadiness(array &$findings): array
    {
        if (!$this->tableHasColumns('platform_email_defaults', ['scope', 'settings_json'])) {
            return ['supported' => false, 'row_count' => 0, 'ready_count' => 0, 'scopes' => []];
        }

        $rows = $this->query("SELECT scope, settings_json FROM platform_email_defaults ORDER BY scope ASC");
        $summary = ['supported' => true, 'row_count' => count($rows), 'ready_count' => 0, 'scopes' => []];
        foreach ($rows as $row) {
            $settings = $this->decodeJson((string) ($row['settings_json'] ?? ''));
            $missing = [];
            if (trim((string) ($settings['smtp_host'] ?? '')) === '') {
                $missing[] = 'smtp_host';
            }
            if (trim((string) ($settings['smtp_username'] ?? '')) === '') {
                $missing[] = 'smtp_username';
            }
            if (!$this->secretPresent($settings['smtp_password'] ?? null)) {
                $missing[] = 'smtp_password';
            }
            if (filter_var(trim((string) ($settings['from_email'] ?? '')), FILTER_VALIDATE_EMAIL) === false) {
                $missing[] = 'from_email';
            }

            $ready = $missing === [];
            if ($ready) {
                $summary['ready_count']++;
            } elseif ($this->hasAnyValue($settings, ['smtp_host', 'smtp_username', 'smtp_password', 'from_email'])) {
                $this->addFinding($findings, 'warning', 'platform_smtp_default_incomplete', 'A platform SMTP default exists but is incomplete.', [
                    'domain' => 'smtp_email',
                    'target' => (string) ($row['scope'] ?? 'platform_email_defaults'),
                    'evidence' => ['missing_fields' => $missing],
                    'recommendation' => 'Complete SMTP host, username, password, and From Email for this platform email scope.',
                ]);
            }

            $summary['scopes'][] = [
                'scope' => (string) ($row['scope'] ?? ''),
                'smtp_configured' => $ready,
                'missing_fields' => $missing,
            ];
        }

        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function emailOAuthReadiness(array &$findings): array
    {
        if (!$this->tableHasColumns('email_integrations', ['provider', 'scope', 'access_token', 'is_active'])) {
            return ['supported' => false, 'active_count' => 0, 'ready_count' => 0, 'missing_token_count' => 0];
        }

        $columns = $this->existingColumns('email_integrations', [
            'id', 'workspace_id', 'provider', 'scope', 'oauth_grant_type', 'access_token', 'token_expires_at',
            'is_active', 'scope_status', 'reconnect_required', 'email_address', 'settings_json',
        ]);
        $rows = $this->query(
            'SELECT ' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . '
             FROM email_integrations
             WHERE is_active = 1
             ORDER BY id ASC
             LIMIT 100'
        );

        $summary = [
            'supported' => true,
            'active_count' => count($rows),
            'ready_count' => 0,
            'manual_smtp_ready_count' => 0,
            'oauth_ready_count' => 0,
            'missing_token_count' => 0,
            'expiring_count' => 0,
            'reconnect_required_count' => 0,
        ];

        foreach ($rows as $row) {
            $target = $this->rowTarget('email_integrations', $row);
            $provider = (string) ($row['provider'] ?? '');
            if ($provider === 'manual_smtp') {
                $settings = $this->decodeJson((string) ($row['settings_json'] ?? ''));
                $missing = [];
                if (trim((string) ($settings['smtp_host'] ?? '')) === '') {
                    $missing[] = 'smtp_host';
                }
                if (trim((string) ($settings['smtp_username'] ?? '')) === '') {
                    $missing[] = 'smtp_username';
                }
                if (!$this->secretPresent($settings['smtp_password'] ?? null)) {
                    $missing[] = 'smtp_password';
                }
                if (filter_var(trim((string) ($settings['from_email'] ?? $row['email_address'] ?? '')), FILTER_VALIDATE_EMAIL) === false) {
                    $missing[] = 'from_email';
                }
                if ($missing === []) {
                    $summary['ready_count']++;
                    $summary['manual_smtp_ready_count']++;
                } else {
                    $summary['missing_token_count']++;
                    $this->addFinding($findings, $this->liveSeverity(), 'email_manual_smtp_incomplete', 'An active manual SMTP integration is incomplete.', [
                        'domain' => 'smtp_email',
                        'target' => $target,
                        'evidence' => $this->safeProviderEvidence($row) + ['missing_fields' => $missing],
                        'recommendation' => 'Complete SMTP host, username, password, and From Email or disable this manual SMTP integration.',
                    ]);
                }
                continue;
            }
            if ($provider === 'manual_imap') {
                continue;
            }

            $tokenPresent = trim((string) ($row['access_token'] ?? '')) !== '';
            if ($tokenPresent) {
                $summary['ready_count']++;
                $summary['oauth_ready_count']++;
            } else {
                $summary['missing_token_count']++;
                $this->addFinding($findings, $this->liveSeverity(), 'email_active_oauth_missing_token', 'An active email integration has no access token.', [
                    'domain' => 'smtp_email',
                    'target' => $target,
                    'evidence' => $this->safeProviderEvidence($row),
                    'recommendation' => 'Reconnect or disable the active email integration before depending on outbound mail.',
                ]);
            }

            if (!empty($row['reconnect_required'])) {
                $summary['reconnect_required_count']++;
                $this->addFinding($findings, 'warning', 'email_oauth_reconnect_required', 'An active email OAuth grant needs reconnection.', [
                    'domain' => 'smtp_email',
                    'target' => $target,
                    'evidence' => $this->safeProviderEvidence($row),
                    'recommendation' => 'Reconnect the OAuth grant and verify required scopes.',
                ]);
            }
            if (isset($row['scope_status']) && in_array((string) $row['scope_status'], ['missing_required', 'unknown'], true)) {
                $this->addFinding($findings, 'warning', 'email_oauth_scope_not_verified', 'An active email OAuth grant does not have verified required scopes.', [
                    'domain' => 'smtp_email',
                    'target' => $target,
                    'evidence' => $this->safeProviderEvidence($row),
                    'recommendation' => 'Verify OAuth scopes or reconnect with the current required scope set.',
                ]);
            }
            if ($this->expiresWithin($row['token_expires_at'] ?? null)) {
                $summary['expiring_count']++;
                $this->addFinding($findings, 'warning', 'email_oauth_token_expiring', 'An email OAuth token is expired or near expiry.', [
                    'domain' => 'smtp_email',
                    'target' => $target,
                    'evidence' => ['token_expires_at' => (string) ($row['token_expires_at'] ?? '')],
                    'recommendation' => 'Refresh or reconnect this email integration before live send volume.',
                ]);
            }
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkWhatsApp(): array
    {
        $findings = [];
        $env = [
            'phone_number_id_present' => $this->envString(['WHATSAPP_PHONE_NUMBER_ID']) !== '',
            'access_token_present' => $this->envString(['WHATSAPP_ACCESS_TOKEN']) !== '',
            'business_account_id_present' => $this->envString(['WHATSAPP_BUSINESS_ACCOUNT_ID']) !== '',
        ];
        $envReady = $env['phone_number_id_present'] && $env['access_token_present'];
        $tableSummary = $this->whatsAppTableReadiness($findings);
        $ready = $envReady || (int) ($tableSummary['connected_ready_count'] ?? 0) > 0;

        if (!$ready) {
            $this->addFinding($findings, 'warning', 'whatsapp_provider_missing', 'No usable WhatsApp provider credentials are configured.', [
                'domain' => 'whatsapp',
                'target' => 'whatsapp',
                'evidence' => ['env_ready' => $envReady, 'connected_ready_count' => (int) ($tableSummary['connected_ready_count'] ?? 0)],
                'recommendation' => 'Configure platform WhatsApp credentials or connect at least one workspace WhatsApp Business number.',
            ]);
        }
        return $this->domain('whatsapp', 'WhatsApp', true, [
            'env' => $env,
            'env_ready' => $envReady,
            'workspace_integrations' => $tableSummary,
            'send_route_ready' => $ready,
        ], $findings);
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function whatsAppTableReadiness(array &$findings): array
    {
        if (!$this->tableHasColumns('workspace_whatsapp_integrations', ['workspace_id', 'connection_status'])) {
            return ['supported' => false, 'row_count' => 0, 'connected_ready_count' => 0];
        }

        $columns = $this->existingColumns('workspace_whatsapp_integrations', [
            'id', 'workspace_id', 'connection_status', 'access_token', 'phone_number_id',
            'whatsapp_business_account_id', 'token_expires_at', 'webhook_token', 'webhook_verify_token',
            'webhook_verified_at', 'webhook_last_status', 'webhook_last_error', 'disconnected_at',
        ]);
        $where = in_array('disconnected_at', $columns, true) ? 'WHERE disconnected_at IS NULL' : '';
        $rows = $this->query(
            'SELECT ' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . '
             FROM workspace_whatsapp_integrations
             ' . $where . '
             ORDER BY workspace_id ASC
             LIMIT 100'
        );

        $summary = [
            'supported' => true,
            'row_count' => count($rows),
            'connected_count' => 0,
            'connected_ready_count' => 0,
            'needs_attention_count' => 0,
            'missing_credential_count' => 0,
            'webhook_unverified_count' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['connection_status'] ?? '');
            $target = $this->rowTarget('workspace_whatsapp_integrations', $row);
            if ($status === 'needs_attention') {
                $summary['needs_attention_count']++;
                $this->addFinding($findings, 'warning', 'whatsapp_connection_needs_attention', 'A WhatsApp workspace connection needs attention.', [
                    'domain' => 'whatsapp',
                    'target' => $target,
                    'evidence' => ['connection_status' => $status, 'workspace_id' => (int) ($row['workspace_id'] ?? 0)],
                    'recommendation' => 'Open the workspace WhatsApp setup and repair the provider connection.',
                ]);
            }
            if (!in_array($status, ['connected', 'connecting', 'needs_attention'], true)) {
                continue;
            }
            if ($status === 'connected') {
                $summary['connected_count']++;
            }

            $missing = [];
            if (!$this->secretPresent($row['access_token'] ?? null)) {
                $missing[] = 'access_token';
            }
            if (trim((string) ($row['phone_number_id'] ?? '')) === '') {
                $missing[] = 'phone_number_id';
            }
            if ($missing === []) {
                if ($status === 'connected') {
                    $summary['connected_ready_count']++;
                }
            } else {
                $summary['missing_credential_count']++;
                $this->addFinding($findings, $this->liveSeverity(), 'whatsapp_active_integration_missing_credential', 'An active WhatsApp connection is missing required credentials.', [
                    'domain' => 'whatsapp',
                    'target' => $target,
                    'evidence' => ['connection_status' => $status, 'missing_fields' => $missing],
                    'recommendation' => 'Reconnect the WhatsApp number or disable the broken active connection before live upload.',
                ]);
            }

            if (isset($row['webhook_verified_at']) && $status === 'connected' && trim((string) ($row['webhook_verified_at'] ?? '')) === '') {
                $summary['webhook_unverified_count']++;
                $this->addFinding($findings, 'warning', 'whatsapp_webhook_not_verified', 'A connected WhatsApp integration has not recorded webhook verification.', [
                    'domain' => 'whatsapp',
                    'target' => $target,
                    'evidence' => [
                        'webhook_token_present' => trim((string) ($row['webhook_token'] ?? '')) !== '',
                        'webhook_verify_token_present' => $this->secretPresent($row['webhook_verify_token'] ?? null),
                    ],
                    'recommendation' => 'Verify the workspace webhook callback in Meta and confirm inbound events are reaching the CRM.',
                ]);
            }

            if ($this->expiresWithin($row['token_expires_at'] ?? null)) {
                $this->addFinding($findings, 'warning', 'whatsapp_token_expiring', 'A WhatsApp access token is expired or near expiry.', [
                    'domain' => 'whatsapp',
                    'target' => $target,
                    'evidence' => ['token_expires_at' => (string) ($row['token_expires_at'] ?? '')],
                    'recommendation' => 'Refresh or replace the WhatsApp access token before live messaging.',
                ]);
            }
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCalendar(): array
    {
        $findings = [];
        $googleClientReady = $this->envString(['GOOGLE_CALENDAR_CLIENT_ID', 'GOOGLE_CLIENT_ID']) !== ''
            && $this->envString(['GOOGLE_CALENDAR_CLIENT_SECRET', 'GOOGLE_CLIENT_SECRET']) !== '';
        $tableSummary = $this->calendarTableReadiness($findings);
        $ready = $googleClientReady || (int) ($tableSummary['enabled_ready_count'] ?? 0) > 0;

        if (!$ready) {
            $this->addFinding($findings, 'warning', 'calendar_provider_missing', 'No usable Google/calendar provider credentials or ready enabled calendar integration are configured.', [
                'domain' => 'calendar',
                'target' => 'calendar',
                'evidence' => ['google_client_ready' => $googleClientReady, 'enabled_ready_count' => (int) ($tableSummary['enabled_ready_count'] ?? 0)],
                'recommendation' => 'Configure Google Calendar OAuth credentials or connect a workspace calendar before live calendar automation.',
            ]);
        }

        return $this->domain('calendar', 'Google / Calendar', true, [
            'google_client_ready' => $googleClientReady,
            'integrations' => $tableSummary,
            'calendar_route_ready' => $ready,
        ], $findings);
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function calendarTableReadiness(array &$findings): array
    {
        if (!$this->tableHasColumns('calendar_integrations', ['provider', 'sync_enabled', 'access_token'])) {
            return ['supported' => false, 'enabled_count' => 0, 'enabled_ready_count' => 0];
        }

        $columns = $this->existingColumns('calendar_integrations', [
            'id', 'workspace_id', 'user_id', 'provider', 'oauth_grant_type', 'access_token', 'refresh_token',
            'token_expires_at', 'calendar_id', 'sync_enabled', 'scope_status', 'reconnect_required',
            'last_sync_at', 'updated_at',
        ]);
        $rows = $this->query(
            'SELECT ' . implode(', ', array_map([$this, 'quoteIdentifier'], $columns)) . '
             FROM calendar_integrations
             WHERE sync_enabled = 1
             ORDER BY id ASC
             LIMIT 100'
        );

        $summary = [
            'supported' => true,
            'enabled_count' => count($rows),
            'enabled_ready_count' => 0,
            'missing_token_count' => 0,
            'reconnect_required_count' => 0,
        ];

        foreach ($rows as $row) {
            $target = $this->rowTarget('calendar_integrations', $row);
            $tokenPresent = trim((string) ($row['access_token'] ?? '')) !== '';
            if ($tokenPresent) {
                $summary['enabled_ready_count']++;
            } else {
                $summary['missing_token_count']++;
                $this->addFinding($findings, $this->liveSeverity(), 'calendar_enabled_integration_missing_token', 'An enabled calendar integration has no access token.', [
                    'domain' => 'calendar',
                    'target' => $target,
                    'evidence' => $this->safeProviderEvidence($row),
                    'recommendation' => 'Reconnect or disable this calendar integration before live scheduling.',
                ]);
            }
            if (!empty($row['reconnect_required'])) {
                $summary['reconnect_required_count']++;
                $this->addFinding($findings, 'warning', 'calendar_oauth_reconnect_required', 'A calendar OAuth grant needs reconnection.', [
                    'domain' => 'calendar',
                    'target' => $target,
                    'evidence' => $this->safeProviderEvidence($row),
                    'recommendation' => 'Reconnect the calendar OAuth grant and verify required calendar scopes.',
                ]);
            }
            if (isset($row['scope_status']) && in_array((string) $row['scope_status'], ['missing_required', 'unknown'], true)) {
                $this->addFinding($findings, 'warning', 'calendar_oauth_scope_not_verified', 'A calendar OAuth grant does not have verified required scopes.', [
                    'domain' => 'calendar',
                    'target' => $target,
                    'evidence' => $this->safeProviderEvidence($row),
                    'recommendation' => 'Verify calendar OAuth scopes or reconnect with the current required scope set.',
                ]);
            }
            if ($this->expiresWithin($row['token_expires_at'] ?? null)) {
                $this->addFinding($findings, 'warning', 'calendar_oauth_token_expiring', 'A calendar OAuth token is expired or near expiry.', [
                    'domain' => 'calendar',
                    'target' => $target,
                    'evidence' => ['token_expires_at' => (string) ($row['token_expires_at'] ?? '')],
                    'recommendation' => 'Refresh or reconnect this calendar integration before live scheduling.',
                ]);
            }
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPaymentProviders(): array
    {
        $findings = [];
        $settings = $this->billingSettings();
        $paystackReady = $this->envString(['PAYSTACK_SECRET_KEY', 'PAYSTACK_SECRET']) !== ''
            || $this->secretPresent($settings['paystack_secret_key'] ?? null);
        $mpesaReady = $this->mpesaReady($settings);
        $cardEnabled = !array_key_exists('payment_card_enabled', $settings) || !empty($settings['payment_card_enabled']);
        $mpesaPaymentEnabled = !array_key_exists('payment_mpesa_enabled', $settings) || !empty($settings['payment_mpesa_enabled']);
        $bankTransferEnabled = !array_key_exists('payment_bank_transfer_enabled', $settings) || !empty($settings['payment_bank_transfer_enabled']);
        $paystackMode = (string) ($settings['paystack_mode'] ?? 'test');
        $mpesaEnvironment = (string) ($settings['mpesa_environment'] ?? 'sandbox');

        if ($cardEnabled && !$paystackReady) {
            $this->addFinding($findings, $paystackMode === 'live' ? 'critical' : 'warning', 'paystack_provider_missing', 'Card payments are enabled but Paystack credentials are not ready.', [
                'domain' => 'payment',
                'target' => 'paystack',
                'evidence' => ['payment_card_enabled' => $cardEnabled, 'paystack_mode' => $paystackMode],
                'recommendation' => 'Configure Paystack live keys or disable card payments before live checkout.',
            ]);
        }
        if ($paystackReady && $paystackMode === 'live') {
            $webhook = trim((string) ($settings['paystack_webhook_url'] ?? ''));
            if ($webhook === '') {
                $webhook = WorkspaceBillingSettings::generatedPaystackWebhookUrl();
            }
            if ($webhook === '' || !str_starts_with(strtolower($webhook), 'https://')) {
                $this->addFinding($findings, 'warning', 'paystack_live_webhook_not_https', 'Paystack live mode needs an HTTPS webhook URL.', [
                    'domain' => 'payment',
                    'target' => 'paystack_webhook_url',
                    'evidence' => ['webhook_url_present' => $webhook !== ''],
                    'recommendation' => 'Set the live HTTPS Paystack webhook URL and confirm it points to api/webhooks/paystack.php.',
                ]);
            }
        }
        if ($mpesaPaymentEnabled && !$mpesaReady) {
            $this->addFinding($findings, $mpesaEnvironment === 'live' ? 'critical' : 'warning', 'mpesa_provider_missing', 'M-Pesa payments are enabled but Daraja credentials are not ready.', [
                'domain' => 'payment',
                'target' => 'mpesa',
                'evidence' => ['payment_mpesa_enabled' => $mpesaPaymentEnabled, 'mpesa_environment' => $mpesaEnvironment],
                'recommendation' => 'Configure M-Pesa Daraja credentials/callback or disable M-Pesa payments before live checkout.',
            ]);
        }
        if (!$paystackReady && !$mpesaReady && !$bankTransferEnabled) {
            $this->addFinding($findings, 'warning', 'payment_provider_missing', 'No payment provider or manual bank transfer path is available.', [
                'domain' => 'payment',
                'target' => 'payment',
                'evidence' => ['paystack_ready' => false, 'mpesa_ready' => false, 'bank_transfer_enabled' => false],
                'recommendation' => 'Enable at least one tested payment path before live paid signup.',
            ]);
        }

        return $this->domain('payment', 'Payment Provider', true, [
            'billing_settings_supported' => $this->tableExists('workspace_billing_settings'),
            'billing_mode' => (string) ($settings['billing_mode'] ?? 'central_hub'),
            'paystack_ready' => $paystackReady,
            'paystack_mode' => $paystackMode,
            'mpesa_ready' => $mpesaReady,
            'mpesa_environment' => $mpesaEnvironment,
            'payment_card_enabled' => $cardEnabled,
            'payment_mpesa_enabled' => $mpesaPaymentEnabled,
            'payment_bank_transfer_enabled' => $bankTransferEnabled,
        ], $findings);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkAIProvider(): array
    {
        $findings = [];
        $runtime = AIRuntimeConfig::validate();
        $workspaceConfigs = $this->workspaceAIProviderSummary($findings);
        $coreReady = !empty($runtime['core_ai_provider_ready']);
        $workspaceReady = (int) ($workspaceConfigs['ready_count'] ?? 0) > 0;

        if (!$coreReady && !$workspaceReady) {
            $this->addFinding($findings, 'warning', 'ai_provider_missing', 'No remote AI provider is ready; runtime will depend on local fallback only.', [
                'domain' => 'ai_provider',
                'target' => 'AI provider',
                'evidence' => [
                    'core_ai_provider_ready' => $coreReady,
                    'fallback_only_mode' => !empty($runtime['fallback_only_mode']),
                    'provider_missing' => (array) (($runtime['provider'] ?? [])['missing'] ?? []),
                ],
                'recommendation' => 'Configure the shared AI provider or a workspace provider key before live AI workflows.',
            ]);
        }

        return $this->domain('ai_provider', 'AI Provider', true, [
            'core_ai_provider_ready' => $coreReady,
            'fallback_only_mode' => !empty($runtime['fallback_only_mode']),
            'provider' => [
                'api_key_present' => !empty(($runtime['provider'] ?? [])['api_key_present']),
                'normalized_api_url_present' => trim((string) (($runtime['provider'] ?? [])['normalized_api_url'] ?? '')) !== '',
                'provider_type' => (string) (($runtime['provider'] ?? [])['provider_type'] ?? ''),
                'model' => (string) (($runtime['provider'] ?? [])['model'] ?? ''),
                'missing' => (array) (($runtime['provider'] ?? [])['missing'] ?? []),
            ],
            'workspace_provider_configs' => $workspaceConfigs,
        ], $findings);
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function workspaceAIProviderSummary(array &$findings): array
    {
        if (!$this->tableHasColumns('workspace_ai_provider_configs', ['workspace_id', 'mode', 'api_url', 'encrypted_api_key'])) {
            return ['supported' => false, 'enabled_count' => 0, 'ready_count' => 0];
        }

        $rows = $this->query(
            "SELECT workspace_id, provider_key, mode, api_url, model, encrypted_api_key, last_verified_at, last_error
             FROM workspace_ai_provider_configs
             WHERE mode = 'enabled'
             ORDER BY workspace_id ASC
             LIMIT 100"
        );
        $summary = ['supported' => true, 'enabled_count' => count($rows), 'ready_count' => 0, 'with_last_error_count' => 0];
        foreach ($rows as $row) {
            $missing = [];
            if (trim((string) ($row['api_url'] ?? '')) === '') {
                $missing[] = 'api_url';
            }
            if (!$this->secretPresent($row['encrypted_api_key'] ?? null)) {
                $missing[] = 'api_key';
            }
            $target = $this->rowTarget('workspace_ai_provider_configs', $row);
            if ($missing === []) {
                $summary['ready_count']++;
            } else {
                $this->addFinding($findings, $this->liveSeverity(), 'workspace_ai_provider_config_incomplete', 'An enabled workspace AI provider config is incomplete.', [
                    'domain' => 'ai_provider',
                    'target' => $target,
                    'evidence' => ['provider_key' => (string) ($row['provider_key'] ?? ''), 'missing_fields' => $missing],
                    'recommendation' => 'Complete or disable the workspace AI provider config before live AI usage.',
                ]);
            }
            if (trim((string) ($row['last_error'] ?? '')) !== '') {
                $summary['with_last_error_count']++;
                $this->addFinding($findings, 'warning', 'workspace_ai_provider_last_error', 'An enabled workspace AI provider has a recorded provider error.', [
                    'domain' => 'ai_provider',
                    'target' => $target,
                    'evidence' => [
                        'last_verified_at' => (string) ($row['last_verified_at'] ?? ''),
                        'last_error_present' => true,
                    ],
                    'recommendation' => 'Run an AI provider probe and clear the provider error before live AI workflows.',
                ]);
            }
        }
        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCronJobs(): array
    {
        $findings = [];
        $jobHealth = $this->automationJobHealthSummary($findings);
        $marketingSchedules = $this->marketingWorkerScheduleSummary($findings);
        $supported = !empty($jobHealth['supported']) || !empty($marketingSchedules['supported']);

        if (!$supported) {
            $this->addFinding($findings, 'warning', 'cron_job_health_unsupported', 'No job health or worker schedule tables are available.', [
                'domain' => 'cron_jobs',
                'target' => 'job health',
                'evidence' => ['automation_job_health_supported' => false, 'marketing_worker_schedules_supported' => false],
                'recommendation' => 'Run migrations and make scheduled workers report into automation_job_health or worker schedules.',
            ]);
        }

        return $this->domain('cron_jobs', 'Cron / Jobs', $supported, [
            'automation_job_health' => $jobHealth,
            'marketing_worker_schedules' => $marketingSchedules,
        ], $findings);
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function automationJobHealthSummary(array &$findings): array
    {
        if (!$this->tableHasColumns('automation_job_health', ['job_key', 'status', 'last_run_at'])) {
            return ['supported' => false, 'total' => 0, 'failed_count' => 0, 'stale_count' => 0];
        }

        $rows = $this->query(
            "SELECT job_key, status, last_run_at, last_success_at, last_failure_at, updated_at
             FROM automation_job_health
             ORDER BY job_key ASC"
        );
        $summary = ['supported' => true, 'total' => count($rows), 'failed_count' => 0, 'stale_count' => 0];
        if ($rows === []) {
            $this->addFinding($findings, 'warning', 'job_health_empty', 'No scheduled jobs have reported health status.', [
                'domain' => 'cron_jobs',
                'target' => 'automation_job_health',
                'evidence' => ['rows' => 0],
                'recommendation' => 'Run scheduled workers once and confirm they record success in automation_job_health.',
            ]);
            return $summary;
        }

        foreach ($rows as $row) {
            $target = (string) ($row['job_key'] ?? 'automation_job');
            if ((string) ($row['status'] ?? '') === 'failed') {
                $summary['failed_count']++;
                $this->addFinding($findings, 'critical', 'background_job_failed', 'A scheduled/background job is marked failed.', [
                    'domain' => 'cron_jobs',
                    'target' => $target,
                    'evidence' => [
                        'last_failure_at' => (string) ($row['last_failure_at'] ?? ''),
                        'last_run_at' => (string) ($row['last_run_at'] ?? ''),
                    ],
                    'recommendation' => 'Inspect the worker log, repair the failing job, and rerun it before live upload.',
                ]);
            }
            if ($this->isOlderThan($row['last_success_at'] ?? $row['last_run_at'] ?? null, self::JOB_STALE_HOURS)) {
                $summary['stale_count']++;
                $this->addFinding($findings, 'warning', 'background_job_stale', 'A scheduled/background job has not reported recent success.', [
                    'domain' => 'cron_jobs',
                    'target' => $target,
                    'evidence' => [
                        'last_success_at' => (string) ($row['last_success_at'] ?? ''),
                        'stale_threshold_hours' => self::JOB_STALE_HOURS,
                    ],
                    'recommendation' => 'Confirm cron or the queue worker is scheduled and reporting successful runs.',
                ]);
            }
        }

        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function marketingWorkerScheduleSummary(array &$findings): array
    {
        if (!$this->tableHasColumns('marketing_live_worker_schedules', ['worker_key', 'status', 'last_confirmed_at', 'max_stale_minutes'])) {
            return ['supported' => false, 'active_count' => 0, 'stale_count' => 0];
        }

        $rows = $this->query(
            "SELECT workspace_id, worker_key, status, expected_interval_minutes, max_stale_minutes, last_confirmed_at, next_check_at
             FROM marketing_live_worker_schedules
             WHERE status = 'active'
             ORDER BY workspace_id ASC, worker_key ASC
             LIMIT 100"
        );
        $summary = ['supported' => true, 'active_count' => count($rows), 'stale_count' => 0];
        foreach ($rows as $row) {
            $maxStaleMinutes = max(1, (int) ($row['max_stale_minutes'] ?? 30));
            if (!$this->isOlderThanMinutes($row['last_confirmed_at'] ?? null, $maxStaleMinutes)) {
                continue;
            }
            $summary['stale_count']++;
            $this->addFinding($findings, 'warning', 'marketing_worker_schedule_stale', 'An active marketing live worker schedule has stale confirmation evidence.', [
                'domain' => 'cron_jobs',
                'target' => (string) ($row['worker_key'] ?? 'marketing_worker'),
                'evidence' => [
                    'workspace_id' => (int) ($row['workspace_id'] ?? 0),
                    'last_confirmed_at' => (string) ($row['last_confirmed_at'] ?? ''),
                    'max_stale_minutes' => $maxStaleMinutes,
                ],
                'recommendation' => 'Confirm the live worker cron command is installed and refresh scheduler validation evidence.',
            ]);
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function checkQueues(): array
    {
        $findings = [];
        $queues = [];
        $supported = 0;
        $failed = 0;
        $stale = 0;

        foreach (self::QUEUE_TABLES as $config) {
            $queue = $this->queueSummary($config, $findings);
            $queues[] = $queue;
            if (!empty($queue['supported'])) {
                $supported++;
                $failed += (int) ($queue['failed_count'] ?? 0);
                $stale += (int) ($queue['stale_pending_count'] ?? 0);
            }
        }

        if ($supported === 0) {
            $this->addFinding($findings, 'warning', 'queue_tables_unsupported', 'No supported runtime queue tables were found.', [
                'domain' => 'queues',
                'target' => 'queues',
                'evidence' => ['configured_queue_tables' => count(self::QUEUE_TABLES)],
                'recommendation' => 'Run migrations and confirm queue tables exist before enabling asynchronous runtime work.',
            ]);
        }

        return $this->domain('queues', 'Queues', $supported > 0, [
            'supported_queue_tables' => $supported,
            'failed_count' => $failed,
            'stale_pending_count' => $stale,
            'queues' => $queues,
        ], $findings);
    }

    /**
     * @param array<string,mixed> $config
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function queueSummary(array $config, array &$findings): array
    {
        $table = (string) ($config['table'] ?? '');
        $statusColumn = (string) ($config['status_column'] ?? 'status');
        if (!$this->tableHasColumns($table, [$statusColumn])) {
            return [
                'key' => (string) ($config['key'] ?? $table),
                'label' => (string) ($config['label'] ?? $table),
                'supported' => false,
                'pending_count' => 0,
                'failed_count' => 0,
                'stale_pending_count' => 0,
            ];
        }

        $timeExpression = $this->firstAvailableTimeExpression($table, (array) ($config['time_columns'] ?? ['created_at']));
        if ($timeExpression === '') {
            $timeExpression = 'NOW()';
        }
        $pendingStatuses = array_values(array_map('strval', (array) ($config['pending_statuses'] ?? ['pending'])));
        $failedStatuses = array_values(array_map('strval', (array) ($config['failed_statuses'] ?? ['failed'])));
        $statusIdentifier = $this->quoteIdentifier($statusColumn);

        $row = $this->queryOne(
            "SELECT
                COALESCE(SUM(CASE WHEN {$statusIdentifier} IN (" . $this->quotedList($pendingStatuses) . ") THEN 1 ELSE 0 END), 0) AS pending_count,
                COALESCE(SUM(CASE WHEN {$statusIdentifier} IN (" . $this->quotedList($failedStatuses) . ") THEN 1 ELSE 0 END), 0) AS failed_count,
                COALESCE(SUM(CASE WHEN {$statusIdentifier} IN (" . $this->quotedList($pendingStatuses) . ")
                    AND {$timeExpression} <= DATE_SUB(NOW(), INTERVAL " . self::QUEUE_STALE_HOURS . " HOUR)
                    THEN 1 ELSE 0 END), 0) AS stale_pending_count,
                MIN(CASE WHEN {$statusIdentifier} IN (" . $this->quotedList($pendingStatuses) . ") THEN {$timeExpression} END) AS oldest_pending_at
             FROM " . $this->quoteIdentifier($table)
        ) ?: [];

        $summary = [
            'key' => (string) ($config['key'] ?? $table),
            'label' => (string) ($config['label'] ?? $table),
            'supported' => true,
            'pending_count' => (int) ($row['pending_count'] ?? 0),
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'stale_pending_count' => (int) ($row['stale_pending_count'] ?? 0),
            'oldest_pending_at' => $row['oldest_pending_at'] ?? null,
        ];

        if ($summary['failed_count'] > 0) {
            $this->addFinding($findings, 'warning', 'queue_failed_items_present', 'A runtime queue has failed items.', [
                'domain' => 'queues',
                'target' => $summary['key'],
                'evidence' => ['failed_count' => $summary['failed_count']],
                'recommendation' => 'Review failed queue items and replay, cancel, or repair the worker before live upload.',
            ]);
        }
        if ($summary['stale_pending_count'] > 0) {
            $this->addFinding($findings, 'warning', 'queue_stale_pending_items', 'A runtime queue has stale pending or processing items.', [
                'domain' => 'queues',
                'target' => $summary['key'],
                'evidence' => [
                    'stale_pending_count' => $summary['stale_pending_count'],
                    'oldest_pending_at' => (string) ($summary['oldest_pending_at'] ?? ''),
                    'stale_threshold_hours' => self::QUEUE_STALE_HOURS,
                ],
                'recommendation' => 'Confirm the queue worker is running and drain or replay stale items before live upload.',
            ]);
        }

        return $summary;
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $context
     */
    private function addFinding(array &$findings, string $severity, string $rule, string $message, array $context): void
    {
        $findings[] = [
            'severity' => $severity,
            'rule' => $rule,
            'message' => $message,
        ] + $context;
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<int,array<string,mixed>> $findings
     * @return array<string,mixed>
     */
    private function domain(string $key, string $label, bool $supported, array $summary, array $findings): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'supported' => $supported,
            'status' => $this->statusForFindings($findings),
            'summary' => $summary,
            'findings' => $findings,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     */
    private function statusForFindings(array $findings): string
    {
        foreach ($findings as $finding) {
            if (($finding['severity'] ?? '') === 'critical') {
                return 'critical';
            }
        }
        return $findings === [] ? 'ok' : 'warning';
    }

    /**
     * @return array<string,mixed>
     */
    private function billingSettings(): array
    {
        $defaults = [
            'billing_mode' => 'central_hub',
            'paystack_mode' => 'test',
            'paystack_secret_key' => '',
            'paystack_webhook_url' => WorkspaceBillingSettings::generatedPaystackWebhookUrl(),
            'mpesa_enabled' => false,
            'mpesa_environment' => 'sandbox',
            'mpesa_consumer_key' => '',
            'mpesa_consumer_secret' => '',
            'mpesa_shortcode' => '',
            'mpesa_passkey' => '',
            'mpesa_callback_url' => WorkspaceBillingSettings::generatedMpesaCallbackUrl(),
            'payment_card_enabled' => false,
            'payment_mpesa_enabled' => false,
            'payment_bank_transfer_enabled' => true,
        ];

        if (!$this->tableExists('workspace_billing_settings')) {
            return $defaults;
        }

        $row = $this->queryOne('SELECT * FROM workspace_billing_settings WHERE id = 1') ?: [];
        return array_merge($defaults, $row);
    }

    /**
     * @param array<string,mixed> $settings
     */
    private function mpesaReady(array $settings): bool
    {
        $fakeMode = in_array(strtolower($this->envString(['MPESA_FAKE_MODE'])), ['1', 'true', 'yes', 'on'], true);
        $enabled = $this->envBool('MPESA_ENABLED', !empty($settings['mpesa_enabled'])) || $fakeMode;
        $consumerKey = $this->envString(['MPESA_CONSUMER_KEY'], (string) ($settings['mpesa_consumer_key'] ?? ''));
        $consumerSecret = $this->envString(['MPESA_CONSUMER_SECRET'], (string) ($settings['mpesa_consumer_secret'] ?? ''));
        $shortCode = $this->envString(['MPESA_BUSINESS_SHORTCODE', 'MPESA_SHORTCODE'], (string) ($settings['mpesa_shortcode'] ?? ''));
        $passkey = $this->envString(['MPESA_PASSKEY'], (string) ($settings['mpesa_passkey'] ?? ''));

        return $enabled && $consumerKey !== '' && $consumerSecret !== '' && $shortCode !== '' && $passkey !== '';
    }

    /**
     * @param mixed $value
     */
    private function secretPresent(mixed $value): bool
    {
        if (is_array($value)) {
            if (!empty($value['encrypted']) && trim((string) ($value['value'] ?? '')) !== '') {
                return true;
            }
            foreach ($value as $nested) {
                if ($this->secretPresent($nested)) {
                    return true;
                }
            }
            return false;
        }
        return trim((string) $value) !== '';
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<int,string> $keys
     */
    private function hasAnyValue(array $settings, array $keys): bool
    {
        foreach ($keys as $key) {
            if ($this->secretPresent($settings[$key] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private function safeProviderEvidence(array $row): array
    {
        return array_filter([
            'workspace_id' => isset($row['workspace_id']) ? (int) $row['workspace_id'] : null,
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
            'provider' => isset($row['provider']) ? (string) $row['provider'] : null,
            'scope' => isset($row['scope']) ? (string) $row['scope'] : null,
            'oauth_grant_type' => isset($row['oauth_grant_type']) ? (string) $row['oauth_grant_type'] : null,
            'scope_status' => isset($row['scope_status']) ? (string) $row['scope_status'] : null,
            'reconnect_required' => isset($row['reconnect_required']) ? (bool) $row['reconnect_required'] : null,
        ], static fn(mixed $value): bool => $value !== null && $value !== '');
    }

    private function rowTarget(string $table, array $row): string
    {
        if (isset($row['workspace_id'])) {
            return $table . ':workspace:' . (int) $row['workspace_id'];
        }
        if (isset($row['user_id'])) {
            return $table . ':user:' . (int) $row['user_id'];
        }
        if (isset($row['id'])) {
            return $table . ':' . (int) $row['id'];
        }
        return $table;
    }

    private function expiresWithin(mixed $value): bool
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return false;
        }
        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            return false;
        }
        return $timestamp <= time() + (self::EXPIRING_CREDENTIAL_DAYS * 86400);
    }

    private function isOlderThan(mixed $value, int $hours): bool
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return true;
        }
        $timestamp = strtotime($raw);
        return $timestamp === false || $timestamp <= time() - ($hours * 3600);
    }

    private function isOlderThanMinutes(mixed $value, int $minutes): bool
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return true;
        }
        $timestamp = strtotime($raw);
        return $timestamp === false || $timestamp <= time() - ($minutes * 60);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int,string> $desired
     * @return array<int,string>
     */
    private function existingColumns(string $table, array $desired): array
    {
        return array_values(array_filter(
            $desired,
            fn(string $column): bool => $this->columnExists($table, $column)
        ));
    }

    /**
     * @param array<int,string> $columns
     */
    private function tableHasColumns(string $table, array $columns): bool
    {
        if (!$this->tableExists($table)) {
            return false;
        }
        foreach ($columns as $column) {
            if (!$this->columnExists($table, $column)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int,string> $columns
     */
    private function firstAvailableTimeExpression(string $table, array $columns): string
    {
        $available = $this->existingColumns($table, $columns);
        if ($available === []) {
            return '';
        }
        if (count($available) === 1) {
            return $this->quoteIdentifier($available[0]);
        }
        return 'COALESCE(' . implode(', ', array_map([$this, 'quoteIdentifier'], $available)) . ')';
    }

    /**
     * @param array<int,string> $values
     */
    private function quotedList(array $values): string
    {
        if ($values === []) {
            return "''";
        }
        return implode(', ', array_map([$this, 'quote'], $values));
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function query(string $sql, array $params = []): array
    {
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return Database::query($sql, $params);
    }

    /**
     * @param array<int,mixed> $params
     * @return array<string,mixed>|null
     */
    private function queryOne(string $sql, array $params = []): ?array
    {
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        return Database::queryOne($sql, $params);
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->query('SHOW TABLES LIKE ' . $this->quote($table));
            return (bool) ($stmt && $stmt->fetch(PDO::FETCH_NUM));
        }
        return Database::tableExists($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table) . ' WHERE Field = ' . $this->quote($column));
            return (bool) ($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
        }
        return Database::columnExists($table, $column);
    }

    private function quote(string $value): string
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo->quote($value);
        }
        return Database::getInstance()->quote($value);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * @param array<int,string> $keys
     */
    private function envString(array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value === false) {
                $value = $_ENV[$key] ?? null;
            }
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }
        return $default;
    }

    private function envBool(string $key, bool $default = false): bool
    {
        $value = getenv($key);
        if ($value === false) {
            $value = $_ENV[$key] ?? null;
        }
        if ($value === null || trim((string) $value) === '') {
            return $default;
        }
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function liveSeverity(): string
    {
        return strtolower($this->envString(['APP_ENV'], 'local')) === 'production' ? 'critical' : 'warning';
    }
}
