<?php
/**
 * Public donation page.
 *
 * Donations are voluntary platform support and do not create an account,
 * change workspace packages, or refill AI Credits.
 */

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Database;
use CRM\Modules\WorkspaceBillingSettings;
use CRM\Security;
use CRM\Session;
use CRM\Services\PlatformLegalIdentityService;
use CRM\Services\SaaSBillingService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$pageTitle = 'Donate - ' . brandProductName();
$donationEndpoint = apiUrl('donations/checkout.php');
$donationStatusEndpoint = apiUrl('donations/status.php');
$returnTo = publicUrl('donate.php');
$loginUrl = publicUrl('login.php');
$authBackgroundDesktopUrl = assetUrl('images/login-bg-desktop.jpg');
$authBackgroundMobileUrl = assetUrl('images/login-bg-mobile.jpg');
$authBackgroundPreviewUrl = assetUrl('images/login-bg-preview.jpg');
$donationsEnabled = WorkspaceBillingSettings::donationsEnabled();
$platformSystemLegalName = (new PlatformLegalIdentityService())->systemLegalName();
$donationPaymentOptions = $donationsEnabled
    ? (new SaaSBillingService())->donationPaymentOptions(['KES', 'USD', 'NGN'], true)
    : [
        'currencies' => [],
        'modes_by_currency' => [],
        'default_currency' => '',
        'has_available_methods' => false,
    ];
$donationCurrencies = (array) ($donationPaymentOptions['currencies'] ?? []);
$donationModesByCurrency = (array) ($donationPaymentOptions['modes_by_currency'] ?? []);
$donationDefaultCurrency = (string) ($donationPaymentOptions['default_currency'] ?? '');
$donationInitialModes = $donationDefaultCurrency !== '' ? (array) ($donationModesByCurrency[$donationDefaultCurrency] ?? []) : [];
$donationHasAvailableMethods = !empty($donationPaymentOptions['has_available_methods']);
$donationModesJson = htmlspecialchars(
    json_encode($donationModesByCurrency, JSON_UNESCAPED_SLASHES) ?: '{}',
    ENT_QUOTES,
    'UTF-8'
);
$donationThankYou = $donationsEnabled && is_array($_SESSION['donation_thank_you'] ?? null)
    ? (array) $_SESSION['donation_thank_you']
    : [];
unset($_SESSION['donation_thank_you']);
$donationInitialState = (string) ($donationThankYou['state'] ?? '') === 'success' ? 'success' : 'checkout';
$donationThankYouJson = htmlspecialchars(
    json_encode($donationThankYou, JSON_UNESCAPED_SLASHES) ?: '{}',
    ENT_QUOTES,
    'UTF-8'
);

ob_start();
?>

<style>
    body {
        background: var(--auth-bg-image) center center / cover no-repeat fixed, var(--auth-bg-fallback) !important;
        position: relative;
        overflow-x: hidden;
    }

    body > div {
        box-sizing: border-box;
    }

    .donate-starfield {
        position: fixed;
        inset: 0;
        pointer-events: none;
        background:
            radial-gradient(circle at 12% 18%, rgba(255, 255, 255, 0.52) 0 1px, transparent 6px),
            radial-gradient(circle at 28% 12%, rgba(255, 255, 255, 0.45) 0 1px, transparent 5px),
            radial-gradient(circle at 76% 22%, rgba(255, 255, 255, 0.5) 0 1px, transparent 6px),
            radial-gradient(circle at 92% 34%, rgba(255, 255, 255, 0.4) 0 1px, transparent 5px);
        z-index: 0;
    }

    .donate-shell {
        width: min(440px, calc(100vw - 32px));
        position: relative;
        z-index: 2;
        padding: 28px;
        border-radius: 24px;
        background: rgba(236, 240, 243, 0.94);
        color: #334155;
        box-shadow:
            12px 12px 30px rgba(163, 177, 198, 0.76),
            -12px -12px 30px rgba(255, 255, 255, 0.68);
    }

    .donate-shell.is-result {
        width: min(500px, calc(100vw - 32px));
    }

    .donate-checkout-state,
    .donate-result-state {
        display: grid;
    }

    .donate-checkout-state[hidden],
    .donate-result-state[hidden] {
        display: none;
    }

    .donate-back {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        color: #64748b;
        text-decoration: none;
        font-size: 13px;
        font-weight: 700;
        margin-bottom: 18px;
    }

    .donate-back:hover {
        color: #92400e;
    }

    .donate-shell h1 {
        margin: 0;
        color: #0f172a;
        font-size: 30px;
        line-height: 1.05;
    }

    .donate-copy {
        margin: 12px 0 22px;
        color: #64748b;
        font-size: 14px;
        line-height: 1.62;
    }

    .donate-unavailable-state {
        display: grid;
        gap: 16px;
    }

    .donate-unavailable-state .donate-copy {
        margin-bottom: 0;
    }

    .donate-system-legal {
        margin: -12px 0 20px;
        color: #64748b;
        font-size: 12px;
        line-height: 1.5;
    }

    .donate-system-legal strong {
        color: #334155;
        font-weight: 800;
    }

    .donate-form {
        display: grid;
        gap: 15px;
    }

    .donate-grid {
        display: grid;
        grid-template-columns: 1fr 0.72fr;
        gap: 12px;
    }

    .donate-field {
        display: grid;
        gap: 7px;
    }

    .donate-field[hidden] {
        display: none;
    }

    .donate-field span {
        color: #475569;
        font-size: 12px;
        font-weight: 800;
        letter-spacing: 0.04em;
        text-transform: uppercase;
    }

    .donate-field input,
    .donate-field select,
    .donate-field textarea {
        width: 100%;
        box-sizing: border-box;
        border: 0;
        border-radius: 12px;
        background: #f8fafc;
        color: #1e293b;
        font: inherit;
        padding: 13px 14px;
        box-shadow:
            inset 4px 4px 8px rgba(163, 177, 198, 0.42),
            inset -4px -4px 8px rgba(255, 255, 255, 0.78);
    }

    .donate-field textarea {
        min-height: 82px;
        resize: vertical;
    }

    .donate-field input:focus,
    .donate-field select:focus,
    .donate-field textarea:focus {
        outline: 3px solid rgba(245, 158, 11, 0.32);
    }

    .donate-status {
        min-height: 18px;
        color: #047857;
        font-size: 13px;
        line-height: 1.45;
    }

    .donate-status.is-error {
        color: #b91c1c;
    }

    .donate-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 9px;
        width: 100%;
        border: 1px solid rgba(146, 64, 14, 0.2);
        border-radius: 13px;
        padding: 14px 18px;
        background: linear-gradient(145deg, #fcd34d, #f59e0b);
        color: #3f2705;
        font-weight: 900;
        font-size: 15px;
        cursor: pointer;
        text-decoration: none;
        box-shadow:
            7px 7px 16px rgba(146, 64, 14, 0.18),
            -6px -6px 14px rgba(255, 255, 255, 0.7),
            inset 0 1px 0 rgba(255, 255, 255, 0.5);
        transition: transform 0.18s ease, box-shadow 0.18s ease;
    }

    .donate-button:hover,
    .donate-button:focus-visible {
        transform: translateY(-1px);
        outline: 3px solid rgba(245, 158, 11, 0.26);
        outline-offset: 3px;
        box-shadow:
            9px 9px 20px rgba(146, 64, 14, 0.22),
            -7px -7px 16px rgba(255, 255, 255, 0.78),
            inset 0 1px 0 rgba(255, 255, 255, 0.58);
    }

    .donate-button:disabled,
    .donate-button[aria-busy="true"] {
        cursor: wait;
        opacity: 0.75;
        transform: none;
    }

    .donate-footnote {
        margin: 4px 0 0;
        color: #64748b;
        font-size: 12px;
        line-height: 1.5;
        text-align: center;
    }

    .donate-result-state {
        gap: 16px;
        justify-items: center;
        text-align: center;
        padding: 4px 0 0;
    }

    .donate-celebration {
        position: relative;
        width: 178px;
        height: 138px;
        margin: 2px auto 0;
        color: #f59e0b;
    }

    .donate-celebration svg {
        display: block;
        width: 100%;
        height: 100%;
        overflow: visible;
    }

    .donate-celebration .seal {
        transform-origin: 90px 74px;
        animation: donateSealPop 620ms cubic-bezier(.2, .9, .2, 1.25) both;
    }

    .donate-celebration .ribbon {
        stroke-dasharray: 160;
        stroke-dashoffset: 160;
        animation: donateRibbon 850ms ease-out 180ms both;
    }

    .donate-celebration .sparkle {
        opacity: 0;
        transform-origin: center;
        animation: donateSparkle 1200ms ease-out 340ms both;
    }

    .donate-celebration .sparkle:nth-of-type(4) {
        animation-delay: 480ms;
    }

    .donate-result-kicker {
        margin: 0;
        color: #92400e;
        font-size: 12px;
        font-weight: 900;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .donate-result-state h1 {
        margin: 0;
        color: #0f172a;
        font-size: 31px;
        line-height: 1.06;
    }

    .donate-result-message {
        margin: 0;
        color: #64748b;
        font-size: 14px;
        line-height: 1.65;
        max-width: 390px;
    }

    .donate-result-amount {
        display: inline-grid;
        gap: 4px;
        min-width: 190px;
        border: 1px solid rgba(245, 158, 11, .28);
        border-radius: 18px;
        padding: 13px 18px;
        background: linear-gradient(145deg, rgba(255, 251, 235, .9), rgba(255, 255, 255, .76));
        box-shadow:
            inset 0 1px 0 rgba(255, 255, 255, .8),
            8px 8px 18px rgba(146, 64, 14, .12);
    }

    .donate-result-amount[hidden] {
        display: none;
    }

    .donate-result-amount span {
        color: #64748b;
        font-size: 11px;
        font-weight: 900;
        letter-spacing: .06em;
        text-transform: uppercase;
    }

    .donate-result-amount strong {
        color: #0f172a;
        font-size: 22px;
        line-height: 1;
    }

    .donate-result-actions {
        display: flex;
        width: 100%;
        gap: 10px;
        margin-top: 2px;
    }

    .donate-result-actions .donate-button,
    .donate-result-actions .donate-secondary-action {
        flex: 1 1 0;
    }

    .donate-secondary-action {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        border: 1px solid rgba(100, 116, 139, .18);
        border-radius: 13px;
        padding: 14px 18px;
        background: rgba(248, 250, 252, .86);
        color: #475569;
        font-weight: 900;
        font-size: 14px;
        text-decoration: none;
        cursor: pointer;
        box-shadow:
            5px 5px 12px rgba(163, 177, 198, .26),
            -5px -5px 12px rgba(255, 255, 255, .72);
    }

    .donate-result-state.is-waiting .donate-celebration .seal {
        animation: donateWaitPulse 1700ms ease-in-out infinite;
    }

    .donate-result-state.is-waiting .donate-celebration .check,
    .donate-result-state.is-failed .donate-celebration .check,
    .donate-result-state.is-expired .donate-celebration .check {
        opacity: .18;
    }

    .donate-result-state.is-failed .donate-result-kicker,
    .donate-result-state.is-expired .donate-result-kicker {
        color: #b45309;
    }

    @keyframes donateSealPop {
        0% { opacity: 0; transform: scale(.72); }
        72% { opacity: 1; transform: scale(1.05); }
        100% { opacity: 1; transform: scale(1); }
    }

    @keyframes donateRibbon {
        to { stroke-dashoffset: 0; }
    }

    @keyframes donateSparkle {
        0% { opacity: 0; transform: scale(.55) rotate(-12deg); }
        34% { opacity: 1; }
        100% { opacity: 0; transform: scale(1.25) rotate(12deg); }
    }

    @keyframes donateWaitPulse {
        0%, 100% { transform: scale(.98); opacity: .78; }
        50% { transform: scale(1.04); opacity: 1; }
    }

    @media (max-width: 520px) {
        .donate-shell {
            padding: 22px;
        }

        .donate-grid {
            grid-template-columns: 1fr;
        }

        .donate-result-actions {
            display: grid;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .donate-celebration .seal,
        .donate-celebration .ribbon,
        .donate-celebration .sparkle,
        .donate-result-state.is-waiting .donate-celebration .seal {
            animation: none;
        }

        .donate-celebration .ribbon {
            stroke-dashoffset: 0;
        }

        .donate-celebration .sparkle {
            opacity: .7;
        }
    }
</style>

<div class="donate-starfield" aria-hidden="true"></div>
<main class="donate-shell<?php echo $donationInitialState === 'success' ? ' is-result' : ''; ?>" data-donation-shell data-initial-state="<?php echo htmlspecialchars($donationInitialState); ?>" data-initial-thank-you="<?php echo $donationThankYouJson; ?>">
    <?php if (!$donationsEnabled): ?>
    <div class="donate-unavailable-state">
        <a class="donate-back" href="<?php echo htmlspecialchars($loginUrl); ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to sign in</a>
        <h1>Donation support is unavailable</h1>
        <p class="donate-copy">Donation checkout is currently turned off for this platform.</p>
    </div>
    <?php else: ?>
    <div class="donate-checkout-state" data-donation-checkout-state <?php echo $donationInitialState === 'success' ? 'hidden' : ''; ?>>
        <a class="donate-back" href="<?php echo htmlspecialchars($loginUrl); ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to sign in</a>
        <h1>Support Startup AI Access</h1>
        <p class="donate-copy">Your contribution helps small startups access AI tools without enterprise-sized costs. This checkout does not create an account or change any workspace package.</p>
        <?php if ($platformSystemLegalName !== ''): ?>
            <p class="donate-system-legal">System legal name: <strong><?php echo htmlspecialchars($platformSystemLegalName); ?></strong></p>
        <?php endif; ?>

        <form class="donate-form" id="publicDonationForm" action="<?php echo htmlspecialchars($donationEndpoint); ?>" method="post" data-public-donation-form data-donation-status-endpoint="<?php echo htmlspecialchars($donationStatusEndpoint); ?>">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($returnTo); ?>">

        <div class="donate-grid">
            <label class="donate-field">
                <span>Amount</span>
                <input type="number" name="amount" min="1" step="1" placeholder="Enter amount" required>
            </label>
            <label class="donate-field">
                <span>Currency</span>
                <select name="currency" data-donation-currency <?php echo $donationHasAvailableMethods ? '' : 'disabled'; ?>>
                    <?php if ($donationCurrencies !== []): ?>
                        <?php foreach ($donationCurrencies as $currencyOption): ?>
                            <?php $currencyValue = (string) ($currencyOption['value'] ?? ''); ?>
                            <option value="<?php echo htmlspecialchars($currencyValue); ?>" <?php echo $currencyValue === $donationDefaultCurrency ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) ($currencyOption['label'] ?? $currencyValue)); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="">No currencies available</option>
                    <?php endif; ?>
                </select>
            </label>
        </div>

        <label class="donate-field">
            <span>Method</span>
            <select name="payment_mode" data-donation-method data-donation-method-options="<?php echo $donationModesJson; ?>" <?php echo $donationHasAvailableMethods ? '' : 'disabled'; ?>>
                <?php if ($donationInitialModes !== []): ?>
                    <?php foreach ($donationInitialModes as $mode): ?>
                        <option value="<?php echo htmlspecialchars((string) ($mode['key'] ?? '')); ?>" data-requires-phone="<?php echo !empty($mode['requires_phone']) ? '1' : '0'; ?>">
                            <?php echo htmlspecialchars((string) ($mode['label'] ?? $mode['key'] ?? 'Payment method')); ?>
                        </option>
                    <?php endforeach; ?>
                <?php else: ?>
                    <option value="">No methods available</option>
                <?php endif; ?>
            </select>
        </label>

        <label class="donate-field" data-donation-email-field>
            <span>Email for checkout</span>
            <input type="email" name="customer_email" autocomplete="email" placeholder="you@example.com" data-donation-email required>
        </label>

        <label class="donate-field" data-donation-phone-field hidden>
            <span>M-Pesa phone</span>
            <input type="tel" name="customer_phone" inputmode="tel" placeholder="254712345678" data-donation-phone disabled>
        </label>

        <label class="donate-field">
            <span>Message optional</span>
            <textarea name="message" maxlength="280" placeholder="Add a note for the team"></textarea>
        </label>

        <span class="donate-status<?php echo $donationHasAvailableMethods ? '' : ' is-error'; ?>" data-donation-status aria-live="polite"><?php echo $donationHasAvailableMethods ? '' : 'Donation checkout is temporarily unavailable.'; ?></span>
        <button class="donate-button" type="submit" data-donation-submit <?php echo $donationHasAvailableMethods ? '' : 'disabled'; ?>>
            <i class="fas fa-hand-holding-heart" aria-hidden="true"></i>
            Support Startup AI Access
        </button>
        <p class="donate-footnote">Donations do not change package access or AI Credit balances.</p>
        </form>
    </div>

    <section class="donate-result-state" data-donation-result-state aria-live="polite" <?php echo $donationInitialState === 'success' ? '' : 'hidden'; ?>>
        <div class="donate-celebration" aria-hidden="true">
            <svg viewBox="0 0 178 138" role="img">
                <path class="ribbon" d="M17 84 C40 44, 67 38, 87 57 C105 76, 132 73, 160 38" fill="none" stroke="rgba(245,158,11,.56)" stroke-width="5" stroke-linecap="round"/>
                <path class="ribbon" d="M24 105 C56 123, 103 121, 145 96" fill="none" stroke="rgba(251,191,36,.5)" stroke-width="4" stroke-linecap="round"/>
                <g class="seal">
                    <circle cx="90" cy="74" r="42" fill="url(#donateSeal)" stroke="rgba(146,64,14,.18)" stroke-width="2"/>
                    <path class="check" d="M70 75 L84 89 L112 59" fill="none" stroke="#fff7ed" stroke-width="8" stroke-linecap="round" stroke-linejoin="round"/>
                </g>
                <path class="sparkle" d="M51 25 l5 11 11 5-11 5-5 11-5-11-11-5 11-5z" fill="#fbbf24"/>
                <path class="sparkle" d="M134 18 l4 9 9 4-9 4-4 9-4-9-9-4 9-4z" fill="#fde68a"/>
                <path class="sparkle" d="M142 80 l3 7 7 3-7 3-3 7-3-7-7-3 7-3z" fill="#f59e0b"/>
                <defs>
                    <linearGradient id="donateSeal" x1="56" y1="39" x2="124" y2="114" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#fde68a"/>
                        <stop offset=".52" stop-color="#f59e0b"/>
                        <stop offset="1" stop-color="#b45309"/>
                    </linearGradient>
                </defs>
            </svg>
        </div>
        <p class="donate-result-kicker" data-donation-result-kicker>Payment confirmed</p>
        <h1 data-donation-result-title>Thank you for supporting startup AI access</h1>
        <p class="donate-result-message" data-donation-result-message>Your contribution has been received. You helped keep AI access more reachable for small teams.</p>
        <div class="donate-result-amount" data-donation-result-amount hidden>
            <span>Contribution</span>
            <strong data-donation-result-amount-text></strong>
        </div>
        <div class="donate-result-actions">
            <a class="donate-secondary-action" href="<?php echo htmlspecialchars($loginUrl); ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to sign in</a>
            <button class="donate-button" type="button" data-donation-reset><i class="fas fa-hand-holding-heart" aria-hidden="true"></i> Support again</button>
        </div>
    </section>
    <?php endif; ?>
</main>

<script>
(function () {
    var form = document.querySelector('[data-public-donation-form]');
    if (!form || !window.fetch) {
        return;
    }

    var shell = document.querySelector('[data-donation-shell]');
    var checkoutState = document.querySelector('[data-donation-checkout-state]');
    var resultState = document.querySelector('[data-donation-result-state]');
    var resultKicker = document.querySelector('[data-donation-result-kicker]');
    var resultTitle = document.querySelector('[data-donation-result-title]');
    var resultMessage = document.querySelector('[data-donation-result-message]');
    var resultAmount = document.querySelector('[data-donation-result-amount]');
    var resultAmountText = document.querySelector('[data-donation-result-amount-text]');
    var resetButton = document.querySelector('[data-donation-reset]');
    var method = form.querySelector('[data-donation-method]');
    var currency = form.querySelector('[data-donation-currency]');
    var emailField = form.querySelector('[data-donation-email-field]');
    var emailInput = form.querySelector('[data-donation-email]');
    var phoneField = form.querySelector('[data-donation-phone-field]');
    var phoneInput = form.querySelector('[data-donation-phone]');
    var status = form.querySelector('[data-donation-status]');
    var submit = form.querySelector('[data-donation-submit]');
    var statusEndpoint = form.getAttribute('data-donation-status-endpoint') || '';
    var csrfInput = form.querySelector('input[name="csrf_token"]');
    var methodOptions = {};
    var pollTimer = null;
    var pollAttempts = 0;
    var maxPollAttempts = 60;

    if (method) {
        try {
            methodOptions = JSON.parse(method.getAttribute('data-donation-method-options') || '{}') || {};
        } catch (error) {
            methodOptions = {};
        }
    }

    function setStatus(message, isError) {
        if (!status) {
            return;
        }
        status.textContent = message || '';
        status.classList.toggle('is-error', !!isError);
    }

    function formatAmount(amount, currencyCode) {
        var numericAmount = Number(amount || 0);
        var code = String(currencyCode || 'KES').toUpperCase();
        try {
            return new Intl.NumberFormat(undefined, {
                style: 'currency',
                currency: code,
                maximumFractionDigits: numericAmount % 1 === 0 ? 0 : 2
            }).format(numericAmount);
        } catch (error) {
            return code + ' ' + numericAmount.toLocaleString();
        }
    }

    function stopPolling() {
        if (pollTimer) {
            window.clearTimeout(pollTimer);
            pollTimer = null;
        }
    }

    function showDonationResult(data) {
        data = data && typeof data === 'object' ? data : {};
        var state = data.state || 'waiting';
        var safeState = ['waiting', 'success', 'failed', 'expired'].indexOf(state) === -1 ? 'waiting' : state;

        if (checkoutState) {
            checkoutState.hidden = true;
        }
        if (resultState) {
            resultState.hidden = false;
            resultState.classList.remove('is-waiting', 'is-success', 'is-failed', 'is-expired');
            resultState.classList.add('is-' + safeState);
        }
        if (shell) {
            shell.classList.add('is-result');
        }
        if (resultKicker) {
            resultKicker.textContent = safeState === 'success'
                ? 'Payment confirmed'
                : (safeState === 'waiting' ? 'Waiting for phone approval' : 'Try again when ready');
        }
        if (resultTitle) {
            resultTitle.textContent = data.title || (safeState === 'success'
                ? 'Thank you for supporting startup AI access'
                : 'Waiting for confirmation');
        }
        if (resultMessage) {
            resultMessage.textContent = data.message || 'We are checking for your payment confirmation.';
        }
        if (resultAmount && resultAmountText) {
            if (Number(data.amount || 0) > 0) {
                resultAmount.hidden = false;
                resultAmountText.textContent = formatAmount(data.amount, data.currency || 'KES');
            } else {
                resultAmount.hidden = true;
                resultAmountText.textContent = '';
            }
        }
    }

    function resetDonationForm() {
        stopPolling();
        pollAttempts = 0;
        if (resultState) {
            resultState.hidden = true;
            resultState.classList.remove('is-waiting', 'is-success', 'is-failed', 'is-expired');
        }
        if (checkoutState) {
            checkoutState.hidden = false;
        }
        if (shell) {
            shell.classList.remove('is-result');
        }
        setStatus('', false);
        if (submit) {
            submit.disabled = false;
            submit.removeAttribute('aria-busy');
        }
        syncFields();
    }

    function pollDonationStatus(checkout) {
        if (!statusEndpoint || !checkout || !checkout.checkout_session_id || !checkout.reference || !csrfInput) {
            return;
        }

        var body = new FormData();
        body.append('csrf_token', csrfInput.value || '');
        body.append('checkout_session_id', checkout.checkout_session_id);
        body.append('reference', checkout.reference);

        window.fetch(statusEndpoint, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, error: response.ok ? 'Status response was empty.' : 'Status check failed.' };
            }).then(function (payload) {
                if (!response.ok || !payload || payload.success === false) {
                    throw new Error((payload && payload.error) || 'Status check failed.');
                }
                return payload;
            });
        }).then(function (payload) {
            var data = payload.data || {};
            showDonationResult(data);
            if (data.terminal) {
                stopPolling();
                if (submit) {
                    submit.disabled = false;
                    submit.removeAttribute('aria-busy');
                }
                return;
            }
            pollAttempts += 1;
            if (pollAttempts >= maxPollAttempts) {
                stopPolling();
                showDonationResult({
                    state: 'waiting',
                    title: 'Still waiting for confirmation',
                    message: 'We have not received final payment confirmation yet. If you approved the prompt, this page can be refreshed later.',
                    amount: data.amount || checkout.amount,
                    currency: data.currency || checkout.currency
                });
                return;
            }
            pollTimer = window.setTimeout(function () {
                pollDonationStatus(checkout);
            }, 4000);
        }).catch(function () {
            pollAttempts += 1;
            if (pollAttempts >= maxPollAttempts) {
                showDonationResult({
                    state: 'waiting',
                    title: 'Still waiting for confirmation',
                    message: 'We could not confirm the payment yet. You can try again or refresh this page later.',
                    amount: checkout.amount,
                    currency: checkout.currency
                });
                return;
            }
            pollTimer = window.setTimeout(function () {
                pollDonationStatus(checkout);
            }, 5000);
        });
    }

    function startDonationWatch(data) {
        stopPolling();
        pollAttempts = 0;
        showDonationResult({
            state: 'waiting',
            title: 'Waiting for phone approval',
            message: data.display_text || 'M-Pesa prompt sent. Approve it on your phone to complete payment.',
            amount: data.amount,
            currency: data.currency
        });
        pollDonationStatus({
            checkout_session_id: data.checkout_session_id,
            reference: data.reference,
            amount: data.amount,
            currency: data.currency
        });
    }

    function modesForCurrency() {
        var selectedCurrency = currency ? String(currency.value || '').toUpperCase() : '';
        return Array.isArray(methodOptions[selectedCurrency]) ? methodOptions[selectedCurrency] : [];
    }

    function selectedMode() {
        var modes = modesForCurrency();
        var selectedValue = method ? method.value : '';
        for (var index = 0; index < modes.length; index += 1) {
            if (modes[index] && modes[index].key === selectedValue) {
                return modes[index];
            }
        }
        return modes.length ? modes[0] : null;
    }

    function rebuildMethodOptions() {
        if (!method) {
            return false;
        }

        var previousValue = method.value;
        var modes = modesForCurrency();
        method.innerHTML = '';

        if (!modes.length) {
            var emptyOption = document.createElement('option');
            emptyOption.value = '';
            emptyOption.textContent = 'No methods available';
            method.appendChild(emptyOption);
            method.disabled = true;
            return false;
        }

        modes.forEach(function (modeConfig) {
            var option = document.createElement('option');
            option.value = modeConfig.key || '';
            option.textContent = modeConfig.label || modeConfig.key || 'Payment method';
            option.setAttribute('data-requires-phone', modeConfig.requires_phone ? '1' : '0');
            method.appendChild(option);
        });

        var hasPreviousValue = modes.some(function (modeConfig) {
            return modeConfig && modeConfig.key === previousValue;
        });
        method.value = hasPreviousValue ? previousValue : (modes[0].key || '');
        method.disabled = false;
        return true;
    }

    function syncFields() {
        var hasMethods = rebuildMethodOptions();
        var modeConfig = selectedMode();
        var requiresPhone = !!(modeConfig && modeConfig.requires_phone);

        if (currency) {
            currency.disabled = !hasMethods;
        }
        if (submit && submit.getAttribute('aria-busy') !== 'true') {
            submit.disabled = !hasMethods;
        }
        if (!hasMethods) {
            setStatus('Donation checkout is temporarily unavailable for the selected currency.', true);
        } else if (status && status.textContent === 'Donation checkout is temporarily unavailable for the selected currency.') {
            setStatus('', false);
        }

        if (emailField) {
            emailField.hidden = requiresPhone;
        }
        if (emailInput) {
            emailInput.required = hasMethods && !requiresPhone;
            emailInput.disabled = !hasMethods || requiresPhone;
            if (requiresPhone || !hasMethods) {
                emailInput.value = '';
            }
        }
        if (phoneField) {
            phoneField.hidden = !requiresPhone;
        }
        if (phoneInput) {
            phoneInput.required = hasMethods && requiresPhone;
            phoneInput.disabled = !hasMethods || !requiresPhone;
            if (!requiresPhone || !hasMethods) {
                phoneInput.value = '';
            }
        }

        return hasMethods;
    }

    function instructionText(data) {
        var instructions = data && data.instructions && typeof data.instructions === 'object'
            ? data.instructions
            : {};
        var visibleLabels = {
            account_name: 'Account name',
            account_number: 'Account number',
            bank_name: 'Bank',
            expires_at: 'Expires at'
        };
        var parts = [];
        Object.keys(visibleLabels).forEach(function (key) {
            if (instructions[key]) {
                parts.push(visibleLabels[key] + ': ' + String(instructions[key]));
            }
        });
        return parts.join(' | ');
    }

    if (resetButton) {
        resetButton.addEventListener('click', resetDonationForm);
    }
    if (method) {
        method.addEventListener('change', syncFields);
    }
    if (currency) {
        currency.addEventListener('change', syncFields);
    }
    syncFields();

    if (shell && shell.getAttribute('data-initial-state') === 'success') {
        try {
            showDonationResult(JSON.parse(shell.getAttribute('data-initial-thank-you') || '{}') || {});
        } catch (error) {
            showDonationResult({
                state: 'success',
                title: 'Thank you for supporting startup AI access',
                message: 'Your contribution has been received.'
            });
        }
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (!syncFields()) {
            return;
        }
        setStatus('Starting checkout...', false);
        if (submit) {
            submit.disabled = true;
            submit.setAttribute('aria-busy', 'true');
        }

        window.fetch(form.getAttribute('action') || '', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        }).then(function (response) {
            return response.json().catch(function () {
                return { success: false, error: response.ok ? 'Checkout response was empty.' : 'Checkout failed.' };
            }).then(function (payload) {
                if (!response.ok || !payload || payload.success === false) {
                    throw new Error((payload && payload.error) || 'Checkout failed.');
                }
                return payload;
            });
        }).then(function (payload) {
            var data = payload.data || {};
            var redirectUrl = data.authorization_url || data.checkout_url || '';
            if (redirectUrl) {
                setStatus('Opening checkout...', false);
                window.location.href = redirectUrl;
                return;
            }
            var instructions = instructionText(data);
            setStatus((data.display_text || 'Donation checkout created.') + (instructions ? ' ' + instructions : ''), false);
            startDonationWatch(data);
        }).catch(function (error) {
            setStatus(error && error.message ? error.message : 'Donation checkout failed.', true);
        }).finally(function () {
            if (submit && (!checkoutState || !checkoutState.hidden)) {
                submit.disabled = false;
                submit.removeAttribute('aria-busy');
            }
        });
    });
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/auth.php';
