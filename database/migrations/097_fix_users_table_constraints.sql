-- Ensure users table keys/constraints are compatible with login and user creation.
-- This migration is intentionally conservative: it will not rewrite user IDs.

-- Normalize stored email values before unique enforcement.
UPDATE users
SET email = LOWER(TRIM(email))
WHERE email IS NOT NULL
  AND email <> LOWER(TRIM(email));

-- Add PRIMARY KEY(id) when missing.
SET @has_users_pk := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND constraint_type = 'PRIMARY KEY'
);
SET @add_users_pk_sql := IF(@has_users_pk = 0, 'ALTER TABLE users ADD PRIMARY KEY (id)', 'SET @noop := 1');
PREPARE users_stmt FROM @add_users_pk_sql;
EXECUTE users_stmt;
DEALLOCATE PREPARE users_stmt;

-- Enable AUTO_INCREMENT on users.id when missing.
SET @users_id_is_auto_inc := (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND column_name = 'id'
      AND LOWER(COALESCE(extra, '')) LIKE '%auto_increment%'
);
SET @users_ai_sql := IF(@users_id_is_auto_inc = 0, 'ALTER TABLE users MODIFY id INT(11) NOT NULL AUTO_INCREMENT', 'SET @noop := 1');
PREPARE users_stmt FROM @users_ai_sql;
EXECUTE users_stmt;
DEALLOCATE PREPARE users_stmt;

-- Ensure uniqueness and lookup indexes.
SET @has_dup_email := (
    SELECT COUNT(*)
    FROM (
        SELECT LOWER(TRIM(email))
        FROM users
        GROUP BY LOWER(TRIM(email))
        HAVING COUNT(*) > 1
    ) d
);
SET @has_uq_users_email := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'uq_users_email'
);
SET @users_uq_email_sql := IF(@has_uq_users_email = 0 AND @has_dup_email = 0, 'ALTER TABLE users ADD UNIQUE KEY uq_users_email (email)', 'SET @noop := 1');
PREPARE users_stmt FROM @users_uq_email_sql;
EXECUTE users_stmt;
DEALLOCATE PREPARE users_stmt;

SET @has_dup_uuid := (
    SELECT COUNT(*)
    FROM (
        SELECT uuid
        FROM users
        GROUP BY uuid
        HAVING COUNT(*) > 1
    ) d
);
SET @has_uq_users_uuid := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'uq_users_uuid'
);
SET @users_uq_uuid_sql := IF(@has_uq_users_uuid = 0 AND @has_dup_uuid = 0, 'ALTER TABLE users ADD UNIQUE KEY uq_users_uuid (uuid)', 'SET @noop := 1');
PREPARE users_stmt FROM @users_uq_uuid_sql;
EXECUTE users_stmt;
DEALLOCATE PREPARE users_stmt;

SET @has_idx_role := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'users'
      AND index_name = 'idx_role'
);
SET @users_idx_role_sql := IF(@has_idx_role = 0, 'ALTER TABLE users ADD KEY idx_role (role)', 'SET @noop := 1');
PREPARE users_stmt FROM @users_idx_role_sql;
EXECUTE users_stmt;
DEALLOCATE PREPARE users_stmt;
