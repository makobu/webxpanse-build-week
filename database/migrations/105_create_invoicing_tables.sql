-- Migration 105: Invoicing module

CREATE TABLE IF NOT EXISTS invoice_settings (
    id INT PRIMARY KEY AUTO_INCREMENT,
    enabled BOOLEAN NOT NULL DEFAULT TRUE,
    invoice_prefix VARCHAR(20) NOT NULL DEFAULT 'INV-',
    invoice_next_number INT NOT NULL DEFAULT 1,
    proforma_prefix VARCHAR(20) NOT NULL DEFAULT 'PF-',
    proforma_next_number INT NOT NULL DEFAULT 1,
    quote_prefix VARCHAR(20) NOT NULL DEFAULT 'QT-',
    quote_next_number INT NOT NULL DEFAULT 1,
    default_currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    default_tax_mode ENUM('exclusive','inclusive','none') NOT NULL DEFAULT 'exclusive',
    default_tax_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    default_payment_terms_days INT NOT NULL DEFAULT 14,
    default_validity_days INT NOT NULL DEFAULT 14,
    default_notes TEXT NULL,
    default_terms TEXT NULL,
    company_legal_name VARCHAR(255) NULL,
    company_tax_id VARCHAR(100) NULL,
    company_address TEXT NULL,
    company_email VARCHAR(255) NULL,
    company_phone VARCHAR(100) NULL,
    bank_name VARCHAR(255) NULL,
    bank_account_name VARCHAR(255) NULL,
    bank_account_number VARCHAR(255) NULL,
    bank_branch VARCHAR(255) NULL,
    bank_swift VARCHAR(100) NULL,
    bank_instructions TEXT NULL,
    logo_asset_path VARCHAR(500) NULL,
    footer_text TEXT NULL,
    visual_theme VARCHAR(50) NOT NULL DEFAULT 'classic',
    proposal_intro_text TEXT NULL,
    acceptance_instructions TEXT NULL,
    ai_create_quotes BOOLEAN NOT NULL DEFAULT TRUE,
    ai_revise_documents BOOLEAN NOT NULL DEFAULT TRUE,
    ai_send_documents BOOLEAN NOT NULL DEFAULT TRUE,
    ai_finalize_invoices BOOLEAN NOT NULL DEFAULT TRUE,
    ai_mark_paid BOOLEAN NOT NULL DEFAULT FALSE,
    ai_require_approval_send BOOLEAN NOT NULL DEFAULT FALSE,
    ai_require_approval_finalize BOOLEAN NOT NULL DEFAULT FALSE,
    ai_allowed_channels JSON NULL,
    ai_max_discount_percent DECIMAL(8,4) NOT NULL DEFAULT 20,
    ai_max_total_change_percent DECIMAL(8,4) NOT NULL DEFAULT 25,
    ai_allowed_document_types_by_stage JSON NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO invoice_settings (
    id, enabled, invoice_prefix, invoice_next_number, proforma_prefix, proforma_next_number,
    quote_prefix, quote_next_number, default_currency, default_tax_mode, default_tax_rate,
    default_payment_terms_days, default_validity_days, default_notes, default_terms, visual_theme,
    proposal_intro_text, acceptance_instructions, ai_create_quotes, ai_revise_documents,
    ai_send_documents, ai_finalize_invoices, ai_mark_paid, ai_require_approval_send,
    ai_require_approval_finalize, ai_allowed_channels, ai_max_discount_percent,
    ai_max_total_change_percent, ai_allowed_document_types_by_stage
) VALUES (
    1, 1, 'INV-', 1, 'PF-', 1, 'QT-', 1, 'USD', 'exclusive', 0,
    14, 14, 'Thank you for your business.', 'Payment due within the stated terms.', 'classic',
    'Prepared for your review. Please see the pricing and terms below.',
    'Reply to this message or contact us to confirm acceptance.',
    1, 1, 1, 1, 0, 0, 0,
    JSON_OBJECT('email', TRUE, 'whatsapp', TRUE),
    20, 25,
    JSON_OBJECT(
        'proposal', JSON_ARRAY('quote', 'proforma'),
        'negotiation', JSON_ARRAY('quote', 'proforma', 'invoice'),
        'closed_won', JSON_ARRAY('invoice')
    )
)
ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS invoices (
    id INT PRIMARY KEY AUTO_INCREMENT,
    document_type ENUM('quote','proforma','invoice','credit_note') NOT NULL DEFAULT 'invoice',
    status ENUM('draft','sent','viewed','accepted','revised','finalized','partially_paid','paid','cancelled','overdue') NOT NULL DEFAULT 'draft',
    invoice_number VARCHAR(60) NOT NULL,
    revision_number INT NOT NULL DEFAULT 1,
    deal_id INT NULL,
    contact_id INT NULL,
    company_id INT NULL,
    assigned_to INT NULL,
    created_by INT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'USD',
    issue_date DATE NULL,
    due_date DATE NULL,
    valid_until DATE NULL,
    payment_terms_days INT NOT NULL DEFAULT 14,
    subtotal DECIMAL(14,2) NOT NULL DEFAULT 0,
    discount_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    grand_total DECIMAL(14,2) NOT NULL DEFAULT 0,
    amount_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
    balance_due DECIMAL(14,2) NOT NULL DEFAULT 0,
    tax_mode ENUM('exclusive','inclusive','none') NOT NULL DEFAULT 'exclusive',
    tax_rate DECIMAL(8,4) NOT NULL DEFAULT 0,
    title VARCHAR(255) NOT NULL,
    intro_text TEXT NULL,
    notes TEXT NULL,
    terms TEXT NULL,
    billing_name VARCHAR(255) NULL,
    billing_email VARCHAR(255) NULL,
    billing_phone VARCHAR(100) NULL,
    billing_address TEXT NULL,
    shipping_address TEXT NULL,
    source_snapshot_json JSON NULL,
    last_sent_at DATETIME NULL,
    last_viewed_at DATETIME NULL,
    accepted_at DATETIME NULL,
    finalized_at DATETIME NULL,
    paid_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_invoice_number (invoice_number),
    INDEX idx_invoice_type (document_type),
    INDEX idx_invoice_status (status),
    INDEX idx_invoice_deal (deal_id),
    INDEX idx_invoice_contact (contact_id),
    INDEX idx_invoice_company (company_id),
    INDEX idx_invoice_created_at (created_at),
    CONSTRAINT fk_invoices_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_assigned_user FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_invoices_created_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_line_items (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    product_id INT NULL,
    description VARCHAR(500) NOT NULL,
    quantity DECIMAL(12,4) NOT NULL DEFAULT 1,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    discount_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    tax_percent DECIMAL(8,4) NOT NULL DEFAULT 0,
    tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_invoice_line_invoice (invoice_id),
    INDEX idx_invoice_line_product (product_id),
    CONSTRAINT fk_invoice_line_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    action_key VARCHAR(100) NOT NULL,
    actor_type ENUM('user','ai','system') NOT NULL DEFAULT 'user',
    actor_id INT NULL,
    summary VARCHAR(255) NOT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice_activity_invoice (invoice_id),
    INDEX idx_invoice_activity_action (action_key),
    CONSTRAINT fk_invoice_activity_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_status_history (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    from_status VARCHAR(50) NULL,
    to_status VARCHAR(50) NOT NULL,
    changed_by INT NULL,
    changed_by_type ENUM('user','ai','system') NOT NULL DEFAULT 'user',
    reason VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice_status_invoice (invoice_id),
    CONSTRAINT fk_invoice_status_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_delivery_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    channel ENUM('email','whatsapp','download','api') NOT NULL DEFAULT 'email',
    recipient VARCHAR(255) NULL,
    subject VARCHAR(255) NULL,
    delivery_status VARCHAR(50) NOT NULL DEFAULT 'queued',
    provider_message_id VARCHAR(255) NULL,
    sent_by INT NULL,
    sent_by_type ENUM('user','ai','system') NOT NULL DEFAULT 'user',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invoice_delivery_invoice (invoice_id),
    CONSTRAINT fk_invoice_delivery_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (permission_key, label, description, is_sensitive) VALUES
('feature.invoices', 'Invoices Feature', 'Access invoices and quotes module', FALSE),
('invoices.view', 'View Invoices', 'View invoices, quotes, and proformas', FALSE),
('invoices.create', 'Create Invoices', 'Create invoices, quotes, and proformas', FALSE),
('invoices.edit', 'Edit Invoices', 'Edit draft and revised invoices', FALSE),
('invoices.send', 'Send Invoices', 'Send invoices to customers', FALSE),
('invoices.finalize', 'Finalize Invoices', 'Finalize commercial documents', TRUE),
('invoices.mark_paid', 'Mark Invoice Paid', 'Update payment status manually', TRUE),
('invoices.settings', 'Invoice Settings', 'Manage invoicing configuration', TRUE),
('settings.invoicing', 'Invoicing Settings', 'Manage invoicing settings tab', TRUE),
('ai.invoices.execute', 'AI Invoice Execution', 'Allow AI invoice operations', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'feature.invoices',
    'invoices.view',
    'invoices.create',
    'invoices.edit',
    'invoices.send',
    'invoices.finalize',
    'invoices.mark_paid',
    'invoices.settings',
    'settings.invoicing',
    'ai.invoices.execute'
)
WHERE r.slug IN ('admin', 'owner')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'feature.invoices',
    'invoices.view',
    'invoices.create',
    'invoices.edit',
    'invoices.send'
)
WHERE r.slug = 'sales'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);
