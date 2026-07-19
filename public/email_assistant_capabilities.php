<?php
/**
 * Email Assistant Capabilities Documentation
 * Renders docs/email-assistant-capabilities.md for in-app viewing.
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
use CRM\Session;
use CRM\Auth;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$mdPath = __DIR__ . '/../docs/email-assistant-capabilities.md';
$raw = file_exists($mdPath) ? file_get_contents($mdPath) : 'Documentation not found.';

$renderInlineMarkdown = static function (string $value): string {
    $html = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    $html = preg_replace('/`([^`]+)`/', '<code>$1</code>', $html);
    $html = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace_callback('/\[([^\]]+)\]\(([^)]+)\)/', static function (array $matches): string {
        $label = $matches[1] ?? '';
        $url = htmlspecialchars_decode((string) ($matches[2] ?? ''), ENT_QUOTES);
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        return '<a href="' . $safeUrl . '" target="_blank" rel="noopener">' . $label . '</a>';
    }, $html);
    return $html;
};

$slugifyHeading = static function (string $heading): string {
    $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $heading) ?? ''));
    return trim($slug, '-') ?: 'section';
};

$toc = [];
$htmlParts = [];
$inList = false;
foreach (preg_split('/\R/', $raw) ?: [] as $line) {
    $trimmed = trim((string) $line);
    if ($trimmed === '') {
        if ($inList) {
            $htmlParts[] = '</ul>';
            $inList = false;
        }
        continue;
    }

    if (preg_match('/^(#{1,3})\s+(.+)$/', $trimmed, $headingMatch)) {
        if ($inList) {
            $htmlParts[] = '</ul>';
            $inList = false;
        }
        $level = strlen($headingMatch[1]);
        $headingText = trim($headingMatch[2]);
        $id = $slugifyHeading($headingText);
        $toc[] = ['level' => $level, 'text' => $headingText, 'id' => $id];
        $htmlParts[] = '<h' . $level . ' id="' . htmlspecialchars($id) . '">' . $renderInlineMarkdown($headingText) . '</h' . $level . '>';
        continue;
    }

    if ($trimmed === '---') {
        if ($inList) {
            $htmlParts[] = '</ul>';
            $inList = false;
        }
        $htmlParts[] = '<hr>';
        continue;
    }

    if (preg_match('/^-\s+(.+)$/', $trimmed, $listMatch)) {
        if (!$inList) {
            $htmlParts[] = '<ul>';
            $inList = true;
        }
        $htmlParts[] = '<li>' . $renderInlineMarkdown($listMatch[1]) . '</li>';
        continue;
    }

    if ($inList) {
        $htmlParts[] = '</ul>';
        $inList = false;
    }
    $htmlParts[] = '<p>' . $renderInlineMarkdown($trimmed) . '</p>';
}
if ($inList) {
    $htmlParts[] = '</ul>';
}
$html = implode("\n", $htmlParts);

$pageTitle = 'Email Assistant Capabilities - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Email Assistant Capabilities</h1>
                <p>Reference for Personal Assistant skills and email commands.</p>
            </div>
            <div class="page-header-actions">
                <a href="workspace_skills.php?module=email_assistant&setup_tab=outbound#setup" class="btn-premium-secondary">
                    <i class="fas fa-envelope"></i>
                    Email Settings
                </a>
            </div>
        </div>

        <div class="premium-doc-layout">
            <?php if (!empty($toc)): ?>
                <aside class="content-card premium-doc-toc">
                    <h2 style="margin-top:0;font-size:1rem;">On this page</h2>
                    <?php foreach (array_slice($toc, 0, 16) as $tocItem): ?>
                        <a href="#<?php echo htmlspecialchars($tocItem['id']); ?>" style="<?php echo (int) $tocItem['level'] === 3 ? 'padding-left:0.75rem;' : ''; ?>">
                            <?php echo htmlspecialchars($tocItem['text']); ?>
                        </a>
                    <?php endforeach; ?>
                </aside>
            <?php endif; ?>
            <article class="content-card premium-doc-content">
                <?php echo $html; ?>
            </article>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
