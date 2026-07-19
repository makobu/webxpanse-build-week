<?php
/**
 * Documents Management Module
 * 
 * Handles file uploads and document management
 */

namespace CRM\Modules;

use CRM\Authorization;
use CRM\Concurrency;
use CRM\ConcurrencyConflictException;
use CRM\Database;
use CRM\Security;
use CRM\Modules\UnifiedInbox;
use CRM\Services\TaskAssignmentAccessService;
use CRM\Services\WorkspaceScopeService;

class Documents
{
    private string $uploadDir;
    private static array $permissionAvailabilityCache = [];
    private WorkspaceScopeService $workspaceScope;
    private const ALLOWED_UPLOAD_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/csv' => 'csv',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];
    
    public function __construct()
    {
        $configuredDir = trim((string) ($_ENV['CRM_DOCUMENT_UPLOAD_DIR'] ?? ''));
        $this->uploadDir = $configuredDir !== ''
            ? rtrim($configuredDir, '/\\') . DIRECTORY_SEPARATOR
            : dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'crm-private' . DIRECTORY_SEPARATOR . 'documents' . DIRECTORY_SEPARATOR;
        $this->workspaceScope = new WorkspaceScopeService();
        if (!is_dir($this->uploadDir) && !mkdir($this->uploadDir, 0750, true) && !is_dir($this->uploadDir)) {
            throw new \RuntimeException('The private document storage directory is unavailable.');
        }
    }
    
    /**
     * Upload and create document record
     */
    public function upload(array $file, string $entityType, int $entityId, array $data = []): int
    {
        // Validate entity type
        $allowedTypes = ['contact', 'task', 'event', 'email', 'activity', 'deal', 'communication'];
        if (!in_array($entityType, $allowedTypes)) {
            throw new \Exception("Invalid entity type");
        }
        
        // Validate file
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \Exception("Invalid file upload");
        }
        
        // Validate file size (max 10MB)
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $maxSize) {
            throw new \Exception("File size exceeds maximum allowed size of 10MB");
        }

        $validatedFile = $this->validateUploadedFile($file);
        $mimeType = $validatedFile['mime_type'];
        $extension = $validatedFile['extension'];
        
        // Generate unique filename
        $fileName = uniqid('doc_', true) . '.' . $extension;
        $filePath = $this->uploadDir . $fileName;
        
        // Get file info
        $originalName = Security::sanitizeInput($file['name'], 'string');
        $fileSize = (int) $file['size'];
        $description = !empty($data['description']) ? Security::sanitizeInput($data['description'], 'string') : null;
        $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;
        $uploadedBy = (int) ($data['uploaded_by'] ?? $_SESSION['user_id'] ?? 0);

        if ($categoryId !== null && !$this->getCategory($categoryId)) {
            throw new \InvalidArgumentException('The selected document category is not available in this workspace.');
        }

        Database::beginTransaction();
        try {
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new \RuntimeException("Failed to save uploaded file");
            }

            Database::execute(
                "INSERT INTO documents (workspace_id, entity_type, entity_id, category_id, file_name, original_name, file_path, file_size, mime_type, description, uploaded_by, current_version)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)",
                [$this->workspaceId(), $entityType, $entityId, $categoryId, $fileName, $originalName, $filePath, $fileSize, $mimeType, $description, $uploadedBy]
            );

            $documentId = (int) Database::lastInsertId();
            $this->createVersion($documentId, 1, $fileName, $originalName, $filePath, $fileSize, $mimeType, $description, $uploadedBy);
            Database::commit();
            return $documentId;
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            if (is_file($filePath)) {
                @unlink($filePath);
            }
            throw $e;
        }
    }
    
    /**
     * Upload new version of existing document
     */
    public function uploadVersion(int $documentId, array $file, array $data = []): int
    {
        // Validate file
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            throw new \Exception("Invalid file upload");
        }
        
        // Validate file size (max 10MB)
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($file['size'] > $maxSize) {
            throw new \Exception("File size exceeds maximum allowed size of 10MB");
        }

        $validatedFile = $this->validateUploadedFile($file);
        $mimeType = $validatedFile['mime_type'];
        $extension = $validatedFile['extension'];

        // Get file info
        $originalName = Security::sanitizeInput($file['name'], 'string');
        $fileSize = (int) $file['size'];
        $description = !empty($data['description']) ? Security::sanitizeInput($data['description'], 'string') : null;
        $uploadedBy = (int) ($data['uploaded_by'] ?? $_SESSION['user_id'] ?? 0);
        $filePath = null;

        Database::beginTransaction();
        try {
            $doc = $this->lockDocument($documentId);
            if (!$doc) {
                throw new \Exception("Document not found");
            }

            $expectedVersion = Concurrency::expectedVersionFromData($data);
            if ($expectedVersion !== null && (int) ($doc['lock_version'] ?? 0) !== $expectedVersion) {
                throw new ConcurrencyConflictException('document', $documentId, $expectedVersion, $doc, [
                    'original_name' => $originalName,
                    'description' => $description,
                ]);
            }

            $latestVersion = max((int) ($doc['current_version'] ?? 1), $this->getMaxVersionNumber($documentId));
            $nextVersion = $latestVersion + 1;
            $fileName = uniqid('doc_v' . $nextVersion . '_', true) . '.' . $extension;
            $filePath = $this->uploadDir . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                throw new \Exception("Failed to save uploaded file");
            }

            $versionId = $this->createVersion($documentId, $nextVersion, $fileName, $originalName, $filePath, $fileSize, $mimeType, $description, $uploadedBy);
            Concurrency::executeWorkspaceUpdate(
                'documents',
                $this->workspaceId(),
                $documentId,
                [
                    'file_name = ?',
                    'original_name = ?',
                    'file_path = ?',
                    'file_size = ?',
                    'mime_type = ?',
                    'current_version = ?',
                    'updated_at = NOW()',
                ],
                [$fileName, $originalName, $filePath, $fileSize, $mimeType, $nextVersion],
                null,
                fn() => $this->getById($documentId),
                ['original_name' => $originalName, 'description' => $description],
                'document'
            );

            Database::commit();
            return $versionId;
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            if ($filePath !== null && is_file($filePath)) {
                @unlink($filePath);
            }
            throw $e;
        }
    }
    
    /**
     * Create version record
     */
    private function createVersion(int $documentId, int $versionNumber, string $fileName, string $originalName, string $filePath, int $fileSize, string $mimeType, ?string $description, int $uploadedBy): int
    {
        Database::execute(
            "INSERT INTO document_versions (document_id, version_number, file_name, original_name, file_path, file_size, mime_type, description, uploaded_by) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$documentId, $versionNumber, $fileName, $originalName, $filePath, $fileSize, $mimeType, $description, $uploadedBy]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get next version number for document
     */
    public function getNextVersionNumber(int $documentId): int
    {
        return $this->getMaxVersionNumber($documentId) + 1;
    }
    
    /**
     * Get version history for document
     */
    public function getVersionHistory(int $documentId): array
    {
        return Database::query(
            "SELECT dv.*, u.email as uploaded_by_email
             FROM document_versions dv
             INNER JOIN documents d ON d.id = dv.document_id AND d.workspace_id = ?
             LEFT JOIN users u ON dv.uploaded_by = u.id
             WHERE dv.document_id = ?
             ORDER BY dv.version_number DESC",
            [$this->workspaceId(), $documentId]
        );
    }
    
    /**
     * Get specific version
     */
    public function getVersion(int $documentId, int $versionNumber): ?array
    {
        return Database::queryOne(
            "SELECT dv.*, u.email as uploaded_by_email
             FROM document_versions dv
             INNER JOIN documents d ON d.id = dv.document_id AND d.workspace_id = ?
             LEFT JOIN users u ON dv.uploaded_by = u.id
             WHERE dv.document_id = ? AND dv.version_number = ?",
            [$this->workspaceId(), $documentId, $versionNumber]
        );
    }
    
    /**
     * Restore document to specific version
     */
    public function restoreVersion(int $documentId, int $versionNumber, ?int $expectedLockVersion = null): bool
    {
        $version = $this->getVersion($documentId, $versionNumber);
        if (!$version) {
            throw new \Exception("Version not found");
        }

        Concurrency::executeWorkspaceUpdate(
            'documents',
            $this->workspaceId(),
            $documentId,
            [
                'file_name = ?',
                'original_name = ?',
                'file_path = ?',
                'file_size = ?',
                'mime_type = ?',
                'current_version = ?',
                'updated_at = NOW()',
            ],
            [$version['file_name'], $version['original_name'], $version['file_path'], $version['file_size'], $version['mime_type'], $versionNumber],
            $expectedLockVersion,
            fn() => $this->getById($documentId),
            ['current_version' => $versionNumber],
            'document'
        );
        
        return true;
    }
    
    /**
     * Get document by ID
     */
    public function getById(int $id): ?array
    {
        $doc = Database::queryOne(
            "SELECT d.*, u.email as uploaded_by_email, dc.name as category_name, dc.color as category_color,
                    COALESCE(d.current_version, 1) as current_version
             FROM documents d
             LEFT JOIN users u ON d.uploaded_by = u.id
             LEFT JOIN document_categories dc ON d.category_id = dc.id AND dc.workspace_id = d.workspace_id
             WHERE d.workspace_id = ?
               AND d.id = ?",
            [$this->workspaceId(), $id]
        );
        
        return $doc;
    }

    public function canUserAccessDocument(?array $user, int $documentId, string $action = 'read'): bool
    {
        $document = $this->getById($documentId);
        if (!$document) {
            return false;
        }

        return $this->canUserAccessDocumentRecord($user, $document, $action);
    }

    public function canUserAccessDocumentRecord(?array $user, array $document, string $action = 'read'): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }

        if (Authorization::can('documents.manage_all', $user)) {
            return true;
        }

        $entityType = (string) ($document['entity_type'] ?? '');
        $entityId = (int) ($document['entity_id'] ?? 0);
        if ($entityType === '' || $entityId <= 0) {
            return false;
        }

        return $this->canUserAccessEntity($user, $entityType, $entityId, $action);
    }

    public function canUserAccessEntity(?array $user, string $entityType, int $entityId, string $action = 'read'): bool
    {
        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0 || $entityId <= 0) {
            return false;
        }

        if (Authorization::can('documents.manage_all', $user)) {
            return true;
        }

        switch ($entityType) {
            case 'contact':
                return $this->canAccessContactDocument($user, $entityId, $action);

            case 'deal':
                return $this->canAccessDealDocument($user, $entityId, $action);

            case 'task':
                $task = (new Tasks())->getById($entityId);
                if (!$this->matchesOwnerFields($task, $userId, ['assigned_to', 'created_by'])) {
                    return false;
                }
                $assignmentAccess = new TaskAssignmentAccessService();
                return $action === 'write'
                    ? $assignmentAccess->canWriteTasks($user)
                    : $assignmentAccess->canReadTasks($user);

            case 'event':
                return $this->canAccessEventDocument($user, $entityId);

            case 'communication':
                $canViewAll = Authorization::can('conversations.view_all', $user);
                $canAccess = (new UnifiedInbox())->canUserAccessCommunication($entityId, $userId, $canViewAll);
                if (!$canAccess) {
                    return false;
                }

                if ($action === 'write' && $this->permissionIsAvailable('conversations.write')) {
                    return Authorization::can('conversations.write', $user)
                        || Authorization::isLegacyFallbackMode($user);
                }

                return true;

            default:
                return false;
        }
    }
    
    /**
     * Update document
     */
    public function update(int $id, array $data): bool
    {
        $doc = $this->getById($id);
        if (!$doc) {
            throw new \Exception("Document not found");
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['description'])) {
            $updates[] = "description = ?";
            $params[] = Security::sanitizeInput($data['description'], 'string');
        }
        
        if (isset($data['category_id'])) {
            $categoryId = !empty($data['category_id']) ? (int) $data['category_id'] : null;
            if ($categoryId !== null && !$this->getCategory($categoryId)) {
                throw new \InvalidArgumentException('The selected document category is not available in this workspace.');
            }
            $updates[] = "category_id = ?";
            $params[] = $categoryId;
        }
        
        if (empty($updates)) {
            return false;
        }
        
        Concurrency::executeWorkspaceUpdate(
            'documents',
            $this->workspaceId(),
            $id,
            $updates,
            $params,
            Concurrency::expectedVersionFromData($data),
            fn() => $this->getById($id),
            array_intersect_key($data, array_flip(['description', 'category_id'])),
            'document'
        );
        
        return true;
    }
    
    /**
     * Delete document
     */
    public function delete(int $id): bool
    {
        $doc = $this->getById($id);
        if (!$doc) {
            return false;
        }
        
        // Delete all version files
        $versions = $this->getVersionHistory($id);
        foreach ($versions as $version) {
            if (file_exists($version['file_path'])) {
                @unlink($version['file_path']);
            }
        }
        
        // Delete physical file
        if (file_exists($doc['file_path'])) {
            @unlink($doc['file_path']);
        }
        
        // Delete database records (versions will be deleted by CASCADE)
        Database::execute("DELETE FROM documents WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $id]);
        
        return true;
    }
    
    /**
     * Get documents for entity
     */
    public function getEntityDocuments(string $entityType, int $entityId, ?int $categoryId = null): array
    {
        $where = ["d.workspace_id = ?", "d.entity_type = ?", "d.entity_id = ?"];
        $params = [$this->workspaceId(), $entityType, $entityId];
        
        if ($categoryId !== null) {
            $where[] = "d.category_id = ?";
            $params[] = $categoryId;
        }
        
        return Database::query(
            "SELECT d.*, u.email as uploaded_by_email, dc.name as category_name, dc.color as category_color
             FROM documents d
             LEFT JOIN users u ON d.uploaded_by = u.id
             LEFT JOIN document_categories dc ON d.category_id = dc.id AND dc.workspace_id = d.workspace_id
             WHERE " . implode(" AND ", $where) . "
             ORDER BY d.created_at DESC",
            $params
        );
    }
    
    /**
     * Get recent documents
     */
    public function getRecent(int $limit = 10, int $userId = null): array
    {
        $where = ["d.workspace_id = ?"];
        $params = [$this->workspaceId()];
        
        if ($userId) {
            $where[] = "d.uploaded_by = ?";
            $params[] = $userId;
        }
        
        $sql = "SELECT d.*, u.email as uploaded_by_email
                FROM documents d
                LEFT JOIN users u ON d.uploaded_by = u.id";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " ORDER BY d.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        return Database::query($sql, $params);
    }
    
    /**
     * Get document count for entity
     */
    public function getEntityDocumentCount(string $entityType, int $entityId): int
    {
        $result = Database::queryOne(
            "SELECT COUNT(*) as count FROM documents WHERE workspace_id = ? AND entity_type = ? AND entity_id = ?",
            [$this->workspaceId(), $entityType, $entityId]
        );
        
        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Format file size
     */
    public function formatFileSize(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    
    /**
     * Get file icon based on mime type
     */
    public function getFileIcon(string $mimeType): string
    {
        if (strpos($mimeType, 'image/') === 0) {
            return '🖼️';
        } elseif (strpos($mimeType, 'pdf') !== false) {
            return '📄';
        } elseif (strpos($mimeType, 'word') !== false || strpos($mimeType, 'document') !== false) {
            return '📝';
        } elseif (strpos($mimeType, 'excel') !== false || strpos($mimeType, 'spreadsheet') !== false) {
            return '📊';
        } elseif (strpos($mimeType, 'zip') !== false || strpos($mimeType, 'archive') !== false) {
            return '📦';
        } else {
            return '📎';
        }
    }
    
    /**
     * Check if file can be previewed
     */
    public function canPreview(string $mimeType): bool
    {
        // Images and PDFs can be previewed
        if (strpos($mimeType, 'image/') === 0) {
            return true;
        }
        if (strpos($mimeType, 'pdf') !== false || $mimeType === 'application/pdf') {
            return true;
        }
        return false;
    }
    
    /**
     * Get preview URL for document
     */
    public function getPreviewUrl(int $documentId): string
    {
        return publicUrl("document_preview.php?id={$documentId}");
    }
    
    /**
     * Get all categories
     */
    public function getCategories(): array
    {
        return Database::query(
            "SELECT dc.*, COUNT(d.id) as document_count
             FROM document_categories dc
             LEFT JOIN documents d ON dc.id = d.category_id AND d.workspace_id = dc.workspace_id
             WHERE dc.workspace_id = ?
             GROUP BY dc.id
             ORDER BY dc.name ASC",
            [$this->workspaceId()]
        );
    }
    
    /**
     * Get category by ID
     */
    public function getCategory(int $id): ?array
    {
        return Database::queryOne(
            "SELECT * FROM document_categories WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId(), $id]
        );
    }

    public function resolveManagedFilePath(string $storedPath): ?string
    {
        $root = realpath($this->uploadDir);
        $file = realpath($storedPath);
        if ($root === false || $file === false || !is_file($file)) {
            return null;
        }

        $prefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        return strncmp($file, $prefix, strlen($prefix)) === 0 ? $file : null;
    }

    private function validateUploadedFile(array $file): array
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo === false) {
            throw new \RuntimeException('Unable to validate uploaded file type.');
        }

        $mimeType = (string) finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $extension = self::ALLOWED_UPLOAD_MIME_TYPES[$mimeType] ?? null;
        if ($extension === null) {
            throw new \RuntimeException('Unsupported file type. Allowed files are PDF, Office documents, CSV, text, and common image formats.');
        }

        return [
            'mime_type' => $mimeType,
            'extension' => $extension,
        ];
    }

    private function matchesOwnerFields(?array $entity, int $userId, array $fields): bool
    {
        if (!$entity || $userId <= 0) {
            return false;
        }

        foreach ($fields as $field) {
            if ((int) ($entity[$field] ?? 0) === $userId) {
                return true;
            }
        }

        return false;
    }

    protected function canAccessContactDocument(?array $user, int $entityId, string $action): bool
    {
        $contact = (new Contacts())->getById($entityId);
        if (!$contact) {
            return false;
        }

        return $this->allowsEntityAction(
            $user,
            $action,
            'contacts.read',
            'contacts.write',
            $this->matchesOwnerFields($contact, (int) ($user['id'] ?? 0), ['assigned_to', 'created_by'])
        );
    }

    protected function canAccessDealDocument(?array $user, int $entityId, string $action): bool
    {
        $deal = (new Deals())->getById($entityId);
        if (!$deal) {
            return false;
        }

        return $this->allowsEntityAction(
            $user,
            $action,
            'deals.read',
            'deals.write',
            $this->matchesOwnerFields($deal, (int) ($user['id'] ?? 0), ['assigned_to', 'created_by'])
        );
    }

    protected function canAccessEventDocument(?array $user, int $entityId): bool
    {
        $event = (new Events())->getById($entityId);
        if (!$event) {
            return false;
        }

        // Events are still auth-gated across the product, so keep document access
        // aligned with the current event pages until dedicated RBAC keys exist.
        return true;
    }

    protected function allowsEntityAction(?array $user, string $action, string $readPermission, string $writePermission, bool $ownerMatch): bool
    {
        if (!$user || empty($user['id'])) {
            return false;
        }

        $permission = $action === 'write' ? $writePermission : $readPermission;
        if ($this->permissionIsAvailable($permission)) {
            if (Authorization::can($permission, $user)) {
                return true;
            }

            return Authorization::isLegacyFallbackMode($user) ? $ownerMatch : false;
        }

        return true;
    }

    protected function permissionIsAvailable(string $permissionKey): bool
    {
        if ($permissionKey === '' || !Authorization::isRbacAvailable()) {
            return false;
        }

        if (array_key_exists($permissionKey, self::$permissionAvailabilityCache)) {
            return self::$permissionAvailabilityCache[$permissionKey];
        }

        try {
            $row = Database::queryOne(
                "SELECT 1 FROM permissions WHERE permission_key = ? LIMIT 1",
                [$permissionKey]
            );
            self::$permissionAvailabilityCache[$permissionKey] = !empty($row);
        } catch (\Throwable $e) {
            self::$permissionAvailabilityCache[$permissionKey] = false;
        }

        return self::$permissionAvailabilityCache[$permissionKey];
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function lockDocument(int $documentId): ?array
    {
        return Database::queryOne(
            "SELECT * FROM documents WHERE workspace_id = ? AND id = ? LIMIT 1 FOR UPDATE",
            [$this->workspaceId(), $documentId]
        );
    }

    private function getMaxVersionNumber(int $documentId): int
    {
        $result = Database::queryOne(
            "SELECT MAX(dv.version_number) as max_version
             FROM document_versions dv
             INNER JOIN documents d ON d.id = dv.document_id AND d.workspace_id = ?
             WHERE dv.document_id = ?",
            [$this->workspaceId(), $documentId]
        );

        return (int) ($result['max_version'] ?? 0);
    }
}
