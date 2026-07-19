<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Nurture;

class CustomerCareAutomationService
{
    public const DOMAIN_KEY = 'customer_care';

    private const ACTION_SUGGEST_CHECK_IN = 'suggest_check_in';
    private const ACTION_CREATE_TASK = 'create_check_in_task';
    private const ACTION_SCHEDULE_CHECK_IN = 'schedule_check_in';
    private const ACTION_ENROLL_PLAN = 'enroll_follow_up_plan';
    private const ACTION_RECORD_CHECK_IN = 'record_check_in';
    private const ACTION_DRAFT_REPLY = 'draft_customer_reply';

    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?Nurture $nurture = null,
        private ?AIAutonomyGovernanceService $governance = null,
        private ?AIDemonstrationCaptureService $capture = null,
        private ?CustomerThreadAutonomyService $threadAutonomy = null,
        ?AIWorkspaceScopeService $workspaceScope = null
    ) {
        $this->nurture = $this->nurture ?? new Nurture();
        $this->governance = $this->governance ?? new AIAutonomyGovernanceService();
        $this->capture = $this->capture ?? new AIDemonstrationCaptureService();
        $this->threadAutonomy = $this->threadAutonomy ?? new CustomerThreadAutonomyService();
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function planNextAction(int $contactId, int $actorUserId): array
    {
        [$profile, $brief] = $this->profileAndBrief($contactId);
        $governance = $this->evaluateCareAction(self::ACTION_SUGGEST_CHECK_IN, $profile, $brief);
        $decision = $this->decisionForSuggestion($governance);

        if ($decision !== 'blocked') {
            $this->captureCareOutcome(self::ACTION_SUGGEST_CHECK_IN, $profile, $brief, $actorUserId, 'observed', [
                'suggestion_only' => true,
            ], 'user');
        }

        return $this->envelope(self::ACTION_SUGGEST_CHECK_IN, $decision, $governance, $brief, [
            'profile' => $profile,
            'suggested_action' => [
                'title' => (string) ($brief['task_title'] ?? 'Check in with customer'),
                'description' => (string) ($brief['task_description'] ?? $brief['suggested_next_step'] ?? ''),
                'why' => (string) ($brief['why_now'] ?? 'Check-in due'),
            ],
        ]);
    }

    public function createCheckInTask(int $contactId, int $actorUserId, array $payload = []): array
    {
        return $this->executeInternalAction(
            self::ACTION_CREATE_TASK,
            $contactId,
            $actorUserId,
            static function (Nurture $nurture, array $profile, array $brief) use ($contactId, $actorUserId, $payload): array {
                $taskId = $nurture->createFollowUpTask($contactId, [
                    'title' => $payload['title'] ?? ($brief['task_title'] ?? null),
                    'description' => $payload['description'] ?? ($brief['task_description'] ?? null),
                    'due_date' => $payload['due_date'] ?? ($profile['next_touch_at'] ?? null),
                    'created_by' => $actorUserId,
                    'actor_user_id' => $actorUserId,
                ]);

                return ['task_id' => $taskId];
            },
            $payload
        );
    }

    public function scheduleCheckIn(int $contactId, int $actorUserId, string $scheduledAt): array
    {
        return $this->executeInternalAction(
            self::ACTION_SCHEDULE_CHECK_IN,
            $contactId,
            $actorUserId,
            static function (Nurture $nurture) use ($contactId, $scheduledAt): array {
                $profile = $nurture->updateProfile($contactId, [
                    'nurture_status' => 'active',
                    'next_touch_at' => $scheduledAt,
                    'next_touch_reason' => 'Scheduled customer check-in from Customer Care.',
                ]);

                return ['profile' => $profile];
            },
            ['scheduled_at' => $scheduledAt]
        );
    }

    public function enrollFollowUpPlan(array $contactIds, int $programId, int $actorUserId): array
    {
        $contactIds = array_values(array_unique(array_filter(array_map('intval', $contactIds))));
        $primaryContactId = (int) ($contactIds[0] ?? 0);
        if ($primaryContactId <= 0) {
            throw new \InvalidArgumentException('At least one customer is required.');
        }

        return $this->executeInternalAction(
            self::ACTION_ENROLL_PLAN,
            $primaryContactId,
            $actorUserId,
            static function (Nurture $nurture) use ($contactIds, $programId): array {
                return $nurture->enrollContacts($programId, $contactIds);
            },
            ['program_id' => $programId, 'contact_ids' => $contactIds]
        );
    }

    public function recordCheckIn(int $contactId, int $actorUserId, array $payload = []): array
    {
        return $this->executeInternalAction(
            self::ACTION_RECORD_CHECK_IN,
            $contactId,
            $actorUserId,
            static function (Nurture $nurture) use ($contactId, $actorUserId, $payload): array {
                $profile = $nurture->recordCheckIn($contactId, array_merge($payload, [
                    'created_by' => $actorUserId,
                ]));

                return ['profile' => $profile];
            },
            $payload,
            'completed'
        );
    }

    public function draftCustomerReply(int $contactId, int $actorUserId, array $threadContext = []): array
    {
        [$profile, $brief] = $this->profileAndBrief($contactId);
        $contact = $this->contactFromProfile($profile);
        $careGovernance = $this->evaluateCareAction(self::ACTION_DRAFT_REPLY, $profile, $brief, [
            'requires_customer_send' => true,
            'customer_facing' => true,
        ]);
        $assessment = $this->threadAutonomy->assess($threadContext, $contact);
        $threadReasons = $this->threadAutonomy->explanation($assessment);
        $threadPolicy = empty($threadReasons)
            ? ['decision' => 'allow', 'reasons' => []]
            : ['decision' => 'reject', 'reasons' => $threadReasons];
        $threadGovernance = $this->governance->evaluate(
            $this->tenantKey(),
            'customer_thread',
            'send_customer_reply',
            $this->threadAutonomy->augmentPolicyContext([
                'contact' => $contact,
                'contact_id' => $contactId,
                'recipient' => (string) ($contact['email'] ?? ''),
                'requires_customer_send' => true,
                'source_domain' => self::DOMAIN_KEY,
                'care_brief' => $brief,
            ], $assessment),
            $threadPolicy
        );

        $decision = $this->combineDraftDecision($careGovernance, $threadGovernance, $threadReasons);
        if ($decision !== 'blocked') {
            $this->captureCareOutcome(self::ACTION_DRAFT_REPLY, $profile, $brief, $actorUserId, 'observed', [
                'customer_facing' => true,
                'thread_reasons' => $threadReasons,
            ], 'user');
        }

        return $this->envelope(self::ACTION_DRAFT_REPLY, $decision, $careGovernance, $brief, [
            'draft' => [
                'subject' => 'Checking in',
                'body' => $this->deterministicReplyDraft($profile, $brief),
            ],
            'thread_assessment' => $assessment,
            'thread_governance' => $threadGovernance,
            'message' => empty($threadReasons)
                ? 'Draft needs human approval before any customer message is sent.'
                : 'Draft needs review because customer message context is incomplete.',
        ], $threadReasons);
    }

    public function recordOutcome(string $actionKey, int $contactId, int $actorUserId, string $outcomeLabel, array $payload = []): int
    {
        [$profile, $brief] = $this->profileAndBrief($contactId);
        return $this->captureCareOutcome($actionKey, $profile, $brief, $actorUserId, $outcomeLabel, $payload, 'user');
    }

    private function executeInternalAction(string $actionKey, int $contactId, int $actorUserId, callable $operation, array $payload = [], string $successOutcome = 'accepted'): array
    {
        [$profile, $brief] = $this->profileAndBrief($contactId);
        $governance = $this->evaluateCareAction($actionKey, $profile, $brief, $payload);
        $decision = $this->decisionForInternalAction($governance);

        if ($decision !== 'allow') {
            return $this->envelope($actionKey, $decision, $governance, $brief, null);
        }

        try {
            $result = $operation($this->nurture, $profile, $brief);
            $this->captureCareOutcome($actionKey, $profile, $brief, $actorUserId, $successOutcome, $payload, 'system', $result);

            return $this->envelope($actionKey, 'allow', $governance, $brief, $result);
        } catch (\Throwable $e) {
            $this->captureCareOutcome($actionKey, $profile, $brief, $actorUserId, 'failed', array_merge($payload, [
                'error' => $e->getMessage(),
            ]), 'system');

            return $this->envelope($actionKey, 'failed', $governance, $brief, [
                'error' => $e->getMessage(),
            ], ['action_failed']);
        }
    }

    private function evaluateCareAction(string $actionKey, array $profile, array $brief, array $extraContext = []): array
    {
        return $this->governance->evaluate(
            $this->tenantKey(),
            self::DOMAIN_KEY,
            $actionKey,
            array_merge([
                'contact_id' => (int) ($profile['contact_id'] ?? 0),
                'contact' => $this->contactFromProfile($profile),
                'care_brief' => $brief,
                'care_status' => (string) ($profile['nurture_status'] ?? ''),
                'care_reason' => (string) ($brief['why_now'] ?? ''),
                'customer_facing' => false,
            ], $extraContext),
            ['decision' => 'allow', 'reasons' => []]
        );
    }

    private function decisionForSuggestion(array $governance): string
    {
        $governanceDecision = (string) ($governance['decision'] ?? 'blocked');
        if ($governanceDecision === 'blocked') {
            return 'blocked';
        }
        if ($governanceDecision === 'approval_required') {
            return 'approval_required';
        }

        return 'suggest_only';
    }

    private function decisionForInternalAction(array $governance): string
    {
        $governanceDecision = (string) ($governance['decision'] ?? 'blocked');
        if ($governanceDecision === 'blocked') {
            return 'blocked';
        }
        if ($governanceDecision === 'approval_required') {
            return 'approval_required';
        }

        $mode = (string) ($governance['control']['autonomy_mode'] ?? 'suggest_only');
        if ($mode === 'suggest_only') {
            return 'suggest_only';
        }

        return in_array($mode, ['auto_safe', 'full_auto'], true) ? 'allow' : 'blocked';
    }

    private function combineDraftDecision(array $careGovernance, array $threadGovernance, array $threadReasons): string
    {
        if (($careGovernance['decision'] ?? '') === 'blocked') {
            return 'blocked';
        }
        if (($threadGovernance['decision'] ?? '') === 'blocked') {
            return in_array('missing_thread_recipient', $threadReasons, true) ? 'blocked' : 'approval_required';
        }

        return 'approval_required';
    }

    private function envelope(string $actionKey, string $decision, array $governance, array $brief, $result, array $extraReasonCodes = []): array
    {
        $reasonCodes = array_values(array_unique(array_filter(array_merge(
            (array) ($governance['reason_codes'] ?? []),
            $extraReasonCodes,
            $decision === 'suggest_only' ? ['suggest_only_mode'] : []
        ))));

        return [
            'decision' => $decision,
            'action_key' => $actionKey,
            'can_execute' => $decision === 'allow',
            'approval_required' => $decision === 'approval_required',
            'reason_codes' => $reasonCodes,
            'brief' => $brief,
            'result' => $result,
            'governance' => $governance,
        ];
    }

    private function profileAndBrief(int $contactId): array
    {
        $profile = $this->nurture->getOrCreateProfile($contactId);
        return [$profile, $this->nurture->careBrief($profile)];
    }

    private function contactFromProfile(array $profile): array
    {
        return [
            'id' => (int) ($profile['contact_id'] ?? 0),
            'first_name' => (string) ($profile['first_name'] ?? ''),
            'last_name' => (string) ($profile['last_name'] ?? ''),
            'name' => (string) ($profile['contact_name'] ?? ''),
            'email' => (string) ($profile['email'] ?? ''),
            'company' => (string) ($profile['company'] ?? ''),
        ];
    }

    private function captureCareOutcome(string $actionKey, array $profile, array $brief, int $actorUserId, string $outcomeLabel, array $payload = [], string $actorType = 'user', array $outcomeState = []): int
    {
        return $this->capture->capture([
            'tenant_key' => $this->tenantKey(),
            'actor_user_id' => $actorUserId > 0 ? $actorUserId : null,
            'actor_type' => $actorType,
            'source_surface' => self::DOMAIN_KEY,
            'domain_key' => self::DOMAIN_KEY,
            'entity_type' => 'contact',
            'entity_id' => (int) ($profile['contact_id'] ?? 0),
            'action_key' => $actionKey,
            'prior_state' => [
                'profile_id' => (int) ($profile['id'] ?? 0),
                'nurture_status' => (string) ($profile['nurture_status'] ?? ''),
                'next_touch_at' => $profile['next_touch_at'] ?? null,
            ],
            'action_payload' => $payload,
            'outcome_state' => $outcomeState,
            'outcome_label' => $outcomeLabel,
            'metadata' => $this->learningMetadata($profile, $brief, $payload),
            'linked_task_id' => !empty($outcomeState['task_id']) ? (int) $outcomeState['task_id'] : null,
            'was_successful' => in_array($outcomeLabel, ['accepted', 'approved', 'completed', 'observed'], true),
            'was_reversed' => $outcomeLabel === 'reversed',
            'was_edited' => $outcomeLabel === 'edited',
        ]);
    }

    private function learningMetadata(array $profile, array $brief, array $payload): array
    {
        $lastTouchAt = (string) ($profile['last_touch_at'] ?? '');
        $daysSinceLastTouch = 0;
        if ($lastTouchAt !== '' && strtotime($lastTouchAt)) {
            $daysSinceLastTouch = max(0, (int) floor((time() - strtotime($lastTouchAt)) / 86400));
        }

        return [
            'care_reason' => (string) ($brief['why_now'] ?? ''),
            'care_status' => (string) ($profile['nurture_status'] ?? ''),
            'cadence' => (string) ($profile['cadence'] ?? ''),
            'plan_id' => !empty($profile['active_program_id']) ? (int) $profile['active_program_id'] : (!empty($payload['program_id']) ? (int) $payload['program_id'] : null),
            'health_score' => (int) ($profile['health_score'] ?? 0),
            'days_since_last_touch' => $daysSinceLastTouch,
            'customer_facing' => !empty($payload['customer_facing']),
            'task_intent' => 'customer_relationship_follow_up',
        ];
    }

    private function deterministicReplyDraft(array $profile, array $brief): string
    {
        $name = trim((string) ($profile['first_name'] ?? ''));
        $greeting = $name !== '' ? 'Hi ' . $name . ',' : 'Hi,';

        return $greeting . "\n\n"
            . (string) ($brief['suggested_next_step'] ?? 'I wanted to check how things are going and whether anything needs attention.')
            . "\n\n"
            . 'Would a quick check-in be useful?';
    }

    private function tenantKey(): string
    {
        return $this->workspaceScope->currentTenantKey();
    }
}
