<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_serializers.php';
require_once __DIR__ . '/_feature_helpers.php';

use CRM\Authorization;
use CRM\Modules\Tasks;
use CRM\Modules\Targets;
use CRM\Services\TargetCoordinator;
use CRM\Services\TargetStateConflictException;

function mobileTargetFilters(array $query, int $userId, array $user): array
{
    $filters = [];
    if (!Authorization::can('targets.manage_all', $user)) {
        $filters['user_id'] = $userId;
    } elseif (isset($query['user_id']) && (int) $query['user_id'] > 0) {
        $filters['user_id'] = (int) $query['user_id'];
    }

    $filter = strtolower(trim((string) ($query['filter'] ?? '')));
    if ($filter === 'completed') {
        $filters['status'] = 'completed';
    } elseif ($filter === 'active') {
        $filters['status'] = 'active';
    } elseif ($filter === 'behind') {
        $filters['status_band'] = 'behind';
    } elseif ($filter === 'due_soon') {
        $filters['status'] = 'active';
        $filters['upcoming'] = true;
    }

    foreach (['status', 'target_type', 'scope', 'progress_mode', 'status_band', 'search'] as $key) {
        $value = trim((string) ($query[$key] ?? ''));
        if ($value !== '') {
            $filters[$key] = $value;
        }
    }

    return $filters;
}

function mobileTargetForUser(array $target, array $user): array
{
    $item = mobileTargetSummary($target);
    $canEdit = (new Targets())->canEditTarget($target, $user);
    $item = array_merge($item, (new TargetCoordinator())->payload($target, $canEdit));
    $actions = [];
    if ($canEdit) {
        $actions[] = 'update';
        if ((string) ($item['status'] ?? '') === 'completed') {
            $actions[] = 'reopen';
        } else {
            if ((string) ($item['progress_mode'] ?? 'manual') !== 'auto_rollup') {
                $actions[] = 'update_progress';
            }
            array_push($actions, 'complete', 'evaluate', 'set_mode');
        }
        array_push($actions, 'manage_milestones');
    }
    $item['allowed_actions'] = array_values(array_unique($actions));

    return $item;
}

function mobileValidateTargetPayload(array $input, bool $creating): void
{
    if ($creating && trim((string) ($input['title'] ?? '')) === '') {
        mobileJson(['error' => 'Target title is required.', 'error_code' => 'validation_failed'], 422);
    }
    if ($creating && (!isset($input['target_value']) || !is_numeric($input['target_value']) || (float) $input['target_value'] <= 0)) {
        mobileJson(['error' => 'Target value must be greater than zero.', 'error_code' => 'validation_failed'], 422);
    }
    if (isset($input['current_value']) && (!is_numeric($input['current_value']) || (float) $input['current_value'] < 0)) {
        mobileJson(['error' => 'Progress cannot be negative.', 'error_code' => 'validation_failed'], 422);
    }
    if (!empty($input['target_date']) && strtotime((string) $input['target_date']) === false) {
        mobileJson(['error' => 'Enter a valid due date.', 'error_code' => 'validation_failed'], 422);
    }
}

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$userId = (int) ($auth['user_id'] ?? 0);
$targets = new Targets();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'POST') {
        $input = mobileRequestBody();
        $action = strtolower(trim((string) ($input['action'] ?? 'create')));

        if ($action === 'create') {
            mobileValidateTargetPayload($input, true);
            $payload = $input;
            $payload['user_id'] = Authorization::can('targets.manage_all', $user)
                ? (int) ($input['user_id'] ?? $userId)
                : $userId;
            $targetId = $targets->create($payload);
            $target = $targets->getById($targetId) ?? ['id' => $targetId];
            mobileJson([
                'success' => true,
                'data' => [
                    'target' => mobileTargetForUser($target, $user),
                ],
            ]);
        }

        $targetId = (int) ($input['target_id'] ?? $input['id'] ?? 0);
        if ($targetId <= 0) {
            mobileJson(['error' => 'target_id is required.'], 422);
        }
        $target = $targets->getById($targetId);
        if (!$target || !$targets->canEditTarget($target, $user)) {
            mobileJson(['error' => 'Target not found or not editable.'], 404);
        }

        if ($action === 'update_progress') {
            mobileValidateTargetPayload($input, false);
            $targets->updateProgress($targetId, (float) ($input['current_value'] ?? 0), isset($input['state_version']) ? (int) $input['state_version'] : null);
        } elseif ($action === 'complete') {
            $targets->markComplete($targetId);
        } elseif ($action === 'update') {
            $allowed = array_intersect_key($input, array_flip([
                'title',
                'description',
                'target_type',
                'target_value',
                'current_value',
                'unit',
                'start_date',
                'target_date',
                'reminder_frequency',
                'custom_reminder_days',
                'status',
                'scope',
                'progress_mode',
            ]));
            $targets->update($targetId, $allowed);
        } else {
            mobileJson(['error' => 'Unsupported action.'], 422);
        }

        mobileJson([
            'success' => true,
            'data' => [
                'target' => mobileTargetForUser($targets->getById($targetId) ?? [], $user),
            ],
        ]);
    }

    if ($method !== 'GET') {
        mobileJson(['error' => 'Method not allowed.'], 405);
    }

    if (!empty($_GET['id'])) {
        $target = $targets->getById((int) $_GET['id']);
        if (!$target || !$targets->canViewTarget($target, $user)) {
            mobileJson(['error' => 'Target not found.'], 404);
        }
        $taskRows = (new Tasks())->getAll(['target_id' => (int) $target['id']], 10, 0);
        mobileJson([
            'success' => true,
            'data' => [
                'target' => mobileTargetForUser($target, $user),
                'related_tasks' => array_map('mobileTaskSummary', $taskRows),
            ],
        ]);
    }

    $limit = mobileBoundedLimit($_GET['limit'] ?? null, 50, 100);
    $offset = mobileBoundedOffset($_GET['offset'] ?? 0);
    $rows = $targets->getAll(mobileTargetFilters($_GET, $userId, $user), $limit, $offset);
    $items = [];
    $filter = strtolower(trim((string) ($_GET['filter'] ?? '')));
    foreach ($rows as $row) {
        if ($filter === 'due_soon' && ((int) ($row['days_remaining'] ?? 9999) < 0 || (int) ($row['days_remaining'] ?? 9999) > 14)) {
            continue;
        }
        if ($filter === 'behind' && !in_array((string) ($row['status_category'] ?? $row['status_band'] ?? ''), ['behind', 'missed', 'at_risk'], true)) {
            continue;
        }
        if ($targets->canViewTarget($row, $user)) {
            $items[] = mobileTargetForUser($row, $user);
        }
    }

    mobileJson([
        'success' => true,
        'data' => [
            'items' => $items,
            'limit' => $limit,
            'offset' => $offset,
            'generated_at' => gmdate('c'),
        ],
    ]);
} catch (TargetStateConflictException $e) {
    mobileJson(['error' => $e->getMessage(), 'error_code' => 'stale_state', 'data' => ['target' => $e->currentTarget()]], 409);
} catch (Throwable $e) {
    mobileJson([
        'error' => $e->getMessage(),
        'error_code' => 'target_mobile_failed',
    ], str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 422);
}
