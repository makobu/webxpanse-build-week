<?php
/**
 * Process Pending Emails API Endpoint
 * Processes pending emails from the queue
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Security;
use CRM\Services\EmailService;
use CRM\Services\EmailQueue;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceContext;

// Initialize database
$dbConfig = require __DIR__ . '/../config/database.php';
Database::init($dbConfig);

// Start session
Session::start();

// Set JSON header early
header('Content-Type: application/json');

// Require authentication - return JSON error for API
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$communicationGate = new WorkspaceCommunicationGateService();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'An active workspace is required.']);
    exit;
}
if (!$communicationGate->isRuntimeReady($workspaceId, Auth::user())) {
    http_response_code(403);
    echo json_encode($communicationGate->jsonBlockPayload($workspaceId, Auth::user()), JSON_UNESCAPED_SLASHES);
    exit;
}

Authorization::requirePermission('settings.email_assistant', true);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$emailService = new EmailService();
$queue = new EmailQueue();

try {
    // Trace requests to diagnose UI "click does nothing" reports.
    error_log(
        "process_emails.php request: method={$method}, user_id=" . ($_SESSION['user_id'] ?? 'none') .
        ", email_id=" . ($_POST['email_id'] ?? '') . ", limit=" . ($_POST['limit'] ?? '')
    );

    switch ($method) {
        case 'POST':
            $csrfToken = (string) ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
            if (!Security::validateCSRF($csrfToken)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid security token']);
                exit;
            }
            Session::closeWrite();

            // Process pending emails
            $limit = (int) ($_POST['limit'] ?? 10);
            if ($limit < 1) {
                $limit = 1;
            }
            if ($limit > 200) {
                $limit = 200;
            }
            $emailId = $_POST['email_id'] ?? null;
            $emailUuid = trim((string) ($_POST['email_uuid'] ?? ''));
            
            if ($emailId || $emailUuid !== '') {
                // Process a specific email through the same leased queue claim as
                // the CLI worker. Direct sends here used to race a background worker.
                $emailId = (int) $emailId;
                if ($emailId > 0) {
                    $email = Database::queryOne(
                        "SELECT e.*, eq.id AS queue_id
                         FROM emails e 
                         LEFT JOIN email_queue eq
                           ON e.id = eq.email_id
                          AND (eq.workspace_id IS NULL OR eq.workspace_id = e.workspace_id)
                         WHERE e.id = ?
                           AND e.workspace_id = ?
                           AND e.status = 'pending'",
                        [$emailId, $workspaceId]
                    );
                } else {
                    $email = Database::queryOne(
                        "SELECT e.*, eq.id AS queue_id
                         FROM emails e 
                         LEFT JOIN email_queue eq
                           ON e.id = eq.email_id
                          AND (eq.workspace_id IS NULL OR eq.workspace_id = e.workspace_id)
                         WHERE e.uuid = ?
                           AND e.workspace_id = ?
                           AND e.status = 'pending'
                         LIMIT 1",
                        [$emailUuid, $workspaceId]
                    );
                    $emailId = (int) ($email['id'] ?? 0);
                }
                
                if (!$email) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Email not found or already processed']);
                    exit;
                }

                $queueId = (int) ($email['queue_id'] ?? 0);
                if ($queueId <= 0) {
                    $queueId = $queue->push($emailId, 0, null, $workspaceId);
                }
                $job = $queue->pop($workspaceId, $queueId);
                if (!$job) {
                    http_response_code(409);
                    echo json_encode(['error' => 'This email is already being processed or is not ready to run.']);
                    exit;
                }

                try {
                    $lastError = null;
                    $success = $emailService->processEmail((int) $job['email_id'], $lastError, ['queue_claim_managed' => true], $workspaceId);
                    
                    if ($success) {
                        $queue->ack($queueId, $workspaceId, $job['claim_token'] ?? null);
                        echo json_encode([
                            'success' => true,
                            'message' => 'Email sent successfully',
                            'email_id' => $emailId
                        ]);
                    } else {
                        if ((int) ($job['attempts'] ?? 0) < (int) ($job['max_attempts'] ?? 3)) {
                            $queue->retry($queueId, $workspaceId, $job['claim_token'] ?? null);
                        } else {
                            $queue->nack($queueId, $lastError ?: 'Max attempts reached', $workspaceId, $job['claim_token'] ?? null);
                        }
                        // Get error message from email record
                        $failedEmail = Database::queryOne(
                            "SELECT error_message
                             FROM emails
                             WHERE id = ?
                               AND workspace_id = ?",
                            [$emailId, $workspaceId]
                        );
                        $errorMsg = $failedEmail['error_message'] ?? 'Failed to send email. Check SMTP configuration.';
                        
                        http_response_code(500);
                        echo json_encode([
                            'success' => false,
                            'error' => $errorMsg,
                            'email_id' => $emailId
                        ]);
                    }
                } catch (\Throwable $e) {
                    try {
                        if ((int) ($job['attempts'] ?? 0) < (int) ($job['max_attempts'] ?? 3)) {
                            $queue->retry($queueId, $workspaceId, $job['claim_token'] ?? null);
                        } else {
                            $queue->nack($queueId, $e->getMessage(), $workspaceId, $job['claim_token'] ?? null);
                        }
                    } catch (\Throwable $claimError) {
                        error_log('Email queue claim finalization failed: ' . $claimError->getMessage());
                    }
                    http_response_code(500);
                    echo json_encode([
                        'success' => false,
                        'error' => 'Error processing email: ' . $e->getMessage(),
                        'email_id' => $emailId
                    ]);
                }
            } else {
                // Process a workspace-scoped batch through leased queue claims.
                $processed = 0;
                $failed = 0;
                $errors = [];
                for ($i = 0; $i < $limit; $i++) {
                    $job = $queue->pop($workspaceId);
                    if (!$job) {
                        $orphan = Database::queryOne(
                            "SELECT e.id
                             FROM emails e
                             LEFT JOIN email_queue eq ON eq.email_id = e.id
                             WHERE e.workspace_id = ?
                               AND e.status = 'pending'
                               AND eq.id IS NULL
                             ORDER BY e.created_at ASC, e.id ASC
                             LIMIT 1",
                            [$workspaceId]
                        );
                        if (!$orphan) {
                            break;
                        }
                        $newQueueId = $queue->push((int) $orphan['id'], 0, null, $workspaceId);
                        $job = $queue->pop($workspaceId, $newQueueId);
                        if (!$job) {
                            continue;
                        }
                    }

                    $pendingEmailId = (int) ($job['email_id'] ?? 0);
                    try {
                        $lastError = null;
                        $success = $emailService->processEmail($pendingEmailId, $lastError, ['queue_claim_managed' => true], $workspaceId);
                        if ($success) {
                            $queue->ack((int) $job['queue_id'], $workspaceId, $job['claim_token'] ?? null);
                            $processed++;
                        } else {
                            if ((int) ($job['attempts'] ?? 0) < (int) ($job['max_attempts'] ?? 3)) {
                                $queue->retry((int) $job['queue_id'], $workspaceId, $job['claim_token'] ?? null);
                            } else {
                                $queue->nack((int) $job['queue_id'], $lastError ?: 'Max attempts reached', $workspaceId, $job['claim_token'] ?? null);
                            }
                            $failed++;
                            $errors[] = "Email ID {$pendingEmailId} failed to send";
                        }
                    } catch (\Throwable $e) {
                        try {
                            if ((int) ($job['attempts'] ?? 0) < (int) ($job['max_attempts'] ?? 3)) {
                                $queue->retry((int) $job['queue_id'], $workspaceId, $job['claim_token'] ?? null);
                            } else {
                                $queue->nack((int) $job['queue_id'], $e->getMessage(), $workspaceId, $job['claim_token'] ?? null);
                            }
                        } catch (\Throwable $claimError) {
                            error_log('Email queue claim finalization failed: ' . $claimError->getMessage());
                        }
                        $failed++;
                        $errors[] = "Email ID {$pendingEmailId}: " . $e->getMessage();
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'processed' => $processed,
                    'failed' => $failed,
                    'errors' => $errors,
                    'message' => "Processed $processed email(s), $failed failed"
                ]);
            }
            break;
            
        case 'GET':
            Session::closeWrite();
            // Get queue statistics
            $stats = $queue->getStats($workspaceId);
            
            // Get pending emails list
            $pendingEmails = Database::query(
                "SELECT e.id, e.uuid, e.to_email, e.subject, e.status, e.created_at,
                        eq.id as queue_id, eq.priority, eq.attempts
                 FROM emails e
                 LEFT JOIN email_queue eq
                   ON e.id = eq.email_id
                  AND (eq.workspace_id IS NULL OR eq.workspace_id = e.workspace_id)
                 WHERE e.workspace_id = ?
                   AND e.status = 'pending'
                 ORDER BY eq.priority DESC, e.created_at DESC, e.id DESC
                 LIMIT 50",
                [$workspaceId]
            );
            
            echo json_encode([
                'stats' => $stats,
                'pending_emails' => $pendingEmails
            ], JSON_PRETTY_PRINT);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    error_log("process_emails.php error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode([
        'success' => false,
        'error' => 'Server error',
        'message' => 'Email processing failed. Review the server log for the internal error reference.'
    ]);
}
