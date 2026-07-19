<?php

require __DIR__ . '/_bootstrap.php';

try {
    $respond($operatingAnalytics->getOperatingSnapshot($workspaceId, $subjectUserId, $timeframe));
} catch (\Throwable $e) {
    $fail('operating_snapshot', $e);
}
