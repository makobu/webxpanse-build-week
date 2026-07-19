<?php

namespace CRM\Services;

use PDO;

class BackupRestoreReadinessService
{
    private const DEFAULT_DB_MAX_AGE_HOURS = 24;
    private const DEFAULT_UPLOADS_MAX_AGE_HOURS = 48;
    private const DEFAULT_RESTORE_DRILL_MAX_AGE_DAYS = 90;
    private const DEFAULT_RETENTION_DAYS = 7;

    /** @var array<int,string> */
    private const DB_BACKUP_PATTERNS = [
        'db_*.sql',
        'db_*.sql.gz',
        'db_*.sql.zip',
        '*.sql',
        '*.sql.gz',
        '*.sql.zip',
        '*.dump',
        '*.dump.gz',
    ];

    /** @var array<int,string> */
    private const UPLOADS_BACKUP_PATTERNS = [
        'uploads_*.tar',
        'uploads_*.tar.gz',
        'uploads_*.tgz',
        'uploads_*.zip',
        'files_*.tar.gz',
        'files_*.zip',
    ];

    public function __construct(
        private ?PDO $pdo = null,
        private ?string $rootPath = null
    ) {
        $this->rootPath = rtrim($rootPath ?: dirname(__DIR__), "\\/");
    }

    /**
     * @return array<string,mixed>
     */
    public function check(): array
    {
        $env = $this->loadSafeEnvironment();
        $findings = [];
        $summary = [
            'backup_scripts_present' => false,
            'windows_backup_script_present' => false,
            'backup_dir_configured' => false,
            'backup_dirs_checked' => 0,
            'db_backup_count' => 0,
            'uploads_backup_count' => 0,
            'latest_db_backup_at' => null,
            'latest_uploads_backup_at' => null,
            'db_backup_age_hours' => null,
            'uploads_backup_age_hours' => null,
            'db_backup_max_age_hours' => $this->positiveInt($env['BACKUP_MAX_AGE_HOURS'] ?? null, self::DEFAULT_DB_MAX_AGE_HOURS),
            'uploads_backup_max_age_hours' => $this->positiveInt($env['UPLOADS_BACKUP_MAX_AGE_HOURS'] ?? null, self::DEFAULT_UPLOADS_MAX_AGE_HOURS),
            'retention_days' => $this->positiveInt($env['RETENTION_DAYS'] ?? null, self::DEFAULT_RETENTION_DAYS),
            'retention_weekly' => $this->positiveInt($env['RETENTION_WEEKLY'] ?? null, 4),
            'uploads_file_count' => $this->countUploadFiles(),
            'mysqldump_available' => false,
            'mysql_client_available' => false,
            'archive_tool_available' => false,
            'runbook_present' => false,
            'offsite_configured' => false,
            'restore_drill_recorded' => false,
            'restore_drill_age_days' => null,
            'database_connection_available' => $this->pdo instanceof PDO,
            'findings' => 0,
            'critical' => 0,
            'warning' => 0,
            'by_rule' => [],
        ];

        $expectations = [
            'database_backup' => 'A restorable database dump exists and is newer than ' . $summary['db_backup_max_age_hours'] . ' hour(s).',
            'uploads_backup' => 'Uploaded files are archived when uploads exist and the archive is newer than ' . $summary['uploads_backup_max_age_hours'] . ' hour(s).',
            'restore_access' => 'Both mysqldump and mysql client binaries are available to backup and restore the configured database.',
            'offsite_copy' => 'At least one offsite or host-level backup destination is configured outside the application tree.',
            'restore_drill' => 'A restore drill or restore access check is recorded within ' . self::DEFAULT_RESTORE_DRILL_MAX_AGE_DAYS . ' days.',
            'runbook' => 'A live-server backup and restore runbook exists for the operator.',
        ];

        $this->checkScripts($findings, $summary);
        $backupDirs = $this->backupDirectories($env, $summary);
        $dbArtifacts = $this->scanBackupArtifacts($backupDirs, self::DB_BACKUP_PATTERNS);
        $uploadsArtifacts = $this->scanBackupArtifacts($backupDirs, self::UPLOADS_BACKUP_PATTERNS);
        $this->checkBackupArtifacts('database', $dbArtifacts, (int) $summary['db_backup_max_age_hours'], true, $findings, $summary);
        $this->checkBackupArtifacts('uploads', $uploadsArtifacts, (int) $summary['uploads_backup_max_age_hours'], (int) $summary['uploads_file_count'] > 0, $findings, $summary);
        $this->checkRestoreTools($env, $findings, $summary);
        $this->checkRetentionAndLocation($backupDirs, $env, $findings, $summary);
        $this->checkRunbook($env, $findings, $summary);
        $this->checkOffsiteCopy($env, $findings, $summary);
        $this->checkRestoreDrill($env, $findings, $summary);

        foreach ($findings as $finding) {
            $severity = (string) ($finding['severity'] ?? 'warning');
            if (!isset($summary[$severity])) {
                $severity = 'warning';
            }
            $summary[$severity]++;
            $summary['findings']++;
            $rule = (string) ($finding['rule'] ?? 'unknown');
            $summary['by_rule'][$rule] = (int) ($summary['by_rule'][$rule] ?? 0) + 1;
        }

        return [
            'status' => $summary['critical'] > 0 ? 'critical' : ($summary['warning'] > 0 ? 'warning' : 'ok'),
            'summary' => $summary,
            'expectations' => $expectations,
            'artifacts' => [
                'backup_directories' => $backupDirs,
                'latest_database_backup' => $dbArtifacts['latest'],
                'latest_uploads_backup' => $uploadsArtifacts['latest'],
            ],
            'tools' => [
                'mysqldump' => $summary['mysqldump_tool'] ?? null,
                'mysql' => $summary['mysql_tool'] ?? null,
                'archive' => $summary['archive_tool'] ?? null,
            ],
            'findings' => $findings,
            'checked_at' => date('c'),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkScripts(array &$findings, array &$summary): void
    {
        $shellScript = $this->rootPath . '/scripts/backup.sh';
        $windowsScript = $this->rootPath . '/scripts/backup.bat';
        $summary['backup_scripts_present'] = is_file($shellScript);
        $summary['windows_backup_script_present'] = is_file($windowsScript);

        if (!is_file($shellScript)) {
            $this->addFinding($findings, 'critical', 'backup_script_missing', 'The Linux backup script is missing.', [
                'target' => 'scripts/backup.sh',
                'recommendation' => 'Restore scripts/backup.sh before relying on live server backups.',
            ]);
        }

        if (!is_file($windowsScript)) {
            $this->addFinding($findings, 'warning', 'windows_backup_script_missing', 'The Windows backup script is missing.', [
                'target' => 'scripts/backup.bat',
                'recommendation' => 'Restore scripts/backup.bat for local/XAMPP backup parity.',
            ]);
        }
    }

    /**
     * @param array<string,string> $env
     * @param array<string,mixed> $summary
     * @return array<int,array<string,mixed>>
     */
    private function backupDirectories(array $env, array &$summary): array
    {
        $dirs = [];
        $configured = trim((string) ($env['BACKUP_DIR'] ?? ''));
        if ($configured !== '') {
            $summary['backup_dir_configured'] = true;
            $dirs[] = $this->directoryState($this->resolvePath($configured), 'BACKUP_DIR');
        }

        $defaultDir = $this->rootPath . '/backups';
        if ($configured === '' || is_dir($defaultDir)) {
            $dirs[] = $this->directoryState($defaultDir, 'default');
        }

        $unique = [];
        foreach ($dirs as $dir) {
            $key = strtolower((string) ($dir['path'] ?? ''));
            $unique[$key] = $dir;
        }

        $dirs = array_values($unique);
        $summary['backup_dirs_checked'] = count($dirs);
        return $dirs;
    }

    /**
     * @return array<string,mixed>
     */
    private function directoryState(string $path, string $source): array
    {
        $normalized = $this->normalizePath($path);
        return [
            'path' => $normalized,
            'source' => $source,
            'exists' => is_dir($normalized),
            'readable' => is_readable($normalized),
            'inside_public' => $this->isInside($normalized, $this->rootPath . '/public'),
            'inside_app_root' => $this->isInside($normalized, $this->rootPath),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $dirs
     * @param array<int,string> $patterns
     * @return array{count:int,latest:?array<string,mixed>}
     */
    private function scanBackupArtifacts(array $dirs, array $patterns): array
    {
        $count = 0;
        $latest = null;

        foreach ($dirs as $dir) {
            $path = (string) ($dir['path'] ?? '');
            if ($path === '' || empty($dir['exists']) || empty($dir['readable'])) {
                continue;
            }

            try {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
                );
            } catch (\Throwable $e) {
                continue;
            }

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                if (!$this->matchesAnyPattern($file->getFilename(), $patterns)) {
                    continue;
                }

                $count++;
                $mtime = $file->getMTime();
                if ($latest === null || $mtime > (int) ($latest['mtime'] ?? 0)) {
                    $latest = [
                        'path' => $this->normalizePath($file->getPathname()),
                        'filename' => $file->getFilename(),
                        'size_bytes' => $file->getSize(),
                        'mtime' => $mtime,
                        'modified_at' => date('c', $mtime),
                        'age_hours' => round(max(0, time() - $mtime) / 3600, 2),
                    ];
                }
            }
        }

        return ['count' => $count, 'latest' => $latest];
    }

    /**
     * @param array{count:int,latest:?array<string,mixed>} $artifacts
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkBackupArtifacts(string $kind, array $artifacts, int $maxAgeHours, bool $required, array &$findings, array &$summary): void
    {
        $summary[$kind === 'database' ? 'db_backup_count' : 'uploads_backup_count'] = (int) $artifacts['count'];
        $latest = is_array($artifacts['latest'] ?? null) ? $artifacts['latest'] : null;
        $summaryKeyAt = $kind === 'database' ? 'latest_db_backup_at' : 'latest_uploads_backup_at';
        $summaryKeyAge = $kind === 'database' ? 'db_backup_age_hours' : 'uploads_backup_age_hours';

        if ($latest === null) {
            if ($required) {
                $this->addFinding($findings, 'critical', $kind . '_backup_missing', ucfirst($kind) . ' backup artifact was not found.', [
                    'target' => 'backup:' . $kind,
                    'recommendation' => $kind === 'database'
                        ? 'Run scripts/backup.sh or configure a host-level database backup before live upload.'
                        : 'Create or configure an uploads/files archive before live upload.',
                ]);
            } else {
                $this->addFinding($findings, 'warning', $kind . '_backup_not_present', ucfirst($kind) . ' backup artifact was not found.', [
                    'target' => 'backup:' . $kind,
                    'recommendation' => 'Confirm no uploaded files need recovery, or create an uploads archive before live upload.',
                ]);
            }
            return;
        }

        $summary[$summaryKeyAt] = (string) ($latest['modified_at'] ?? '');
        $summary[$summaryKeyAge] = (float) ($latest['age_hours'] ?? 0.0);

        if ((int) ($latest['size_bytes'] ?? 0) <= 0) {
            $this->addFinding($findings, 'critical', $kind . '_backup_empty', ucfirst($kind) . ' backup artifact is empty.', [
                'target' => (string) ($latest['filename'] ?? 'backup'),
                'path' => (string) ($latest['path'] ?? ''),
                'recommendation' => 'Discard the empty artifact and create a fresh backup.',
            ]);
        }

        if ((float) ($latest['age_hours'] ?? 0.0) > $maxAgeHours) {
            $this->addFinding($findings, 'critical', $kind . '_backup_stale', ucfirst($kind) . ' backup is older than the allowed freshness window.', [
                'target' => (string) ($latest['filename'] ?? 'backup'),
                'path' => (string) ($latest['path'] ?? ''),
                'age_hours' => (float) ($latest['age_hours'] ?? 0.0),
                'max_age_hours' => $maxAgeHours,
                'recommendation' => 'Create a fresh ' . $kind . ' backup and verify it is readable before live upload.',
            ]);
        }
    }

    /**
     * @param array<string,string> $env
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkRestoreTools(array $env, array &$findings, array &$summary): void
    {
        $mysqldump = $this->findBinary('MYSQLDUMP_BIN', ['mysqldump'], [
            'C:/xampp/mysql/bin/mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ], $env);
        $mysql = $this->findBinary('MYSQL_BIN', ['mysql'], [
            'C:/xampp/mysql/bin/mysql.exe',
            '/usr/bin/mysql',
            '/usr/local/bin/mysql',
        ], $env);
        $archive = $this->findBinary('TAR_BIN', ['tar', 'bsdtar'], [
            'C:/Windows/System32/tar.exe',
            '/usr/bin/tar',
            '/bin/tar',
        ], $env);

        $summary['mysqldump_available'] = (bool) ($mysqldump['available'] ?? false);
        $summary['mysql_client_available'] = (bool) ($mysql['available'] ?? false);
        $summary['archive_tool_available'] = (bool) ($archive['available'] ?? false);
        $summary['mysqldump_tool'] = $mysqldump;
        $summary['mysql_tool'] = $mysql;
        $summary['archive_tool'] = $archive;

        if (empty($mysqldump['available'])) {
            $this->addFinding($findings, 'critical', 'mysqldump_unavailable', 'mysqldump is not available for database backups.', [
                'target' => 'mysqldump',
                'recommendation' => 'Install MySQL client tools or set MYSQLDUMP_BIN to the live server mysqldump path.',
            ]);
        }
        if (empty($mysql['available'])) {
            $this->addFinding($findings, 'critical', 'mysql_restore_client_unavailable', 'mysql client is not available for database restore.', [
                'target' => 'mysql',
                'recommendation' => 'Install MySQL client tools or set MYSQL_BIN to the live server mysql path.',
            ]);
        }
        if (empty($archive['available'])) {
            $this->addFinding($findings, 'warning', 'archive_tool_unavailable', 'tar/bsdtar is not available for uploads archive restore.', [
                'target' => 'tar',
                'recommendation' => 'Install tar or set TAR_BIN to the archive tool path used by restore operations.',
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $backupDirs
     * @param array<string,string> $env
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkRetentionAndLocation(array $backupDirs, array $env, array &$findings, array &$summary): void
    {
        if ((int) ($summary['retention_days'] ?? 0) < self::DEFAULT_RETENTION_DAYS) {
            $this->addFinding($findings, 'warning', 'backup_retention_too_short', 'Backup retention is shorter than the production minimum.', [
                'target' => 'RETENTION_DAYS',
                'retention_days' => (int) ($summary['retention_days'] ?? 0),
                'minimum_days' => self::DEFAULT_RETENTION_DAYS,
                'recommendation' => 'Keep at least 7 daily backups before live upload.',
            ]);
        }

        foreach ($backupDirs as $dir) {
            if (empty($dir['exists'])) {
                $this->addFinding($findings, 'critical', 'backup_directory_missing', 'A configured backup directory does not exist.', [
                    'target' => (string) ($dir['source'] ?? 'backup_dir'),
                    'path' => (string) ($dir['path'] ?? ''),
                    'recommendation' => 'Create the backup directory and confirm the web server user can write to it.',
                ]);
                continue;
            }
            if (empty($dir['readable'])) {
                $this->addFinding($findings, 'critical', 'backup_directory_unreadable', 'A backup directory is not readable.', [
                    'target' => (string) ($dir['source'] ?? 'backup_dir'),
                    'path' => (string) ($dir['path'] ?? ''),
                    'recommendation' => 'Fix backup directory permissions before relying on restore operations.',
                ]);
            }
            if (!empty($dir['inside_public'])) {
                $this->addFinding($findings, 'critical', 'backup_directory_public', 'A backup directory is inside public webroot.', [
                    'target' => (string) ($dir['source'] ?? 'backup_dir'),
                    'path' => (string) ($dir['path'] ?? ''),
                    'recommendation' => 'Move backups outside public/ so database dumps cannot be downloaded.',
                ]);
            } elseif (!empty($dir['inside_app_root']) && trim((string) ($env['BACKUP_DIR'] ?? '')) !== '') {
                $this->addFinding($findings, 'warning', 'backup_directory_inside_app_root', 'The configured backup directory is inside the application tree.', [
                    'target' => (string) ($dir['source'] ?? 'backup_dir'),
                    'path' => (string) ($dir['path'] ?? ''),
                    'recommendation' => 'Prefer a host-level or offsite backup directory outside the deployed app tree.',
                ]);
            }
        }
    }

    /**
     * @param array<string,string> $env
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkRunbook(array $env, array &$findings, array &$summary): void
    {
        $runbook = trim((string) ($env['BACKUP_RUNBOOK_PATH'] ?? ''));
        $path = $runbook !== '' ? $this->resolvePath($runbook) : $this->rootPath . '/docs/backup-restore-readiness.md';
        $summary['runbook_present'] = is_file($path);
        $summary['runbook_path'] = $this->normalizePath($path);

        if (!is_file($path)) {
            $this->addFinding($findings, 'warning', 'restore_runbook_missing', 'A backup and restore runbook was not found.', [
                'target' => 'backup_restore_runbook',
                'path' => $this->normalizePath($path),
                'recommendation' => 'Create a short live-server restore runbook with backup location, restore steps, and recovery owner.',
            ]);
        }
    }

    /**
     * @param array<string,string> $env
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkOffsiteCopy(array $env, array &$findings, array &$summary): void
    {
        $keys = ['BACKUP_OFFSITE_PATH', 'BACKUP_REMOTE_PATH', 'BACKUP_REMOTE_URL', 'BACKUP_REMOTE_BUCKET', 'BACKUP_PROVIDER'];
        foreach ($keys as $key) {
            if (trim((string) ($env[$key] ?? '')) !== '') {
                $summary['offsite_configured'] = true;
                $summary['offsite_source'] = $key;
                return;
            }
        }

        $this->addFinding($findings, 'warning', 'offsite_backup_not_configured', 'No offsite backup destination is configured.', [
            'target' => 'offsite_backup',
            'recommendation' => 'Configure host-level, cloud, or remote backups outside the live server filesystem.',
        ]);
    }

    /**
     * @param array<string,string> $env
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function checkRestoreDrill(array $env, array &$findings, array &$summary): void
    {
        $value = trim((string) (($env['BACKUP_RESTORE_DRILL_AT'] ?? '') ?: ($env['BACKUP_RESTORE_TESTED_AT'] ?? '')));
        if ($value === '') {
            $this->addFinding($findings, 'warning', 'restore_drill_not_recorded', 'No restore drill or restore access check date is recorded.', [
                'target' => 'BACKUP_RESTORE_DRILL_AT',
                'recommendation' => 'Record the last successful restore drill date after testing a backup in a safe environment.',
            ]);
            return;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            $this->addFinding($findings, 'warning', 'restore_drill_date_invalid', 'Restore drill date is not parseable.', [
                'target' => 'BACKUP_RESTORE_DRILL_AT',
                'value' => $value,
                'recommendation' => 'Use an ISO date such as 2026-07-04 for BACKUP_RESTORE_DRILL_AT.',
            ]);
            return;
        }

        $ageDays = (int) floor(max(0, time() - $timestamp) / 86400);
        $summary['restore_drill_recorded'] = true;
        $summary['restore_drill_age_days'] = $ageDays;
        $summary['restore_drill_at'] = date('Y-m-d', $timestamp);

        if ($ageDays > self::DEFAULT_RESTORE_DRILL_MAX_AGE_DAYS) {
            $this->addFinding($findings, 'warning', 'restore_drill_stale', 'The recorded restore drill is older than the expected window.', [
                'target' => 'BACKUP_RESTORE_DRILL_AT',
                'age_days' => $ageDays,
                'max_age_days' => self::DEFAULT_RESTORE_DRILL_MAX_AGE_DAYS,
                'recommendation' => 'Run and record a fresh restore drill before live upload.',
            ]);
        }
    }

    /**
     * @param array<string,string> $env
     * @param array<int,string> $names
     * @param array<int,string> $commonPaths
     * @return array<string,mixed>
     */
    private function findBinary(string $envKey, array $names, array $commonPaths, array $env): array
    {
        $configured = trim((string) ($env[$envKey] ?? ''));
        if ($configured !== '') {
            $path = $this->resolvePath($configured);
            return [
                'available' => is_file($path),
                'path' => $this->normalizePath($path),
                'source' => $envKey,
            ];
        }

        foreach ($this->pathCandidates($names, $commonPaths) as $candidate) {
            if (is_file($candidate)) {
                return [
                    'available' => true,
                    'path' => $this->normalizePath($candidate),
                    'source' => 'auto_detected',
                ];
            }
        }

        return ['available' => false, 'path' => '', 'source' => 'not_found'];
    }

    /**
     * @param array<int,string> $names
     * @param array<int,string> $commonPaths
     * @return array<int,string>
     */
    private function pathCandidates(array $names, array $commonPaths): array
    {
        $candidates = $commonPaths;
        $extensions = DIRECTORY_SEPARATOR === '\\' ? ['', '.exe', '.bat', '.cmd'] : [''];
        $paths = explode(PATH_SEPARATOR, (string) getenv('PATH'));
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '') {
                continue;
            }
            foreach ($names as $name) {
                foreach ($extensions as $extension) {
                    $candidates[] = rtrim($path, "\\/") . DIRECTORY_SEPARATOR . $name . $extension;
                }
            }
        }

        return array_values(array_unique(array_filter($candidates, static fn(string $path): bool => trim($path) !== '')));
    }

    /**
     * @return array<string,string>
     */
    private function loadSafeEnvironment(): array
    {
        $keys = [
            'BACKUP_DIR',
            'BACKUP_MAX_AGE_HOURS',
            'UPLOADS_BACKUP_MAX_AGE_HOURS',
            'RETENTION_DAYS',
            'RETENTION_WEEKLY',
            'BACKUP_OFFSITE_PATH',
            'BACKUP_REMOTE_PATH',
            'BACKUP_REMOTE_URL',
            'BACKUP_REMOTE_BUCKET',
            'BACKUP_PROVIDER',
            'BACKUP_RUNBOOK_PATH',
            'BACKUP_RESTORE_DRILL_AT',
            'BACKUP_RESTORE_TESTED_AT',
            'MYSQLDUMP_BIN',
            'MYSQL_BIN',
            'TAR_BIN',
        ];
        $allowed = array_fill_keys($keys, true);
        $values = [];

        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value !== false && trim((string) $value) !== '') {
                $values[$key] = trim((string) $value);
            } elseif (isset($_ENV[$key]) && trim((string) $_ENV[$key]) !== '') {
                $values[$key] = trim((string) $_ENV[$key]);
            }
        }

        $envFile = $this->rootPath . '/.env';
        if (is_file($envFile) && is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES) ?: [];
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                if (!isset($allowed[$key]) || isset($values[$key])) {
                    continue;
                }
                $values[$key] = trim($value, "\"'");
            }
        }

        return $values;
    }

    private function countUploadFiles(): int
    {
        $uploads = $this->rootPath . '/uploads';
        if (!is_dir($uploads) || !is_readable($uploads)) {
            return 0;
        }

        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($uploads, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()) {
                    $count++;
                }
            }
        } catch (\Throwable $e) {
            return 0;
        }

        return $count;
    }

    private function matchesAnyPattern(string $filename, array $patterns): bool
    {
        $filename = strtolower($filename);
        foreach ($patterns as $pattern) {
            $regex = '/^' . str_replace('\*', '.*', preg_quote(strtolower($pattern), '/')) . '$/';
            if (preg_match($regex, $filename) === 1) {
                return true;
            }
        }
        return false;
    }

    private function positiveInt(mixed $value, int $default): int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($int) && $int > 0 ? $int : $default;
    }

    private function resolvePath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return $this->rootPath;
        }
        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1 || str_starts_with($path, '/') || str_starts_with($path, '\\\\')) {
            return $path;
        }
        return $this->rootPath . '/' . $path;
    }

    private function normalizePath(string $path): string
    {
        $real = realpath($path);
        return str_replace('\\', '/', $real !== false ? $real : $path);
    }

    private function isInside(string $path, string $parent): bool
    {
        $realPath = realpath($path);
        $realParent = realpath($parent);
        if ($realPath === false || $realParent === false) {
            return false;
        }
        $path = rtrim(str_replace('\\', '/', $realPath), '/') . '/';
        $parent = rtrim(str_replace('\\', '/', $realParent), '/') . '/';
        return str_starts_with($path, $parent);
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $context
     */
    private function addFinding(array &$findings, string $severity, string $rule, string $message, array $context): void
    {
        $findings[] = [
            'severity' => $severity,
            'rule' => $rule,
            'message' => $message,
        ] + $context;
    }
}
