<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo \CRM\Security::getCsrfToken(); ?>">
    <title><?php echo $pageTitle ?? ('Login - ' . htmlspecialchars(brandProductName())); ?></title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?php echo htmlspecialchars(function_exists('assetUrl') ? assetUrl('images/clarity-logo-64.png') : ((function_exists('getBasePath') ? rtrim(getBasePath(), '/') : '') . '/assets/images/clarity-logo-64.png')); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo htmlspecialchars(function_exists('assetUrl') ? assetUrl('images/clarity-logo-64.png') : ((function_exists('getBasePath') ? rtrim(getBasePath(), '/') : '') . '/assets/images/clarity-logo-64.png')); ?>">
    <?php if (!empty($authBackgroundDesktopUrl)): ?>
        <link rel="preload" as="image" href="<?php echo htmlspecialchars($authBackgroundDesktopUrl); ?>" media="(min-width: 641px)" fetchpriority="high">
    <?php endif; ?>
    <?php if (!empty($authBackgroundMobileUrl)): ?>
        <link rel="preload" as="image" href="<?php echo htmlspecialchars($authBackgroundMobileUrl); ?>" media="(max-width: 640px)" fetchpriority="high">
    <?php endif; ?>
    <script>
        (function () {
            function loadFontAwesome() {
                if (document.querySelector('link[data-font-awesome="true"]')) {
                    return;
                }
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css';
                link.dataset.fontAwesome = 'true';
                document.head.appendChild(link);
            }
            if (document.readyState === 'complete') {
                window.setTimeout(loadFontAwesome, 0);
            } else {
                window.addEventListener('load', loadFontAwesome, { once: true });
            }
        }());
    </script>
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <style>
        :root {
            --bg: #e0e5ec;
            --input: #ecf0f3;
            --shadow-dark: #a3b1c6;
            --shadow-light: #ffffff;
            --accent: #9db2bf;
            --text: #555;
            --auth-bg-fallback: #e8edf3;
            <?php if (!empty($authBackgroundPreviewUrl)): ?>
            --auth-bg-image: url('<?php echo htmlspecialchars($authBackgroundPreviewUrl); ?>');
            <?php endif; ?>
        }

        body.pink {
            --bg: #f5e9ec;
            --input: #fdf6f9;
            --shadow-dark: #d4b9c3;
            --shadow-light: #fff;
            --accent: #e38cad;
            --text: #444;
        }

        body {
            margin: 0;
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.4s ease;
        }
    </style>
    <?php if (!empty($authBackgroundDesktopUrl) && !empty($authBackgroundMobileUrl)): ?>
        <script>
            (function () {
                var isCompact = window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
                var background = new Image();
                background.decoding = 'async';
                background.src = isCompact
                    ? <?php echo json_encode($authBackgroundMobileUrl); ?>
                    : <?php echo json_encode($authBackgroundDesktopUrl); ?>;
                background.onload = function () {
                    document.documentElement.style.setProperty('--auth-bg-image', 'url("' + background.src + '")');
                };
            }());
        </script>
    <?php endif; ?>
</head>
<body>
    <div style="width: 100%; display: flex; align-items: center; justify-content: center; padding: 20px;">
        <?php echo $content ?? ''; ?>
    </div>
    <script src="<?php echo htmlspecialchars(function_exists('assetUrl') ? assetUrl('js/app.js') : 'assets/js/app.js'); ?>"></script>
</body>
</html>
