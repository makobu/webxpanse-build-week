<?php

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Session;
use CRM\Services\AIOperatingContextService;
use CRM\Services\AIQualificationPolicyService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int) (Auth::user()['id'] ?? 0);
$surface = (string) ($_GET['surface'] ?? 'coach');
$context = (new AIOperatingContextService())->buildForSurface($userId, $surface);
$policy = new AIQualificationPolicyService();

echo json_encode([
    'success' => true,
    'qualification' => $policy->evaluateAdviceEligibility($context),
    'mode' => $context['qualification_state']['effective_mode'] ?? '1',
    'reason_codes' => $context['qualification_state']['reason_codes'] ?? [],
]);
