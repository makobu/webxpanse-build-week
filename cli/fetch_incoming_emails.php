<?php
/**
 * Fetch Incoming Emails Worker
 * 
 * CLI script to fetch incoming emails from IMAP/POP3
 * Run via cron every 5 minutes, for example:
 * cron expression: star-slash-5 * * * * php /path/to/cli/fetch_incoming_emails.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Remove quotes if present
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || 
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\EmailFetcher;
use CRM\Services\EmailIntegrationService;
use CRM\Services\AutomationJobHealthService;
use CRM\Modules\EmailAssistantHandler;

// Initialize database
try {
    $dbConfig = require __DIR__ . '/../config/database.php';
    Database::init($dbConfig);
} catch (\Exception $e) {
    echo "Database initialization error: " . $e->getMessage() . "\n";
    exit(1);
}

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from command line.\n";
    exit(1);
}

// Run workspace customer mailboxes and assistant fetches when configured.
$emailIntegrationService = new EmailIntegrationService();
$workspaceEmailIntegrations = $emailIntegrationService->listActiveRoleIntegrations(['outreach', 'nurture']);
$assistantWorkspaceIds = EmailFetcher::getAssistantEnabledWorkspaceIds();

$workspaceEmailEnabled = !empty($workspaceEmailIntegrations);
$assistantEnabled = $assistantWorkspaceIds !== [];
$jobHealth = new AutomationJobHealthService();
$jobStartedAt = microtime(true);
$jobHealth->markStarted('email_assistant_inbound', [
    'assistant_workspace_count' => count($assistantWorkspaceIds),
    'email_workspace_count' => count($workspaceEmailIntegrations),
]);

if (!$workspaceEmailEnabled && !$assistantEnabled) {
    echo "Neither workspace Outreach/Nurture mail nor Assistant IMAP is enabled or configured.\n";
    echo "Configure Outreach/Nurture in Marketplace -> Email or assistant mail in Marketplace -> Email Assistant.\n";
    $jobHealth->markSuccess('email_assistant_inbound', 'No configured inboxes.', 0, ['assistant_workspace_count' => 0]);
    exit(0);
}

try {
    $totalProcessed = 0;
    $totalSkipped = 0;
    $totalErrors = 0;

    // --- Workspace Email inboxes (contact flow) ---
    if ($workspaceEmailEnabled) {
        foreach ($workspaceEmailIntegrations as $integration) {
            $workspaceId = (int) ($integration['workspace_id'] ?? 0);
            $workspaceName = trim((string) ($integration['workspace_name'] ?? $integration['workspace_slug'] ?? ('workspace-' . $workspaceId)));
            $scope = (string) ($integration['scope'] ?? EmailIntegrationService::SCOPE_OUTREACH_EMAIL);
            $profile = $scope === EmailIntegrationService::SCOPE_NURTURE_EMAIL ? 'nurture' : 'outreach';
            $profileLabel = $profile === 'nurture' ? 'Nurture Email' : 'Outreach Email';

            try {
                $workspaceSummary = AsyncWorkspaceRunner::runWithWorkspace(
                    $workspaceId,
                    static function () use ($emailIntegrationService, $workspaceId, $workspaceName, $profile, $profileLabel): array {
                        $workspaceFetcher = new EmailFetcher($profile);
                        $providerSummary = $profile === 'nurture'
                            ? $emailIntegrationService->getNurtureProviderSummary($workspaceId)
                            : $emailIntegrationService->getOutreachProviderSummary($workspaceId);
                        echo "[" . date('Y-m-d H:i:s') . "] Starting {$profileLabel} fetch for workspace {$workspaceName} via " . ($providerSummary['provider_label'] ?? $workspaceFetcher->getProviderKey()) . "...\n";

                        if (!$workspaceFetcher->isEnabled()) {
                            echo "[" . date('Y-m-d H:i:s') . "] Skipping {$profileLabel} for workspace {$workspaceName}: provider not enabled after workspace activation.\n";
                            return [
                                'processed' => 0,
                                'skipped' => 0,
                                'errors' => 0,
                            ];
                        }

                        $workspaceFetchLogId = $workspaceFetcher->startImportRun(null, 'worker');
                        $summary = [
                            'emails_found' => 0,
                            'processed' => 0,
                            'skipped' => 0,
                            'auto_created' => 0,
                            'errors' => 0,
                        ];

                        try {
                            $workspaceFetcher->connect();
                            echo "[" . date('Y-m-d H:i:s') . "] Connected to {$profileLabel} inbox provider for workspace {$workspaceName}.\n";
                            $emails = $workspaceFetcher->fetchNewEmails();
                            $summary['emails_found'] = count($emails);

                            if (empty($emails)) {
                                echo "[" . date('Y-m-d H:i:s') . "] No new {$profileLabel} emails for workspace {$workspaceName}.\n";
                                $workspaceFetcher->commitFetchCheckpoint();
                                $workspaceFetcher->finalizeImportRun($workspaceFetchLogId, $summary);
                                return [
                                    'processed' => 0,
                                    'skipped' => 0,
                                    'errors' => 0,
                                ];
                            }

                            echo "[" . date('Y-m-d H:i:s') . "] Found " . count($emails) . " {$profileLabel} email(s) for workspace {$workspaceName}.\n";

                            foreach ($emails as $emailData) {
                                try {
                                    $result = $workspaceFetcher->importFetchedEmail($emailData, $workspaceFetchLogId, true);
                                    if (($result['outcome'] ?? '') === 'auto_created') {
                                        $summary['auto_created']++;
                                        echo "[" . date('Y-m-d H:i:s') . "] Created new contact in {$workspaceName}: {$emailData['from_email']}\n";
                                    } elseif (($result['outcome'] ?? '') === 'skipped') {
                                        $summary['skipped']++;
                                        echo "[" . date('Y-m-d H:i:s') . "] Skipping email from {$emailData['from_email']} in {$workspaceName} ({$result['reason_code']})\n";
                                        continue;
                                    } else {
                                        $summary['processed']++;
                                    }

                                    $contactId = (int) ($result['contact_id'] ?? 0);
                                    $commId = (int) ($result['communication_id'] ?? 0);

                                    try {
                                        $orchestrator = new \CRM\Services\DealAutomationOrchestrator();
                                        $orchestrator->runForCommunication($contactId, $commId, 'communication');
                                    } catch (\Throwable $e) {
                                        error_log("Deal automation (email): " . $e->getMessage());
                                    }

                                    echo "[" . date('Y-m-d H:i:s') . "] Processed email in {$workspaceName}: {$emailData['subject']} from {$emailData['from_email']}\n";
                                } catch (\Exception $e) {
                                    echo "[" . date('Y-m-d H:i:s') . "] Error processing email from {$emailData['from_email']} in {$workspaceName}: " . $e->getMessage() . "\n";
                                    $summary['errors']++;
                                    error_log("Email fetch error: " . $e->getMessage());
                                }
                            }

                            if ($summary['errors'] === 0) {
                                $workspaceFetcher->commitFetchCheckpoint();
                            } else {
                                $workspaceFetcher->discardFetchCheckpoint();
                            }

                            $workspaceFetcher->finalizeImportRun(
                                $workspaceFetchLogId,
                                $summary,
                                $summary['errors'] > 0 ? 'Worker import completed with errors.' : null
                            );
                            echo "[" . date('Y-m-d H:i:s') . "] {$profileLabel} fetch completed for {$workspaceName}. Processed: {$summary['processed']}, Skipped: {$summary['skipped']}, Errors: {$summary['errors']}\n";
                        } catch (\Throwable $e) {
                            $workspaceFetcher->discardFetchCheckpoint();
                            $summary['errors']++;
                            $workspaceFetcher->recordRunFailure($workspaceFetchLogId, $e->getMessage(), $summary);
                            throw $e;
                        } finally {
                            $workspaceFetcher->disconnect();
                        }

                        return [
                            'processed' => (int) $summary['processed'] + (int) $summary['auto_created'],
                            'skipped' => (int) $summary['skipped'],
                            'errors' => (int) $summary['errors'],
                        ];
                    },
                    null,
                    'Active email integration is missing a valid workspace.'
                );

                $totalProcessed += (int) ($workspaceSummary['processed'] ?? 0);
                $totalSkipped += (int) ($workspaceSummary['skipped'] ?? 0);
                $totalErrors += (int) ($workspaceSummary['errors'] ?? 0);
            } catch (\Throwable $e) {
                $totalErrors++;
                error_log('Workspace email fetch failure: ' . $e->getMessage());
                echo "[" . date('Y-m-d H:i:s') . "] Workspace email fetch failed for {$workspaceName}: " . $e->getMessage() . "\n";
            }
        }
    }

    // --- Assistant inbox (instruction flow) ---
    if ($assistantEnabled) {
        foreach ($assistantWorkspaceIds as $assistantWorkspaceId) {
            try {
                $workspaceSummary = AsyncWorkspaceRunner::runWithWorkspace(
                    $assistantWorkspaceId,
                    static function () use ($assistantWorkspaceId): array {
                        $fetcher = new EmailFetcher('assistant');
                        if (!$fetcher->isEnabled()) {
                            return ['processed' => 0, 'errors' => 0, 'skipped' => 1];
                        }

                        echo "[" . date('Y-m-d H:i:s') . "] Starting assistant inbox fetch for workspace {$assistantWorkspaceId}...\n";
                        $processed = 0;
                        $errors = 0;
                        try {
                            $fetcher->connect();
                            $emails = $fetcher->fetchNewEmails();
                            foreach ($emails as $emailData) {
                                try {
                                    (new EmailAssistantHandler())->handleInbound($emailData);
                                    $processed++;
                                    echo "[" . date('Y-m-d H:i:s') . "] Workspace {$assistantWorkspaceId}: processed assistant email from {$emailData['from_email']}\n";
                                } catch (\Throwable $e) {
                                    $errors++;
                                    error_log("Assistant email fetch error for workspace {$assistantWorkspaceId}: " . $e->getMessage());
                                }
                            }
                            if ($errors === 0) {
                                $fetcher->commitFetchCheckpoint();
                            } else {
                                $fetcher->discardFetchCheckpoint();
                            }
                        } catch (\Throwable $e) {
                            $fetcher->discardFetchCheckpoint();
                            throw $e;
                        } finally {
                            $fetcher->disconnect();
                        }

                        return ['processed' => $processed, 'errors' => $errors, 'skipped' => 0];
                    },
                    null,
                    'Assistant inbox workspace is missing or inactive.'
                );
                $totalProcessed += (int) ($workspaceSummary['processed'] ?? 0);
                $totalErrors += (int) ($workspaceSummary['errors'] ?? 0);
                $totalSkipped += (int) ($workspaceSummary['skipped'] ?? 0);
            } catch (\Throwable $e) {
                $totalErrors++;
                error_log("Assistant inbox workspace {$assistantWorkspaceId} failed: " . $e->getMessage());
            }
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] All fetches done. Processed: $totalProcessed, Skipped: $totalSkipped, Errors: $totalErrors\n";
    $durationMs = (int) round((microtime(true) - $jobStartedAt) * 1000);
    if ($totalErrors > 0) {
        $jobHealth->markFailure('email_assistant_inbound', "Completed with {$totalErrors} error(s).", $durationMs, ['processed' => $totalProcessed, 'skipped' => $totalSkipped]);
    } else {
        $jobHealth->markSuccess('email_assistant_inbound', "Processed {$totalProcessed}; skipped {$totalSkipped}.", $durationMs, ['assistant_workspace_count' => count($assistantWorkspaceIds)]);
    }
    
} catch (\Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] Fatal error: " . $e->getMessage() . "\n";
    error_log("Email fetch fatal error: " . $e->getMessage());
    $jobHealth->markFailure('email_assistant_inbound', $e->getMessage(), (int) round((microtime(true) - $jobStartedAt) * 1000));
    exit(1);
}

exit(0);
