<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\MarketplaceVideoAssetService;
use CRM\Tests\TestCase;
use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class MarketplaceVideoAssetServiceTest extends TestCase
{
    /**
     * @var string[]
     */
    private array $tempRoots = [];
    /**
     * @var string[]
     */
    private array $tempUploads = [];
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
        $this->databaseName = 'crm_video_asset_test_' . bin2hex(random_bytes(6));

        $admin = $this->adminPdo();
        $admin->exec('CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

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
        foreach ($this->tempUploads as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempUploads = [];

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

    public function testUploadManyDeduplicatesByChecksum(): void
    {
        $root = $this->makeProjectRoot();
        $service = new MarketplaceVideoAssetService($root);
        $first = $this->makeUploadFile('intro.mp4', 'same video bytes');
        $second = $this->makeUploadFile('intro-copy.mp4', 'same video bytes');

        $result = $service->uploadManyFromFiles([
            'name' => [$first['name'], $second['name']],
            'type' => [$first['type'], $second['type']],
            'tmp_name' => [$first['tmp_name'], $second['tmp_name']],
            'error' => [$first['error'], $second['error']],
            'size' => [$first['size'], $second['size']],
        ], 0);

        $this->assertSame(1, $result['uploaded']);
        $this->assertSame(1, $result['deduplicated']);
        $this->assertCount(2, $result['assets']);
        $this->assertSame((int) $result['assets'][0]['id'], (int) $result['assets'][1]['id']);
        $this->assertSame(1, (int) (Database::queryOne('SELECT COUNT(*) AS c FROM marketplace_video_assets')['c'] ?? 0));
    }

    public function testOneAssetCanBeAssignedToMultiplePagesAndRenderedActive(): void
    {
        $root = $this->makeProjectRoot();
        $service = new MarketplaceVideoAssetService($root);
        $asset = $service->uploadFile($this->makeUploadFile('shared-guide.mp4', 'shared bytes'), 0)['asset'];
        $assetId = (int) ($asset['id'] ?? 0);

        $service->assignToPage(MarketplacePageExplainerService::PAGE_DASHBOARD, 'Dashboard page guide', $assetId, true, 0);
        $service->assignToPage(MarketplacePageExplainerService::PAGE_FORMS, 'Forms page guide', $assetId, true, 0);

        $assets = $service->listAssets();
        $this->assertSame(2, (int) ($assets[0]['usage_count'] ?? 0));
        $dashboard = (new MarketplacePageExplainerService())->getActive(MarketplacePageExplainerService::PAGE_DASHBOARD);
        $this->assertNotNull($dashboard);
        $this->assertSame((string) ($asset['stored_path'] ?? ''), (string) ($dashboard['video_url'] ?? ''));
        $this->assertSame('video/mp4', (string) ($dashboard['video_asset_mime_type'] ?? ''));
        $this->assertNotSame('', (string) ($dashboard['video_asset_created_at'] ?? ''));
    }

    public function testDetachPageKeepsLibraryAssetAvailable(): void
    {
        $root = $this->makeProjectRoot();
        $service = new MarketplaceVideoAssetService($root);
        $asset = $service->uploadFile($this->makeUploadFile('detach-guide.mp4', 'detach bytes'), 0)['asset'];
        $assetId = (int) ($asset['id'] ?? 0);

        $service->assignToPage(MarketplacePageExplainerService::PAGE_FORMS, 'Forms page guide', $assetId, true, 0);
        $service->detachPage(MarketplacePageExplainerService::PAGE_FORMS, 'Forms page guide', 0);

        $page = Database::queryOne(
            "SELECT video_url, video_asset_id, is_active FROM marketplace_page_explainers WHERE page_key = ? LIMIT 1",
            [MarketplacePageExplainerService::PAGE_FORMS]
        ) ?: [];
        $this->assertSame('', (string) ($page['video_url'] ?? ''));
        $this->assertNull($page['video_asset_id'] ?? null);
        $this->assertSame(0, (int) ($page['is_active'] ?? 1));
        $this->assertNotNull($service->getAsset($assetId));
    }

    public function testDeleteAssetIsBlockedWhileAttachedToPages(): void
    {
        $root = $this->makeProjectRoot();
        $service = new MarketplaceVideoAssetService($root);
        $asset = $service->uploadFile($this->makeUploadFile('blocked-guide.mp4', 'blocked bytes'), 0)['asset'];
        $assetId = (int) ($asset['id'] ?? 0);

        $service->assignToPage(MarketplacePageExplainerService::PAGE_DASHBOARD, 'Dashboard page guide', $assetId, true, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Detach this video from these pages before deleting it');
        $service->deleteAsset($assetId);
    }

    /**
     * @return array{name:string,type:string,tmp_name:string,error:int,size:int}
     */
    private function makeUploadFile(string $name, string $contents): array
    {
        $path = tempnam(sys_get_temp_dir(), 'crm_video_asset_');
        file_put_contents($path, $contents);
        $this->tempUploads[] = (string) $path;

        return [
            'name' => $name,
            'type' => 'video/mp4',
            'tmp_name' => (string) $path,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($contents),
        ];
    }

    private function makeProjectRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm_video_library_' . bin2hex(random_bytes(6));
        mkdir($root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'marketplace', 0777, true);
        $this->tempRoots[] = $root;

        return $root;
    }

    private function removeDirectory(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->isDir()) {
                @rmdir($fileInfo->getPathname());
            } else {
                @unlink($fileInfo->getPathname());
            }
        }
        @rmdir($root);
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
                UNIQUE KEY uq_marketplace_video_assets_path (stored_path),
                UNIQUE KEY uq_marketplace_video_assets_checksum (checksum_sha256)
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
