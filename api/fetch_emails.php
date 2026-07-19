<?php
/**
 * Fetch Emails API Endpoint
 * Manually trigger email fetching
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
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || 
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\EmailIntegrationService;
use CRM\Services\EmailFetcher;
use CRM\Services\EmailService;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceContext;

header('Content-Type: application/json');
try {
    $dbConfig = require __DIR__ . '/../config/database.php';
    Database::init($dbConfig);
    Auth::requireAuth();
    $communicationGate = new WorkspaceCommunicationGateService();
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    if (!$communicationGate->isRuntimeReady($workspaceId, Auth::user())) {
        http_response_code(403);
        echo json_encode($communicationGate->jsonBlockPayload($workspaceId, Auth::user()), JSON_UNESCAPED_SLASHES);
        exit;
    }
} catch (\Throwable $e) {
    error_log('Fetch emails bootstrap failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Email fetch is currently unavailable.',
        'code' => 'FETCH_EMAILS_BOOTSTRAP_FAILED'
    ], JSON_PRETTY_PRINT);
    exit;
}

Authorization::requirePermission('settings.email_assistant', true);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}
$requestPayload = json_decode((string) file_get_contents('php://input'), true);
$requestPayload = is_array($requestPayload) ? $requestPayload : [];
$csrfToken = (string) ($requestPayload['csrf_token'] ?? $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
if (!Security::validateCSRF($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}
Session::closeWrite();

try {
    $integrationService = new EmailIntegrationService();
    $profileSummaries = [
        'outreach' => $integrationService->getOutreachProviderSummary($workspaceId),
        'nurture' => $integrationService->getNurtureProviderSummary($workspaceId),
    ];
    $fetchers = [];
    foreach ($profileSummaries as $profile => $summary) {
        $candidate = new EmailFetcher($profile);
        if ($candidate->isEnabled()) {
            $fetchers[] = [
                'profile' => $profile,
                'label' => $profile === 'nurture' ? 'Nurture Email' : 'Outreach Email',
                'summary' => $summary,
                'fetcher' => $candidate,
            ];
        }
    }

    if ($fetchers === []) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'No Outreach or Nurture inbox is configured for this workspace. Configure IMAP or OAuth in Marketplace -> Email.',
            'configured' => false,
            'profiles' => $profileSummaries,
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $processed = 0;
    $skipped = 0;
    $autoCreated = 0;
    $errors = 0;
    $errorMessages = [];
    $emailsFound = 0;
    $profileResults = [];
    $activeFetcher = null;
    $fetchLogId = 0;

    foreach ($fetchers as $entry) {
        /** @var EmailFetcher $fetcher */
        $fetcher = $entry['fetcher'];
        $activeFetcher = $fetcher;
        $fetchLogId = $fetcher->startImportRun((int) (Auth::user()['id'] ?? 0), 'manual');
        $profileEmails = [];
        $profileProcessed = 0;
        $profileSkipped = 0;
        $profileAutoCreated = 0;
        $profileErrors = 0;

        try {
            $fetcher->connect();
            $profileEmails = $fetcher->fetchNewEmails();
            $emailsFound += count($profileEmails);

            foreach ($profileEmails as $emailData) {
                try {
                    $result = $fetcher->importFetchedEmail($emailData, $fetchLogId, true);
                    if (($result['outcome'] ?? '') === 'auto_created') {
                        $autoCreated++;
                        $profileAutoCreated++;
                    } elseif (($result['outcome'] ?? '') === 'skipped') {
                        $skipped++;
                        $profileSkipped++;
                    } else {
                        $processed++;
                        $profileProcessed++;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    $profileErrors++;
                    $errorMessages[] = $entry['label'] . ' could not import one message.';
                    error_log("Email fetch error: " . $e->getMessage());
                }
            }

            if ($profileErrors === 0) {
                $fetcher->commitFetchCheckpoint();
            } else {
                $fetcher->discardFetchCheckpoint();
            }

            $fetcher->finalizeImportRun($fetchLogId, [
                'emails_found' => count($profileEmails),
                'processed' => $profileProcessed,
                'skipped' => $profileSkipped,
                'auto_created' => $profileAutoCreated,
                'errors' => $profileErrors,
            ], $profileErrors > 0 ? implode("\n", array_slice($errorMessages, 0, 10)) : null);
        } catch (\Throwable $e) {
            $fetcher->discardFetchCheckpoint();
            $errors++;
            $profileErrors++;
            $errorMessages[] = $entry['label'] . ' fetch failed.';
            $fetcher->recordRunFailure($fetchLogId, $e->getMessage(), [
                'emails_found' => count($profileEmails),
                'processed' => $profileProcessed,
                'skipped' => $profileSkipped,
                'auto_created' => $profileAutoCreated,
                'errors' => $profileErrors,
            ]);
            error_log("Email fetch error: " . $e->getMessage());
        } finally {
            $fetcher->disconnect();
            $profileResults[] = [
                'profile' => $entry['profile'],
                'label' => $entry['label'],
                'provider' => $entry['summary']['provider_key'] ?? $fetcher->getProviderKey(),
                'provider_label' => $entry['summary']['provider_label'] ?? $fetcher->getProviderKey(),
                'emails_found' => count($profileEmails),
                'processed' => $profileProcessed,
                'skipped' => $profileSkipped,
                'auto_created' => $profileAutoCreated,
                'errors' => $profileErrors,
            ];
        }
    }

    $syncedSent = 0;
    try {
        $emailService = new EmailService();
        $syncedSent = (int) $emailService->syncMissingSentEmails(200, $workspaceId);
    } catch (\Throwable $syncError) {
        error_log('Fetch emails sent sync warning: ' . $syncError->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Email fetch completed',
        'provider' => count($profileResults) === 1 ? ($profileResults[0]['provider'] ?? 'workspace_email') : 'workspace_email',
        'provider_label' => count($profileResults) === 1 ? ($profileResults[0]['provider_label'] ?? 'Workspace Email') : 'Workspace Email',
        'profiles' => $profileResults,
        'emails_found' => $emailsFound,
        'processed' => $processed,
        'skipped' => $skipped,
        'auto_created' => $autoCreated,
        'synced_sent' => $syncedSent,
        'errors' => $errors,
        'error_messages' => $errorMessages
    ], JSON_PRETTY_PRINT);
    
} catch (\Exception $e) {
    if (isset($activeFetcher, $fetchLogId) && $fetchLogId > 0) {
        $activeFetcher->recordRunFailure($fetchLogId, $e->getMessage(), [
            'emails_found' => $emailsFound ?? 0,
            'processed' => $processed ?? 0,
            'skipped' => $skipped ?? 0,
            'auto_created' => $autoCreated ?? 0,
            'errors' => ($errors ?? 0) + 1,
        ]);
    }
    error_log('Fetch emails endpoint failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Email fetch failed. Check the workspace email connection and try again.'
    ], JSON_PRETTY_PRINT);
}
