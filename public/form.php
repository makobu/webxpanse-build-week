<?php
/**
 * Public Form Render - Unauthenticated
 * Renders form from definition with custom styling, logo, images, etc.
 */

require_once __DIR__ . '/../vendor/autoload.php';

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
use CRM\Modules\Forms;
use CRM\Modules\Marketing;
use CRM\Modules\RateLimiter;
use CRM\Modules\Tracking;
use CRM\Security;
use CRM\Services\FormDesignService;
use CRM\Services\FormStudioService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');

$uuid = $_GET['uuid'] ?? '';
$landingToken = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($_GET['landing_token'] ?? '')) ?: '';
$previewToken = preg_replace('/[^a-f0-9]/i', '', (string) ($_GET['preview_token'] ?? '')) ?: '';
$isEmbedded = (string) ($_GET['embed'] ?? '') === '1';
if (!$uuid) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Form Not Found</title></head><body><h1>Form not found</h1></body></html>';
    exit;
}

$formsModule = new Forms();
$form = $formsModule->getByUuid($uuid);

if (!$form) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Form Not Found</title></head><body><h1>Form not found</h1></body></html>';
    exit;
}

$design = new FormDesignService();
$studio = new FormStudioService();
$document = $studio->publicDocument($form, $previewToken);
if ($document === null) {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><title>Form Not Published</title></head><body><h1>Form not published</h1></body></html>';
    exit;
}
$runtimeFields = array_values(array_filter(
    (array) ($document['fields'] ?? []),
    static fn(array $field): bool => trim((string) ($field['name'] ?? '')) !== ''
));
$form['fields'] = $runtimeFields;
$form['name'] = (string) ($document['content']['title'] ?? $form['name'] ?? 'Form');
$form['success_message'] = (string) ($document['content']['success_message'] ?? $form['success_message'] ?? 'Thank you! Your submission has been received.');
$form['redirect_url'] = (string) ($document['content']['redirect_url'] ?? $form['redirect_url'] ?? '');
$settings = $design->legacySettings($document);

$submitted = false;
$error = null;

$hasFileField = false;
foreach ($form['fields'] ?? [] as $f) {
    if (($f['type'] ?? '') === 'file') {
        $hasFileField = true;
        break;
    }
}

function formFieldLabel(array $field): string
{
    $label = trim((string) ($field['label'] ?? ''));
    if ($label !== '') {
        return $label;
    }

    $name = trim((string) ($field['name'] ?? ''));
    return $name !== '' ? $name : 'Field';
}

function normalizePublicFormValue(array $field, array $post, array &$errors): mixed
{
    $type = (string) ($field['type'] ?? 'text');
    $name = trim((string) ($field['name'] ?? ''));
    $label = formFieldLabel($field);
    $required = !empty($field['required']);

    if ($name === '') {
        return null;
    }

    if ($type === 'checkbox') {
        $checked = !empty($post[$name]) ? '1' : '0';
        if ($required && $checked !== '1') {
            $errors[] = $label . ' is required.';
        }
        return $checked;
    }

    $value = trim((string) ($post[$name] ?? ''));
    if ($type === 'hidden' && $value === '') {
        $value = trim((string) ($field['default_value'] ?? ''));
    }
    if ($value === '') {
        if ($required) {
            $errors[] = $label . ' is required.';
        }
        return '';
    }

    if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        $errors[] = $label . ' must be a valid email address.';
        return $value;
    }

    if ($type === 'number' && !is_numeric($value)) {
        $errors[] = $label . ' must be a valid number.';
        return $value;
    }

    if ($type === 'date') {
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            $errors[] = $label . ' must be a valid date.';
            return $value;
        }
        return date('Y-m-d', $timestamp);
    }

    if ($type === 'time' && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
        $errors[] = $label . ' must be a valid time.';
        return $value;
    }

    if ($type === 'rating' && (!ctype_digit($value) || (int) $value < 1 || (int) $value > 5)) {
        $errors[] = $label . ' must be between 1 and 5.';
        return $value;
    }

    if ($type === 'nps' && (!ctype_digit($value) || (int) $value < 0 || (int) $value > 10)) {
        $errors[] = $label . ' must be between 0 and 10.';
        return $value;
    }

    if (in_array($type, ['select', 'radio'], true)) {
        $options = array_values(array_filter(array_map(
            static fn($option): string => trim((string) $option),
            is_array($field['options'] ?? null) ? $field['options'] : []
        ), static fn(string $option): bool => $option !== ''));
        if ($options !== [] && !in_array($value, $options, true)) {
            $errors[] = $label . ' has an invalid selection.';
        }
    }

    $rules = is_array($field['validation'] ?? null) ? $field['validation'] : [];
    $length = mb_strlen($value);
    if (($rules['min_length'] ?? null) !== null && $length < (int) $rules['min_length']) {
        $errors[] = $label . ' must be at least ' . (int) $rules['min_length'] . ' characters.';
    }
    if (($rules['max_length'] ?? null) !== null && $length > (int) $rules['max_length']) {
        $errors[] = $label . ' must be no more than ' . (int) $rules['max_length'] . ' characters.';
    }
    if (is_numeric($value) && ($rules['min_value'] ?? null) !== null && (float) $value < (float) $rules['min_value']) {
        $errors[] = $label . ' must be at least ' . $rules['min_value'] . '.';
    }
    if (is_numeric($value) && ($rules['max_value'] ?? null) !== null && (float) $value > (float) $rules['max_value']) {
        $errors[] = $label . ' must be no more than ' . $rules['max_value'] . '.';
    }

    return Security::sanitizeInput($value, 'string');
}

function publicFormUploadMatchesAccept(string $mimeType, string $extension, string $accept): bool
{
    $tokens = array_values(array_filter(array_map('trim', explode(',', strtolower($accept)))));
    if ($tokens === []) {
        return true;
    }
    foreach ($tokens as $token) {
        if (str_starts_with($token, '.') && ltrim($token, '.') === strtolower($extension)) {
            return true;
        }
        if (str_ends_with($token, '/*') && str_starts_with(strtolower($mimeType), substr($token, 0, -1))) {
            return true;
        }
        if ($token === strtolower($mimeType)) {
            return true;
        }
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formData = [];
    $visibility = $design->visibilityMap($document, $_POST);
    $rateLimiter = new RateLimiter(
        'public_form_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $uuid),
        (int) ($_ENV['RATE_LIMIT_FORM_SUBMIT_ATTEMPTS'] ?? 60),
        (int) ($_ENV['RATE_LIMIT_FORM_SUBMIT_WINDOW_SECONDS'] ?? ($_ENV['RATE_LIMIT_WINDOW_SECONDS'] ?? 900))
    );
    if ($rateLimiter->isLimited()) {
        $error = 'Too many submissions. Please wait before trying again.';
    } else {
        $rateLimiter->recordAttempt();
    }

    $allowedUploadMimeTypes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/csv' => 'csv',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];
    if ($error === null) {
        $validationErrors = [];
        foreach ($form['fields'] ?? [] as $field) {
            if (empty($visibility[(string) ($field['id'] ?? '')])) {
                continue;
            }
            if (($field['type'] ?? '') === 'file') {
                continue;
            }

            $name = trim((string) ($field['name'] ?? ''));
            if ($name === '' || in_array($name, ['visitor_id', 'form_uuid'], true)) {
                continue;
            }

            $formData[$name] = normalizePublicFormValue($field, $_POST, $validationErrors);
        }

        if (!empty($document['content']['gdpr_enabled']) && empty($_POST['gdpr_consent'])) {
            $validationErrors[] = 'Privacy consent is required.';
        }

        if (!empty($validationErrors)) {
            $error = implode(' ', $validationErrors);
        }
    }

    if ($error === null && $hasFileField) {
        $uploadDir = __DIR__ . '/../uploads/form_submissions/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $uploadErrors = [];
        foreach ($form['fields'] ?? [] as $field) {
            if (empty($visibility[(string) ($field['id'] ?? '')])) {
                continue;
            }
            if (($field['type'] ?? '') !== 'file') continue;
            $name = $field['name'] ?? '';
            if (!$name || !isset($_FILES[$name])) {
                if (!empty($field['required'])) {
                    $uploadErrors[] = ($field['label'] ?? $name ?: 'File') . ' is required.';
                }
                continue;
            }
            $file = $_FILES[$name];
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                if (!empty($field['required'])) {
                    $uploadErrors[] = ($field['label'] ?? $name) . ' is required.';
                }
                continue;
            }
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'] ?? '')) {
                $uploadErrors[] = ($field['label'] ?? $name) . ' could not be uploaded.';
                continue;
            }
            $fieldMaxMb = max(1, (int) ($field['max_size'] ?? 5));
            $serverMaxMb = max(1, (int) ($_ENV['FORM_UPLOAD_MAX_MB'] ?? 5));
            $maxSize = min($fieldMaxMb, $serverMaxMb) * 1024 * 1024;
            if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > $maxSize) {
                $uploadErrors[] = ($field['label'] ?? $name) . ' exceeds the maximum allowed size.';
                continue;
            }
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = $finfo ? (string) finfo_file($finfo, $file['tmp_name']) : '';
            if ($finfo) {
                finfo_close($finfo);
            }
            $ext = $allowedUploadMimeTypes[$mimeType] ?? null;
            if ($ext === null) {
                $uploadErrors[] = ($field['label'] ?? $name) . ' has an unsupported file type.';
                continue;
            }
            if (!publicFormUploadMatchesAccept($mimeType, $ext, (string) ($field['accept'] ?? ''))) {
                $uploadErrors[] = ($field['label'] ?? $name) . ' does not match the allowed file types.';
                continue;
            }
            if (str_starts_with($mimeType, 'image/') && @getimagesize($file['tmp_name']) === false) {
                $uploadErrors[] = ($field['label'] ?? $name) . ' is not a valid image file.';
                continue;
            }
            $safeStem = pathinfo((string) $file['name'], PATHINFO_FILENAME);
            $safeStem = preg_replace('/[^a-zA-Z0-9._-]/', '', $safeStem) ?: 'file';
            $fileName = uniqid('fs_') . '_' . substr($safeStem, 0, 50) . '.' . $ext;
            $subDir = date('Y-m-d') . '/';
            if (!is_dir($uploadDir . $subDir)) mkdir($uploadDir . $subDir, 0755, true);
            $dest = $uploadDir . $subDir . $fileName;
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                $formData[$name] = 'form_submissions/' . $subDir . $fileName;
            } else {
                $uploadErrors[] = ($field['label'] ?? $name) . ' could not be stored.';
            }
        }
        if (!empty($uploadErrors)) {
            $error = implode(' ', $uploadErrors);
        }
    }

    $visitorId = $_POST['visitor_id'] ?? ('v_' . bin2hex(random_bytes(8)));
    try {
        if ($error !== null) {
            throw new \RuntimeException($error);
        }
        $tracking = new Tracking();
        $workspaceSnapshot = WorkspaceContext::runtimeSnapshot();
        try {
            $formWorkspaceId = (int) ($form['workspace_id'] ?? 0);
            if ($formWorkspaceId > 0 && WorkspaceContext::activateRuntimeWorkspace($formWorkspaceId) === null) {
                throw new \RuntimeException('This form is not connected to an active workspace.');
            }
            $tracking->trackFormSubmission([
                'workspace_id' => $formWorkspaceId,
                'visitor_id' => $visitorId,
                'form_id' => $form['uuid'],
                'form_definition_id' => $form['id'],
                'form_data' => $formData,
                'page' => $_SERVER['REQUEST_URI'] ?? ''
            ]);
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($workspaceSnapshot);
        }
        $submitted = true;
        if ($landingToken !== '') {
            try {
                (new Marketing())->recordMarketingTrackingEvent([
                    'token' => $landingToken,
                    'session_key' => (string) ($_COOKIE['crm_marketing_vid'] ?? $visitorId),
                    'event_type' => 'form_submit',
                    'event_name' => 'Landing page form submission',
                    'form_id' => (int) ($form['id'] ?? 0),
                    'page_url' => (string) ($_SERVER['HTTP_REFERER'] ?? $_SERVER['REQUEST_URI'] ?? ''),
                    'referrer' => (string) ($_SERVER['HTTP_REFERER'] ?? ''),
                    'ip_address' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                    'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    'metadata' => ['source' => 'design_studio_crm_form', 'form_uuid' => (string) $form['uuid']],
                ]);
            } catch (Throwable $trackingError) {
                error_log('Design Studio form conversion tracking failed: ' . $trackingError->getMessage());
            }
        }
        if (!empty($form['redirect_url'])) {
            $redirectUrl = Security::sanitizeRedirectUrl((string) $form['redirect_url'], '');
            if ($redirectUrl !== '') {
                header('Location: ' . $redirectUrl);
                exit;
            }
        }
    } catch (\Exception $e) {
        $error = $e->getMessage();
    }
}

$visitorId = 'v_' . bin2hex(random_bytes(8));
$basePath = getBasePath();
$logoPath = (string) ($settings['logo_path'] ?? '');
$headerPath = (string) ($settings['header_image_path'] ?? '');
$logoUrl = $logoPath !== '' ? $basePath . '/form_asset.php?form_uuid=' . urlencode((string) $form['uuid']) . '&type=logo&v=' . urlencode($logoPath) : '';
$headerUrl = $headerPath !== '' ? $basePath . '/form_asset.php?form_uuid=' . urlencode((string) $form['uuid']) . '&type=header_image&v=' . urlencode($headerPath) : '';
$stepIds = array_map(static fn(array $step): string => (string) ($step['id'] ?? ''), (array) ($document['steps'] ?? []));
$requestedStep = trim((string) ($_POST['form_active_step'] ?? ''));
$activeStep = in_array($requestedStep, $stepIds, true) ? $requestedStep : (string) ($document['steps'][0]['id'] ?? 'step_1');
$runtimeHtml = !$submitted ? $design->render($document, [
    'form' => $form,
    'mode' => 'public',
    'active_step' => $activeStep,
    'values' => $_POST,
    'visitor_id' => $visitorId,
    'form_uuid' => (string) $form['uuid'],
    'error' => $error,
    'logo_url' => $logoUrl,
    'header_url' => $headerUrl,
    'has_file' => $hasFileField,
]) : '';
$theme = (array) ($document['theme'] ?? []);
$successStyle = sprintf(
    '--form-primary:%s;--form-button:%s;--form-page:%s;--form-surface:%s;--form-text:%s;--form-muted:%s;--form-radius:%dpx',
    htmlspecialchars((string) ($theme['primary_color'] ?? '#0f67ea')),
    htmlspecialchars((string) ($theme['button_color'] ?? '#0f67ea')),
    htmlspecialchars((string) ($theme['page_color'] ?? '#f4f7fb')),
    htmlspecialchars((string) ($theme['surface_color'] ?? '#ffffff')),
    htmlspecialchars((string) ($theme['text_color'] ?? '#17243a')),
    htmlspecialchars((string) ($theme['muted_color'] ?? '#65758b')),
    (int) ($theme['radius'] ?? 12)
);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($form['name']); ?></title>
    <link rel="stylesheet" href="assets/css/form-studio-public.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/form-studio-public.css'); ?>">
</head>
<body class="form-studio-public-body<?php echo $isEmbedded ? ' form-studio-public-body--embedded' : ''; ?>" style="<?php echo $successStyle; ?>">
    <?php if ($submitted): ?>
        <main class="form-runtime" style="<?php echo $successStyle; ?>"><section class="form-runtime__card"><div class="form-runtime__inner form-runtime__success"><?php if ($logoUrl): ?><img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="" class="form-runtime__logo"><?php endif; ?><i aria-hidden="true">✓</i><h1>Submission received</h1><p><?php echo htmlspecialchars((string) $form['success_message']); ?></p></div></section></main>
    <?php else: ?>
        <?php echo $runtimeHtml; ?>
    <?php endif; ?>
    <script src="assets/js/form-runtime.js?v=<?php echo (int) filemtime(__DIR__ . '/assets/js/form-runtime.js'); ?>" defer></script>
</body>
</html>
