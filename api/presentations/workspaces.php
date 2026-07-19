<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Services\PresentationSessionService;

$user = presentationRequire('presentation.workspace.create');
$input = presentationJsonInput();
presentationValidateCsrf($input);

try {
    presentationJsonResponse((new PresentationSessionService())->create($input, (int) ($user['id'] ?? 0)));
} catch (\InvalidArgumentException $e) {
    presentationJsonError($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('presentations/workspaces failed: ' . $e->getMessage());
    presentationJsonError('Unable to create the presentation workspace.', 500);
}
