ALTER TABLE nurture_profiles
    ADD COLUMN IF NOT EXISTS entry_source ENUM('deal_closed_won','paid_invoice','workspace_account','manual_customer_success') NULL AFTER owner_user_id,
    ADD COLUMN IF NOT EXISTS entry_reference_id INT NULL AFTER entry_source,
    ADD COLUMN IF NOT EXISTS entry_at DATETIME NULL AFTER entry_reference_id,
    ADD COLUMN IF NOT EXISTS purchase_summary_json JSON NULL AFTER entry_at;

SET @idx_exists := (
    SELECT COUNT(1)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'nurture_profiles'
      AND INDEX_NAME = 'idx_nurture_profiles_entry_source'
);
SET @sql := IF(@idx_exists = 0,
    'CREATE INDEX idx_nurture_profiles_entry_source ON nurture_profiles (workspace_id, entry_source, entry_at)',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE nurture_profiles np
JOIN contacts c ON c.id = np.contact_id AND c.workspace_id = np.workspace_id
SET np.entry_source = 'workspace_account',
    np.entry_reference_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.owner_workspace_id')) AS UNSIGNED),
    np.entry_at = COALESCE(
        STR_TO_DATE(JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.subscription_payment_state.last_paid_at')), '%Y-%m-%d %H:%i:%s'),
        np.created_at
    ),
    np.purchase_summary_json = JSON_OBJECT(
        'source', 'workspace_account',
        'label', 'Workspace account purchase',
        'title', JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.owner_workspace_name')),
        'status', JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.owner_workspace_plan_status'))
    )
WHERE np.entry_source IS NULL
  AND c.metadata_json IS NOT NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(c.metadata_json, '$.current_paying_customer')) = 'true';

UPDATE nurture_profiles np
JOIN (
    SELECT d.workspace_id,
           d.contact_id,
           MAX(d.id) AS deal_id,
           MAX(COALESCE(d.actual_close_date, DATE(d.updated_at), DATE(d.created_at))) AS closed_at
    FROM deals d
    WHERE d.stage = 'closed_won'
      AND d.contact_id IS NOT NULL
    GROUP BY d.workspace_id, d.contact_id
) won ON won.workspace_id = np.workspace_id AND won.contact_id = np.contact_id
JOIN deals d ON d.id = won.deal_id AND d.workspace_id = won.workspace_id
SET np.entry_source = 'deal_closed_won',
    np.entry_reference_id = d.id,
    np.entry_at = COALESCE(d.actual_close_date, d.updated_at, d.created_at),
    np.purchase_summary_json = JSON_OBJECT(
        'source', 'deal_closed_won',
        'label', 'Closed-won deal',
        'title', d.title,
        'amount', d.value,
        'currency', d.currency
    )
WHERE np.entry_source IS NULL;

UPDATE nurture_profiles np
JOIN (
    SELECT i.workspace_id,
           COALESCE(i.contact_id, d.contact_id, co.primary_contact_id) AS resolved_contact_id,
           MAX(i.id) AS invoice_id,
           MAX(COALESCE(i.paid_at, i.finalized_at, i.updated_at, i.created_at)) AS paid_at
    FROM invoices i
    LEFT JOIN deals d ON d.id = i.deal_id AND d.workspace_id = i.workspace_id
    LEFT JOIN companies co ON co.id = i.company_id AND co.workspace_id = i.workspace_id
    WHERE i.document_type = 'invoice'
      AND i.status IN ('paid', 'partially_paid')
      AND COALESCE(i.contact_id, d.contact_id, co.primary_contact_id) IS NOT NULL
    GROUP BY i.workspace_id, COALESCE(i.contact_id, d.contact_id, co.primary_contact_id)
) paid ON paid.workspace_id = np.workspace_id AND paid.resolved_contact_id = np.contact_id
JOIN invoices i ON i.id = paid.invoice_id AND i.workspace_id = paid.workspace_id
SET np.entry_source = 'paid_invoice',
    np.entry_reference_id = i.id,
    np.entry_at = COALESCE(i.paid_at, i.finalized_at, i.updated_at, i.created_at),
    np.purchase_summary_json = JSON_OBJECT(
        'source', 'paid_invoice',
        'label', 'Paid invoice',
        'title', i.title,
        'number', i.invoice_number,
        'amount', i.grand_total,
        'currency', i.currency,
        'status', i.status
    )
WHERE np.entry_source IS NULL;
