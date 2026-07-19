<?php
/**
 * Privacy Policy Page
 * Public page - no authentication required
 */

require_once __DIR__ . '/_public_bootstrap.php';

$pageTitle = 'Privacy Policy - ' . brandProductName();
$companyName = $_ENV['COMPANY_NAME'] ?? brandProductName();
$privacyEmail = $_ENV['PRIVACY_CONTACT_EMAIL'] ?? 'privacy@example.com';
$lastUpdated = '2024-01-01'; // Update this date when policy changes

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

    .policy-container .btn {
        display: inline-block;
        padding: 12px 24px;
        background: var(--accent);
        color: white;
        text-decoration: none;
        border-radius: 12px;
        margin-top: 15px;
        box-shadow: 5px 5px 10px var(--shadow-dark),
                    -5px -5px 10px var(--shadow-light);
        transition: all 0.3s ease;
        font-weight: 600;
    }

    .policy-container .btn:hover {
        transform: scale(1.05);
        box-shadow: 6px 6px 12px var(--shadow-dark),
                    -6px -6px 12px var(--shadow-light);
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
    <h1>Privacy Policy</h1>
    <p class="last-updated">
        <strong>Last Updated:</strong> <?php echo date('F j, Y', strtotime($lastUpdated)); ?>
    </p>

    <div style="line-height: 1.8;">
        <section>
            <h2>1. Introduction</h2>
            <p>
                <?php echo htmlspecialchars($companyName); ?> ("we," "our," or "us") is committed to protecting your privacy. 
                This Privacy Policy explains how we collect, use, disclose, and safeguard your information when you use our 
                Customer Relationship Management (CRM) system.
            </p>
        </section>

        <section>
            <h2>2. Information We Collect</h2>
            
            <h3>2.1 Personal Information</h3>
            <p>We collect personal information that you provide directly to us, including:</p>
            <ul>
                <li>Name, email address, phone number, and other contact information</li>
                <li>Company name, job title, and business information</li>
                <li>Account credentials (email and password)</li>
                <li>Communication preferences and settings</li>
            </ul>

            <h3>2.2 Automatically Collected Information</h3>
            <p>When you use our system, we automatically collect:</p>
            <ul>
                <li>Session information and authentication tokens</li>
                <li>IP address and device information</li>
                <li>Browser type and version</li>
                <li>Pages visited and time spent on pages</li>
                <li>Referrer information and UTM parameters</li>
            </ul>

            <h3>2.3 Communication Data</h3>
            <p>We store communications sent through our system, including:</p>
            <ul>
                <li>Email messages (sent and received)</li>
                <li>WhatsApp messages</li>
                <li>SMS messages</li>
                <li>Notes and activity logs</li>
            </ul>
        </section>

        <section>
            <h2>3. How We Use Your Information</h2>
            <p>We use the information we collect to:</p>
            <ul>
                <li>Provide, maintain, and improve our CRM services</li>
                <li>Process and manage your account and transactions</li>
                <li>Send you service-related communications</li>
                <li>Respond to your inquiries and provide customer support</li>
                <li>Monitor and analyze usage patterns and trends</li>
                <li>Detect, prevent, and address technical issues and security threats</li>
                <li>Comply with legal obligations and enforce our terms of service</li>
            </ul>
        </section>

        <section>
            <h2>4. Data Sharing and Disclosure</h2>
            <p>We do not sell your personal information. We may share your information only in the following circumstances:</p>
            <ul>
                <li><strong>Service Providers:</strong> With third-party service providers who perform services on our behalf (e.g., email delivery, hosting)</li>
                <li><strong>Legal Requirements:</strong> When required by law, court order, or government regulation</li>
                <li><strong>Business Transfers:</strong> In connection with a merger, acquisition, or sale of assets</li>
                <li><strong>With Your Consent:</strong> When you have given explicit consent to share information</li>
            </ul>
        </section>

        <section>
            <h2>5. Data Retention</h2>
            <p>
                We retain your personal information for as long as necessary to provide our services and fulfill the purposes 
                outlined in this policy, unless a longer retention period is required or permitted by law. When you delete 
                your account, we will delete or anonymize your personal information, except where we are required to retain 
                it for legal, accounting, or regulatory purposes.
            </p>
        </section>

        <section>
            <h2>6. Your Rights (GDPR)</h2>
            <p>If you are located in the European Economic Area (EEA) or United Kingdom, you have the following rights under the General Data Protection Regulation (GDPR):</p>
            
            <h3>6.1 Right of Access (Article 15)</h3>
            <p>You have the right to request a copy of all personal data we hold about you.</p>

            <h3>6.2 Right to Rectification (Article 16)</h3>
            <p>You have the right to request correction of inaccurate or incomplete personal data.</p>

            <h3>6.3 Right to Erasure (Article 17)</h3>
            <p>You have the right to request deletion of your personal data ("right to be forgotten").</p>

            <h3>6.4 Right to Restrict Processing (Article 18)</h3>
            <p>You have the right to request restriction of processing of your personal data.</p>

            <h3>6.5 Right to Data Portability (Article 20)</h3>
            <p>You have the right to receive your personal data in a structured, commonly used, and machine-readable format.</p>

            <h3>6.6 Right to Object (Article 21)</h3>
            <p>You have the right to object to processing of your personal data for direct marketing purposes.</p>

            <h3>6.7 Right to Withdraw Consent</h3>
            <p>Where processing is based on consent, you have the right to withdraw consent at any time.</p>

            <p style="margin-top: 20px;">
                To exercise any of these rights, please contact us at 
                <a href="mailto:<?php echo htmlspecialchars($privacyEmail); ?>"><?php echo htmlspecialchars($privacyEmail); ?></a> 
                or use our <a href="gdpr-request.php">GDPR Request Form</a>.
            </p>
        </section>

        <section>
            <h2>7. Security Measures</h2>
            <p>We implement appropriate technical and organizational measures to protect your personal information, including:</p>
            <ul>
                <li>Encryption of data in transit (HTTPS/TLS)</li>
                <li>Secure password hashing and storage</li>
                <li>Regular security assessments and updates</li>
                <li>Access controls and authentication mechanisms</li>
                <li>Secure session management with HttpOnly and Secure cookies</li>
            </ul>
        </section>

        <section>
            <h2>8. International Data Transfers</h2>
            <p>
                Your information may be transferred to and processed in countries other than your country of residence. 
                These countries may have data protection laws that differ from those in your country. We ensure appropriate 
                safeguards are in place to protect your data in accordance with this Privacy Policy.
            </p>
        </section>

        <section>
            <h2>9. Cookies and Tracking</h2>
            <p>
                We use cookies and similar tracking technologies to collect and store information. For detailed information 
                about the cookies we use, please see our <a href="cookie-policy.php">Cookie Policy</a>.
            </p>
        </section>

        <section>
            <h2>10. Children's Privacy</h2>
            <p>
                Our services are not intended for individuals under the age of 18. We do not knowingly collect personal 
                information from children. If you believe we have collected information from a child, please contact us 
                immediately.
            </p>
        </section>

        <section>
            <h2>11. Changes to This Privacy Policy</h2>
            <p>
                We may update this Privacy Policy from time to time. We will notify you of any changes by posting the new 
                Privacy Policy on this page and updating the "Last Updated" date. You are advised to review this Privacy 
                Policy periodically for any changes.
            </p>
        </section>

        <section>
            <h2>12. Contact Us</h2>
            <p>
                If you have any questions about this Privacy Policy or wish to exercise your rights, please contact us:
            </p>
            <p>
                <strong>Email:</strong> <a href="mailto:<?php echo htmlspecialchars($privacyEmail); ?>"><?php echo htmlspecialchars($privacyEmail); ?></a><br>
                <strong>Company:</strong> <?php echo htmlspecialchars($companyName); ?>
            </p>
            <a href="gdpr-request.php" class="btn">
                Submit GDPR Request
            </a>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
$hideNavActions = true; // No Login or theme toggle on policy pages
include __DIR__ . '/../views/layouts/public.php';
?>
