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

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceGovernanceService;
use CRM\Services\WorkspaceLaunchGuardrailService;
use CRM\Services\WorkspaceLaunchReadinessException;
use CRM\Services\WorkspaceLaunchThrottleException;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$basePath = getBasePath();
$token = trim((string) ($_REQUEST['token'] ?? ''));
$service = new WorkspaceGovernanceService();
$invite = null;
$error = null;
$success = null;
$currentUser = Auth::check() ? (Auth::user() ?? []) : null;

try {
    $invite = $token !== '' ? $service->getInvitePreview($token) : null;
} catch (WorkspaceLaunchReadinessException $e) {
    $error = $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } elseif ($token === '' || $invite === null) {
        $error = 'This workspace invite could not be found.';
    } else {
        try {
            (new WorkspaceLaunchGuardrailService())->enforceInviteAcceptance($token);

            $action = trim((string) ($_POST['invite_action'] ?? ''));
            if ($action === 'accept_existing') {
                if (!$currentUser) {
                    throw new RuntimeException('Please sign in with the invited email address first.');
                }

                $service->acceptInviteForUser($token, (int) ($currentUser['id'] ?? 0));
                header('Location: ' . (empty($basePath) ? '/dashboard.php' : rtrim($basePath, '/') . '/dashboard.php') . '?success=' . rawurlencode('Workspace invite accepted.'));
                exit;
            }

            if ($action === 'create_account') {
                if ($currentUser) {
                    throw new RuntimeException('You are already signed in. Accept the invite with your current account instead.');
                }

                $password = (string) ($_POST['password'] ?? '');
                $passwordConfirm = (string) ($_POST['password_confirm'] ?? '');
                if ($password === '' || strlen($password) < 8) {
                    throw new RuntimeException('Choose a password with at least 8 characters.');
                }
                if ($password !== $passwordConfirm) {
                    throw new RuntimeException('Your password confirmation did not match.');
                }

                $accepted = $service->acceptInviteForNewUser(
                    $token,
                    $password,
                    trim((string) ($_POST['first_name'] ?? '')),
                    trim((string) ($_POST['last_name'] ?? ''))
                );

                $email = (string) ($accepted['email'] ?? '');
                if ($email === '' || !Auth::login($email, $password, false)) {
                    throw new RuntimeException('The account was created, but automatic sign-in did not complete. Please sign in manually.');
                }

                header('Location: ' . (empty($basePath) ? '/dashboard.php' : rtrim($basePath, '/') . '/dashboard.php') . '?success=' . rawurlencode('Workspace invite accepted.'));
                exit;
            }

            throw new RuntimeException('Unsupported invite action.');
        } catch (WorkspaceLaunchThrottleException $e) {
            http_response_code(429);
            header('Retry-After: ' . $e->retryAfter());
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            try {
                $invite = $service->getInvitePreview($token);
            } catch (WorkspaceLaunchReadinessException $readinessException) {
                $invite = null;
                $error = $readinessException->getMessage();
            }
            $currentUser = Auth::check() ? (Auth::user() ?? []) : null;
        }
    }
}

$pageTitle = 'Workspace Invite - ' . brandProductName();
$inviteStatus = (string) ($invite['invite_status'] ?? 'invalid');
$inviteValid = !empty($invite['is_valid']);
$inviteEmail = (string) ($invite['email'] ?? '');
$accountExists = !empty($invite['account_exists']);
$loginRedirect = 'login.php?redirect_to=' . rawurlencode('workspace_invite.php?token=' . $token);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <style>
        body { margin: 0; font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #eff6ff, #f8fafc 55%, #eef2ff); color: #0f172a; }
        .shell { min-height: 100vh; display: grid; place-items: center; padding: 2rem 1rem; }
        .card { width: min(760px, 100%); background: rgba(255,255,255,.96); border: 1px solid rgba(15,23,42,.08); border-radius: 24px; box-shadow: 0 24px 60px rgba(15,23,42,.08); padding: 2rem; display: grid; gap: 1.25rem; }
        .muted { color: #475569; }
        .notice { padding: 1rem; border-radius: 14px; border: 1px solid; }
        .notice.error { background: #fef2f2; color: #991b1b; border-color: #fecaca; }
        .notice.success { background: #ecfdf5; color: #166534; border-color: #bbf7d0; }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        .panel { border: 1px solid rgba(15,23,42,.08); border-radius: 18px; padding: 1rem; display: grid; gap: .85rem; background: #fff; }
        label { display: grid; gap: .35rem; font-weight: 600; }
        input { padding: .8rem .9rem; border-radius: 12px; border: 1px solid rgba(15,23,42,.14); font: inherit; }
        .btn { display: inline-flex; align-items: center; justify-content: center; padding: .8rem 1rem; border-radius: 12px; border: none; cursor: pointer; font: inherit; font-weight: 600; text-decoration: none; }
        .btn-primary { background: #2563eb; color: #fff; }
        .btn-secondary { background: #e2e8f0; color: #0f172a; }
    </style>
</head>
<body>
<div class="shell">
    <div class="card">
        <div>
            <div class="muted" style="margin-bottom:.5rem;"><?php echo htmlspecialchars(brandProductName()); ?></div>
            <h1 style="margin:0 0 .5rem 0;">Workspace Invite</h1>
            <p class="muted" style="margin:0;">Join the invited workspace using the stored invite token. Workspace and role always come from the invite row, not from request input.</p>
        </div>

        <?php if ($error !== null): ?>
            <div class="notice error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success !== null): ?>
            <div class="notice success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($invite === null): ?>
            <div class="notice error">This invite link is invalid or missing.</div>
        <?php else: ?>
            <div class="panel">
                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start;">
                    <div>
                        <div class="muted" style="font-size:.9rem;">Workspace</div>
                        <div style="font-size:1.2rem;font-weight:700;"><?php echo htmlspecialchars((string) ($invite['workspace_name'] ?? 'Workspace')); ?></div>
                        <div class="muted"><?php echo htmlspecialchars((string) ($invite['workspace_slug'] ?? '')); ?></div>
                    </div>
                    <div style="text-align:right;">
                        <div class="muted" style="font-size:.9rem;">Invite status</div>
                        <div style="font-size:1.05rem;font-weight:700;"><?php echo htmlspecialchars($inviteStatus); ?></div>
                        <div class="muted">Role: <?php echo htmlspecialchars((string) ($invite['role_slug'] ?? 'viewer')); ?></div>
                    </div>
                </div>
                <div class="muted">Invited email: <strong><?php echo htmlspecialchars($inviteEmail); ?></strong></div>
                <div class="muted">Expires at: <?php echo htmlspecialchars((string) ($invite['expires_at'] ?? '')); ?></div>
            </div>

            <?php if (!$inviteValid): ?>
                <div class="notice error"><?php echo htmlspecialchars(match ($inviteStatus) {
                    'accepted' => 'This invite has already been accepted.',
                    'revoked' => 'This invite has been revoked.',
                    'expired' => 'This invite has expired.',
                    default => 'This invite is no longer available.',
                }); ?></div>
            <?php else: ?>
                <div class="grid">
                    <div class="panel">
                        <div>
                            <h2 style="margin:0 0 .35rem 0;font-size:1.15rem;">Existing user</h2>
                            <p class="muted" style="margin:0;">Sign in with the invited email address, then accept the workspace membership.</p>
                        </div>

                        <?php if ($currentUser): ?>
                            <div class="muted">Signed in as <strong><?php echo htmlspecialchars((string) ($currentUser['email'] ?? '')); ?></strong></div>
                            <form method="POST" style="display:grid;gap:.85rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                                <input type="hidden" name="invite_action" value="accept_existing">
                                <button type="submit" class="btn btn-primary">Accept Invite</button>
                            </form>
                        <?php else: ?>
                            <a class="btn btn-secondary" href="<?php echo htmlspecialchars($loginRedirect); ?>">Sign In First</a>
                        <?php endif; ?>
                    </div>

                    <div class="panel">
                        <div>
                            <h2 style="margin:0 0 .35rem 0;font-size:1.15rem;">New user</h2>
                            <p class="muted" style="margin:0;">Create an account with the invited email address and join the workspace in one step.</p>
                        </div>

                        <?php if ($accountExists): ?>
                            <div class="notice error">An account already exists for <?php echo htmlspecialchars($inviteEmail); ?>. Sign in first, then accept the invite.</div>
                        <?php elseif ($currentUser): ?>
                            <div class="notice error">You are already signed in. Use the existing-user flow instead.</div>
                        <?php else: ?>
                            <form method="POST" style="display:grid;gap:.85rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                                <input type="hidden" name="invite_action" value="create_account">
                                <label>
                                    <span>First name</span>
                                    <input type="text" name="first_name" required>
                                </label>
                                <label>
                                    <span>Last name</span>
                                    <input type="text" name="last_name" required>
                                </label>
                                <label>
                                    <span>Password</span>
                                    <input type="password" name="password" required>
                                </label>
                                <label>
                                    <span>Confirm password</span>
                                    <input type="password" name="password_confirm" required>
                                </label>
                                <button type="submit" class="btn btn-primary">Create Account And Join</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
