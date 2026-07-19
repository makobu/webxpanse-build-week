<?php
/**
 * Terms of Service Page
 * Public page - no authentication required
 */

require_once __DIR__ . '/_public_bootstrap.php';

$pageTitle = 'Terms of Service - ' . brandProductName();
$companyName = $_ENV['COMPANY_NAME'] ?? brandProductName();
$lastUpdated = '2024-01-01'; // Update this date when terms change

ob_start();
?>

<style>
    .policy-container {
        background: var(--input);
        border-radius: 20px;
        padding: 40px;
        box-shadow: inset 5px 5px 15px var(--shadow-dark),
                    inset -5px -5px 15px var(--shadow-light),
                    8px 8px 20px var(--shadow-dark),
                    -8px -8px 20px var(--shadow-light);
        margin: 20px 0;
        animation: fadeSlideIn 0.6s ease;
    }

    @keyframes fadeSlideIn {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .policy-container h1 {
        color: var(--text);
        font-size: 32px;
        margin-bottom: 15px;
        font-weight: 600;
    }

    .policy-container .last-updated {
        color: var(--text);
        opacity: 0.7;
        margin-bottom: 30px;
        font-size: 14px;
        padding: 12px;
        background: var(--bg);
        border-radius: 10px;
        box-shadow: inset 3px 3px 6px var(--shadow-dark),
                    inset -3px -3px 6px var(--shadow-light);
    }

    .policy-container section {
        margin-bottom: 35px;
        padding: 25px;
        background: var(--bg);
        border-radius: 15px;
        box-shadow: inset 3px 3px 8px var(--shadow-dark),
                    inset -3px -3px 8px var(--shadow-light);
    }

    .policy-container h2 {
        color: var(--text);
        font-size: 24px;
        margin-bottom: 15px;
        font-weight: 600;
    }

    .policy-container h3 {
        color: var(--text);
        font-size: 18px;
        margin-top: 20px;
        margin-bottom: 12px;
        font-weight: 600;
    }

    .policy-container p {
        color: var(--text);
        opacity: 0.8;
        line-height: 1.8;
        margin-bottom: 15px;
    }

    .policy-container ul {
        color: var(--text);
        opacity: 0.8;
        line-height: 1.8;
        margin-left: 20px;
        margin-bottom: 15px;
    }

    .policy-container li {
        margin-bottom: 8px;
    }

    .policy-container a {
        color: var(--accent);
        text-decoration: none;
        font-weight: 600;
        transition: all 0.3s ease;
    }

    .policy-container a:hover {
        text-decoration: underline;
    }

    @media (max-width: 768px) {
        .policy-container {
            padding: 25px 20px;
        }

        .policy-container h1 {
            font-size: 26px;
        }

        .policy-container section {
            padding: 20px 15px;
        }
    }
</style>

<div class="policy-container">
    <h1>Terms of Service</h1>
    <p class="last-updated">
        <strong>Last Updated:</strong> <?php echo date('F j, Y', strtotime($lastUpdated)); ?>
    </p>

    <div style="line-height: 1.8;">
        <section>
            <h2>1. Acceptance of Terms</h2>
            <p>
                By accessing and using the <?php echo htmlspecialchars($companyName); ?> Customer Relationship Management (CRM) 
                system, you accept and agree to be bound by the terms and provision of this agreement. If you do not agree 
                to abide by the above, please do not use this service.
            </p>
        </section>

        <section>
            <h2>2. Use License</h2>
            <p>Permission is granted to temporarily use the CRM system for business purposes. Under this license you may not:</p>
            <ul>
                <li>Modify or copy the materials</li>
                <li>Use the materials for any commercial purpose or for any public display</li>
                <li>Attempt to reverse engineer any software contained in the system</li>
                <li>Remove any copyright or other proprietary notations from the materials</li>
                <li>Transfer the materials to another person or "mirror" the materials on any other server</li>
            </ul>
        </section>

        <section>
            <h2>3. User Accounts</h2>
            <p>When you create an account with us, you must provide information that is accurate, complete, and current at all times. You are responsible for:</p>
            <ul>
                <li>Maintaining the security of your account and password</li>
                <li>All activities that occur under your account</li>
                <li>Notifying us immediately of any unauthorized use of your account</li>
                <li>Ensuring that your account information is kept up to date</li>
            </ul>
        </section>

        <section>
            <h2>4. Acceptable Use</h2>
            <p>You agree not to use the service to:</p>
            <ul>
                <li>Violate any laws or regulations</li>
                <li>Infringe upon the rights of others</li>
                <li>Transmit any harmful, offensive, or illegal content</li>
                <li>Interfere with or disrupt the service or servers</li>
                <li>Attempt to gain unauthorized access to any portion of the service</li>
                <li>Use automated systems to access the service without permission</li>
            </ul>
        </section>

        <section>
            <h2>5. Data and Privacy</h2>
            <p>
                Your use of the service is also governed by our <a href="privacy-policy.php">Privacy Policy</a>. 
                You are responsible for ensuring that any data you upload or store in the system complies with applicable 
                privacy laws and regulations, including GDPR.
            </p>
        </section>

        <section>
            <h2>6. Intellectual Property</h2>
            <p>
                The service and its original content, features, and functionality are owned by <?php echo htmlspecialchars($companyName); ?> 
                and are protected by international copyright, trademark, patent, trade secret, and other intellectual property laws.
            </p>
        </section>

        <section>
            <h2>7. Service Availability</h2>
            <p>
                We strive to provide continuous availability of the service but do not guarantee uninterrupted access. 
                We reserve the right to modify, suspend, or discontinue the service at any time with or without notice.
            </p>
        </section>

        <section>
            <h2>8. Limitation of Liability</h2>
            <p>
                In no event shall <?php echo htmlspecialchars($companyName); ?> or its suppliers be liable for any damages 
                (including, without limitation, damages for loss of data or profit, or due to business interruption) arising 
                out of the use or inability to use the service, even if we have been notified orally or in writing of the 
                possibility of such damage.
            </p>
        </section>

        <section>
            <h2>9. Indemnification</h2>
            <p>
                You agree to indemnify and hold harmless <?php echo htmlspecialchars($companyName); ?> and its officers, 
                directors, employees, and agents from any claims, damages, losses, liabilities, and expenses (including 
                attorneys' fees) arising out of your use of the service or violation of these terms.
            </p>
        </section>

        <section>
            <h2>10. Termination</h2>
            <p>
                We may terminate or suspend your account and access to the service immediately, without prior notice or 
                liability, for any reason whatsoever, including without limitation if you breach the Terms. Upon termination, 
                your right to use the service will immediately cease.
            </p>
        </section>

        <section>
            <h2>11. Changes to Terms</h2>
            <p>
                We reserve the right, at our sole discretion, to modify or replace these Terms at any time. If a revision 
                is material, we will try to provide at least 30 days notice prior to any new terms taking effect. What 
                constitutes a material change will be determined at our sole discretion.
            </p>
        </section>

        <section>
            <h2>12. Governing Law</h2>
            <p>
                These Terms shall be interpreted and governed by the laws of the jurisdiction in which <?php echo htmlspecialchars($companyName); ?> 
                operates, without regard to its conflict of law provisions.
            </p>
        </section>

        <section>
            <h2>13. Contact Information</h2>
            <p>
                If you have any questions about these Terms of Service, please contact us at 
                <a href="mailto:<?php echo htmlspecialchars($_ENV['PRIVACY_CONTACT_EMAIL'] ?? 'privacy@example.com'); ?>">
                    <?php echo htmlspecialchars($_ENV['PRIVACY_CONTACT_EMAIL'] ?? 'privacy@example.com'); ?>
                </a>.
            </p>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
$hideNavActions = true; // No Login or theme toggle on policy pages
include __DIR__ . '/../views/layouts/public.php';
?>
