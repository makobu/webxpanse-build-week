<?php
/**
 * Entrance Page
 *
 * Immersive entrance introducing Clarity, the intelligence layer of webXpanse.
 * Redirects to login on "Enter".
 */

require_once __DIR__ . '/vendor/autoload.php';

$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/config/constants.php';

try {
    \CRM\Database::init(require __DIR__ . '/config/database.php');
} catch (\Throwable $e) {
    // Keep the public entrance available even if settings storage is unreachable.
}

// Detect base path dynamically (same logic as index.php)
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$httpHost = $_SERVER['HTTP_HOST'] ?? '';
$isLocalhost = strpos($httpHost, 'localhost') !== false
    || strpos($httpHost, '127.0.0.1') !== false
    || strpos($httpHost, '::1') !== false;

if ($isLocalhost) {
    $basePath = '/crm';
} else {
    $basePath = strpos($scriptName, '/crm/') !== false ? '/crm' : '';
}

// Skip entrance for returning users
if (isset($_GET['skip']) && $_GET['skip'] === '1') {
    header('Location: ' . $basePath . '/public/login.php');
    exit;
}

$loginUrl = $basePath . '/public/login.php';
$signupUrl = $basePath . '/public/signup.php';
$landingInquiryFormUuid = '9c648567-448e-4a7e-a951-3c3fc9a0d4e6';
$landingInquiryFormUrl = $basePath . '/public/form.php?uuid=' . rawurlencode($landingInquiryFormUuid) . '&embed=1';
$privacyUrl = $basePath . '/public/privacy-policy.php';
$termsUrl = $basePath . '/public/terms-of-service.php';
$donateUrl = $basePath . '/public/donate.php';
$donationsEnabled = \CRM\Modules\WorkspaceBillingSettings::donationsEnabled();
$entranceLogoUrl = 'public/assets/images/clarity-entrance-logo-web.png';
$clarityProductName = 'Clarity';
$parentBrandName = 'webXpanse';
$parentBrandUrl = 'https://webxpanse.com/';
$pageTitle = $clarityProductName . ' by ' . $parentBrandName . ' | AI-native Business Operating System';
$seoDescription = 'Clarity is an AI-native business operating system for founders, lean teams, and one-person companies—connecting customer context, work, and controlled automation.';

$configuredAppUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
$configuredScheme = strtolower((string) parse_url($configuredAppUrl, PHP_URL_SCHEME));
if ($configuredAppUrl !== '' && in_array($configuredScheme, ['http', 'https'], true)) {
    $siteRootUrl = $configuredAppUrl;
} else {
    $requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $safeHost = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $httpHost) ?: 'localhost';
    $siteRootUrl = $requestScheme . '://' . $safeHost . $basePath;
}

$canonicalUrl = rtrim($siteRootUrl, '/') . '/';
$absoluteAssetUrl = static function (string $path) use ($siteRootUrl): string {
    return rtrim($siteRootUrl, '/') . '/' . ltrim($path, '/');
};
$ogImageUrl = $absoluteAssetUrl('public/assets/images/clarity-business-os-og.png');
$demoVideoPath = 'public/assets/videos/clarity-demo.mp4';
$demoPosterPath = 'public/assets/videos/clarity-demo-poster-landscape.webp';
$demoVideoMime = 'video/mp4';
$demoVideoUploadDate = '2026-03-04';
$demoVideoUsesUploadedAsset = false;

try {
    $entranceVideo = (new \CRM\Services\MarketplacePageExplainerService())->getActive(
        \CRM\Services\MarketplacePageExplainerService::PAGE_ENTRANCE
    );
    $configuredVideoPath = trim(str_replace('\\', '/', (string) ($entranceVideo['video_url'] ?? '')));
    $configuredVideoPath = ltrim($configuredVideoPath, '/');
    $isManagedUpload = $configuredVideoPath !== ''
        && !str_contains($configuredVideoPath, '..')
        && str_starts_with($configuredVideoPath, 'uploads/marketplace/page_video_library/')
        && is_file(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $configuredVideoPath));

    if ($isManagedUpload) {
        $demoVideoPath = $configuredVideoPath;
        $configuredMime = strtolower(trim((string) ($entranceVideo['video_asset_mime_type'] ?? '')));
        $mimeByExtension = [
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'm4v' => 'video/x-m4v',
        ];
        $extensionMime = $mimeByExtension[strtolower(pathinfo($configuredVideoPath, PATHINFO_EXTENSION))] ?? 'video/mp4';
        $demoVideoMime = in_array($configuredMime, array_values($mimeByExtension), true)
            ? $configuredMime
            : $extensionMime;
        $configuredUploadDate = substr((string) ($entranceVideo['video_asset_created_at'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $configuredUploadDate) === 1) {
            $demoVideoUploadDate = $configuredUploadDate;
        }
        $demoVideoUsesUploadedAsset = true;
    }
} catch (\Throwable $e) {
    // The bundled explainer remains available if video settings cannot be read.
}

$demoVideoUrl = $absoluteAssetUrl($demoVideoPath);
$demoPosterUrl = $absoluteAssetUrl($demoPosterPath);
$demoVideoAssetUrl = $basePath . '/' . $demoVideoPath;
$demoPosterAssetUrl = $basePath . '/' . $demoPosterPath;

$demoVideoStructuredData = [
    '@type' => 'VideoObject',
    '@id' => $canonicalUrl . '#demo-video',
    'name' => 'Clarity by webXpanse product explainer',
    'description' => 'A short product explainer introducing how the webXpanse intelligence layer connects customer conversations, context, ownership, follow-up, and pipeline movement.',
    'thumbnailUrl' => [$demoPosterUrl],
    'contentUrl' => $demoVideoUrl,
    'uploadDate' => $demoVideoUploadDate,
];
if (!$demoVideoUsesUploadedAsset) {
    $demoVideoStructuredData['duration'] = 'PT2M51S';
}

$faqItems = [
    [
        'question' => 'How do Clarity and webXpanse relate?',
        'answer' => 'webXpanse is the parent platform. Clarity is its intelligence layer: the part that learns each business, keeps customer and operating context connected, and turns that understanding into recommendations and safe, bounded action.',
    ],
    [
        'question' => 'Is Clarity a CRM or a business operating system?',
        'answer' => 'CRM is the customer-memory foundation, but Clarity is broader. It connects customer context, strategy, communication, sales, tasks, finance-ready workflows, guidance, and governed automation so the business can move from knowing to doing in one operating rhythm.',
    ],
    [
        'question' => 'How does Clarity learn the business before recommending?',
        'answer' => 'Clarity builds context from the company profile, customer evidence, offer, goals, operating rules, strategy, brand voice, CRM activity, installed capabilities, and readiness gaps. When important context is missing, the system can surface the gap instead of pretending to be certain.',
    ],
    [
        'question' => 'Does AI act without approval?',
        'answer' => 'Clarity is designed for progressive autonomy. It can begin in suggest-only or diagnostics modes, require approval for higher-risk work, and expand into bounded automation only when workspace readiness, policy, evidence, and human controls allow it.',
    ],
    [
        'question' => 'Can I use Clarity while I am still testing a business idea?',
        'answer' => 'Yes. Clarity can support customer discovery, problem definition, value proposition work, early offer and MVP thinking, go-to-market planning, and the first customer workflow. It helps structure evidence and experiments; it does not replace independent market research or guarantee demand.',
    ],
    [
        'question' => 'Does Clarity replace every tool we already use?',
        'answer' => 'No. Clarity is the shared operating context and command layer. Channels and providers such as email, WhatsApp, calendar, social, finance, and AI still depend on the workspace setup, but their customer and work context can stay connected instead of living in separate silos.',
    ],
    [
        'question' => 'Who is Clarity built for?',
        'answer' => 'Clarity is strongest for solo founders, founder-led businesses, and lean teams that need more structure without more tool chaos. Growing teams can add ownership, permissions, workflows, governance, and specialist capabilities as their operation matures.',
    ],
    [
        'question' => 'What does one-person company (OPC) mean here?',
        'answer' => 'In this context, a one-person company is a founder-led business with an exceptionally small permanent human core. A principal operator uses AI, automation, connected systems, and specialists on demand to create the capacity of a much larger organization. The model is sometimes called a one-man company or one-person business; it does not mean doing every task alone, and Clarity is not using it as a particular legal registration label.',
    ],
];

$structuredData = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'Organization',
            '@id' => $parentBrandUrl . '#organization',
            'name' => $parentBrandName,
            'url' => $parentBrandUrl,
        ],
        [
            '@type' => 'WebSite',
            '@id' => $canonicalUrl . '#website',
            'url' => $canonicalUrl,
            'name' => $parentBrandName,
            'alternateName' => $clarityProductName . ' by ' . $parentBrandName,
            'description' => $seoDescription,
            'inLanguage' => 'en',
            'publisher' => ['@id' => $parentBrandUrl . '#organization'],
        ],
        [
            '@type' => 'SoftwareApplication',
            '@id' => $canonicalUrl . '#software',
            'name' => $clarityProductName,
            'alternateName' => $clarityProductName . ' by ' . $parentBrandName,
            'url' => $canonicalUrl,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'description' => $seoDescription,
            'brand' => ['@id' => $parentBrandUrl . '#organization'],
            'provider' => ['@id' => $parentBrandUrl . '#organization'],
            'isPartOf' => ['@id' => $canonicalUrl . '#website'],
            'featureList' => [
                'Business and customer operating context',
                'Idea validation and founder guidance',
                'Connected customer memory and follow-through',
                'Brand-aware communication support',
                'AI-assisted next-action guidance',
                'Readiness-gated and human-controlled automation',
                'Tasks, sales, finance-ready workflows, and team ownership',
                'One-person company and founder-led lean-team operations',
            ],
        ],
        $demoVideoStructuredData,
        [
            '@type' => 'FAQPage',
            '@id' => $canonicalUrl . '#faq',
            'mainEntity' => array_map(static function (array $item): array {
                return [
                    '@type' => 'Question',
                    'name' => $item['question'],
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => $item['answer'],
                    ],
                ];
            }, $faqItems),
        ],
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo htmlspecialchars($seoDescription); ?>">
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
    <meta name="theme-color" content="#05080d">
    <link rel="canonical" href="<?php echo htmlspecialchars($canonicalUrl); ?>">
    <link rel="icon" type="image/png" href="<?php echo htmlspecialchars($entranceLogoUrl); ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?php echo htmlspecialchars($parentBrandName); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($seoDescription); ?>">
    <meta property="og:url" content="<?php echo htmlspecialchars($canonicalUrl); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($ogImageUrl); ?>">
    <meta property="og:image:alt" content="Clarity by webXpanse, an AI-native business operating system for founders and lean teams">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($pageTitle); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($seoDescription); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($ogImageUrl); ?>">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <script type="application/ld+json"><?php echo json_encode($structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@300;400;500&family=Inter:wght@200;300;400;500;600&family=Space+Grotesk:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --midnight-black: #0a0a0a;
            --charcoal: #1a1a1a;
            --accent-blue: #2563eb;
            --white: #ffffff;
            --glow: rgba(37, 99, 235, 0.4);
        }

        html, body {
            font-family: 'Inter', sans-serif;
            background: var(--midnight-black);
            color: var(--white);
            min-height: 100vh;
            overflow: hidden;
        }

        #canvas {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            display: block;
            pointer-events: none;
        }

        .overlay {
            position: fixed;
            inset: 0;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            padding: 2rem 1.5rem;
        }

        .overlay > * { pointer-events: auto; }

        .hero-stage {
            position: relative;
            width: min(88vw, 1220px);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .intro-kicker {
            position: absolute;
            left: 50%;
            top: clamp(-7.2rem, -10vh, -4.8rem);
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1.2rem;
            width: min(82vw, 720px);
            color: rgba(231, 241, 255, 0.68);
            font-size: clamp(0.64rem, 1vw, 0.78rem);
            font-weight: 500;
            letter-spacing: 0.52em;
            text-transform: uppercase;
            opacity: 0;
            white-space: nowrap;
        }

        .intro-kicker::before,
        .intro-kicker::after {
            content: '';
            height: 1px;
            flex: 1 1 8rem;
            max-width: 180px;
            background: linear-gradient(90deg, transparent, rgba(125, 211, 252, 0.5));
            opacity: 0.72;
        }

        .intro-kicker::after {
            background: linear-gradient(90deg, rgba(125, 211, 252, 0.5), transparent);
        }

        .intro-kicker span + span {
            color: rgba(125, 211, 252, 0.86);
            letter-spacing: 0.32em;
        }

        .headline-frame {
            position: relative;
            width: 100%;
            padding: clamp(1.3rem, 2.2vw, 2.1rem) clamp(1.1rem, 3.3vw, 3.5rem) clamp(1rem, 1.8vw, 1.6rem);
            opacity: 0;
        }

        .headline-frame::before {
            content: '';
            position: absolute;
            inset: 0;
            border: 1px solid rgba(219, 234, 254, 0.28);
            border-bottom-color: rgba(219, 234, 254, 0.08);
            box-shadow: 0 0 36px rgba(37, 99, 235, 0.08) inset;
            clip-path: polygon(0 0, 100% 0, 100% 74%, 82% 74%, 82% 100%, 18% 100%, 18% 74%, 0 74%);
            transform: scaleX(0.9);
            opacity: 0;
            animation: frameReveal 1.6s cubic-bezier(.16, 1, .3, 1) 0.9s forwards;
        }

        .headline-frame::after {
            content: 'AI AUTOMATION';
            position: absolute;
            right: clamp(1rem, 2.5vw, 2.6rem);
            top: -0.48rem;
            padding-left: 0.8rem;
            background: var(--midnight-black);
            color: rgba(191, 219, 254, 0.72);
            font-size: clamp(0.58rem, 0.8vw, 0.7rem);
            font-weight: 500;
            letter-spacing: 0.42em;
            text-transform: uppercase;
            opacity: 0;
            animation: labelReveal 0.9s ease 1.65s forwards;
        }

        .logo-constellation {
            position: fixed;
            inset: 0;
            z-index: 7;
            pointer-events: none;
            overflow: visible;
        }

        .logo-constellation::before {
            content: '';
            position: absolute;
            right: -16vmin;
            top: -22vmin;
            width: min(88vw, 920px);
            aspect-ratio: 1;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(37, 99, 235, 0.28), rgba(125, 211, 252, 0.1) 38%, transparent 68%);
            filter: blur(28px);
            opacity: 0;
            animation: logoHaloReveal 2.2s ease 0.65s forwards, logoHaloDrift 9s ease-in-out 2.8s infinite;
        }

        .corner-logo {
            position: absolute;
            right: max(-24vw, -360px);
            top: max(-26vh, -260px);
            width: clamp(520px, 62vw, 1120px);
            max-width: none;
            opacity: 0;
            mix-blend-mode: screen;
            filter: drop-shadow(0 0 42px rgba(37, 99, 235, 0.5)) saturate(1.16);
            transform: translate3d(44px, -18px, 0) rotate(-13deg) scale(0.94);
            animation: logoReveal 1.9s cubic-bezier(.16, 1, .3, 1) 0.45s forwards, logoFloat 8s ease-in-out 2.4s infinite;
        }

        .brand-sigil {
            position: fixed;
            left: 50%;
            top: clamp(1.35rem, 4vh, 2.4rem);
            z-index: 12;
            width: clamp(54px, 6.2vw, 82px);
            height: clamp(54px, 6.2vw, 82px);
            transform: translateX(-50%);
            display: grid;
            place-items: center;
            opacity: 0;
            pointer-events: none;
            animation: sigilReveal 1s ease 1.15s forwards;
        }

        .brand-sigil::before {
            content: '';
            position: absolute;
            inset: -18%;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(125, 211, 252, 0.28), transparent 64%);
            filter: blur(10px);
            animation: sigilPulse 4.5s ease-in-out 2.2s infinite;
        }

        .brand-sigil img {
            position: relative;
            width: 100%;
            height: 100%;
            object-fit: contain;
            mix-blend-mode: screen;
            filter: drop-shadow(0 0 18px rgba(125, 211, 252, 0.58));
        }

        .della-text {
            position: relative;
            font-family: 'Barlow Condensed', 'Space Grotesk', 'Inter', sans-serif;
            font-weight: 400;
            font-size: clamp(2.8rem, 7.3vw, 7rem);
            letter-spacing: clamp(0.1em, 0.42vw, 0.25em);
            text-transform: uppercase;
            opacity: 1;
            background: linear-gradient(96deg, #ffffff 0%, #dbeafe 28%, #7dd3fc 48%, #ffffff 68%, #b7c8ff 100%);
            background-size: 220% auto;
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            text-shadow: 0 0 42px rgba(37, 99, 235, 0.28);
            text-align: center;
            line-height: 0.9;
            max-width: min(94vw, 1400px);
            white-space: normal;
            margin: 0 auto;
            animation: headlineGlow 8s ease-in-out infinite, headlineShimmer 9s ease-in-out infinite;
        }

        .della-text::after {
            content: '';
            position: absolute;
            left: 50%;
            bottom: -0.75rem;
            width: min(58vw, 620px);
            height: 1px;
            transform: translateX(-50%) scaleX(0);
            transform-origin: center;
            background: linear-gradient(90deg, transparent, rgba(125, 211, 252, 0.18), rgba(255,255,255,0.72), rgba(37, 99, 235, 0.32), transparent);
            box-shadow: 0 0 24px rgba(37, 99, 235, 0.45);
            animation: underlineBreathe 5.4s ease-in-out 1.4s infinite;
        }

        .headline-accent {
            position: relative;
            display: inline-block;
            background: linear-gradient(96deg, #ffffff 0%, #bfdbfe 32%, #60a5fa 54%, #ffffff 78%);
            background-size: 180% auto;
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            -webkit-text-fill-color: transparent;
            animation: headlineShimmer 7s ease-in-out infinite;
        }

        .headline-accent::before {
            content: '';
            position: absolute;
            inset: -0.12em -0.08em;
            z-index: -1;
            border-radius: 999px;
            background: radial-gradient(circle at 50% 50%, rgba(37, 99, 235, 0.24), transparent 68%);
            filter: blur(10px);
            opacity: 0.85;
            animation: accentPulse 4.8s ease-in-out infinite;
        }

        .tagline {
            position: relative;
            font-weight: 300;
            font-size: clamp(0.95rem, 2vw, 1.24rem);
            letter-spacing: clamp(0.03em, 0.14vw, 0.08em);
            margin-top: clamp(1.25rem, 2.2vw, 1.9rem);
            opacity: 0;
            text-align: center;
            max-width: min(88vw, 780px);
            color: rgba(255,255,255,0.8);
            line-height: 1.6;
        }

        .tagline strong {
            font-weight: 500;
            color: rgba(255,255,255,0.98);
            text-shadow: 0 0 22px rgba(37, 99, 235, 0.34);
        }

        .tagline .quiet-charge {
            color: rgba(191, 219, 254, 0.96);
        }

        .trust-line {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.65rem;
            margin-top: 1.35rem;
            opacity: 0;
            color: rgba(255,255,255,0.54);
            font-size: clamp(0.68rem, 1.25vw, 0.78rem);
            font-weight: 400;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            text-align: center;
        }

        .trust-line span {
            position: relative;
            padding: 0 0.15rem;
        }

        .trust-line span + span::before {
            content: '';
            position: absolute;
            left: -0.43rem;
            top: 50%;
            width: 3px;
            height: 3px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.9);
            box-shadow: 0 0 14px var(--glow);
        }

        @keyframes headlineShimmer {
            0%, 18% { background-position: 0% center; }
            54%, 100% { background-position: 100% center; }
        }

        @keyframes headlineGlow {
            0%, 100% { filter: drop-shadow(0 0 0 rgba(37, 99, 235, 0)); }
            45% { filter: drop-shadow(0 0 18px rgba(37, 99, 235, 0.36)); }
        }

        @keyframes underlineBreathe {
            0%, 22% { opacity: 0; transform: translateX(-50%) scaleX(0.12); }
            45%, 72% { opacity: 1; transform: translateX(-50%) scaleX(1); }
            100% { opacity: 0; transform: translateX(-50%) scaleX(0.28); }
        }

        @keyframes accentPulse {
            0%, 100% { opacity: 0.55; transform: scale(0.94); }
            50% { opacity: 1; transform: scale(1.04); }
        }

        @keyframes logoReveal {
            0% { opacity: 0; transform: translate3d(72px, -48px, 0) rotate(-18deg) scale(0.86); }
            100% { opacity: 0.38; transform: translate3d(0, 0, 0) rotate(-13deg) scale(1); }
        }

        @keyframes logoFloat {
            0%, 100% { transform: translate3d(0, 0, 0) rotate(-13deg) scale(1); opacity: 0.38; }
            50% { transform: translate3d(-18px, 14px, 0) rotate(-10deg) scale(1.025); opacity: 0.5; }
        }

        @keyframes logoHaloReveal {
            0% { opacity: 0; transform: scale(0.78); }
            100% { opacity: 1; transform: scale(1); }
        }

        @keyframes logoHaloDrift {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50% { transform: translate3d(-2vw, 2vh, 0) scale(1.05); }
        }

        @keyframes sigilReveal {
            from { opacity: 0; transform: translateX(-50%) translateY(-10px) scale(0.85); }
            to { opacity: 0.9; transform: translateX(-50%) translateY(0) scale(1); }
        }

        @keyframes sigilPulse {
            0%, 100% { opacity: 0.42; transform: scale(0.92); }
            50% { opacity: 0.95; transform: scale(1.12); }
        }

        @keyframes frameReveal {
            from { opacity: 0; transform: scaleX(0.86); }
            to { opacity: 1; transform: scaleX(1); }
        }

        @keyframes labelReveal {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .enter-btn {
            margin-top: 2.25rem;
            padding: 1rem 2.75rem;
            font-family: inherit;
            font-size: 0.9rem;
            font-weight: 500;
            letter-spacing: 0.28em;
            text-transform: uppercase;
            background:
                linear-gradient(145deg, rgba(226, 236, 248, 0.14), rgba(28, 44, 68, 0.52)),
                rgba(12, 18, 28, 0.72);
            color: rgba(245, 250, 255, 0.92);
            border: 1px solid rgba(219, 234, 254, 0.22);
            border-radius: 14px;
            cursor: pointer;
            opacity: 0;
            transition: transform 0.28s ease, box-shadow 0.28s ease, border-color 0.28s ease, background 0.28s ease, letter-spacing 0.28s ease;
            position: relative;
            overflow: visible;
            min-width: 168px;
            backdrop-filter: blur(14px);
            box-shadow:
                10px 10px 24px rgba(0, 0, 0, 0.52),
                -8px -8px 18px rgba(125, 211, 252, 0.08),
                inset 1px 1px 0 rgba(255, 255, 255, 0.22),
                inset -1px -1px 0 rgba(0, 0, 0, 0.42);
        }

        .enter-btn::before {
            content: '';
            position: absolute;
            inset: 1px;
            border-radius: 12px;
            background:
                radial-gradient(circle at 30% 18%, rgba(255, 255, 255, 0.22), transparent 30%),
                linear-gradient(90deg, transparent, rgba(125, 211, 252, 0.18), transparent);
            opacity: 0.72;
            transition: opacity 0.28s ease;
        }

        .enter-btn:hover {
            transform: translateY(-2px);
            border-color: rgba(125, 211, 252, 0.44);
            background:
                linear-gradient(145deg, rgba(238, 246, 255, 0.18), rgba(38, 64, 98, 0.58)),
                rgba(16, 26, 42, 0.78);
            box-shadow:
                14px 14px 30px rgba(0, 0, 0, 0.58),
                -10px -10px 24px rgba(125, 211, 252, 0.12),
                0 0 28px rgba(37, 99, 235, 0.22),
                inset 1px 1px 0 rgba(255, 255, 255, 0.28),
                inset -1px -1px 0 rgba(0, 0, 0, 0.48);
            letter-spacing: 0.31em;
        }

        .enter-btn:hover::before { opacity: 1; }

        .enter-btn:active {
            transform: translateY(1px) scale(0.99);
            border-color: rgba(125, 211, 252, 0.26);
            box-shadow:
                inset 7px 7px 14px rgba(0, 0, 0, 0.52),
                inset -5px -5px 12px rgba(125, 211, 252, 0.08),
                0 0 18px rgba(37, 99, 235, 0.12);
        }

        .enter-btn::after {
            content: '';
            position: absolute;
            left: 50%;
            top: -22px;
            width: 1px;
            height: 22px;
            background: linear-gradient(180deg, transparent, rgba(219, 234, 254, 0.58));
            opacity: 0.72;
        }

        .entrance-actions {
            display: inline-grid;
            justify-items: center;
            gap: 0.85rem;
            margin-top: 2.25rem;
        }

        .entrance-actions .enter-btn {
            margin-top: 0;
        }

        .donate-cta-gold {
            position: fixed;
            top: 1.5rem;
            left: 1.5rem;
            z-index: 20;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.55rem;
            min-width: 152px;
            max-width: calc(100vw - 3rem);
            padding: 0.78rem 1.25rem;
            border: 1px solid rgba(251, 191, 36, 0.48);
            border-radius: 13px;
            background: linear-gradient(145deg, rgba(254, 240, 138, 0.96), rgba(245, 158, 11, 0.96));
            color: #3f2705;
            font-family: inherit;
            font-size: 0.76rem;
            font-weight: 800;
            letter-spacing: 0.18em;
            line-height: 1;
            text-decoration: none;
            text-transform: uppercase;
            opacity: 0;
            box-shadow:
                9px 9px 20px rgba(0, 0, 0, 0.32),
                -7px -7px 16px rgba(255, 255, 255, 0.07),
                inset 0 1px 0 rgba(255, 255, 255, 0.52);
            transition: transform 0.24s ease, box-shadow 0.24s ease, border-color 0.24s ease;
        }

        .donate-cta-gold:hover,
        .donate-cta-gold:focus-visible {
            transform: translateY(-1px);
            border-color: rgba(253, 224, 71, 0.72);
            outline: 2px solid rgba(245, 158, 11, 0.28);
            outline-offset: 3px;
            box-shadow:
                12px 12px 26px rgba(0, 0, 0, 0.38),
                -8px -8px 18px rgba(255, 255, 255, 0.09),
                0 0 20px rgba(245, 158, 11, 0.2),
                inset 0 1px 0 rgba(255, 255, 255, 0.58);
        }

        .donate-cta-gold svg {
            width: 15px;
            height: 15px;
            stroke-width: 2.2;
        }

        .vignette {
            position: fixed;
            inset: 0;
            z-index: 5;
            background: radial-gradient(ellipse at center, transparent 40%, rgba(0,0,0,0.6) 100%);
            pointer-events: none;
        }

        .loading {
            position: fixed;
            inset: 0;
            z-index: 100;
            background: var(--midnight-black);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 300;
            letter-spacing: 0.2em;
            transition: opacity 0.6s ease;
        }

        .loading.hidden {
            opacity: 0;
            pointer-events: none;
        }

        .loading.tap-prompt {
            cursor: pointer;
            letter-spacing: 0.3em;
        }

        .sound-toggle {
            position: fixed;
            bottom: 1.5rem;
            left: 1.5rem;
            z-index: 20;
            width: 40px;
            height: 40px;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            background: transparent;
            color: rgba(255,255,255,0.6);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            transition: all 0.3s;
        }

        .sound-toggle:hover {
            color: rgba(255,255,255,0.9);
            border-color: rgba(255,255,255,0.5);
        }

        .sound-toggle.muted { opacity: 0.5; }

        .skip-link {
            position: fixed;
            bottom: 1.5rem;
            right: 1.5rem;
            z-index: 20;
            font-size: 0.75rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.4);
            text-decoration: none;
            transition: color 0.3s;
        }

        .skip-link:hover {
            color: rgba(255,255,255,0.8);
        }

        @media (max-width: 768px) {
            .hero-stage {
                width: min(92vw, 420px);
            }
            .intro-kicker {
                top: -5.7rem;
                gap: 0.75rem;
                letter-spacing: 0.28em;
                font-size: 0.58rem;
            }
            .intro-kicker span + span,
            .headline-frame::after {
                display: none;
            }
            .headline-frame {
                padding: 1rem 0.85rem 0.82rem;
            }
            .headline-frame::before {
                clip-path: polygon(0 0, 100% 0, 100% 78%, 76% 78%, 76% 100%, 24% 100%, 24% 78%, 0 78%);
            }
            .della-text {
                font-size: clamp(2.45rem, 14vw, 4.25rem);
                letter-spacing: 0.1em;
            }
            .tagline {
                letter-spacing: 0.04em;
                margin-top: 1.1rem;
            }
            .trust-line {
                max-width: 86vw;
                gap: 0.5rem 0.85rem;
                letter-spacing: 0.1em;
            }
            .corner-logo {
                right: -58vw;
                top: -12vh;
                width: 118vw;
                opacity: 0;
            }
            .logo-constellation::before {
                right: -44vw;
                top: -16vh;
                width: 118vw;
            }
            .brand-sigil {
                top: 1rem;
                width: 52px;
                height: 52px;
            }
            .enter-btn {
                width: min(80vw, 320px);
            }
            .entrance-actions {
                margin-top: 2.1rem;
                width: min(80vw, 320px);
            }
            .donate-cta-gold {
                top: 5rem;
                left: 1rem;
                max-width: calc(100vw - 2rem);
            }
        }

        /* Landing-page expansion: keep the cinematic hero, then open into a
           searchable, accessible product story for curious visitors. */
        html {
            scroll-behavior: smooth;
            background: #05080d;
        }

        html,
        body {
            min-height: 100%;
            overflow-x: hidden;
            overflow-y: auto;
        }

        body {
            cursor: auto;
            background: #05080d;
        }

        .skip-to-content {
            position: fixed;
            top: 0.75rem;
            left: 50%;
            z-index: 200;
            padding: 0.8rem 1.1rem;
            border: 1px solid rgba(125, 211, 252, 0.55);
            border-radius: 10px;
            background: #07111f;
            color: #f8fbff;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-decoration: none;
            text-transform: uppercase;
            transform: translate(-50%, -160%);
            transition: transform 0.2s ease;
        }

        .skip-to-content:focus {
            transform: translate(-50%, 0);
        }

        .entrance-hero {
            position: relative;
            isolation: isolate;
            min-height: 100svh;
            overflow: hidden;
            background: #05080d;
        }

        .entrance-hero #canvas,
        .entrance-hero .vignette,
        .entrance-hero .logo-constellation,
        .entrance-hero .brand-sigil,
        .entrance-hero .overlay,
        .entrance-hero .loading,
        .entrance-hero .sound-toggle {
            position: absolute;
        }

        .entrance-hero .overlay {
            min-height: 100%;
            padding-top: clamp(7rem, 13vh, 9rem);
            padding-bottom: clamp(4rem, 8vh, 6rem);
        }

        .entrance-hero .hero-stage {
            width: min(90vw, 1220px);
        }

        .entrance-hero .loading {
            display: none;
        }

        .entrance-hero .donate-cta-gold {
            position: absolute;
        }

        .entrance-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.85rem;
            width: auto;
            margin-top: 2.25rem;
        }

        .entrance-hero .headline-accent {
            display: block;
        }

        .entrance-actions .enter-btn,
        .entrance-actions .enter-secondary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 54px;
            margin: 0;
            padding: 0.95rem 1.65rem;
            border-radius: 12px;
            font-family: inherit;
            font-size: clamp(0.68rem, 1vw, 0.82rem);
            font-weight: 600;
            letter-spacing: 0.2em;
            line-height: 1.2;
            text-align: center;
            text-decoration: none;
            text-transform: uppercase;
        }

        .entrance-actions .enter-btn {
            min-width: min(78vw, 290px);
            border-color: rgba(96, 165, 250, 0.7);
            background:
                linear-gradient(145deg, rgba(59, 130, 246, 0.38), rgba(29, 78, 216, 0.2)),
                rgba(9, 24, 45, 0.9);
            box-shadow: 0 0 30px rgba(37, 99, 235, 0.2), inset 0 1px 0 rgba(255,255,255,0.2);
        }

        .entrance-actions .enter-btn::after {
            display: none;
        }

        .entrance-actions .enter-secondary {
            min-width: 142px;
            border: 1px solid rgba(125, 211, 252, 0.4);
            background: rgba(4, 10, 18, 0.7);
            color: rgba(244, 249, 255, 0.9);
            backdrop-filter: blur(12px);
            transition: transform 0.25s ease, border-color 0.25s ease, background 0.25s ease;
        }

        .entrance-actions .enter-secondary:hover,
        .entrance-actions .enter-secondary:focus-visible {
            transform: translateY(-2px);
            border-color: rgba(125, 211, 252, 0.72);
            background: rgba(13, 31, 54, 0.86);
            outline: none;
        }

        .hero-scroll-cue {
            position: absolute;
            left: 50%;
            bottom: 1.25rem;
            z-index: 12;
            display: flex;
            align-items: center;
            gap: 0.7rem;
            color: rgba(219, 234, 254, 0.5);
            font-size: 0.64rem;
            letter-spacing: 0.18em;
            text-decoration: none;
            text-transform: uppercase;
            transform: translateX(-50%);
        }

        .hero-scroll-cue::before,
        .hero-scroll-cue::after {
            content: '';
            width: clamp(2rem, 7vw, 7rem);
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(96, 165, 250, 0.65));
        }

        .hero-scroll-cue::after {
            background: linear-gradient(90deg, rgba(96, 165, 250, 0.65), transparent);
        }

        .landing-main {
            position: relative;
            z-index: 30;
            overflow: hidden;
            background:
                radial-gradient(circle at 50% -8%, rgba(37, 99, 235, 0.13), transparent 26rem),
                #05080d;
            color: #f5f9ff;
        }

        .landing-main::before {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
            opacity: 0.35;
            background-image:
                radial-gradient(circle at 16% 11%, rgba(255,255,255,0.75) 0 1px, transparent 1.5px),
                radial-gradient(circle at 72% 8%, rgba(96,165,250,0.65) 0 1px, transparent 1.5px),
                radial-gradient(circle at 88% 37%, rgba(255,255,255,0.55) 0 1px, transparent 1.5px),
                radial-gradient(circle at 30% 62%, rgba(96,165,250,0.5) 0 1px, transparent 1.5px);
            background-size: 420px 380px, 520px 460px, 610px 520px, 680px 590px;
        }

        .landing-section,
        .landing-footer {
            position: relative;
            z-index: 1;
        }

        .landing-section {
            padding: clamp(4.5rem, 7vw, 6.5rem) clamp(1.25rem, 4vw, 4rem);
            border-top: 1px solid rgba(148, 163, 184, 0.12);
        }

        .landing-container {
            width: min(1400px, 100%);
            margin: 0 auto;
        }

        .landing-heading {
            margin: 0;
            font-family: 'Barlow Condensed', 'Arial Narrow', sans-serif;
            font-size: clamp(2.7rem, 6vw, 5.5rem);
            font-weight: 300;
            letter-spacing: 0.015em;
            line-height: 0.98;
            text-wrap: balance;
        }

        .landing-heading .accent,
        .landing-subheading .accent {
            color: #60a5fa;
        }

        .landing-copy {
            color: rgba(226, 232, 240, 0.72);
            font-size: clamp(1rem, 1.5vw, 1.16rem);
            font-weight: 300;
            line-height: 1.8;
        }

        .section-rule {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 2rem;
            color: #60a5fa;
            font-size: 0.7rem;
            font-weight: 600;
            letter-spacing: 0.26em;
            text-transform: uppercase;
        }

        .section-rule::before {
            content: '';
            width: 3.6rem;
            height: 1px;
            background: linear-gradient(90deg, rgba(96, 165, 250, 0.95), transparent);
        }

        .demo-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(380px, 0.9fr);
            gap: clamp(2.5rem, 3.4vw, 3.5rem);
            align-items: start;
        }

        .demo-stage {
            position: relative;
            min-height: 0;
            display: grid;
            place-items: center;
            padding: 0;
            border: 1px solid rgba(96, 165, 250, 0.25);
            border-radius: 2px;
            background:
                radial-gradient(circle at 50% 42%, rgba(37, 99, 235, 0.25), transparent 46%),
                rgba(3, 9, 17, 0.74);
            box-shadow: 0 30px 80px rgba(0, 0, 0, 0.32), inset 0 0 50px rgba(37, 99, 235, 0.05), 0 0 0 8px rgba(3, 9, 17, 0.55);
        }

        .demo-stage::before,
        .demo-stage::after {
            display: none;
        }

        .demo-video-shell {
            position: relative;
            z-index: 2;
            width: 100%;
            overflow: hidden;
            border: 1px solid rgba(147, 197, 253, 0.45);
            border-radius: 2px;
            background: #02060c;
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.58), 0 0 45px rgba(37, 99, 235, 0.17);
        }

        .demo-video-shell video {
            display: block;
            width: 100%;
            height: auto;
            aspect-ratio: 16 / 9;
            background: #02060c;
            object-fit: cover;
        }

        .demo-signal {
            position: absolute;
            z-index: 3;
            display: inline-flex;
            align-items: center;
            gap: 0.65rem;
            padding: 0.68rem 0.85rem;
            border: 1px solid rgba(96, 165, 250, 0.2);
            background: rgba(3, 10, 19, 0.88);
            color: rgba(226, 232, 240, 0.7);
            font-size: 0.72rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            backdrop-filter: blur(12px);
        }

        .demo-signal svg {
            width: 18px;
            height: 18px;
            color: #60a5fa;
        }

        .demo-signal-one { left: 1.1rem; top: 18%; }
        .demo-signal-two { left: 1.1rem; top: 43%; }
        .demo-signal-three { left: 1.1rem; bottom: 17%; }

        .demo-copy .landing-heading {
            font-size: clamp(2.85rem, 3.7vw, 4rem);
        }

        .demo-copy .landing-copy {
            max-width: 580px;
            margin-top: 1.5rem;
        }

        .demo-points {
            display: grid;
            gap: 0;
            margin-top: 1.6rem;
            border-top: 1px solid rgba(148, 163, 184, 0.18);
        }

        .demo-point {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.9rem 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.18);
            color: rgba(241, 245, 249, 0.82);
        }

        .demo-point svg,
        .audience-item svg,
        .outcome-item svg {
            flex: 0 0 auto;
            width: 24px;
            height: 24px;
            color: #60a5fa;
        }

        .demo-overview {
            grid-column: 1 / -1;
            margin-top: clamp(1rem, 2.2vw, 2rem);
            border-top: 1px solid rgba(96, 165, 250, 0.22);
            border-bottom: 1px solid rgba(96, 165, 250, 0.22);
            background: rgba(3, 10, 19, 0.32);
        }

        .demo-overview summary {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            padding: 1.25rem 1.1rem;
            color: #93c5fd;
            cursor: pointer;
            font-family: 'Barlow Condensed', sans-serif;
            font-size: clamp(1.2rem, 2vw, 1.65rem);
            font-weight: 600;
            letter-spacing: 0.08em;
        }

        .demo-overview summary::before,
        .demo-overview summary::after {
            content: '';
            width: min(24vw, 300px);
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(96, 165, 250, 0.65));
        }

        .demo-overview summary::after {
            transform: scaleX(-1);
        }

        .demo-overview p {
            max-width: 860px;
            margin: 0 auto;
            padding: 0 1.1rem 1.4rem;
            color: rgba(226, 232, 240, 0.68);
            text-align: center;
            line-height: 1.75;
        }

        .workflow-section {
            position: relative;
            padding-top: clamp(4rem, 5vw, 4.75rem);
            padding-bottom: 0;
            background: linear-gradient(180deg, rgba(4, 11, 21, 0.2), rgba(6, 15, 28, 0.72), rgba(4, 10, 18, 0.18));
        }

        .workflow-intro {
            display: block;
        }

        .workflow-intro .landing-copy {
            max-width: 880px;
            margin: 1.25rem 0 0;
        }

        .workflow-intro .landing-heading {
            font-size: clamp(3.2rem, 4vw, 4rem);
        }

        .workflow-rail {
            position: relative;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: clamp(1.5rem, 4vw, 4rem);
            margin: clamp(2.4rem, 3.2vw, 3rem) 0 0;
            padding: 0;
            list-style: none;
        }

        .workflow-rail::before {
            content: '';
            position: absolute;
            top: 35px;
            left: 4.5%;
            right: 3%;
            height: 1px;
            background: linear-gradient(90deg, transparent, #2563eb 18%, #60a5fa 50%, #2563eb 82%, transparent);
            box-shadow: 0 0 16px rgba(37, 99, 235, 0.48);
        }

        .workflow-step {
            position: relative;
            z-index: 1;
        }

        .workflow-node {
            display: grid;
            place-items: center;
            width: 70px;
            height: 70px;
            margin-bottom: 1.65rem;
            border: 1px solid rgba(147, 197, 253, 0.5);
            border-radius: 50%;
            background: #07111f;
            color: #bfdbfe;
            font-family: 'Barlow Condensed', sans-serif;
            font-size: 1.55rem;
            box-shadow: 0 0 30px rgba(37, 99, 235, 0.18);
        }

        .workflow-step h3 {
            margin: 0 0 0.85rem;
            color: #f7fbff;
            font-size: clamp(1.25rem, 2vw, 1.55rem);
            font-weight: 500;
            line-height: 1.25;
        }

        .workflow-step p {
            margin: 0;
            color: rgba(203, 213, 225, 0.65);
            line-height: 1.62;
        }

        .outcomes-band {
            position: relative;
            isolation: isolate;
            display: grid;
            grid-template-columns: minmax(0, 1.1fr) minmax(300px, 0.7fr);
            gap: clamp(3rem, 8vw, 8rem);
            align-items: center;
            margin-top: clamp(2.75rem, 3vw, 3.5rem);
            padding: clamp(3rem, 3.5vw, 3.75rem) 0;
        }

        .outcomes-band::before {
            content: '';
            position: absolute;
            z-index: -1;
            top: 0;
            bottom: 0;
            left: 50%;
            width: 100vw;
            transform: translateX(-50%);
            border-top: 1px solid rgba(96, 165, 250, 0.2);
            background:
                radial-gradient(ellipse at 18% 100%, rgba(37, 99, 235, 0.14), transparent 48%),
                radial-gradient(ellipse at 83% 100%, rgba(30, 64, 175, 0.12), transparent 50%),
                rgba(3, 10, 19, 0.55);
        }

        .outcomes-band .landing-copy {
            max-width: 670px;
            margin-top: 1rem;
        }

        .landing-subheading {
            margin: 0;
            font-family: 'Barlow Condensed', sans-serif;
            font-size: clamp(2.6rem, 5vw, 4.7rem);
            font-weight: 300;
            line-height: 1.05;
            text-wrap: balance;
        }

        .outcome-list,
        .audience-list {
            display: grid;
            margin: 0;
            padding: 0;
            list-style: none;
        }

        .outcome-item,
        .audience-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1.15rem 0;
            border-bottom: 1px solid rgba(148, 163, 184, 0.18);
            color: rgba(241, 245, 249, 0.86);
            font-size: 1.04rem;
        }

        .audience-section {
            padding-top: clamp(2.75rem, 3vw, 3.25rem);
            padding-bottom: clamp(2.75rem, 3vw, 3.25rem);
        }

        .audience-layout {
            display: grid;
            grid-template-columns: minmax(300px, 0.72fr) minmax(0, 1.55fr);
            gap: clamp(2.5rem, 4vw, 4.5rem);
            align-items: stretch;
        }

        .audience-section .landing-heading {
            font-size: clamp(2.5rem, 3vw, 3rem);
        }

        .audience-list {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            align-items: stretch;
        }

        .audience-item {
            display: grid;
            align-content: start;
            gap: 1rem;
            min-height: 120px;
            padding: 0.6rem clamp(1rem, 2vw, 1.8rem);
            border-bottom: 0;
            border-left: 1px solid rgba(148, 163, 184, 0.2);
            line-height: 1.45;
        }

        .audience-item svg {
            width: 30px;
            height: 30px;
            padding: 0.3rem;
            border: 1px solid rgba(96, 165, 250, 0.24);
        }

        .faq-section {
            padding-top: clamp(2.75rem, 3vw, 3.25rem);
            padding-bottom: clamp(2.75rem, 3vw, 3.25rem);
            background:
                radial-gradient(ellipse at 55% 100%, rgba(37, 99, 235, 0.09), transparent 55%),
                rgba(2, 7, 13, 0.48);
        }

        .faq-layout {
            display: grid;
            grid-template-columns: minmax(250px, 0.55fr) minmax(0, 1fr);
            gap: clamp(3rem, 6vw, 6rem);
            align-items: start;
        }

        .faq-section .landing-subheading {
            font-size: clamp(2.4rem, 3.2vw, 3.2rem);
        }

        .faq-list {
            border-top: 1px solid rgba(148, 163, 184, 0.2);
        }

        .faq-list details {
            border-bottom: 1px solid rgba(148, 163, 184, 0.2);
        }

        .faq-list summary {
            position: relative;
            padding: 1rem 3rem 1rem 0;
            color: rgba(248, 250, 252, 0.9);
            cursor: pointer;
            font-size: clamp(1rem, 1.5vw, 1.16rem);
            font-weight: 400;
            line-height: 1.5;
            list-style: none;
        }

        .faq-list summary::-webkit-details-marker { display: none; }

        .faq-list summary::after {
            content: '+';
            position: absolute;
            top: 50%;
            right: 0;
            color: #93c5fd;
            font-size: 1.45rem;
            font-weight: 300;
            transform: translateY(-50%);
        }

        .faq-list details[open] summary::after { content: '−'; }

        .faq-list details p {
            max-width: 760px;
            margin: -0.35rem 0 0;
            padding: 0 3rem 1.5rem 0;
            color: rgba(203, 213, 225, 0.68);
            line-height: 1.75;
        }

        .final-cta-section {
            overflow: hidden;
            padding-top: 0;
            padding-bottom: 0;
            background:
                radial-gradient(ellipse at 50% 100%, rgba(37, 99, 235, 0.24), transparent 58%),
                rgba(3, 9, 17, 0.72);
        }

        .final-cta {
            position: relative;
            overflow: hidden;
            padding: clamp(2.75rem, 3vw, 3.25rem) clamp(1.5rem, 6vw, 5rem);
            border: 0;
            background: transparent;
            text-align: center;
            box-shadow: none;
        }

        .final-cta::after {
            content: '';
            position: absolute;
            right: 8%;
            bottom: 0;
            left: 8%;
            height: 1px;
            background: #60a5fa;
            box-shadow: 0 0 14px #2563eb, 0 0 45px rgba(37, 99, 235, 0.82), 0 0 100px rgba(37, 99, 235, 0.42);
        }

        .final-cta .landing-subheading {
            max-width: 1200px;
            margin: 0 auto;
            font-size: clamp(2.7rem, 3.5vw, 3.5rem);
        }

        .final-cta .landing-copy {
            max-width: 760px;
            margin: 1.2rem auto 0;
        }

        .final-actions {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-top: 1.9rem;
        }

        .landing-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 210px;
            min-height: 54px;
            padding: 0.95rem 1.5rem;
            border: 1px solid rgba(96, 165, 250, 0.62);
            border-radius: 4px;
            background: linear-gradient(145deg, rgba(37, 99, 235, 0.72), rgba(29, 78, 216, 0.45));
            color: #f8fbff;
            font-size: 0.78rem;
            font-weight: 600;
            letter-spacing: 0.14em;
            text-decoration: none;
            text-transform: uppercase;
            box-shadow: 0 0 30px rgba(37, 99, 235, 0.22);
            transition: transform 0.22s ease, border-color 0.22s ease, background 0.22s ease;
        }

        .landing-cta.secondary {
            background: rgba(3, 9, 17, 0.72);
            border-color: rgba(148, 163, 184, 0.3);
            box-shadow: none;
        }

        .landing-cta:hover,
        .landing-cta:focus-visible {
            transform: translateY(-2px);
            border-color: rgba(147, 197, 253, 0.9);
            outline: none;
        }

        .landing-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1.5rem;
            width: min(1400px, calc(100% - 2.5rem));
            margin: 0 auto;
            padding: 2rem 0 2.5rem;
            color: rgba(148, 163, 184, 0.68);
            font-size: 0.8rem;
        }

        .landing-footer nav {
            display: flex;
            flex-wrap: wrap;
            gap: 1.4rem;
        }

        .landing-footer a {
            color: rgba(203, 213, 225, 0.7);
            text-decoration: none;
        }

        .landing-footer a:hover,
        .landing-footer a:focus-visible {
            color: #bfdbfe;
            outline: none;
        }

        .footer-brand-line {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 0.45rem 0.8rem;
        }

        .footer-brand-line .footer-product-relationship {
            color: rgba(191, 219, 254, 0.72);
        }

        :focus-visible {
            outline: 2px solid #60a5fa;
            outline-offset: 4px;
        }

        @media (min-width: 1200px) {
            .entrance-hero {
                min-height: max(720px, 92svh);
            }
        }

        @media (max-width: 920px) {
            .demo-layout,
            .workflow-intro,
            .outcomes-band,
            .audience-layout,
            .faq-layout {
                grid-template-columns: 1fr;
            }

            .workflow-rail {
                grid-template-columns: 1fr;
                gap: 2.4rem;
            }

            .workflow-rail::before {
                top: 38px;
                bottom: 38px;
                left: 38px;
                right: auto;
                width: 1px;
                height: auto;
                background: linear-gradient(180deg, transparent, #2563eb 18%, #60a5fa 50%, #2563eb 82%, transparent);
            }

            .workflow-step {
                min-height: 78px;
                padding-left: 110px;
            }

            .workflow-node {
                position: absolute;
                left: 0;
                top: 0;
                margin: 0;
            }

            .landing-footer {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 640px) {
            .entrance-hero .brand-sigil {
                display: none;
            }

            .entrance-hero .overlay {
                justify-content: center;
                padding-top: 6.5rem;
                padding-bottom: 5.2rem;
            }

            .entrance-hero .intro-kicker {
                top: -4.7rem;
            }

            .entrance-actions {
                width: min(84vw, 330px);
            }

            .entrance-actions .enter-btn,
            .entrance-actions .enter-secondary {
                width: 100%;
            }

            .entrance-hero .donate-cta-gold {
                top: 1rem;
                left: 1rem;
                width: auto;
                min-width: 0;
                padding: 0.68rem 0.85rem;
                font-size: 0.63rem;
            }

            .entrance-hero .sound-toggle {
                bottom: 1rem;
                left: 1rem;
            }

            .hero-scroll-cue { display: none; }

            .landing-section {
                padding-left: 1.15rem;
                padding-right: 1.15rem;
            }

            .demo-stage {
                min-height: 0;
                padding: 0;
            }

            .demo-video-shell {
                width: 100%;
            }

            .demo-signal {
                display: none;
            }

            .workflow-step {
                padding-left: 92px;
            }

            .workflow-node {
                width: 68px;
                height: 68px;
            }

            .workflow-rail::before {
                left: 33px;
                top: 33px;
            }

            .audience-list {
                grid-template-columns: 1fr;
            }

            .audience-item {
                grid-template-columns: auto 1fr;
                align-items: center;
                min-height: 0;
                padding: 1rem 0;
                border-left: 0;
                border-bottom: 1px solid rgba(148, 163, 184, 0.18);
            }

            .demo-overview summary::before,
            .demo-overview summary::after {
                width: min(15vw, 70px);
            }

            .final-actions,
            .landing-cta {
                width: 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after {
                animation-duration: 0.001ms !important;
                animation-iteration-count: 1 !important;
                scroll-behavior: auto !important;
                transition-duration: 0.001ms !important;
            }
        }
    </style>
    <style>
        :root {
            --clarity-bg: #03070d;
            --clarity-bg-soft: #060c14;
            --clarity-panel: rgba(7, 17, 30, 0.78);
            --clarity-line: rgba(96, 165, 250, 0.3);
            --clarity-line-strong: rgba(96, 165, 250, 0.68);
            --clarity-blue: #60a5fa;
            --clarity-blue-bright: #93c5fd;
            --clarity-cyan: #67e8f9;
            --clarity-gold: #f6c95f;
            --clarity-text: #f7fbff;
            --clarity-muted: #a8b2c1;
            --clarity-faint: #6f7c8f;
            --clarity-display: 'Barlow Condensed', sans-serif;
            --clarity-ui: 'Space Grotesk', 'Inter', sans-serif;
            --clarity-body: 'Inter', sans-serif;
            --clarity-shell: min(1500px, calc(100vw - 7rem));
        }

        body {
            background:
                radial-gradient(circle at 80% 8%, rgba(22, 78, 148, 0.12), transparent 34rem),
                var(--clarity-bg);
            color: var(--clarity-text);
        }

        .entrance-hero {
            min-height: max(760px, 100svh);
            background: var(--clarity-bg);
        }

        .entrance-hero #canvas {
            opacity: 0.3;
            filter: saturate(1.12) contrast(1.05);
        }

        .entrance-hero .vignette {
            background:
                linear-gradient(90deg, rgba(3, 7, 13, 0.5), transparent 42%, rgba(3, 7, 13, 0.16)),
                radial-gradient(circle at 72% 42%, rgba(37, 99, 235, 0.08), transparent 35%),
                linear-gradient(180deg, rgba(3, 7, 13, 0.08), rgba(3, 7, 13, 0.72));
        }

        .entrance-hero .logo-constellation,
        .entrance-hero .brand-sigil {
            display: none;
        }

        .entrance-hero .overlay {
            inset: 0;
            display: block;
            min-height: 100%;
            padding: 0 0 5rem;
        }

        .clarity-nav {
            position: relative;
            z-index: 10;
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 2rem;
            width: var(--clarity-shell);
            min-height: 86px;
            margin: 0 auto;
            border-bottom: 1px solid rgba(125, 211, 252, 0.11);
        }

        .clarity-brand,
        .clarity-nav a {
            color: var(--clarity-text);
            text-decoration: none;
        }

        .clarity-brand {
            display: inline-flex;
            align-items: center;
            gap: 0.8rem;
            font-family: var(--clarity-ui);
            font-size: 0.96rem;
            font-weight: 500;
            letter-spacing: 0.32em;
            text-transform: uppercase;
        }

        .clarity-brand img {
            width: 42px;
            height: 42px;
            object-fit: contain;
            filter: drop-shadow(0 0 16px rgba(59, 130, 246, 0.48));
        }

        .clarity-brand-lockup {
            display: grid;
            gap: 0.2rem;
            line-height: 1;
        }

        .clarity-brand-lockup strong {
            font: inherit;
        }

        .clarity-nav-links {
            display: flex;
            align-items: center;
            gap: clamp(1.4rem, 3vw, 3.4rem);
            padding-left: clamp(1rem, 4vw, 4rem);
        }

        .clarity-nav-links a,
        .clarity-signin {
            color: rgba(226, 232, 240, 0.78);
            font-family: var(--clarity-body);
            font-size: 0.9rem;
            transition: color 0.2s ease;
        }

        .clarity-nav-links a:hover,
        .clarity-nav-links a:focus-visible,
        .clarity-signin:hover,
        .clarity-signin:focus-visible {
            color: #fff;
            outline: none;
        }

        .clarity-nav-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .clarity-nav-cta,
        .hero-action,
        .landing-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 50px;
            border-radius: 12px;
            font-family: var(--clarity-ui);
            font-size: 0.88rem;
            font-weight: 500;
            letter-spacing: 0.02em;
            text-decoration: none;
        }

        .clarity-nav-cta,
        .hero-primary,
        .landing-cta:not(.secondary) {
            border: 1px solid rgba(96, 165, 250, 0.72);
            background:
                linear-gradient(145deg, rgba(37, 99, 235, 0.72), rgba(18, 65, 146, 0.72)),
                #0d2b5c;
            color: #fff;
            box-shadow: 0 0 24px rgba(37, 99, 235, 0.24), inset 0 1px 0 rgba(255, 255, 255, 0.18);
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease;
        }

        .clarity-nav-cta {
            min-height: 46px;
            padding: 0.72rem 1.15rem;
        }

        .clarity-nav-cta:hover,
        .clarity-nav-cta:focus-visible,
        .hero-primary:hover,
        .hero-primary:focus-visible,
        .landing-cta:not(.secondary):hover,
        .landing-cta:not(.secondary):focus-visible {
            transform: translateY(-2px);
            border-color: rgba(147, 197, 253, 0.96);
            box-shadow: 0 0 34px rgba(37, 99, 235, 0.35), inset 0 1px 0 rgba(255, 255, 255, 0.22);
            outline: none;
        }

        .entrance-hero .hero-stage {
            position: relative;
            z-index: 4;
            display: grid;
            grid-template-columns: minmax(0, 0.92fr) minmax(430px, 0.78fr);
            align-items: center;
            gap: clamp(2rem, 5vw, 6rem);
            width: var(--clarity-shell);
            min-height: calc(max(760px, 100svh) - 86px);
            margin: 0 auto;
            padding: clamp(3rem, 7vh, 6rem) 0 clamp(5.5rem, 9vh, 7rem);
            text-align: left;
        }

        .hero-copy {
            position: relative;
            z-index: 2;
            max-width: 760px;
        }

        .hero-parent-label {
            display: flex;
            align-items: center;
            gap: 0.7rem;
            margin: 0 0 1.35rem;
            color: rgba(191, 219, 254, 0.76);
            font-family: var(--clarity-ui);
            font-size: 0.7rem;
            font-weight: 500;
            letter-spacing: 0.18em;
        }

        .hero-parent-label::before {
            content: '';
            width: 2.8rem;
            height: 1px;
            background: linear-gradient(90deg, var(--clarity-blue), rgba(96, 165, 250, 0.08));
        }

        .hero-parent-label span {
            text-transform: uppercase;
        }

        .hero-parent-label strong {
            color: var(--clarity-text);
            font-family: var(--clarity-display);
            font-size: 0.98rem;
            font-weight: 500;
            letter-spacing: 0.05em;
        }

        .hero-copy .intro-kicker {
            display: none;
        }

        .hero-copy .headline-frame {
            width: auto;
            margin: 0;
            padding: 0;
            border: 0;
        }

        .hero-copy .headline-frame::before,
        .hero-copy .headline-frame::after {
            display: none;
        }

        .hero-copy .della-text {
            max-width: 760px;
            margin: 0;
            color: var(--clarity-text);
            font-family: var(--clarity-display);
            font-size: clamp(3.4rem, 4.95vw, 6.9rem);
            font-weight: 300;
            letter-spacing: 0.035em;
            line-height: 0.93;
            text-align: left;
            text-shadow: 0 0 26px rgba(148, 197, 255, 0.1);
            text-transform: uppercase;
        }

        .hero-copy .headline-accent {
            display: inline;
            color: var(--clarity-blue);
            -webkit-text-fill-color: currentColor;
        }

        .hero-copy .tagline {
            max-width: 690px;
            margin: 1.8rem 0 0;
            color: rgba(210, 220, 232, 0.8);
            font-family: var(--clarity-body);
            font-size: clamp(1.02rem, 1.35vw, 1.28rem);
            font-weight: 300;
            letter-spacing: 0.005em;
            line-height: 1.7;
            text-align: left;
        }

        .hero-copy .trust-line {
            justify-content: flex-start;
            max-width: 720px;
            margin: 1.6rem 0 0;
            color: rgba(185, 197, 212, 0.72);
            font-family: var(--clarity-body);
            font-size: 0.8rem;
            font-weight: 400;
            letter-spacing: 0.02em;
            text-align: left;
            text-transform: none;
        }

        .hero-copy .trust-line span::after {
            color: var(--clarity-blue);
        }

        .hero-copy .entrance-actions {
            justify-content: flex-start;
            width: auto;
            margin: 2rem 0 0;
            gap: 1rem;
        }

        .hero-action {
            min-width: 220px;
            padding: 0.95rem 1.35rem;
            cursor: pointer;
        }

        .hero-secondary,
        .landing-cta.secondary {
            border: 1px solid rgba(125, 211, 252, 0.42);
            background: rgba(3, 8, 15, 0.66);
            color: rgba(244, 249, 255, 0.92);
            box-shadow: none;
            transition: transform 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }

        .hero-secondary:hover,
        .hero-secondary:focus-visible,
        .landing-cta.secondary:hover,
        .landing-cta.secondary:focus-visible {
            transform: translateY(-2px);
            border-color: rgba(125, 211, 252, 0.76);
            background: rgba(12, 27, 47, 0.86);
            outline: none;
        }

        .hero-orbit {
            position: relative;
            width: min(38vw, 590px);
            aspect-ratio: 1;
            justify-self: end;
            border: 1px solid rgba(96, 165, 250, 0.3);
            border-radius: 50%;
            background:
                radial-gradient(circle, rgba(37, 99, 235, 0.17), transparent 30%),
                radial-gradient(circle, transparent 48%, rgba(59, 130, 246, 0.045) 49%, transparent 50%);
            box-shadow: inset 0 0 80px rgba(37, 99, 235, 0.055), 0 0 80px rgba(37, 99, 235, 0.06);
        }

        .hero-orbit::before,
        .hero-orbit::after {
            content: '';
            position: absolute;
            inset: 10%;
            border: 1px solid rgba(96, 165, 250, 0.16);
            border-radius: 50%;
        }

        .hero-orbit::after {
            inset: 24%;
            border-style: dashed;
        }

        .orbit-axis {
            position: absolute;
            inset: 50% 8% auto;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(96, 165, 250, 0.55), transparent);
        }

        .orbit-axis.vertical {
            inset: 8% auto 8% 50%;
            width: 1px;
            height: auto;
            background: linear-gradient(180deg, transparent, rgba(96, 165, 250, 0.55), transparent);
        }

        .orbit-core {
            position: absolute;
            top: 50%;
            left: 50%;
            z-index: 3;
            display: grid;
            place-items: center;
            width: 35%;
            aspect-ratio: 1;
            transform: translate(-50%, -50%);
        }

        .orbit-core::before {
            content: '';
            position: absolute;
            inset: 5%;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.36), rgba(14, 39, 82, 0.08) 58%, transparent 72%);
            filter: blur(6px);
        }

        .orbit-core img {
            position: relative;
            z-index: 2;
            width: 78%;
            height: 78%;
            object-fit: contain;
            filter: drop-shadow(0 0 28px rgba(59, 130, 246, 0.72));
            animation: clarity-float 5s ease-in-out infinite;
        }

        .orbit-node {
            position: absolute;
            z-index: 4;
            display: grid;
            place-items: center;
            width: 86px;
            aspect-ratio: 1;
            border: 1px solid rgba(96, 165, 250, 0.65);
            border-radius: 50%;
            background: rgba(5, 16, 31, 0.92);
            color: #dbeafe;
            box-shadow: 0 0 22px rgba(37, 99, 235, 0.26), inset 0 0 18px rgba(59, 130, 246, 0.12);
        }

        .orbit-node svg {
            width: 32px;
            height: 32px;
        }

        .orbit-node span {
            position: absolute;
            top: calc(100% + 0.75rem);
            width: 150px;
            color: rgba(226, 232, 240, 0.84);
            font-family: var(--clarity-ui);
            font-size: 0.68rem;
            letter-spacing: 0.16em;
            line-height: 1.4;
            text-align: center;
            text-transform: uppercase;
        }

        .orbit-node.top { top: -4%; left: 50%; transform: translateX(-50%); }
        .orbit-node.right { top: 50%; right: -4%; transform: translateY(-50%); }
        .orbit-node.bottom { bottom: 5%; left: 50%; transform: translateX(-50%); }
        .orbit-node.left { top: 50%; left: -4%; transform: translateY(-50%); }

        .hero-corner-lockup {
            position: absolute;
            right: max(2rem, calc((100vw - min(1380px, calc(100vw - 10rem))) / 2));
            bottom: 1.25rem;
            z-index: 4;
            display: grid;
            justify-items: end;
            gap: 0.55rem;
            width: min(620px, calc(100vw - 4rem));
        }

        .hero-manifesto {
            max-width: 100%;
            margin: 0;
            padding-top: 0.8rem;
            padding-left: clamp(2rem, 8vw, 7rem);
            border-top: 1px solid rgba(96, 165, 250, 0.25);
            color: rgba(184, 202, 222, 0.7);
            font-family: var(--clarity-display);
            font-size: clamp(0.9rem, 1.35vw, 1.15rem);
            font-weight: 300;
            letter-spacing: 0.28em;
            line-height: 1.35;
            text-align: right;
            text-transform: uppercase;
        }

        .webxpanse-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.34rem 0.62rem;
            border: 1px solid rgba(96, 165, 250, 0.28);
            border-radius: 999px;
            background: rgba(4, 12, 23, 0.72);
            color: rgba(219, 234, 254, 0.8);
            font-family: var(--clarity-ui);
            font-size: 0.63rem;
            font-weight: 500;
            letter-spacing: 0.12em;
            line-height: 1;
            text-transform: none;
        }

        .webxpanse-badge span {
            color: rgba(148, 163, 184, 0.66);
            font-size: 0.56rem;
        }

        .entrance-hero .hero-scroll-cue {
            display: none;
        }

        .entrance-hero .donate-cta-gold {
            display: none;
        }

        .landing-main {
            position: relative;
            background:
                radial-gradient(circle at 50% 0%, rgba(30, 90, 170, 0.08), transparent 34rem),
                var(--clarity-bg);
        }

        .landing-container,
        .clarity-section-shell {
            width: var(--clarity-shell);
            max-width: none;
            margin: 0 auto;
        }

        .landing-section,
        .clarity-section {
            position: relative;
            padding: clamp(6rem, 10vw, 10rem) 0;
            border-top: 1px solid rgba(125, 211, 252, 0.08);
            overflow: hidden;
        }

        .clarity-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            width: min(1100px, 84vw);
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(96, 165, 250, 0.26), transparent);
            transform: translateX(-50%);
        }

        .section-copy {
            max-width: 760px;
        }

        .section-heading,
        .landing-heading,
        .landing-subheading {
            color: var(--clarity-text);
            font-family: var(--clarity-display);
            font-weight: 300;
            letter-spacing: 0.025em;
            line-height: 1.02;
        }

        .section-heading {
            font-size: clamp(3.4rem, 5.2vw, 6rem);
        }

        .section-copy p,
        .landing-copy {
            color: var(--clarity-muted);
            font-family: var(--clarity-body);
            font-size: clamp(1rem, 1.25vw, 1.18rem);
            font-weight: 300;
            line-height: 1.75;
        }

        .section-copy p {
            margin-top: 1.15rem;
        }

        .section-accent,
        .accent {
            color: var(--clarity-blue);
        }

        .loop-section {
            background:
                radial-gradient(circle at 52% 55%, rgba(30, 96, 190, 0.1), transparent 28rem),
                var(--clarity-bg);
        }

        .loop-intro {
            display: flex;
            align-items: end;
            justify-content: space-between;
            gap: 3rem;
        }

        .loop-intro .section-copy {
            max-width: 690px;
        }

        .loop-side-note {
            max-width: 360px;
            color: var(--clarity-faint);
            font-size: 0.78rem;
            letter-spacing: 0.12em;
            line-height: 1.7;
            text-align: right;
            text-transform: uppercase;
        }

        .editorial-image {
            position: relative;
            overflow: hidden;
            margin: 0;
            border: 1px solid rgba(246, 201, 95, 0.2);
            border-radius: 16px;
            background: rgba(5, 13, 23, 0.72);
            box-shadow: 0 24px 68px rgba(0, 0, 0, 0.28);
        }

        .editorial-image::after {
            content: '';
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: linear-gradient(180deg, transparent 62%, rgba(3, 7, 13, 0.18));
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.025);
            pointer-events: none;
        }

        .editorial-image img {
            display: block;
            width: 100%;
            height: 100%;
            object-fit: cover;
            filter: saturate(0.96) contrast(1.02);
        }

        .editorial-wide {
            height: clamp(260px, 22vw, 340px);
        }

        .loop-editorial {
            margin-top: clamp(2.75rem, 5vw, 4.5rem);
        }

        .loop-editorial img {
            object-position: center 30%;
        }

        .loop-editorial + .loop-rail {
            margin-top: clamp(3rem, 5vw, 4.5rem);
        }

        .loop-rail {
            position: relative;
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 1.3rem;
            margin-top: clamp(4.5rem, 8vw, 7.5rem);
            padding: 0;
            list-style: none;
        }

        .loop-rail::before {
            content: '';
            position: absolute;
            top: 52px;
            right: 7%;
            left: 7%;
            height: 1px;
            background: linear-gradient(90deg, rgba(96, 165, 250, 0.2), rgba(96, 165, 250, 0.9), rgba(96, 165, 250, 0.2));
            box-shadow: 0 0 12px rgba(59, 130, 246, 0.3);
        }

        .loop-step {
            position: relative;
            z-index: 2;
            text-align: center;
        }

        .loop-node,
        .journey-icon,
        .voice-core,
        .customer-node {
            display: grid;
            place-items: center;
            width: 104px;
            aspect-ratio: 1;
            margin: 0 auto;
            border: 1px solid rgba(96, 165, 250, 0.65);
            border-radius: 50%;
            background: rgba(5, 16, 31, 0.94);
            color: #dbeafe;
            box-shadow: 0 0 25px rgba(37, 99, 235, 0.24), inset 0 0 20px rgba(59, 130, 246, 0.12);
        }

        .loop-node svg,
        .journey-icon svg,
        .voice-core svg,
        .customer-node svg {
            width: 38px;
            height: 38px;
        }

        .loop-step h3 {
            margin-top: 1.25rem;
            color: var(--clarity-blue);
            font-family: var(--clarity-ui);
            font-size: 0.86rem;
            font-weight: 500;
            letter-spacing: 0.17em;
            text-transform: uppercase;
        }

        .loop-step p {
            max-width: 235px;
            margin: 0.7rem auto 0;
            color: var(--clarity-faint);
            font-size: 0.82rem;
            line-height: 1.55;
        }

        .seed-list {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 0.45rem 0.8rem;
            margin-top: 1rem;
            color: rgba(191, 219, 254, 0.72);
            font-family: var(--clarity-ui);
            font-size: 0.66rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .human-boundary {
            margin-top: 0.9rem;
            color: var(--clarity-gold);
            font-family: var(--clarity-ui);
            font-size: 0.64rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .loop-return {
            margin-top: 3.2rem;
            padding: 1.35rem 2rem;
            border: 1px solid rgba(96, 165, 250, 0.28);
            clip-path: polygon(2% 0, 98% 0, 100% 22%, 100% 100%, 0 100%, 0 22%);
            color: rgba(220, 231, 244, 0.82);
            font-family: var(--clarity-display);
            font-size: clamp(1rem, 1.7vw, 1.45rem);
            font-weight: 300;
            letter-spacing: 0.2em;
            text-align: center;
            text-transform: uppercase;
        }

        .context-section {
            background:
                linear-gradient(90deg, rgba(4, 9, 17, 0.96), rgba(5, 13, 24, 0.92)),
                var(--clarity-bg);
        }

        .context-layout {
            display: block;
        }

        .context-layout > .section-copy {
            max-width: 820px;
        }

        .fragment-list {
            display: flex;
            flex-wrap: wrap;
            gap: 0.65rem;
            margin-top: 2rem;
        }

        .fragment-list span {
            padding: 0.55rem 0.75rem;
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 8px;
            color: rgba(191, 201, 215, 0.68);
            font-family: var(--clarity-ui);
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .context-caption {
            margin-top: 2rem;
            color: var(--clarity-faint);
            font-size: 0.86rem;
        }

        .context-map {
            position: relative;
            width: min(980px, 100%);
            min-height: 600px;
            margin: clamp(3.5rem, 7vw, 6rem) auto 0;
        }

        .context-lines {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            overflow: visible;
        }

        .context-lines line,
        .context-lines path {
            fill: none;
            stroke: rgba(96, 165, 250, 0.42);
            stroke-width: 1;
        }

        .context-core {
            position: absolute;
            top: 50%;
            left: 48%;
            z-index: 3;
            display: grid;
            place-items: center;
            width: 230px;
            aspect-ratio: 1;
            border: 1px solid rgba(96, 165, 250, 0.64);
            border-radius: 50%;
            background: radial-gradient(circle, rgba(28, 82, 168, 0.34), rgba(4, 13, 27, 0.94) 66%);
            box-shadow: 0 0 60px rgba(37, 99, 235, 0.18), inset 0 0 38px rgba(59, 130, 246, 0.16);
            transform: translate(-50%, -50%);
        }

        .context-core::before,
        .context-core::after {
            content: '';
            position: absolute;
            inset: -18px;
            border: 1px solid rgba(96, 165, 250, 0.18);
            border-radius: 50%;
        }

        .context-core::after {
            inset: -42px;
            border-style: dashed;
        }

        .context-core img {
            position: absolute;
            top: 47%;
            left: 50%;
            width: 160px;
            height: auto;
            object-fit: contain;
            filter: drop-shadow(0 0 24px rgba(59, 130, 246, 0.62));
            transform: translate(-50%, -50%);
        }

        .context-core span {
            position: absolute;
            bottom: 1.1rem;
            left: 50%;
            color: rgba(241, 245, 249, 0.88);
            font-family: var(--clarity-display);
            font-size: 1rem;
            letter-spacing: 0.16em;
            text-align: center;
            text-transform: uppercase;
            transform: translateX(-50%);
        }

        .domain-node {
            position: absolute;
            z-index: 4;
            display: block;
            width: 82px;
            aspect-ratio: 1;
            padding: 4px;
            border: 1px solid rgba(147, 197, 253, 0.82);
            border-radius: 50%;
            background: linear-gradient(145deg, rgba(219, 234, 254, 0.98), rgba(37, 99, 235, 0.72));
            box-shadow: 0 0 0 7px rgba(8, 24, 45, 0.9), 0 12px 28px rgba(0, 0, 0, 0.34), 0 0 28px rgba(37, 99, 235, 0.3);
        }

        .domain-node img {
            display: block;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            filter: saturate(1.08) contrast(1.03);
        }

        .domain-customer img { object-position: 66% center; }
        .domain-strategy img { object-position: 50% 52%; }
        .domain-communication img { object-position: 54% center; }
        .domain-sales img { object-position: center; }
        .domain-tasks img { object-position: center; }
        .domain-finance img { object-position: 76% 75%; }
        .domain-workflows img { object-position: center; }

        .domain-node span {
            position: absolute;
            top: calc(100% + 0.5rem);
            width: 140px;
            color: rgba(218, 228, 240, 0.78);
            font-family: var(--clarity-ui);
            font-size: 0.64rem;
            letter-spacing: 0.11em;
            text-align: center;
            text-transform: uppercase;
        }

        .domain-customer { top: 0; left: 48%; transform: translateX(-50%); }
        .domain-strategy { top: 15%; left: 12%; }
        .domain-communication { top: 15%; right: 8%; }
        .domain-sales { top: 48%; left: 0; }
        .domain-tasks { top: 48%; right: 0; }
        .domain-finance { bottom: 5%; left: 14%; }
        .domain-workflows { right: 10%; bottom: 5%; }

        .next-move {
            position: absolute;
            right: 0;
            bottom: 24%;
            color: var(--clarity-blue);
            font-family: var(--clarity-display);
            font-size: 1.15rem;
            letter-spacing: 0.16em;
            line-height: 1.25;
            text-align: center;
            text-transform: uppercase;
        }

        .demo-section,
        #demo-video {
            scroll-margin-top: 1rem;
        }

        .demo-section {
            background:
                radial-gradient(circle at 28% 48%, rgba(21, 75, 155, 0.12), transparent 28rem),
                #040a12;
        }

        .demo-layout {
            grid-template-columns: minmax(0, 1fr);
            align-items: start;
            gap: clamp(2.5rem, 5vw, 4.5rem);
        }

        .demo-copy {
            max-width: 1120px;
            margin: 0 auto;
            text-align: center;
        }

        .demo-copy .section-rule {
            justify-content: center;
        }

        .demo-copy .section-rule,
        .faq-section .section-rule {
            color: var(--clarity-blue);
        }

        .demo-copy .landing-heading {
            max-width: 1080px;
            margin-inline: auto;
            font-size: clamp(3.2rem, 4.8vw, 5.5rem);
        }

        .demo-copy .landing-copy {
            max-width: 820px;
            margin-inline: auto;
        }

        .demo-copy .demo-points {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            max-width: 1180px;
            margin-inline: auto;
        }

        .demo-copy .demo-point {
            justify-content: center;
            padding-inline: clamp(0.7rem, 1.8vw, 1.5rem);
            text-align: left;
        }

        .demo-stage {
            width: min(1280px, 100%);
            margin: 0 auto;
            padding: 4.75rem 1rem 1rem;
        }

        .demo-signal-one {
            top: 1.15rem;
            left: 1.15rem;
        }

        .demo-signal-two {
            top: 1.15rem;
            left: 50%;
            transform: translateX(-50%);
        }

        .demo-signal-three {
            top: 1.15rem;
            right: 1.15rem;
            bottom: auto;
            left: auto;
        }

        .demo-video-shell {
            width: 100%;
            border-color: rgba(96, 165, 250, 0.42);
            background: rgba(3, 8, 15, 0.8);
            box-shadow: 0 0 44px rgba(37, 99, 235, 0.12);
        }

        .demo-video-shell video {
            object-fit: cover;
            object-position: center;
        }

        .demo-play-gate {
            position: absolute;
            z-index: 4;
            top: 70%;
            left: 50%;
            display: grid;
            grid-template-columns: auto 1fr;
            grid-template-rows: auto auto;
            column-gap: 0.85rem;
            align-items: center;
            min-width: 190px;
            padding: 0.9rem 1.15rem;
            border: 1px solid rgba(254, 240, 138, 0.92);
            border-radius: 999px;
            background: linear-gradient(135deg, #fde68a 0%, #fbbf24 48%, #f97316 100%);
            color: #2b1903;
            font-family: var(--clarity-ui);
            text-align: left;
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.5), 0 0 30px rgba(245, 158, 11, 0.28);
            transform: translate(-50%, -50%);
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
        }

        .demo-play-gate:hover,
        .demo-play-gate:focus-visible {
            border-color: #fff7d6;
            outline: 3px solid rgba(251, 191, 36, 0.28);
            outline-offset: 4px;
            box-shadow: 0 22px 52px rgba(0, 0, 0, 0.54), 0 0 34px rgba(245, 158, 11, 0.38);
            transform: translate(-50%, -52%) scale(1.02);
            filter: saturate(1.08) brightness(1.03);
        }

        .demo-play-gate[hidden] { display: none; }

        .demo-play-gate svg {
            grid-row: 1 / -1;
            width: 34px;
            height: 34px;
            padding: 0.45rem;
            border: 1px solid rgba(63, 39, 5, 0.24);
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            color: #3f2705;
        }

        .demo-play-gate strong {
            font-size: 0.78rem;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .demo-play-gate small {
            margin-top: 0.15rem;
            color: rgba(63, 39, 5, 0.72);
            font-size: 0.68rem;
            letter-spacing: 0.06em;
        }

        @media (max-width: 640px) {
            .demo-play-gate {
                top: 76%;
                min-width: 174px;
                padding: 0.72rem 0.95rem;
            }

            .demo-video-shell .video-brand-overlay-logo {
                width: 4.5rem;
            }
        }

        .customer-section {
            background:
                radial-gradient(circle at 72% 50%, rgba(30, 91, 184, 0.1), transparent 30rem),
                var(--clarity-bg);
        }

        .customer-heading {
            max-width: 1020px;
            margin: 0 auto;
            text-align: center;
        }

        .customer-heading .section-copy {
            max-width: 820px;
            margin: 0 auto;
        }

        .customer-editorial {
            margin-top: clamp(3rem, 5.5vw, 5rem);
        }

        .customer-editorial img {
            object-position: center 30%;
        }

        .customer-editorial + .customer-system {
            margin-top: clamp(2.75rem, 4vw, 4rem);
        }

        .customer-system {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(340px, 0.75fr);
            gap: clamp(3rem, 6vw, 6.5rem);
            margin-top: clamp(4rem, 7vw, 6.5rem);
        }

        .customer-flow {
            position: relative;
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            padding: 0;
            list-style: none;
        }

        .customer-flow::before {
            content: '';
            position: absolute;
            top: 45px;
            right: 7%;
            left: 7%;
            height: 1px;
            background: linear-gradient(90deg, rgba(96, 165, 250, 0.2), rgba(96, 165, 250, 0.88), rgba(96, 165, 250, 0.2));
        }

        .customer-step {
            position: relative;
            z-index: 2;
        }

        .customer-node {
            width: 90px;
            margin: 0;
        }

        .customer-step h3 {
            margin-top: 1rem;
            color: var(--clarity-blue);
            font-family: var(--clarity-ui);
            font-size: 0.68rem;
            letter-spacing: 0.13em;
            text-transform: uppercase;
        }

        .customer-step p {
            margin-top: 0.65rem;
            color: var(--clarity-faint);
            font-size: 0.79rem;
            line-height: 1.55;
        }

        .customer-example {
            position: relative;
            min-height: 112px;
            margin-top: 1rem;
            padding: 1rem 1.05rem;
            border: 1px solid rgba(147, 197, 253, 0.74);
            border-radius: 18px 18px 18px 5px;
            background: linear-gradient(145deg, rgba(248, 251, 255, 0.98), rgba(219, 234, 254, 0.96));
            color: #10233e;
            font-size: 0.76rem;
            font-weight: 500;
            line-height: 1.55;
            box-shadow: 0 14px 32px rgba(0, 0, 0, 0.24), inset 0 1px 0 rgba(255, 255, 255, 0.82);
        }

        .customer-example::after {
            content: '';
            position: absolute;
            bottom: -7px;
            left: -1px;
            width: 18px;
            height: 18px;
            background: #dbeafe;
            clip-path: polygon(0 0, 100% 0, 0 100%);
        }

        .customer-example--outgoing {
            border-radius: 18px 18px 5px 18px;
            background: linear-gradient(145deg, rgba(224, 247, 255, 0.98), rgba(191, 219, 254, 0.96));
        }

        .customer-example--outgoing::after {
            right: -1px;
            left: auto;
            background: #bfdbfe;
            clip-path: polygon(0 0, 100% 0, 100% 100%);
        }

        .customer-example--system {
            display: grid;
            gap: 0.6rem;
            border: 1px solid rgba(96, 165, 250, 0.34);
            border-radius: 12px;
            background:
                radial-gradient(circle at 100% 0, rgba(37, 99, 235, 0.2), transparent 60%),
                rgba(5, 15, 28, 0.94);
            color: rgba(226, 232, 240, 0.9);
            box-shadow: inset 0 1px 0 rgba(148, 163, 184, 0.08), 0 14px 32px rgba(0, 0, 0, 0.22);
        }

        .customer-example--system::after {
            display: none;
        }

        .customer-example--system span {
            display: flex;
            gap: 0.55rem;
            align-items: center;
        }

        .customer-example--system svg {
            flex: 0 0 auto;
            width: 14px;
            height: 14px;
            color: var(--clarity-blue);
        }

        .brand-voice {
            position: relative;
            padding-left: clamp(2rem, 4vw, 4rem);
            border-left: 1px solid rgba(96, 165, 250, 0.3);
        }

        .brand-voice h3 {
            color: var(--clarity-blue);
            font-family: var(--clarity-ui);
            font-size: 0.8rem;
            letter-spacing: 0.15em;
            text-transform: uppercase;
        }

        .voice-line {
            position: relative;
            display: grid;
            grid-template-columns: 1fr auto;
            align-items: center;
            gap: 1rem;
            margin-top: 1.25rem;
            padding-bottom: 1.05rem;
            border-bottom: 1px solid rgba(148, 163, 184, 0.13);
            color: rgba(224, 232, 242, 0.82);
            font-size: 0.88rem;
        }

        .voice-line span:last-child {
            color: var(--clarity-faint);
            font-size: 0.7rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .voice-core {
            width: 112px;
            margin: 2rem auto;
        }

        .channel-row {
            display: flex;
            justify-content: center;
            gap: 0.6rem;
        }

        .channel-row span {
            padding: 0.55rem 0.65rem;
            border: 1px solid rgba(96, 165, 250, 0.24);
            border-radius: 8px;
            color: rgba(203, 213, 225, 0.72);
            font-size: 0.68rem;
        }

        .customer-manifesto {
            margin-top: 4rem;
            padding-top: 1.4rem;
            border-top: 1px solid rgba(96, 165, 250, 0.22);
            color: rgba(211, 223, 237, 0.72);
            font-family: var(--clarity-display);
            font-size: clamp(1rem, 1.55vw, 1.35rem);
            letter-spacing: 0.2em;
            text-align: center;
            text-transform: uppercase;
        }

        .growth-section {
            background:
                radial-gradient(circle at 50% 40%, rgba(24, 79, 166, 0.1), transparent 34rem),
                #040912;
        }

        .opc-positioning {
            position: relative;
            display: grid;
            grid-template-columns: minmax(0, 0.72fr) minmax(0, 1.28fr);
            gap: clamp(2rem, 5vw, 5rem);
            margin-top: 3.5rem;
            padding: clamp(1.6rem, 3vw, 2.5rem);
            overflow: hidden;
            border: 1px solid rgba(96, 165, 250, 0.3);
            border-radius: 16px;
            background:
                radial-gradient(circle at 4% 12%, rgba(37, 99, 235, 0.22), transparent 18rem),
                linear-gradient(135deg, rgba(7, 19, 36, 0.94), rgba(4, 10, 20, 0.88));
            box-shadow: inset 0 1px 0 rgba(191, 219, 254, 0.08), 0 24px 60px rgba(0, 0, 0, 0.2);
        }

        .opc-positioning::after {
            content: 'OPC';
            position: absolute;
            right: -0.08em;
            bottom: -0.3em;
            color: rgba(96, 165, 250, 0.045);
            font-family: var(--clarity-display);
            font-size: clamp(6rem, 14vw, 13rem);
            letter-spacing: 0.05em;
            line-height: 1;
            pointer-events: none;
        }

        .opc-heading,
        .opc-copy {
            position: relative;
            z-index: 1;
        }

        .opc-kicker {
            color: var(--clarity-blue);
            font-family: var(--clarity-ui);
            font-size: 0.72rem;
            letter-spacing: 0.18em;
            text-transform: uppercase;
        }

        .opc-heading h3 {
            max-width: 13ch;
            margin-top: 0.9rem;
            color: var(--clarity-text);
            font-family: var(--clarity-display);
            font-size: clamp(2.2rem, 3.5vw, 3.7rem);
            font-weight: 300;
            letter-spacing: 0.035em;
            line-height: 1.02;
        }

        .opc-copy p {
            max-width: 68ch;
            color: rgba(211, 223, 237, 0.8);
            font-size: 0.94rem;
            line-height: 1.8;
        }

        .opc-copy p + p {
            margin-top: 0.9rem;
            color: var(--clarity-faint);
        }

        .opc-signals {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-top: 1.35rem;
        }

        .opc-signals span {
            padding: 0.45rem 0.65rem;
            border: 1px solid rgba(96, 165, 250, 0.24);
            border-radius: 999px;
            color: rgba(219, 234, 254, 0.78);
            font-family: var(--clarity-ui);
            font-size: 0.62rem;
            letter-spacing: 0.09em;
            text-transform: uppercase;
        }

        .journey-rail {
            position: relative;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 3rem;
            margin-top: 4.8rem;
            padding: 0;
            list-style: none;
        }

        .journey-rail::before {
            content: '';
            position: absolute;
            top: 52px;
            right: 4%;
            left: 4%;
            display: none;
            height: 1px;
            background: linear-gradient(90deg, rgba(96, 165, 250, 0.2), rgba(96, 165, 250, 0.9), rgba(96, 165, 250, 0.2));
        }

        .journey-stage {
            position: relative;
            z-index: 2;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 1.15rem;
            align-items: start;
        }

        .journey-icon {
            width: 104px;
            margin: 0;
        }

        .journey-stage h3 {
            color: var(--clarity-blue);
            font-family: var(--clarity-display);
            font-size: 2.2rem;
            font-weight: 300;
            letter-spacing: 0.08em;
        }

        .journey-stage p {
            margin-top: 0.35rem;
            color: var(--clarity-faint);
            font-size: 0.82rem;
            line-height: 1.6;
        }

        @media (min-width: 821px) {
            .journey-stage:not(:last-child)::after {
                content: '\2192';
                position: absolute;
                top: 38px;
                right: -2rem;
                color: rgba(96, 165, 250, 0.72);
                font-family: var(--clarity-display);
                font-size: 1.15rem;
                line-height: 1;
                text-shadow: 0 0 12px rgba(59, 130, 246, 0.3);
            }
        }

        .journey-points {
            grid-column: 1 / -1;
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            margin-top: 1rem;
        }

        .journey-points span {
            color: rgba(210, 220, 232, 0.74);
            font-family: var(--clarity-ui);
            font-size: 0.67rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
        }

        .autonomy-ladder {
            margin-top: 5rem;
            padding-top: 3rem;
            border-top: 1px solid rgba(125, 211, 252, 0.12);
        }

        .autonomy-title {
            color: var(--clarity-blue);
            font-family: var(--clarity-ui);
            font-size: 0.74rem;
            letter-spacing: 0.17em;
            text-transform: uppercase;
        }

        .autonomy-track {
            display: grid;
            grid-template-columns: 1fr auto 1fr auto 1fr;
            align-items: center;
            gap: 1.4rem;
            margin-top: 1.5rem;
        }

        .autonomy-step {
            min-height: 120px;
            padding: 1.1rem 1.2rem;
            border: 1px solid rgba(96, 165, 250, 0.28);
            border-radius: 12px;
            background: rgba(5, 15, 28, 0.68);
        }

        .autonomy-step h3 {
            color: var(--clarity-text);
            font-family: var(--clarity-display);
            font-size: 1.7rem;
            font-weight: 300;
            letter-spacing: 0.04em;
        }

        .autonomy-step p {
            margin-top: 0.45rem;
            color: var(--clarity-faint);
            font-size: 0.78rem;
            line-height: 1.55;
        }

        .autonomy-gate {
            display: grid;
            place-items: center;
            width: 74px;
            aspect-ratio: 1;
            border: 1px solid rgba(246, 201, 95, 0.7);
            transform: rotate(45deg);
            color: var(--clarity-gold);
            box-shadow: 0 0 20px rgba(246, 201, 95, 0.12);
        }

        .autonomy-gate span {
            font-family: var(--clarity-ui);
            font-size: 0.56rem;
            letter-spacing: 0.05em;
            text-align: center;
            text-transform: uppercase;
            transform: rotate(-45deg);
        }

        .growth-close {
            margin-top: 4.5rem;
            padding: 2.6rem 2rem;
            border: 1px solid rgba(96, 165, 250, 0.28);
            clip-path: polygon(2.5% 0, 97.5% 0, 100% 18%, 100% 100%, 0 100%, 0 18%);
            text-align: center;
        }

        .growth-close p {
            color: var(--clarity-text);
            font-family: var(--clarity-display);
            font-size: clamp(2rem, 3vw, 3.1rem);
            font-weight: 300;
            letter-spacing: 0.08em;
        }

        .growth-close .landing-cta {
            margin-top: 1.5rem;
            padding: 0.9rem 1.5rem;
        }

        .inquiry-section {
            background:
                radial-gradient(circle at 76% 38%, rgba(37, 99, 235, 0.15), transparent 30rem),
                radial-gradient(circle at 12% 68%, rgba(14, 116, 144, 0.08), transparent 26rem),
                #030811;
        }

        .inquiry-layout {
            display: grid;
            grid-template-columns: minmax(0, 0.84fr) minmax(560px, 1.16fr);
            gap: clamp(3rem, 7vw, 7rem);
            align-items: start;
        }

        .inquiry-copy {
            max-width: 620px;
            padding-top: 1.5rem;
        }

        .inquiry-copy .landing-subheading {
            margin-top: 1.15rem;
            font-size: clamp(3.1rem, 5vw, 5.2rem);
        }

        .inquiry-copy .landing-copy {
            max-width: 570px;
        }

        .inquiry-routes {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.7rem;
            margin-top: 2rem;
        }

        .inquiry-route {
            min-height: 88px;
            padding: 1rem;
            border: 1px solid rgba(96, 165, 250, 0.2);
            border-radius: 12px;
            background: rgba(5, 15, 28, 0.62);
        }

        .inquiry-route strong {
            display: block;
            color: rgba(239, 246, 255, 0.92);
            font-family: var(--clarity-ui);
            font-size: 0.72rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .inquiry-route span {
            display: block;
            margin-top: 0.45rem;
            color: var(--clarity-faint);
            font-size: 0.75rem;
            line-height: 1.5;
        }

        .inquiry-note {
            margin-top: 1.5rem;
            padding-left: 1rem;
            border-left: 1px solid rgba(96, 165, 250, 0.48);
            color: rgba(183, 199, 218, 0.72);
            font-size: 0.76rem;
            line-height: 1.65;
        }

        .inquiry-form-shell {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(96, 165, 250, 0.3);
            border-radius: 20px;
            background: rgba(3, 9, 17, 0.82);
            box-shadow: 0 28px 80px rgba(0, 0, 0, 0.38), 0 0 48px rgba(37, 99, 235, 0.1);
        }

        .inquiry-form-frame {
            display: block;
            width: 100%;
            min-height: 420px;
            border: 0;
            background: #03070d;
        }

        .faq-section {
            background: var(--clarity-bg);
        }

        .faq-layout {
            gap: clamp(3rem, 7vw, 8rem);
        }

        .faq-list details {
            border-color: rgba(148, 163, 184, 0.16);
        }

        .faq-list summary {
            color: rgba(239, 246, 255, 0.88);
        }

        .faq-list details p {
            color: var(--clarity-muted);
        }

        .final-cta-section {
            padding-top: clamp(4rem, 7vw, 7rem);
            background:
                radial-gradient(circle at 50% 50%, rgba(27, 85, 178, 0.14), transparent 28rem),
                #040912;
        }

        .final-cta {
            max-width: 1050px;
            padding: clamp(3rem, 6vw, 5.5rem);
            border-color: rgba(96, 165, 250, 0.3);
            background: rgba(4, 12, 23, 0.74);
        }

        .final-cta .landing-subheading {
            font-size: clamp(3rem, 5vw, 5.5rem);
        }

        .final-actions .landing-cta {
            min-width: 220px;
            padding: 0.95rem 1.35rem;
        }

        .support-access-link {
            display: inline-flex;
            margin-top: 1.4rem;
            color: rgba(246, 201, 95, 0.76);
            font-size: 0.75rem;
            letter-spacing: 0.1em;
            text-decoration: none;
            text-transform: uppercase;
        }

        .landing-footer {
            width: var(--clarity-shell);
            margin: 0 auto;
            border-top-color: rgba(125, 211, 252, 0.12);
        }

        @keyframes clarity-float {
            0%, 100% { transform: translateY(0) rotate(-1deg); }
            50% { transform: translateY(-10px) rotate(1deg); }
        }

        @media (max-width: 1180px) {
            :root { --clarity-shell: min(100% - 3rem, 1120px); }

            .clarity-nav-links { display: none; }
            .clarity-nav { grid-template-columns: 1fr auto; }
            .clarity-nav-actions { justify-self: end; }

            .entrance-hero .hero-stage {
                grid-template-columns: minmax(0, 1fr) minmax(360px, 0.72fr);
                gap: 2rem;
            }

            .hero-copy .della-text { font-size: clamp(3.7rem, 7.5vw, 5.8rem); }
            .orbit-node { width: 72px; }

            .context-map { width: min(820px, 100%); margin: clamp(3rem, 8vw, 5rem) auto 0; }
            .customer-system { grid-template-columns: 1fr; }
            .inquiry-layout { grid-template-columns: 1fr; }
            .inquiry-copy { max-width: 820px; }
            .brand-voice { padding: 2.5rem 0 0; border-top: 1px solid rgba(96, 165, 250, 0.3); border-left: 0; }
            .brand-voice { display: grid; grid-template-columns: auto 1fr auto; gap: 2rem; align-items: center; }
            .brand-voice h3 { grid-column: 1 / -1; }
            .voice-core { margin: 0; }
            .channel-row { flex-wrap: wrap; }
        }

        @media (max-width: 820px) {
            :root { --clarity-shell: calc(100% - 2rem); }

            .clarity-nav {
                min-height: 72px;
                gap: 0.75rem;
            }

            .clarity-brand { font-size: 0.8rem; letter-spacing: 0.22em; }
            .clarity-brand img { width: 36px; height: 36px; }
            .clarity-signin { display: none; }
            .clarity-nav-cta { min-height: 42px; padding: 0.65rem 0.85rem; font-size: 0.76rem; }

            .entrance-hero { min-height: auto; }
            .entrance-hero .overlay { position: relative; }
            .entrance-hero .hero-stage {
                display: block;
                min-height: 0;
                padding: 3.25rem 0 6.5rem;
            }

            .hero-copy .della-text { font-size: clamp(2.75rem, 12vw, 4.75rem); }
            .hero-copy .tagline { margin-top: 1.4rem; font-size: 0.98rem; line-height: 1.62; }
            .hero-copy .entrance-actions { display: grid; grid-template-columns: 1fr; justify-content: stretch; margin-top: 1.5rem; }
            .hero-action { width: 100%; min-width: 0; }
            .hero-copy .trust-line { display: grid; gap: 0.5rem; margin-top: 1.2rem; }
            .hero-copy .trust-line span::after { display: none; }

            .hero-orbit {
                width: min(82vw, 430px);
                margin: 4rem auto 1.75rem;
            }

            .orbit-node { width: 68px; }
            .orbit-node span { width: 118px; font-size: 0.58rem; }
            .hero-corner-lockup {
                right: 1rem;
                bottom: 0.85rem;
                width: calc(100% - 2rem);
            }
            .hero-manifesto {
                max-width: 21rem;
                padding-left: 1rem;
                font-size: 0.8rem;
                line-height: 1.55;
            }

            .landing-section,
            .clarity-section { padding: 4.5rem 0; }
            .section-heading { font-size: clamp(2.85rem, 12vw, 4.5rem); }
            .loop-intro { display: block; }
            .loop-side-note { margin-top: 1.5rem; text-align: left; }

            .editorial-wide {
                height: auto;
                aspect-ratio: 16 / 10;
            }

            .loop-editorial,
            .customer-editorial {
                margin-top: 2.5rem;
            }

            .loop-editorial + .loop-rail,
            .customer-editorial + .customer-system {
                margin-top: 2.75rem;
            }

            .loop-rail {
                grid-template-columns: 1fr;
                gap: 2rem;
                margin-top: 3rem;
            }

            .loop-rail::before {
                top: 30px;
                bottom: 30px;
                left: 42px;
                width: 1px;
                height: auto;
            }

            .loop-step {
                display: grid;
                grid-template-columns: 86px 1fr;
                column-gap: 1.2rem;
                text-align: left;
            }

            .loop-node { grid-row: 1 / span 3; width: 86px; margin: 0; }
            .loop-step h3 { margin-top: 0.3rem; }
            .loop-step p { margin: 0.55rem 0 0; }
            .seed-list { justify-content: flex-start; }

            .context-map { min-height: 720px; transform: scale(0.92); transform-origin: top center; margin-bottom: -50px; }
            .context-core { left: 50%; }
            .domain-sales { left: 2%; }
            .domain-tasks { right: 2%; }
            .next-move { right: 0; bottom: 24%; }

            .customer-flow {
                grid-template-columns: 1fr 1fr;
                gap: 2rem 1rem;
            }
            .customer-flow::before { display: none; }
            .customer-example { min-height: 0; }
            .customer-system { margin-top: 3.5rem; }

            .brand-voice { display: block; }
            .voice-core { margin: 2rem auto; }

            .opc-positioning {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            .opc-heading h3 { max-width: 18ch; }

            .journey-rail {
                grid-template-columns: 1fr;
                gap: 2.5rem;
                margin-top: 3.5rem;
            }
            .journey-rail::before { display: none; }
            .autonomy-ladder { margin-top: 4rem; padding-top: 2.25rem; }
            .autonomy-track { grid-template-columns: 1fr; }
            .autonomy-gate { width: 62px; margin: 0 auto; }
            .growth-close { margin-top: 3.5rem; }
            .inquiry-copy { padding-top: 0; }
            .inquiry-form-frame { min-height: 420px; }
        }

        @media (min-width: 821px) and (max-height: 900px) {
            .entrance-hero .hero-stage {
                min-height: calc(100svh - 86px);
                padding: clamp(2rem, 5vh, 2.75rem) 0 7.5rem;
            }

            .hero-copy .della-text {
                font-size: clamp(3.4rem, 4.95vw, 5.75rem);
                line-height: 0.9;
            }

            .hero-copy .tagline {
                margin-top: 1rem;
                font-size: clamp(0.95rem, 1.1vw, 1.1rem);
                line-height: 1.55;
            }

            .hero-copy .entrance-actions {
                margin-top: 1.15rem;
            }

            .hero-copy .trust-line {
                margin-top: 0.85rem;
                font-size: 0.75rem;
            }

            .hero-orbit {
                width: min(38vw, 62vh, 540px);
            }

            .hero-corner-lockup {
                bottom: 0.65rem;
            }

            .hero-manifesto {
                padding-top: 0.65rem;
                font-size: 0.85rem;
                line-height: 1.3;
            }
        }

        @media (max-width: 920px) {
            .demo-copy .demo-points {
                grid-template-columns: 1fr;
                max-width: 680px;
            }

            .demo-copy .demo-point {
                justify-content: flex-start;
            }

            .demo-stage {
                padding: 0.55rem;
            }
        }

        @media (max-width: 640px) {
            .context-map {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1.3rem 0.8rem;
                min-height: 0;
                margin: 3rem 0 0;
                transform: none;
            }

            .context-lines {
                display: none;
            }

            .context-core {
                position: relative;
                top: auto;
                left: auto;
                grid-column: 1 / -1;
                width: 190px;
                margin: 0 auto 2rem;
                transform: none;
            }

            .domain-node {
                position: relative;
                top: auto;
                right: auto;
                bottom: auto;
                left: auto;
                width: 68px;
                margin: 0 auto 2rem;
                transform: none;
            }

            .domain-node span {
                width: 120px;
            }

            .next-move {
                position: relative;
                right: auto;
                bottom: auto;
                grid-column: 1 / -1;
                margin-top: 1rem;
            }
        }

        @media (max-width: 520px) {
            .clarity-brand span { display: none; }
            .clarity-nav-cta { padding-inline: 0.7rem; }
            .hero-parent-label {
                gap: 0.4rem;
                margin-bottom: 1.1rem;
                font-size: 0.56rem;
                letter-spacing: 0.11em;
            }
            .hero-parent-label::before { width: 1.25rem; flex: 0 0 auto; }
            .hero-parent-label span,
            .hero-parent-label strong { white-space: nowrap; }
            .hero-parent-label strong { font-size: 0.82rem; }
            .hero-orbit { margin-top: 3.5rem; }
            .orbit-node { width: 52px; }
            .orbit-node svg { width: 24px; height: 24px; }
            .orbit-node span { width: 96px; font-size: 0.5rem; }
            .orbit-node.right { right: 0; }
            .orbit-node.right span { right: 0; }
            .orbit-node.left { left: 0; }
            .orbit-node.left span { left: 0; }
            .entrance-hero .sound-toggle {
                bottom: 1rem;
                left: 1rem;
                width: 36px;
                height: 36px;
            }

            .context-map {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 1.3rem 0.8rem;
                min-height: 0;
                margin: 3rem 0 0;
                transform: none;
            }
            .context-lines { display: none; }
            .context-core {
                position: relative;
                top: auto;
                left: auto;
                grid-column: 1 / -1;
                width: 190px;
                margin: 0 auto 2rem;
                transform: none;
            }
            .domain-node {
                position: relative;
                top: auto;
                right: auto;
                bottom: auto;
                left: auto;
                width: 68px;
                margin: 0 auto 2rem;
                transform: none;
            }
            .domain-node span { width: 120px; }
            .next-move {
                position: relative;
                right: auto;
                bottom: auto;
                grid-column: 1 / -1;
                margin-top: 1rem;
            }

            .customer-flow { grid-template-columns: 1fr; }
            .customer-node { width: 78px; }
            .opc-positioning { padding: 1.35rem; }
            .opc-heading h3 { font-size: clamp(2.1rem, 11vw, 3.15rem); }
            .journey-stage { grid-template-columns: 80px 1fr; }
            .journey-icon { width: 80px; }
            .growth-close { padding-inline: 1rem; }
            .inquiry-routes { grid-template-columns: 1fr; }
            .inquiry-form-frame { min-height: 420px; }
            .final-cta { padding: 2.5rem 1.2rem; }
            .final-actions { display: grid; }
            .final-actions .landing-cta { width: 100%; min-width: 0; }
        }

        @media (prefers-reduced-motion: reduce) {
            .orbit-core img { animation: none; }
        }
    </style>
    <?php echo \CRM\Services\VideoBrandOverlayUi::assets(); ?>
</head>
<body>
    <a href="#landing-main" class="skip-to-content">Skip to product overview</a>

    <section class="entrance-hero" id="hero" aria-labelledby="della">
        <div class="loading hidden" id="loading" aria-hidden="true"></div>
        <canvas id="canvas" aria-hidden="true"></canvas>
        <div class="vignette" aria-hidden="true"></div>
        <div class="overlay">
            <header class="clarity-nav" aria-label="Primary navigation">
                <a class="clarity-brand" href="#hero" aria-label="Clarity by webXpanse home">
                    <img src="<?php echo htmlspecialchars($entranceLogoUrl); ?>" alt="">
                    <span class="clarity-brand-lockup">
                        <strong>Clarity</strong>
                    </span>
                </a>
                <nav class="clarity-nav-links" aria-label="Landing page sections">
                    <a href="#cofounder-loop">How it works</a>
                    <a href="#demo">Explainer</a>
                    <a href="#growth">Safety</a>
                    <a href="#customer-intelligence">For founders</a>
                    <a href="#inquiry">Talk to us</a>
                </nav>
                <div class="clarity-nav-actions">
                    <a class="clarity-signin" href="<?php echo htmlspecialchars($loginUrl); ?>">Sign in</a>
                    <a class="clarity-nav-cta" href="<?php echo htmlspecialchars($signupUrl); ?>">Start with your business</a>
                </div>
            </header>
            <div class="hero-stage">
                <div class="hero-copy">
                    <p class="hero-parent-label"><span>The intelligence layer of</span> <strong>webXpanse</strong></p>
                    <div class="intro-kicker" id="introKicker" aria-hidden="true"></div>
                    <div class="headline-frame" id="headlineFrame">
                        <h1 class="della-text" id="della">One system that learns your business&mdash;and <span class="headline-accent">helps you run it.</span></h1>
                    </div>
                    <p class="tagline" id="tagline">Clarity is the intelligence layer of webXpanse&mdash;an AI-native business operating system for founders and lean teams, including the new generation of one-person companies. It connects the customer, the plan, and the work, then helps move the business forward within boundaries you set.</p>
                    <div class="entrance-actions">
                        <a class="hero-action hero-primary" href="<?php echo htmlspecialchars($signupUrl); ?>">Start with your business</a>
                        <a class="hero-action hero-secondary" id="enterBtn" href="#demo-video">Watch the explainer</a>
                    </div>
                    <div class="trust-line" id="trustLine" aria-label="Clarity commitments">
                        <span>Learns before it recommends</span>
                        <span>Human approval where it matters</span>
                        <span>Starts small. Grows with you.</span>
                    </div>
                </div>

                <div class="hero-orbit" aria-label="Clarity, the webXpanse intelligence layer, connects customer context, strategy, work, and governed automation">
                    <span class="orbit-axis" aria-hidden="true"></span>
                    <span class="orbit-axis vertical" aria-hidden="true"></span>
                    <div class="orbit-core">
                        <img src="<?php echo htmlspecialchars($entranceLogoUrl); ?>" alt="Clarity by webXpanse">
                    </div>
                    <div class="orbit-node top">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                        <span>Customer context</span>
                    </div>
                    <div class="orbit-node right">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M4 19V5m0 0h11l-2 4 2 4H4"/></svg>
                        <span>Strategy</span>
                    </div>
                    <div class="orbit-node bottom">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 14h6"/></svg>
                        <span>Work</span>
                    </div>
                    <div class="orbit-node left">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M12 3l8 4v5c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V7z"/><path d="M9 12l2 2 4-4"/></svg>
                        <span>Governed automation</span>
                    </div>
                </div>
            </div>
            <div class="hero-corner-lockup">
                <p class="hero-manifesto">Grow with clarity. Scale with intelligence.</p>
                <span class="webxpanse-badge"><span>by</span> webXpanse</span>
            </div>
        </div>
        <button class="sound-toggle muted" id="soundToggle" aria-label="Turn ambient sound on" title="Ambient sound on/off">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M11 5L6 9H2v6h4l5 4V5z"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>
        </button>
    </section>

    <main class="landing-main" id="landing-main">
        <section class="clarity-section loop-section" id="cofounder-loop" aria-labelledby="loop-title">
            <div class="clarity-section-shell">
                <div class="loop-intro">
                    <div class="section-copy">
                        <div class="section-rule">The cofounder loop</div>
                        <h2 class="section-heading" id="loop-title">Clarity earns the right <span class="section-accent">to act.</span></h2>
                        <p>Automation should begin with understanding. Clarity learns the business, remembers what matters, and keeps people in control as recommendations become action.</p>
                    </div>
                    <p class="loop-side-note">The system becomes useful before it becomes autonomous.</p>
                </div>

                <figure class="editorial-image editorial-wide loop-editorial">
                    <img src="<?php echo htmlspecialchars($basePath . '/public/assets/images/landing/founder-learning-studio.webp'); ?>" width="1440" height="960" loading="lazy" decoding="async" alt="A founder reviewing customer notes and product materials in her studio">
                </figure>

                <ol class="loop-rail" aria-label="Clarity's five-step learning and action loop">
                    <li class="loop-step">
                        <span class="loop-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M4 19V5m0 0h11l-2 4 2 4H4"/></svg></span>
                        <h3>Learn</h3>
                        <p>Start with the customer, offer, voice, goals, and operating rules.</p>
                        <div class="seed-list"><span>Customer</span><span>Offer</span><span>Voice</span><span>Goals</span><span>Rules</span></div>
                    </li>
                    <li class="loop-step">
                        <span class="loop-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"/><path d="M8 8h7M8 12h6"/></svg></span>
                        <h3>Remember</h3>
                        <p>Keep decisions, customer needs, promises, and outcomes in one useful memory.</p>
                    </li>
                    <li class="loop-step">
                        <span class="loop-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M12 3l2 6 6 2-6 2-2 6-2-6-6-2 6-2z"/><path d="M19 17l.8 2.2L22 20l-2.2.8L19 23l-.8-2.2L16 20l2.2-.8z"/></svg></span>
                        <h3>Recommend</h3>
                        <p>Surface the next move with the business context and reasoning visible.</p>
                    </li>
                    <li class="loop-step">
                        <span class="loop-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M12 3l8 4v5c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V7z"/><path d="M9 12l2 2 4-4"/></svg></span>
                        <h3>Approve</h3>
                        <p>Let a person review important decisions, permissions, and boundaries.</p>
                        <div class="human-boundary">Human control boundary</div>
                    </li>
                    <li class="loop-step">
                        <span class="loop-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M13 2L4 14h7l-1 8 9-12h-7z"/></svg></span>
                        <h3>Act</h3>
                        <p>Carry out approved work, track the result, and bring the learning back.</p>
                    </li>
                </ol>
                <p class="loop-return">Learn from outcomes &rarr; improve the next recommendation</p>
            </div>
        </section>

        <section class="clarity-section context-section" id="one-context" aria-labelledby="context-title">
            <div class="clarity-section-shell context-layout">
                <div class="section-copy">
                    <div class="section-rule">Less tool fragmentation</div>
                    <h2 class="section-heading" id="context-title">One context. Fewer <span class="section-accent">disconnected tools.</span></h2>
                    <p>Clarity gives the business a shared operating context. Customer memory, strategy, communication, sales, tasks, finance-ready workflows, and automation can inform the same next move.</p>
                    <div class="fragment-list" aria-label="Common fragmented business sources">
                        <span>Inboxes</span><span>Spreadsheets</span><span>Notes</span><span>Channels</span><span>Memory</span>
                    </div>
                    <p class="context-caption">Keep specialist tools where they help. Let Clarity connect the decisions and work between them.</p>
                </div>

                <div class="context-map" aria-label="Business context connected through Clarity">
                    <svg class="context-lines" viewBox="0 0 760 600" preserveAspectRatio="none" aria-hidden="true">
                        <line x1="365" y1="300" x2="365" y2="45"/><line x1="365" y1="300" x2="120" y2="125"/>
                        <line x1="365" y1="300" x2="630" y2="125"/><line x1="365" y1="300" x2="55" y2="320"/>
                        <line x1="365" y1="300" x2="700" y2="320"/><line x1="365" y1="300" x2="130" y2="530"/>
                        <line x1="365" y1="300" x2="625" y2="530"/>
                    </svg>
                    <div class="context-core">
                        <img src="<?php echo htmlspecialchars($entranceLogoUrl); ?>" alt="">
                        <span>Clarity</span>
                    </div>
                    <div class="domain-node domain-customer"><img src="<?php echo htmlspecialchars($basePath . '/public/assets/images/landing/founder-learning-studio.webp'); ?>" loading="lazy" decoding="async" alt=""><span>Customer memory</span></div>
                    <div class="domain-node domain-strategy"><img src="<?php echo htmlspecialchars($basePath . '/public/assets/images/marketplace/lean-canvas.webp'); ?>" loading="lazy" decoding="async" alt=""><span>Strategy</span></div>
                    <div class="domain-node domain-communication"><img src="<?php echo htmlspecialchars($basePath . '/public/assets/images/landing/customer-conversation-team-multiethnic.webp'); ?>" loading="lazy" decoding="async" alt=""><span>Communication</span></div>
                    <div class="domain-node domain-sales"><img src="<?php echo htmlspecialchars($basePath . '/public/assets/images/marketplace/professional-marketer.webp'); ?>" loading="lazy" decoding="async" alt=""><span>Sales</span></div>
                    <div class="domain-node domain-tasks"><img src="<?php echo htmlspecialchars($basePath . '/public/assets/images/marketplace/email-assistant.webp'); ?>" loading="lazy" decoding="async" alt=""><span>Tasks</span></div>
                    <div class="domain-node domain-finance"><img src="<?php echo htmlspecialchars($basePath . '/public/assets/images/marketplace/lean-canvas.webp'); ?>" loading="lazy" decoding="async" alt=""><span>Finance</span></div>
                    <div class="domain-node domain-workflows"><img src="<?php echo htmlspecialchars($basePath . '/public/assets/images/marketplace/whatsapp-assistant.webp'); ?>" loading="lazy" decoding="async" alt=""><span>Workflows</span></div>
                    <div class="next-move">A clearer<br>next move &rarr;</div>
                </div>
            </div>
        </section>

        <section class="landing-section demo-section" id="demo" aria-labelledby="demo-title">
            <div class="landing-container demo-layout">
                <div class="demo-copy">
                    <div class="section-rule">Product explainer</div>
                    <h2 class="landing-heading" id="demo-title">Understand how Clarity keeps <span class="accent">work moving.</span></h2>
                    <p class="landing-copy">This short explainer introduces how Clarity brings customer conversations, context, ownership, and follow-through into one operating system&mdash;while people remain in control of important decisions.</p>
                    <div class="demo-points" aria-label="Product explainer themes">
                        <div class="demo-point">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M21 11.5a8.4 8.4 0 0 1-9 8.4 9.5 9.5 0 0 1-4.1-.9L3 20l1.3-4.1A8.4 8.4 0 1 1 21 11.5z"/><path d="M8 9.5c.8 2.1 2.4 3.7 4.5 4.5"/></svg>
                            Customer conversations and context connected
                        </div>
                        <div class="demo-point">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                            Ownership and next steps made clear
                        </div>
                        <div class="demo-point">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M12 2l1.8 6.2L20 10l-6.2 1.8L12 18l-1.8-6.2L4 10l6.2-1.8z"/><path d="M19 16l.8 2.2L22 19l-2.2.8L19 22l-.8-2.2L16 19l2.2-.8z"/></svg>
                            AI guidance with human control
                        </div>
                    </div>
                </div>

                <div class="demo-stage" id="demo-video">
                    <span class="demo-signal demo-signal-one">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3v-7a4 4 0 0 1-1-2.6V7a4 4 0 0 1 4-4h11a4 4 0 0 1 4 4z"/><path d="M7 9h10M7 13h6"/></svg>
                        Conversation
                    </span>
                    <span class="demo-signal demo-signal-two">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        Next task
                    </span>
                    <span class="demo-signal demo-signal-three">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>
                        Pipeline
                    </span>
                    <div class="demo-video-shell">
                        <video playsinline preload="none" data-poster="<?php echo htmlspecialchars($demoPosterAssetUrl); ?>" width="1280" height="720" aria-describedby="demo-overview-copy">
                            <source data-src="<?php echo htmlspecialchars($demoVideoAssetUrl); ?>" type="<?php echo htmlspecialchars($demoVideoMime); ?>">
                            Your browser does not support HTML video. Read the explainer overview beside the video instead.
                        </video>
                        <button class="demo-play-gate" type="button" aria-label="Play the product explainer with sound">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg>
                            <strong>Play explainer</strong>
                            <small>Sound on</small>
                        </button>
                        <?php echo \CRM\Services\VideoBrandOverlayUi::logo(); ?>
                    </div>
                </div>

                <details class="demo-overview">
                    <summary>What the explainer covers</summary>
                    <p id="demo-overview-copy">The video explains Clarity's approach to keeping customer conversations, context, follow-up, tasks, and pipeline movement connected in one operating workflow. It introduces the operating model rather than presenting a live product demonstration.</p>
                </details>
            </div>
        </section>

        <section class="clarity-section customer-section" id="customer-intelligence" aria-labelledby="customer-title">
            <div class="clarity-section-shell">
                <div class="customer-heading">
                    <div class="section-copy">
                        <div class="section-rule">Customer intelligence that compounds</div>
                        <h2 class="section-heading" id="customer-title">Remember the customer. <span class="section-accent">Speak like the business.</span></h2>
                        <p>Every useful interaction should improve the next one. Clarity helps a small team document the need, own the response, and follow through in a consistent brand voice.</p>
                    </div>
                </div>

                <figure class="editorial-image editorial-wide customer-editorial">
                    <img src="<?php echo htmlspecialchars($basePath . '/public/assets/images/landing/customer-conversation-team-multiethnic.webp'); ?>" width="1440" height="960" loading="lazy" decoding="async" alt="A multi-ethnic small business team listening closely to a customer in a bright studio">
                </figure>

                <div class="customer-system">
                    <ol class="customer-flow" aria-label="Customer need to follow-through workflow">
                        <li class="customer-step">
                            <span class="customer-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M21 15a4 4 0 0 1-4 4H8l-5 3v-7a4 4 0 0 1-1-2.6V7a4 4 0 0 1 4-4h11a4 4 0 0 1 4 4z"/></svg></span>
                            <h3>Conversation</h3>
                            <p>A customer shares what they need in the channel they already use.</p>
                            <div class="customer-example customer-example--incoming">“Can you install by Friday? We may need to split the payment.”</div>
                        </li>
                        <li class="customer-step">
                            <span class="customer-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M5 4h11a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3z"/><path d="M8 8h7M8 12h6"/></svg></span>
                            <h3>Need captured</h3>
                            <p>The customer, timing, objection, and promise become usable context.</p>
                            <div class="customer-example customer-example--outgoing">Installation by Friday<br>Payment flexibility requested<br>Decision pending</div>
                        </li>
                        <li class="customer-step">
                            <span class="customer-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg></span>
                            <h3>Action owned</h3>
                            <p>A clear person, deadline, and next move replace informal memory.</p>
                            <div class="customer-example customer-example--incoming">Owner: Amina<br>Confirm installation window<br>Send approved payment options</div>
                        </li>
                        <li class="customer-step">
                            <span class="customer-node"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg></span>
                            <h3>Follow-through visible</h3>
                            <p>The response, task, and outcome stay connected for the next interaction.</p>
                            <div class="customer-example customer-example--system" aria-label="Completed follow-through status">
                                <span><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>Reply approved</span>
                                <span><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>Follow-up scheduled</span>
                                <span><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="m3 8 3 3 7-7"/></svg>Outcome returned to memory</span>
                            </div>
                        </li>
                    </ol>

                    <aside class="brand-voice" aria-labelledby="brand-voice-title">
                        <h3 id="brand-voice-title">Your brand voice</h3>
                        <div class="voice-line"><span>Warm</span><span>Human</span></div>
                        <div class="voice-line"><span>Consultative</span><span>Helpful</span></div>
                        <div class="voice-line"><span>Clear</span><span>Direct</span></div>
                        <div class="voice-line"><span>On-brand</span><span>Consistent</span></div>
                        <span class="voice-core"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M12 3l2 6 6 2-6 2-2 6-2-6-6-2 6-2z"/></svg></span>
                        <div class="channel-row" aria-label="Communication channels"><span>Email</span><span>WhatsApp</span><span>Social</span></div>
                    </aside>
                </div>
                <p class="customer-manifesto">The customer is not a record. The customer is a relationship the business learns to serve better.</p>
            </div>
        </section>

        <section class="clarity-section growth-section" id="growth" aria-labelledby="growth-title">
            <div class="clarity-section-shell">
                <div class="section-copy">
                    <div class="section-rule">Useful at every honest stage</div>
                    <h2 class="section-heading" id="growth-title">Start curious. Operate clearly. <span class="section-accent">Scale intelligently.</span></h2>
                    <p>Within webXpanse, Clarity can begin as a thinking partner for an early idea, become the operating rhythm for a lean team, and grow into governed coordination for a larger organization.</p>
                </div>

                <aside class="opc-positioning" aria-labelledby="opc-title">
                    <div class="opc-heading">
                        <div class="opc-kicker">The one-person company era</div>
                        <h3 id="opc-title">Small human core. Enterprise-level coordination.</h3>
                    </div>
                    <div class="opc-copy">
                        <p>A one-person company (OPC) is not one person doing every job. It is a founder-led business with an exceptionally small permanent human core, using AI, automation, connected systems, and specialists on demand to create the capacity of a much larger organization.</p>
                        <p>Clarity gives that model a shared operating context while continuing to support founder-led lean teams as people join.</p>
                        <div class="opc-signals" aria-label="One-person company operating model">
                            <span>Founder-led</span>
                            <span>AI and automation</span>
                            <span>Specialists on demand</span>
                        </div>
                    </div>
                </aside>

                <ol class="journey-rail" aria-label="Clarity from startup idea to growing organization">
                    <li class="journey-stage">
                        <span class="journey-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M12 3c-4 2-6 5-6 9 0 3 2 6 6 9 4-3 6-6 6-9 0-4-2-7-6-9z"/><path d="M12 8v9M9 13h6"/></svg></span>
                        <div><h3>Start</h3><p>Turn curiosity, a hobby, or an early business test into a clearer offer and learning plan.</p></div>
                        <div class="journey-points"><span>Idea validation</span><span>Offer clarity</span><span>Early customer learning</span></div>
                    </li>
                    <li class="journey-stage">
                        <span class="journey-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 14h6"/></svg></span>
                        <div><h3>Operate</h3><p>Give a solo founder or lean team the context and follow-through of a much larger operation.</p></div>
                        <div class="journey-points"><span>Customer memory</span><span>Work ownership</span><span>Consistent communication</span></div>
                    </li>
                    <li class="journey-stage">
                        <span class="journey-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg></span>
                        <div><h3>Scale</h3><p>Coordinate growing teams through shared context, explicit boundaries, and measurable workflows.</p></div>
                        <div class="journey-points"><span>Team coordination</span><span>Governance</span><span>Workflow learning</span></div>
                    </li>
                </ol>

                <div class="autonomy-ladder">
                    <div class="autonomy-title">Automation grows only when the business is ready</div>
                    <div class="autonomy-track">
                        <div class="autonomy-step"><h3>Suggest</h3><p>Clarity prepares ideas, summaries, and next moves for a person to consider.</p></div>
                        <div class="autonomy-gate"><span>Readiness<br>check</span></div>
                        <div class="autonomy-step"><h3>Assist</h3><p>Clarity prepares work and routes meaningful decisions through approval.</p></div>
                        <div class="autonomy-gate"><span>Approval<br>gate</span></div>
                        <div class="autonomy-step"><h3>Automate within limits</h3><p>Trusted, repeatable work can run inside the policies and permissions you choose.</p></div>
                    </div>
                </div>

                <div class="growth-close">
                    <p>Grow with clarity. Scale with intelligence.</p>
                    <a class="landing-cta" href="<?php echo htmlspecialchars($signupUrl); ?>">Start with your business</a>
                </div>
            </div>
        </section>

        <section class="landing-section inquiry-section" id="inquiry" aria-labelledby="inquiry-title">
            <div class="landing-container inquiry-layout">
                <div class="inquiry-copy">
                    <div class="section-rule">Start a useful conversation</div>
                    <h2 class="landing-subheading" id="inquiry-title">Bring the question, opportunity, or <span class="accent">unfinished idea.</span></h2>
                    <p class="landing-copy">If you want to understand the product more deeply, explore a partnership, test Clarity with a real business, or discuss a larger rollout, share the context. We will route it into the same operating workspace we use to document, learn, and follow through.</p>

                    <div class="inquiry-routes" aria-label="Reasons to contact Clarity">
                        <div class="inquiry-route"><strong>Product fit</strong><span>See how Clarity could support your stage, workflow, or team.</span></div>
                        <div class="inquiry-route"><strong>Partnership</strong><span>Explore ecosystem, channel, research, or implementation opportunities.</span></div>
                        <div class="inquiry-route"><strong>Pilot or market test</strong><span>Run a focused experiment around an idea, offer, or operating problem.</span></div>
                        <div class="inquiry-route"><strong>Team or integration</strong><span>Discuss rollout, governance, existing tools, and technical fit.</span></div>
                    </div>

                    <p class="inquiry-note">Not sure which route fits? Choose “Other.” A useful first response matters more than putting your question in the perfect category.</p>
                </div>

                <div class="inquiry-form-shell">
                    <iframe class="inquiry-form-frame" id="landing-inquiry-frame" src="<?php echo htmlspecialchars($landingInquiryFormUrl); ?>" title="Clarity product inquiry and partnership form" loading="lazy"></iframe>
                </div>
            </div>
        </section>

        <section class="landing-section faq-section" id="faq" aria-labelledby="faq-title">
            <div class="landing-container faq-layout">
                <div>
                    <div class="section-rule">Plain answers</div>
                    <h2 class="landing-subheading" id="faq-title">Questions curious teams <span class="accent">ask</span></h2>
                </div>
                <div class="faq-list">
                    <?php foreach ($faqItems as $item): ?>
                    <details>
                        <summary><?php echo htmlspecialchars($item['question']); ?></summary>
                        <p><?php echo htmlspecialchars($item['answer']); ?></p>
                    </details>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <section class="landing-section final-cta-section" aria-labelledby="final-cta-title">
            <div class="landing-container final-cta">
                <h2 class="landing-subheading" id="final-cta-title">Your business can become more capable without becoming <span class="accent">more complicated.</span></h2>
                <p class="landing-copy">Start by teaching Clarity, the intelligence layer of webXpanse, what you are building, who you serve, and how you want the business to operate.</p>
                <div class="final-actions">
                    <a class="landing-cta" href="<?php echo htmlspecialchars($signupUrl); ?>">Start with your business</a>
                    <a class="landing-cta secondary" href="<?php echo htmlspecialchars($loginUrl); ?>">Sign in</a>
                </div>
                <?php if ($donationsEnabled): ?>
                <a class="support-access-link" href="<?php echo htmlspecialchars($donateUrl); ?>" data-public-donate-cta>Support startup AI access</a>
                <?php endif; ?>
            </div>
        </section>

        <footer class="landing-footer">
            <span class="footer-brand-line">
                <span>&copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($parentBrandName); ?></span>
                <span class="footer-product-relationship">Clarity is the webXpanse intelligence layer.</span>
            </span>
            <nav aria-label="Legal and support links">
                <a href="<?php echo htmlspecialchars($privacyUrl); ?>">Privacy</a>
                <a href="<?php echo htmlspecialchars($termsUrl); ?>">Terms</a>
                <a href="mailto:support@webxpanse.com">Support</a>
            </nav>
        </footer>
    </main>

    <script>
(function() {
    var prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var audioStarted = false;
    var audioCtx = null;
    var masterGain = null;
    var finalGain = null;
    var oscillators = [];

    // === AMBIENT AUDIO (Web Audio API) ===
    function createAmbientAudio() {
        try {
            audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            masterGain = audioCtx.createGain();
            masterGain.gain.setValueAtTime(0, audioCtx.currentTime);
            finalGain = audioCtx.createGain();
            finalGain.gain.setValueAtTime(1, audioCtx.currentTime);
            masterGain.connect(finalGain);
            finalGain.connect(audioCtx.destination);

            // Low-pass filter for warmth
            var filter = audioCtx.createBiquadFilter();
            filter.type = 'lowpass';
            filter.frequency.setValueAtTime(1200, audioCtx.currentTime);
            filter.Q.setValueAtTime(0.5, audioCtx.currentTime);
            filter.connect(masterGain);

            // Rich pad: A minor with sine + triangle, stereo spread, detuning
            var baseFreqs = [55, 82.5, 110, 165, 220, 330];
            var pans = [-0.3, 0.2, 0, -0.2, 0.3, 0];
            for (var i = 0; i < baseFreqs.length; i++) {
                var osc = audioCtx.createOscillator();
                var osc2 = audioCtx.createOscillator();
                var gain = audioCtx.createGain();
                var panner = audioCtx.createStereoPanner();
                panner.pan.setValueAtTime(pans[i], audioCtx.currentTime);
                osc.type = 'sine';
                osc2.type = 'triangle';
                osc2.frequency.setValueAtTime(baseFreqs[i] * 1.005, audioCtx.currentTime);
                osc.frequency.setValueAtTime(baseFreqs[i] * (0.995 + Math.random() * 0.02), audioCtx.currentTime);
                gain.gain.setValueAtTime(0.04 - i * 0.005, audioCtx.currentTime);
                osc.connect(gain);
                osc2.connect(gain);
                gain.connect(panner);
                panner.connect(filter);
                osc.start(audioCtx.currentTime);
                osc2.start(audioCtx.currentTime);
                oscillators.push({ osc: osc, osc2: osc2, gain: gain });
            }

            // Subtle LFO "breathing" on master
            var lfo = audioCtx.createOscillator();
            var lfoGain = audioCtx.createGain();
            lfo.type = 'sine';
            lfo.frequency.setValueAtTime(0.05, audioCtx.currentTime);
            lfoGain.gain.setValueAtTime(0.04, audioCtx.currentTime);
            lfo.connect(lfoGain);
            lfoGain.connect(masterGain.gain);
            lfo.start(audioCtx.currentTime);

            return true;
        } catch (e) {
            return false;
        }
    }

    function playChime() {
        if (!audioCtx || !audioStarted) return;
        try {
            var osc = audioCtx.createOscillator();
            var gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, audioCtx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(1320, audioCtx.currentTime + 0.15);
            osc.frequency.exponentialRampToValueAtTime(1760, audioCtx.currentTime + 0.35);
            gain.gain.setValueAtTime(0.1, audioCtx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 1.2);
            osc.connect(gain);
            gain.connect(masterGain);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + 1.2);
        } catch (e) {}
    }

    function playSwell() {
        if (!audioCtx || !masterGain) return;
        try {
            var osc = audioCtx.createOscillator();
            var gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(110, audioCtx.currentTime);
            osc.frequency.linearRampToValueAtTime(220, audioCtx.currentTime + 1.5);
            gain.gain.setValueAtTime(0, audioCtx.currentTime);
            gain.gain.linearRampToValueAtTime(0.12, audioCtx.currentTime + 0.8);
            gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 2);
            osc.connect(gain);
            gain.connect(masterGain);
            osc.start(audioCtx.currentTime);
            osc.stop(audioCtx.currentTime + 2);
        } catch (e) {}
    }

    var padDuration = 45;

    function startAudio() {
        if (audioStarted) return;
        if (!audioCtx) {
            if (!createAmbientAudio()) return;
        }
        if (audioCtx.state === 'suspended') audioCtx.resume();
        audioStarted = true;
        var t = audioCtx.currentTime;
        masterGain.gain.linearRampToValueAtTime(0.28, t + 2);
        finalGain.gain.setValueAtTime(1, t);
        finalGain.gain.linearRampToValueAtTime(1, t + padDuration - 4);
        finalGain.gain.linearRampToValueAtTime(0, t + padDuration);
        playSwell();
        var toggle = document.getElementById('soundToggle');
        if (toggle) { toggle.style.display = 'flex'; toggle.classList.remove('muted'); }
    }

    function stopAudio() {
        if (!finalGain || !audioCtx) return;
        finalGain.gain.linearRampToValueAtTime(0, audioCtx.currentTime + 0.5);
    }

    function muteAudio(muted) {
        if (!masterGain || !audioCtx) return;
        masterGain.gain.linearRampToValueAtTime(muted ? 0 : 0.28, audioCtx.currentTime + 0.3);
        var toggle = document.getElementById('soundToggle');
        if (toggle) toggle.classList.toggle('muted', muted);
    }

    // === SPIRAL ANIMATION (Vanilla JS) ===
    var hero = document.getElementById('hero');
    var canvas = document.getElementById('canvas');
    var ctx = canvas.getContext('2d');
    var w, h, dpr, animId;
    var stars = [];
    var ambientStars = [];
    var trailLength = 84;
    var changeEventTime = 0.32;
    var cameraZ = -400;
    var cameraTravel = 2700;
    var viewZoom = 145;
    var fieldScale = 1;
    var time = 0.1;

    function resize() {
        w = Math.max(hero ? hero.clientWidth : window.innerWidth, 1);
        h = Math.max(hero ? hero.clientHeight : window.innerHeight, 1);
        dpr = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = Math.ceil(w * dpr);
        canvas.height = Math.ceil(h * dpr);
        canvas.style.width = '100%';
        canvas.style.height = '100%';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        fieldScale = w <= 820
            ? Math.max(0.82, Math.min(1, w / 390))
            : Math.max(1.05, Math.min(1.35, Math.min(w / 980, h / 720) * 1.2));
    }

    function ease(p, g) {
        return p < 0.5 ? 0.5 * Math.pow(2 * p, g) : 1 - 0.5 * Math.pow(2 * (1 - p), g);
    }

    function spiralPath(p) {
        p = Math.min(Math.max(1.2 * p, 0), 1);
        p = ease(p, 1.8);
        var turns = 6;
        var theta = 2 * Math.PI * turns * Math.sqrt(p);
        var r = 160 * Math.sqrt(p);
        return { x: r * Math.cos(theta), y: r * Math.sin(theta) + 28 };
    }

    function createStars() {
        stars = [];
        ambientStars = [];
        var ambientStarCount = Math.round(Math.max(150, Math.min(260, (w * h) / 4200)));
        for (var a = 0; a < ambientStarCount; a++) {
            ambientStars.push({
                x: Math.random(),
                y: Math.random(),
                radius: 0.2 + Math.pow(Math.random(), 2) * 0.65,
                alpha: 0.16 + Math.random() * 0.36,
                phase: Math.random() * Math.PI * 2
            });
        }
        for (var i = 0; i < 3600; i++) {
            stars.push({
                angle: Math.random() * Math.PI * 2,
                distance: 48 * Math.random() + 18,
                dir: Math.random() > 0.5 ? 1 : -1,
                loc: (1 - Math.pow(1 - Math.random(), 3)) / 1.3,
                z: cameraZ + Math.random() * (cameraTravel + cameraZ),
                sw: Math.pow(Math.random(), 2)
            });
        }
    }

    function render() {
        ctx.fillStyle = '#0a0a0a';
        ctx.fillRect(0, 0, w, h);

        // Keep a quiet, hero-wide particle bed visible throughout the cycle.
        ctx.save();
        for (var a = 0; a < ambientStars.length; a++) {
            var ambient = ambientStars[a];
            var twinkle = 0.74 + 0.26 * Math.sin(time * Math.PI * 2 + ambient.phase);
            ctx.globalAlpha = ambient.alpha * twinkle;
            ctx.fillStyle = ambient.radius > 0.62 ? '#dbeafe' : '#8fbfff';
            ctx.beginPath();
            ctx.arc(ambient.x * w, ambient.y * h, ambient.radius, 0, Math.PI * 2);
            ctx.fill();
        }
        ctx.restore();

        ctx.save();
        ctx.translate(w / 2, h / 2);

        var t1 = Math.min(Math.max((time - 0) / (changeEventTime + 0.25), 0), 1);
        var t2 = Math.min(Math.max((time - changeEventTime) / (1 - changeEventTime), 0), 1);
        ctx.rotate(-Math.PI * ease(t2, 2.7));
        ctx.scale(fieldScale, fieldScale);

        // Trail
        ctx.save();
        ctx.globalCompositeOperation = 'lighter';
        ctx.shadowColor = 'rgba(96, 165, 250, 0.56)';
        ctx.shadowBlur = 4;
        for (var i = 0; i < trailLength; i++) {
            var f = Math.pow(1 - (i / trailLength), 1.45);
            var sw = (1.15 * (1 - t1) + 3 * Math.sin(Math.PI * t1)) * f;
            var pt = spiralPath(t1 - 0.00055 * i);
            ctx.fillStyle = 'rgba(219, 234, 254, ' + (0.07 + 0.38 * f) + ')';
            ctx.beginPath();
            ctx.arc(pt.x, pt.y, Math.max(sw / 2, 0.25), 0, Math.PI * 2);
            ctx.fill();
        }

        var cometHead = spiralPath(t1);
        var cometHeadRadius = 3.8 + 3.4 * Math.sin(Math.PI * t1);
        var cometGlow = ctx.createRadialGradient(
            cometHead.x,
            cometHead.y,
            0,
            cometHead.x,
            cometHead.y,
            cometHeadRadius * 1.55
        );
        cometGlow.addColorStop(0, 'rgba(255, 255, 255, 0.88)');
        cometGlow.addColorStop(0.24, 'rgba(147, 197, 253, 0.72)');
        cometGlow.addColorStop(1, 'rgba(59, 130, 246, 0)');
        ctx.fillStyle = cometGlow;
        ctx.beginPath();
        ctx.arc(cometHead.x, cometHead.y, cometHeadRadius * 1.55, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();

        // Stars
        var newCamZ = cameraZ + ease(Math.pow(t2, 1.2), 1.8) * cameraTravel;
        for (var s = 0; s < stars.length; s++) {
            var star = stars[s];
            if (t1 - star.loc <= 0) continue;
            var q = t1 - star.loc;
            var prog = Math.min(4 * q, 1);
            var dx = star.distance * Math.cos(star.angle);
            var dy = star.distance * Math.sin(star.angle);
            var sx = spiralPath(star.loc).x + dx * prog;
            var sy = spiralPath(star.loc).y + dy * prog;
            var vx = (star.z - newCamZ) * sx / viewZoom;
            var vy = (star.z - newCamZ) * sy / viewZoom;
            if (star.z > newCamZ) {
                var dotSw = 400 * 8.5 * star.sw / (star.z - newCamZ);
                var particleRadius = Math.min(0.9, Math.max(0.22, dotSw * 0.12));
                ctx.globalAlpha = 0.12 + star.sw * 0.34;
                ctx.fillStyle = star.dir > 0 ? '#dbeafe' : '#7dd3fc';
                ctx.beginPath();
                ctx.arc(vx, vy, particleRadius, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        ctx.restore();
    }

    function loop() {
        time += 0.0012;
        if (time > 1) time = 0.1;
        render();
        animId = requestAnimationFrame(loop);
    }

    // === REVEAL ANIMATION ===
    function initReveal() {
        var loading = document.getElementById('loading');
        var introKicker = document.getElementById('introKicker');
        var headlineFrame = document.getElementById('headlineFrame');
        var della = document.getElementById('della');
        var tagline = document.getElementById('tagline');
        var trustLine = document.getElementById('trustLine');
        var btn = document.getElementById('enterBtn');
        var donateBtn = document.querySelector('.donate-cta-gold');

        if (!prefersReducedMotion && introKicker && typeof introKicker.animate === 'function') {
            loading.classList.add('hidden');

            function revealElement(element, keyframes, duration, delay, finalStyles, onFinish) {
                if (!element) return;
                var animation = element.animate(keyframes, {
                    duration: duration,
                    delay: delay,
                    easing: 'cubic-bezier(.16, 1, .3, 1)',
                    fill: 'forwards'
                });
                animation.addEventListener('finish', function() {
                    Object.assign(element.style, finalStyles || {});
                    animation.cancel();
                    if (onFinish) onFinish();
                }, { once: true });
            }

            revealElement(introKicker, [
                { opacity: 0, transform: 'translate(-50%, -8px)' },
                { opacity: 1, transform: 'translate(-50%, 0)' }
            ], 900, 250, { opacity: '1', transform: 'translateX(-50%)' });
            revealElement(headlineFrame, [
                { opacity: 0, transform: 'translateY(18px)', filter: 'blur(10px)' },
                { opacity: 1, transform: 'translateY(0)', filter: 'blur(0)' }
            ], 1250, 450, { opacity: '1', transform: 'none', filter: 'none' }, playChime);
            revealElement(della, [
                { opacity: 0, transform: 'translateY(10px)' },
                { opacity: 1, transform: 'translateY(0)' }
            ], 1150, 520, { opacity: '1', transform: 'none' });
            revealElement(tagline, [
                { opacity: 0, transform: 'translateY(12px)' },
                { opacity: 0.78, transform: 'translateY(0)' }
            ], 1000, 1180, { opacity: '0.78', transform: 'none' });
            revealElement(trustLine, [
                { opacity: 0, transform: 'translateY(8px)' },
                { opacity: 1, transform: 'translateY(0)' }
            ], 900, 1520, { opacity: '1', transform: 'none' });
            Array.prototype.forEach.call(trustLine.querySelectorAll('span'), function(item, index) {
                revealElement(item, [
                    { opacity: 0, transform: 'translateY(6px)' },
                    { opacity: 1, transform: 'translateY(0)' }
                ], 600, 1680 + (index * 120), { opacity: '1', transform: 'none' });
            });
            revealElement(btn, [{ opacity: 0 }, { opacity: 1 }], 1000, 1900, { opacity: '1' });
            revealElement(donateBtn, [{ opacity: 0 }, { opacity: 1 }], 800, 2050, { opacity: '1' });
        } else {
            loading.classList.add('hidden');
            introKicker.style.opacity = '1';
            headlineFrame.style.opacity = '1';
            della.style.opacity = '1';
            tagline.style.opacity = '0.7';
            trustLine.style.opacity = '1';
            btn.style.opacity = '1';
            if (donateBtn) {
                donateBtn.style.opacity = '1';
            }
        }
    }

    // === SOUND TOGGLE ===
    var isMuted = true;
    document.getElementById('soundToggle').addEventListener('click', function() {
        if (!audioStarted) {
            startAudio();
            isMuted = false;
        } else {
            isMuted = !isMuted;
            muteAudio(isMuted);
        }
        this.classList.toggle('muted', isMuted);
        this.setAttribute('aria-label', isMuted ? 'Turn ambient sound on' : 'Turn ambient sound off');
        this.innerHTML = isMuted
            ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M11 5L6 9H2v6h4l5 4V5z"/><line x1="23" y1="9" x2="17" y2="15"/><line x1="17" y1="9" x2="23" y2="15"/></svg>'
            : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M11 5L6 9H2v6h4l5 4V5z"/><path d="M15.54 8.46a5 5 0 0 1 0 7.07"/><path d="M19.07 4.93a10 10 0 0 1 0 14.14"/></svg>';
    });

    // === INIT ===
    window.addEventListener('resize', function() {
        resize();
        createStars();
    });
    resize();
    createStars();
    if (prefersReducedMotion) {
        render();
    } else {
        loop();
    }

    if (!prefersReducedMotion && hero && 'IntersectionObserver' in window) {
        var heroObserver = new IntersectionObserver(function(entries) {
            var isVisible = !!(entries[0] && entries[0].isIntersecting);
            if (!isVisible && animId) {
                cancelAnimationFrame(animId);
                animId = null;
            } else if (isVisible && !animId) {
                loop();
            }
        }, { threshold: 0.02 });
        heroObserver.observe(hero);
    }

    if (hero && 'ResizeObserver' in window) {
        var measuredHeroWidth = w;
        var measuredHeroHeight = h;
        var canvasResizeObserver = new ResizeObserver(function() {
            var nextWidth = Math.max(hero.clientWidth, 1);
            var nextHeight = Math.max(hero.clientHeight, 1);
            if (nextWidth === measuredHeroWidth && nextHeight === measuredHeroHeight) return;
            measuredHeroWidth = nextWidth;
            measuredHeroHeight = nextHeight;
            resize();
            createStars();
            if (prefersReducedMotion) render();
        });
        canvasResizeObserver.observe(hero);
    }

    var demoVideo = document.querySelector('#demo-video video');
    if (demoVideo) {
        var demoPlayGate = document.querySelector('.demo-play-gate');
        var demoSource = demoVideo.querySelector('source[data-src]');
        var demoPosterUrl = demoVideo.getAttribute('data-poster') || '';
        demoVideo.muted = false;
        demoVideo.defaultMuted = false;
        demoVideo.volume = 1;
        demoVideo.dataset.autoplayState = 'sound-on-ready';

        function showDemoPlayGate(label) {
            if (!demoPlayGate) return;
            var gateLabel = demoPlayGate.querySelector('strong');
            if (gateLabel && label) gateLabel.textContent = label;
            demoPlayGate.hidden = false;
        }

        function prepareDemoPoster() {
            if (demoPosterUrl && !demoVideo.getAttribute('poster')) {
                demoVideo.setAttribute('poster', demoPosterUrl);
            }
        }

        function prepareDemoMedia() {
            prepareDemoPoster();
            demoVideo.controls = true;
            if (demoSource && !demoSource.getAttribute('src') && demoSource.dataset.src) {
                demoSource.setAttribute('src', demoSource.dataset.src);
                demoVideo.load();
            }
        }

        function playDemoWithSound() {
            prepareDemoMedia();
            demoVideo.muted = false;
            demoVideo.defaultMuted = false;
            demoVideo.volume = 1;
            demoVideo.dataset.autoplayState = 'sound-on-requested';
            var playback = demoVideo.play();
            if (playback && typeof playback.then === 'function') {
                playback.then(function() {
                    demoVideo.dataset.autoplayState = 'playing-with-sound';
                    if (demoPlayGate) demoPlayGate.hidden = true;
                }).catch(function() {
                    demoVideo.dataset.autoplayState = 'sound-on-blocked';
                    showDemoPlayGate('Play explainer');
                });
            }
        }

        function pauseDemoVideo() {
            if (!demoVideo.paused) demoVideo.pause();
            demoVideo.dataset.autoplayState = 'paused';
        }

        if (demoPlayGate) {
            demoPlayGate.addEventListener('click', playDemoWithSound);
        }

        demoVideo.addEventListener('pointerdown', prepareDemoMedia, { once: true });
        demoVideo.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' || event.key === ' ') prepareDemoMedia();
        });

        demoVideo.addEventListener('play', function() {
            demoVideo.dataset.autoplayState = demoVideo.muted ? 'playing-muted-by-viewer' : 'playing-with-sound';
            if (demoPlayGate) demoPlayGate.hidden = true;
        });

        demoVideo.addEventListener('pause', function() {
            if (!demoVideo.ended && demoVideo.currentTime > 0) showDemoPlayGate('Resume explainer');
        });

        demoVideo.addEventListener('ended', function() {
            showDemoPlayGate('Watch again');
        });

        if ('IntersectionObserver' in window) {
            var demoPosterObserver = new IntersectionObserver(function(entries) {
                if (entries[0] && entries[0].isIntersecting) {
                    prepareDemoPoster();
                    demoPosterObserver.disconnect();
                }
            }, { rootMargin: '600px 0px' });
            demoPosterObserver.observe(demoVideo);

            var demoVideoObserver = new IntersectionObserver(function(entries) {
                var entry = entries[0];
                if (!entry) return;

                if (!entry.isIntersecting || entry.intersectionRatio <= 0.2) {
                    pauseDemoVideo();
                }
            }, { threshold: [0, 0.2, 0.8] });
            demoVideoObserver.observe(demoVideo);
        } else {
            prepareDemoPoster();
        }

        document.addEventListener('visibilitychange', function() {
            if (document.hidden) pauseDemoVideo();
        });
    }

    var inquiryFrame = document.getElementById('landing-inquiry-frame');
    if (inquiryFrame) {
        var inquiryFrameObserver = null;

        function resizeInquiryFrame() {
            try {
                var frameDocument = inquiryFrame.contentDocument;
                if (!frameDocument) return;
                var formCard = frameDocument.querySelector('.form-runtime__card');
                var frameHeight = formCard
                    ? Math.ceil(formCard.getBoundingClientRect().height)
                    : Math.max(
                        frameDocument.documentElement ? frameDocument.documentElement.scrollHeight : 0,
                        frameDocument.body ? frameDocument.body.scrollHeight : 0
                    );
                if (frameHeight > 0) inquiryFrame.style.height = Math.ceil(frameHeight + 2) + 'px';
            } catch (error) {
                // The CSS minimum remains a usable fallback if embedding is cross-origin.
            }
        }

        inquiryFrame.addEventListener('load', function() {
            resizeInquiryFrame();
            try {
                if (inquiryFrameObserver) inquiryFrameObserver.disconnect();
                var formCard = inquiryFrame.contentDocument
                    ? inquiryFrame.contentDocument.querySelector('.form-runtime__card')
                    : null;
                if ('ResizeObserver' in window && formCard) {
                    inquiryFrameObserver = new ResizeObserver(resizeInquiryFrame);
                    inquiryFrameObserver.observe(formCard);
                }
            } catch (error) {
                // Keep the static responsive frame height when observation is unavailable.
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initReveal, { once: true });
    } else {
        initReveal();
    }
})();
    </script>
</body>
</html>
