<?php

namespace CRM\Services;

final class AICoachDashboardBriefService
{
    /**
     * Build the lightweight, deterministic Coach summary used after the
     * dashboard's first paint. Full recommendations remain lazy-loaded.
     *
     * @param array<string,mixed> $readiness
     * @return array<string,mixed>
     */
    public function build(array $readiness): array
    {
        $payload = (array) ($readiness['onboarding_payload'] ?? []);
        $products = array_values(array_filter(
            (array) ($payload['products'] ?? []),
            static fn($product): bool => trim((string) (((array) $product)['name'] ?? '')) !== ''
        ));

        $signals = [
            !empty($readiness['company_context_ready']),
            $products !== [],
            !empty($readiness['clarity_journey_ready']),
            !empty($readiness['inherited_context_ready']),
            !empty($readiness['strategy_ready']),
            !empty($readiness['idea_validation_ready']),
        ];
        $completedSignals = count(array_filter($signals));
        $contextStrength = (int) round(($completedSignals / count($signals)) * 100);
        $missingRequirements = array_values(array_filter(
            array_map(static fn($item): array => (array) $item, (array) ($readiness['missing_requirements'] ?? [])),
            static fn(array $item): bool => trim((string) ($item['message'] ?? '')) !== ''
        ));
        $missingCount = count($missingRequirements);
        $recommendationsReady = !empty($readiness['recommendations_ready']);
        $nextRequirement = (array) ($missingRequirements[0] ?? []);
        $nextMessage = trim((string) ($nextRequirement['message'] ?? ''));

        if ($recommendationsReady) {
            return [
                'state' => 'ready',
                'title' => 'Your daily operating brief',
                'summary' => 'See what needs attention, why it matters, and turn the next move into tracked work.',
                'status_label' => "Coach is ready · Today's priorities",
                'primary_label' => "Open today's brief",
                'context_strength' => $contextStrength,
                'missing_count' => 0,
                'next_action' => null,
            ];
        }

        $statusLabel = $missingCount > 0
            ? $missingCount . ' setup ' . ($missingCount === 1 ? 'step' : 'steps') . ' to stronger guidance'
            : 'Coach context is being prepared';

        return [
            'state' => 'building',
            'title' => 'Build your daily operating brief',
            'summary' => $nextMessage !== ''
                ? $nextMessage
                : 'Complete the shared business context so Coach can rank the next best moves.',
            'status_label' => $statusLabel,
            'primary_label' => 'Open Coach setup',
            'context_strength' => $contextStrength,
            'missing_count' => $missingCount,
            'next_action' => $nextRequirement !== [] ? [
                'field' => (string) ($nextRequirement['field'] ?? ''),
                'action' => (string) ($nextRequirement['action'] ?? ''),
                'message' => $nextMessage,
            ] : null,
        ];
    }
}
