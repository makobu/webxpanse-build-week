<?php

declare(strict_types=1);

namespace CRM\Modules;

use CRM\Database;

class DemoModeManager
{
    private static ?array $cachedState = null;

    public function getState(): array
    {
        $this->ensureTables();

        $state = Database::queryOne('SELECT * FROM demo_mode_state WHERE id = 1 LIMIT 1');
        if (!$state) {
            Database::execute('INSERT INTO demo_mode_state (id, is_enabled, simulation_only) VALUES (1, 0, 1)');
            $state = Database::queryOne('SELECT * FROM demo_mode_state WHERE id = 1 LIMIT 1') ?: [];
        }

        $run = null;
        $runId = (int) ($state['active_run_id'] ?? 0);
        if ($runId > 0) {
            $run = Database::queryOne('SELECT * FROM demo_runs WHERE id = ? LIMIT 1', [$runId]);
        }

        $seedCount = 0;
        if ($runId > 0 && $this->tableExists('demo_seed_registry')) {
            $row = Database::queryOne('SELECT COUNT(*) AS c FROM demo_seed_registry WHERE run_id = ?', [$runId]);
            $seedCount = (int) ($row['c'] ?? 0);
        }

        $lastLog = null;
        if ($this->tableExists('demo_operation_logs')) {
            $lastLog = Database::queryOne('SELECT * FROM demo_operation_logs ORDER BY id DESC LIMIT 1');
        }

        $result = [
            'is_enabled' => ((int) ($state['is_enabled'] ?? 0)) === 1,
            'simulation_only' => ((int) ($state['simulation_only'] ?? 1)) === 1,
            'active_run_id' => $runId > 0 ? $runId : null,
            'active_run' => $run,
            'seeded_records' => $seedCount,
            'last_operation' => $lastLog,
            'updated_at' => $state['updated_at'] ?? null,
        ];

        self::$cachedState = $result;
        return $result;
    }

    public function enable(int $adminUserId, string $seedProfile = 'full'): array
    {
        $this->ensureTables();
        $seedProfile = trim($seedProfile) !== '' ? trim($seedProfile) : 'full';

        Database::beginTransaction();
        try {
            $runUuid = $this->uuid();
            Database::execute(
                'INSERT INTO demo_runs (run_uuid, status, seed_profile, created_by, notes) VALUES (?, ?, ?, ?, ?)',
                [$runUuid, 'active', $seedProfile, $adminUserId, 'Demo mode enabled']
            );
            $runId = (int) Database::lastInsertId();

            Database::execute(
                'UPDATE demo_mode_state SET is_enabled = 1, simulation_only = 1, active_run_id = ?, updated_by = ? WHERE id = 1',
                [$runId, $adminUserId]
            );

            $this->logOperation($runId, 'toggle_on', 'success', 'Demo mode enabled', $adminUserId);

            $seeder = new DemoSeedService();
            $seedSummary = $seeder->seedEverything($runId, $adminUserId, $seedProfile);
            $seedErrorCount = count($seedSummary['errors'] ?? []);
            if ($seedErrorCount > 0) {
                $this->logOperation($runId, 'seed', 'failed', 'Seed completed with errors: ' . $seedErrorCount, $adminUserId);
            } else {
                $this->logOperation($runId, 'seed', 'success', 'Seed completed successfully', $adminUserId);
            }

            Database::commit();
            self::$cachedState = null;

            return [
                'success' => true,
                'run_id' => $runId,
                'run_uuid' => $runUuid,
                'seed_summary' => $seedSummary,
                'state' => $this->getState(),
            ];
        } catch (\Throwable $e) {
            Database::rollBack();
            $this->logOperation(null, 'toggle_on', 'failed', $e->getMessage(), $adminUserId);
            self::$cachedState = null;
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function disable(int $adminUserId): array
    {
        $this->ensureTables();

        Database::beginTransaction();
        try {
            $state = Database::queryOne('SELECT * FROM demo_mode_state WHERE id = 1 LIMIT 1') ?: [];
            $runId = (int) ($state['active_run_id'] ?? 0);

            Database::execute(
                'UPDATE demo_mode_state SET is_enabled = 0, active_run_id = NULL, updated_by = ? WHERE id = 1',
                [$adminUserId]
            );

            if ($runId > 0) {
                Database::execute(
                    "UPDATE demo_runs SET status = 'inactive', deactivated_at = NOW() WHERE id = ?",
                    [$runId]
                );
            }

            $this->logOperation($runId > 0 ? $runId : null, 'toggle_off', 'success', 'Demo mode disabled; data retained until purge', $adminUserId);
            Database::commit();

            self::$cachedState = null;
            return ['success' => true, 'state' => $this->getState()];
        } catch (\Throwable $e) {
            Database::rollBack();
            $this->logOperation(null, 'toggle_off', 'failed', $e->getMessage(), $adminUserId);
            self::$cachedState = null;
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function seed(int $runId, int $adminUserId, string $seedProfile = 'full'): array
    {
        $this->ensureTables();
        if ($runId <= 0) {
            return ['success' => false, 'error' => 'Invalid run id'];
        }

        try {
            $summary = (new DemoSeedService())->seedEverything($runId, $adminUserId, $seedProfile);
            $result = count($summary['errors'] ?? []) > 0 ? 'failed' : 'success';
            $this->logOperation($runId, 'reseed', $result, 'Reseed completed', $adminUserId);
            self::$cachedState = null;
            return ['success' => true, 'seed_summary' => $summary, 'state' => $this->getState()];
        } catch (\Throwable $e) {
            $this->logOperation($runId, 'reseed', 'failed', $e->getMessage(), $adminUserId);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function seedScenarioRun(int $adminUserId, string $seedProfile, string $notes = '', array $context = []): array
    {
        $this->ensureTables();
        $seedProfile = trim($seedProfile) !== '' ? trim($seedProfile) : 'full';

        Database::beginTransaction();
        try {
            $runUuid = $this->uuid();
            Database::execute(
                'INSERT INTO demo_runs (run_uuid, status, seed_profile, created_by, notes) VALUES (?, ?, ?, ?, ?)',
                [$runUuid, 'inactive', $seedProfile, $adminUserId, $notes !== '' ? $notes : 'Demo scenario seed']
            );
            $runId = (int) Database::lastInsertId();

            $seedSummary = (new DemoSeedService())->seedEverything($runId, $adminUserId, $seedProfile, $context);
            $seedErrorCount = count($seedSummary['errors'] ?? []);
            $result = $seedErrorCount > 0 ? 'failed' : 'success';

            if ($seedErrorCount > 0) {
                Database::execute("UPDATE demo_runs SET status = 'failed' WHERE id = ?", [$runId]);
            }

            $this->logOperation($runId, 'seed', $result, $seedErrorCount > 0 ? 'Demo seed completed with errors' : 'Demo seed completed successfully', $adminUserId);

            Database::commit();
            self::$cachedState = null;

            return [
                'success' => true,
                'run_id' => $runId,
                'run_uuid' => $runUuid,
                'seed_summary' => $seedSummary,
            ];
        } catch (\Throwable $e) {
            Database::rollBack();
            $this->logOperation(null, 'seed', 'failed', $e->getMessage(), $adminUserId);
            self::$cachedState = null;
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function purge(int $runId, int $adminUserId): array
    {
        $this->ensureTables();
        if ($runId <= 0) {
            return ['success' => false, 'error' => 'Invalid run id'];
        }

        Database::beginTransaction();
        try {
            $state = Database::queryOne('SELECT * FROM demo_mode_state WHERE id = 1 LIMIT 1') ?: [];
            $activeRunId = (int) ($state['active_run_id'] ?? 0);
            $entries = Database::query(
                'SELECT id, table_name, record_id FROM demo_seed_registry WHERE run_id = ? ORDER BY id DESC',
                [$runId]
            );

            $deleted = 0;
            $skipped = 0;
            foreach ($entries as $entry) {
                $table = (string) ($entry['table_name'] ?? '');
                $recordId = (int) ($entry['record_id'] ?? 0);
                if ($table === '' || $recordId <= 0 || !$this->tableExists($table)) {
                    $skipped++;
                    continue;
                }

                try {
                    $affected = Database::execute('DELETE FROM `' . $table . '` WHERE id = ?', [$recordId]);
                    if ($affected > 0) {
                        $deleted += $affected;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $deleteError) {
                    $skipped++;
                }
            }

            Database::execute('DELETE FROM demo_seed_registry WHERE run_id = ?', [$runId]);
            Database::execute("UPDATE demo_runs SET status = 'purged', deactivated_at = NOW() WHERE id = ?", [$runId]);
            if ($activeRunId === $runId) {
                Database::execute(
                    'UPDATE demo_mode_state SET is_enabled = 0, active_run_id = NULL, simulation_only = 1, updated_by = ? WHERE id = 1',
                    [$adminUserId]
                );
            }
            $this->logOperation($runId, 'purge', 'success', 'Purged demo records. deleted=' . $deleted . ', skipped=' . $skipped, $adminUserId);

            Database::commit();
            self::$cachedState = null;
            return [
                'success' => true,
                'deleted' => $deleted,
                'skipped' => $skipped,
                'state' => $this->getState(),
            ];
        } catch (\Throwable $e) {
            Database::rollBack();
            $this->logOperation($runId, 'purge', 'failed', $e->getMessage(), $adminUserId);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function setSimulationOnly(bool $enabled, int $adminUserId): array
    {
        $this->ensureTables();

        Database::beginTransaction();
        try {
            $state = Database::queryOne('SELECT * FROM demo_mode_state WHERE id = 1 LIMIT 1') ?: [];
            $isEnabled = ((int) ($state['is_enabled'] ?? 0)) === 1;
            $runId = (int) ($state['active_run_id'] ?? 0);

            // Safety guard: do not allow real outbound while demo mode is actively enabled.
            if (!$enabled && $isEnabled) {
                throw new \RuntimeException('Disable Demo Mode first before turning simulation off.');
            }

            Database::execute(
                'UPDATE demo_mode_state SET simulation_only = ?, updated_by = ? WHERE id = 1',
                [$enabled ? 1 : 0, $adminUserId]
            );

            $op = $enabled ? 'simulation_on' : 'simulation_off';
            $msg = $enabled ? 'Simulation mode enabled' : 'Simulation mode disabled';
            $this->logOperation($runId > 0 ? $runId : null, $op, 'success', $msg, $adminUserId);

            Database::commit();
            self::$cachedState = null;
            return ['success' => true, 'state' => $this->getState()];
        } catch (\Throwable $e) {
            Database::rollBack();
            $this->logOperation(null, 'simulation_toggle', 'failed', $e->getMessage(), $adminUserId);
            self::$cachedState = null;
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function isEnabled(): bool
    {
        if (self::$cachedState !== null) {
            return (bool) (self::$cachedState['is_enabled'] ?? false);
        }
        $state = $this->getState();
        return (bool) ($state['is_enabled'] ?? false);
    }

    public function isSimulationOnly(): bool
    {
        if (self::$cachedState !== null) {
            return (bool) (self::$cachedState['simulation_only'] ?? true);
        }
        $state = $this->getState();
        return (bool) ($state['simulation_only'] ?? true);
    }

    public static function getCachedState(): array
    {
        if (self::$cachedState !== null) {
            return self::$cachedState;
        }

        try {
            return (new self())->getState();
        } catch (\Throwable $e) {
            return [
                'is_enabled' => false,
                'simulation_only' => true,
                'active_run_id' => null,
                'active_run' => null,
                'seeded_records' => 0,
                'last_operation' => null,
                'updated_at' => null,
            ];
        }
    }

    private function logOperation(?int $runId, string $operation, string $result, string $message, ?int $createdBy): void
    {
        if (!$this->tableExists('demo_operation_logs')) {
            return;
        }

        Database::execute(
            'INSERT INTO demo_operation_logs (run_id, operation, result, message, created_by) VALUES (?, ?, ?, ?, ?)',
            [$runId, $operation, $result, $message, $createdBy]
        );
    }

    private function ensureTables(): void
    {
        if (!$this->tableExists('demo_mode_state')) {
            throw new \RuntimeException('Demo mode tables are missing. Run migration 104_create_demo_mode_tables.sql first.');
        }
    }

    private function tableExists(string $tableName): bool
    {
        static $existsCache = [];
        if (array_key_exists($tableName, $existsCache)) {
            return $existsCache[$tableName];
        }

        $row = Database::queryOne(
            'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$tableName]
        );
        $existsCache[$tableName] = ((int) ($row['cnt'] ?? 0)) > 0;

        return $existsCache[$tableName];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
