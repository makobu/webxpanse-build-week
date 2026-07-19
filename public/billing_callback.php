<?php

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

use CRM\Database;
use CRM\Auth;
use CRM\Services\SaaSBillingService;
use CRM\Services\BillingReturnUrlService;
use CRM\Services\WorkspaceLaunchGuardrailService;
use CRM\Services\WorkspaceLaunchReadinessException;
use CRM\Services\WorkspaceLaunchThrottleException;
use CRM\Services\WorkspaceBillingService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$reference = trim((string) ($_GET['reference'] ?? ''));
$message = 'No payment reference was provided.';
$success = false;
$throttled = false;
$saasResult = [];
$billingService = new WorkspaceBillingService();
$billingSettings = $billingService->getSettings();
$isCentralHub = (string) ($billingSettings['billing_mode'] ?? 'central_hub') === 'central_hub'
    && trim((string) ($billingSettings['billing_hub_base_url'] ?? '')) !== ''
    && trim((string) ($billingSettings['billing_workspace_key'] ?? '')) !== ''
    && trim((string) ($billingSettings['billing_hub_signing_secret'] ?? '')) !== '';

function billingCallbackFallbackTarget(bool $success): string
{
    return (new BillingReturnUrlService())->defaultReturnTo();
}

function billingCallbackSanitizeReturnTo(?string $candidate, bool $success): string
{
    global $billingSettings;

    $returnUrls = new BillingReturnUrlService();
    $candidate = trim((string) $candidate);
    $configuredMobileReturnUrl = is_array($billingSettings ?? null)
        ? (string) ($billingSettings['mobile_return_url'] ?? '')
        : '';

    if ($candidate !== ''
        && !$returnUrls->isSafeRelativeUrl($candidate)
        && $returnUrls->isTrustedMobileUrl($candidate, $configuredMobileReturnUrl)) {
        return $returnUrls->sanitizeMobileReturnUrl($candidate, $configuredMobileReturnUrl);
    }

    return $returnUrls->sanitizeWebReturnTo($candidate, billingCallbackFallbackTarget($success));
}

function billingCallbackRedirect(string $target, string $status): void
{
    $separator = str_contains($target, '?') ? '&' : '?';
    header('Location: ' . $target . $separator . 'billing_status=' . rawurlencode($status));
    exit;
}

function billingCallbackRedirectWithParams(string $target, array $params): void
{
    $separator = str_contains($target, '?') ? '&' : '?';
    header('Location: ' . $target . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    exit;
}

/**
 * @param array<string,mixed> $result
 */
function billingCallbackSuccessMessage(array $result): string
{
    $snapshot = is_array($result['snapshot'] ?? null) ? (array) $result['snapshot'] : [];

    if (!empty($snapshot['billing_blocked'])) {
        return 'Payment confirmed. Billing status has been updated, but workspace access is still restricted. Contact an administrator to reactivate the workspace.';
    }

    return 'Payment confirmed. Workspace access has been restored.';
}

if ($reference !== '') {
    try {
        (new WorkspaceLaunchGuardrailService())->enforceBillingCallback($reference);

        $saasResult = (new SaaSBillingService())->verifyCheckoutReference($reference);
        if (!empty($saasResult['handled'])) {
            $success = !empty($saasResult['success']);
            $message = $success
                ? billingCallbackSuccessMessage($saasResult)
                : ((string) ($saasResult['message'] ?? 'Payment verification was not successful yet.'));
        } elseif ($billingService->hasPaymentIntentReference($reference)) {
            $result = $billingService->verifyAndApplyPayment($reference);
            $success = !empty($result['success']);
            $message = $success
                ? 'Payment confirmed. Billing status has been updated.'
                : 'Payment verification was not successful yet. Please contact an administrator.';
        } else {
            $message = 'No billing checkout matched this payment reference.';
        }
    } catch (WorkspaceLaunchReadinessException $e) {
        $message = $e->getMessage();
    } catch (WorkspaceLaunchThrottleException $e) {
        $throttled = true;
        http_response_code(429);
        header('Retry-After: ' . $e->retryAfter());
        $message = $e->getMessage();
    } catch (\Throwable $e) {
        $message = $e->getMessage();
    }
} elseif ($isCentralHub) {
    try {
        $summary = $billingService->refreshFromBillingHub();
        $success = (string) ($summary['status'] ?? 'current') === 'current';
        $message = $success
            ? 'Payment confirmed. Billing status has been updated.'
            : 'Your payment return was received. Billing status is still syncing from the billing hub.';
    } catch (\Throwable $e) {
        $message = $e->getMessage();
    }
}

$returnTo = billingCallbackSanitizeReturnTo($_GET['return_to'] ?? null, $success);
if (!$throttled && $success && (string) ($saasResult['checkout_type'] ?? '') === 'donation') {
    $_SESSION['donation_thank_you'] = [
        'status' => 'paid',
        'state' => 'success',
        'title' => 'Thank you for supporting startup AI access',
        'message' => 'Your contribution has been received. You helped keep AI access more reachable for small teams.',
        'amount' => (float) ($saasResult['amount'] ?? ($saasResult['donation']['amount'] ?? 0)),
        'currency' => strtoupper((string) ($saasResult['currency'] ?? ($saasResult['donation']['currency'] ?? 'KES'))),
        'paid_at' => (string) ($saasResult['paid_at'] ?? ''),
        'terminal' => true,
    ];
    $donationReturnTo = str_contains((string) parse_url($returnTo, PHP_URL_PATH), 'donate.php')
        ? $returnTo
        : (function_exists('publicUrl') ? publicUrl('donate.php') : 'donate.php');
    billingCallbackRedirectWithParams($donationReturnTo, ['donation_status' => 'success']);
}
if (Auth::check() && !$throttled) {
    if ($success) {
        billingCallbackRedirect($returnTo, 'success');
    }

    billingCallbackRedirect(billingCallbackFallbackTarget(false), 'failed');
}

$pageTitle = 'Billing Callback - ' . brandProductName();
ob_start();
?>
<div class="page-premium">
    <div class="container" style="max-width:720px;margin:3rem auto;">
        <div class="content-card">
            <h1 style="margin-top:0;"><?php echo $success ? 'Payment Received' : 'Billing Update'; ?></h1>
            <p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
            <div style="margin-top:1rem;display:flex;gap:.75rem;flex-wrap:wrap;">
                <a href="<?php echo htmlspecialchars($success ? 'login.php' : 'billing_payment_required.php'); ?>" class="btn-premium-primary"><?php echo $success ? 'Go to login' : 'Open billing page'; ?></a>
                <a href="dashboard.php" class="btn-premium-secondary">Return to dashboard</a>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
