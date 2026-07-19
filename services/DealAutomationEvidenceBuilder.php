<?php
/**
 * Deal Automation Evidence Builder
 *
 * Aggregates evidence per deal/contact from:
 * - Recent communications (intent, sentiment, direction, timestamps)
 * - Proposal/quote sent event markers
 * - Contact/deal context (current stage, value, time in stage)
 * - Lead score band (cold/warm/hot)
 *
 * Normalizes into a deterministic payload for rules engine and AI.
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\UnifiedInbox;
use CRM\Modules\Deals;
use CRM\Modules\Activities;

class DealAutomationEvidenceBuilder
{
    private UnifiedInbox $inbox;
    private Deals $deals;
    private Activities $activities;

    public function __construct()
    {
        $this->inbox = new UnifiedInbox();
        $this->deals = new Deals();
        $this->activities = new Activities();
    }

    /**
     * Build evidence payload for a deal (and its contact).
     *
     * @param int $dealId Deal ID
     * @param int $lookbackDays Number of days to look back for evidence
     * @return array Normalized evidence payload
     */
    public function buildForDeal(int $dealId, int $lookbackDays = 14): array
    {
        $deal = $this->deals->getById($dealId);
        if (!$deal) {
            return $this->emptyPayload();
        }

        $contactId = (int) ($deal['contact_id'] ?? 0);
        if (!$contactId) {
            return $this->buildDealOnlyPayload($deal, $lookbackDays);
        }

        $communications = $this->getRecentCommunications($contactId, $lookbackDays);
        $proposalEvidence = $this->detectProposalSent($contactId, $lookbackDays, $communications);
        $contactContext = $this->getContactContext($contactId);
        $dealContext = $this->getDealContext($deal);
        $leadScoreBand = $this->getLeadScoreBand((int) ($contactContext['lead_score'] ?? 0));
        $invoiceEvidence = $this->getInvoiceEvidence($dealId, $contactId, $lookbackDays);
        $commercialState = $this->getCommercialState($dealId, $contactId);

        return [
            'deal_id' => $dealId,
            'contact_id' => $contactId,
            'current_stage' => $deal['stage'] ?? 'prospecting',
            'deal_value' => (float) ($deal['value'] ?? 0),
            'deal_currency' => $deal['currency'] ?? 'USD',
            'time_in_stage_days' => $dealContext['time_in_stage_days'],
            'communications' => $communications,
            'intent_counts' => $this->aggregateIntentCounts($communications),
            'sentiment_summary' => $this->aggregateSentiment($communications),
            'proposal_sent' => $proposalEvidence,
            'proposal_sent_at' => $proposalEvidence ? $this->getProposalSentAt($contactId, $lookbackDays) : null,
            'quote_created' => !empty($invoiceEvidence['quote_created']),
            'quote_sent' => !empty($invoiceEvidence['quote_sent']),
            'invoice_sent' => !empty($invoiceEvidence['invoice_sent']),
            'invoice_accepted' => !empty($invoiceEvidence['invoice_accepted']),
            'latest_document_type' => $commercialState['latest_document_type'],
            'latest_document_status' => $commercialState['latest_document_status'],
            'latest_document_sent_at' => $commercialState['latest_document_sent_at'],
            'latest_revision_number' => $commercialState['latest_revision_number'],
            'pending_approval_count' => $commercialState['pending_approval_count'],
            'delivery_failure_count' => $commercialState['delivery_failure_count'],
            'has_missing_pricing' => $commercialState['has_missing_pricing'],
            'has_missing_recipient' => $commercialState['has_missing_recipient'],
            'quote_viewed' => $commercialState['quote_viewed'],
            'quote_accepted' => $commercialState['quote_accepted'],
            'invoice_overdue' => $commercialState['invoice_overdue'],
            'lead_score' => (int) ($contactContext['lead_score'] ?? 0),
            'lead_score_band' => $leadScoreBand,
            'bidirectional_exchange' => $this->hasBidirectionalExchange($communications),
            'last_activity_at' => $this->getLastActivityAt($contactId),
            'lookback_days' => $lookbackDays,
            'built_at' => date('c'),
        ];
    }

    /**
     * Build evidence payload for a contact (no deal yet - e.g. for proposal-trigger create).
     *
     * @param int $contactId Contact ID
     * @param int $lookbackDays Number of days to look back
     * @return array Normalized evidence payload
     */
    public function buildForContact(int $contactId, int $lookbackDays = 14): array
    {
        $contactContext = $this->getContactContext($contactId);
        if (!$contactContext) {
            return $this->emptyPayload();
        }

        $communications = $this->getRecentCommunications($contactId, $lookbackDays);
        $proposalEvidence = $this->detectProposalSent($contactId, $lookbackDays, $communications);
        $invoiceEvidence = $this->getInvoiceEvidence(null, $contactId, $lookbackDays);
        $commercialState = $this->getCommercialState(null, $contactId);

        return [
            'deal_id' => null,
            'contact_id' => $contactId,
            'current_stage' => null,
            'deal_value' => 0,
            'deal_currency' => 'USD',
            'time_in_stage_days' => null,
            'communications' => $communications,
            'intent_counts' => $this->aggregateIntentCounts($communications),
            'sentiment_summary' => $this->aggregateSentiment($communications),
            'proposal_sent' => $proposalEvidence,
            'proposal_sent_at' => $proposalEvidence ? $this->getProposalSentAt($contactId, $lookbackDays) : null,
            'quote_created' => !empty($invoiceEvidence['quote_created']),
            'quote_sent' => !empty($invoiceEvidence['quote_sent']),
            'invoice_sent' => !empty($invoiceEvidence['invoice_sent']),
            'invoice_accepted' => !empty($invoiceEvidence['invoice_accepted']),
            'latest_document_type' => $commercialState['latest_document_type'],
            'latest_document_status' => $commercialState['latest_document_status'],
            'latest_document_sent_at' => $commercialState['latest_document_sent_at'],
            'latest_revision_number' => $commercialState['latest_revision_number'],
            'pending_approval_count' => $commercialState['pending_approval_count'],
            'delivery_failure_count' => $commercialState['delivery_failure_count'],
            'has_missing_pricing' => $commercialState['has_missing_pricing'],
            'has_missing_recipient' => $commercialState['has_missing_recipient'],
            'quote_viewed' => $commercialState['quote_viewed'],
            'quote_accepted' => $commercialState['quote_accepted'],
            'invoice_overdue' => $commercialState['invoice_overdue'],
            'lead_score' => (int) ($contactContext['lead_score'] ?? 0),
            'lead_score_band' => $this->getLeadScoreBand((int) ($contactContext['lead_score'] ?? 0)),
            'bidirectional_exchange' => $this->hasBidirectionalExchange($communications),
            'last_activity_at' => $this->getLastActivityAt($contactId),
            'lookback_days' => $lookbackDays,
            'built_at' => date('c'),
        ];
    }

    /**
     * Get recent communications with metadata (intent, sentiment, direction).
     */
    private function getRecentCommunications(int $contactId, int $lookbackDays): array
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$lookbackDays} days"));
        $comms = Database::query(
            "SELECT id, channel, direction, subject, body, metadata, created_at
             FROM communications
             WHERE contact_id = ? AND created_at >= ?
             ORDER BY created_at DESC
             LIMIT 100",
            [$contactId, $since]
        );

        $normalized = [];
        foreach ($comms as $c) {
            $meta = $c['metadata'] ?? null;
            if (is_string($meta)) {
                $meta = json_decode($meta, true) ?: [];
            }
            $normalized[] = [
                'id' => (int) $c['id'],
                'channel' => $c['channel'] ?? 'email',
                'direction' => $c['direction'] ?? 'inbound',
                'subject' => $c['subject'] ?? null,
                'body_preview' => mb_substr(strip_tags($c['body'] ?? ''), 0, 500),
                'intent' => $meta['intent']['intent'] ?? null,
                'intent_confidence' => (float) ($meta['intent']['confidence'] ?? 0),
                'sentiment' => $meta['sentiment']['sentiment'] ?? null,
                'sentiment_score' => (float) ($meta['sentiment']['score'] ?? 0),
                'metadata_purpose' => $meta['purpose'] ?? null,
                'created_at' => $c['created_at'] ?? null,
            ];
        }
        return $normalized;
    }

    /**
     * Detect if a proposal/quote was sent to this contact within lookback window.
     */
    private function detectProposalSent(int $contactId, int $lookbackDays, array $communications): bool
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$lookbackDays} days"));

        // 1. Communications metadata with purpose=proposal
        foreach ($communications as $c) {
            $purpose = $c['metadata_purpose'] ?? null;
            if ($purpose && in_array(strtolower((string) $purpose), ['proposal', 'quote'])) {
                return true;
            }
        }

        // 2. Outbound communications with proposal-related subject/body
        $proposalKeywords = ['proposal', 'quote', 'quotation', 'estimate', 'pricing'];
        foreach ($communications as $c) {
            if (($c['direction'] ?? '') !== 'outbound') {
                continue;
            }
            $text = strtolower(($c['subject'] ?? '') . ' ' . ($c['body_preview'] ?? ''));
            foreach ($proposalKeywords as $kw) {
                if (strpos($text, $kw) !== false) {
                    return true;
                }
            }
        }

        // 3. Activities with proposal_sent or description containing proposal
        $activities = Database::query(
            "SELECT activity_type, description, metadata, created_at
             FROM activities
             WHERE contact_id = ? AND created_at >= ?
             ORDER BY created_at DESC
             LIMIT 50",
            [$contactId, $since]
        );
        foreach ($activities as $a) {
            $type = strtolower($a['activity_type'] ?? '');
            $desc = strtolower($a['description'] ?? '');
            if ($type === 'proposal_sent' || $type === 'email_sent') {
                if (strpos($desc, 'proposal') !== false || strpos($desc, 'quote') !== false) {
                    return true;
                }
            }
            $meta = $a['metadata'] ?? null;
            if ($meta) {
                $decoded = is_string($meta) ? json_decode($meta, true) : $meta;
                if (!empty($decoded['purpose']) && in_array(strtolower($decoded['purpose']), ['proposal', 'quote'])) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getProposalSentAt(int $contactId, int $lookbackDays): ?string
    {
        $since = date('Y-m-d H:i:s', strtotime("-{$lookbackDays} days"));
        $row = Database::queryOne(
            "SELECT created_at FROM communications
             WHERE contact_id = ? AND direction = 'outbound' AND created_at >= ?
             AND (subject LIKE '%proposal%' OR subject LIKE '%quote%' OR body LIKE '%proposal%' OR body LIKE '%quote%')
             ORDER BY created_at DESC LIMIT 1",
            [$contactId, $since]
        );
        return $row['created_at'] ?? null;
    }

    private function getInvoiceEvidence(?int $dealId, ?int $contactId, int $lookbackDays): array
    {
        $where = [];
        $params = [];
        if ($dealId) {
            $where[] = 'deal_id = ?';
            $params[] = $dealId;
        }
        if ($contactId) {
            $where[] = 'contact_id = ?';
            $params[] = $contactId;
        }
        if (empty($where)) {
            return ['quote_created' => false, 'quote_sent' => false, 'invoice_sent' => false, 'invoice_accepted' => false];
        }
        $params[] = date('Y-m-d H:i:s', strtotime("-{$lookbackDays} days"));
        $rows = Database::query(
            "SELECT document_type, status, created_at
             FROM invoices
             WHERE (" . implode(' OR ', $where) . ")
               AND created_at >= ?",
            $params
        );

        $out = ['quote_created' => false, 'quote_sent' => false, 'invoice_sent' => false, 'invoice_accepted' => false];
        foreach ($rows as $row) {
            $type = (string) ($row['document_type'] ?? '');
            $status = (string) ($row['status'] ?? '');
            if (in_array($type, ['quote', 'proforma'], true)) {
                $out['quote_created'] = true;
                if (in_array($status, ['sent', 'viewed', 'accepted', 'finalized', 'paid', 'partially_paid'], true)) {
                    $out['quote_sent'] = true;
                }
                if ($status === 'accepted') {
                    $out['invoice_accepted'] = true;
                }
            }
            if ($type === 'invoice' && in_array($status, ['sent', 'viewed', 'accepted', 'finalized', 'paid', 'partially_paid'], true)) {
                $out['invoice_sent'] = true;
                if ($status === 'accepted') {
                    $out['invoice_accepted'] = true;
                }
            }
        }
        return $out;
    }

    private function getCommercialState(?int $dealId, ?int $contactId): array
    {
        $where = [];
        $params = [];
        if ($dealId) {
            $where[] = 'deal_id = ?';
            $params[] = $dealId;
        }
        if ($contactId) {
            $where[] = 'contact_id = ?';
            $params[] = $contactId;
        }
        if (!$where) {
            return [
                'latest_document_type' => null,
                'latest_document_status' => null,
                'latest_document_sent_at' => null,
                'latest_revision_number' => null,
                'pending_approval_count' => 0,
                'delivery_failure_count' => 0,
                'has_missing_pricing' => false,
                'has_missing_recipient' => false,
                'quote_viewed' => false,
                'quote_accepted' => false,
                'invoice_overdue' => false,
            ];
        }

        $invoice = Database::queryOne(
            "SELECT * FROM invoices WHERE (" . implode(' OR ', $where) . ") ORDER BY revision_number DESC, id DESC LIMIT 1",
            $params
        );

        $state = [
            'latest_document_type' => $invoice['document_type'] ?? null,
            'latest_document_status' => $invoice['status'] ?? null,
            'latest_document_sent_at' => $invoice['last_sent_at'] ?? null,
            'latest_revision_number' => isset($invoice['revision_number']) ? (int) $invoice['revision_number'] : null,
            'pending_approval_count' => 0,
            'delivery_failure_count' => 0,
            'has_missing_pricing' => false,
            'has_missing_recipient' => false,
            'quote_viewed' => ($invoice['document_type'] ?? null) === 'quote' && ($invoice['status'] ?? null) === 'viewed',
            'quote_accepted' => ($invoice['document_type'] ?? null) === 'quote' && ($invoice['status'] ?? null) === 'accepted',
            'invoice_overdue' => ($invoice['document_type'] ?? null) === 'invoice' && ($invoice['status'] ?? null) === 'overdue',
        ];

        if ($invoice) {
            $state['pending_approval_count'] = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c FROM commercial_automation_approvals WHERE status = 'pending' AND invoice_id = ?",
                [(int) $invoice['id']]
            )['c'] ?? 0);
            $state['delivery_failure_count'] = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c FROM invoice_delivery_log WHERE invoice_id = ? AND delivery_status = 'failed'",
                [(int) $invoice['id']]
            )['c'] ?? 0);

            $lineItems = Database::query("SELECT unit_price FROM invoice_line_items WHERE invoice_id = ?", [(int) $invoice['id']]);
            $state['has_missing_pricing'] = empty($lineItems);
            foreach ($lineItems as $lineItem) {
                if ((float) ($lineItem['unit_price'] ?? 0) <= 0) {
                    $state['has_missing_pricing'] = true;
                    break;
                }
            }
            $state['has_missing_recipient'] = trim((string) ($invoice['billing_email'] ?? '')) === '' && trim((string) ($invoice['billing_phone'] ?? '')) === '';
        }

        return $state;
    }

    private function getContactContext(int $contactId): ?array
    {
        $row = Database::queryOne(
            "SELECT id, lead_score, stage FROM contacts WHERE id = ?",
            [$contactId]
        );
        return $row;
    }

    private function getDealContext(array $deal): array
    {
        $stage = $deal['stage'] ?? 'prospecting';
        $updatedAt = $deal['updated_at'] ?? $deal['created_at'] ?? null;
        $timeInStage = 0;
        if ($updatedAt) {
            $ts = strtotime($updatedAt);
            $timeInStage = $ts ? max(0, (int) ((time() - $ts) / 86400)) : 0;
        }
        return [
            'time_in_stage_days' => $timeInStage,
        ];
    }

    private function getLeadScoreBand(int $score): string
    {
        if ($score >= 70) {
            return 'hot';
        }
        if ($score >= 40) {
            return 'warm';
        }
        return 'cold';
    }

    private function aggregateIntentCounts(array $communications): array
    {
        $counts = [];
        foreach ($communications as $c) {
            $intent = $c['intent'] ?? 'unknown';
            if ($intent) {
                $counts[$intent] = ($counts[$intent] ?? 0) + 1;
            }
        }
        return $counts;
    }

    private function aggregateSentiment(array $communications): array
    {
        $positive = 0;
        $negative = 0;
        $neutral = 0;
        $scores = [];
        foreach ($communications as $c) {
            $sent = strtolower($c['sentiment'] ?? 'neutral');
            $score = (float) ($c['sentiment_score'] ?? 0);
            if ($sent === 'positive' || $score > 0.2) {
                $positive++;
            } elseif ($sent === 'negative' || $score < -0.2) {
                $negative++;
            } else {
                $neutral++;
            }
            $scores[] = $score;
        }
        $avgScore = !empty($scores) ? array_sum($scores) / count($scores) : 0;
        return [
            'positive_count' => $positive,
            'negative_count' => $negative,
            'neutral_count' => $neutral,
            'avg_sentiment_score' => round($avgScore, 4),
            'has_negative_trend' => $negative > $positive && count($communications) >= 2,
        ];
    }

    private function hasBidirectionalExchange(array $communications): bool
    {
        $inbound = 0;
        $outbound = 0;
        foreach ($communications as $c) {
            if (($c['direction'] ?? '') === 'inbound') {
                $inbound++;
            } elseif (($c['direction'] ?? '') === 'outbound') {
                $outbound++;
            }
        }
        return $inbound >= 1 && $outbound >= 1;
    }

    private function getLastActivityAt(int $contactId): ?string
    {
        $comm = Database::queryOne("SELECT MAX(created_at) as last_at FROM communications WHERE contact_id = ?", [$contactId]);
        $act = Database::queryOne("SELECT MAX(created_at) as last_at FROM activities WHERE contact_id = ?", [$contactId]);
        $c = $comm['last_at'] ?? null;
        $a = $act['last_at'] ?? null;
        if (!$c && !$a) {
            return null;
        }
        if (!$c) {
            return $a;
        }
        if (!$a) {
            return $c;
        }
        return strtotime($c) >= strtotime($a) ? $c : $a;
    }

    private function buildDealOnlyPayload(array $deal, int $lookbackDays): array
    {
        $dealContext = $this->getDealContext($deal);
        return [
            'deal_id' => (int) $deal['id'],
            'contact_id' => null,
            'current_stage' => $deal['stage'] ?? 'prospecting',
            'deal_value' => (float) ($deal['value'] ?? 0),
            'deal_currency' => $deal['currency'] ?? 'USD',
            'time_in_stage_days' => $dealContext['time_in_stage_days'],
            'communications' => [],
            'intent_counts' => [],
            'sentiment_summary' => ['positive_count' => 0, 'negative_count' => 0, 'neutral_count' => 0, 'avg_sentiment_score' => 0, 'has_negative_trend' => false],
            'proposal_sent' => false,
            'proposal_sent_at' => null,
            'latest_document_type' => null,
            'latest_document_status' => null,
            'latest_document_sent_at' => null,
            'latest_revision_number' => null,
            'pending_approval_count' => 0,
            'delivery_failure_count' => 0,
            'has_missing_pricing' => false,
            'has_missing_recipient' => false,
            'quote_viewed' => false,
            'quote_accepted' => false,
            'invoice_overdue' => false,
            'lead_score' => 0,
            'lead_score_band' => 'cold',
            'bidirectional_exchange' => false,
            'last_activity_at' => null,
            'lookback_days' => $lookbackDays,
            'built_at' => date('c'),
        ];
    }

    private function emptyPayload(): array
    {
        return [
            'deal_id' => null,
            'contact_id' => null,
            'current_stage' => null,
            'deal_value' => 0,
            'deal_currency' => 'USD',
            'time_in_stage_days' => null,
            'communications' => [],
            'intent_counts' => [],
            'sentiment_summary' => ['positive_count' => 0, 'negative_count' => 0, 'neutral_count' => 0, 'avg_sentiment_score' => 0, 'has_negative_trend' => false],
            'proposal_sent' => false,
            'proposal_sent_at' => null,
            'latest_document_type' => null,
            'latest_document_status' => null,
            'latest_document_sent_at' => null,
            'latest_revision_number' => null,
            'pending_approval_count' => 0,
            'delivery_failure_count' => 0,
            'has_missing_pricing' => false,
            'has_missing_recipient' => false,
            'quote_viewed' => false,
            'quote_accepted' => false,
            'invoice_overdue' => false,
            'lead_score' => 0,
            'lead_score_band' => 'cold',
            'bidirectional_exchange' => false,
            'last_activity_at' => null,
            'lookback_days' => 14,
            'built_at' => date('c'),
        ];
    }
}
