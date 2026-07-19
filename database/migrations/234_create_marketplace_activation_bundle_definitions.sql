CREATE TABLE IF NOT EXISTS workspace_marketplace_activation_bundle_definitions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    bundle_key VARCHAR(80) NOT NULL,
    label VARCHAR(160) NOT NULL,
    summary TEXT NOT NULL,
    included_skill_keys_json JSON NOT NULL,
    why_this_bundle TEXT NULL,
    expected_outcome TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at DATETIME NULL,
    display_order INT NOT NULL DEFAULT 100,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marketplace_activation_bundle_definitions_key (bundle_key),
    KEY idx_marketplace_activation_bundle_definitions_active (is_active, archived_at),
    KEY idx_marketplace_activation_bundle_definitions_order (display_order, label),
    KEY idx_marketplace_activation_bundle_definitions_created_by (created_by_user_id),
    KEY idx_marketplace_activation_bundle_definitions_updated_by (updated_by_user_id),
    CONSTRAINT fk_marketplace_activation_bundle_definitions_created_by
        FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_marketplace_activation_bundle_definitions_updated_by
        FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_marketplace_activation_bundle_definitions (
    bundle_key,
    label,
    summary,
    included_skill_keys_json,
    why_this_bundle,
    expected_outcome,
    is_active,
    display_order
) VALUES
(
    'strategy_foundation',
    'Strategy foundation',
    'Clarify the business model and marketing context before scaling execution.',
    JSON_ARRAY('lean_canvas', 'professional_marketer'),
    'This gives Clarity and Coach a cleaner strategic frame for recommendations.',
    'Sharper customer, offer, positioning, and campaign guidance.',
    1,
    10
),
(
    'email_led_growth',
    'Email-led growth',
    'Pair strategy context with Email Assistant setup for email-heavy businesses.',
    JSON_ARRAY('lean_canvas', 'professional_marketer', 'email_assistant'),
    'Email is strongest when replies, digests, and positioning share the same context.',
    'More useful customer replies, inbound instructions, and email growth workflows.',
    1,
    20
),
(
    'whatsapp_led_growth',
    'WhatsApp-led growth',
    'Combine strategy context with WhatsApp Assistant setup for WhatsApp-first businesses.',
    JSON_ARRAY('lean_canvas', 'professional_marketer', 'whatsapp_assistant'),
    'WhatsApp-led teams need assistant context close to the channel they actually use.',
    'Better WhatsApp instructions, digests, session continuity, and campaign suggestions.',
    1,
    30
),
(
    'omnichannel_assistant',
    'Omnichannel assistant',
    'Coordinate Email and WhatsApp assistants around the same growth motion.',
    JSON_ARRAY('email_assistant', 'whatsapp_assistant', 'professional_marketer'),
    'When customers use more than one channel, assistant setup should stay coordinated.',
    'Cleaner cross-channel follow-up, digests, and assistant recommendations.',
    1,
    40
),
(
    'communication_ai_operator',
    'Communication + AI Operator',
    'Activate the main optional channels, meeting context, and AI Coach as one operating path.',
    JSON_ARRAY('email_assistant', 'whatsapp_assistant', 'sms_channel', 'calendar_meetings', 'ai_coach'),
    'Phase 1 modularization works best when channels, meetings, and proactive coaching share the same installed-module model.',
    'Owners can activate communication tools and AI guidance from the Marketplace without exposing uninstalled features in normal UI.',
    1,
    50
)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    summary = VALUES(summary),
    included_skill_keys_json = VALUES(included_skill_keys_json),
    why_this_bundle = VALUES(why_this_bundle),
    expected_outcome = VALUES(expected_outcome),
    display_order = VALUES(display_order),
    updated_at = NOW();
