ALTER TABLE contacts
    ADD COLUMN IF NOT EXISTS lock_version INT NOT NULL DEFAULT 0 AFTER updated_at;

ALTER TABLE deals
    ADD COLUMN IF NOT EXISTS lock_version INT NOT NULL DEFAULT 0 AFTER updated_at;

ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS lock_version INT NOT NULL DEFAULT 0 AFTER updated_at;

ALTER TABLE documents
    ADD COLUMN IF NOT EXISTS lock_version INT NOT NULL DEFAULT 0 AFTER updated_at;

ALTER TABLE workflows
    ADD COLUMN IF NOT EXISTS lock_version INT NOT NULL DEFAULT 0 AFTER updated_at;

ALTER TABLE conversation_threads
    ADD COLUMN IF NOT EXISTS lock_version INT NOT NULL DEFAULT 0 AFTER updated_at;

CREATE TABLE IF NOT EXISTS communication_user_state (
    id INT AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    communication_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at DATETIME NULL,
    archived_at DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_communication_user_state (workspace_id, communication_id, user_id),
    KEY idx_communication_user_state_user (workspace_id, user_id, archived_at, deleted_at, read_at),
    KEY idx_communication_user_state_comm (communication_id),
    CONSTRAINT fk_communication_user_state_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_communication_user_state_communication
        FOREIGN KEY (communication_id) REFERENCES communications(id) ON DELETE CASCADE,
    CONSTRAINT fk_communication_user_state_user
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
