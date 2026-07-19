<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Modules\DemoModeManager;

try {
    $manager = new DemoModeManager();
    echo json_encode(['success' => true, 'data' => $manager->getState()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
