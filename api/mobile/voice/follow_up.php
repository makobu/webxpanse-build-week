<?php

require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_serializers.php';

use CRM\Modules\Tasks;

mobileVoiceRequireMethod('POST');
mobileVoiceRequire('voice.calls.use');
mobileVoiceRequire('tasks.write');

try {
    $callId = (int) ($mobileVoiceInput['call_id'] ?? 0);
    if ($callId <= 0) {
        throw new InvalidArgumentException('Call is required.');
    }
    $params = [$mobileVoiceWorkspaceId, $callId];
    $scope = '';
    if (!mobileVoiceCan('voice.calls.view_all')) {
        $scope = ' AND (agent_user_id = ? OR created_by_user_id = ?)';
        $params[] = $mobileVoiceUserId;
        $params[] = $mobileVoiceUserId;
    }
    $call = \CRM\Database::queryOne(
        'SELECT id, contact_id, direction, state, disposition, disposition_notes,
                from_number_masked, to_number_masked, created_at
         FROM voice_calls
         WHERE workspace_id = ? AND id = ?' . $scope . ' LIMIT 1',
        $params
    ) ?: [];
    if ($call === []) {
        mobileJson(['success' => false, 'error' => 'Voice call not found or not available to this user.'], 404);
    }
    if (empty($call['contact_id'])) {
        mobileJson([
            'success' => false,
            'error' => 'Link this call to a CRM contact before creating a follow-up task.',
            'error_code' => 'voice_follow_up_contact_required',
        ], 422);
    }

    $disposition = trim((string) ($call['disposition'] ?? ''));
    $defaultTitle = match ($disposition) {
        'qualified' => 'Follow up with qualified contact',
        'follow_up' => 'Complete call follow-up',
        'voicemail' => 'Retry customer call',
        default => 'Follow up after customer call',
    };
    $title = trim((string) ($mobileVoiceInput['title'] ?? $defaultTitle));
    if ($title === '') {
        $title = $defaultTitle;
    }
    $priority = trim((string) ($mobileVoiceInput['priority'] ?? ''));
    if (!in_array($priority, ['low', 'medium', 'high', 'urgent'], true)) {
        $priority = in_array($disposition, ['qualified', 'follow_up'], true) ? 'high' : 'medium';
    }
    $dueDate = trim((string) ($mobileVoiceInput['due_date'] ?? ''));
    if ($dueDate === '') {
        $dueDate = date('Y-m-d 09:00:00', strtotime('+1 day'));
    }
    $dueTimestamp = strtotime($dueDate);
    if ($dueTimestamp === false) {
        throw new InvalidArgumentException('Choose a valid follow-up date.');
    }
    $dueDate = date('Y-m-d H:i:s', $dueTimestamp);
    $number = (string) (($call['direction'] ?? '') === 'inbound'
        ? ($call['from_number_masked'] ?? '')
        : ($call['to_number_masked'] ?? ''));
    $descriptionLines = [
        'Created from CRM Call Center call #' . $callId . '.',
        'Outcome: ' . ($disposition !== '' ? str_replace('_', ' ', $disposition) : 'not recorded'),
    ];
    if (trim($number) !== '') {
        $descriptionLines[] = 'Customer number: ' . trim($number);
    }
    if (trim((string) ($call['disposition_notes'] ?? '')) !== '') {
        $descriptionLines[] = 'Call notes: ' . trim((string) $call['disposition_notes']);
    }

    $tasks = new Tasks();
    $taskId = $tasks->create([
        'title' => substr($title, 0, 255),
        'description' => implode("\n", $descriptionLines),
        'contact_id' => (int) $call['contact_id'],
        'assigned_to' => $mobileVoiceUserId,
        'created_by' => $mobileVoiceUserId,
        'actor_user_id' => $mobileVoiceUserId,
        'priority' => $priority,
        'due_date' => $dueDate,
        'metadata_json' => [
            'source_surface' => 'mobile_call_center',
            'voice_call_id' => $callId,
            'voice_disposition' => $disposition,
        ],
        'source_surface' => 'mobile_call_center',
        'source_capability_key' => 'voice_call_follow_up',
        'origin_type' => 'manual',
        'automation_dedupe_key' => 'voice_follow_up:' . $mobileVoiceWorkspaceId . ':' . $callId,
    ]);
    $task = $tasks->getById($taskId) ?: [];

    mobileJson([
        'success' => true,
        'message' => 'Call follow-up task is ready.',
        'data' => array_merge(mobileTaskSummary($task), [
            'voice_call_id' => $callId,
            'result_route' => '/tasks/' . $taskId,
        ]),
    ]);
} catch (Throwable $e) {
    error_log('Mobile voice follow-up failed for call_id=' . (int) ($mobileVoiceInput['call_id'] ?? 0) . ': ' . $e->getMessage());
    mobileJson(['success' => false, 'error' => $e->getMessage()], 422);
}
