<?php

require __DIR__ . '/_bootstrap.php';

try {
    $respond($operatingAnalytics->getChannelAnalytics($workspaceId, $subjectUserId, $timeframe));
} catch (\Throwable $e) {
    $fail('channels', $e);
}
