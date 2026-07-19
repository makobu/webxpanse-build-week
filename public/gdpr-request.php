<?php
/**
 * GDPR Request Form
 * Public page - no authentication required
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
use CRM\Modules\GDPR;

$pageTitle = 'GDPR Request - ' . brandProductName();
$success = false;
$error = '';
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Database::init(require __DIR__ . '/../config/database.php');

    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $requestType = $_POST['request_type'] ?? '';
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please provide a valid email address.';
    } elseif (empty($requestType)) {
        $error = 'Please select a request type.';
    } else {
        try {
            $gdpr = new GDPR();
            $result = $gdpr->createRequest($email, $requestType);
            $success = true;
            $message = 'A verification email has been sent to ' . htmlspecialchars($email) . '. Please check your inbox and click the verification link to complete your request.';
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

ob_start();
?>

<div style="max-width: 700px; margin: 0 auto; padding: var(--spacing-xl);">
    <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-md);">GDPR Data Request</h1>
    <p style="color: var(--charcoal-grey); margin-bottom: var(--spacing-lg);">
        Under the General Data Protection Regulation (GDPR), you have the right to access, export, rectify, or delete your personal data. 
        Please use this form to submit a request.
    </p>

    <?php if ($success): ?>
        <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
            <p style="margin: 0;"><strong>Success!</strong> <?php echo $message; ?></p>
        </div>
    <?php elseif ($error): ?>
        <div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
            <p style="margin: 0;"><strong>Error:</strong> <?php echo htmlspecialchars($error); ?></p>
        </div>
    <?php endif; ?>

    <div style="background: white; border: 1px solid var(--border-color); border-radius: 8px; padding: var(--spacing-xl);">
        <form method="POST" action="gdpr-request.php">
            <div style="margin-bottom: var(--spacing-lg);">
                <label for="email" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">
                    Email Address *
                </label>
                <input 
                    type="email" 
                    id="email" 
                    name="email" 
                    required
                    value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
                    style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px;"
                    placeholder="your.email@example.com"
                >
                <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                    We will send a verification email to this address to confirm your request.
                </small>
            </div>

            <div style="margin-bottom: var(--spacing-lg);">
                <label style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">
                    Request Type *
                </label>
                <div style="display: flex; flex-direction: column; gap: var(--spacing-sm);">
                    <label style="display: flex; align-items: start; gap: var(--spacing-sm); padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; cursor: pointer;">
                        <input type="radio" name="request_type" value="export" required style="margin-top: 4px;">
                        <div>
                            <strong>Data Export</strong>
                            <p style="margin: 4px 0 0 0; color: var(--charcoal-grey); font-size: 13px;">
                                Receive a copy of all your personal data in a machine-readable format (JSON).
                            </p>
                        </div>
                    </label>
                    
                    <label style="display: flex; align-items: start; gap: var(--spacing-sm); padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; cursor: pointer;">
                        <input type="radio" name="request_type" value="deletion" required style="margin-top: 4px;">
                        <div>
                            <strong>Data Deletion (Right to be Forgotten)</strong>
                            <p style="margin: 4px 0 0 0; color: var(--charcoal-grey); font-size: 13px;">
                                Request deletion of your personal data. Note: Some data may be retained for legal compliance.
                            </p>
                        </div>
                    </label>
                    
                    <label style="display: flex; align-items: start; gap: var(--spacing-sm); padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; cursor: pointer;">
                        <input type="radio" name="request_type" value="access" required style="margin-top: 4px;">
                        <div>
                            <strong>Data Access</strong>
                            <p style="margin: 4px 0 0 0; color: var(--charcoal-grey); font-size: 13px;">
                                View all personal data we hold about you.
                            </p>
                        </div>
                    </label>
                    
                    <label style="display: flex; align-items: start; gap: var(--spacing-sm); padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; cursor: pointer;">
                        <input type="radio" name="request_type" value="rectification" required style="margin-top: 4px;">
                        <div>
                            <strong>Data Rectification</strong>
                            <p style="margin: 4px 0 0 0; color: var(--charcoal-grey); font-size: 13px;">
                                Request correction of inaccurate or incomplete personal data.
                            </p>
                        </div>
                    </label>
                </div>
            </div>

            <div style="background: #f0f7ff; border: 1px solid #b3d9ff; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
                <p style="margin: 0; color: var(--charcoal-grey); font-size: 13px;">
                    <strong>Important:</strong> After submitting this form, you will receive a verification email. 
                    You must click the verification link within 24 hours to complete your request. 
                    This security measure ensures that only you can request access to or deletion of your data.
                </p>
            </div>

            <button 
                type="submit" 
                style="width: 100%; padding: var(--spacing-sm) var(--spacing-md); background: var(--accent-blue); color: white; border: none; border-radius: 4px; font-size: 16px; font-weight: 500; cursor: pointer;"
                onmouseover="this.style.background='#0052a3'"
                onmouseout="this.style.background='var(--accent-blue)'"
            >
                Submit Request
            </button>
        </form>
    </div>

    <div style="margin-top: var(--spacing-xl); padding: var(--spacing-md); background: var(--light-grey); border-radius: 4px;">
        <h3 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm); font-size: 16px;">Need Help?</h3>
        <p style="color: var(--charcoal-grey); font-size: 14px; margin: 0;">
            If you have questions about GDPR requests or need assistance, please contact us at 
            <a href="mailto:<?php echo htmlspecialchars($_ENV['PRIVACY_CONTACT_EMAIL'] ?? 'privacy@example.com'); ?>">
                <?php echo htmlspecialchars($_ENV['PRIVACY_CONTACT_EMAIL'] ?? 'privacy@example.com'); ?>
            </a>
        </p>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/public.php';
?>
