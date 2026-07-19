<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceEmailTemplateSeederService
{
    public const CATEGORY = 'workspace_starter';
    public const TEMPLATE_VERSION = '2.0';

    /**
     * @param array<string,mixed> $context
     * @return array{created:int,existing:int,total:int,template_ids:array<int,int>}
     */
    public function seed(int $workspaceId, int $ownerUserId, array $context = []): array
    {
        if ($workspaceId <= DefaultWorkspaceService::DEFAULT_ID) {
            throw new \InvalidArgumentException('A normal workspace is required for starter email templates.');
        }

        $workspace = Database::queryOne('SELECT id, created_by FROM workspaces WHERE id = ? LIMIT 1', [$workspaceId]);
        if ($workspace === null) {
            throw new \RuntimeException('Workspace not found for email template seeding.');
        }

        $creatorUserId = $this->resolveCreatorUserId($ownerUserId, (int) ($workspace['created_by'] ?? 0));
        $created = 0;
        $existingCount = 0;
        $templateIds = [];

        foreach ($this->definitions($context) as $definition) {
            $slug = $this->slug((string) $definition['key'], $workspaceId);
            $existing = Database::queryOne(
                'SELECT id FROM email_templates WHERE workspace_id = ? AND slug = ? LIMIT 1',
                [$workspaceId, $slug]
            );
            if ($existing !== null) {
                $existingCount++;
                $templateIds[] = (int) ($existing['id'] ?? 0);
                continue;
            }

            $variables = array_values(array_map('strval', (array) ($definition['variables'] ?? [])));
            $templateKey = 'workspace_starter_' . (string) $definition['key'];
            $metadata = [
                'seed_source' => 'workspace_premium_email_starters',
                'seed_version' => self::TEMPLATE_VERSION,
                'system_managed' => true,
            ];
            Database::execute(
                "INSERT INTO email_templates
                    (workspace_id, name, slug, subject, body_html, body_text, category, variables,
                     is_active, created_by, is_library, description, tags, industry, purpose,
                     is_featured, author, version, is_ai_generated, template_key,
                     match_metadata_json, seed_metadata_json)
                 VALUES
                    (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, 0, ?, ?, 'General', ?, 0, 'webXpanse', ?, 1, ?, ?, ?)",
                [
                    $workspaceId,
                    $definition['name'],
                    $slug,
                    $definition['subject'],
                    $definition['body_html'],
                    $definition['body_text'],
                    self::CATEGORY,
                    json_encode($variables, JSON_UNESCAPED_SLASHES),
                    $creatorUserId,
                    $definition['description'],
                    json_encode(['workspace_starter', 'premium', (string) $definition['key']], JSON_UNESCAPED_SLASHES),
                    $definition['purpose'],
                    self::TEMPLATE_VERSION,
                    $templateKey,
                    json_encode([
                        'purpose' => $definition['purpose'],
                        'required_variables' => $variables,
                        'audience' => 'customer',
                        'tone' => (string) ($definition['tone'] ?? 'warm'),
                    ], JSON_UNESCAPED_SLASHES),
                    json_encode($metadata + ['template_key' => $templateKey], JSON_UNESCAPED_SLASHES),
                ]
            );
            $created++;
            $templateIds[] = (int) Database::lastInsertId();
        }

        return [
            'created' => $created,
            'existing' => $existingCount,
            'total' => count($templateIds),
            'template_ids' => array_values(array_filter($templateIds, static fn(int $id): bool => $id > 0)),
        ];
    }

    public function slug(string $key, int $workspaceId): string
    {
        return 'workspace-starter-' . str_replace('_', '-', $key) . '-' . $workspaceId;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    public function definitions(array $context = []): array
    {
        return [
            [
                'key' => 'first_helpful_note',
                'name' => 'First Helpful Note',
                'subject' => '{first_name}, a useful next step',
                'description' => 'A thoughtful first-touch note that leads with usefulness rather than pressure.',
                'purpose' => 'first_touch',
                'tone' => 'warm',
                'variables' => ['first_name'],
                'body_text' => "Hi {first_name},\n\nI took another look at what we discussed and found one practical next step that could create momentum without adding complexity.\n\nWould a concise two-minute outline be useful?",
                'body_html' => '<p>Hi {first_name},</p><p>I took another look at what we discussed and found one practical next step that could create momentum without adding complexity.</p><p><strong>Would a concise two-minute outline be useful?</strong></p>',
            ],
            [
                'key' => 'warm_follow_up',
                'name' => 'Warm Follow-up',
                'subject' => 'Still useful, {first_name}?',
                'description' => 'A calm follow-up that makes replying easy and keeps the conversation human.',
                'purpose' => 'follow_up',
                'tone' => 'conversational',
                'variables' => ['first_name'],
                'body_text' => "Hi {first_name},\n\nJust checking in while our conversation is still fresh. If the timing is not right, no pressure. If it is, I can turn the next step into something simple and concrete.\n\nShould I send that over?",
                'body_html' => '<p>Hi {first_name},</p><p>Just checking in while our conversation is still fresh. If the timing is not right, no pressure. If it is, I can turn the next step into something simple and concrete.</p><p><strong>Should I send that over?</strong></p>',
            ],
            [
                'key' => 'proposal_next_step',
                'name' => 'Proposal Next Step',
                'subject' => 'A clear way forward, {first_name}',
                'description' => 'A premium proposal follow-up focused on clarity, confidence, and one decision.',
                'purpose' => 'proposal_follow_up',
                'tone' => 'confident',
                'variables' => ['first_name'],
                'body_text' => "Hi {first_name},\n\nI wanted to make the decision easier: the proposal is designed around the outcome we discussed, with a practical first milestone and no unnecessary moving parts.\n\nWhat would you need to feel comfortable with the next step?",
                'body_html' => '<p>Hi {first_name},</p><p>I wanted to make the decision easier: the proposal is designed around the outcome we discussed, with a practical first milestone and no unnecessary moving parts.</p><p><strong>What would you need to feel comfortable with the next step?</strong></p>',
            ],
            [
                'key' => 'meeting_recap',
                'name' => 'Meeting Recap',
                'subject' => 'Your recap and next step, {first_name}',
                'description' => 'A crisp post-meeting recap that turns a good conversation into forward motion.',
                'purpose' => 'meeting_follow_up',
                'tone' => 'clear',
                'variables' => ['first_name'],
                'body_text' => "Hi {first_name},\n\nThank you for the conversation. The clearest priority is to protect momentum while keeping the next step easy to evaluate.\n\nI will keep the follow-through focused, practical, and visible. Does that match your takeaway?",
                'body_html' => '<p>Hi {first_name},</p><p>Thank you for the conversation. The clearest priority is to protect momentum while keeping the next step easy to evaluate.</p><p>I will keep the follow-through <strong>focused, practical, and visible</strong>. Does that match your takeaway?</p>',
            ],
            [
                'key' => 'thoughtful_thank_you',
                'name' => 'Thoughtful Thank You',
                'subject' => 'Thank you, {first_name}',
                'description' => 'A polished thank-you note that feels personal without becoming over-written.',
                'purpose' => 'relationship',
                'tone' => 'appreciative',
                'variables' => ['first_name'],
                'body_text' => "Hi {first_name},\n\nThank you for your time and trust. I appreciate the clarity you brought to the conversation, and I am looking forward to turning that into useful progress.\n\nI will take good care of the next step.",
                'body_html' => '<p>Hi {first_name},</p><p>Thank you for your time and trust. I appreciate the clarity you brought to the conversation, and I am looking forward to turning that into useful progress.</p><p><strong>I will take good care of the next step.</strong></p>',
            ],
        ];
    }

    private function resolveCreatorUserId(int $ownerUserId, int $workspaceCreatorUserId): ?int
    {
        foreach ([$ownerUserId, $workspaceCreatorUserId] as $candidate) {
            if ($candidate > 0 && Database::queryOne('SELECT id FROM users WHERE id = ? LIMIT 1', [$candidate]) !== null) {
                return $candidate;
            }
        }
        $fallback = Database::queryOne('SELECT MIN(id) AS id FROM users');
        $id = (int) ($fallback['id'] ?? 0);
        return $id > 0 ? $id : null;
    }
}
