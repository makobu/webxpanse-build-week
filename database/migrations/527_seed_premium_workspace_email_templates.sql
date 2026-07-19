-- Seed five premium, system-managed starter email templates into every normal workspace.
-- Slugs include the workspace id because email_templates.slug is globally unique.
INSERT INTO email_templates (
    workspace_id,
    name,
    slug,
    subject,
    body_html,
    body_text,
    category,
    variables,
    is_active,
    created_by,
    is_library,
    description,
    tags,
    industry,
    purpose,
    is_featured,
    author,
    version,
    is_ai_generated,
    template_key,
    match_metadata_json,
    seed_metadata_json
)
SELECT
    w.id,
    seed.name,
    CONCAT('workspace-starter-', REPLACE(seed.key_suffix, '_', '-'), '-', w.id),
    seed.subject,
    seed.body_html,
    seed.body_text,
    'workspace_starter',
    seed.variables_json,
    1,
    COALESCE(
        (
            SELECT wm.user_id
            FROM workspace_memberships wm
            WHERE wm.workspace_id = w.id
              AND wm.membership_status = 'active'
            ORDER BY wm.is_owner DESC, FIELD(wm.role_slug, 'owner', 'admin', 'superadmin', 'accountant', 'expert', 'viewer'), wm.id ASC
            LIMIT 1
        ),
        w.created_by,
        (SELECT MIN(id) FROM users)
    ),
    0,
    seed.description,
    JSON_ARRAY('workspace_starter', 'premium', seed.key_suffix),
    'General',
    seed.purpose,
    0,
    'webXpanse',
    '2.0',
    1,
    CONCAT('workspace_starter_', seed.key_suffix),
    seed.match_metadata_json,
    JSON_OBJECT(
        'seed_source', 'workspace_premium_email_starters',
        'seed_version', '2.0',
        'system_managed', TRUE,
        'template_key', CONCAT('workspace_starter_', seed.key_suffix)
    )
FROM workspaces w
CROSS JOIN (
    SELECT
        'first_helpful_note' AS key_suffix,
        'First Helpful Note' AS name,
        '{first_name}, a useful next step' AS subject,
        '<p>Hi {first_name},</p><p>I took another look at what we discussed and found one practical next step that could create momentum without adding complexity.</p><p><strong>Would a concise two-minute outline be useful?</strong></p>' AS body_html,
        'Hi {first_name},\n\nI took another look at what we discussed and found one practical next step that could create momentum without adding complexity.\n\nWould a concise two-minute outline be useful?' AS body_text,
        'A thoughtful first-touch note that leads with usefulness rather than pressure.' AS description,
        'first_touch' AS purpose,
        JSON_ARRAY('first_name') AS variables_json,
        JSON_OBJECT('purpose', 'first_touch', 'required_variables', JSON_ARRAY('first_name'), 'audience', 'customer', 'tone', 'warm') AS match_metadata_json
    UNION ALL
    SELECT
        'warm_follow_up',
        'Warm Follow-up',
        'Still useful, {first_name}?',
        '<p>Hi {first_name},</p><p>Just checking in while our conversation is still fresh. If the timing is not right, no pressure. If it is, I can turn the next step into something simple and concrete.</p><p><strong>Should I send that over?</strong></p>',
        'Hi {first_name},\n\nJust checking in while our conversation is still fresh. If the timing is not right, no pressure. If it is, I can turn the next step into something simple and concrete.\n\nShould I send that over?',
        'A calm follow-up that makes replying easy and keeps the conversation human.',
        'follow_up',
        JSON_ARRAY('first_name'),
        JSON_OBJECT('purpose', 'follow_up', 'required_variables', JSON_ARRAY('first_name'), 'audience', 'customer', 'tone', 'conversational')
    UNION ALL
    SELECT
        'proposal_next_step',
        'Proposal Next Step',
        'A clear way forward, {first_name}',
        '<p>Hi {first_name},</p><p>I wanted to make the decision easier: the proposal is designed around the outcome we discussed, with a practical first milestone and no unnecessary moving parts.</p><p><strong>What would you need to feel comfortable with the next step?</strong></p>',
        'Hi {first_name},\n\nI wanted to make the decision easier: the proposal is designed around the outcome we discussed, with a practical first milestone and no unnecessary moving parts.\n\nWhat would you need to feel comfortable with the next step?',
        'A premium proposal follow-up focused on clarity, confidence, and one decision.',
        'proposal_follow_up',
        JSON_ARRAY('first_name'),
        JSON_OBJECT('purpose', 'proposal_follow_up', 'required_variables', JSON_ARRAY('first_name'), 'audience', 'customer', 'tone', 'confident')
    UNION ALL
    SELECT
        'meeting_recap',
        'Meeting Recap',
        'Your recap and next step, {first_name}',
        '<p>Hi {first_name},</p><p>Thank you for the conversation. The clearest priority is to protect momentum while keeping the next step easy to evaluate.</p><p>I will keep the follow-through <strong>focused, practical, and visible</strong>. Does that match your takeaway?</p>',
        'Hi {first_name},\n\nThank you for the conversation. The clearest priority is to protect momentum while keeping the next step easy to evaluate.\n\nI will keep the follow-through focused, practical, and visible. Does that match your takeaway?',
        'A crisp post-meeting recap that turns a good conversation into forward motion.',
        'meeting_follow_up',
        JSON_ARRAY('first_name'),
        JSON_OBJECT('purpose', 'meeting_follow_up', 'required_variables', JSON_ARRAY('first_name'), 'audience', 'customer', 'tone', 'clear')
    UNION ALL
    SELECT
        'thoughtful_thank_you',
        'Thoughtful Thank You',
        'Thank you, {first_name}',
        '<p>Hi {first_name},</p><p>Thank you for your time and trust. I appreciate the clarity you brought to the conversation, and I am looking forward to turning that into useful progress.</p><p><strong>I will take good care of the next step.</strong></p>',
        'Hi {first_name},\n\nThank you for your time and trust. I appreciate the clarity you brought to the conversation, and I am looking forward to turning that into useful progress.\n\nI will take good care of the next step.',
        'A polished thank-you note that feels personal without becoming over-written.',
        'relationship',
        JSON_ARRAY('first_name'),
        JSON_OBJECT('purpose', 'relationship', 'required_variables', JSON_ARRAY('first_name'), 'audience', 'customer', 'tone', 'appreciative')
) seed
WHERE w.id <> 1
  AND NOT EXISTS (
      SELECT 1
      FROM email_templates existing
      WHERE existing.workspace_id = w.id
        AND existing.slug = CONCAT('workspace-starter-', REPLACE(seed.key_suffix, '_', '-'), '-', w.id)
  );
