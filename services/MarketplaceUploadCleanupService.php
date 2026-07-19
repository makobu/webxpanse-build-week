<?php

namespace CRM\Services;

use CRM\Database;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MarketplaceUploadCleanupService
{
    private const MARKETPLACE_RELATIVE_ROOT = 'uploads/marketplace';

    /**
     * @var array<string,true>
     */
    private const VIDEO_EXTENSIONS = [
        'm4v' => true,
        'mov' => true,
        'mp4' => true,
        'webm' => true,
    ];

    /**
     * @var array<string,true>
     */
    private const CLEANUP_EXTENSIONS = [
        'avif' => true,
        'gif' => true,
        'jpeg' => true,
        'jpg' => true,
        'm4v' => true,
        'mov' => true,
        'mp4' => true,
        'png' => true,
        'svg' => true,
        'webm' => true,
        'webp' => true,
    ];

    private string $projectRoot;
    private string $marketplaceRoot;

    public function __construct(?string $projectRoot = null)
    {
        if ($projectRoot === null) {
            $overrideRoot = trim((string) (getenv('MARKETPLACE_UPLOAD_CLEANUP_ROOT') ?: ($_ENV['MARKETPLACE_UPLOAD_CLEANUP_ROOT'] ?? '')));
            $projectRoot = $overrideRoot !== '' ? $overrideRoot : dirname(__DIR__);
        }

        $this->projectRoot = rtrim((string) $projectRoot, "\\/");
        $this->marketplaceRoot = $this->projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'marketplace';
    }

    /**
     * @param array{delete?:bool,older_than_days?:int,videos_only?:bool,now?:int} $options
     * @return array<string,mixed>
     */
    public function scan(array $options = []): array
    {
        $delete = (bool) ($options['delete'] ?? false);
        $olderThanDays = max(0, (int) ($options['older_than_days'] ?? 7));
        $videosOnly = (bool) ($options['videos_only'] ?? false);
        $now = (int) ($options['now'] ?? time());
        $cutoffTimestamp = $now - ($olderThanDays * 86400);

        $result = [
            'mode' => $delete ? 'delete' : 'dry-run',
            'root' => $this->marketplaceRoot,
            'relative_root' => self::MARKETPLACE_RELATIVE_ROOT,
            'older_than_days' => $olderThanDays,
            'cutoff_timestamp' => $cutoffTimestamp,
            'videos_only' => $videosOnly,
            'summary' => [
                'scanned_files' => 0,
                'scanned_bytes' => 0,
                'referenced_files' => 0,
                'referenced_bytes' => 0,
                'candidate_files' => 0,
                'candidate_bytes' => 0,
                'deleted_files' => 0,
                'deleted_bytes' => 0,
                'skipped_files' => 0,
                'errors' => 0,
            ],
            'files' => [],
            'errors' => [],
        ];

        $references = $this->collectReferences();
        $referencedPaths = $references['paths'];
        foreach ($references['errors'] as $error) {
            $result['errors'][] = $error;
        }

        $root = realpath($this->marketplaceRoot);
        if ($root === false || !is_dir($root)) {
            $result['errors'][] = [
                'path' => $this->marketplaceRoot,
                'message' => 'Marketplace upload root does not exist.',
            ];
            $result['summary']['errors'] = count($result['errors']);
            return $result;
        }

        $projectRoot = realpath($this->projectRoot);
        if ($projectRoot === false || !$this->isPathInsideRoot($root, $projectRoot)) {
            $result['errors'][] = [
                'path' => $root,
                'message' => 'Marketplace upload root is outside the configured project root.',
            ];
            $result['summary']['errors'] = count($result['errors']);
            return $result;
        }

        $deletionBlocked = $delete && $references['errors'] !== [];
        if ($deletionBlocked) {
            $result['errors'][] = [
                'path' => $this->marketplaceRoot,
                'message' => 'Deletion blocked because referenced upload paths could not be collected safely.',
            ];
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $path = $fileInfo->getPathname();
                $realPath = realpath($path);
                if ($realPath === false || !$this->isPathInsideRoot($realPath, $root)) {
                    $result['errors'][] = [
                        'path' => $path,
                        'message' => 'Skipped unsafe file outside the marketplace root.',
                    ];
                    continue;
                }

                $relativePath = $this->relativeUploadPath($realPath, $root);
                $size = (int) $fileInfo->getSize();
                $mtime = (int) $fileInfo->getMTime();
                $extension = strtolower((string) pathinfo($realPath, PATHINFO_EXTENSION));
                $isReferenced = isset($referencedPaths[$relativePath]);
                $isVideo = isset(self::VIDEO_EXTENSIONS[$extension]);

                $result['summary']['scanned_files']++;
                $result['summary']['scanned_bytes'] += $size;
                if ($isReferenced) {
                    $result['summary']['referenced_files']++;
                    $result['summary']['referenced_bytes'] += $size;
                }

                $candidate = false;
                $reason = 'old_orphan';
                if (basename($realPath) === '.htaccess') {
                    $reason = 'protected_file';
                } elseif (!isset(self::CLEANUP_EXTENSIONS[$extension])) {
                    $reason = 'unsupported_extension';
                } elseif ($videosOnly && !$isVideo) {
                    $reason = 'non_video';
                } elseif ($isReferenced) {
                    $reason = 'referenced';
                } elseif ($mtime > $cutoffTimestamp) {
                    $reason = 'recent';
                } else {
                    $candidate = true;
                    $result['summary']['candidate_files']++;
                    $result['summary']['candidate_bytes'] += $size;
                }

                $deleted = false;
                if ($candidate && $delete && !$deletionBlocked) {
                    if (@unlink($realPath)) {
                        $deleted = true;
                        $result['summary']['deleted_files']++;
                        $result['summary']['deleted_bytes'] += $size;
                    } else {
                        $result['errors'][] = [
                            'path' => $relativePath,
                            'message' => 'Failed to delete candidate file.',
                        ];
                    }
                }

                if (!$candidate) {
                    $result['summary']['skipped_files']++;
                }

                $result['files'][] = [
                    'path' => $relativePath,
                    'absolute_path' => $realPath,
                    'size' => $size,
                    'mtime' => $mtime,
                    'extension' => $extension,
                    'referenced' => $isReferenced,
                    'candidate' => $candidate,
                    'reason' => $reason,
                    'deleted' => $deleted,
                ];
            }
        } catch (\Throwable $e) {
            $result['errors'][] = [
                'path' => $root,
                'message' => $e->getMessage(),
            ];
        }

        if ($delete && !$deletionBlocked) {
            $this->removeEmptyDirectories($root, $result);
        }

        usort($result['files'], static function (array $a, array $b): int {
            if ($a['candidate'] !== $b['candidate']) {
                return $a['candidate'] ? -1 : 1;
            }

            return ((int) $b['size']) <=> ((int) $a['size']);
        });

        $result['summary']['errors'] = count($result['errors']);
        return $result;
    }

    /**
     * @return array<string,true>
     */
    public function collectReferencedPaths(): array
    {
        return $this->collectReferences()['paths'];
    }

    /**
     * @return array<string,int>
     */
    public function videoInventory(): array
    {
        $local = $this->scanLocalVideos(false);
        $database = $this->countDatabaseVideoReferences();

        return [
            'local_video_files' => (int) ($local['summary']['video_files'] ?? 0),
            'local_video_bytes' => (int) ($local['summary']['video_bytes'] ?? 0),
            'catalog_video_refs' => (int) ($database['catalog_video_refs'] ?? 0),
            'bundle_video_refs' => (int) ($database['bundle_video_refs'] ?? 0),
            'library_video_refs' => (int) ($database['library_video_refs'] ?? 0),
            'page_video_refs' => (int) ($database['page_video_refs'] ?? 0),
            'errors' => (int) (count((array) ($local['errors'] ?? [])) + count((array) ($database['errors'] ?? []))),
        ];
    }

    /**
     * Delete all local marketplace videos and clear every marketplace video placement.
     *
     * @return array<string,mixed>
     */
    public function purgeAllVideos(int $userId = 0): array
    {
        $report = [
            'mode' => 'delete-all-videos',
            'root' => $this->marketplaceRoot,
            'summary' => [
                'local_video_files' => 0,
                'local_video_bytes' => 0,
                'deleted_files' => 0,
                'deleted_bytes' => 0,
                'cleared_catalog_video_refs' => 0,
                'cleared_bundle_video_refs' => 0,
                'cleared_library_video_refs' => 0,
                'cleared_page_video_refs' => 0,
                'skipped_files' => 0,
                'errors' => 0,
            ],
            'files' => [],
            'errors' => [],
        ];

        try {
            Database::getInstance();
        } catch (\Throwable $e) {
            $report['errors'][] = [
                'message' => 'Failed to connect to the database before deleting marketplace videos: ' . $e->getMessage(),
            ];
            $report['summary']['errors'] = count($report['errors']);
            return $report;
        }

        $clearResult = $this->clearDatabaseVideoReferences($userId);
        foreach ($clearResult['errors'] as $error) {
            $report['errors'][] = $error;
        }
        $report['summary']['cleared_catalog_video_refs'] = (int) $clearResult['cleared_catalog_video_refs'];
        $report['summary']['cleared_bundle_video_refs'] = (int) $clearResult['cleared_bundle_video_refs'];
        $report['summary']['cleared_library_video_refs'] = (int) ($clearResult['cleared_library_video_refs'] ?? 0);
        $report['summary']['cleared_page_video_refs'] = (int) $clearResult['cleared_page_video_refs'];

        if ($clearResult['errors'] !== []) {
            $report['summary']['errors'] = count($report['errors']);
            return $report;
        }

        $deleteResult = $this->scanLocalVideos(true);
        foreach ($deleteResult['files'] as $file) {
            $report['files'][] = $file;
        }
        foreach ($deleteResult['errors'] as $error) {
            $report['errors'][] = $error;
        }

        $deleteSummary = (array) ($deleteResult['summary'] ?? []);
        $report['summary']['local_video_files'] = (int) ($deleteSummary['video_files'] ?? 0);
        $report['summary']['local_video_bytes'] = (int) ($deleteSummary['video_bytes'] ?? 0);
        $report['summary']['deleted_files'] = (int) ($deleteSummary['deleted_files'] ?? 0);
        $report['summary']['deleted_bytes'] = (int) ($deleteSummary['deleted_bytes'] ?? 0);
        $report['summary']['skipped_files'] = (int) ($deleteSummary['skipped_files'] ?? 0);
        $report['summary']['errors'] = count($report['errors']);

        return $report;
    }

    public function normalizeMarketplacePath(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '' || str_contains($raw, "\0")) {
            return null;
        }

        $raw = html_entity_decode(str_replace('\/', '/', $raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $raw = str_replace('\\', '/', $raw);

        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $raw;
        }

        $path = preg_replace('/[?#].*$/', '', $path) ?? $path;
        $path = str_replace('\\', '/', rawurldecode($path));
        $path = preg_replace('#/+#', '/', $path) ?? $path;
        $position = strpos($path, self::MARKETPLACE_RELATIVE_ROOT . '/');
        if ($position === false) {
            return null;
        }

        $relative = substr($path, $position);
        $suffix = substr($relative, strlen(self::MARKETPLACE_RELATIVE_ROOT . '/'));
        if ($suffix === false || trim($suffix) === '') {
            return null;
        }

        $segments = explode('/', $suffix);
        $cleanSegments = [];
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                return null;
            }
            $cleanSegments[] = $segment;
        }

        return self::MARKETPLACE_RELATIVE_ROOT . '/' . implode('/', $cleanSegments);
    }

    /**
     * @return array{paths:array<string,true>,errors:array<int,array{table?:string,path?:string,message:string}>}
     */
    private function collectReferences(): array
    {
        $paths = [];
        $errors = [];

        try {
            Database::getInstance();
        } catch (\Throwable $e) {
            return [
                'paths' => [],
                'errors' => [[
                    'message' => 'Failed to connect to the database while collecting marketplace upload references: ' . $e->getMessage(),
                ]],
            ];
        }

        $this->collectTableReferences(
            'workspace_skill_catalog_overrides',
            ['thumbnail_url', 'banner_url', 'marketplace_profile_json'],
            $paths,
            $errors
        );
        $this->collectTableReferences(
            'workspace_marketplace_activation_bundle_definitions',
            ['thumbnail_url', 'banner_url', 'explainer_video_url'],
            $paths,
            $errors
        );
        $this->collectTableReferences(
            'marketplace_video_assets',
            ['stored_path'],
            $paths,
            $errors
        );
        $this->collectTableReferences(
            'marketplace_page_explainers',
            ['video_url'],
            $paths,
            $errors
        );

        ksort($paths);
        return ['paths' => $paths, 'errors' => $errors];
    }

    /**
     * @param string[] $columns
     * @param array<string,true> $paths
     * @param array<int,array{table?:string,path?:string,message:string}> $errors
     */
    private function collectTableReferences(string $table, array $columns, array &$paths, array &$errors): void
    {
        if (!Database::tableExists($table)) {
            return;
        }

        $existingColumns = [];
        foreach ($columns as $column) {
            if (Database::columnExists($table, $column)) {
                $existingColumns[] = $column;
            }
        }
        if ($existingColumns === []) {
            return;
        }

        try {
            $select = implode(', ', array_map([$this, 'quoteIdentifier'], $existingColumns));
            $rows = Database::query('SELECT ' . $select . ' FROM ' . $this->quoteIdentifier($table));
        } catch (\Throwable $e) {
            $errors[] = [
                'table' => $table,
                'message' => 'Failed to collect marketplace upload references: ' . $e->getMessage(),
            ];
            return;
        }

        foreach ($rows as $row) {
            foreach ($existingColumns as $column) {
                $this->collectReferencesFromValue($row[$column] ?? null, $paths);
            }
        }
    }

    /**
     * @param array<string,true> $paths
     */
    private function collectReferencesFromValue(mixed $value, array &$paths): void
    {
        if ($value === null) {
            return;
        }

        if (is_array($value)) {
            foreach ($value as $nested) {
                $this->collectReferencesFromValue($nested, $paths);
            }
            return;
        }

        if (!is_scalar($value)) {
            return;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return;
        }

        $normalized = $this->normalizeMarketplacePath($raw);
        if ($normalized !== null) {
            $paths[$normalized] = true;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $this->collectReferencesFromValue($decoded, $paths);
        }

        $searchable = str_replace('\/', '/', html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (preg_match_all('#(?:\.\./|/)?uploads/marketplace/[A-Za-z0-9._~/%+\-]+#i', $searchable, $matches) !== 1) {
            return;
        }

        foreach ($matches[0] as $match) {
            $matchedPath = $this->normalizeMarketplacePath($match);
            if ($matchedPath !== null) {
                $paths[$matchedPath] = true;
            }
        }
    }

    private function relativeUploadPath(string $realPath, string $root): string
    {
        $relative = ltrim(substr($realPath, strlen(rtrim($root, "\\/"))), "\\/");
        return self::MARKETPLACE_RELATIVE_ROOT . '/' . str_replace('\\', '/', $relative);
    }

    /**
     * @return array{summary:array<string,int>,files:array<int,array<string,mixed>>,errors:array<int,array{path?:string,message:string}>}
     */
    private function scanLocalVideos(bool $delete): array
    {
        $result = [
            'summary' => [
                'video_files' => 0,
                'video_bytes' => 0,
                'deleted_files' => 0,
                'deleted_bytes' => 0,
                'skipped_files' => 0,
                'errors' => 0,
            ],
            'files' => [],
            'errors' => [],
        ];

        $root = realpath($this->marketplaceRoot);
        if ($root === false || !is_dir($root)) {
            $result['errors'][] = [
                'path' => $this->marketplaceRoot,
                'message' => 'Marketplace upload root does not exist.',
            ];
            $result['summary']['errors'] = count($result['errors']);
            return $result;
        }

        $projectRoot = realpath($this->projectRoot);
        if ($projectRoot === false || !$this->isPathInsideRoot($root, $projectRoot)) {
            $result['errors'][] = [
                'path' => $root,
                'message' => 'Marketplace upload root is outside the configured project root.',
            ];
            $result['summary']['errors'] = count($result['errors']);
            return $result;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $path = $fileInfo->getPathname();
                $realPath = realpath($path);
                if ($realPath === false || !$this->isPathInsideRoot($realPath, $root)) {
                    $result['errors'][] = [
                        'path' => $path,
                        'message' => 'Skipped unsafe file outside the marketplace root.',
                    ];
                    continue;
                }

                $relativePath = $this->relativeUploadPath($realPath, $root);
                $extension = strtolower((string) pathinfo($realPath, PATHINFO_EXTENSION));
                $isVideo = isset(self::VIDEO_EXTENSIONS[$extension]);
                $size = (int) $fileInfo->getSize();
                $mtime = (int) $fileInfo->getMTime();

                if (!$isVideo) {
                    $result['summary']['skipped_files']++;
                    continue;
                }

                $result['summary']['video_files']++;
                $result['summary']['video_bytes'] += $size;

                $deleted = false;
                if ($delete) {
                    if (@unlink($realPath)) {
                        $deleted = true;
                        $result['summary']['deleted_files']++;
                        $result['summary']['deleted_bytes'] += $size;
                    } else {
                        $result['errors'][] = [
                            'path' => $relativePath,
                            'message' => 'Failed to delete marketplace video file.',
                        ];
                    }
                }

                $result['files'][] = [
                    'path' => $relativePath,
                    'absolute_path' => $realPath,
                    'size' => $size,
                    'mtime' => $mtime,
                    'extension' => $extension,
                    'deleted' => $deleted,
                ];
            }
        } catch (\Throwable $e) {
            $result['errors'][] = [
                'path' => $root,
                'message' => $e->getMessage(),
            ];
        }

        if ($delete) {
            $this->removeEmptyDirectories($root, $result);
        }

        $result['summary']['errors'] = count($result['errors']);
        return $result;
    }

    /**
     * @return array{catalog_video_refs:int,bundle_video_refs:int,library_video_refs:int,page_video_refs:int,errors:array<int,array{table?:string,message:string}>}
     */
    private function countDatabaseVideoReferences(): array
    {
        $result = [
            'catalog_video_refs' => 0,
            'bundle_video_refs' => 0,
            'library_video_refs' => 0,
            'page_video_refs' => 0,
            'errors' => [],
        ];

        try {
            Database::getInstance();
        } catch (\Throwable $e) {
            $result['errors'][] = [
                'message' => 'Failed to connect to the database while counting marketplace video references: ' . $e->getMessage(),
            ];
            return $result;
        }

        if (Database::tableExists('workspace_skill_catalog_overrides') && Database::columnExists('workspace_skill_catalog_overrides', 'marketplace_profile_json')) {
            try {
                foreach (Database::query('SELECT marketplace_profile_json FROM workspace_skill_catalog_overrides') as $row) {
                    $profile = json_decode((string) ($row['marketplace_profile_json'] ?? '{}'), true);
                    if (!is_array($profile)) {
                        continue;
                    }
                    foreach (['explainer_video_url', 'setup_video_url'] as $field) {
                        if (trim((string) ($profile[$field] ?? '')) !== '') {
                            $result['catalog_video_refs']++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'table' => 'workspace_skill_catalog_overrides',
                    'message' => 'Failed to count catalog video references: ' . $e->getMessage(),
                ];
            }
        }

        if (Database::tableExists('workspace_marketplace_activation_bundle_definitions') && Database::columnExists('workspace_marketplace_activation_bundle_definitions', 'explainer_video_url')) {
            try {
                $row = Database::queryOne(
                    "SELECT COUNT(*) AS c
                     FROM workspace_marketplace_activation_bundle_definitions
                     WHERE COALESCE(TRIM(explainer_video_url), '') <> ''"
                );
                $result['bundle_video_refs'] = (int) ($row['c'] ?? 0);
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'table' => 'workspace_marketplace_activation_bundle_definitions',
                    'message' => 'Failed to count bundle video references: ' . $e->getMessage(),
                ];
            }
        }

        if (Database::tableExists('marketplace_video_assets') && Database::columnExists('marketplace_video_assets', 'stored_path')) {
            try {
                $row = Database::queryOne(
                    "SELECT COUNT(*) AS c
                     FROM marketplace_video_assets
                     WHERE COALESCE(TRIM(stored_path), '') <> ''"
                );
                $result['library_video_refs'] = (int) ($row['c'] ?? 0);
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'table' => 'marketplace_video_assets',
                    'message' => 'Failed to count page video library references: ' . $e->getMessage(),
                ];
            }
        }

        if (Database::tableExists('marketplace_page_explainers') && Database::columnExists('marketplace_page_explainers', 'video_url')) {
            try {
                $hasAssetColumn = Database::columnExists('marketplace_page_explainers', 'video_asset_id');
                $where = $hasAssetColumn
                    ? "COALESCE(TRIM(video_url), '') <> '' OR video_asset_id IS NOT NULL"
                    : "COALESCE(TRIM(video_url), '') <> ''";
                $row = Database::queryOne("SELECT COUNT(*) AS c FROM marketplace_page_explainers WHERE {$where}");
                $result['page_video_refs'] = (int) ($row['c'] ?? 0);
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'table' => 'marketplace_page_explainers',
                    'message' => 'Failed to count page explainer video references: ' . $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    /**
     * @return array{cleared_catalog_video_refs:int,cleared_bundle_video_refs:int,cleared_library_video_refs:int,cleared_page_video_refs:int,errors:array<int,array{table?:string,message:string}>}
     */
    private function clearDatabaseVideoReferences(int $userId): array
    {
        $result = [
            'cleared_catalog_video_refs' => 0,
            'cleared_bundle_video_refs' => 0,
            'cleared_library_video_refs' => 0,
            'cleared_page_video_refs' => 0,
            'errors' => [],
        ];

        try {
            if (Database::tableExists('workspace_skill_catalog_overrides') && Database::columnExists('workspace_skill_catalog_overrides', 'marketplace_profile_json')) {
                $rows = Database::query('SELECT id, marketplace_profile_json FROM workspace_skill_catalog_overrides');
                foreach ($rows as $row) {
                    $profile = json_decode((string) ($row['marketplace_profile_json'] ?? '{}'), true);
                    if (!is_array($profile)) {
                        continue;
                    }

                    $cleared = 0;
                    foreach (['explainer_video_url', 'setup_video_url'] as $field) {
                        if (trim((string) ($profile[$field] ?? '')) !== '') {
                            $profile[$field] = '';
                            $cleared++;
                        }
                    }
                    if (trim((string) ($profile['setup_video_uploaded_at'] ?? '')) !== '') {
                        $profile['setup_video_uploaded_at'] = '';
                    }
                    if ($cleared <= 0) {
                        continue;
                    }

                    $result['cleared_catalog_video_refs'] += $cleared;
                    $sql = "UPDATE workspace_skill_catalog_overrides
                            SET marketplace_profile_json = ?,
                                updated_at = NOW()";
                    $params = [json_encode($profile, JSON_UNESCAPED_SLASHES)];
                    if (Database::columnExists('workspace_skill_catalog_overrides', 'updated_by_user_id')) {
                        $sql .= ', updated_by_user_id = ?';
                        $params[] = $userId > 0 ? $userId : null;
                    }
                    $sql .= ' WHERE id = ?';
                    $params[] = (int) ($row['id'] ?? 0);
                    Database::execute($sql, $params);
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = [
                'table' => 'workspace_skill_catalog_overrides',
                'message' => 'Failed to clear catalog video references: ' . $e->getMessage(),
            ];
        }

        try {
            if (Database::tableExists('workspace_marketplace_activation_bundle_definitions') && Database::columnExists('workspace_marketplace_activation_bundle_definitions', 'explainer_video_url')) {
                $count = Database::queryOne(
                    "SELECT COUNT(*) AS c
                     FROM workspace_marketplace_activation_bundle_definitions
                     WHERE COALESCE(TRIM(explainer_video_url), '') <> ''"
                );
                $result['cleared_bundle_video_refs'] = (int) ($count['c'] ?? 0);
                if ($result['cleared_bundle_video_refs'] > 0) {
                    $sql = "UPDATE workspace_marketplace_activation_bundle_definitions
                            SET explainer_video_url = NULL,
                                updated_at = NOW()";
                    $params = [];
                    if (Database::columnExists('workspace_marketplace_activation_bundle_definitions', 'updated_by_user_id')) {
                        $sql .= ', updated_by_user_id = ?';
                        $params[] = $userId > 0 ? $userId : null;
                    }
                    $sql .= " WHERE COALESCE(TRIM(explainer_video_url), '') <> ''";
                    Database::execute($sql, $params);
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = [
                'table' => 'workspace_marketplace_activation_bundle_definitions',
                'message' => 'Failed to clear activation bundle video references: ' . $e->getMessage(),
            ];
        }

        try {
            if (Database::tableExists('marketplace_page_explainers') && Database::columnExists('marketplace_page_explainers', 'video_url')) {
                $hasAssetColumn = Database::columnExists('marketplace_page_explainers', 'video_asset_id');
                $where = $hasAssetColumn
                    ? "COALESCE(TRIM(video_url), '') <> '' OR video_asset_id IS NOT NULL"
                    : "COALESCE(TRIM(video_url), '') <> ''";
                $count = Database::queryOne("SELECT COUNT(*) AS c FROM marketplace_page_explainers WHERE {$where}");
                $result['cleared_page_video_refs'] = (int) ($count['c'] ?? 0);
                if ($result['cleared_page_video_refs'] > 0) {
                    $sql = $hasAssetColumn
                        ? "UPDATE marketplace_page_explainers
                            SET video_url = NULL,
                                video_asset_id = NULL,
                                is_active = 0,
                                updated_at = NOW()"
                        : "UPDATE marketplace_page_explainers
                            SET video_url = NULL,
                                is_active = 0,
                                updated_at = NOW()";
                    $params = [];
                    if (Database::columnExists('marketplace_page_explainers', 'updated_by_user_id')) {
                        $sql .= ', updated_by_user_id = ?';
                        $params[] = $userId > 0 ? $userId : null;
                    }
                    $sql .= " WHERE {$where}";
                    Database::execute($sql, $params);
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = [
                'table' => 'marketplace_page_explainers',
                'message' => 'Failed to clear page explainer video references: ' . $e->getMessage(),
            ];
        }

        try {
            if (Database::tableExists('marketplace_video_assets') && Database::columnExists('marketplace_video_assets', 'stored_path')) {
                $count = Database::queryOne(
                    "SELECT COUNT(*) AS c
                     FROM marketplace_video_assets
                     WHERE COALESCE(TRIM(stored_path), '') <> ''"
                );
                $result['cleared_library_video_refs'] = (int) ($count['c'] ?? 0);
                if ($result['cleared_library_video_refs'] > 0) {
                    Database::execute("DELETE FROM marketplace_video_assets WHERE COALESCE(TRIM(stored_path), '') <> ''");
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = [
                'table' => 'marketplace_video_assets',
                'message' => 'Failed to clear page video library references: ' . $e->getMessage(),
            ];
        }

        return $result;
    }

    private function isPathInsideRoot(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = strtolower($path);
            $root = strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root . '/');
    }

    /**
     * @param array<string,mixed> $result
     */
    private function removeEmptyDirectories(string $root, array &$result): void
    {
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isDir()) {
                    continue;
                }

                $realPath = realpath($fileInfo->getPathname());
                if ($realPath === false || $realPath === $root || !$this->isPathInsideRoot($realPath, $root)) {
                    continue;
                }

                $items = @scandir($realPath);
                if ($items === false || count($items) > 2) {
                    continue;
                }

                if (!@rmdir($realPath)) {
                    $result['errors'][] = [
                        'path' => $this->relativeUploadPath($realPath, $root),
                        'message' => 'Failed to remove empty directory.',
                    ];
                }
            }
        } catch (\Throwable $e) {
            $result['errors'][] = [
                'path' => $root,
                'message' => 'Failed to remove empty directories: ' . $e->getMessage(),
            ];
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new \InvalidArgumentException('Unsafe SQL identifier.');
        }

        return '`' . $identifier . '`';
    }
}
