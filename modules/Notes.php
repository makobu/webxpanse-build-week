<?php
/**
 * Notes Management Module
 * 
 * Handles notes and comments on entities
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceScopeService;

class Notes
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
    }

    /**
     * Create a new note
     */
    public function create(array $data): int
    {
        $workspaceId = $this->resolveEntityWorkspaceId(
            (string) ($data['entity_type'] ?? ''),
            (int) ($data['entity_id'] ?? 0)
        );
        // Validate required fields
        if (empty($data['content']) || empty($data['entity_type']) || !isset($data['entity_id'])) {
            throw new \Exception("Note content, entity type, and entity ID are required");
        }
        
        // Sanitize inputs
        $entityType = Security::sanitizeInput($data['entity_type'], 'string');
        $entityId = (int) $data['entity_id'];
        $title = !empty($data['title']) ? Security::sanitizeInput($data['title'], 'string') : null;
        
        // Handle rich text content - accept HTML if provided, otherwise use plain text
        $content = '';
        if (!empty($data['content_html'])) {
            // HTML content from rich text editor
            $content = self::sanitizeHtml($data['content_html']);
        } elseif (!empty($data['content'])) {
            // Check if it's JSON (Quill Delta format) or plain text
            $decoded = json_decode($data['content'], true);
            if (json_last_error() === JSON_ERROR_NONE && isset($decoded['ops'])) {
                // It's a Quill Delta - convert to HTML
                $content = self::sanitizeHtml(self::deltaToHtml($decoded));
            } else {
                // Plain text - sanitize and convert to HTML
                $content = nl2br(htmlspecialchars($data['content'], ENT_QUOTES, 'UTF-8'));
            }
        }
        
        $isPrivate = isset($data['is_private']) ? (int) $data['is_private'] : 0;
        $createdBy = (int) ($data['created_by'] ?? $_SESSION['user_id'] ?? 0);
        $parentId = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;
        
        // Validate entity type
        $allowedTypes = ['contact', 'task', 'event', 'email', 'activity', 'deal'];
        if (!in_array($entityType, $allowedTypes)) {
            throw new \Exception("Invalid entity type");
        }
        
        Database::execute(
            "INSERT INTO notes (workspace_id, entity_type, entity_id, parent_id, title, content, is_private, created_by) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $entityType, $entityId, $parentId, $title, $content, $isPrivate, $createdBy]
        );
        
        $noteId = (int) Database::lastInsertId();
        
        // Parse and store mentions
        $this->processMentions($noteId, $content, $createdBy);
        
        return $noteId;
    }
    
    /**
     * Parse @mentions from content and store them
     */
    private function processMentions(int $noteId, string $content, int $createdBy): void
    {
        // Extract @mentions from HTML content
        // Pattern: @username or @email (also handle HTML tags)
        // First, extract text content to find mentions
        $textContent = strip_tags($content);
        preg_match_all('/@([a-zA-Z0-9._-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})|@([a-zA-Z0-9._-]+)/', $textContent, $matches);
        
        $mentionedEmails = [];
        $mentionedUsernames = [];
        
        // Collect email mentions
        if (!empty($matches[1])) {
            foreach ($matches[1] as $email) {
                if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $mentionedEmails[] = $email;
                }
            }
        }
        
        // Collect username mentions (convert to emails by looking up users)
        if (!empty($matches[2])) {
            foreach ($matches[2] as $username) {
                if (!empty($username)) {
                    $mentionedUsernames[] = $username;
                }
            }
        }
        
        // Look up users by email or username
        $userIds = [];
        
        if (!empty($mentionedEmails)) {
            $users = Database::query(
                "SELECT u.id
                 FROM users u
                 INNER JOIN workspace_memberships wm ON wm.user_id = u.id
                 WHERE wm.workspace_id = ?
                   AND wm.membership_status = 'active'
                   AND u.email IN (" . implode(',', array_fill(0, count($mentionedEmails), '?')) . ")",
                array_merge([$this->workspaceId()], $mentionedEmails)
            );
            foreach ($users as $user) {
                $userId = (int) $user['id'];
                if ($userId !== $createdBy && !in_array($userId, $userIds)) {
                    $userIds[] = $userId;
                }
            }
        }
        
        if (!empty($mentionedUsernames)) {
            // Try to match by email prefix (before @) or by partial email match
            foreach ($mentionedUsernames as $username) {
                $users = Database::query(
                    "SELECT u.id
                     FROM users u
                     INNER JOIN workspace_memberships wm ON wm.user_id = u.id
                     WHERE wm.workspace_id = ?
                       AND wm.membership_status = 'active'
                       AND (u.email LIKE ? OR u.email LIKE ?)",
                    [$this->workspaceId(), $username . '@%', '%' . $username . '%']
                );
                foreach ($users as $user) {
                    $userId = (int) $user['id'];
                    if ($userId !== $createdBy && !in_array($userId, $userIds)) {
                        $userIds[] = $userId;
                    }
                }
            }
        }
        
        // Store mentions and create notifications
        if (!empty($userIds)) {
            $notificationsModule = new \CRM\Modules\Notifications();
            
            foreach ($userIds as $userId) {
                // Store mention
                try {
                    Database::execute(
                        "INSERT INTO note_mentions (note_id, user_id) VALUES (?, ?)",
                        [$noteId, $userId]
                    );
                } catch (\Exception $e) {
                    // Ignore duplicate mentions
                }
                
                // Create notification
                $notificationsModule->create(
                    $userId,
                    'note_mention',
                    'You were mentioned in a note',
                    "You were mentioned in a note",
                    [
                        'entity_type' => 'note',
                        'entity_id' => $noteId,
                        'link' => "#note-{$noteId}"
                    ]
                );
            }
        }
    }
    
    /**
     * Get note by ID
     */
    public function getById(int $id): ?array
    {
        $note = Database::queryOne(
            "SELECT n.*, u.email as created_by_email
             FROM notes n
             LEFT JOIN users u ON n.created_by = u.id
             WHERE n.workspace_id = ? AND n.id = ?",
            [$this->workspaceId(), $id]
        );
        
        if ($note) {
            $note['mentions'] = $this->getMentions($id);
        }
        
        return $note;
    }
    
    /**
     * Get mentions for a note
     */
    public function getMentions(int $noteId): array
    {
        return Database::query(
            "SELECT nm.*, u.email as user_email, u.id as user_id
             FROM note_mentions nm
             LEFT JOIN users u ON nm.user_id = u.id
             INNER JOIN notes n ON n.id = nm.note_id
             WHERE n.workspace_id = ? AND nm.note_id = ?
             ORDER BY nm.created_at ASC",
            [$this->workspaceId(), $noteId]
        );
    }
    
    /**
     * Update note
     */
    public function update(int $id, array $data): bool
    {
        $note = $this->getById($id);
        if (!$note) {
            throw new \Exception("Note not found");
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['title'])) {
            $updates[] = "title = ?";
            $params[] = Security::sanitizeInput($data['title'], 'string');
        }
        
        if (isset($data['content'])) {
            $updates[] = "content = ?";
            $params[] = Security::sanitizeInput($data['content'], 'string');
        } elseif (isset($data['content_html'])) {
            $updates[] = "content = ?";
            $params[] = self::sanitizeHtml((string) $data['content_html']);
        }
        
        if (isset($data['is_private'])) {
            $updates[] = "is_private = ?";
            $params[] = (int) $data['is_private'];
        }
        
        if (empty($updates)) {
            return false;
        }
        
        Database::execute(
            "UPDATE notes SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND id = ?",
            array_merge($params, [$this->workspaceId(), $id])
        );
        
        // Reprocess mentions if content was updated
        if (isset($data['content']) || isset($data['content_html'])) {
            // Delete old mentions
            Database::execute(
                "DELETE nm
                 FROM note_mentions nm
                 INNER JOIN notes n ON n.id = nm.note_id
                 WHERE n.workspace_id = ? AND nm.note_id = ?",
                [$this->workspaceId(), $id]
            );
            
            // Process new mentions
            $content = $data['content'] ?? $data['content_html'] ?? '';
            if (!empty($content)) {
                $note = $this->getById($id);
                if ($note) {
                    $this->processMentions($id, $content, $note['created_by']);
                }
            }
        }
        
        return true;
    }
    
    /**
     * Delete note
     */
    public function delete(int $id): bool
    {
        $note = $this->getById($id);
        if (!$note) {
            return false;
        }
        
        Database::execute("DELETE FROM notes WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $id]);
        
        return true;
    }
    
    /**
     * Get notes for entity
     */
    public function getEntityNotes(string $entityType, int $entityId, bool $includePrivate = false, int $userId = null): array
    {
        $where = ["n.workspace_id = ?", "n.entity_type = ?", "n.entity_id = ?", "n.parent_id IS NULL"];
        $params = [$this->workspaceId(), $entityType, $entityId];
        
        if (!$includePrivate) {
            if ($userId) {
                // Include private notes created by the user
                $where[] = "(n.is_private = 0 OR (n.is_private = 1 AND n.created_by = ?))";
                $params[] = $userId;
            } else {
                $where[] = "n.is_private = 0";
            }
        }
        
        $notes = Database::query(
            "SELECT n.*, u.email as created_by_email
             FROM notes n
             LEFT JOIN users u ON n.created_by = u.id
             WHERE " . implode(" AND ", $where) . "
             ORDER BY n.created_at ASC",
            $params
        );
        
        // Load mentions and replies for each note
        foreach ($notes as &$note) {
            $note['mentions'] = $this->getMentions($note['id']);
            $note['replies'] = $this->getReplies($note['id'], $includePrivate, $userId);
        }
        unset($note);
        
        return $notes;
    }
    
    /**
     * Get replies for a parent note
     */
    public function getReplies(int $parentId, bool $includePrivate = false, int $userId = null): array
    {
        $where = ["n.workspace_id = ?", "n.parent_id = ?"];
        $params = [$this->workspaceId(), $parentId];
        
        if (!$includePrivate) {
            if ($userId) {
                $where[] = "(n.is_private = 0 OR (n.is_private = 1 AND n.created_by = ?))";
                $params[] = $userId;
            } else {
                $where[] = "n.is_private = 0";
            }
        }
        
        return Database::query(
            "SELECT n.*, u.email as created_by_email
             FROM notes n
             LEFT JOIN users u ON n.created_by = u.id
             WHERE " . implode(" AND ", $where) . "
             ORDER BY n.created_at ASC",
            $params
        );
    }
    
    /**
     * Add reply to a parent note
     */
    public function addReply(int $parentId, string $content, int $createdBy = null): int
    {
        $parent = $this->getById($parentId);
        if (!$parent) {
            throw new \Exception("Parent note not found");
        }
        return $this->create([
            'entity_type' => $parent['entity_type'],
            'entity_id' => $parent['entity_id'],
            'parent_id' => $parentId,
            'content' => $content,
            'created_by' => $createdBy ?? $_SESSION['user_id'] ?? 0
        ]);
    }
    
    /**
     * Get recent notes
     */
    public function getRecent(int $limit = 10, int $userId = null): array
    {
        $where = ["n.workspace_id = ?"];
        $params = [$this->workspaceId()];
        
        if ($userId) {
            // Include private notes created by the user
            $where[] = "(n.is_private = 0 OR (n.is_private = 1 AND n.created_by = ?))";
            $params[] = $userId;
        } else {
            $where[] = "n.is_private = 0";
        }
        
        $sql = "SELECT n.*, u.email as created_by_email
                FROM notes n
                LEFT JOIN users u ON n.created_by = u.id";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " ORDER BY n.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        return Database::query($sql, $params);
    }
    
    /**
     * Get note count for entity
     */
    public function getEntityNoteCount(string $entityType, int $entityId, bool $includePrivate = false, int $userId = null): int
    {
        $where = ["workspace_id = ?", "entity_type = ?", "entity_id = ?"];
        $params = [$this->workspaceId(), $entityType, $entityId];
        
        if (!$includePrivate) {
            if ($userId) {
                $where[] = "(is_private = 0 OR (is_private = 1 AND created_by = ?))";
                $params[] = $userId;
            } else {
                $where[] = "is_private = 0";
            }
        }
        
        $result = Database::queryOne(
            "SELECT COUNT(*) as count FROM notes WHERE " . implode(" AND ", $where),
            $params
        );
        
        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Get notes by user
     */
    public function getByUser(int $userId, int $limit = 50, int $offset = 0): array
    {
        return Database::query(
            "SELECT n.*, u.email as created_by_email
             FROM notes n
             LEFT JOIN users u ON n.created_by = u.id
             WHERE n.workspace_id = ? AND n.created_by = ?
             ORDER BY n.created_at DESC
             LIMIT ? OFFSET ?",
            [$this->workspaceId(), $userId, $limit, $offset]
        );
    }
    
    /**
     * Search notes
     */
    public function search(string $query, int $limit = 50, int $offset = 0): array
    {
        $searchTerm = '%' . Security::sanitizeInput($query, 'string') . '%';
        
        return Database::query(
            "SELECT n.*, u.email as created_by_email
             FROM notes n
             LEFT JOIN users u ON n.created_by = u.id
             WHERE n.workspace_id = ?
             AND (n.title LIKE ? OR n.content LIKE ?)
             AND n.is_private = 0
             ORDER BY n.created_at DESC
             LIMIT ? OFFSET ?",
            [$this->workspaceId(), $searchTerm, $searchTerm, $limit, $offset]
        );
    }
    
    /**
     * Sanitize HTML content - allow only safe HTML tags
     */
    private static function sanitizeHtml(string $html): string
    {
        // Allow safe HTML tags for rich text formatting
        $allowedTags = '<p><br><strong><b><em><i><u><s><h1><h2><h3><ul><ol><li><a><span>';
        
        // Strip all tags except allowed ones
        $html = strip_tags($html, $allowedTags);
        
        // Rebuild opening tags instead of trying to remove dangerous attributes
        // one pattern at a time. Rich-text formatting does not need arbitrary
        // attributes; links retain only a validated href.
        return preg_replace_callback(
            '/<([a-z][a-z0-9]*)[^>]*>/i',
            static function (array $matches): string {
                $tag = strtolower((string) $matches[1]);
                if ($tag !== 'a') {
                    return '<' . $tag . '>';
                }

                if (!preg_match('/\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', (string) $matches[0], $hrefMatch)) {
                    return '<a>';
                }

                $rawHref = (string) ($hrefMatch[1] ?? $hrefMatch[2] ?? $hrefMatch[3] ?? '');
                $href = trim(html_entity_decode($rawHref, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (!self::isSafeNoteHref($href)) {
                    return '<a>';
                }

                return '<a href="' . htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
            },
            $html
        ) ?? '';
    }

    private static function isSafeNoteHref(string $href): bool
    {
        if ($href === '' || str_contains($href, "\0") || preg_match('/[\x00-\x1F\x7F]/', $href)) {
            return false;
        }

        if (str_starts_with($href, '//')) {
            return false;
        }

        $scheme = parse_url($href, PHP_URL_SCHEME);
        if ($scheme === null) {
            return true;
        }

        return in_array(strtolower((string) $scheme), ['http', 'https', 'mailto', 'tel'], true);
    }
    
    /**
     * Convert Quill Delta format to HTML
     */
    private static function deltaToHtml(array $delta): string
    {
        if (!isset($delta['ops']) || !is_array($delta['ops'])) {
            return '';
        }
        
        $html = '';
        foreach ($delta['ops'] as $op) {
            if (!isset($op['insert'])) {
                continue;
            }
            
            $text = $op['insert'];
            $attributes = $op['attributes'] ?? [];
            
            // Handle line breaks
            if (is_string($text) && strpos($text, "\n") !== false) {
                $lines = explode("\n", $text);
                foreach ($lines as $i => $line) {
                    if ($i > 0) {
                        $html .= '<br>';
                    }
                    if (!empty($line)) {
                        $html .= self::formatText($line, $attributes);
                    }
                }
            } else {
                $html .= self::formatText($text, $attributes);
            }
        }
        
        // Wrap in paragraphs if needed
        if (!empty($html) && strpos($html, '<p>') === false && strpos($html, '<h') === false) {
            $html = '<p>' . $html . '</p>';
        }
        
        return $html;
    }
    
    /**
     * Format text with attributes
     */
    private static function formatText(string $text, array $attributes): string
    {
        $text = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        
        // Apply formatting
        if (isset($attributes['bold']) || isset($attributes['b'])) {
            $text = '<strong>' . $text . '</strong>';
        }
        if (isset($attributes['italic']) || isset($attributes['i'])) {
            $text = '<em>' . $text . '</em>';
        }
        if (isset($attributes['underline'])) {
            $text = '<u>' . $text . '</u>';
        }
        if (isset($attributes['strike'])) {
            $text = '<s>' . $text . '</s>';
        }
        
        // Handle headers
        if (isset($attributes['header'])) {
            $level = (int) $attributes['header'];
            $text = "<h{$level}>" . $text . "</h{$level}>";
        }
        
        // Handle links
        if (isset($attributes['link'])) {
            $url = htmlspecialchars($attributes['link'], ENT_QUOTES, 'UTF-8');
            $text = '<a href="' . $url . '">' . $text . '</a>';
        }
        
        // Handle lists
        if (isset($attributes['list'])) {
            $listType = $attributes['list'] === 'ordered' ? 'ol' : 'ul';
            $text = "<{$listType}><li>" . $text . "</li></{$listType}>";
        }
        
        return $text;
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    private function resolveEntityWorkspaceId(string $entityType, int $entityId): int
    {
        $workspaceId = $this->workspaceId();
        $entityTableMap = [
            'contact' => 'contacts',
            'task' => 'tasks',
            'event' => 'events',
            'activity' => 'activities',
            'deal' => 'deals',
        ];

        $table = $entityTableMap[$entityType] ?? null;
        if ($table !== null && $entityId > 0) {
            $this->workspaceScope->assertSameWorkspace($table, $entityId, $workspaceId);
        }

        return $workspaceId;
    }
}
