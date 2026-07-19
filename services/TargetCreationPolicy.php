<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Targets;

class TargetCreationPolicy
{
    public function createAutomated(array $data, array $source): int
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required to create an automated target.');
        }
        $surface = strtolower(trim((string) ($source['surface'] ?? 'automation')));
        $runId = trim((string) ($source['run_id'] ?? ''));
        $dedupe = trim((string) ($source['dedupe_key'] ?? ''));
        if ($dedupe === '') {
            throw new \InvalidArgumentException('Automated targets require a stable dedupe key.');
        }
        return (new Targets())->create($data + [
            'origin_type' => (string) ($source['origin_type'] ?? 'automation'),
            'automation_mode' => (string) ($source['automation_mode'] ?? 'review'),
            'automation_dedupe_key' => $dedupe,
            'source_surface' => $surface,
            'source_run_id' => $runId ?: null,
            'source_skill_key' => $source['skill_key'] ?? null,
            'source_plugin_key' => $source['plugin_key'] ?? null,
            'source_capability_key' => $source['capability_key'] ?? null,
        ]);
    }

    public function proposeOrCreateFromAi(array $data, array $source, int $actorUserId): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required.');
        }
        $settings = (new WorkspaceTargetAutomationSettingsService())->get($workspaceId);
        $risk = (string) ($source['risk'] ?? 'medium');
        $confidence = max(0, min(1, (float) ($source['confidence'] ?? 0)));
        $dedupe = trim((string) ($source['dedupe_key'] ?? ''));
        if ($dedupe === '') {
            throw new \InvalidArgumentException('AI target proposals require a stable dedupe key.');
        }
        $existing = Database::queryOne('SELECT id FROM targets WHERE workspace_id=? AND automation_dedupe_key=?', [$workspaceId, $dedupe]);
        if ($existing) {
            return ['target_id' => (int) $existing['id'], 'proposal_id' => null, 'deduped' => true];
        }
        $canCreate = $settings['mode'] === 'full_auto' && !empty($settings['allow_ai_creation'])
            && $risk === 'low' && $confidence >= (float) $settings['confidence_threshold'] && !empty($source['user_opt_in']);
        if ($canCreate) {
            $targetId = $this->createAutomated($data, $source + [
                'origin_type' => 'ai', 'automation_mode' => 'auto', 'dedupe_key' => $dedupe,
            ]);
            return ['target_id' => $targetId, 'proposal_id' => null, 'deduped' => false];
        }
        $existingProposal = Database::queryOne(
            "SELECT id FROM target_automation_proposals WHERE workspace_id=? AND proposal_type='create' AND source_run_id=? AND decision_state='pending' LIMIT 1",
            [$workspaceId, (string) ($source['run_id'] ?? $dedupe)]
        );
        if ($existingProposal) {
            return ['target_id' => null, 'proposal_id' => (int) $existingProposal['id'], 'deduped' => true];
        }
        Database::execute(
            "INSERT INTO target_automation_proposals
                (workspace_id,target_id,proposal_type,proposed_changes_json,confidence_score,risk_level,explanation,
                 evidence_fingerprints_json,missing_evidence_json,conflicts_json,provider_key,source_run_id)
             VALUES (?,NULL,'create',?,?,?,?,?,?,?,?,?)",
            [$workspaceId, json_encode($data + ['automation_dedupe_key' => $dedupe], JSON_UNESCAPED_SLASHES), $confidence, $risk,
             (string) ($source['explanation'] ?? 'Clarity recommends this target.'),
             json_encode((array) ($source['evidence_fingerprints'] ?? []), JSON_UNESCAPED_SLASHES),
             json_encode((array) ($source['missing_evidence'] ?? []), JSON_UNESCAPED_SLASHES),
             json_encode((array) ($source['conflicts'] ?? []), JSON_UNESCAPED_SLASHES),
             (string) ($source['provider'] ?? 'clarity'), (string) ($source['run_id'] ?? $dedupe)]
        );
        return ['target_id' => null, 'proposal_id' => (int) Database::lastInsertId(), 'deduped' => false];
    }
}
