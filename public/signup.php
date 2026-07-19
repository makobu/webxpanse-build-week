<?php

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Database;
use CRM\Security;
use CRM\Auth;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOwnerWelcomeService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $result = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
                'workspace_name' => (string) ($_POST['workspace_name'] ?? ''),
                'first_name' => (string) ($_POST['first_name'] ?? ''),
                'last_name' => (string) ($_POST['last_name'] ?? ''),
                'email' => (string) ($_POST['email'] ?? ''),
                'password' => (string) ($_POST['password'] ?? ''),
            ]);

            try {
                (new WorkspaceOwnerWelcomeService())->sendForProvisionedWorkspace(
                    (int) ($result['workspace_id'] ?? 0),
                    (int) ($result['user_id'] ?? 0),
                    (int) ($result['user_id'] ?? 0),
                    'public_signup'
                );
            } catch (\Throwable $welcomeError) {
                error_log('Workspace signup welcome email failed: ' . $welcomeError->getMessage());
            }

            Auth::completeProvisionedLogin((int) ($result['user_id'] ?? 0));
            WorkspaceContext::activateWorkspaceById((int) ($result['user_id'] ?? 0), (int) ($result['workspace_id'] ?? 0));
            header('Location: onboarding.php?signup_success=1');
            exit;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Create Workspace - ' . brandProductName();
$authBackgroundDesktopUrl = assetUrl('images/login-bg-desktop.jpg');
$authBackgroundMobileUrl = assetUrl('images/login-bg-mobile.jpg');
$authBackgroundPreviewUrl = assetUrl('images/login-bg-preview.jpg');
ob_start();
?>

<style>
    body {
        background: var(--auth-bg-image) center center / cover no-repeat fixed, var(--auth-bg-fallback) !important;
        position: relative;
        overflow: auto;
    }

    body.pink {
        background: var(--auth-bg-image) center center / cover no-repeat fixed, var(--auth-bg-fallback) !important;
    }

    .starfield,
    .starfield::before,
    .starfield::after {
        content: '';
        position: fixed;
        left: 0;
        top: 0;
        width: 100%;
        height: 56vh;
        pointer-events: none;
        z-index: 0;
    }

    .starfield {
        background:
            radial-gradient(circle at 4% 18%, rgba(255, 255, 255, 0.55) 0 1.4px, transparent 6px),
            radial-gradient(circle at 10% 9%, rgba(255, 255, 255, 0.46) 0 1.2px, transparent 6px),
            radial-gradient(circle at 16% 30%, rgba(255, 255, 255, 0.5) 0 1.3px, transparent 6px),
            radial-gradient(circle at 23% 12%, rgba(255, 255, 255, 0.58) 0 1.4px, transparent 6px),
            radial-gradient(circle at 36% 10%, rgba(255, 255, 255, 0.62) 0 1.4px, transparent 6px),
            radial-gradient(circle at 50% 8%, rgba(255, 255, 255, 0.56) 0 1.4px, transparent 6px),
            radial-gradient(circle at 64% 11%, rgba(255, 255, 255, 0.6) 0 1.4px, transparent 6px),
            radial-gradient(circle at 78% 12%, rgba(255, 255, 255, 0.58) 0 1.4px, transparent 6px),
            radial-gradient(circle at 92% 9%, rgba(255, 255, 255, 0.62) 0 1.4px, transparent 6px);
        animation: starsDrift 13s ease-in-out infinite alternate;
        filter: drop-shadow(0 0 10px rgba(255, 255, 255, 0.42));
    }

    .starfield::before {
        content: '';
        background:
            radial-gradient(circle at 2% 7%, rgba(255, 255, 255, 0.86) 0 1px, transparent 4px),
            radial-gradient(circle at 14% 14%, rgba(255, 255, 255, 0.88) 0 1px, transparent 4px),
            radial-gradient(circle at 28% 17%, rgba(255, 255, 255, 0.82) 0 1px, transparent 4px),
            radial-gradient(circle at 49% 13%, rgba(255, 255, 255, 0.86) 0 1px, transparent 4px),
            radial-gradient(circle at 70% 8%, rgba(255, 255, 255, 0.88) 0 1px, transparent 4px),
            radial-gradient(circle at 83% 15%, rgba(255, 255, 255, 0.84) 0 1px, transparent 4px),
            radial-gradient(circle at 95% 10%, rgba(255, 255, 255, 0.9) 0 1px, transparent 4px);
        animation: starsTwinkle 6.2s ease-in-out infinite;
    }

    .starfield::after {
        content: '';
        background:
            radial-gradient(circle at 14% 12%, rgba(255, 255, 255, 0.95) 0 1.6px, transparent 6px),
            radial-gradient(circle at 36% 22%, rgba(255, 255, 255, 0.9) 0 1.5px, transparent 6px),
            radial-gradient(circle at 58% 14%, rgba(255, 255, 255, 0.92) 0 1.7px, transparent 6px),
            radial-gradient(circle at 81% 11%, rgba(255, 255, 255, 0.9) 0 1.6px, transparent 6px);
        animation: starsFlash 4.8s ease-in-out infinite;
    }

    @keyframes starsDrift {
        0% { opacity: 0.5; transform: translate3d(0, 0, 0) scale(1); }
        50% { opacity: 0.8; transform: translate3d(0, -3px, 0) scale(1.02); }
        100% { opacity: 0.58; transform: translate3d(0, 2px, 0) scale(1); }
    }

    @keyframes starsTwinkle {
        0%, 100% { opacity: 0.3; transform: scale(1); }
        35% { opacity: 0.68; transform: scale(1.05); }
        70% { opacity: 0.78; transform: scale(1.08); }
    }

    @keyframes starsFlash {
        0%, 100% { opacity: 0.18; }
        30% { opacity: 0.62; }
        76% { opacity: 0.58; }
    }

    .signup-card {
        width: min(440px, calc(100vw - 40px));
        padding: 30px;
        border-radius: 25px;
        background: var(--bg);
        box-shadow: 12px 12px 30px var(--shadow-dark), -12px -12px 30px var(--shadow-light);
        animation: fadeSlideIn 0.6s ease;
        position: relative;
        z-index: 2;
    }

    .signup-card::before {
        content: '';
        position: absolute;
        top: -10px;
        left: -10px;
        right: -10px;
        bottom: -10px;
        border-radius: 35px;
        background: linear-gradient(145deg, var(--shadow-light), var(--shadow-dark));
        z-index: -1;
        opacity: 0.5;
    }

    @keyframes fadeSlideIn {
        from { opacity: 0; transform: translateY(20px) scale(0.95); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }

    .back-to-login {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        color: var(--text);
        text-decoration: none;
        font-size: 13px;
        opacity: 0.8;
        margin-bottom: 14px;
    }

    .signup-header {
        text-align: center;
        margin-bottom: 24px;
        color: var(--text);
    }

    .signup-header h1 {
        margin: 0 0 10px;
        font-size: 27px;
        line-height: 1.12;
        font-weight: 650;
        color: var(--text);
        letter-spacing: 0;
    }

    .signup-header p {
        margin: 0;
        color: var(--text);
        opacity: 0.72;
        font-size: 14px;
        line-height: 1.5;
    }

    .signup-form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    .signup-field {
        border-radius: 12px;
        background: var(--input);
        box-shadow: inset 5px 5px 10px var(--shadow-dark),
                    inset -5px -5px 10px var(--shadow-light);
        padding: 13px 15px;
        transition: box-shadow 0.3s ease;
    }

    .signup-field:focus-within {
        box-shadow: 0 0 0 3px var(--accent), inset 5px 5px 10px var(--shadow-dark), inset -5px -5px 10px var(--shadow-light);
    }

    .signup-field.is-wide {
        grid-column: 1 / -1;
    }

    .signup-field label {
        display: block;
        margin-bottom: 5px;
        color: var(--accent);
        font-size: 11px;
        font-weight: 650;
    }

    .signup-field input {
        width: 100%;
        border: none;
        background: none;
        outline: none;
        color: #333;
        font-size: 15px;
        font-family: inherit;
        padding: 0;
    }

    .signup-field input::placeholder {
        color: #9aa6b2;
    }

    .signup-notice {
        grid-column: 1 / -1;
        display: flex;
        align-items: flex-start;
        gap: 10px;
        padding: 13px 14px;
        border: 1px solid rgba(122, 147, 168, 0.28);
        border-radius: 16px;
        background: rgba(255, 255, 255, 0.48);
        color: var(--text);
        font-size: 13px;
        line-height: 1.4;
    }

    .signup-notice-icon {
        color: var(--accent);
        font-size: 16px;
        line-height: 1.2;
        padding-top: 1px;
    }

    .signup-notice strong {
        display: block;
        color: #334155;
        margin-bottom: 2px;
    }

    .signup-notice p {
        margin: 0;
        opacity: 0.76;
    }

    .signup-btn {
        grid-column: 1 / -1;
        width: 100%;
        padding: 14px;
        border: none;
        border-radius: 12px;
        background: var(--accent);
        color: white;
        font-weight: 800;
        font-size: 16px;
        box-shadow: 5px 5px 10px var(--shadow-dark),
                    -5px -5px 10px var(--shadow-light);
        cursor: pointer;
        transition: background 0.3s ease, transform 0.2s ease;
        margin-top: 4px;
    }

    .signup-btn:hover {
        background: #7a93a8;
        transform: translateY(-1px);
    }

    .signup-message {
        padding: 10px 12px;
        border-radius: 10px;
        margin-bottom: 16px;
        font-size: 13px;
        line-height: 1.4;
        box-shadow: inset 2px 2px 5px rgba(0, 0, 0, 0.05);
    }

    .signup-message.error {
        color: #991b1b;
        background: rgba(254, 226, 226, 0.8);
        border: 1px solid rgba(254, 202, 202, 0.92);
    }

    .signup-message.success {
        color: #166534;
        background: rgba(220, 252, 231, 0.78);
        border: 1px solid rgba(134, 239, 172, 0.86);
    }

    .signup-footer {
        margin-top: 22px;
        padding-top: 18px;
        border-top: 1px solid rgba(0, 0, 0, 0.1);
        text-align: center;
        color: var(--text);
        font-size: 12px;
        opacity: 0.74;
    }

    .signup-footer a {
        color: var(--text);
        font-weight: 700;
        text-decoration: none;
    }

    .signup-footer a:hover {
        color: var(--accent);
        text-decoration: underline;
    }

    @media (max-width: 520px) {
        .signup-card {
            width: min(340px, calc(100vw - 32px));
            padding: 22px;
        }

        .signup-form-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="starfield" aria-hidden="true"></div>
<div class="signup-card">
    <a href="login.php" class="back-to-login"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to sign in</a>

    <div class="signup-header">
        <h1>Set up your company workspace</h1>
        <p>Create the owner account, activate Compass Free, and open onboarding for your team.</p>
    </div>

    <?php if ($error): ?>
        <div class="signup-message error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <form method="POST" class="signup-form-grid">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">

        <div class="signup-field is-wide">
            <label for="workspace_name">Company or workspace name</label>
            <input id="workspace_name" name="workspace_name" required placeholder="Acme Growth Studio" value="<?php echo htmlspecialchars($_POST['workspace_name'] ?? ''); ?>">
        </div>

        <div class="signup-field">
            <label for="first_name">First name</label>
            <input id="first_name" name="first_name" required placeholder="Catherine" value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
        </div>

        <div class="signup-field">
            <label for="last_name">Last name</label>
            <input id="last_name" name="last_name" placeholder="Makobu" value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
        </div>

        <div class="signup-field is-wide">
            <label for="email">Owner email</label>
            <input id="email" type="email" name="email" required placeholder="you@company.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
        </div>

        <div class="signup-field is-wide">
            <label for="password">Owner password</label>
            <input id="password" type="password" name="password" required minlength="8" placeholder="At least 8 characters">
        </div>

        <div class="signup-notice" role="note">
            <span class="signup-notice-icon" aria-hidden="true"><i class="fas fa-compass"></i></span>
            <div>
                <strong>Your workspace starts on Compass Free with 50,000 onboarding AI Credits.</strong>
                <p>No checkout is required during setup.</p>
            </div>
        </div>

        <button type="submit" class="signup-btn" aria-label="Create workspace">Create Company Workspace</button>
    </form>

    <div class="signup-footer">
        Already have an account? <a href="login.php">Sign in</a>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/auth.php';
