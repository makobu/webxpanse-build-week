ALTER TABLE workspace_whatsapp_suppression_list
    ADD COLUMN IF NOT EXISTS active_phone_key VARCHAR(191) NULL AFTER phone_number;

UPDATE workspace_whatsapp_suppression_list s
JOIN (
    SELECT workspace_id, phone_number, MIN(id) AS keep_id
    FROM workspace_whatsapp_suppression_list
    WHERE released_at IS NULL
    GROUP BY workspace_id, phone_number
    HAVING COUNT(*) > 1
) dup
  ON dup.workspace_id = s.workspace_id
 AND dup.phone_number = s.phone_number
SET s.released_at = DATE_ADD(NOW(), INTERVAL s.id SECOND),
    s.metadata_json = JSON_SET(
        COALESCE(NULLIF(s.metadata_json, ''), JSON_OBJECT()),
        '$.auto_released_duplicate_active_suppression', TRUE,
        '$.auto_released_at', UTC_TIMESTAMP()
    )
WHERE s.released_at IS NULL
  AND s.id <> dup.keep_id;

UPDATE workspace_whatsapp_suppression_list
SET active_phone_key = CASE WHEN released_at IS NULL THEN phone_number ELSE NULL END;

ALTER TABLE workspace_whatsapp_suppression_list
    ADD UNIQUE KEY uniq_whatsapp_suppression_active_phone (workspace_id, active_phone_key);

UPDATE workspace_skill_definitions
SET settings_schema_json = JSON_SET(
        COALESCE(settings_schema_json, JSON_OBJECT()),
        '$.feature_flags.whatsapp_managed_billing', FALSE,
        '$.feature_flags.whatsapp_managed_billing_workspace_ids', JSON_ARRAY(1)
    )
WHERE skill_key = 'whatsapp';
