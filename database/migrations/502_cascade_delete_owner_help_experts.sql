-- Keep owner-help expert profiles tied to real user accounts.

UPDATE owner_help_service_requests r
JOIN owner_help_expert_profiles p ON p.id = r.expert_profile_id
SET r.expert_profile_id = NULL,
    r.updated_at = NOW()
WHERE p.user_id IS NULL;

DELETE FROM owner_help_expert_profiles
WHERE user_id IS NULL;

SET @owner_help_experts_user_fk := (
    SELECT kcu.CONSTRAINT_NAME
    FROM information_schema.KEY_COLUMN_USAGE kcu
    WHERE kcu.TABLE_SCHEMA = DATABASE()
      AND kcu.TABLE_NAME = 'owner_help_expert_profiles'
      AND kcu.COLUMN_NAME = 'user_id'
      AND kcu.REFERENCED_TABLE_NAME = 'users'
    LIMIT 1
);

SET @owner_help_experts_drop_fk_sql := IF(
    @owner_help_experts_user_fk IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE owner_help_expert_profiles DROP FOREIGN KEY `', REPLACE(@owner_help_experts_user_fk, '`', '``'), '`')
);

PREPARE owner_help_experts_drop_fk_stmt FROM @owner_help_experts_drop_fk_sql;
EXECUTE owner_help_experts_drop_fk_stmt;
DEALLOCATE PREPARE owner_help_experts_drop_fk_stmt;

ALTER TABLE owner_help_expert_profiles
    ADD CONSTRAINT fk_owner_help_experts_user
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
