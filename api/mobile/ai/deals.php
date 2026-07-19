<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/_helpers.php';

use CRM\Modules\Deals;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$dealId = (int) ($_GET['deal_id'] ?? $_GET['id'] ?? 0);
if ($dealId <= 0) {
    mobileJson(['error' => 'Deal id is required.'], 422);
}

$deal = (new Deals())->getById($dealId);
if (!$deal || !in_array($userId, array_filter([(int) ($deal['assigned_to'] ?? 0), (int) ($deal['created_by'] ?? 0)]), true)) {
    mobileJson(['error' => 'Deal not found or not accessible.'], 404);
}

mobileJson([
    'success' => true,
    'data' => mobileAiDealInsights($dealId, $userId),
]);
