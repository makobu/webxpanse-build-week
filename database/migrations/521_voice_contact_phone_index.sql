-- Privacy-preserving, workspace-scoped phone matching for voice callbacks.

CREATE TABLE IF NOT EXISTS voice_contact_phone_index (
    workspace_id INT NOT NULL,
    contact_id INT NOT NULL,
    phone_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (workspace_id, contact_id),
    KEY idx_voice_contact_phone_hash (workspace_id, phone_hash),
    CONSTRAINT fk_voice_contact_phone_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
