<?php
/** Public renderer for an immutable, explicitly published Design Studio snapshot. */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) { continue; }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Services\LandingPageDesignService;

Database::init(require __DIR__ . '/../config/database.php');

$marketing = new Marketing();
$page = $marketing->getPublishedLandingPageByToken((string) ($_GET['token'] ?? ''));
if (!$page) {
    http_response_code(404);
    echo 'Landing page not found.';
    exit;
}

$design = new LandingPageDesignService();
$document = $design->normalizeDocument((array) ($page['design_document_json'] ?? []), $page);
$mediaById = (array) ($page['_media']['by_id'] ?? []);
foreach ($mediaById as &$media) {
    $media['display_url'] = $marketing->mediaDisplayUrl($media);
}
unset($media);
$rendered = $design->render($document, [
    'page' => $page,
    'media_by_id' => $mediaById,
    'form_uuid' => (string) ($page['form_uuid'] ?? ''),
    'forms_by_id' => !empty($page['form_id']) ? [(int) $page['form_id'] => ['uuid' => (string) ($page['form_uuid'] ?? '')]] : [],
    'public_token' => (string) ($page['public_token'] ?? ''),
    'mode' => 'public',
]);
$pageTitle = (string) (($page['seo_title'] ?? '') ?: ($page['title'] ?? 'Landing page'));
$description = trim((string) ($page['meta_description'] ?? ''));
$socialPreviewUrl = !empty($page['_media']['social_preview']) ? $marketing->mediaDisplayUrl($page['_media']['social_preview']) : '';
$publicCss = assetUrl('css/design-studio-public.css');
$publicCssPath = __DIR__ . '/assets/css/design-studio-public.css';
if (is_file($publicCssPath)) { $publicCss .= (str_contains($publicCss, '?') ? '&' : '?') . 'v=' . rawurlencode((string) filemtime($publicCssPath)); }
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <?php if ($description !== ''): ?><meta name="description" content="<?php echo htmlspecialchars($description); ?>"><?php endif; ?>
    <?php if ($socialPreviewUrl !== ''): ?><meta property="og:image" content="<?php echo htmlspecialchars($socialPreviewUrl); ?>"><?php endif; ?>
    <meta property="og:title" content="<?php echo htmlspecialchars($pageTitle); ?>">
    <?php if ($description !== ''): ?><meta property="og:description" content="<?php echo htmlspecialchars($description); ?>"><?php endif; ?>
    <link rel="stylesheet" href="assets/css/marketing-public.css?v=<?php echo (int) filemtime(__DIR__ . '/assets/css/marketing-public.css'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($publicCss); ?>">
</head>
<body class="marketing-public-landing design-public-page">
    <main><?php echo $rendered; ?></main>
    <script src="assets/js/form-embed-host.js" defer></script>
    <script>
        (function () {
            var token = <?php echo json_encode((string) ($page['public_token'] ?? ''), JSON_UNESCAPED_SLASHES); ?>;
            if (!token) { return; }
            var endpoint = 'marketing_track.php';
            var cookieName = 'crm_marketing_vid';
            function cookieValue(name) {
                var match = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
                return match ? decodeURIComponent(match[1]) : '';
            }
            function makeSessionKey() {
                if (window.crypto && window.crypto.getRandomValues) {
                    var data = new Uint32Array(4);
                    window.crypto.getRandomValues(data);
                    return Array.prototype.map.call(data, function (part) { return part.toString(16); }).join('');
                }
                return String(Date.now()) + String(Math.random()).slice(2);
            }
            var sessionKey = cookieValue(cookieName) || makeSessionKey();
            document.cookie = cookieName + '=' + encodeURIComponent(sessionKey) + '; path=/; max-age=' + (86400 * 180) + '; SameSite=Lax';
            function withUtm(payload) {
                var params = new URLSearchParams(window.location.search);
                ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function (key) { if (params.has(key)) { payload[key] = params.get(key); } });
                return payload;
            }
            function send(payload) {
                payload.token = token;
                payload.session_key = sessionKey;
                payload.page_url = window.location.href;
                payload.referrer = document.referrer || '';
                var body = JSON.stringify(withUtm(payload));
                if (navigator.sendBeacon) { navigator.sendBeacon(endpoint, new Blob([body], { type: 'application/json' })); return; }
                fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'same-origin' }).catch(function () {});
            }
            send({ event_type: 'page_view', event_name: 'Landing page view' });
            document.addEventListener('click', function (event) {
                var target = event.target.closest('[data-marketing-cta]');
                if (!target) { return; }
                send({ event_type: 'cta_click', event_name: 'Landing page CTA click', cta_label: target.getAttribute('data-cta-label') || target.textContent || 'CTA', cta_destination: target.getAttribute('data-cta-destination') || target.getAttribute('href') || '' });
            });
        }());
    </script>
</body>
</html>
