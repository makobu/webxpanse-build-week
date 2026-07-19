<?php

namespace CRM\Services;

use CRM\Database;

class TargetIntelligenceScanQueueService
{
    public function enqueue(int $workspaceId, ?string $source = null, ?int $entityId = null, array $context = []): int
    {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('A workspace is required.');
        }
        $bucket = date('Y-m-d H:i');
        $dedupe = hash('sha256', implode('|', [$workspaceId, strtolower((string) $source), (int) $entityId, $bucket, json_encode($context)]));
        Database::execute(
            "INSERT INTO target_intelligence_scan_queue (workspace_id,source_entity_type,source_entity_id,dedupe_key)
             VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE available_at=LEAST(available_at,VALUES(available_at))",
            [$workspaceId, $source ?: null, $entityId ?: null, $dedupe]
        );
        $row = Database::queryOne('SELECT id FROM target_intelligence_scan_queue WHERE workspace_id=? AND dedupe_key=?', [$workspaceId, $dedupe]);
        return (int) ($row['id'] ?? 0);
    }

    public function enqueueReconciliation(int $workspaceId): int
    {
        return $this->enqueue($workspaceId, null, null, ['reconciliation' => date('Y-m-d-H')]);
    }

    public function processNext(): array
    {
        $started = !Database::getInstance()->inTransaction();
        if ($started) {
            Database::beginTransaction();
        }
        try {
            $job = Database::queryOne(
                "SELECT * FROM target_intelligence_scan_queue
                 WHERE state IN ('pending','failed') AND available_at<=NOW()
                   AND (lease_expires_at IS NULL OR lease_expires_at<NOW()) AND attempts<5
                 ORDER BY id ASC LIMIT 1 FOR UPDATE"
            );
            if (!$job) {
                if ($started) {
                    Database::commit();
                }
                return ['processed' => 0, 'continuation' => false];
            }
            Database::execute("UPDATE target_intelligence_scan_queue SET state='processing',attempts=attempts+1,lease_expires_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE id=?", [(int) $job['id']]);
            if ($started) {
                Database::commit();
            }

            $workspaceId = (int) $job['workspace_id'];
            $cursor = (int) $job['cursor_target_id'];
            $pageSize = max(1, min(250, (int) $job['page_size']));
            $where = ["workspace_id=?", "id>?", "status IN ('active','missed','completed')"];
            $params = [$workspaceId, $cursor];
            if (!empty($job['source_entity_type'])) {
                $sources = (string) $job['source_entity_type'] === 'invoices' ? ['invoices', 'documents'] : [(string) $job['source_entity_type']];
                $where[] = 'rollup_source IN (' . implode(',', array_fill(0, count($sources), '?')) . ')';
                array_push($params, ...$sources);
            }
            $rows = Database::query('SELECT id FROM targets WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT ' . $pageSize, $params);
            $processed = 0;
            foreach ($rows as $row) {
                (new TargetIntelligenceService())->syncTarget((int) $row['id'], $workspaceId);
                $cursor = (int) $row['id'];
                $processed++;
            }
            $continuation = count($rows) === $pageSize;
            Database::execute(
                "UPDATE target_intelligence_scan_queue SET cursor_target_id=?,continuation_count=continuation_count+?,
                    state=?,lease_expires_at=NULL,last_error=NULL,available_at=NOW() WHERE id=?",
                [$cursor, $continuation ? 1 : 0, $continuation ? 'pending' : 'completed', (int) $job['id']]
            );
            return ['job_id' => (int) $job['id'], 'processed' => $processed, 'continuation' => $continuation, 'cursor_target_id' => $cursor];
        } catch (\Throwable $e) {
            if ($started && Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            if (!empty($job['id'])) {
                Database::execute(
                    "UPDATE target_intelligence_scan_queue SET state=IF(attempts>=5,'dead_letter','failed'),last_error=?,lease_expires_at=NULL,
                        available_at=DATE_ADD(NOW(),INTERVAL LEAST(attempts*2,30) MINUTE) WHERE id=?",
                    [substr($e->getMessage(), 0, 4000), (int) $job['id']]
                );
            }
            error_log('Target intelligence queue failed: ' . $e->getMessage());
            throw $e;
        }
    }
}
