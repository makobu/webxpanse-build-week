<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\MarketplaceUploadCleanupService;
use CRM\Tests\TestCase;
use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MarketplaceUploadCleanupServiceTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $tempRoots = [];
    private ?string $databaseName = null;
    private ?array $databaseConfig = null;

    protected function setUp(): void
    {
        parent::setUp();

        $config = require __DIR__ . '/../../../config/database.php';
        $this->databaseConfig = [
            'host' => (string) ($config['host'] ?? 'localhost'),
            'user' => (string) ($config['user'] ?? 'root'),
            'pass' => (string) ($config['pass'] ?? ''),
            'charset' => (string) ($config['charset'] ?? 'utf8mb4'),
        ];
        $this->databaseName = 'crm_cleanup_test_' . bin2hex(random_bytes(6));

        $this->adminPdo()->exec('CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        Database::close();
        Database::init([
            'host' => $this->databaseConfig['host'],
            'name' => $this->databaseName,
            'user' => $this->databaseConfig['user'],
            'pass' => $this->databaseConfig['pass'],
            'charset' => $this->databaseConfig['charset'],
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ],
        ]);
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempRoots as $root) {
            $this->removeDirectory($root);
        }
        $this->tempRoots = [];

        Database::close();
        if ($this->databaseName !== null) {
            try {
                $this->adminPdo()->exec('DROP DATABASE IF EXISTS `' . $this->databaseName . '`');
            } catch (\Throwable $e) {
                // Best-effort cleanup for a throwaway test database.
            }
        }

        parent::tearDown();
    }

    public function testNormalizeMarketplacePathAcceptsLocalMarketplaceFormsOnly(): void
    {
        $service = new MarketplaceUploadCleanupService($this->makeProjectRoot());

        $this->assertSame(
            'uploads/marketplace/demo/video.mp4',
            $service->normalizeMarketplacePath('/uploads/marketplace/demo/video.mp4?cache=1')
        );
        $this->assertSame(
            'uploads/marketplace/demo/clip.mp4',
            $service->normalizeMarketplacePath('C:\\xampp\\htdocs\\crm\\uploads\\marketplace\\demo\\clip.mp4')
        );
        $this->assertSame(
            'uploads/marketplace/demo/banner.webp',
            $service->normalizeMarketplacePath('../uploads/marketplace/demo/banner.webp')
        );
        $this->assertNull($service->normalizeMarketplacePath('uploads/forms/demo/video.mp4'));
        $this->assertNull($service->normalizeMarketplacePath('https://cdn.example.test/demo/video.mp4'));
        $this->assertNull($service->normalizeMarketplacePath('uploads/marketplace/../private/video.mp4'));
    }

    public function testDryRunReportsOldOrphansWithoutDeletingReferencedRecentOrProtectedFiles(): void
    {
        $now = time();
        $old = $now - (10 * 86400);
        $recent = $now - 3600;
        $projectRoot = $this->makeProjectRoot();

        $oldOrphan = $this->writeUploadFile($projectRoot, 'uploads/marketplace/module/old-orphan.mp4', 'old', $old);
        $this->writeUploadFile($projectRoot, 'uploads/marketplace/module/recent-orphan.mp4', 'recent', $recent);
        $this->writeUploadFile($projectRoot, 'uploads/marketplace/module/referenced-video.mp4', 'referenced', $old);
        $this->writeUploadFile($projectRoot, 'uploads/marketplace/module/referenced-thumb.webp', 'thumb', $old);
        $this->writeUploadFile($projectRoot, 'uploads/marketplace/module/embedded.webp', 'embedded', $old);
        $this->writeUploadFile($projectRoot, 'uploads/marketplace/page_explainers/unit/referenced-page.mp4', 'page', $old);
        $this->writeUploadFile($projectRoot, 'uploads/marketplace/page_video_library/202607/library-guide.mp4', 'library', $old);
        $this->writeUploadFile($projectRoot, 'uploads/marketplace/module/readme.txt', 'skip', $old);
        $this->writeUploadFile($projectRoot, 'uploads/marketplace/.htaccess', 'deny from all', $old);

        $this->seedMarketplaceReferences();

        $report = (new MarketplaceUploadCleanupService($projectRoot))->scan([
            'older_than_days' => 7,
            'now' => $now,
        ]);

        $this->assertSame(0, (int) $report['summary']['errors']);
        $this->assertSame(1, (int) $report['summary']['candidate_files']);
        $this->assertFileExists($oldOrphan);

        $oldOrphanEntry = $this->reportFile($report, 'uploads/marketplace/module/old-orphan.mp4');
        $this->assertTrue((bool) $oldOrphanEntry['candidate']);
        $this->assertFalse((bool) $oldOrphanEntry['deleted']);
        $this->assertSame('old_orphan', $oldOrphanEntry['reason']);

        $this->assertSame('recent', $this->reportFile($report, 'uploads/marketplace/module/recent-orphan.mp4')['reason']);
        $this->assertSame('referenced', $this->reportFile($report, 'uploads/marketplace/module/referenced-video.mp4')['reason']);
        $this->assertSame('referenced', $this->reportFile($report, 'uploads/marketplace/module/referenced-thumb.webp')['reason']);
        $this->assertSame('referenced', $this->reportFile($report, 'uploads/marketplace/module/embedded.webp')['reason']);
        $this->assertSame('referenced', $this->reportFile($report, 'uploads/marketplace/page_explainers/unit/referenced-page.mp4')['reason']);
        $this->assertSame('referenced', $this->reportFile($report, 'uploads/marketplace/page_video_library/202607/library-guide.mp4')['reason']);
        $this->assertSame('unsupported_extension', $this->reportFile($report, 'uploads/marketplace/module/readme.txt')['reason']);
        $this->assertSame('protected_file', $this->reportFile($report, 'uploads/marketplace/.htaccess')['reason']);
    }

    public function testDeleteRemovesOnlyOldOrphansAndKeepsReferencedAndRecentFiles(): void
    {
        $now = time();
        $old = $now - (12 * 86400);
        $recent = $now - 120;
        $projectRoot = $this->makeProjectRoot();

        $oldVideo = $this->writeUploadFile($projectRoot, 'uploads/marketplace/delete-test/old-video.mp4', 'old video', $old);
        $oldImage = $this->writeUploadFile($projectRoot, 'uploads/marketplace/delete-test/old-image.webp', 'old image', $old);
        $referenced = $this->writeUploadFile($projectRoot, 'uploads/marketplace/delete-test/referenced.mp4', 'referenced', $old);
        $recentFile = $this->writeUploadFile($projectRoot, 'uploads/marketplace/delete-test/recent.mp4', 'recent', $recent);

        $this->seedPageExplainerReference('uploads/marketplace/delete-test/referenced.mp4');

        $report = (new MarketplaceUploadCleanupService($projectRoot))->scan([
            'delete' => true,
            'older_than_days' => 7,
            'now' => $now,
        ]);

        $this->assertSame(0, (int) $report['summary']['errors']);
        $this->assertSame(2, (int) $report['summary']['candidate_files']);
        $this->assertSame(2, (int) $report['summary']['deleted_files']);
        $this->assertFileDoesNotExist($oldVideo);
        $this->assertFileDoesNotExist($oldImage);
        $this->assertFileExists($referenced);
        $this->assertFileExists($recentFile);
        $this->assertSame('referenced', $this->reportFile($report, 'uploads/marketplace/delete-test/referenced.mp4')['reason']);
        $this->assertSame('recent', $this->reportFile($report, 'uploads/marketplace/delete-test/recent.mp4')['reason']);
    }

    public function testVideosOnlyLeavesOldImageOrphansOutOfCandidates(): void
    {
        $now = time();
        $old = $now - (9 * 86400);
        $projectRoot = $this->makeProjectRoot();

        $this->writeUploadFile($projectRoot, 'uploads/marketplace/videos-only/old-video.mp4', 'video', $old);
        $this->writeUploadFile($projectRoot, 'uploads/marketplace/videos-only/old-image.webp', 'image', $old);

        $report = (new MarketplaceUploadCleanupService($projectRoot))->scan([
            'older_than_days' => 7,
            'videos_only' => true,
            'now' => $now,
        ]);

        $this->assertSame(1, (int) $report['summary']['candidate_files']);
        $this->assertTrue((bool) $this->reportFile($report, 'uploads/marketplace/videos-only/old-video.mp4')['candidate']);
        $this->assertSame('non_video', $this->reportFile($report, 'uploads/marketplace/videos-only/old-image.webp')['reason']);
    }

    public function testPurgeAllVideosDeletesEveryLocalVideoAndClearsDatabasePlacements(): void
    {
        $this->clearMarketplaceReferenceTables();
        $now = time();
        $old = $now - (12 * 86400);
        $recent = $now - 60;
        $projectRoot = $this->makeProjectRoot();

        $referencedVideo = $this->writeUploadFile($projectRoot, 'uploads/marketplace/purge/referenced.mp4', 'referenced', $old);
        $recentVideo = $this->writeUploadFile($projectRoot, 'uploads/marketplace/purge/recent.webm', 'recent', $recent);
        $orphanVideo = $this->writeUploadFile($projectRoot, 'uploads/marketplace/purge/orphan.mov', 'orphan', $old);
        $libraryVideo = $this->writeUploadFile($projectRoot, 'uploads/marketplace/page_video_library/202607/purge-library.mp4', 'library', $old);
        $image = $this->writeUploadFile($projectRoot, 'uploads/marketplace/purge/keep-image.webp', 'image', $old);
        $text = $this->writeUploadFile($projectRoot, 'uploads/marketplace/purge/readme.txt', 'text', $old);
        $htaccess = $this->writeUploadFile($projectRoot, 'uploads/marketplace/.htaccess', 'deny from all', $old);
        $outsideVideo = $this->writeUploadFile($projectRoot, 'uploads/forms/outside.mp4', 'outside', $old);

        Database::execute(
            "INSERT INTO workspace_skill_catalog_overrides (skill_key, marketplace_profile_json)
             VALUES ('purge_catalog', ?)",
            [json_encode([
                'explainer_video_url' => 'uploads/marketplace/purge/referenced.mp4',
                'setup_video_url' => 'https://cdn.example.test/setup.mp4',
                'setup_video_uploaded_at' => '2026-06-01T00:00:00+00:00',
                'pitch' => 'Keep non-video profile content.',
            ], JSON_UNESCAPED_SLASHES)]
        );
        Database::execute(
            "INSERT INTO workspace_marketplace_activation_bundle_definitions (
                bundle_key, label, summary, included_skill_keys_json, explainer_video_url, is_active, display_order
             ) VALUES ('purge_bundle', 'Purge Bundle', 'Purge summary', ?, 'https://cdn.example.test/bundle.mp4', 1, 10)",
            [json_encode(['email_assistant'], JSON_UNESCAPED_SLASHES)]
        );
        Database::execute(
            "INSERT INTO marketplace_page_explainers (page_key, label, video_url, is_active)
             VALUES ('purge_page', 'Purge Page', 'uploads/marketplace/purge/referenced.mp4', 1)"
        );
        Database::execute(
            "INSERT INTO marketplace_video_assets (title, original_filename, stored_path, mime_type, file_size)
             VALUES ('Purge Library', 'purge-library.mp4', 'uploads/marketplace/page_video_library/202607/purge-library.mp4', 'video/mp4', 7)"
        );

        $service = new MarketplaceUploadCleanupService($projectRoot);
        $inventory = $service->videoInventory();
        $this->assertSame(4, (int) $inventory['local_video_files']);
        $this->assertSame(2, (int) $inventory['catalog_video_refs']);
        $this->assertSame(1, (int) $inventory['bundle_video_refs']);
        $this->assertSame(1, (int) $inventory['library_video_refs']);
        $this->assertSame(1, (int) $inventory['page_video_refs']);

        $report = $service->purgeAllVideos(123);

        $this->assertSame(0, (int) $report['summary']['errors']);
        $this->assertSame(4, (int) $report['summary']['local_video_files']);
        $this->assertSame(4, (int) $report['summary']['deleted_files']);
        $this->assertSame(2, (int) $report['summary']['cleared_catalog_video_refs']);
        $this->assertSame(1, (int) $report['summary']['cleared_bundle_video_refs']);
        $this->assertSame(1, (int) $report['summary']['cleared_library_video_refs']);
        $this->assertSame(1, (int) $report['summary']['cleared_page_video_refs']);
        $this->assertFileDoesNotExist($referencedVideo);
        $this->assertFileDoesNotExist($recentVideo);
        $this->assertFileDoesNotExist($orphanVideo);
        $this->assertFileDoesNotExist($libraryVideo);
        $this->assertFileExists($image);
        $this->assertFileExists($text);
        $this->assertFileExists($htaccess);
        $this->assertFileExists($outsideVideo);

        $catalogRow = Database::queryOne(
            "SELECT marketplace_profile_json FROM workspace_skill_catalog_overrides WHERE skill_key = 'purge_catalog' LIMIT 1"
        ) ?: [];
        $profile = json_decode((string) ($catalogRow['marketplace_profile_json'] ?? '{}'), true) ?: [];
        $this->assertSame('', (string) ($profile['explainer_video_url'] ?? ''));
        $this->assertSame('', (string) ($profile['setup_video_url'] ?? ''));
        $this->assertSame('', (string) ($profile['setup_video_uploaded_at'] ?? ''));
        $this->assertSame('Keep non-video profile content.', (string) ($profile['pitch'] ?? ''));
        $bundle = Database::queryOne("SELECT explainer_video_url FROM workspace_marketplace_activation_bundle_definitions WHERE bundle_key = 'purge_bundle' LIMIT 1") ?: [];
        $this->assertSame('', (string) ($bundle['explainer_video_url'] ?? ''));
        $page = Database::queryOne("SELECT video_url, is_active FROM marketplace_page_explainers WHERE page_key = 'purge_page' LIMIT 1") ?: [];
        $this->assertSame('', (string) ($page['video_url'] ?? ''));
        $this->assertSame(0, (int) ($page['is_active'] ?? 1));
        $libraryCount = Database::queryOne("SELECT COUNT(*) AS c FROM marketplace_video_assets") ?: [];
        $this->assertSame(0, (int) ($libraryCount['c'] ?? 1));
    }

    private function seedMarketplaceReferences(): void
    {
        $profile = [
            'explainer_video_url' => 'uploads/marketplace/module/referenced-video.mp4',
            'overview_brief_content' => '<figure><img src="/uploads/marketplace/module/referenced-thumb.webp" alt="Referenced thumbnail"></figure>',
            'overview_deep_dive_content' => '<img src="uploads/marketplace/module/embedded.webp">',
        ];

        Database::execute(
            "INSERT INTO workspace_skill_catalog_overrides (skill_key, thumbnail_url, banner_url, marketplace_profile_json)
             VALUES (?, ?, ?, ?)",
            [
                'cleanup_test_' . bin2hex(random_bytes(4)),
                '/uploads/marketplace/module/referenced-thumb.webp',
                '',
                json_encode($profile, JSON_UNESCAPED_SLASHES),
            ]
        );

        Database::execute(
            "INSERT INTO workspace_marketplace_activation_bundle_definitions (
                bundle_key, label, summary, included_skill_keys_json, thumbnail_url, banner_url, explainer_video_url, is_active, display_order
             ) VALUES (?, 'Cleanup Bundle', 'Cleanup summary', ?, NULL, NULL, ?, 1, 999)",
            [
                'cleanup_bundle_' . bin2hex(random_bytes(4)),
                json_encode(['email_assistant'], JSON_UNESCAPED_SLASHES),
                'uploads/marketplace/module/referenced-video.mp4',
            ]
        );

        $this->seedPageExplainerReference('uploads/marketplace/page_explainers/unit/referenced-page.mp4');
        Database::execute(
            "INSERT INTO marketplace_video_assets (title, original_filename, stored_path, mime_type, file_size)
             VALUES ('Cleanup Library', 'library-guide.mp4', 'uploads/marketplace/page_video_library/202607/library-guide.mp4', 'video/mp4', 7)"
        );
    }

    private function seedPageExplainerReference(string $path): void
    {
        Database::execute(
            "INSERT INTO marketplace_page_explainers (page_key, label, video_url, is_active)
             VALUES (?, 'Cleanup Page', ?, 1)",
            ['cleanup_page_' . bin2hex(random_bytes(4)), $path]
        );
    }

    private function clearMarketplaceReferenceTables(): void
    {
        Database::execute('DELETE FROM workspace_skill_catalog_overrides');
        Database::execute('DELETE FROM workspace_marketplace_activation_bundle_definitions');
        Database::execute('DELETE FROM marketplace_page_explainers');
        Database::execute('DELETE FROM marketplace_video_assets');
    }

    private function makeProjectRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm_marketplace_cleanup_' . bin2hex(random_bytes(6));
        mkdir($root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'marketplace', 0777, true);
        $this->tempRoots[] = $root;

        return $root;
    }

    private function writeUploadFile(string $projectRoot, string $relativePath, string $contents, int $mtime): string
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
        touch($path, $mtime);

        return $path;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function reportFile(array $report, string $path): array
    {
        foreach ((array) ($report['files'] ?? []) as $file) {
            if ((string) ($file['path'] ?? '') === $path) {
                return $file;
            }
        }

        $this->fail('Expected cleanup report to include ' . $path);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
            } else {
                @unlink($fileInfo->getPathname());
            }
        }

        @rmdir($directory);
    }

    private function adminPdo(): PDO
    {
        $config = $this->databaseConfig ?: (require __DIR__ . '/../../../config/database.php');

        return new PDO(
            'mysql:host=' . (string) ($config['host'] ?? 'localhost') . ';charset=utf8mb4',
            (string) ($config['user'] ?? 'root'),
            (string) ($config['pass'] ?? ''),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true,
            ]
        );
    }

    private function createSchema(): void
    {
        Database::execute(
            "CREATE TABLE workspace_skill_catalog_overrides (
                id INT PRIMARY KEY AUTO_INCREMENT,
                skill_key VARCHAR(160) NOT NULL UNIQUE,
                thumbnail_url VARCHAR(500) NULL,
                banner_url VARCHAR(500) NULL,
                marketplace_profile_json JSON NULL,
                updated_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        Database::execute(
            "CREATE TABLE workspace_marketplace_activation_bundle_definitions (
                id INT PRIMARY KEY AUTO_INCREMENT,
                bundle_key VARCHAR(120) NOT NULL UNIQUE,
                label VARCHAR(160) NOT NULL,
                summary TEXT NULL,
                included_skill_keys_json JSON NOT NULL,
                thumbnail_url VARCHAR(500) NULL,
                banner_url VARCHAR(500) NULL,
                explainer_video_url VARCHAR(500) NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                display_order INT NOT NULL DEFAULT 0,
                updated_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        Database::execute(
            "CREATE TABLE marketplace_video_assets (
                id INT PRIMARY KEY AUTO_INCREMENT,
                title VARCHAR(160) NOT NULL,
                original_filename VARCHAR(255) NOT NULL,
                stored_path VARCHAR(500) NOT NULL,
                mime_type VARCHAR(100) NULL,
                file_size BIGINT UNSIGNED NULL,
                checksum_sha256 CHAR(64) NULL,
                uploaded_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_marketplace_video_assets_path (stored_path)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        Database::execute(
            "CREATE TABLE marketplace_page_explainers (
                id INT PRIMARY KEY AUTO_INCREMENT,
                page_key VARCHAR(80) NOT NULL,
                label VARCHAR(160) NOT NULL,
                video_url VARCHAR(500) NULL,
                video_asset_id INT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 0,
                updated_by_user_id INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_marketplace_page_explainers_key (page_key),
                KEY idx_marketplace_page_explainers_video_asset (video_asset_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}
