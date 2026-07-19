<?php
/**
 * Document Categories Management Page
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
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Security;
use CRM\Modules\DocumentCategories;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication and document admin access
if (!Auth::check() || !Authorization::can('documents.manage_all', Auth::user())) {
    header('Location: dashboard.php');
    exit;
}

$categoriesModule = new DocumentCategories();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            if (isset($_POST['create_category'])) {
                $categoryId = $categoriesModule->create([
                    'name' => $_POST['name'] ?? '',
                    'description' => $_POST['description'] ?? '',
                    'color' => $_POST['color'] ?? '#3B82F6',
                    'created_by' => $userId
                ]);
                header('Location: document_categories.php?success=created');
                exit;
            } elseif (isset($_POST['update_category'])) {
                $categoryId = (int) ($_POST['category_id'] ?? 0);
                $categoriesModule->update($categoryId, [
                    'name' => $_POST['name'] ?? '',
                    'description' => $_POST['description'] ?? '',
                    'color' => $_POST['color'] ?? '#3B82F6'
                ]);
                header('Location: document_categories.php?success=updated');
                exit;
            } elseif (isset($_POST['delete_category'])) {
                $categoryId = (int) ($_POST['category_id'] ?? 0);
                $categoriesModule->delete($categoryId);
                header('Location: document_categories.php?success=deleted');
                exit;
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get category to edit
$editCategory = null;
if (isset($_GET['edit'])) {
    $editCategory = $categoriesModule->getById((int) $_GET['edit']);
}

// Get all categories
$categories = $categoriesModule->getAll();

$pageTitle = 'Document Categories - ' . brandProductName();
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-xl);">
    <div>
        <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Document Categories</h1>
        <p style="color: var(--charcoal-grey);">Organize documents with categories</p>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div style="background: #efe; border: 1px solid #cfc; color: #3c3; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        <?php
        $messages = [
            'created' => 'Category created successfully!',
            'updated' => 'Category updated successfully!',
            'deleted' => 'Category deleted successfully!'
        ];
        echo $messages[$_GET['success']] ?? 'Operation completed successfully!';
        ?>
    </div>
<?php endif; ?>

<?php if (isset($error)): ?>
    <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        Error: <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-lg);">
    <!-- Create/Edit Category Form -->
    <div style="background: white; padding: var(--spacing-lg); border: 1px solid var(--border-color); border-radius: 8px;">
        <h2 style="color: var(--midnight-black); font-size: 20px; margin-bottom: var(--spacing-md);">
            <?php echo $editCategory ? 'Edit Category' : 'Create Category'; ?>
        </h2>
        
        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <?php if ($editCategory): ?>
                <input type="hidden" name="category_id" value="<?php echo $editCategory['id']; ?>">
            <?php endif; ?>
            
            <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
                <div>
                    <label for="name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500; font-size: 14px;">Category Name *</label>
                    <input 
                        type="text" 
                        id="name" 
                        name="name" 
                        required
                        value="<?php echo htmlspecialchars($editCategory['name'] ?? ''); ?>"
                        placeholder="e.g., Contracts, Invoices, Photos"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px;"
                    >
                </div>
                
                <div>
                    <label for="description" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500; font-size: 14px;">Description</label>
                    <textarea 
                        id="description" 
                        name="description" 
                        rows="3"
                        placeholder="Optional description for this category"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px; resize: vertical;"
                    ><?php echo htmlspecialchars($editCategory['description'] ?? ''); ?></textarea>
                </div>
                
                <div>
                    <label for="color" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500; font-size: 14px;">Color</label>
                    <div style="display: flex; gap: var(--spacing-sm); align-items: center;">
                        <input 
                            type="color" 
                            id="color" 
                            name="color" 
                            value="<?php echo htmlspecialchars($editCategory['color'] ?? '#3B82F6'); ?>"
                            style="width: 60px; height: 40px; border: 1px solid var(--border-color); border-radius: 4px; cursor: pointer;"
                        >
                        <input 
                            type="text" 
                            id="color_text" 
                            value="<?php echo htmlspecialchars($editCategory['color'] ?? '#3B82F6'); ?>"
                            pattern="^#[0-9A-Fa-f]{6}$"
                            placeholder="#3B82F6"
                            style="flex: 1; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px; font-family: monospace;"
                            onchange="document.getElementById('color').value = this.value"
                        >
                    </div>
                    <script>
                        document.getElementById('color').addEventListener('input', function(e) {
                            document.getElementById('color_text').value = e.target.value;
                        });
                        document.getElementById('color_text').addEventListener('input', function(e) {
                            if (/^#[0-9A-Fa-f]{6}$/.test(e.target.value)) {
                                document.getElementById('color').value = e.target.value;
                            }
                        });
                    </script>
                </div>
                
                <div style="display: flex; gap: var(--spacing-sm);">
                    <?php if ($editCategory): ?>
                        <button type="submit" name="update_category" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer; flex: 1;">
                            Update Category
                        </button>
                        <a href="document_categories.php" style="background: white; color: var(--charcoal-grey); padding: var(--spacing-sm) var(--spacing-md); border: 1px solid var(--border-color); border-radius: 4px; text-decoration: none; font-weight: 500; text-align: center;">
                            Cancel
                        </a>
                    <?php else: ?>
                        <button type="submit" name="create_category" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer; flex: 1;">
                            Create Category
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Categories List -->
    <div style="background: white; border: 1px solid var(--border-color); border-radius: 8px; overflow: hidden;">
        <div style="padding: var(--spacing-lg); border-bottom: 1px solid var(--border-color); background: var(--light-grey);">
            <h2 style="color: var(--midnight-black); font-size: 20px; margin: 0;">Existing Categories</h2>
        </div>
        
        <?php if (empty($categories)): ?>
            <div style="padding: var(--spacing-xl); text-align: center; color: var(--charcoal-grey);">
                <p>No categories created yet.</p>
                <p style="font-size: 14px; margin-top: var(--spacing-sm);">Create your first category using the form on the left.</p>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column;">
                <?php foreach ($categories as $category): ?>
                    <div style="padding: var(--spacing-md); border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                        <div style="display: flex; align-items: center; gap: var(--spacing-sm); flex: 1;">
                            <div style="width: 20px; height: 20px; border-radius: 4px; background: <?php echo htmlspecialchars($category['color']); ?>; border: 1px solid var(--border-color);"></div>
                            <div>
                                <div style="font-weight: 600; color: var(--midnight-black); font-size: 16px;">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </div>
                                <?php if ($category['description']): ?>
                                    <div style="color: var(--charcoal-grey); font-size: 12px; margin-top: var(--spacing-xs);">
                                        <?php echo htmlspecialchars($category['description']); ?>
                                    </div>
                                <?php endif; ?>
                                <div style="color: var(--charcoal-grey); font-size: 12px; margin-top: var(--spacing-xs);">
                                    <?php echo (int) ($category['document_count'] ?? 0); ?> document<?php echo (int) ($category['document_count'] ?? 0) !== 1 ? 's' : ''; ?>
                                </div>
                            </div>
                        </div>
                        <div style="display: flex; gap: var(--spacing-sm);">
                            <a href="document_categories.php?edit=<?php echo $category['id']; ?>" style="background: var(--accent-blue); color: white; padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; font-size: 14px; font-weight: 500;">
                                Edit
                            </a>
                            <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this category? Documents in this category will be uncategorized.');">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                <button type="submit" name="delete_category" style="background: #c33; color: white; padding: var(--spacing-xs) var(--spacing-md); border: none; border-radius: 4px; font-size: 14px; font-weight: 500; cursor: pointer;">
                                    Delete
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
