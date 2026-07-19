<?php

namespace CRM\Services;

class RuntimePathService
{
    /** @var array<int,string> */
    private const REQUIRED_PATHS = ['cache', 'logs', 'tmp', 'uploads'];

    private string $rootPath;

    public function __construct(?string $rootPath = null)
    {
        $this->rootPath = rtrim($rootPath ?: dirname(__DIR__), "\\/");
    }

    /**
     * @return array<int,string>
     */
    public static function requiredPaths(): array
    {
        return self::REQUIRED_PATHS;
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        return $this->buildResult([]);
    }

    /**
     * @return array<string,mixed>
     */
    public function ensure(int $mode = 0775): array
    {
        $created = [];
        foreach (self::REQUIRED_PATHS as $relativePath) {
            $fullPath = $this->absolutePath($relativePath);
            if (!is_dir($fullPath)) {
                @mkdir($fullPath, $mode, true);
                if (is_dir($fullPath)) {
                    $created[] = $relativePath;
                }
            }

            if (is_dir($fullPath)) {
                @chmod($fullPath, $mode);
            }
        }

        return $this->buildResult($created);
    }

    /**
     * @param array<int,string> $created
     * @return array<string,mixed>
     */
    private function buildResult(array $created): array
    {
        $missing = [];
        $notWritable = [];
        $details = [];

        foreach (self::REQUIRED_PATHS as $relativePath) {
            $fullPath = $this->absolutePath($relativePath);
            $exists = is_dir($fullPath);
            $writable = $exists && is_writable($fullPath);
            if (!$exists) {
                $missing[] = $relativePath;
            }
            if (!$writable) {
                $notWritable[] = $relativePath;
            }

            $details[] = [
                'path' => $relativePath,
                'exists' => $exists,
                'writable' => $writable,
                'created' => in_array($relativePath, $created, true),
            ];
        }

        return [
            'paths_checked' => self::REQUIRED_PATHS,
            'created' => $created,
            'missing' => $missing,
            'not_writable' => $notWritable,
            'details' => $details,
        ];
    }

    private function absolutePath(string $relativePath): string
    {
        return $this->rootPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
