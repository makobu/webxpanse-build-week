<?php

declare(strict_types=1);

namespace CRM\Modules;

use CRM\Database;

class ImplementationChecklist
{
    private ClarityPackageCatalog $catalog;

    public function __construct()
    {
        $this->catalog = new ClarityPackageCatalog();
    }

    public function getItems(): array
    {
        $defaults = $this->catalog->getImplementationChecklist();
        if (!$this->tableExists()) {
            return array_map(static function (array $item): array {
                $item['is_complete'] = false;
                $item['notes'] = '';
                $item['completed_at'] = null;
                return $item;
            }, $defaults);
        }

        $stored = Database::query('SELECT * FROM implementation_checklist_items ORDER BY checklist_label ASC');
        $storedByKey = [];
        foreach ($stored as $row) {
            $storedByKey[(string) ($row['checklist_key'] ?? '')] = $row;
        }

        $items = [];
        foreach ($defaults as $item) {
            $state = $storedByKey[$item['key']] ?? [];
            $items[] = array_merge($item, [
                'is_complete' => ((int) ($state['is_complete'] ?? 0)) === 1,
                'notes' => (string) ($state['notes'] ?? ''),
                'completed_at' => $state['completed_at'] ?? null,
            ]);
        }

        return $items;
    }

    public function setItemState(string $key, bool $isComplete, int $userId, string $notes = ''): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $definitions = [];
        foreach ($this->catalog->getImplementationChecklist() as $item) {
            $definitions[$item['key']] = $item;
        }

        if (!isset($definitions[$key])) {
            return false;
        }

        $label = (string) ($definitions[$key]['label'] ?? $key);
        $existing = Database::queryOne('SELECT id FROM implementation_checklist_items WHERE checklist_key = ? LIMIT 1', [$key]);

        if ($existing) {
            Database::execute(
                'UPDATE implementation_checklist_items
                 SET checklist_label = ?, notes = ?, is_complete = ?, completed_at = ?, completed_by = ?
                 WHERE id = ?',
                [
                    $label,
                    $notes,
                    $isComplete ? 1 : 0,
                    $isComplete ? date('Y-m-d H:i:s') : null,
                    $isComplete ? $userId : null,
                    (int) $existing['id'],
                ]
            );
        } else {
            Database::execute(
                'INSERT INTO implementation_checklist_items
                 (checklist_key, checklist_label, notes, is_complete, completed_at, completed_by)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $key,
                    $label,
                    $notes,
                    $isComplete ? 1 : 0,
                    $isComplete ? date('Y-m-d H:i:s') : null,
                    $isComplete ? $userId : null,
                ]
            );
        }

        return true;
    }

    public function tableExists(): bool
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'implementation_checklist_items'"
        );

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }
}
