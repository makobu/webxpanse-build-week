<?php
/**
 * Public Marketing email unsubscribe confirmation.
 *
 * GET renders a confirmation screen only; POST records the opt-out so email
 * security scanners do not accidentally unsubscribe recipients by opening links.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Modules\Marketing;

$h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$token = trim((string) ($_POST['t'] ?? $_GET['t'] ?? ''));
$result = null;
$error = null;

try {
    Database::init(require __DIR__ . '/../config/database.php');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $marketing = new Marketing();
        $result = $marketing->processLiveEmailUnsubscribeToken($token, [
            'ip' => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
    }
} catch (Throwable $e) {
    $error = 'Marketing unsubscribe processing is temporarily unavailable. Please contact the sender to be removed.';
}

$success = is_array($result) && !empty($result['success']);
$message = $error ?: (is_array($result) ? (string) ($result['message'] ?? '') : '');
if ($message === '') {
    $message = 'Confirm that you want to unsubscribe from future marketing emails.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage email preferences</title>
    <link rel="stylesheet" href="assets/css/marketing-public.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/marketing-public.css'); ?>">
</head>
<body class="marketing-public-preferences">
    <main class="marketing-public-preferences-card">
        <span class="marketing-public-preferences-status <?php echo $success ? 'is-success' : ($error ? 'is-error' : ''); ?>">
            <?php echo $success ? 'Unsubscribed' : ($error ? 'Unavailable' : 'Confirmation required'); ?>
        </span>
        <h1><?php echo $success ? 'You are unsubscribed' : 'Manage email preferences'; ?></h1>
        <p><?php echo $h($message); ?></p>
        <?php if (!$success && !$error): ?>
            <form class="marketing-public-preferences-form" method="post">
                <input type="hidden" name="t" value="<?php echo $h($token); ?>">
                <button type="submit">Unsubscribe me</button>
            </form>
            <p class="marketing-public-preferences-muted">Opening this page does not unsubscribe you. The opt-out is recorded only after you confirm.</p>
        <?php endif; ?>
    </main>
</body>
</html>
