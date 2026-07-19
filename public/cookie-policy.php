<?php
/**
 * Cookie Policy Page
 * Public page - no authentication required
 */

require_once __DIR__ . '/_public_bootstrap.php';

$pageTitle = 'Cookie Policy - ' . brandProductName();
$companyName = $_ENV['COMPANY_NAME'] ?? brandProductName();
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

    .policy-container table {
        width: 100%;
        border-collapse: collapse;
        margin: 20px 0;
        background: var(--input);
        border-radius: 12px;
        overflow: hidden;
        box-shadow: inset 3px 3px 6px var(--shadow-dark),
                    inset -3px -3px 6px var(--shadow-light);
    }

    .policy-container th {
        background: var(--bg);
        padding: 12px;
        text-align: left;
        font-weight: 600;
        color: var(--text);
        border-bottom: 2px solid var(--shadow-dark);
    }

    .policy-container td {
        padding: 12px;
        border-bottom: 1px solid rgba(0, 0, 0, 0.1);
        color: var(--text);
        opacity: 0.8;
    }

    .policy-container tr:last-child td {
        border-bottom: none;
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

        .policy-container table {
            font-size: 12px;
        }

        .policy-container th,
        .policy-container td {
            padding: 8px;
        }
    }
</style>

<div class="policy-container">
    <h1>Cookie Policy</h1>
    <p class="last-updated">
        <strong>Last Updated:</strong> <?php echo date('F j, Y', strtotime($lastUpdated)); ?>
    </p>

    <div style="line-height: 1.8;">
        <section>
            <h2>1. What Are Cookies?</h2>
            <p>
                Cookies are small text files that are placed on your computer or mobile device when you visit a website. 
                They are widely used to make websites work more efficiently and provide information to the website owners.
            </p>
        </section>

        <section>
            <h2>2. How We Use Cookies</h2>
            <p>
                <?php echo htmlspecialchars($companyName); ?> uses cookies to enhance your experience, analyze site usage, 
                and assist in our marketing efforts. We use both session cookies (which expire when you close your browser) 
                and persistent cookies (which remain on your device until deleted or expired).
            </p>
        </section>

        <section>
            <h2>3. Types of Cookies We Use</h2>
            
            <h3>3.1 Essential Cookies</h3>
            <p>These cookies are necessary for the website to function and cannot be switched off. They are usually set in response to actions made by you, such as logging in or filling in forms.</p>
            <table>
                <thead>
                    <tr>
                        <th>Cookie Name</th>
                        <th>Purpose</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td style="font-family: monospace;">PHPSESSID</td>
                        <td>Maintains your session state and authentication</td>
                        <td>Session (deleted when browser closes)</td>
                    </tr>
                    <tr>
                        <td style="font-family: monospace;">csrf_token</td>
                        <td>Security token to prevent cross-site request forgery attacks</td>
                        <td>Session</td>
                    </tr>
                </tbody>
            </table>

            <h3>3.2 Analytics and Tracking Cookies</h3>
            <p>These cookies help us understand how visitors interact with our website by collecting and reporting information anonymously.</p>
            <table>
                <thead>
                    <tr>
                        <th>Storage Type</th>
                        <th>Key Name</th>
                        <th>Purpose</th>
                        <th>Duration</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>localStorage</td>
                        <td style="font-family: monospace;">crm_visitor_id</td>
                        <td>Unique identifier for tracking visitor behavior across sessions</td>
                        <td>Persistent (until deleted)</td>
                    </tr>
                    <tr>
                        <td>sessionStorage</td>
                        <td style="font-family: monospace;">crm_utm</td>
                        <td>Stores UTM parameters for marketing campaign tracking</td>
                        <td>Session</td>
                    </tr>
                </tbody>
            </table>
            <p style="margin-top: 20px;">
                <strong>Note:</strong> These cookies require your consent. You can manage your cookie preferences using our 
                <a href="javascript:void(0);" onclick="if(window.cookieConsent) window.cookieConsent.showSettings();">Cookie Settings</a>.
            </p>

            <h3>3.3 Functional Cookies</h3>
            <p>These cookies enable enhanced functionality and personalization, such as remembering your preferences.</p>
            <ul>
                <li>User preferences and settings</li>
                <li>Saved searches and filters</li>
                <li>Language preferences</li>
            </ul>
        </section>

        <section>
            <h2>4. Third-Party Cookies</h2>
            <p>
                We may use third-party services that set their own cookies. These services help us analyze website usage 
                and provide additional functionality. We do not control these third-party cookies, and you should review 
                their respective privacy policies.
            </p>
        </section>

        <section>
            <h2>5. Managing Cookies</h2>
            <p>You have several options for managing cookies:</p>
            
            <h3>5.1 Browser Settings</h3>
            <p>Most web browsers allow you to control cookies through their settings. You can:</p>
            <ul>
                <li>Block all cookies</li>
                <li>Block third-party cookies</li>
                <li>Delete cookies when you close your browser</li>
                <li>Delete existing cookies</li>
            </ul>
            <p>Please note that blocking essential cookies may affect the functionality of our website.</p>

            <h3>5.2 Cookie Consent Banner</h3>
            <p>
                When you first visit our website, you will see a cookie consent banner. You can accept all cookies, reject 
                non-essential cookies, or customize your preferences. You can change your preferences at any time by clicking 
                the "Cookie Settings" link in the footer or using the cookie consent banner.
            </p>

            <h3>5.3 Browser-Specific Instructions</h3>
            <ul>
                <li><strong>Chrome:</strong> Settings → Privacy and security → Cookies and other site data</li>
                <li><strong>Firefox:</strong> Options → Privacy & Security → Cookies and Site Data</li>
                <li><strong>Safari:</strong> Preferences → Privacy → Cookies and website data</li>
                <li><strong>Edge:</strong> Settings → Cookies and site permissions → Cookies and site data</li>
            </ul>
        </section>

        <section>
            <h2>6. Impact of Disabling Cookies</h2>
            <p>
                If you choose to disable cookies, some features of our website may not function properly. Essential cookies 
                are required for basic functionality, such as maintaining your login session. Disabling non-essential 
                cookies may limit certain features but will not prevent you from using the core functionality of our system.
            </p>
        </section>

        <section>
            <h2>7. Updates to This Cookie Policy</h2>
            <p>
                We may update this Cookie Policy from time to time to reflect changes in our practices or for other operational, 
                legal, or regulatory reasons. We will notify you of any material changes by posting the new Cookie Policy on 
                this page and updating the "Last Updated" date.
            </p>
        </section>

        <section>
            <h2>8. Contact Us</h2>
            <p>
                If you have any questions about our use of cookies, please contact us at 
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
