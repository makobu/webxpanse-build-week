<?php

namespace CRM\Tests\Integration;

use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class ProductImageUploadEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    /** @var list<string> */
    private array $tempUploadRoots = [];

    protected function tearDown(): void
    {
        foreach ($this->tempUploadRoots as $root) {
            $this->removeDirectory($root);
        }
        $this->tempUploadRoots = [];

        parent::tearDown();
    }

    public function testOwnerCanUploadProductImageAndInvalidRequestsAreRejected(): void
    {
        $seed = $this->provisionWorkspace('product-image-upload');
        $uploadRoot = $this->makeUploadRoot();
        $env = [
            'CRM_ENDPOINT_TEST' => '1',
            'PRODUCT_IMAGE_UPLOAD_ROOT' => $uploadRoot,
        ];
        $gifPath = $this->writeUploadFile(
            $uploadRoot,
            'tmp/product-source.gif',
            "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;"
        );

        $success = $this->runWebEndpoint('api/workspace/product_image_upload.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'env' => $env,
            'post' => ['csrf_token' => 'csrf-product-image'],
            'files' => [
                'image_file' => [
                    'name' => 'product-source.gif',
                    'type' => 'image/gif',
                    'tmp_name' => $gifPath,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($gifPath),
                ],
            ],
        ]);
        $successData = json_decode((string) ($success['body'] ?? ''), true);

        $this->assertSame(200, (int) ($success['status'] ?? 0), (string) ($success['stderr'] ?? ''));
        $this->assertTrue((bool) ($successData['success'] ?? false), (string) ($success['body'] ?? ''));
        $this->assertStringStartsWith('uploads/products/' . (int) $seed['workspace_id'] . '/product-image-', (string) ($successData['stored_path'] ?? ''));
        $this->assertStringStartsWith('../uploads/products/' . (int) $seed['workspace_id'] . '/product-image-', (string) ($successData['html_src'] ?? ''));
        $this->assertFileExists($uploadRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) ($successData['stored_path'] ?? '')));

        $badPath = $this->writeUploadFile($uploadRoot, 'tmp/not-image.txt', 'not an image');
        $badMime = $this->runWebEndpoint('api/workspace/product_image_upload.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'env' => $env,
            'post' => ['csrf_token' => 'csrf-product-image'],
            'files' => [
                'image_file' => [
                    'name' => 'not-image.txt',
                    'type' => 'text/plain',
                    'tmp_name' => $badPath,
                    'error' => UPLOAD_ERR_OK,
                    'size' => filesize($badPath),
                ],
            ],
        ]);
        $badData = json_decode((string) ($badMime['body'] ?? ''), true);

        $this->assertSame(422, (int) ($badMime['status'] ?? 0), (string) ($badMime['stderr'] ?? ''));
        $this->assertFalse((bool) ($badData['success'] ?? true));
        $this->assertStringContainsString('JPG, PNG, WebP, or GIF', (string) ($badData['error'] ?? ''));

        $missingCsrf = $this->runWebEndpoint('api/workspace/product_image_upload.php', $this->webSession($seed, 'owner'), [
            'method' => 'POST',
            'env' => $env,
            'post' => [],
        ]);
        $this->assertSame(403, (int) ($missingCsrf['status'] ?? 0), (string) ($missingCsrf['stderr'] ?? ''));
        $this->assertStringContainsString('Invalid security token', (string) ($missingCsrf['body'] ?? ''));

        $viewerPost = $this->runWebEndpoint('api/workspace/product_image_upload.php', $this->webSession($seed, 'viewer'), [
            'method' => 'POST',
            'env' => $env,
            'post' => ['csrf_token' => 'csrf-product-image'],
        ]);
        $this->assertSame(403, (int) ($viewerPost['status'] ?? 0), (string) ($viewerPost['stderr'] ?? ''));
        $this->assertStringContainsString('permission', strtolower((string) ($viewerPost['body'] ?? '')));
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string}
     */
    private function provisionWorkspace(string $slugPrefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Product Image Upload ' . $suffix,
            'workspace_slug' => $slugPrefix . '-' . $suffix,
            'first_name' => 'Product',
            'last_name' => 'Owner',
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        $workspace = \CRM\Database::queryOne("SELECT uuid, slug, name FROM workspaces WHERE id = ?", [$workspaceId]) ?? [];
        $membership = \CRM\Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'membership_id' => (int) ($membership['id'] ?? 0),
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
        ];
    }

    private function makeUploadRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm_product_image_upload_' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);
        $this->tempUploadRoots[] = $root;

        return $root;
    }

    private function writeUploadFile(string $projectRoot, string $relativePath, string $contents): string
    {
        $path = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);

        return $path;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
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

    /**
     * @param array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int,email:string} $seed
     * @return array<string,mixed>
     */
    private function webSession(array $seed, string $role): array
    {
        return [
            'user_id' => (int) $seed['user_id'],
            'user_uuid' => 'product-image-upload-user',
            'user_email' => (string) $seed['email'],
            'user_role' => 'admin',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => $role,
            'active_workspace_membership_id' => (int) $seed['membership_id'],
            'csrf_token' => 'csrf-product-image',
            '__remember_restore_attempted' => true,
        ];
    }
}
