<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\OrganizationIntelligenceConversationService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();
header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
if (!Authorization::can('hr.analytics.view', $user)) {
    http_response_code(403);
    echo json_encode(['error' => 'Organization Intelligence access is required.']);
    exit;
}

try {
    $workspaceId = (new AnalyticsWorkspaceService())->requireAnalyticsWorkspaceId();
    $userId = (int) ($user['id'] ?? 0);
    $service = new OrganizationIntelligenceConversationService();
    $requestedId = !empty($_GET['conversation_id']) ? (int) $_GET['conversation_id'] : null;

    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!Security::validateCSRF($token)) {
            throw new RuntimeException('Invalid security token.');
        }
        if (!$requestedId) {
            throw new InvalidArgumentException('Conversation ID is required.');
        }
        $conversation = Database::queryOne(
            "SELECT id FROM organization_intelligence_conversations
             WHERE id = ? AND workspace_id = ? AND user_id = ? AND surface = 'organization_intelligence' AND status = 'active' LIMIT 1",
            [$requestedId, $workspaceId, $userId]
        );
        if (!$conversation) {
            throw new RuntimeException('Conversation is outside your private scope.');
        }
        $service->clear($requestedId, $workspaceId, $userId);
        echo json_encode(['success' => true, 'cleared_conversation_id' => $requestedId]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $conversation = $service->currentOrCreate($workspaceId, $userId, $requestedId);
    $conversationId = (int) ($conversation['id'] ?? 0);
    echo json_encode([
        'conversation_id' => $conversationId,
        'rolling_summary' => (string) ($conversation['rolling_summary'] ?? ''),
        'messages' => $service->history($conversationId, $workspaceId, $userId, 20),
        'retention_days' => 90,
    ], JSON_UNESCAPED_SLASHES);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('Organization Intelligence conversation API error: ' . $e->getMessage());
    echo json_encode(['error' => 'Conversation could not be loaded.']);
}
