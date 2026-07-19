<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\ReadinessCaptureSettings;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!$user || !Authorization::can('settings.general', $user)) {
    header('Location: dashboard.php');
    exit;
}

$settingsModule = new ReadinessCaptureSettings();
$userId = (int) ($user['id'] ?? 0);
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $action = (string) ($_POST['action'] ?? 'save_connector');
            if ($action === 'rotate_secret') {
                $settingsModule->rotateSecret($userId);
                $success = 'Capture secret rotated. Update the landing page configuration with the new secret.';
            } else {
                $settingsModule->update([
                    'is_enabled' => isset($_POST['is_enabled']),
                    'source_label' => (string) ($_POST['source_label'] ?? ReadinessCaptureSettings::DEFAULT_SOURCE),
                    'origin_notes' => (string) ($_POST['origin_notes'] ?? ''),
                ], $userId);
                $success = 'Readiness capture settings saved.';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$connector = $settingsModule->getOrCreate($userId);

$appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
if ($appUrl === '') {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $publicDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/public/settings_readiness_capture.php')), '/');
    $crmPath = preg_replace('#/public$#', '', $publicDir) ?: '';
    $appUrl = $scheme . '://' . $host . $crmPath;
}
$captureUrl = $settingsModule->buildCaptureUrl($appUrl);

$pageTitle = 'Readiness Capture Connector';
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/settings-ui.css?v=20260526c">

<div class="page-premium settings-page settings-aux-page">
    <div class="container settings-container--narrow">
        <div class="settings-inline-header">
            <a href="settings.php" class="settings-back-link"><i class="fas fa-arrow-left" aria-hidden="true"></i> Settings</a>
            <h1>Business AI Readiness Capture</h1>
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <div class="content-card settings-panel--blue" style="margin-bottom:1rem;">
            <div class="settings-card-stack">
                <div class="settings-section-kicker">Install-owned connector</div>
                <h2 style="margin:0;">Use this CRM as the lead receiver for the readiness check</h2>
                <p style="margin:0;color:#475569;line-height:1.6;">
                    When enabled, visitors who ask to email themselves the readiness report can also be captured as leads in this CRM.
                    The landing page does not need a hardcoded tenant pairing. It only needs the capture URL and secret shown below.
                </p>
            </div>
        </div>

        <div class="settings-two-column-grid">
            <form method="POST" class="content-card settings-card-stack">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="action" value="save_connector">

                <div>
                    <label class="settings-checkbox-row">
                        <input type="checkbox" name="is_enabled" value="1" <?php echo !empty($connector['is_enabled']) ? 'checked' : ''; ?>>
                        <span class="settings-checkbox-copy">
                            <span style="font-weight:600;color:#0f172a;">Enable readiness lead capture</span>
                            <span style="color:#64748b;font-size:.85rem;">Accept inbound readiness-check leads from a landing page that has this install's capture URL and secret.</span>
                        </span>
                    </label>
                </div>

                <div class="settings-form-field">
                    <label for="source_label">Lead source</label>
                    <select id="source_label" name="source_label">
                        <?php foreach ($settingsModule->getAllowedSources() as $source): ?>
                            <option value="<?php echo htmlspecialchars($source); ?>" <?php echo ($connector['source_label'] ?? '') === $source ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($source); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="settings-help">This is the contact lead source that will be written for captured readiness-check leads.</div>
                </div>

                <div class="settings-form-field">
                    <label for="origin_notes">Origin notes</label>
                    <textarea id="origin_notes" name="origin_notes" rows="4"><?php echo htmlspecialchars((string) ($connector['origin_notes'] ?? '')); ?></textarea>
                    <div class="settings-help">Optional notes about which landing page or campaign should use this connector.</div>
                </div>

                <div>
                    <button type="submit" class="btn-premium-primary">Save Connector</button>
                </div>
            </form>

            <div class="content-card settings-card-stack">
                <div>
                    <div class="settings-section-kicker">Copy into the landing page</div>
                    <h3 style="margin:.35rem 0 0;color:#0f172a;font-size:1.05rem;">Capture endpoint and secret</h3>
                </div>

                <div class="settings-form-field">
                    <label>Capture URL</label>
                    <input type="text" readonly class="settings-readonly-field" value="<?php echo htmlspecialchars($captureUrl); ?>">
                </div>

                <div class="settings-form-field">
                    <label>Capture secret</label>
                    <input type="text" readonly class="settings-readonly-field" value="<?php echo htmlspecialchars((string) ($connector['capture_secret'] ?? '')); ?>">
                </div>

                <form method="POST" class="settings-action-row">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="rotate_secret">
                    <button type="submit" class="btn-premium-secondary" onclick="return confirm('Rotate the capture secret? The landing page will need the new secret immediately.');">Rotate Secret</button>
                    <span class="settings-help">Rotating invalidates the old secret right away.</span>
                </form>

                <div class="settings-note-panel">
                    <div style="font-weight:700;margin-bottom:.35rem;">Landing page config</div>
                    <div><code>ASSESSMENT_CAPTURE_URL</code> = the capture URL above</div>
                    <div><code>ASSESSMENT_CAPTURE_SECRET</code> = the capture secret above</div>
                    <div><code>ASSESSMENT_CAPTURE_SOURCE</code> = optional override, otherwise this CRM uses <code><?php echo htmlspecialchars((string) ($connector['source_label'] ?? ReadinessCaptureSettings::DEFAULT_SOURCE)); ?></code></div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
