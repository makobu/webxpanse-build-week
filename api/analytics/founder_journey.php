<?php

require __DIR__ . '/_bootstrap.php';

try {
    $respond($operatingAnalytics->getFounderJourneyAnalytics($workspaceId, $subjectUserId ?? $viewerUserId));
} catch (\Throwable $e) {
    $fail('founder_journey', $e);
}
