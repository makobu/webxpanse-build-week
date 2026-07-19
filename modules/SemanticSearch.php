<?php
/**
 * Semantic Search Module
 *
 * Translates natural-language queries into structured filters and runs search.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\AIService;

class SemanticSearch
{
    private AIService $aiService;
    private Search $search;

    public function __construct()
    {
        $this->aiService = new AIService();
        $this->search = new Search();
    }

    /**
     * Parse natural-language query into structured filters.
     *
     * @return array { filters: { stage?, tags?, min_score?, entity_types? }, keywords: string[] }
     */
    public function parseQuery(string $query): array
    {
        $query = trim($query);
        if (empty($query)) {
            return ['filters' => [], 'keywords' => []];
        }

        $raw = $this->aiService->process('search_query_parse', [
            'query' => $query,
            'text' => $query,
        ]);

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return [
                'filters' => $decoded['filters'] ?? [],
                'keywords' => $decoded['keywords'] ?? $this->extractKeywords($query),
            ];
        }

        return ['filters' => [], 'keywords' => [$query]];
    }

    /**
     * Search using semantic parsing + optional filters.
     */
    public function search(string $query, int $limit = 20): array
    {
        $parsed = $this->parseQuery($query);
        $filters = $parsed['filters'] ?? [];
        $keywords = $parsed['keywords'] ?? [$query];

        if (empty($keywords) && empty(array_filter($filters))) {
            return $this->search->search($query, $limit);
        }

        $searchTerm = '%' . Security::sanitizeInput(implode(' ', $keywords), 'string') . '%';
        if (empty(trim($searchTerm, '% '))) {
            $searchTerm = '%' . Security::sanitizeInput($query, 'string') . '%';
        }

        $results = [];
        $entityTypes = $filters['entity_types'] ?? ['contact', 'deal', 'task'];

        if (in_array('contact', $entityTypes)) {
            $contacts = $this->searchContacts($searchTerm, $filters, $limit);
            if (!empty($contacts)) {
                $results['contacts'] = $contacts;
            }
        }

        if (in_array('deal', $entityTypes)) {
            $deals = $this->searchDeals($searchTerm, $filters, $limit);
            if (!empty($deals)) {
                $results['deals'] = $deals;
            }
        }

        if (in_array('task', $entityTypes)) {
            $tasks = $this->searchTasks($searchTerm, $filters, $limit);
            if (!empty($tasks)) {
                $results['tasks'] = $tasks;
            }
        }

        if (empty($results)) {
            return $this->search->search($query, $limit);
        }

        return $results;
    }

    private function searchContacts(string $searchTerm, array $filters, int $limit): array
    {
        $where = ["(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR company LIKE ?)"];
        $params = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];

        if (!empty($filters['stage'])) {
            $where[] = "stage = ?";
            $params[] = $filters['stage'];
        }
        if (isset($filters['min_score']) && $filters['min_score'] !== null) {
            $where[] = "COALESCE(lead_score, 0) >= ?";
            $params[] = (int) $filters['min_score'];
        }

        $params[] = $limit;
        return Database::query(
            "SELECT id, first_name, last_name, email, company, stage, 'contact' as entity_type
             FROM contacts
             WHERE " . implode(' AND ', $where) . "
             ORDER BY first_name, last_name ASC
             LIMIT ?",
            $params
        );
    }

    private function searchDeals(string $searchTerm, array $filters, int $limit): array
    {
        $where = ["(d.title LIKE ? OR d.description LIKE ?)"];
        $params = [$searchTerm, $searchTerm];

        if (!empty($filters['stage'])) {
            $where[] = "d.stage = ?";
            $params[] = $filters['stage'];
        }

        $params[] = $limit;
        return Database::query(
            "SELECT d.id, d.title, d.stage, d.value, d.currency, 'deal' as entity_type,
                    c.first_name, c.last_name, c.email as contact_email
             FROM deals d
             LEFT JOIN contacts c ON d.contact_id = c.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY d.created_at DESC
             LIMIT ?",
            $params
        );
    }

    private function searchTasks(string $searchTerm, array $filters, int $limit): array
    {
        $params = [$searchTerm, $searchTerm, $limit];
        return Database::query(
            "SELECT t.id, t.title, t.status, t.priority, t.due_date, 'task' as entity_type,
                    c.first_name, c.last_name, c.email as contact_email
             FROM tasks t
             LEFT JOIN contacts c ON t.contact_id = c.id
             WHERE t.title LIKE ? OR t.description LIKE ?
             ORDER BY t.created_at DESC
             LIMIT ?",
            $params
        );
    }

    private function extractKeywords(string $query): array
    {
        $words = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        return array_filter($words, fn($w) => strlen($w) > 1);
    }
}
