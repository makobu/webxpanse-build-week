<?php

namespace CRM\Services;

use CRM\Database;

class MarketplaceVideoAssetService
{
    private const MAX_VIDEO_BYTES = 50 * 1024 * 1024;
    private const RELATIVE_UPLOAD_ROOT = 'uploads/marketplace/page_video_library';

    /**
     * @var array<string,string>
     */
    private const MIME_EXTENSIONS = [
        'video/mp4' => 'mp4',
        'application/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/quicktime' => 'mov',
        'video/x-m4v' => 'm4v',
        'video/m4v' => 'm4v',
        'video/mp4v-es' => 'm4v',
    ];

    /**
     * @var array<string,string>
     */
    private const NAME_EXTENSIONS = [
        'mp4' => 'mp4',
        'webm' => 'webm',
        'mov' => 'mov',
        'm4v' => 'm4v',
    ];

    /**
     * @var array<string,string>
     */
    private const EXTENSION_MIMES = [
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'm4v' => 'video/x-m4v',
    ];

    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = rtrim((string) ($projectRoot ?: dirname(__DIR__)), "\\/");
    }

    public function tableReady(): bool
    {
        return Database::tableExists('marketplace_video_assets')
            && Database::tableExists('marketplace_page_explainers')
            && Database::columnExists('marketplace_page_explainers', 'video_asset_id');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listAssets(): array
    {
        if (!Database::tableExists('marketplace_video_assets')) {
            return [];
        }

        $join = Database::columnExists('marketplace_page_explainers', 'video_asset_id')
            ? "LEFT JOIN marketplace_page_explainers e ON e.video_asset_id = a.id"
            : '';
        $usageSelect = $join !== ''
            ? "COUNT(e.id) AS usage_count, GROUP_CONCAT(e.page_key ORDER BY e.page_key SEPARATOR ', ') AS used_page_keys"
            : "0 AS usage_count, NULL AS used_page_keys";

        return Database::query(
            "SELECT a.*, {$usageSelect}
             FROM marketplace_video_assets a
             {$join}
             GROUP BY a.id
             ORDER BY a.updated_at DESC, a.id DESC"
        );
    }

    public function getAsset(int $assetId): ?array
    {
        if ($assetId <= 0 || !Database::tableExists('marketplace_video_assets')) {
            return null;
        }

        return Database::queryOne(
            "SELECT * FROM marketplace_video_assets WHERE id = ? LIMIT 1",
            [$assetId]
        ) ?: null;
    }

    /**
     * @return array{uploaded:int,deduplicated:int,assets:array<int,array<string,mixed>>}
     */
    public function uploadManyFromFiles(array $files, int $userId): array
    {
        $result = [
            'uploaded' => 0,
            'deduplicated' => 0,
            'assets' => [],
        ];

        foreach ($this->normalizeFilesArray($files) as $file) {
            if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $created = $this->uploadFile($file, $userId);
            $result['assets'][] = $created['asset'];
            if (!empty($created['deduplicated'])) {
                $result['deduplicated']++;
            } else {
                $result['uploaded']++;
            }
        }

        return $result;
    }

    /**
     * @return array{asset:array<string,mixed>,deduplicated:bool}
     */
    public function uploadFile(array $file, int $userId): array
    {
        if (!Database::tableExists('marketplace_video_assets')) {
            throw new \RuntimeException('Page video library table is not installed.');
        }

        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new \RuntimeException($this->uploadErrorMessage('Page guide video', $uploadError));
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0) {
            throw new \RuntimeException('Page guide video is empty.');
        }
        if ($size > self::MAX_VIDEO_BYTES) {
            throw new \RuntimeException('Page guide videos must be 50 MB or smaller.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !$this->isUploadedFile($tmpName)) {
            throw new \RuntimeException('Page guide video upload was not valid.');
        }

        $mime = $this->detectMimeType($file, $tmpName);
        $extension = $this->videoExtension($file, $mime);
        $mime = self::EXTENSION_MIMES[$extension] ?? $mime;
        $checksum = hash_file('sha256', $tmpName) ?: null;
        if ($checksum !== null) {
            $existing = Database::queryOne(
                "SELECT * FROM marketplace_video_assets WHERE checksum_sha256 = ? LIMIT 1",
                [$checksum]
            );
            if ($existing) {
                return [
                    'asset' => $existing,
                    'deduplicated' => true,
                ];
            }
        }

        $originalName = $this->sanitizeFilename((string) ($file['name'] ?? 'page-guide-video.' . $extension));
        $title = $this->sanitizeTitle(pathinfo($originalName, PATHINFO_FILENAME) ?: 'Page guide video');
        $relativeDir = self::RELATIVE_UPLOAD_ROOT . '/' . date('Ym');
        $uploadDir = $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDir);
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException('Could not create page video library upload directory.');
        }

        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(pathinfo($originalName, PATHINFO_FILENAME))) ?? '', '-');
        $slug = $slug !== '' ? $slug : 'page-guide-video';
        $fileName = $slug . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
        if (!$this->moveUploadedFile($tmpName, $destination)) {
            throw new \RuntimeException('Could not save page guide video.');
        }

        $storedPath = $relativeDir . '/' . $fileName;
        Database::execute(
            "INSERT INTO marketplace_video_assets (
                title, original_filename, stored_path, mime_type, file_size, checksum_sha256, uploaded_by_user_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $title,
                $originalName,
                $storedPath,
                $mime !== '' ? $mime : null,
                $size,
                $checksum,
                $userId > 0 ? $userId : null,
            ]
        );

        return [
            'asset' => $this->getAsset((int) Database::lastInsertId()) ?? [],
            'deduplicated' => false,
        ];
    }

    public function assignToPage(string $pageKey, string $label, int $assetId, bool $isActive, int $userId): array
    {
        $pageKey = $this->normalizePageKey($pageKey);
        if ($pageKey === '') {
            throw new \InvalidArgumentException('Page key is required.');
        }
        if (!$this->tableReady()) {
            throw new \RuntimeException('Page video library tables are not installed.');
        }
        if ($assetId <= 0) {
            return $this->detachPage($pageKey, $label, $userId);
        }

        $asset = $this->getAsset($assetId);
        if (!$asset) {
            throw new \RuntimeException('Selected page guide video was not found.');
        }

        $label = $this->sanitizeTitle($label !== '' ? $label : ucwords(str_replace('_', ' ', $pageKey)));
        Database::execute(
            "INSERT INTO marketplace_page_explainers (
                page_key, label, video_url, video_asset_id, is_active, updated_by_user_id
             ) VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                video_url = VALUES(video_url),
                video_asset_id = VALUES(video_asset_id),
                is_active = VALUES(is_active),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [
                $pageKey,
                $label,
                (string) ($asset['stored_path'] ?? ''),
                $assetId,
                $isActive ? 1 : 0,
                $userId > 0 ? $userId : null,
            ]
        );

        return Database::queryOne(
            "SELECT * FROM marketplace_page_explainers WHERE page_key = ? LIMIT 1",
            [$pageKey]
        ) ?: [];
    }

    public function detachPage(string $pageKey, string $label, int $userId): array
    {
        $pageKey = $this->normalizePageKey($pageKey);
        if ($pageKey === '') {
            throw new \InvalidArgumentException('Page key is required.');
        }
        if (!Database::tableExists('marketplace_page_explainers')) {
            throw new \RuntimeException('Marketplace page explainer table is not installed.');
        }

        $label = $this->sanitizeTitle($label !== '' ? $label : ucwords(str_replace('_', ' ', $pageKey)));
        $hasAssetColumn = Database::columnExists('marketplace_page_explainers', 'video_asset_id');
        $columns = $hasAssetColumn
            ? 'page_key, label, video_url, video_asset_id, is_active, updated_by_user_id'
            : 'page_key, label, video_url, is_active, updated_by_user_id';
        $values = $hasAssetColumn ? '?, ?, NULL, NULL, 0, ?' : '?, ?, NULL, 0, ?';
        $updates = $hasAssetColumn
            ? 'label = VALUES(label), video_url = NULL, video_asset_id = NULL, is_active = 0, updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()'
            : 'label = VALUES(label), video_url = NULL, is_active = 0, updated_by_user_id = VALUES(updated_by_user_id), updated_at = NOW()';

        Database::execute(
            "INSERT INTO marketplace_page_explainers ({$columns})
             VALUES ({$values})
             ON DUPLICATE KEY UPDATE {$updates}",
            [
                $pageKey,
                $label,
                $userId > 0 ? $userId : null,
            ]
        );

        return Database::queryOne(
            "SELECT * FROM marketplace_page_explainers WHERE page_key = ? LIMIT 1",
            [$pageKey]
        ) ?: [];
    }

    public function deleteAsset(int $assetId): void
    {
        $asset = $this->getAsset($assetId);
        if (!$asset) {
            throw new \RuntimeException('Page guide video was not found.');
        }

        $usage = $this->assetUsage($assetId);
        if ($usage !== []) {
            $labels = array_map(static fn(array $row): string => (string) ($row['label'] ?? $row['page_key'] ?? 'Page'), $usage);
            throw new \RuntimeException('Detach this video from these pages before deleting it: ' . implode(', ', $labels) . '.');
        }

        Database::execute("DELETE FROM marketplace_video_assets WHERE id = ?", [$assetId]);
        $this->deleteLocalAssetFile((string) ($asset['stored_path'] ?? ''));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function assetUsage(int $assetId): array
    {
        if ($assetId <= 0 || !Database::columnExists('marketplace_page_explainers', 'video_asset_id')) {
            return [];
        }

        return Database::query(
            "SELECT page_key, label
             FROM marketplace_page_explainers
             WHERE video_asset_id = ?
             ORDER BY label, page_key",
            [$assetId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function normalizeFilesArray(array $files): array
    {
        if (!isset($files['name']) || !is_array($files['name'])) {
            return $files !== [] ? [$files] : [];
        }

        $normalized = [];
        foreach ($files['name'] as $index => $name) {
            $normalized[] = [
                'name' => $name,
                'type' => $files['type'][$index] ?? '',
                'tmp_name' => $files['tmp_name'][$index] ?? '',
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $files['size'][$index] ?? 0,
            ];
        }

        return $normalized;
    }

    private function videoExtension(array $file, string $mime): string
    {
        $mime = strtolower($mime);
        if (isset(self::MIME_EXTENSIONS[$mime])) {
            return self::MIME_EXTENSIONS[$mime];
        }

        $nameExtension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $extensionAllowed = isset(self::NAME_EXTENSIONS[$nameExtension]);
        if ($extensionAllowed && in_array($mime, ['', 'application/octet-stream', 'binary/octet-stream'], true)) {
            return self::NAME_EXTENSIONS[$nameExtension];
        }
        if ($extensionAllowed && PHP_SAPI === 'cli') {
            return self::NAME_EXTENSIONS[$nameExtension];
        }

        throw new \RuntimeException('Page guide video must be an MP4, WebM, MOV, or M4V file.');
    }

    private function detectMimeType(array $file, string $tmpName): string
    {
        if (function_exists('mime_content_type')) {
            $mime = strtolower((string) mime_content_type($tmpName));
            if ($mime !== '') {
                return $mime;
            }
        }

        return strtolower((string) ($file['type'] ?? ''));
    }

    private function uploadErrorMessage(string $label, int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $label . ' is larger than the server allows.',
            UPLOAD_ERR_PARTIAL => $label . ' was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
            default => $label . ' upload failed. Please try again.',
        };
    }

    private function isUploadedFile(string $tmpName): bool
    {
        return is_uploaded_file($tmpName) || (PHP_SAPI === 'cli' && is_file($tmpName));
    }

    private function moveUploadedFile(string $tmpName, string $destination): bool
    {
        if (is_uploaded_file($tmpName)) {
            return move_uploaded_file($tmpName, $destination);
        }

        return PHP_SAPI === 'cli' && rename($tmpName, $destination);
    }

    private function deleteLocalAssetFile(string $storedPath): void
    {
        $storedPath = trim(str_replace('\\', '/', $storedPath));
        if ($storedPath === '' || !str_starts_with($storedPath, 'uploads/marketplace/')) {
            return;
        }

        $fullPath = realpath($this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath));
        $marketplaceRoot = realpath($this->projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'marketplace');
        if ($fullPath === false || $marketplaceRoot === false || !is_file($fullPath)) {
            return;
        }

        $normalizedFull = rtrim(str_replace('\\', '/', $fullPath), '/');
        $normalizedRoot = rtrim(str_replace('\\', '/', $marketplaceRoot), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $normalizedFull = strtolower($normalizedFull);
            $normalizedRoot = strtolower($normalizedRoot);
        }
        if ($normalizedFull === $normalizedRoot || !str_starts_with($normalizedFull, $normalizedRoot . '/')) {
            return;
        }

        @unlink($fullPath);
    }

    private function normalizePageKey(string $pageKey): string
    {
        return trim(preg_replace('/[^a-z0-9_]+/', '_', strtolower($pageKey)) ?? '', '_');
    }

    private function sanitizeTitle(string $value): string
    {
        $value = trim(str_replace(["\r", "\n"], ' ', $value));
        if ($value === '') {
            return 'Page guide video';
        }
        if (mb_strlen($value) > 160) {
            return mb_substr($value, 0, 160);
        }

        return $value;
    }

    private function sanitizeFilename(string $value): string
    {
        $value = basename(str_replace('\\', '/', trim($value)));
        $value = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $value) ?? '';
        $value = trim($value, " .\t\r\n");

        return $value !== '' ? mb_substr($value, 0, 255) : 'page-guide-video.mp4';
    }
}
