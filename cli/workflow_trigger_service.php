<?php
/**
 * Workflow Trigger Service CLI
 * Initialize workflow subscriptions on system startup
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
use CRM\Database;
use CRM\Services\WorkflowTriggerService;

Database::init(require __DIR__ . '/../config/database.php');

echo "Initializing workflow trigger service...\n";

try {
    $triggerService = new WorkflowTriggerService();
    $triggerService->initializeWorkflows();
    
    $subscribed = $triggerService->getSubscribedWorkflows();
    echo "Successfully subscribed " . count($subscribed) . " workflows to events.\n";
    
    foreach ($subscribed as $workflowId => $triggerType) {
        echo "  - Workflow #$workflowId subscribed to '$triggerType'\n";
    }
    
    echo "Workflow trigger service initialized successfully.\n";
} catch (\Exception $e) {
    echo "Error initializing workflow trigger service: " . $e->getMessage() . "\n";
    exit(1);
}
