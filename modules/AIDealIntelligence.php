<?php
/**
 * AI Deal Intelligence
 *
 * Next-step suggestions, deal history summary, close-date prediction.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AIService;

class AIDealIntelligence
{
    private AIService $aiService;
    private Deals $deals;
    private Notes $notes;
    private Activities $activities;

    public function __construct()
    {
        $this->aiService = new AIService();
        $this->deals = new Deals();
        $this->notes = new Notes();
        $this->activities = new Activities();
    }

    /**
     * Get suggested next steps for a deal.
     *
     * @return array { next_steps: [{ action, priority }] }
     */
    public function getNextSteps(int $dealId, ?int $userId = null): array
    {
        $context = $this->buildDealContext($dealId, $userId);
        $raw = $this->aiService->process('deal_next_steps', [
            'context' => $context,
            'text' => json_encode($context, JSON_PRETTY_PRINT),
        ], ['surface' => 'commercial_assistant']);
        return $this->parseNextStepsResponse($raw);
    }

    /**
     * Get narrative summary of deal history.
     */
    public function getDealSummary(int $dealId, ?int $userId = null): string
    {
        $context = $this->buildDealContext($dealId, $userId);
        return $this->aiService->process('deal_summary', [
            'text' => json_encode($context, JSON_PRETTY_PRINT),
        ], ['surface' => 'commercial_assistant']);
    }

    /**
     * Predict close date for a deal.
     *
     * @return array|null { predicted_date, confidence, reasoning }
     */
    public function predictCloseDate(int $dealId, ?int $userId = null): ?array
    {
        $context = $this->buildDealContext($dealId, $userId);
        $raw = $this->aiService->process('deal_close_date', [
            'context' => $context,
            'text' => json_encode($context, JSON_PRETTY_PRINT),
        ], ['surface' => 'commercial_assistant']);
        return $this->parseCloseDateResponse($raw);
    }

    /** @return array<string,mixed> */
    public function getLastProviderStatus(): array
    {
        return $this->aiService->getLastProviderStatus();
    }

    private function buildDealContext(int $dealId, ?int $userId): array
    {
        $deal = $this->deals->getById($dealId);
        if (!$deal) {
            throw new \Exception("Deal not found");
        }

        $userId = $userId ?? ($_SESSION['user_id'] ?? 0);
        $notes = $this->notes->getEntityNotes('deal', $dealId, false, $userId);
        $contactId = (int) ($deal['contact_id'] ?? 0);
        $activities = $contactId ? $this->activities->getByContact($contactId, 20, 0) : [];

        return [
            'deal' => [
                'id' => $deal['id'],
                'title' => $deal['title'],
                'stage' => $deal['stage'],
                'value' => $deal['value'],
                'currency' => $deal['currency'],
                'probability' => $deal['probability'],
                'expected_close_date' => $deal['expected_close_date'],
                'description' => mb_substr(strip_tags($deal['description'] ?? ''), 0, 500),
            ],
            'notes' => array_map(fn($n) => [
                'title' => $n['title'] ?? null,
                'content_preview' => mb_substr(strip_tags($n['content'] ?? ''), 0, 300),
                'created_at' => $n['created_at'] ?? null,
            ], array_slice($notes, 0, 10)),
            'activities' => array_map(fn($a) => [
                'activity_type' => $a['activity_type'],
                'description' => $a['description'],
                'created_at' => $a['created_at'],
            ], $activities),
        ];
    }

    private function parseNextStepsResponse(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['next_steps'])) {
            return ['next_steps' => $decoded['next_steps']];
        }
        return ['next_steps' => []];
    }

    private function parseCloseDateResponse(string $raw): ?array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($decoded['predicted_date'])) {
            return [
                'predicted_date' => $decoded['predicted_date'],
                'confidence' => (float) ($decoded['confidence'] ?? 0.5),
                'reasoning' => $decoded['reasoning'] ?? '',
            ];
        }
        return null;
    }
}
