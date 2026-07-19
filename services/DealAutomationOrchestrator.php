<?php
/**
 * Deal Automation Orchestrator
 *
 * Coordinates evidence building, AI suggestion, rules engine, and stage updates.
 * Entrypoints: runForCommunication, runForDeal, runForProposalSent, runInactivitySweep.
 * Includes loop guards, idempotency, audit logging, and recursion prevention.
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\DealAutomationConfig;
use CRM\Modules\Deals;

class DealAutomationOrchestrator
{
    private const SOURCE_TAG = 'automation_ai';
    private const DEDUPE_WINDOW_SECONDS = 300; // 5 min

    private DealAutomationConfig $config;
    private DealAutomationEvidenceBuilder $evidenceBuilder;
    private DealAutomationAIStageSuggestion $aiSuggester;
    private DealAutomationRulesEngine $rulesEngine;
    private CommercialAutomationOrchestrator $commercialAutomation;
    private Deals $deals;

    /** @var bool Recursion guard - prevent deal.stage_changed from re-triggering automation */
    private static bool $inAutomationUpdate = false;

    public function __construct()
    {
        $this->config = new DealAutomationConfig();
        $this->evidenceBuilder = new DealAutomationEvidenceBuilder();
        $this->aiSuggester = new DealAutomationAIStageSuggestion();
        $this->rulesEngine = new DealAutomationRulesEngine();
        $this->commercialAutomation = new CommercialAutomationOrchestrator();
        $this->deals = new Deals();
    }

    /**
     * Run automation when a communication was ingested (email/whatsapp/sms).
     */
    public function runForCommunication(int $contactId, int $communicationId, string $triggerType = 'communication'): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        // Optional: auto-create a prospecting deal from qualified inbound responses.
        if ($this->maybeAutoCreateProspectingDeal($contactId, $communicationId)) {
            return;
        }

        $openDeals = $this->getOpenDealsForContact($contactId);
        foreach ($openDeals as $deal) {
            $this->runForDeal((int) $deal['id'], $triggerType, $communicationId);
        }
        $this->commercialAutomation->runForCommunication($contactId, $communicationId);
    }

    /**
     * Run automation for a specific deal.
     */
    public function runForDeal(int $dealId, string $triggerType = 'communication', ?int $triggerRefId = null): ?array
    {
        if (!$this->config->isEnabled()) {
            return null;
        }
        if (self::$inAutomationUpdate) {
            return null;
        }

        $cfg = $this->config->get();
        $lookbackDays = (int) ($cfg['lookback_days'] ?? 14);

        $evidence = $this->evidenceBuilder->buildForDeal($dealId, $lookbackDays);
        $suggestion = $this->aiSuggester->suggest($evidence);
        $toStage = $suggestion['suggested_stage'] ?? null;

        if (!$toStage || $toStage === ($evidence['current_stage'] ?? '')) {
            $this->auditLog($dealId, $evidence['contact_id'] ?? null, $triggerType, $triggerRefId, null, null, 'reject', null, 'No stage change suggested.', $evidence, $suggestion, []);
            return null;
        }

        $rulesResult = $this->rulesEngine->evaluate($evidence, $suggestion, $dealId);
        $decision = $rulesResult['decision'] ?? 'reject';

        $applied = false;
        if ($decision === 'auto_apply') {
            $applied = $this->applyStageUpdate($dealId, $toStage, $evidence, $suggestion, $triggerType, $triggerRefId);
        }

        $this->auditLog(
            $dealId,
            $evidence['contact_id'] ?? null,
            $triggerType,
            $triggerRefId,
            $evidence['current_stage'] ?? null,
            $toStage,
            $decision,
            $suggestion['confidence'] ?? 0,
            $rulesResult['reason'] ?? '',
            $evidence,
            $suggestion,
            $rulesResult['checklist_results'] ?? [],
            $applied
        );

        return [
            'decision' => $decision,
            'suggested_stage' => $toStage,
            'confidence' => $suggestion['confidence'] ?? 0,
            'applied' => $applied,
        ];
    }

    /**
     * Run when proposal/quote was sent - create deal if none open, set initial stage.
     */
    public function runForProposalSent(int $contactId, ?int $triggerRefId = null): ?array
    {
        if (!$this->config->isEnabled()) {
            return null;
        }

        $openDeals = $this->getOpenDealsForContact($contactId);
        if (!empty($openDeals)) {
            $deal = $openDeals[0];
            $result = $this->runForDeal((int) $deal['id'], 'proposal', $triggerRefId);
            $this->commercialAutomation->runForDeal((int) $deal['id'], 'proposal', $triggerRefId);
            return $result;
        }

        $cfg = $this->config->get();
        $lookbackDays = (int) ($cfg['lookback_days'] ?? 14);
        $evidence = $this->evidenceBuilder->buildForContact($contactId, $lookbackDays);
        $suggestion = $this->aiSuggester->suggest($evidence);

        if (($suggestion['suggested_stage'] ?? null) === 'proposal') {
            $contact = Database::queryOne("SELECT first_name, last_name, company_id FROM contacts WHERE id = ?", [$contactId]);
            $title = 'Deal - ' . trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? 'Contact'));
            $dealId = $this->deals->create([
                'title' => $title,
                'contact_id' => $contactId,
                'company_id' => $contact['company_id'] ?? null,
                'stage' => 'proposal',
                'value' => 0,
            ]);
            $this->auditLog($dealId, $contactId, 'proposal', $triggerRefId, null, 'proposal', 'auto_apply', $suggestion['confidence'] ?? 0, 'Created deal at proposal stage.', $evidence, $suggestion, [], true);
            $this->commercialAutomation->runForDeal($dealId, 'proposal', $triggerRefId);
            return ['decision' => 'auto_apply', 'suggested_stage' => 'proposal', 'deal_id' => $dealId, 'created' => true];
        }

        return null;
    }

    /**
     * Scheduled inactivity sweep - suggest closed_lost for inactive deals.
     */
    public function runInactivitySweep(): array
    {
        if (!$this->config->isEnabled()) {
            return ['processed' => 0, 'suggestions' => 0, 'applied' => 0];
        }

        $cfg = $this->config->get();
        $inactivityDays = (int) ($cfg['inactivity_days_for_loss'] ?? 14);
        $since = date('Y-m-d H:i:s', strtotime("-{$inactivityDays} days"));

        $deals = Database::query(
            "SELECT d.id, d.contact_id, d.stage
             FROM deals d
             WHERE d.stage IN ('prospecting','qualification','proposal','negotiation')
             AND NOT EXISTS (
                 SELECT 1 FROM deal_automation_audit a
                 WHERE a.deal_id = d.id AND a.trigger_type = 'inactivity'
                 AND a.created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             )",
            []
        );

        $processed = 0;
        $suggestions = 0;
        $applied = 0;

        foreach ($deals as $deal) {
            $dealId = (int) $deal['id'];
            $contactId = (int) $deal['contact_id'];
            $evidence = $this->evidenceBuilder->buildForDeal($dealId, $inactivityDays);
            $lastAt = $evidence['last_activity_at'] ?? null;
            if ($lastAt && strtotime($lastAt) < strtotime($since)) {
                $result = $this->runForDeal($dealId, 'inactivity', null);
                $processed++;
                if ($result && ($result['suggested_stage'] ?? '') === 'closed_lost') {
                    $suggestions++;
                    if (!empty($result['applied'])) {
                        $applied++;
                    }
                }
            }
        }

        return ['processed' => $processed, 'suggestions' => $suggestions, 'applied' => $applied];
    }

    /**
     * Apply stage update with loop guard and idempotency.
     */
    private function applyStageUpdate(int $dealId, string $toStage, array $evidence, array $suggestion, string $triggerType, ?int $triggerRefId): bool
    {
        $idempotencyKey = 'deal_' . $dealId . '_' . $toStage . '_' . ($triggerRefId ?? '') . '_' . (int) (time() / self::DEDUPE_WINDOW_SECONDS);
        $recent = Database::queryOne(
            "SELECT 1 FROM deal_automation_audit
             WHERE deal_id = ? AND to_stage = ? AND applied = 1
             AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)
             LIMIT 1",
            [$dealId, $toStage, self::DEDUPE_WINDOW_SECONDS]
        );
        if ($recent) {
            return false;
        }

        self::$inAutomationUpdate = true;
        try {
            $this->deals->update($dealId, ['stage' => $toStage]);
            return true;
        } catch (\Throwable $e) {
            error_log("DealAutomationOrchestrator: apply failed - " . $e->getMessage());
            return false;
        } finally {
            self::$inAutomationUpdate = false;
        }
    }

    private function getOpenDealsForContact(int $contactId): array
    {
        return Database::query(
            "SELECT id, stage FROM deals
             WHERE contact_id = ? AND stage IN ('prospecting','qualification','proposal','negotiation')
             ORDER BY updated_at DESC",
            [$contactId]
        );
    }

    private function maybeAutoCreateProspectingDeal(int $contactId, int $communicationId): bool
    {
        $cfg = $this->config->get();
        if (empty($cfg['auto_create_from_inbound'])) {
            return false;
        }

        if (!empty($this->getOpenDealsForContact($contactId))) {
            return false;
        }

        $communication = Database::queryOne(
            "SELECT id, channel, direction, subject, body, metadata, created_at
             FROM communications
             WHERE id = ? AND contact_id = ?
             LIMIT 1",
            [$communicationId, $contactId]
        );
        if (!$communication) {
            return false;
        }

        $direction = strtolower((string) ($communication['direction'] ?? ''));
        if ($direction !== 'inbound') {
            return false;
        }

        $channel = strtolower((string) ($communication['channel'] ?? ''));
        $allowedChannels = $cfg['auto_create_channels'] ?? ['email' => true, 'whatsapp' => true, 'sms' => false];
        if (empty($allowedChannels[$channel])) {
            return false;
        }

        $body = trim((string) ($communication['body'] ?? ''));
        $subject = trim((string) ($communication['subject'] ?? ''));
        $bodyLen = mb_strlen($body !== '' ? $body : $subject);
        $minChars = max(1, (int) ($cfg['auto_create_min_message_chars'] ?? 20));
        if ($bodyLen < $minChars) {
            return false;
        }

        if ($this->looksLikeAutoReply($channel, '', $subject, $body)) {
            return false;
        }

        $meta = $communication['metadata'] ?? null;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : [];
        } elseif (!is_array($meta)) {
            $meta = [];
        }

        if (!empty($cfg['auto_create_require_non_negative']) && $this->isNegativeCommunication($meta, $subject . ' ' . $body)) {
            return false;
        }

        if (!empty($cfg['auto_create_require_meaningful_reply']) && !$this->isMeaningfulReply($meta, $subject . ' ' . $body)) {
            return false;
        }

        $dedupeHours = max(1, min(168, (int) ($cfg['auto_create_dedupe_hours'] ?? 24)));
        $recentAuto = Database::queryOne(
            "SELECT id
             FROM deals
             WHERE contact_id = ?
               AND lead_source = 'ai_auto_create'
               AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY created_at DESC
             LIMIT 1",
            [$contactId, $dedupeHours]
        );
        if ($recentAuto) {
            return false;
        }

        $contact = Database::queryOne(
            "SELECT first_name, last_name, company_id, assigned_to, created_by
             FROM contacts
             WHERE id = ?
             LIMIT 1",
            [$contactId]
        );
        if (!$contact) {
            return false;
        }

        $ownerId = (int) ($contact['assigned_to'] ?? 0);
        if ($ownerId <= 0) {
            $ownerId = (int) ($contact['created_by'] ?? 0);
        }
        if ($ownerId <= 0) {
            $ownerId = $this->resolveFallbackUserId();
        }
        if ($ownerId <= 0) {
            return false;
        }

        $contactName = trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? ''));
        if ($contactName === '') {
            $contactName = 'Contact #' . $contactId;
        }

        $dealId = $this->deals->create([
            'title' => 'Deal - ' . $contactName,
            'description' => 'Auto-created from qualified inbound response.',
            'contact_id' => $contactId,
            'company_id' => $contact['company_id'] ?? null,
            'assigned_to' => $ownerId,
            'created_by' => $ownerId,
            'stage' => 'prospecting',
            'value' => 0,
            'lead_source' => 'ai_auto_create',
        ]);

        $this->auditLog(
            $dealId,
            $contactId,
            'manual',
            $communicationId,
            null,
            'prospecting',
            'auto_apply',
            null,
            'Auto-created prospecting deal from inbound response guardrails.',
            ['communications' => [['id' => $communicationId]], 'proposal_sent' => false, 'lead_score' => 0],
            ['suggested_stage' => 'prospecting', 'confidence' => null],
            ['auto_create' => ['passed' => true]],
            true
        );

        return true;
    }

    private function resolveFallbackUserId(): int
    {
        $row = Database::queryOne(
            "SELECT id FROM users ORDER BY 
                CASE WHEN role = 'admin' THEN 0 ELSE 1 END,
                id ASC
             LIMIT 1"
        );
        return (int) ($row['id'] ?? 0);
    }

    private function looksLikeAutoReply(string $channel, string $fromEmail, string $subject, string $body): bool
    {
        if ($channel !== 'email') {
            return false;
        }

        $haystack = strtolower(trim($subject . ' ' . $body));
        $fromEmail = strtolower(trim($fromEmail));

        if ($fromEmail !== '' && (strpos($fromEmail, 'no-reply') !== false || strpos($fromEmail, 'noreply') !== false)) {
            return true;
        }

        $patterns = [
            'out of office',
            'automatic reply',
            'auto reply',
            'autoreply',
            'vacation',
            'away from office',
            'delivery status notification',
            'undeliverable',
            'mailer-daemon',
        ];
        foreach ($patterns as $p) {
            if (strpos($haystack, $p) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isNegativeCommunication(array $meta, string $text): bool
    {
        $sentiment = strtolower((string) ($meta['sentiment']['sentiment'] ?? ''));
        $score = (float) ($meta['sentiment']['score'] ?? 0);
        if ($sentiment === 'negative' || $score < -0.15) {
            return true;
        }

        $intent = strtolower((string) ($meta['intent']['intent'] ?? ''));
        if (in_array($intent, ['complaint', 'cancellation'], true)) {
            return true;
        }

        $textLower = strtolower($text);
        $negativeSignals = ['not interested', 'stop', 'unsubscribe', 'leave me alone', 'no thanks', 'not now', 'remove me'];
        foreach ($negativeSignals as $signal) {
            if (strpos($textLower, $signal) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isMeaningfulReply(array $meta, string $text): bool
    {
        $intent = strtolower((string) ($meta['intent']['intent'] ?? ''));
        if (in_array($intent, ['purchase', 'inquiry', 'information', 'booking'], true)) {
            return true;
        }

        $textLower = strtolower(trim($text));
        if (strpos($textLower, '?') !== false) {
            return true;
        }

        $keywords = ['price', 'cost', 'quote', 'demo', 'meeting', 'call', 'buy', 'purchase', 'interested', 'details'];
        foreach ($keywords as $kw) {
            if (strpos($textLower, $kw) !== false) {
                return true;
            }
        }

        return false;
    }

    private function auditLog(
        int $dealId,
        ?int $contactId,
        string $triggerType,
        ?int $triggerRefId,
        ?string $fromStage,
        ?string $toStage,
        string $decision,
        ?float $confidence,
        string $reason,
        array $evidence,
        array $suggestion,
        array $checklistResults,
        bool $applied = false
    ): void {
        $cfg = $this->config->get();
        $evidenceSummary = [
            'proposal_sent' => $evidence['proposal_sent'] ?? false,
            'lead_score' => $evidence['lead_score'] ?? 0,
            'comm_count' => count($evidence['communications'] ?? []),
        ];
        $params = [
            $dealId,
            $contactId,
            $triggerType,
            $triggerRefId,
            $fromStage,
            $toStage,
            $decision,
            $confidence,
            $applied ? 1 : 0,
            $reason,
            json_encode($evidenceSummary),
            json_encode($checklistResults),
            $cfg['schema_version'] ?? 1,
        ];
        $columns = 'deal_id, contact_id, trigger_type, trigger_ref_id, from_stage, to_stage, decision, confidence, applied, reason, evidence_summary, checklist_results, config_version';
        $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
        if (Database::columnExists('deal_automation_audit', 'workspace_id')) {
            $columns = 'workspace_id, ' . $columns;
            $placeholders = '?, ' . $placeholders;
            array_unshift($params, $this->resolveWorkspaceIdForAudit($dealId, $contactId));
        }

        Database::execute(
            "INSERT INTO deal_automation_audit ({$columns}) VALUES ({$placeholders})",
            $params
        );
    }

    private function resolveWorkspaceIdForAudit(int $dealId, ?int $contactId): int
    {
        $row = Database::queryOne(
            "SELECT COALESCE(d.workspace_id, c.workspace_id) AS workspace_id
             FROM deals d
             LEFT JOIN contacts c ON c.id = COALESCE(?, d.contact_id)
             WHERE d.id = ?
             LIMIT 1",
            [$contactId, $dealId]
        );

        $workspaceId = (int) ($row['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        return (int) (WorkspaceContext::currentWorkspaceId() ?? 1);
    }

    /**
     * Rollback last AI-applied stage change for a deal.
     */
    public function rollbackLastChange(int $dealId): bool
    {
        $last = Database::queryOne(
            "SELECT id, from_stage, to_stage FROM deal_automation_audit
             WHERE deal_id = ? AND applied = 1
             ORDER BY created_at DESC LIMIT 1",
            [$dealId]
        );
        if (!$last || !$last['from_stage']) {
            return false;
        }
        self::$inAutomationUpdate = true;
        try {
            $this->deals->update($dealId, ['stage' => $last['from_stage']]);
            Database::execute(
                "UPDATE deal_automation_audit SET applied = 0 WHERE id = ?",
                [$last['id']]
            );
            return true;
        } finally {
            self::$inAutomationUpdate = false;
        }
    }
}
