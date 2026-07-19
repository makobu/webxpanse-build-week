-- Owner Help Center: expert profile approval, profile photos, and structured quotes.

ALTER TABLE owner_help_expert_profiles
    ADD COLUMN profile_photo_path VARCHAR(500) NULL AFTER user_id,
    ADD COLUMN approval_status ENUM('draft','pending','approved','rejected') NOT NULL DEFAULT 'approved' AFTER profile_status,
    ADD COLUMN approved_by INT NULL AFTER approval_status,
    ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
    ADD COLUMN rejection_note TEXT NULL AFTER approved_at,
    ADD COLUMN sort_order INT NOT NULL DEFAULT 100 AFTER rejection_note,
    ADD COLUMN public_slug VARCHAR(160) NULL AFTER sort_order,
    ADD KEY idx_owner_help_experts_approval (approval_status, profile_status, is_internal, sort_order),
    ADD UNIQUE KEY uq_owner_help_experts_slug (public_slug),
    ADD CONSTRAINT fk_owner_help_experts_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL;

UPDATE owner_help_expert_profiles
SET approval_status = 'approved',
    approved_at = COALESCE(approved_at, NOW()),
    sort_order = COALESCE(sort_order, id * 10)
WHERE approval_status = 'approved';

CREATE TABLE IF NOT EXISTS owner_help_quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_request_id INT NOT NULL,
    ops_event_id INT NOT NULL,
    quote_number VARCHAR(80) NOT NULL,
    title VARCHAR(180) NOT NULL,
    scope_summary TEXT NULL,
    owner_visible_notes TEXT NULL,
    internal_notes TEXT NULL,
    terms TEXT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'KES',
    subtotal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('draft','sent','accepted','changes_requested','withdrawn','expired') NOT NULL DEFAULT 'draft',
    valid_until DATE NULL,
    sent_at DATETIME NULL,
    accepted_at DATETIME NULL,
    changes_requested_at DATETIME NULL,
    created_by INT NULL,
    updated_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_owner_help_quotes_number (quote_number),
    KEY idx_owner_help_quotes_request (service_request_id, status, created_at),
    KEY idx_owner_help_quotes_event (ops_event_id, status),
    CONSTRAINT fk_owner_help_quotes_request FOREIGN KEY (service_request_id) REFERENCES owner_help_service_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_owner_help_quotes_event FOREIGN KEY (ops_event_id) REFERENCES default_workspace_ops_events(id) ON DELETE CASCADE,
    CONSTRAINT fk_owner_help_quotes_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_owner_help_quotes_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS owner_help_quote_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quote_id INT NOT NULL,
    item_label VARCHAR(180) NOT NULL,
    item_description TEXT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1.00,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    sort_order INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_owner_help_quote_items_quote (quote_id, sort_order),
    CONSTRAINT fk_owner_help_quote_items_quote FOREIGN KEY (quote_id) REFERENCES owner_help_quotes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
