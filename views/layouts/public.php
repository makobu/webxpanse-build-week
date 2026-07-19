<?php
if (!function_exists('crmPublicBasePath')) {
    /**
     * Resolve public base path for policy/public pages even when config/constants.php is not loaded.
     */
    function crmPublicBasePath(): string
    {
        if (function_exists('getBasePath')) {
            return rtrim((string) getBasePath(), '/');
        }

        $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        if ($scriptName !== '') {
            $dir = str_replace('\\', '/', dirname($scriptName));
            if ($dir !== '' && $dir !== '.' && $dir !== '/') {
                return rtrim($dir, '/');
            }
        }

        return '';
    }
}

$basePath = crmPublicBasePath();
$assetBase = function_exists('assetUrl') ? rtrim(assetUrl(''), '/') : ($basePath . '/assets');
$brandLogoUrl = $assetBase . '/images/logo-web.png';
$brandLogoSrcset = $assetBase . '/images/logo-web.png 1x, ' . $assetBase . '/images/logo-web@2x.png 2x';
$loginUrl = ($basePath === '' ? '' : $basePath) . '/login.php';
$privacyUrl = ($basePath === '' ? '' : $basePath) . '/privacy-policy.php';
$cookieUrl = ($basePath === '' ? '' : $basePath) . '/cookie-policy.php';
$termsUrl = ($basePath === '' ? '' : $basePath) . '/terms-of-service.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? htmlspecialchars(brandProductName()); ?></title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?php echo htmlspecialchars($assetBase . '/images/clarity-logo-64.png'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase . '/css/cookie-consent.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg: #e0e5ec;
            --input: #ecf0f3;
            --shadow-dark: #a3b1c6;
            --shadow-light: #ffffff;
            --accent: #9db2bf;
            --text: #555;
        }

        body.pink {
            --bg: #f5e9ec;
            --input: #fdf6f9;
            --shadow-dark: #d4b9c3;
            --shadow-light: #fff;
            --accent: #e38cad;
            --text: #444;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            transition: background 0.4s ease;
        }

        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--input);
            border-radius: 50%;
            padding: 12px;
            box-shadow: 3px 3px 8px var(--shadow-dark), -3px -3px 8px var(--shadow-light);
            cursor: pointer;
            transition: transform 0.3s ease;
            font-size: 20px;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 100;
            border: none;
        }
        
        .theme-toggle:hover {
            transform: rotate(15deg) scale(1.1);
        }

        nav {
            background: var(--input);
            box-shadow: 0 2px 10px var(--shadow-dark), 0 -2px 10px var(--shadow-light);
            padding: 15px 0;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        nav .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        nav .navbar-brand {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
        }

        nav .navbar-brand .brand-logo-img {
            display: block;
            width: auto;
            height: 56px;
            max-width: 240px;
            object-fit: contain;
        }

        nav a[href*="login"] {
            color: var(--accent);
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            box-shadow: 2px 2px 5px var(--shadow-dark), -2px -2px 5px var(--shadow-light);
            transition: all 0.3s ease;
        }

        nav a[href*="login"]:hover {
            box-shadow: inset 2px 2px 5px var(--shadow-dark), inset -2px -2px 5px var(--shadow-light);
        }

        main {
            flex: 1;
            padding: 40px 20px;
        }

        main .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        footer {
            background: var(--input);
            box-shadow: 0 -2px 10px var(--shadow-dark), 0 2px 10px var(--shadow-light);
            padding: 30px 0;
            margin-top: auto;
        }

        footer .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }

        footer p {
            text-align: center;
            color: var(--text);
            opacity: 0.7;
            margin-bottom: 15px;
            font-size: 14px;
        }

        footer a {
            color: var(--accent);
            text-decoration: none;
            margin: 0 8px;
            padding: 4px 8px;
            border-radius: 6px;
            transition: all 0.3s ease;
            font-size: 14px;
        }

        footer a:hover {
            text-decoration: underline;
            box-shadow: inset 2px 2px 4px var(--shadow-dark), inset -2px -2px 4px var(--shadow-light);
        }

        @media (max-width: 768px) {
            .theme-toggle {
                top: 10px;
                right: 10px;
            }

            nav {
                padding: 12px 0;
            }

            main {
                padding: 20px 15px;
            }
        }
    </style>
</head>
<body>
    <?php if (empty($hideNavActions)): ?>
    <button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">🎨</button>
    <?php endif; ?>

    <nav>
        <div class="container">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <a href="<?php echo htmlspecialchars($loginUrl); ?>" class="navbar-brand">
                    <img src="<?php echo htmlspecialchars($brandLogoUrl); ?>" srcset="<?php echo htmlspecialchars($brandLogoSrcset); ?>" width="84" height="56" alt="<?php echo htmlspecialchars(brandProductName()); ?> logo" class="brand-logo-img" decoding="async" fetchpriority="high">
                </a>
                <?php if (empty($hideNavActions)): ?>
                <div>
                    <a href="<?php echo htmlspecialchars($loginUrl); ?>">Login</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <main>
        <div class="container">
            <?php echo $content ?? ''; ?>
        </div>
    </main>

    <footer>
        <div class="container">
            <p>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($_ENV['COMPANY_NAME'] ?? brandProductName()); ?>. All rights reserved.</p>
            <div style="display: flex; justify-content: center; flex-wrap: wrap;">
                <a href="<?php echo htmlspecialchars($privacyUrl); ?>">Privacy Policy</a>
                <span style="color: var(--text); opacity: 0.5;">|</span>
                <a href="<?php echo htmlspecialchars($cookieUrl); ?>">Cookie Policy</a>
                <span style="color: var(--text); opacity: 0.5;">|</span>
                <a href="<?php echo htmlspecialchars($termsUrl); ?>">Terms of Service</a>
            </div>
        </div>
    </footer>

    <!-- Cookie Consent Banner -->
    <div id="cookie-consent-banner" class="cookie-consent-banner">
        <div class="container">
            <div class="content">
                <p>
                    We use cookies to enhance your experience, analyze site usage, and assist in our marketing efforts. 
                    By clicking "Accept All", you consent to our use of cookies. 
                    <a href="<?php echo htmlspecialchars($cookieUrl); ?>">Learn more</a>
                </p>
            </div>
            <div class="actions">
                <button id="cookie-accept" class="btn-accept">Accept All</button>
                <button id="cookie-reject" class="btn-reject">Reject Non-Essential</button>
                <button id="cookie-settings" class="btn-settings">Cookie Settings</button>
            </div>
        </div>
    </div>

    <!-- Cookie Settings Modal -->
    <div id="cookie-settings-modal" class="cookie-settings-modal">
        <div class="cookie-settings-content">
            <h2>Cookie Settings</h2>
            <p>Manage your cookie preferences. You can enable or disable different types of cookies below.</p>
            
            <div class="cookie-category">
                <h3>Essential Cookies</h3>
                <p>These cookies are necessary for the website to function and cannot be disabled. They are usually set in response to actions made by you, such as logging in or filling in forms.</p>
                <div class="toggle-switch">
                    <input type="checkbox" id="cookie-essential-toggle" checked disabled>
                    <label for="cookie-essential-toggle">Always Active</label>
                </div>
            </div>
            
            <div class="cookie-category">
                <h3>Analytics Cookies</h3>
                <p>These cookies help us understand how visitors interact with our website by collecting and reporting information anonymously.</p>
                <div class="toggle-switch">
                    <input type="checkbox" id="cookie-analytics-toggle">
                    <label for="cookie-analytics-toggle">Enable Analytics Cookies</label>
                </div>
            </div>
            
            <div class="cookie-category">
                <h3>Functional Cookies</h3>
                <p>These cookies enable enhanced functionality and personalization, such as remembering your preferences.</p>
                <div class="toggle-switch">
                    <input type="checkbox" id="cookie-functional-toggle">
                    <label for="cookie-functional-toggle">Enable Functional Cookies</label>
                </div>
            </div>
            
            <div class="modal-actions">
                <button id="cookie-cancel-settings" class="btn-cancel">Cancel</button>
                <button id="cookie-save-settings" class="btn-save">Save Preferences</button>
            </div>
        </div>
    </div>

    <script src="<?php echo htmlspecialchars($assetBase . '/js/cookie-consent.js'); ?>"></script>
    <script src="<?php echo htmlspecialchars($assetBase . '/js/app.js'); ?>"></script>
    <script>
        // Theme toggle functionality (only when theme toggle is present)
        const themeToggle = document.getElementById('themeToggle');
        if (themeToggle) {
            themeToggle.addEventListener('click', () => {
                document.body.classList.toggle('pink');
                themeToggle.textContent = document.body.classList.contains('pink') ? '🌙' : '🎨';
                themeToggle.style.transform = 'rotate(360deg)';
                setTimeout(() => {
                    themeToggle.style.transform = 'rotate(0deg)';
                }, 300);
            });
        }
    </script>
</body>
</html>
