<?php
/**
 * Event View Page
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
use CRM\Modules\Events;
use CRM\Modules\Notes;
use CRM\Modules\Documents;
use CRM\Security;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$canManageAllNotes = Authorization::can('notes.manage_all', $user);
$canManageAllDocuments = Authorization::can('documents.manage_all', $user);
$eventsModule = new Events();
$notesModule = new Notes();
$documentsModule = new Documents();
$eventId = (int) ($_GET['id'] ?? 0);

if (!$eventId) {
    header('Location: calendar.php');
    exit;
}

$event = $eventsModule->getByIdForUser($eventId, $user);

if (!$event) {
    header('Location: calendar.php');
    exit;
}

// Get event notes
$eventNotes = $notesModule->getEntityNotes('event', $eventId, false, $userId);

// Get event documents
$eventDocuments = $documentsModule->getEntityDocuments('event', $eventId);

// Handle note creation
$noteError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_note') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $notesModule->create([
                'entity_type' => 'event',
                'entity_id' => $eventId,
                'title' => $_POST['note_title'] ?? null,
                'content' => $_POST['note_content'] ?? '',
                'content_html' => $_POST['note_content_html'] ?? null,
                'is_private' => isset($_POST['note_private']) ? 1 : 0,
                'created_by' => $userId
            ]);
            $eventNotes = $notesModule->getEntityNotes('event', $eventId, false, $userId);
        } catch (\Exception $e) {
            $noteError = $e->getMessage();
        }
    }
}

// Handle note deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_note') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $noteId = (int) ($_POST['note_id'] ?? 0);
        $note = $notesModule->getById($noteId);
        if ($note && ($note['created_by'] == $userId || $canManageAllNotes)) {
            $notesModule->delete($noteId);
            $eventNotes = $notesModule->getEntityNotes('event', $eventId, false, $userId);
        }
    }
}

$eventTypeColors = [
    'meeting' => '#3c3',
    'call' => '#f90',
    'email' => '#36c',
    'task' => '#999',
    'other' => '#c33'
];

$statusColors = [
    'scheduled' => '#3c3',
    'completed' => '#999',
    'cancelled' => '#c33',
    'postponed' => '#f90'
];

$pageTitle = 'Event Details - ' . brandProductName();
ob_start();
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-xl);">
    <div>
        <h1 style="color: var(--midnight-black); margin-bottom: var(--spacing-sm);">Event Details</h1>
        <p style="color: var(--charcoal-grey);">View and manage event information</p>
    </div>
    <div style="display: flex; gap: var(--spacing-sm);">
        <a href="event_edit.php?id=<?php echo $eventId; ?>" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-md); border-radius: 4px; text-decoration: none; font-weight: 500;">
            Edit Event
        </a>
        <a href="calendar.php" style="background: white; color: var(--charcoal-grey); padding: var(--spacing-sm) var(--spacing-md); border: 1px solid var(--border-color); border-radius: 4px; text-decoration: none; font-weight: 500;">
            Back to Calendar
        </a>
    </div>
</div>

<?php if (isset($_GET['success'])): ?>
    <div style="background: #efe; border: 1px solid #cfc; color: #3c3; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
        Event <?php echo $_GET['success'] === 'created' ? 'created' : 'updated'; ?> successfully!
    </div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: var(--spacing-lg);">
    <!-- Main Content -->
    <div style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
        <!-- Event Details -->
        <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px;">
            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-lg);">
                <h2 style="color: var(--midnight-black); font-size: 24px; margin: 0;">
                    <?php echo htmlspecialchars($event['title']); ?>
                </h2>
                <div style="display: flex; gap: var(--spacing-sm);">
                    <span style="background: <?php echo $eventTypeColors[$event['event_type']] ?? '#999'; ?>; color: white; padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase;">
                        <?php echo htmlspecialchars($event['event_type']); ?>
                    </span>
                    <span style="background: <?php echo $statusColors[$event['status']] ?? '#999'; ?>; color: white; padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; text-transform: uppercase;">
                        <?php echo htmlspecialchars($event['status']); ?>
                    </span>
                </div>
            </div>
            
            <?php if ($event['description']): ?>
                <div style="margin-bottom: var(--spacing-lg);">
                    <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); font-weight: 500;">Description</div>
                    <div style="color: var(--midnight-black); line-height: 1.6; white-space: pre-wrap;">
                        <?php echo htmlspecialchars($event['description']); ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md); margin-top: var(--spacing-lg);">
                <div>
                    <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); font-weight: 500;"><?php echo !empty($event['is_all_day']) ? 'Date' : 'Start Time'; ?></div>
                    <div style="color: var(--midnight-black); font-weight: 500; font-size: 18px;">
                        <?php
                        if (!empty($event['is_all_day'])) {
                            echo htmlspecialchars($eventsModule->formatEventDateTimeRange($event));
                        } else {
                            echo date('M d, Y g:i A', strtotime($event['start_time']));
                        }
                        ?>
                    </div>
                </div>
                
                <?php if ($event['end_time'] && empty($event['is_all_day'])): ?>
                    <div>
                        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); font-weight: 500;">End Time</div>
                        <div style="color: var(--midnight-black); font-weight: 500; font-size: 18px;">
                            <?php echo date('M d, Y g:i A', strtotime($event['end_time'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if ($event['location']): ?>
                <div style="margin-top: var(--spacing-md);">
                    <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs); font-weight: 500;">Location</div>
                    <div style="color: var(--midnight-black); font-weight: 500;">
                        📍 <?php echo htmlspecialchars($event['location']); ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Sidebar -->
    <div style="display: flex; flex-direction: column; gap: var(--spacing-lg);">
        <!-- Event Info -->
        <div style="background: white; padding: var(--spacing-lg); border: 1px solid var(--border-color); border-radius: 8px;">
            <h3 style="color: var(--midnight-black); font-size: 18px; margin-bottom: var(--spacing-md);">Event Information</h3>
            
            <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
                <div>
                    <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Schedule</div>
                    <div style="color: var(--midnight-black); font-weight: 500;">
                        <?php echo htmlspecialchars($eventsModule->formatEventTimeLabel($event)); ?>
                    </div>
                </div>

                <div>
                    <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Assigned To</div>
                    <div style="color: var(--midnight-black); font-weight: 500;">
                        <?php echo $event['assigned_to_email'] ? htmlspecialchars($event['assigned_to_email']) : '<span style="color: var(--charcoal-grey);">Unassigned</span>'; ?>
                    </div>
                </div>
                
                <div>
                    <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Related Contact</div>
                    <div style="color: var(--midnight-black); font-weight: 500;">
                        <?php if ($event['contact_id']): ?>
                            <a href="contact_view.php?id=<?php echo $event['contact_id']; ?>" style="color: var(--accent-blue); text-decoration: none;">
                                <?php echo htmlspecialchars(($event['contact_first_name'] ?? '') . ' ' . ($event['contact_last_name'] ?? '')); ?>
                            </a>
                        <?php else: ?>
                            <span style="color: var(--charcoal-grey);">None</span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div>
                    <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Created By</div>
                    <div style="color: var(--midnight-black); font-weight: 500;">
                        <?php echo htmlspecialchars($event['created_by_email'] ?? 'Unknown'); ?>
                    </div>
                </div>
                
                <div>
                    <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Created At</div>
                    <div style="color: var(--midnight-black); font-weight: 500;">
                        <?php echo date('M d, Y g:i A', strtotime($event['created_at'])); ?>
                    </div>
                </div>
                
                <?php if ($event['reminder_minutes']): ?>
                    <div>
                        <div style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">Reminder</div>
                        <div style="color: var(--midnight-black); font-weight: 500;">
                            <?php 
                            if ($event['reminder_minutes'] < 60) {
                                echo $event['reminder_minutes'] . ' minutes before';
                            } elseif ($event['reminder_minutes'] < 1440) {
                                echo ($event['reminder_minutes'] / 60) . ' hour(s) before';
                            } else {
                                echo ($event['reminder_minutes'] / 1440) . ' day(s) before';
                            }
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Notes Section -->
    <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-top: var(--spacing-lg);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-md);">
            <h2 style="color: var(--midnight-black); font-size: 20px; margin: 0;">Notes</h2>
            <span style="color: var(--charcoal-grey); font-size: 14px;">
                <?php echo count($eventNotes); ?> note<?php echo count($eventNotes) !== 1 ? 's' : ''; ?>
            </span>
        </div>
        
        <!-- Create Note Form -->
        <div style="background: var(--light-grey); padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
            <?php if ($noteError): ?>
                <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-sm); border-radius: 4px; margin-bottom: var(--spacing-sm); font-size: 14px;">
                    <?php echo htmlspecialchars($noteError); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-sm);">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="action" value="create_note">
                
                <input 
                    type="text" 
                    name="note_title" 
                    placeholder="Note title (optional)"
                    style="padding: var(--spacing-xs) var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px;"
                >
                
                <div class="rich-text-editor" style="margin-bottom: var(--spacing-sm);">
                    <textarea 
                        name="note_content" 
                        required
                        rows="3"
                        placeholder="Add a note about this event..."
                        style="display: none;"
                    ></textarea>
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <label style="display: flex; align-items: center; gap: var(--spacing-xs); cursor: pointer; font-size: 14px; color: var(--charcoal-grey);">
                        <input 
                            type="checkbox" 
                            name="note_private" 
                            value="1"
                            style="width: 16px; height: 16px;"
                        >
                        <span>Private note (only visible to me)</span>
                    </label>
                    <button 
                        type="submit" 
                        style="background: var(--accent-blue); color: white; padding: var(--spacing-xs) var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer; font-size: 14px;"
                    >
                        Add Note
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Notes List -->
        <?php if (empty($eventNotes)): ?>
            <p style="color: var(--charcoal-grey); text-align: center; padding: var(--spacing-lg);">
                No notes yet. Add your first note above.
            </p>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: var(--spacing-md);">
                <?php foreach ($eventNotes as $note): ?>
                    <div style="padding: var(--spacing-md); border: 1px solid var(--border-color); border-radius: 4px; background: white;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-sm);">
                            <div>
                                <?php if ($note['title']): ?>
                                    <div style="font-weight: 600; color: var(--midnight-black); margin-bottom: var(--spacing-xs); font-size: 16px;">
                                        <?php echo htmlspecialchars($note['title']); ?>
                                    </div>
                                <?php endif; ?>
                                <div style="color: var(--charcoal-grey); font-size: 12px;">
                                    <?php echo htmlspecialchars($note['created_by_email'] ?? 'Unknown'); ?>
                                    <?php if ($note['is_private']): ?>
                                        <span style="color: #f90; margin-left: var(--spacing-xs);">🔒 Private</span>
                                    <?php endif; ?>
                                    • <?php echo date('M j, Y g:i A', strtotime($note['created_at'])); ?>
                                </div>
                            </div>
                            <?php if ($note['created_by'] == $userId || $canManageAllNotes): ?>
                                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this note?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="delete_note">
                                    <input type="hidden" name="note_id" value="<?php echo $note['id']; ?>">
                                    <button 
                                        type="submit" 
                                        style="background: none; border: none; color: #c33; cursor: pointer; font-size: 12px; padding: 4px 8px;"
                                        title="Delete note"
                                    >
                                        Delete
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div style="color: var(--midnight-black); font-size: 14px; line-height: 1.6; white-space: pre-wrap;">
                            <?php 
                            $content = $note['content'];
                            if (!empty($content) && (strpos($content, '<p>') !== false || strpos($content, '<br>') !== false || strpos($content, '<strong>') !== false || strpos($content, '<em>') !== false)) {
                                echo strip_tags($content, '<p><br><strong><b><em><i><u><s><h1><h2><h3><ul><ol><li><a><span>');
                            } else {
                                echo nl2br(htmlspecialchars($content));
                            }
                            ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Documents Section -->
    <div style="background: white; padding: var(--spacing-xl); border: 1px solid var(--border-color); border-radius: 8px; margin-top: var(--spacing-lg);">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-md);">
            <h2 style="color: var(--midnight-black); font-size: 20px; margin: 0;">Documents</h2>
            <span style="color: var(--charcoal-grey); font-size: 14px;">
                <?php echo count($eventDocuments); ?> file<?php echo count($eventDocuments) !== 1 ? 's' : ''; ?>
            </span>
        </div>
        
        <!-- Upload Form -->
        <div style="background: var(--light-grey); padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
            <form class="documentUploadForm" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: var(--spacing-sm);">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="entity_type" value="event">
                <input type="hidden" name="entity_id" value="<?php echo $eventId; ?>">
                
                <div>
                    <input 
                        type="file" 
                        name="file" 
                        required
                        accept="*/*"
                        style="width: 100%; padding: var(--spacing-xs); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px;"
                    >
                    <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                        Maximum file size: 10MB
                    </small>
                </div>
                
                <input 
                    type="text" 
                    name="description" 
                    placeholder="Description (optional)"
                    style="padding: var(--spacing-xs) var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; font-size: 14px;"
                >
                
                <button 
                    type="submit" 
                    style="background: var(--accent-blue); color: white; padding: var(--spacing-xs) var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer; font-size: 14px; align-self: flex-start;"
                >
                    Upload Document
                </button>
            </form>
        </div>
        
        <!-- Documents List -->
        <?php if (empty($eventDocuments)): ?>
            <p style="color: var(--charcoal-grey); text-align: center; padding: var(--spacing-lg);">
                No documents yet. Upload your first document above.
            </p>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: var(--spacing-md);">
                <?php foreach ($eventDocuments as $doc): ?>
                    <div style="padding: var(--spacing-md); border: 1px solid var(--border-color); border-radius: 4px; background: white;">
                        <div style="display: flex; align-items: start; gap: var(--spacing-sm); margin-bottom: var(--spacing-sm);">
                            <div style="font-size: 32px;">
                                <?php echo $documentsModule->getFileIcon($doc['mime_type'] ?? ''); ?>
                            </div>
                            <div style="flex: 1; min-width: 0;">
                                <div style="font-weight: 500; color: var(--midnight-black); margin-bottom: var(--spacing-xs); font-size: 14px; word-break: break-word;">
                                    <?php echo htmlspecialchars($doc['original_name']); ?>
                                </div>
                                <div style="color: var(--charcoal-grey); font-size: 12px;">
                                    <?php echo $documentsModule->formatFileSize($doc['file_size']); ?>
                                </div>
                                <?php if ($doc['description']): ?>
                                    <div style="color: var(--charcoal-grey); font-size: 12px; margin-top: var(--spacing-xs);">
                                        <?php echo htmlspecialchars($doc['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: var(--spacing-sm); padding-top: var(--spacing-sm); border-top: 1px solid var(--border-color);">
                            <div style="color: var(--charcoal-grey); font-size: 11px;">
                                <?php echo htmlspecialchars($doc['uploaded_by_email'] ?? 'Unknown'); ?><br>
                                <?php echo date('M j, Y', strtotime($doc['created_at'])); ?>
                            </div>
                            <div style="display: flex; gap: var(--spacing-xs);">
                                <a 
                                    href="document_download.php?id=<?php echo $doc['id']; ?>" 
                                    style="color: var(--accent-blue); text-decoration: none; font-size: 12px; font-weight: 500;"
                                >
                                    Download
                                </a>
                                <?php if ($doc['uploaded_by'] == $userId || $canManageAllDocuments): ?>
                                    <button 
                                        onclick="deleteDocument(<?php echo $doc['id']; ?>)" 
                                        style="background: none; border: none; color: #c33; cursor: pointer; font-size: 12px; padding: 0;"
                                    >
                                        Delete
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelector('.documentUploadForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    
    submitBtn.disabled = true;
    submitBtn.textContent = 'Uploading...';
    
    try {
        const response = await fetch('document_upload.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Upload failed'));
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    } catch (error) {
        alert('Error: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    }
});

async function deleteDocument(docId) {
    if (!confirm('Are you sure you want to delete this document?')) {
        return;
    }
    
    const formData = new FormData();
    formData.append('csrf_token', '<?php echo Security::getCsrfToken(); ?>');
    formData.append('document_id', docId);
    
    try {
        const response = await fetch('document_delete.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Delete failed'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
