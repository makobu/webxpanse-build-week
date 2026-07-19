<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Security;
use CRM\Services\GuidedDemoActionService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    guidedDemoJson(['success' => false, 'error' => 'Method not allowed'], 405);
}
if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    guidedDemoJson(['success' => false, 'error' => 'Invalid security token.'], 403);
}

$stepKey = trim((string) ($_POST['step_key'] ?? ''));
$actionKey = trim((string) ($_POST['action_key'] ?? ''));
$choiceKey = trim((string) ($_POST['choice_key'] ?? ''));
if ($stepKey === '' || $actionKey === '') {
    guidedDemoJson(['success' => false, 'error' => 'Demo step and action are required.'], 422);
}

try {
    $payload = (new GuidedDemoActionService())->perform($guidedDemoWorkspaceId, $guidedDemoUserId, $stepKey, $actionKey, $choiceKey);
    guidedDemoJson(['success' => true, 'data' => $payload]);
} catch (Throwable $e) {
    guidedDemoJson(['success' => false, 'error' => $e->getMessage()], 422);
}
