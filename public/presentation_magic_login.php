<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';

loadEnvFile(__DIR__ . '/../.env');
require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Session;
use CRM\Services\PresentationSessionService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$token = trim((string) ($_GET['token'] ?? ''));
$error = '';

try {
    $result = (new PresentationSessionService())->consumeMagicToken($token);
    header('Location: ' . (string) ($result['route'] ?? publicUrl('dashboard.php?presentation=1')));
    exit;
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

$pageTitle = 'Presentation Login - ' . brandProductName();
ob_start();
?>

<style>
    .presentation-magic-error {
        max-width: 560px;
        margin: 12vh auto;
        padding: 28px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        background: #fff;
        color: #0f172a;
        box-shadow: 0 18px 48px rgba(15, 23, 42, .08);
    }
    .presentation-magic-error h1 {
        margin: 0 0 12px;
        font-size: 1.5rem;
    }
    .presentation-magic-error p {
        margin: 0;
        color: #475569;
        line-height: 1.55;
    }
</style>

<main class="presentation-magic-error">
    <h1>Presentation link unavailable</h1>
    <p><?php echo htmlspecialchars($error !== '' ? $error : 'This presentation workspace link could not be opened.', ENT_QUOTES, 'UTF-8'); ?></p>
</main>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/auth.php';
