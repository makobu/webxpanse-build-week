<?php

namespace CRM\Modules;

use CRM\Database;

class EmailFetchAudit
{
    public function getSummary(): array
    {
        $row = Database::queryOne(
            "SELECT
                SUM(CASE WHEN outcome = 'skipped' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS skipped_24h,
                SUM(CASE WHEN outcome = 'skipped' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS skipped_7d,
                SUM(CASE WHEN outcome = 'auto_created' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS auto_created_24h,
                SUM(CASE WHEN outcome = 'auto_created' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS auto_created_7d,
                SUM(CASE WHEN outcome = 'error' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS failed_7d
             FROM email_fetch_log_details"
        ) ?: [];

        return [
            'skipped_24h' => (int) ($row['skipped_24h'] ?? 0),
            'skipped_7d' => (int) ($row['skipped_7d'] ?? 0),
            'auto_created_24h' => (int) ($row['auto_created_24h'] ?? 0),
            'auto_created_7d' => (int) ($row['auto_created_7d'] ?? 0),
            'failed_7d' => (int) ($row['failed_7d'] ?? 0),
        ];
    }

    public function getReasonCodes(): array
    {
        return Database::query(
            "SELECT DISTINCT reason_code
             FROM email_fetch_log_details
             WHERE reason_code IS NOT NULL
               AND reason_code <> ''
             ORDER BY reason_code ASC"
        );
    }

    public function getEntries(array $filters, int $limit, int $offset): array
    {
        [$whereClause, $params] = $this->buildFilters($filters);
        $params[] = $limit;
        $params[] = $offset;

        return Database::query(
            "SELECT d.*,
                    l.last_fetch_at,
                    l.status AS run_status,
                    l.source,
                    c.first_name AS contact_first_name,
                    c.last_name AS contact_last_name,
                    comm.channel AS communication_channel
             FROM email_fetch_log_details d
             JOIN email_fetch_log l ON l.id = d.fetch_log_id
             LEFT JOIN contacts c ON c.id = d.contact_id
             LEFT JOIN communications comm ON comm.id = d.communication_id
             {$whereClause}
             ORDER BY d.created_at DESC, d.id DESC
             LIMIT ? OFFSET ?",
            $params
        );
    }

    public function getCount(array $filters): int
    {
        [$whereClause, $params] = $this->buildFilters($filters);
        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM email_fetch_log_details d
             JOIN email_fetch_log l ON l.id = d.fetch_log_id
             {$whereClause}",
            $params
        ) ?: [];

        return (int) ($row['c'] ?? 0);
    }

    private function buildFilters(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['outcome'])) {
            $where[] = 'd.outcome = ?';
            $params[] = $filters['outcome'];
        }
        if (!empty($filters['sender_email'])) {
            $where[] = 'd.sender_email LIKE ?';
            $params[] = '%' . $filters['sender_email'] . '%';
        }
        if (!empty($filters['reason_code'])) {
            $where[] = 'd.reason_code = ?';
            $params[] = $filters['reason_code'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'd.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'd.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        return [
            !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '',
            $params,
        ];
    }
}
