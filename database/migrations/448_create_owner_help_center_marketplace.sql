-- Coordinated owner Help Center, paid setup help, and internal expert marketplace.

CREATE TABLE IF NOT EXISTS owner_help_offerings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    offering_key VARCHAR(80) NOT NULL,
    lane ENUM('setup_help','installation_help') NOT NULL DEFAULT 'setup_help',
    label VARCHAR(160) NOT NULL,
    summary TEXT NULL,
    pricing_label VARCHAR(120) NULL,
    sort_order INT NOT NULL DEFAULT 100,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_help_offerings_key (offering_key),
    KEY idx_owner_help_offerings_lane_active (lane, is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS owner_help_expert_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    role_label VARCHAR(120) NOT NULL DEFAULT 'Setup Specialist',
    headline VARCHAR(255) NOT NULL,
    bio TEXT NULL,
    cv_summary TEXT NULL,
    setup_areas_json JSON NULL,
    industries_json JSON NULL,
    languages_json JSON NULL,
    timezone VARCHAR(80) NULL,
    availability_summary VARCHAR(255) NULL,
    profile_status ENUM('active','hidden') NOT NULL DEFAULT 'active',
    is_internal TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_help_experts_user (user_id),
    KEY idx_owner_help_experts_status (profile_status, is_internal),
    CONSTRAINT fk_owner_help_experts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS owner_help_expert_skills (
    id INT AUTO_INCREMENT PRIMARY KEY,
    expert_profile_id INT NOT NULL,
    skill_key VARCHAR(100) NOT NULL,
    skill_label VARCHAR(160) NOT NULL,
    verification_level ENUM('self_declared','platform_verified','system_verified') NOT NULL DEFAULT 'self_declared',
    evidence_label VARCHAR(255) NULL,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_help_expert_skill (expert_profile_id, skill_key),
    KEY idx_owner_help_expert_skills_level (verification_level, sort_order),
    CONSTRAINT fk_owner_help_expert_skills_profile FOREIGN KEY (expert_profile_id) REFERENCES owner_help_expert_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS owner_help_service_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ops_event_id INT NOT NULL,
    owner_workspace_id INT NOT NULL,
    owner_user_id INT NOT NULL,
    lane ENUM('system_error','billing_access','account_access','setup_help','installation_help','strategy_mentor','account_manager') NOT NULL DEFAULT 'system_error',
    commercial_type ENUM('free','paid_setup','paid_expert') NOT NULL DEFAULT 'free',
    pricing_state ENUM('free','quote_required','quoted','accepted','manual_payment_pending','not_applicable') NOT NULL DEFAULT 'free',
    lifecycle_status ENUM('new','triaged','quoted','waiting_on_owner','assigned','in_progress','resolved','cancelled') NOT NULL DEFAULT 'new',
    offering_id INT NULL,
    expert_profile_id INT NULL,
    owner_goal TEXT NULL,
    preferred_contact_method VARCHAR(80) NULL,
    preferred_time VARCHAR(160) NULL,
    quote_notes TEXT NULL,
    admin_notes TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_help_request_event (ops_event_id),
    KEY idx_owner_help_requests_workspace (owner_workspace_id, lane, lifecycle_status, created_at),
    KEY idx_owner_help_requests_admin (commercial_type, pricing_state, lifecycle_status, created_at),
    KEY idx_owner_help_requests_expert (expert_profile_id, lifecycle_status),
    CONSTRAINT fk_owner_help_requests_event FOREIGN KEY (ops_event_id) REFERENCES default_workspace_ops_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_owner_help_requests_offering FOREIGN KEY (offering_id) REFERENCES owner_help_offerings(id) ON DELETE SET NULL,
    CONSTRAINT fk_owner_help_requests_expert FOREIGN KEY (expert_profile_id) REFERENCES owner_help_expert_profiles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO owner_help_offerings (offering_key, lane, label, summary, pricing_label, sort_order, is_active)
VALUES
    ('workspace_setup_audit', 'setup_help', 'Workspace Setup Audit', 'Review the workspace setup, spot missing configuration, and produce a practical setup checklist.', 'Quote required', 10, 1),
    ('email_whatsapp_setup', 'setup_help', 'Email / WhatsApp Setup', 'Help connect outbound and inbound messaging channels, test readiness, and document the operating handoff.', 'Quote required', 20, 1),
    ('email_assistant_setup', 'setup_help', 'Email Assistant Setup', 'Configure assistant identity, inbound/outbound controls, digest behavior, tests, and safety checks.', 'Quote required', 30, 1),
    ('automation_setup', 'setup_help', 'Automation Setup', 'Set up practical workflow automation, readiness checks, and owner-safe operating boundaries.', 'Quote required', 40, 1),
    ('finance_setup', 'setup_help', 'Finance Setup', 'Prepare finance opening details, invoice defaults, payment instructions, and readiness for owner review.', 'Quote required', 50, 1),
    ('launch_readiness_review', 'installation_help', 'Launch Readiness Review', 'Review the workspace before launch and identify final setup, access, communication, and billing gaps.', 'Quote required', 60, 1)
ON DUPLICATE KEY UPDATE
    lane = VALUES(lane),
    label = VALUES(label),
    summary = VALUES(summary),
    pricing_label = VALUES(pricing_label),
    sort_order = VALUES(sort_order),
    is_active = VALUES(is_active),
    updated_at = NOW();

INSERT INTO owner_help_expert_profiles (
    user_id,
    role_label,
    headline,
    bio,
    cv_summary,
    setup_areas_json,
    industries_json,
    languages_json,
    timezone,
    availability_summary,
    profile_status,
    is_internal
)
SELECT
    u.id,
    'Setup Specialist',
    'Internal verified CRM setup and launch support',
    'Helps workspace owners configure core CRM setup, communication channels, marketplace modules, and launch readiness.',
    'Internal platform operator with CRM setup, owner onboarding, support triage, and implementation experience.',
    JSON_ARRAY('Workspace setup', 'Email and WhatsApp setup', 'Automation readiness', 'Launch readiness'),
    JSON_ARRAY('Startups', 'Sales operations', 'Service businesses'),
    JSON_ARRAY('English'),
    'Africa/Nairobi',
    'Request a match from the Help Center.',
    'active',
    1
FROM users u
JOIN workspace_memberships wm ON wm.user_id = u.id
WHERE wm.workspace_id = 1
  AND wm.membership_status = 'active'
  AND (wm.is_owner = 1 OR wm.role_slug IN ('superadmin', 'admin'))
ORDER BY wm.is_owner DESC, u.id ASC
LIMIT 3
ON DUPLICATE KEY UPDATE
    role_label = VALUES(role_label),
    headline = VALUES(headline),
    bio = VALUES(bio),
    cv_summary = VALUES(cv_summary),
    setup_areas_json = VALUES(setup_areas_json),
    industries_json = VALUES(industries_json),
    languages_json = VALUES(languages_json),
    timezone = VALUES(timezone),
    availability_summary = VALUES(availability_summary),
    profile_status = VALUES(profile_status),
    is_internal = VALUES(is_internal),
    updated_at = NOW();

INSERT INTO owner_help_expert_skills (expert_profile_id, skill_key, skill_label, verification_level, evidence_label, sort_order)
SELECT p.id, 'workspace_setup', 'Workspace setup', 'system_verified', 'Verified by internal workspace setup operations', 10
FROM owner_help_expert_profiles p
WHERE p.profile_status = 'active'
ON DUPLICATE KEY UPDATE
    skill_label = VALUES(skill_label),
    verification_level = VALUES(verification_level),
    evidence_label = VALUES(evidence_label),
    sort_order = VALUES(sort_order),
    updated_at = NOW();

INSERT INTO owner_help_expert_skills (expert_profile_id, skill_key, skill_label, verification_level, evidence_label, sort_order)
SELECT p.id, 'marketplace_setup', 'Marketplace module setup', 'platform_verified', 'Reviewed for marketplace setup support', 20
FROM owner_help_expert_profiles p
WHERE p.profile_status = 'active'
ON DUPLICATE KEY UPDATE
    skill_label = VALUES(skill_label),
    verification_level = VALUES(verification_level),
    evidence_label = VALUES(evidence_label),
    sort_order = VALUES(sort_order),
    updated_at = NOW();

INSERT INTO owner_help_expert_skills (expert_profile_id, skill_key, skill_label, verification_level, evidence_label, sort_order)
SELECT p.id, 'launch_readiness', 'Launch readiness', 'platform_verified', 'Reviewed for owner launch readiness support', 30
FROM owner_help_expert_profiles p
WHERE p.profile_status = 'active'
ON DUPLICATE KEY UPDATE
    skill_label = VALUES(skill_label),
    verification_level = VALUES(verification_level),
    evidence_label = VALUES(evidence_label),
    sort_order = VALUES(sort_order),
    updated_at = NOW();
