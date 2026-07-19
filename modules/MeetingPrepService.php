<?php
/**
 * Meeting Prep Service
 *
 * Generates one-click meeting prep summary: contact + deals + notes + activities + communications.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AIService;
use CRM\Services\WorkspaceScopeService;

class MeetingPrepService
{
    private AIService $aiService;
    private Notes $notes;
    private Deals $deals;
    private Activities $activities;
    private UnifiedInbox $inbox;

    public function __construct(
        ?AIService $aiService = null,
        ?Notes $notes = null,
        ?Deals $deals = null,
        ?Activities $activities = null,
        ?UnifiedInbox $inbox = null
    )
    {
        $this->aiService = $aiService ?? new AIService();
        $this->notes = $notes ?? new Notes();
        $this->deals = $deals ?? new Deals();
        $this->activities = $activities ?? new Activities();
        $this->inbox = $inbox ?? new UnifiedInbox();
    }

    /**
     * Get meeting prep summary for a contact, optionally focused on a deal.
     *
     * @param int $contactId Contact ID
     * @param int|null $dealId Optional deal ID to focus on
     * @param int|null $userId User ID for notes visibility
     * @return array { summary, key_points, open_questions, suggested_topics }
     */
    public function getPrepSummary(int $contactId, ?int $dealId = null, ?int $userId = null): array
    {
        $userId = $userId ?? ($_SESSION['user_id'] ?? 0);

        $context = $this->buildPrepContext($contactId, $dealId, $userId);
        $contact = $context['contact'];
        if (!$contact) {
            throw new \Exception("Contact not found");
        }

        $promptContext = [
            'contact' => $this->sanitizeForJson($contact),
            'active_deals' => array_map(fn($d) => $this->sanitizeForJson($d), array_slice($context['active_deals'], 0, 5)),
            'focus_deal' => $context['focus_deal'] ? $this->sanitizeForJson($context['focus_deal']) : null,
            'notes' => array_map(fn($n) => [
                'title' => $n['title'] ?? null,
                'content_preview' => mb_substr(strip_tags($n['content'] ?? ''), 0, 300),
                'created_at' => $n['created_at'] ?? null,
            ], $context['notes']),
            'activities' => array_map(fn($a) => [
                'activity_type' => $a['activity_type'] ?? null,
                'description' => $a['description'] ?? null,
                'created_at' => $a['created_at'] ?? null,
            ], $context['activities']),
            'communications' => array_map(fn($c) => [
                'channel' => $c['channel'] ?? null,
                'direction' => $c['direction'] ?? null,
                'subject' => $c['subject'] ?? null,
                'body_preview' => mb_substr(strip_tags($c['body'] ?? ''), 0, 200),
                'created_at' => $c['created_at'] ?? null,
            ], $context['communications']),
        ];

        try {
            $raw = $this->aiService->process('meeting_prep_summary', [
                'context' => $promptContext,
                'text' => json_encode($promptContext, JSON_PRETTY_PRINT),
            ]);
        } catch (\Throwable $e) {
            $raw = '';
        }

        $parsed = $this->parseMeetingPrepResponse($raw);
        if ($this->hasMeaningfulPrep($parsed)) {
            $parsed['used_fallback'] = false;
            return $parsed;
        }

        $fallback = $this->buildFallbackSummaryFromContext($context);
        $fallback['used_fallback'] = true;
        return $fallback;
    }

    public function buildFallbackSummary(int $contactId, ?int $dealId = null, ?int $userId = null): array
    {
        $userId = $userId ?? ($_SESSION['user_id'] ?? 0);
        $context = $this->buildPrepContext($contactId, $dealId, $userId);
        $contact = $context['contact'];
        if (!$contact) {
            throw new \Exception("Contact not found");
        }

        $fallback = $this->buildFallbackSummaryFromContext($context);
        $fallback['used_fallback'] = true;
        return $fallback;
    }

    private function sanitizeForJson(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if (is_string($v) || is_numeric($v) || is_bool($v) || is_null($v)) {
                $out[$k] = $v;
            } elseif (is_array($v)) {
                $out[$k] = $this->sanitizeForJson($v);
            }
        }
        return $out;
    }

    private function parseMeetingPrepResponse(string $raw): array
    {
        $default = [
            'summary' => '',
            'key_points' => [],
            'open_questions' => [],
            'suggested_topics' => [],
        ];

        // Try JSON first
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [
                'summary' => $decoded['summary'] ?? $decoded['brief'] ?? '',
                'key_points' => $decoded['key_points'] ?? $decoded['keyPoints'] ?? [],
                'open_questions' => $decoded['open_questions'] ?? $decoded['openQuestions'] ?? [],
                'suggested_topics' => $decoded['suggested_topics'] ?? $decoded['suggestedTopics'] ?? [],
            ];
        }

        // Fallback: treat entire response as summary
        $default['summary'] = trim($raw);
        return $default;
    }

    private function hasMeaningfulPrep(array $prep): bool
    {
        return trim((string) ($prep['summary'] ?? '')) !== ''
            || !empty($prep['key_points'])
            || !empty($prep['open_questions'])
            || !empty($prep['suggested_topics']);
    }

    private function buildPrepContext(int $contactId, ?int $dealId, int $userId): array
    {
        $workspaceId = $this->requireWorkspaceId();
        $contact = Database::queryOne(
            "SELECT * FROM contacts WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );

        $deals = $contact ? $this->deals->getContactDeals($contactId) : [];
        $activeDeals = array_values(array_filter(
            $deals,
            static fn($d) => !in_array($d['stage'] ?? '', ['closed_won', 'closed_lost'], true)
        ));

        $focusDeal = null;
        if ($dealId) {
            $dealInWorkspace = Database::queryOne(
                "SELECT id
                 FROM deals
                 WHERE workspace_id = ?
                   AND id = ?
                   AND contact_id = ?
                 LIMIT 1",
                [$workspaceId, $dealId, $contactId]
            );
            if (!$dealInWorkspace) {
                throw new \Exception("Contact not found");
            }

            foreach ($deals as $deal) {
                if ((int) ($deal['id'] ?? 0) === $dealId) {
                    $focusDeal = $deal;
                    break;
                }
            }
        }

        $contactNotes = $contact ? $this->notes->getEntityNotes('contact', $contactId, false, $userId) : [];
        $dealNotes = [];
        if ($contact && $dealId) {
            $dealNotes = $this->notes->getEntityNotes('deal', $dealId, false, $userId);
        }
        $notes = array_merge($contactNotes, $dealNotes);
        usort($notes, fn($a, $b) => strtotime($b['created_at'] ?? '0') <=> strtotime($a['created_at'] ?? '0'));
        $notes = array_slice($notes, 0, 10);

        $activities = $contact ? $this->activities->getByContact($contactId, 15, 0) : [];
        $communications = $contact ? $this->inbox->getByContact($contactId, 10, 0) : [];

        return [
            'contact' => $contact,
            'active_deals' => $activeDeals,
            'focus_deal' => $focusDeal,
            'notes' => $notes,
            'activities' => $activities,
            'communications' => $communications,
        ];
    }

    private function requireWorkspaceId(): int
    {
        return (new WorkspaceScopeService())->requireActiveWorkspaceId();
    }

    private function buildFallbackSummaryFromContext(array $context): array
    {
        $contact = $context['contact'] ?? null;
        if (!is_array($contact) || !$contact) {
            throw new \RuntimeException('Contact not found');
        }

        $name = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));
        if ($name === '') {
            $name = (string) ($contact['email'] ?? 'Contact');
        }

        $activeDeals = array_values($context['active_deals'] ?? []);
        $activities = array_values($context['activities'] ?? []);
        $recentNotes = array_values($context['notes'] ?? []);
        $communications = array_values($context['communications'] ?? []);

        $keyPoints = [];
        if (!empty($contact['stage'])) {
            $keyPoints[] = 'Contact stage: ' . $contact['stage'];
        }
        if (!empty($contact['company'])) {
            $keyPoints[] = 'Company: ' . $contact['company'];
        }

        if (!empty($activeDeals)) {
            foreach (array_slice($activeDeals, 0, 3) as $deal) {
                $dealLine = 'Deal "' . ($deal['title'] ?? ('#' . (int) ($deal['id'] ?? 0))) . '"';
                if (!empty($deal['stage'])) {
                    $dealLine .= ' is at stage ' . $deal['stage'];
                }
                if (!empty($deal['value'])) {
                    $dealLine .= ' with value ' . $deal['value'];
                }
                $dealLine .= '.';
                $keyPoints[] = $dealLine;
            }
        } else {
            $keyPoints[] = 'No active deals linked to this contact right now.';
        }

        if (!empty($activities[0]['created_at'])) {
            $keyPoints[] = 'Latest activity logged on ' . date('M j, Y', strtotime((string) $activities[0]['created_at'])) . '.';
        } else {
            $keyPoints[] = 'No recent activities found.';
        }

        if (!empty($communications)) {
            $channels = array_values(array_unique(array_filter(array_map(static fn($row) => $row['channel'] ?? null, $communications))));
            if (!empty($channels)) {
                $keyPoints[] = 'Recent communication channels: ' . implode(', ', $channels) . '.';
            }
        }

        $openQuestions = [];
        if (empty($communications)) {
            $openQuestions[] = 'What is the preferred communication channel for this contact?';
        }
        if (empty($recentNotes)) {
            $openQuestions[] = 'What goals or blockers should be captured in notes before the meeting?';
        }
        if (empty($activeDeals)) {
            $openQuestions[] = 'Should a new deal be opened from this conversation?';
        } else {
            $openQuestions[] = 'What decision criteria and timeline does the buyer have for the active deal?';
        }

        $suggestedTopics = [
            'Current priorities and immediate business goals',
            'Decision process, stakeholders, and timeline',
            'Concrete next step and follow-up owner',
        ];
        if (!empty($activeDeals)) {
            $suggestedTopics[] = 'Deal blockers and actions needed to progress stage';
        }

        $summary = 'Meeting prep for ' . $name . ': review current stage, recent interactions, and align on a clear next step.'
            . (empty($activeDeals)
                ? ' There are no active deals, so prioritize qualification and opportunity discovery.'
                : ' Focus on moving the active deal(s) forward with agreed actions.');

        return [
            'summary' => $summary,
            'key_points' => array_values(array_slice($keyPoints, 0, 6)),
            'open_questions' => array_values(array_slice($openQuestions, 0, 3)),
            'suggested_topics' => array_values(array_slice($suggestedTopics, 0, 4)),
        ];
    }
}
