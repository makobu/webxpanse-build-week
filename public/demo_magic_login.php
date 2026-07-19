<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Session;
use CRM\Services\DemoPresentationSessionService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$token = trim((string) ($_GET['token'] ?? ''));
$error = '';

try {
    $result = (new DemoPresentationSessionService())->consumeMagicToken($token);
    header('Location: ' . (string) ($result['route'] ?? publicUrl('dashboard.php?demo=metrodrive')));
    exit;
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Demo Login - ' . brandProductName();
ob_start();
?>

<style>
    .demo-magic-error {
        max-width: 560px;
        margin: 12vh auto;
        padding: 28px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #fff;
        color: #0f172a;
        box-shadow: 0 18px 48px rgba(15, 23, 42, .08);
    }
    .demo-magic-error h1 {
        margin: 0 0 12px;
        font-size: 1.5rem;
    }
    .demo-magic-error p {
        margin: 0;
        color: #475569;
        line-height: 1.55;
    }
</style>

<main class="demo-magic-error">
    <h1>Demo link unavailable</h1>
    <p><?php echo htmlspecialchars($error !== '' ? $error : 'This presentation demo link could not be opened.', ENT_QUOTES, 'UTF-8'); ?></p>
</main>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/auth.php';
