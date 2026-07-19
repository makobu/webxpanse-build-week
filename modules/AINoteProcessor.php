<?php
/**
 * AI Note Processor
 *
 * Summarize notes and extract action items.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AIService;

class AINoteProcessor
{
    private AIService $aiService;
    private Notes $notes;

    public function __construct()
    {
        $this->aiService = new AIService();
        $this->notes = new Notes();
    }

    /**
     * Summarize multiple notes.
     *
     * @param array $noteIds Note IDs
     * @return string Summary text
     */
    public function summarizeNotes(array $noteIds): string
    {
        if (empty($noteIds)) {
            return 'No notes to summarize.';
        }

        $contents = [];
        foreach ($noteIds as $id) {
            $note = $this->notes->getById((int) $id);
            if ($note) {
                $text = strip_tags($note['content'] ?? '');
                if ($note['title'] ?? '') {
                    $text = "[" . $note['title'] . "] " . $text;
                }
                $contents[] = $text;
            }
        }

        $combined = implode("\n\n---\n\n", array_slice($contents, 0, 20));
        $combined = mb_substr($combined, 0, 8000);

        return $this->aiService->process('summarization', [
            'text' => $combined,
        ]);
    }

    /**
     * Extract action items from note content.
     *
     * @return array [{ text, due, assignee_hint }]
     */
    public function extractActionItems(string $noteContent): array
    {
        $text = strip_tags($noteContent);
        $text = mb_substr($text, 0, 4000);

        if (empty(trim($text))) {
            return [];
        }

        $raw = $this->aiService->process('action_item_extraction', [
            'text' => $text,
            'content' => $text,
        ]);

        return $this->parseActionItemsResponse($raw);
    }

    private function parseActionItemsResponse(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($decoded['actions']) && is_array($decoded['actions'])) {
            return array_map(function ($a) {
                return [
                    'text' => $a['text'] ?? '',
                    'due' => $a['due'] ?? null,
                    'assignee_hint' => $a['assignee_hint'] ?? null,
                ];
            }, $decoded['actions']);
        }
        return [];
    }
}
