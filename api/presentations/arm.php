<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use CRM\Services\PresentationSessionService;

$user = presentationRequire('presentation.live_send');
$input = presentationJsonInput();
presentationValidateCsrf($input);

try {
    presentationJsonResponse((new PresentationSessionService())->arm(
        (int) ($input['session_id'] ?? 0),
        (int) ($user['id'] ?? 0),
        !empty($input['armed'])
    ));
} catch (\InvalidArgumentException $e) {
    presentationJsonError($e->getMessage(), 422);
} catch (\Throwable $e) {
    error_log('presentations/arm failed: ' . $e->getMessage());
    presentationJsonError('Unable to update live presentation mode.', 500);
}
