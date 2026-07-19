<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/_helpers.php';
require_once __DIR__ . '/../_conversation_resolver.php';

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Deals;
use CRM\Modules\Tasks;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$contactId = (int) ($_GET['contact_id'] ?? $_GET['id'] ?? 0);
if ($contactId <= 0) {
    mobileJson(['error' => 'Contact id is required.'], 422);
}

$contact = (new Contacts())->getById($contactId);
if (!$contact || !mobileCanAccessContact($contact, $userId, $user)) {
    mobileJson(['error' => 'Contact not found or not accessible.'], 404);
}

mobileJson([
    'success' => true,
    'data' => mobileAiContactInsights($contactId),
]);
