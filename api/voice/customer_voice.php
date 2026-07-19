<?php

require_once __DIR__ . '/_bootstrap.php';

voiceApiRequire('voice.customer_voice.review');
$service = new \CRM\Services\CustomerVoiceAggregationService();

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        voiceApiRequireCsrf($voiceInput);
        $service->review(
            $voiceWorkspaceId,
            max(1, (int) ($voiceInput['insight_id'] ?? 0)),
            $voiceUserId,
            (string) ($voiceInput['status'] ?? '')
        );
        echo json_encode(['success' => true, 'message' => 'Customer Voice review saved.']);
        exit;
    }
    echo json_encode(['success' => true, 'insights' => $service->reviewable($voiceWorkspaceId, (int) ($_GET['limit'] ?? 100))]);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
