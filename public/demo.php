<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\DemoSessionService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$demoState = ['active' => false, 'route' => publicUrl('dashboard.php?demo=1')];
try {
    $demoState = (new DemoSessionService())->state();
} catch (\Throwable $e) {
    $demoState['error'] = 'Demo workspace is not ready yet.';
}

$isLoggedIn = Auth::check();
$user = Auth::user() ?: [];
$pageTitle = 'Open Demo Workspace - ' . brandProductName();
$authBackgroundDesktopUrl = assetUrl('images/login-bg-desktop.jpg');
$authBackgroundMobileUrl = assetUrl('images/login-bg-mobile.jpg');
$authBackgroundPreviewUrl = assetUrl('images/login-bg-preview.jpg');

ob_start();
?>

<style>
    body {
        background: var(--auth-bg-image) center center / cover no-repeat fixed, var(--auth-bg-fallback) !important;
        position: relative;
        overflow-x: hidden;
        overflow-y: auto;
    }

    body > div {
        box-sizing: border-box;
    }

    .demo-starfield,
    .demo-starfield::before,
    .demo-starfield::after {
        content: '';
        position: fixed;
        left: 0;
        top: 0;
        width: 100%;
        height: 56vh;
        pointer-events: none;
        z-index: 0;
    }

    .demo-starfield {
        background:
            radial-gradient(circle at 4% 18%, rgba(255, 255, 255, 0.55) 0 1.4px, transparent 6px),
            radial-gradient(circle at 10% 9%, rgba(255, 255, 255, 0.46) 0 1.2px, transparent 6px),
            radial-gradient(circle at 16% 30%, rgba(255, 255, 255, 0.5) 0 1.3px, transparent 6px),
            radial-gradient(circle at 23% 12%, rgba(255, 255, 255, 0.58) 0 1.4px, transparent 6px),
            radial-gradient(circle at 29% 27%, rgba(255, 255, 255, 0.52) 0 1.3px, transparent 6px),
            radial-gradient(circle at 36% 10%, rgba(255, 255, 255, 0.62) 0 1.4px, transparent 6px),
            radial-gradient(circle at 43% 24%, rgba(255, 255, 255, 0.49) 0 1.2px, transparent 6px),
            radial-gradient(circle at 50% 8%, rgba(255, 255, 255, 0.56) 0 1.4px, transparent 6px),
            radial-gradient(circle at 57% 26%, rgba(255, 255, 255, 0.53) 0 1.3px, transparent 6px),
            radial-gradient(circle at 64% 11%, rgba(255, 255, 255, 0.6) 0 1.4px, transparent 6px),
            radial-gradient(circle at 71% 29%, rgba(255, 255, 255, 0.47) 0 1.2px, transparent 6px),
            radial-gradient(circle at 78% 12%, rgba(255, 255, 255, 0.58) 0 1.4px, transparent 6px),
            radial-gradient(circle at 85% 25%, rgba(255, 255, 255, 0.5) 0 1.3px, transparent 6px),
            radial-gradient(circle at 92% 9%, rgba(255, 255, 255, 0.62) 0 1.4px, transparent 6px),
            radial-gradient(circle at 97% 22%, rgba(255, 255, 255, 0.5) 0 1.2px, transparent 6px);
        animation: demoStarsDrift 13s ease-in-out infinite alternate;
        filter: drop-shadow(0 0 10px rgba(255, 255, 255, 0.42));
    }

    .demo-starfield::before {
        background:
            radial-gradient(circle at 2% 7%, rgba(255, 255, 255, 0.86) 0 1px, transparent 4px),
            radial-gradient(circle at 7% 24%, rgba(255, 255, 255, 0.78) 0 1px, transparent 4px),
            radial-gradient(circle at 14% 14%, rgba(255, 255, 255, 0.88) 0 1px, transparent 4px),
            radial-gradient(circle at 21% 33%, rgba(255, 255, 255, 0.72) 0 1px, transparent 4px),
            radial-gradient(circle at 28% 17%, rgba(255, 255, 255, 0.82) 0 1px, transparent 4px),
            radial-gradient(circle at 35% 6%, rgba(255, 255, 255, 0.92) 0 1px, transparent 4px),
            radial-gradient(circle at 41% 29%, rgba(255, 255, 255, 0.74) 0 1px, transparent 4px),
            radial-gradient(circle at 49% 13%, rgba(255, 255, 255, 0.86) 0 1px, transparent 4px),
            radial-gradient(circle at 56% 32%, rgba(255, 255, 255, 0.7) 0 1px, transparent 4px),
            radial-gradient(circle at 63% 18%, rgba(255, 255, 255, 0.8) 0 1px, transparent 4px),
            radial-gradient(circle at 70% 8%, rgba(255, 255, 255, 0.88) 0 1px, transparent 4px),
            radial-gradient(circle at 76% 27%, rgba(255, 255, 255, 0.74) 0 1px, transparent 4px),
            radial-gradient(circle at 83% 15%, rgba(255, 255, 255, 0.84) 0 1px, transparent 4px),
            radial-gradient(circle at 89% 31%, rgba(255, 255, 255, 0.72) 0 1px, transparent 4px),
            radial-gradient(circle at 95% 10%, rgba(255, 255, 255, 0.9) 0 1px, transparent 4px);
        animation: demoStarsTwinkle 6.2s ease-in-out infinite;
    }

    .demo-starfield::after {
        background:
            radial-gradient(circle at 14% 12%, rgba(255, 255, 255, 0.95) 0 1.6px, transparent 6px),
            radial-gradient(circle at 36% 22%, rgba(255, 255, 255, 0.9) 0 1.5px, transparent 6px),
            radial-gradient(circle at 58% 14%, rgba(255, 255, 255, 0.92) 0 1.7px, transparent 6px),
            radial-gradient(circle at 81% 11%, rgba(255, 255, 255, 0.9) 0 1.6px, transparent 6px),
            radial-gradient(circle at 93% 27%, rgba(255, 255, 255, 0.86) 0 1.4px, transparent 6px);
        animation: demoStarsFlash 4.8s ease-in-out infinite;
        filter: drop-shadow(0 0 14px rgba(255, 255, 255, 0.5));
    }

    @keyframes demoStarsDrift {
        0% { opacity: 0.5; transform: translate3d(0, 0, 0) scale(1); }
        50% { opacity: 0.8; transform: translate3d(0, -3px, 0) scale(1.02); }
        100% { opacity: 0.58; transform: translate3d(0, 2px, 0) scale(1); }
    }

    @keyframes demoStarsTwinkle {
        0%, 100% { opacity: 0.3; transform: scale(1); }
        20% { opacity: 0.65; transform: scale(1.05); }
        45% { opacity: 0.42; transform: scale(0.98); }
        70% { opacity: 0.78; transform: scale(1.08); }
    }

    @keyframes demoStarsFlash {
        0%, 100% { opacity: 0.18; }
        30% { opacity: 0.62; }
        52% { opacity: 0.24; }
        76% { opacity: 0.58; }
    }

    .demo-auth-shell {
        width: 420px;
        max-width: calc(100vw - 56px);
        display: flex;
        flex-direction: column;
        align-items: stretch;
        position: relative;
        z-index: 2;
        animation: demoFadeSlideIn 0.6s ease;
    }

    @keyframes demoFadeSlideIn {
        from {
            opacity: 0;
            transform: translateY(20px) scale(0.95);
        }
        to {
            opacity: 1;
            transform: translateY(0) scale(1);
        }
    }

    .demo-access-card {
        position: relative;
        width: 100%;
        box-sizing: border-box;
        padding: 30px;
        border: 1px solid rgba(191, 219, 254, 0.26);
        border-radius: 25px;
        background:
            linear-gradient(155deg, rgba(41, 96, 210, 0.96) 0%, rgba(29, 78, 216, 0.97) 46%, rgba(30, 64, 175, 0.98) 100%),
            radial-gradient(circle at 18% 0%, rgba(219, 234, 254, 0.34), transparent 18rem);
        box-shadow:
            12px 12px 30px rgba(92, 111, 141, 0.36),
            -12px -12px 30px rgba(255, 255, 255, 0.58),
            inset 0 1px 0 rgba(255, 255, 255, 0.34);
        color: #f8fbff;
    }

    .demo-access-card::before {
        content: '';
        position: absolute;
        inset: -10px;
        border-radius: 35px;
        background: linear-gradient(145deg, rgba(255, 255, 255, 0.56), rgba(96, 165, 250, 0.18));
        z-index: -1;
        opacity: 0.62;
    }

    .demo-back-link {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: rgba(239, 246, 255, 0.82);
        text-decoration: none;
        font-size: 13px;
        margin-bottom: 16px;
        transition: color 0.2s ease, opacity 0.2s ease, transform 0.2s ease;
    }

    .demo-back-link:hover,
    .demo-back-link:focus-visible {
        color: #ffffff;
        opacity: 1;
        transform: translateX(-1px);
    }

    .demo-back-link i {
        font-size: 12px;
    }

    .demo-access-card h1,
    .demo-access-card h2 {
        margin: 0;
        color: #ffffff;
        text-align: center;
        font-size: 28px;
        font-weight: 600;
        line-height: 1.15;
        letter-spacing: 0;
    }

    .demo-access-subtitle {
        margin: 18px 0 24px;
        color: rgba(239, 246, 255, 0.82);
        font-size: 14px;
        line-height: 1.45;
        text-align: center;
    }

    .demo-access-form,
    .demo-access-actions {
        display: grid;
        gap: 14px;
    }

    .demo-input-group {
        position: relative;
        border-radius: 12px;
        background: rgba(248, 251, 255, 0.94);
        box-shadow:
            inset 5px 5px 10px rgba(20, 46, 99, 0.24),
            inset -5px -5px 10px rgba(255, 255, 255, 0.78);
        padding: 14px 16px;
        transition: box-shadow 0.3s ease, background 0.3s ease, transform 0.2s ease;
    }

    .demo-input-group:hover,
    .demo-input-group:focus-within {
        background: #ffffff;
        box-shadow:
            0 0 0 3px rgba(191, 219, 254, 0.62),
            inset 5px 5px 10px rgba(20, 46, 99, 0.18),
            inset -5px -5px 10px rgba(255, 255, 255, 0.84);
        transform: translateY(-1px);
    }

    .demo-input-group input {
        width: 100%;
        box-sizing: border-box;
        border: none;
        background: none;
        outline: none;
        color: #0f172a;
        font: inherit;
        font-size: 16px;
        padding: 8px 0 0;
    }

    .demo-input-group label {
        position: absolute;
        top: 16px;
        left: 16px;
        color: #7896b4;
        font-size: 14px;
        pointer-events: none;
        transition: 0.24s ease;
    }

    .demo-input-group input:focus + label,
    .demo-input-group input:not(:placeholder-shown) + label {
        top: 4px;
        color: #2563eb;
        font-size: 11px;
    }

    .demo-access-check {
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 12px;
        border: 1px solid rgba(219, 234, 254, 0.42);
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.15);
        box-shadow:
            inset 1px 1px 0 rgba(255, 255, 255, 0.22),
            3px 3px 9px rgba(15, 23, 42, 0.12);
        color: rgba(248, 251, 255, 0.92);
        font-size: 13px;
        font-weight: 400;
        line-height: 1.35;
        cursor: pointer;
    }

    .demo-access-check input {
        flex: 0 0 auto;
        width: 16px;
        height: 16px;
        margin: 1px 0 0;
        accent-color: #ffffff;
    }

    .demo-access-status {
        display: none;
        border-radius: 12px;
        font-size: 13px;
        font-weight: 700;
        line-height: 1.45;
        padding: 11px 12px;
        text-align: center;
    }

    .demo-access-status.is-visible {
        display: block;
    }

    .demo-access-status.is-error {
        background: rgba(254, 242, 242, 0.95);
        color: #991b1b;
    }

    .demo-access-status.is-ok {
        background: rgba(219, 234, 254, 0.94);
        color: #1e3a8a;
    }

    .demo-access-button,
    .demo-access-secondary {
        width: 100%;
        min-height: 46px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-sizing: border-box;
        border-radius: 12px;
        font-size: 16px;
        font-weight: bold;
        line-height: 1.2;
        text-decoration: none;
        transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, color 0.2s ease;
    }

    .demo-access-button {
        margin-top: 2px;
        border: 1px solid rgba(255, 255, 255, 0.9);
        background: linear-gradient(145deg, #ffffff, #dbeafe);
        color: #123f97;
        cursor: pointer;
        box-shadow:
            5px 5px 13px rgba(15, 23, 42, 0.22),
            -4px -4px 10px rgba(255, 255, 255, 0.26),
            inset 0 1px 0 rgba(255, 255, 255, 0.75);
    }

    .demo-access-button:hover,
    .demo-access-button:focus-visible {
        background: #ffffff;
        transform: scale(1.015);
        box-shadow:
            7px 7px 16px rgba(15, 23, 42, 0.25),
            -5px -5px 12px rgba(255, 255, 255, 0.28);
    }

    .demo-access-button:active {
        transform: scale(0.985);
        box-shadow:
            inset 5px 5px 10px rgba(20, 46, 99, 0.18),
            inset -5px -5px 10px rgba(255, 255, 255, 0.82);
    }

    .demo-access-button:disabled,
    .demo-access-button[aria-busy="true"] {
        cursor: wait;
        opacity: 0.76;
        transform: none;
    }

    .demo-access-secondary {
        border: 1px solid rgba(239, 246, 255, 0.68);
        background: rgba(255, 255, 255, 0.08);
        color: #f8fbff;
        cursor: pointer;
        font-family: inherit;
    }

    .demo-access-secondary:hover,
    .demo-access-secondary:focus-visible {
        background: rgba(255, 255, 255, 0.16);
        color: #ffffff;
        transform: translateY(-1px);
    }

    .demo-session-note {
        margin: 0 0 22px;
        color: rgba(239, 246, 255, 0.82);
        font-size: 14px;
        line-height: 1.45;
        text-align: center;
    }

    @media (max-width: 480px) {
        .demo-auth-shell {
            max-width: calc(100vw - 32px);
        }

        .demo-access-card {
            padding: 24px 20px;
            border-radius: 24px;
        }

        .demo-access-card::before {
            inset: -8px;
            border-radius: 32px;
        }

        .demo-access-card h1,
        .demo-access-card h2 {
            font-size: 28px;
        }

        .demo-access-subtitle,
        .demo-session-note {
            font-size: 13px;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .demo-starfield,
        .demo-starfield::before,
        .demo-starfield::after,
        .demo-auth-shell,
        .demo-access-button,
        .demo-access-secondary,
        .demo-input-group {
            animation: none;
            transition: none;
        }
    }
</style>

<div class="demo-starfield" aria-hidden="true"></div>
<div class="demo-auth-shell">
    <main class="demo-access-card" aria-label="Open demo workspace">
        <a class="demo-back-link" href="<?php echo htmlspecialchars(publicUrl('login.php')); ?>">
            <i class="fas fa-arrow-left" aria-hidden="true"></i>
            <span>Back to sign in</span>
        </a>

        <?php if (!empty($demoState['active'])): ?>
            <h1>Resume your demo</h1>
            <p class="demo-session-note">Your sandbox session is active until <?php echo htmlspecialchars((string) ($demoState['expires_at'] ?? 'later today')); ?>.</p>
            <div class="demo-access-actions">
                <a class="demo-access-button" href="<?php echo htmlspecialchars((string) $demoState['route']); ?>">Open demo workspace</a>
                <button class="demo-access-secondary" type="button" id="endDemoButton">End demo</button>
            </div>
        <?php else: ?>
            <h1><?php echo $isLoggedIn ? 'Confirm demo access' : 'Instant demo access'; ?></h1>
            <p class="demo-access-subtitle">
                <?php if ($isLoggedIn): ?>
                    You are signed in as <?php echo htmlspecialchars((string) ($user['email'] ?? 'your account')); ?>. We will switch this browser into the protected demo workspace.
                <?php else: ?>
                    Create a temporary private demo session. We use your details to keep the demo private and remove contact details during cleanup unless you opt in to follow-up.
                <?php endif; ?>
            </p>

            <form class="demo-access-form" id="demoAccessForm">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                <?php if (!$isLoggedIn): ?>
                    <div class="demo-input-group">
                        <input type="text" id="demoAccessName" name="name" autocomplete="name" required maxlength="120" placeholder=" " autofocus>
                        <label for="demoAccessName">Name</label>
                    </div>
                    <div class="demo-input-group">
                        <input type="email" id="demoAccessEmail" name="email" autocomplete="email" required maxlength="180" placeholder=" ">
                        <label for="demoAccessEmail">Email</label>
                    </div>
                    <div class="demo-input-group">
                        <input type="tel" id="demoAccessPhone" name="phone" autocomplete="tel" required maxlength="40" placeholder=" ">
                        <label for="demoAccessPhone">WhatsApp phone</label>
                    </div>
                    <label class="demo-access-check" for="demoAccessContact">
                        <input type="checkbox" id="demoAccessContact" name="consent_contact" value="1">
                        <span>You may contact me by email or WhatsApp about setup help, product improvements, and offers. I can opt out anytime.</span>
                    </label>
                <?php else: ?>
                    <label class="demo-access-check" for="demoAccessPrivacy">
                        <input type="checkbox" id="demoAccessPrivacy" name="consent_privacy" value="1" checked>
                        <span>Start a protected sandbox session for this browser.</span>
                    </label>
                <?php endif; ?>
                <div class="demo-access-status" id="demoAccessStatus"></div>
                <button type="submit" class="demo-access-button" id="demoAccessSubmit" data-ready-label="<?php echo htmlspecialchars($isLoggedIn ? 'Open demo workspace' : 'Start demo'); ?>" data-loading-label="Opening..."><?php echo $isLoggedIn ? 'Open demo workspace' : 'Start demo'; ?></button>
            </form>
        <?php endif; ?>
    </main>
</div>

<script>
(function() {
    const form = document.getElementById('demoAccessForm');
    const status = document.getElementById('demoAccessStatus');
    const submit = document.getElementById('demoAccessSubmit');
    const endButton = document.getElementById('endDemoButton');

    function setStatus(message, isError) {
        if (!status) return;
        status.textContent = message || '';
        status.className = 'demo-access-status is-visible ' + (isError ? 'is-error' : 'is-ok');
    }

    if (form) {
        form.addEventListener('submit', function(event) {
            event.preventDefault();
            if (submit) {
                submit.disabled = true;
                submit.setAttribute('aria-busy', 'true');
                submit.textContent = submit.dataset.loadingLabel || 'Opening...';
            }
            const payload = {};
            new FormData(form).forEach(function(value, key) {
                payload[key] = value;
            });
            fetch(<?php echo json_encode(apiUrl('demo_access/start.php')); ?>, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            }).then(function(response) {
                return response.json().then(function(data) {
                    if (!response.ok || !data.success) {
                        throw new Error(data.error || 'Unable to start demo.');
                    }
                    return data;
                });
            }).then(function(data) {
                setStatus('Demo ready. Redirecting...', false);
                window.location.href = data.demo && data.demo.route ? data.demo.route : <?php echo json_encode(publicUrl('dashboard.php?demo=1')); ?>;
            }).catch(function(error) {
                setStatus(error.message || 'Unable to start demo.', true);
                if (submit) {
                    submit.disabled = false;
                    submit.removeAttribute('aria-busy');
                    submit.textContent = submit.dataset.readyLabel || <?php echo json_encode($isLoggedIn ? 'Open demo workspace' : 'Start demo'); ?>;
                }
            });
        });
    }

    if (endButton) {
        endButton.addEventListener('click', function() {
            fetch(<?php echo json_encode(apiUrl('demo_access/end.php')); ?>, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: <?php echo json_encode(Security::getCsrfToken()); ?> })
            }).finally(function() {
                window.location.href = <?php echo json_encode(publicUrl('demo.php?ended=1')); ?>;
            });
        });
    }
}());
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/auth.php';
?>
