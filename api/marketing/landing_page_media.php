<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Session;
use CRM\Services\MarketingMarketplaceGateService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $payload): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $respond(405, ['success' => false, 'error' => 'Method not allowed.']);
}
if (!Auth::check()) {
    $respond(401, ['success' => false, 'error' => 'Unauthorized']);
}
if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
    $respond(419, ['success' => false, 'error' => 'Invalid security token.']);
}

$user = Auth::user() ?: [];
if (!Authorization::can('marketing.write', $user)) {
    $respond(403, ['success' => false, 'error' => 'You do not have permission to add marketing media.']);
}

try {
    (new MarketingMarketplaceGateService())->assertCanRun($user, MarketingMarketplaceGateService::FEATURE_DESIGN);
    $pageId = (int) ($_POST['page_id'] ?? 0);
    if ($pageId <= 0) {
        throw new InvalidArgumentException('A landing page is required.');
    }

    $marketing = new Marketing();
    if (!$marketing->getLandingPage($pageId)) {
        throw new RuntimeException('Marketing landing page not found.');
    }

    $file = $_FILES['media_file'] ?? [];
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('Choose an image or video to upload.');
    }

    $originalName = basename((string) ($file['name'] ?? 'landing-page-media'));
    $title = trim((string) ($_POST['title'] ?? ''));
    if ($title === '') {
        $title = (string) pathinfo($originalName, PATHINFO_FILENAME);
        $title = trim((string) preg_replace('/[_-]+/', ' ', $title));
        $title = $title !== '' ? mb_convert_case($title, MB_CASE_TITLE, 'UTF-8') : 'Landing Page Media';
    }
    $altText = trim((string) ($_POST['alt_text'] ?? ''));
    if ($altText === '') {
        $altText = $title;
    }

    $mediaId = $marketing->createMediaFileFromUpload($file, [
        'title' => $title,
        'alt_text' => $altText,
        'tags' => ['landing-page', 'design-studio'],
        'created_by' => (int) ($user['id'] ?? 0),
    ]);
    $media = $marketing->getMediaFile($mediaId) ?: [];
    $media['display_url'] = $marketing->mediaDisplayUrl($media);

    $respond(201, [
        'success' => true,
        'message' => 'Media uploaded and selected.',
        'media' => $media,
    ]);
} catch (Throwable $e) {
    if ($e instanceof InvalidArgumentException || ($e instanceof RuntimeException && !($e instanceof PDOException))) {
        $respond(422, ['success' => false, 'error' => $e->getMessage()]);
    }
    error_log('Design Studio media upload failure: ' . $e->getMessage());
    $respond(500, ['success' => false, 'error' => 'Design Studio could not upload this media.']);
}
