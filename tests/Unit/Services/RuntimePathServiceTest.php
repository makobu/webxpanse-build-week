<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\RuntimePathService;
use PHPUnit\Framework\TestCase;

class RuntimePathServiceTest extends TestCase
{
    public function testEnsureCreatesRequiredRuntimePaths(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm-runtime-paths-' . bin2hex(random_bytes(6));
        mkdir($root, 0777, true);

        try {
            $service = new RuntimePathService($root);
            $before = $service->status();
            $after = $service->ensure();

            $this->assertSame(RuntimePathService::requiredPaths(), $before['missing'] ?? []);
            $this->assertSame([], $after['missing'] ?? ['missing']);
            $this->assertSame([], $after['not_writable'] ?? ['missing']);
            $this->assertSame(RuntimePathService::requiredPaths(), $after['created'] ?? []);
            foreach (RuntimePathService::requiredPaths() as $path) {
                $this->assertDirectoryExists($root . DIRECTORY_SEPARATOR . $path);
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
