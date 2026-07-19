<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Services\PresentationSessionService;

$user = presentationRequire('presentation.workspace.archive');
$input = presentationJsonInput();
presentationValidateCsrf($input);

try {
    presentationJsonResponse((new PresentationSessionService())->archive((int) ($input['session_id'] ?? 0), (int) ($user['id'] ?? 0)));
} catch (\InvalidArgumentException $e) {
    presentationJsonError($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('presentations/archive failed: ' . $e->getMessage());
    presentationJsonError('Unable to archive the presentation workspace.', 500);
}
