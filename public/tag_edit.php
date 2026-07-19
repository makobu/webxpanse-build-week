<?php
/**
 * Edit Tag Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
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
use CRM\Security;
use CRM\Modules\Tags;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$tagsModule = new Tags();
$tagId = (int) ($_GET['id'] ?? 0);

if (!$tagId) {
    header('Location: tags.php?error=not_found');
    exit;
}

$tag = $tagsModule->getById($tagId);

if (!$tag) {
    header('Location: tags.php?error=not_found');
    exit;
}

$error = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $data = [
                'name' => trim((string) ($_POST['name'] ?? '')),
                'color' => $_POST['color'] ?? '#33cc33',
                'description' => $_POST['description'] ?? ''
            ];
            
            $tagsModule->update($tagId, $data);
            
            header('Location: tags.php?success=updated');
            exit;
        } catch (\InvalidArgumentException $e) {
            $error = $e->getMessage();
        } catch (\Throwable $e) {
            error_log('Tag update failed: ' . $e->getMessage());
            $error = 'The tag could not be updated. Please try again.';
        }
    }
}

// Predefined colors
$colorOptions = [
    '#33cc33' => 'Green',
    '#ff9900' => 'Orange',
    '#3366cc' => 'Blue',
    '#cc3333' => 'Red',
    '#999999' => 'Grey',
    '#99cc33' => 'Yellow',
    '#cc99cc' => 'Pink',
    '#33cccc' => 'Cyan'
];

$pageTitle = 'Edit Tag - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/admin-controls-ui.css">
<link rel="stylesheet" href="assets/css/utility-forms-ui.css">

<div class="page-premium">
    <div class="container">
        <div class="admin-form-page">
            <div class="admin-hero">
                <div>
                    <h1>Edit Tag</h1>
                    <p>Update tag details</p>
                </div>
                <div class="admin-hero-actions">
                    <a href="tags.php" class="btn-premium-secondary">
                        <i class="fas fa-arrow-left"></i>
                        Back to Tags
                    </a>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="premium-banner premium-banner-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php $currentColor = $_POST['color'] ?? $tag['color'] ?? '#33cc33'; ?>
            <form method="POST" action="tag_edit.php?id=<?php echo $tagId; ?>" class="admin-form-card">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

                <section class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Tag Details</h2>
                        <p>Keep the tag name clear and short for repeated use across records.</p>
                    </div>
                    <div class="form-group">
                        <label for="name">Tag Name *</label>
                        <input
                            type="text"
                            id="name"
                            name="name"
                            required
                            maxlength="100"
                            value="<?php echo htmlspecialchars($_POST['name'] ?? $tag['name']); ?>"
                        >
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <textarea id="description" name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? $tag['description'] ?? ''); ?></textarea>
                    </div>
                </section>

                <section class="admin-form-section">
                    <div class="admin-form-section-header">
                        <h2>Color And Usage</h2>
                        <p>This tag is used <?php echo number_format($tag['usage_count'] ?? 0); ?> time<?php echo ($tag['usage_count'] ?? 0) !== 1 ? 's' : ''; ?>.</p>
                    </div>
                    <div class="utility-swatch-grid">
                        <?php foreach ($colorOptions as $color => $label): ?>
                            <label class="utility-color-option">
                                <input
                                    type="radio"
                                    name="color"
                                    value="<?php echo $color; ?>"
                                    <?php echo ($currentColor === $color) ? 'checked' : ''; ?>
                                    onchange="updateColorPreview()"
                                >
                                <span class="utility-color-swatch" style="--tag-color: <?php echo $color; ?>;"></span>
                                <span class="utility-color-label"><?php echo $label; ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-group">
                        <label for="color">Custom Hex Color *</label>
                        <input
                            type="text"
                            id="color"
                            name="color"
                            value="<?php echo htmlspecialchars($currentColor); ?>"
                            pattern="^#[0-9A-Fa-f]{6}$"
                        >
                    </div>
                </section>

                <div class="form-actions">
                    <a href="tags.php" class="btn-premium-secondary">Cancel</a>
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-save"></i>
                        Update Tag
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function updateColorPreview() {
    const selected = document.querySelector('input[name="color"]:checked');
    if (selected) {
        document.getElementById('color').value = selected.value;
    }
}

document.getElementById('color').addEventListener('input', function() {
    const value = this.value;
    const radio = document.querySelector(`input[type="radio"][value="${value}"]`);
    if (radio) {
        radio.checked = true;
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
