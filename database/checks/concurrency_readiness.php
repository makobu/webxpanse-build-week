<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use CRM\Database;
use CRM\Concurrency;
use CRM\ConcurrencyConflictException;
use CRM\Services\EmailQueue;
use CRM\Services\QueueWorkerHeartbeatService;

$options = getopt('', ['database:', 'exercise-queues', 'exercise-locks', 'allow-live']);
$databaseName = trim((string) ($options['database'] ?? ''));
if ($databaseName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $databaseName)) {
    fwrite(STDERR, "Usage: php database/checks/concurrency_readiness.php --database=DB [--exercise-queues] [--exercise-locks] [--allow-live]\n");
    exit(2);
}

$config = require __DIR__ . '/../../config/database.php';
$configuredDatabase = (string) ($config['name'] ?? '');
$exerciseQueues = array_key_exists('exercise-queues', $options);
$exerciseLocks = array_key_exists('exercise-locks', $options);
if (($exerciseQueues || $exerciseLocks) && $databaseName === $configuredDatabase && !array_key_exists('allow-live', $options)) {
    fwrite(STDERR, "Refusing to exercise writes on the configured database without --allow-live.\n");
    exit(2);
}

$config['name'] = $databaseName;
Database::init($config);
$pdo = Database::getInstance();
$checks = [];

$record = static function (string $name, bool $passed, string $detail) use (&$checks): void {
    $checks[] = ['name' => $name, 'passed' => $passed, 'detail' => $detail];
};

$schemaCounts = Database::queryOne(
    "SELECT
        (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = ? AND table_type = 'BASE TABLE') AS base_tables,
        (SELECT COUNT(DISTINCT table_name) FROM information_schema.statistics
         WHERE table_schema = ? AND index_name = 'PRIMARY') AS primary_key_tables,
        (SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema = ? AND table_type = 'BASE TABLE' AND engine <> 'InnoDB') AS non_innodb_tables",
    [$databaseName, $databaseName, $databaseName]
) ?: [];
$baseTables = (int) ($schemaCounts['base_tables'] ?? 0);
$primaryKeyTables = (int) ($schemaCounts['primary_key_tables'] ?? 0);
$record('all_base_tables_have_primary_keys', $baseTables > 0 && $baseTables === $primaryKeyTables, "{$primaryKeyTables}/{$baseTables} tables");
$record('all_base_tables_use_innodb', (int) ($schemaCounts['non_innodb_tables'] ?? -1) === 0, (int) ($schemaCounts['non_innodb_tables'] ?? -1) . ' non-InnoDB tables');

$sqlMode = (string) ($pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn() ?: '');
$record('strict_sql_mode', str_contains(strtoupper($sqlMode), 'STRICT_TRANS_TABLES'), $sqlMode);

$queueColumns = Database::queryOne(
    "SELECT COUNT(*) AS found
     FROM information_schema.columns
     WHERE table_schema = ? AND table_name = 'email_queue'
       AND column_name IN ('claim_token', 'claimed_at', 'lease_expires_at', 'worker_id')",
    [$databaseName]
);
$record('email_queue_has_leases', (int) ($queueColumns['found'] ?? 0) === 4, (int) ($queueColumns['found'] ?? 0) . '/4 columns');
$workflowQueueColumns = Database::queryOne(
    "SELECT COUNT(*) AS found
     FROM information_schema.columns
     WHERE table_schema = ? AND table_name = 'workflow_queue'
       AND column_name IN ('claim_token', 'claimed_at', 'lease_expires_at', 'worker_id')",
    [$databaseName]
);
$record('workflow_queue_has_leases', (int) ($workflowQueueColumns['found'] ?? 0) === 4, (int) ($workflowQueueColumns['found'] ?? 0) . '/4 columns');
$record('worker_heartbeat_table_exists', Database::tableExists('queue_worker_heartbeats'), 'queue_worker_heartbeats');

$lockTables = ['companies', 'products', 'events', 'invoices', 'company_profile', 'custom_fields', 'scheduled_reports'];
$lockPlaceholders = implode(',', array_fill(0, count($lockTables), '?'));
$lockColumns = Database::queryOne(
    "SELECT COUNT(*) AS found
     FROM information_schema.columns
     WHERE table_schema = ? AND column_name = 'lock_version' AND table_name IN ({$lockPlaceholders})",
    array_merge([$databaseName], $lockTables)
);
$record('shared_records_have_optimistic_locks', (int) ($lockColumns['found'] ?? 0) === count($lockTables), (int) ($lockColumns['found'] ?? 0) . '/' . count($lockTables) . ' tables');

$duplicateMigrations = Database::queryOne(
    "SELECT COUNT(*) AS duplicate_count
     FROM (
         SELECT migration_name
         FROM migrations
         GROUP BY migration_name
         HAVING COUNT(*) > 1
     ) duplicate_names"
);
$record('migration_names_are_unique', (int) ($duplicateMigrations['duplicate_count'] ?? -1) === 0, (int) ($duplicateMigrations['duplicate_count'] ?? -1) . ' duplicate names');

$zeroIdentityTables = [];
$identityTables = Database::query(
    "SELECT table_name
     FROM information_schema.columns
     WHERE table_schema = ? AND column_name = 'id' AND column_key = 'PRI'",
    [$databaseName]
);
foreach ($identityTables as $identityTable) {
    $tableName = (string) ($identityTable['table_name'] ?? '');
    if ($tableName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
        continue;
    }
    $minimum = $pdo->query("SELECT MIN(id) FROM `{$tableName}`")->fetchColumn();
    if ($minimum !== false && $minimum !== null && (int) $minimum <= 0) {
        $zeroIdentityTables[] = $tableName;
    }
}
$record('primary_identities_are_positive', $zeroIdentityTables === [], $zeroIdentityTables === [] ? 'no id <= 0' : implode(', ', $zeroIdentityTables));

if ($exerciseQueues) {
    $workspace = Database::queryOne('SELECT id FROM workspaces ORDER BY id ASC LIMIT 1');
    $workspaceId = (int) ($workspace['id'] ?? 0);
    if ($workspaceId <= 0) {
        $record('leased_queue_claim_exercise', false, 'no workspace available');
    } else {
        $uuid = static function (): string {
            $hex = bin2hex(random_bytes(16));
            return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
        };
        $emailId = 0;
        $queueId = 0;
        $heartbeat = null;
        try {
            Database::execute(
                "INSERT INTO emails (workspace_id, uuid, contact_id, to_email, from_email, subject, body, status)
                 VALUES (?, ?, 0, 'concurrency-check@example.test', 'crm@example.test', 'Concurrency check', 'Disposable queue check', 'pending')",
                [$workspaceId, $uuid()]
            );
            $emailId = (int) Database::lastInsertId();
            $queue = new EmailQueue();
            $queueId = $queue->push($emailId, 9999, null, $workspaceId);
            $firstClaim = $queue->pop($workspaceId, $queueId);
            $secondClaim = $queue->pop($workspaceId, $queueId);
            Database::execute('UPDATE email_queue SET lease_expires_at = DATE_SUB(NOW(), INTERVAL 1 SECOND) WHERE id = ?', [$queueId]);
            $reclaimed = $queue->pop($workspaceId, $queueId);

            $oldClaimRejected = false;
            try {
                $queue->ack($queueId, $workspaceId, (string) ($firstClaim['claim_token'] ?? ''));
            } catch (RuntimeException $e) {
                $oldClaimRejected = true;
            }
            if ($reclaimed) {
                $queue->ack($queueId, $workspaceId, (string) ($reclaimed['claim_token'] ?? ''));
            }

            $claimPassed = $firstClaim !== null
                && $secondClaim === null
                && $reclaimed !== null
                && ($firstClaim['claim_token'] ?? '') !== ($reclaimed['claim_token'] ?? '')
                && $oldClaimRejected;
            $record('leased_queue_claim_exercise', $claimPassed, $claimPassed ? 'exclusive claim, expiry recovery, and stale-owner rejection passed' : 'one or more queue claim assertions failed');

            $heartbeat = new QueueWorkerHeartbeatService('concurrency_check', 'check-' . $uuid());
            $heartbeat->start(['database' => $databaseName]);
            $heartbeat->touch(['phase' => 'verified'], true);
            $heartbeatRow = Database::queryOne(
                "SELECT status, heartbeat_at FROM queue_worker_heartbeats
                 WHERE worker_name = 'concurrency_check' AND worker_id = ?",
                [$heartbeat->workerId()]
            );
            $heartbeat->stop();
            $record('worker_heartbeat_exercise', ($heartbeatRow['status'] ?? '') === 'running' && !empty($heartbeatRow['heartbeat_at']), 'start and touch persisted');
        } catch (Throwable $e) {
            $record('leased_queue_claim_exercise', false, $e->getMessage());
        } finally {
            if ($heartbeat instanceof QueueWorkerHeartbeatService) {
                Database::execute("DELETE FROM queue_worker_heartbeats WHERE worker_name = 'concurrency_check' AND worker_id = ?", [$heartbeat->workerId()]);
            }
            if ($queueId > 0) {
                Database::execute('DELETE FROM email_queue WHERE id = ?', [$queueId]);
            }
            if ($emailId > 0) {
                Database::execute('DELETE FROM emails WHERE id = ?', [$emailId]);
            }
        }
    }
}

if ($exerciseLocks) {
    $workspace = Database::queryOne('SELECT id FROM workspaces ORDER BY id ASC LIMIT 1');
    $workspaceId = (int) ($workspace['id'] ?? 0);
    $companyId = 0;
    try {
        if ($workspaceId <= 0) {
            throw new RuntimeException('no workspace available');
        }
        $companyUuid = sprintf(
            '%s-%s-%s-%s-%s',
            ...array_map(
                static fn(array $slice): string => substr(bin2hex(random_bytes(16)), $slice[0], $slice[1]),
                [[0, 8], [8, 4], [12, 4], [16, 4], [20, 12]]
            )
        );
        Database::execute(
            "INSERT INTO companies (workspace_id, uuid, name, lock_version) VALUES (?, ?, 'Concurrency check', 0)",
            [$workspaceId, $companyUuid]
        );
        $companyId = (int) Database::lastInsertId();
        $loader = static fn(): ?array => Database::queryOne(
            'SELECT * FROM companies WHERE workspace_id = ? AND id = ?',
            [$workspaceId, $companyId]
        );
        Concurrency::executeWorkspaceUpdate(
            'companies',
            $workspaceId,
            $companyId,
            ['name = ?'],
            ['First writer'],
            0,
            $loader,
            ['name' => 'First writer'],
            'company'
        );
        $staleRejected = false;
        try {
            Concurrency::executeWorkspaceUpdate(
                'companies',
                $workspaceId,
                $companyId,
                ['name = ?'],
                ['Stale writer'],
                0,
                $loader,
                ['name' => 'Stale writer'],
                'company'
            );
        } catch (ConcurrencyConflictException $e) {
            $staleRejected = true;
        }
        $current = $loader() ?: [];
        $lockPassed = $staleRejected
            && (string) ($current['name'] ?? '') === 'First writer'
            && (int) ($current['lock_version'] ?? -1) === 1;
        $record('optimistic_lock_exercise', $lockPassed, $lockPassed ? 'stale writer rejected without data loss' : 'stale update was not safely rejected');
    } catch (Throwable $e) {
        $record('optimistic_lock_exercise', false, $e->getMessage());
    } finally {
        if ($companyId > 0) {
            Database::execute('DELETE FROM companies WHERE workspace_id = ? AND id = ?', [$workspaceId, $companyId]);
        }
    }
}

$failed = array_values(array_filter($checks, static fn(array $check): bool => !$check['passed']));
foreach ($checks as $check) {
    echo sprintf("[%s] %s: %s\n", $check['passed'] ? 'PASS' : 'FAIL', $check['name'], $check['detail']);
}
echo sprintf("Result: %d passed, %d failed\n", count($checks) - count($failed), count($failed));
exit($failed === [] ? 0 : 1);
