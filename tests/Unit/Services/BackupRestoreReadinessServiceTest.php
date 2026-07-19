<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\BackupRestoreReadinessService;
use CRM\Tests\DatabaseTestCase;

class BackupRestoreReadinessServiceTest extends DatabaseTestCase
{
    public function testFreshBackupsAndRecoveryEvidencePass(): void
    {
        [$root, $backupDir, $binDir] = $this->createReadinessFixture(true, true);

        try {
            $this->withBackupEnv($root, $backupDir, $binDir, function () use ($root): void {
                $report = (new BackupRestoreReadinessService(Database::getInstance(), $root))->check();
                $summary = (array) ($report['summary'] ?? []);

                $this->assertSame('ok', $report['status'] ?? null, json_encode($report['findings'] ?? []));
                $this->assertSame(1, (int) ($summary['db_backup_count'] ?? 0));
                $this->assertSame(1, (int) ($summary['uploads_backup_count'] ?? 0));
                $this->assertTrue((bool) ($summary['mysqldump_available'] ?? false));
                $this->assertTrue((bool) ($summary['mysql_client_available'] ?? false));
                $this->assertTrue((bool) ($summary['archive_tool_available'] ?? false));
                $this->assertTrue((bool) ($summary['offsite_configured'] ?? false));
                $this->assertTrue((bool) ($summary['restore_drill_recorded'] ?? false));
                $this->assertArrayHasKey('expectations', $report);
            });
        } finally {
            $this->removeDirectory($root);
            $this->removeDirectory($backupDir);
            $this->removeDirectory($binDir);
        }
    }

    public function testMissingBackupArtifactsAreCritical(): void
    {
        [$root, $backupDir, $binDir] = $this->createReadinessFixture(false, false);

        try {
            $this->withBackupEnv($root, $backupDir, $binDir, function () use ($root): void {
                $report = (new BackupRestoreReadinessService(Database::getInstance(), $root))->check();
                $rules = $this->findingRules($report);

                $this->assertSame('critical', $report['status'] ?? null);
                $this->assertContains('database_backup_missing', $rules);
                $this->assertContains('uploads_backup_missing', $rules);
            });
        } finally {
            $this->removeDirectory($root);
            $this->removeDirectory($backupDir);
            $this->removeDirectory($binDir);
        }
    }

    public function testStaleDatabaseBackupIsCritical(): void
    {
        [$root, $backupDir, $binDir] = $this->createReadinessFixture(true, true);
        touch($backupDir . DIRECTORY_SEPARATOR . 'db_fresh.sql', time() - (72 * 3600));

        try {
            $this->withBackupEnv($root, $backupDir, $binDir, function () use ($root): void {
                $report = (new BackupRestoreReadinessService(Database::getInstance(), $root))->check();

                $this->assertSame('critical', $report['status'] ?? null);
                $this->assertContains('database_backup_stale', $this->findingRules($report));
            });
        } finally {
            $this->removeDirectory($root);
            $this->removeDirectory($backupDir);
            $this->removeDirectory($binDir);
        }
    }

    /**
     * @return array{0:string,1:string,2:string}
     */
    private function createReadinessFixture(bool $withDatabaseBackup, bool $withUploadsBackup): array
    {
        $token = bin2hex(random_bytes(6));
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm-backup-root-' . $token;
        $backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm-backup-artifacts-' . $token;
        $binDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm-backup-bin-' . $token;

        mkdir($root . DIRECTORY_SEPARATOR . 'scripts', 0777, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'docs', 0777, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'uploads', 0777, true);
        mkdir($backupDir, 0777, true);
        mkdir($binDir, 0777, true);

        file_put_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup.sh', '#!/bin/sh');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'backup.bat', '@echo off');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'docs' . DIRECTORY_SEPARATOR . 'backup-restore-readiness.md', '# Backup');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'asset.txt', 'asset');
        file_put_contents($binDir . DIRECTORY_SEPARATOR . 'mysqldump', 'tool');
        file_put_contents($binDir . DIRECTORY_SEPARATOR . 'mysql', 'tool');
        file_put_contents($binDir . DIRECTORY_SEPARATOR . 'tar', 'tool');

        if ($withDatabaseBackup) {
            file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'db_fresh.sql', str_repeat('insert into t values (1);', 20));
        }
        if ($withUploadsBackup) {
            file_put_contents($backupDir . DIRECTORY_SEPARATOR . 'uploads_fresh.tar.gz', str_repeat('archive', 20));
        }

        return [$root, $backupDir, $binDir];
    }

    private function withBackupEnv(string $root, string $backupDir, string $binDir, callable $callback): void
    {
        $values = [
            'BACKUP_DIR' => $backupDir,
            'BACKUP_MAX_AGE_HOURS' => '24',
            'UPLOADS_BACKUP_MAX_AGE_HOURS' => '48',
            'RETENTION_DAYS' => '14',
            'RETENTION_WEEKLY' => '4',
            'BACKUP_OFFSITE_PATH' => 's3://crm-backups',
            'BACKUP_RUNBOOK_PATH' => 'docs/backup-restore-readiness.md',
            'BACKUP_RESTORE_DRILL_AT' => date('Y-m-d'),
            'MYSQLDUMP_BIN' => $binDir . DIRECTORY_SEPARATOR . 'mysqldump',
            'MYSQL_BIN' => $binDir . DIRECTORY_SEPARATOR . 'mysql',
            'TAR_BIN' => $binDir . DIRECTORY_SEPARATOR . 'tar',
        ];
        $old = [];
        foreach ($values as $key => $value) {
            $old[$key] = [getenv($key), $_ENV[$key] ?? null];
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }

        try {
            $callback();
        } finally {
            foreach ($old as $key => [$oldEnv, $oldArray]) {
                if ($oldEnv === false) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $oldEnv);
                }
                if ($oldArray === null) {
                    unset($_ENV[$key]);
                } else {
                    $_ENV[$key] = $oldArray;
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,string>
     */
    private function findingRules(array $report): array
    {
        return array_values(array_map(
            static fn(array $finding): string => (string) ($finding['rule'] ?? ''),
            array_filter((array) ($report['findings'] ?? []), 'is_array')
        ));
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
