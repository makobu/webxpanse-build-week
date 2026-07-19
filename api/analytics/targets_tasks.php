<?php

require __DIR__ . '/_bootstrap.php';

try {
    $respond($operatingAnalytics->getTargetTaskAnalytics($workspaceId, $subjectUserId, $timeframe));
} catch (\Throwable $e) {
    $fail('targets_tasks', $e);
}
