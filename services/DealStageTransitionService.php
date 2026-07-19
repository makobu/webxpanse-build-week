<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Modules\Activities;
use CRM\Modules\DealAutomationConfig;
use CRM\Modules\Deals;
use CRM\Modules\Notes;

class DealStageTransitionService
{
    private const STAGE_LABELS = [
        'prospecting' => 'Prospecting',
        'qualification' => 'Qualification',
        'proposal' => 'Proposal',
        'negotiation' => 'Negotiation',
        'closed_won' => 'Won',
        'closed_lost' => 'Lost',
    ];

    private const STAGE_COLORS = [
        'prospecting' => '#64748b',
        'qualification' => '#2563eb',
        'proposal' => '#f59e0b',
        'negotiation' => '#16a34a',
        'closed_won' => '#059669',
        'closed_lost' => '#dc2626',
    ];

    public function transition(int $dealId, string $toStage, array $options = []): array
    {
        $reason = trim((string) ($options['reason'] ?? ''));
        $closeDate = trim((string) ($options['close_date'] ?? ''));
        $closeNote = trim((string) ($options['close_note'] ?? ''));
        $createdBy = (int) ($options['created_by'] ?? (Auth::user()['id'] ?? 0));
        $noteTitle = trim((string) ($options['note_title'] ?? 'Stage transition note'));
        $actorLabel = trim((string) ($options['actor_label'] ?? 'Manual'));

        if (!in_array($toStage, DealAutomationConfig::getAllowedStages(), true)) {
            throw new \RuntimeException('Invalid stage');
        }

        $deals = new Deals();
        $deal = $deals->getById($dealId);
        if (!$deal) {
            throw new \RuntimeException('Deal not found');
        }
        if ($toStage === (string) ($deal['stage'] ?? '')) {
            throw new \RuntimeException('Deal is already in that stage');
        }

        $order = ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
        $fromIndex = array_search((string) $deal['stage'], $order, true);
        $toIndex = array_search($toStage, $order, true);
        $allowMultiStageJump = !empty((new DealAutomationConfig())->get()['allow_multi_stage_jump']);
        if ($fromIndex !== false && $toIndex !== false && !$allowMultiStageJump && abs($toIndex - $fromIndex) > 1) {
            throw new \RuntimeException('That move is blocked unless multi-stage jump is enabled in deal automation settings.');
        }

        $isTerminal = in_array($toStage, ['closed_won', 'closed_lost'], true);
        if ($isTerminal) {
            if ($closeDate === '') {
                throw new \RuntimeException('close_date is required for won/lost transitions');
            }
            if ($toStage === 'closed_lost' && $reason === '') {
                throw new \RuntimeException('reason is required for lost transitions');
            }
            if ($reason === '' && $closeNote === '') {
                throw new \RuntimeException('A close note or reason is required for terminal transitions');
            }
        }

        $updatePayload = ['stage' => $toStage];
        if ($closeDate !== '') {
            $updatePayload['actual_close_date'] = $closeDate;
        }
        $deals->update($dealId, $updatePayload);

        $noteBody = trim(implode("\n\n", array_filter([
            "{$actorLabel} deal stage transition: " . (self::STAGE_LABELS[$deal['stage']] ?? $deal['stage']) . ' -> ' . (self::STAGE_LABELS[$toStage] ?? $toStage),
            $reason !== '' ? 'Reason: ' . $reason : '',
            $closeNote !== '' ? 'Close note: ' . $closeNote : '',
        ])));
        if ($noteBody !== '') {
            (new Notes())->create([
                'entity_type' => 'deal',
                'entity_id' => $dealId,
                'title' => $noteTitle,
                'content' => $noteBody,
                'created_by' => $createdBy,
            ]);
        }

        if (!empty($deal['contact_id'])) {
            (new Activities())->log(
                (int) $deal['contact_id'],
                'deal_stage_changed_manual',
                "Deal moved from {$deal['stage']} to {$toStage}"
            );
        }

        return [
            'success' => true,
            'deal_id' => $dealId,
            'from_stage' => (string) $deal['stage'],
            'to_stage' => $toStage,
            'stage_label' => self::STAGE_LABELS[$toStage] ?? ucfirst(str_replace('_', ' ', $toStage)),
            'stage_color' => self::STAGE_COLORS[$toStage] ?? '#64748b',
            'message' => 'Deal stage updated successfully',
        ];
    }
}
