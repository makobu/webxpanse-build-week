ALTER TABLE workspace_memberships
    MODIFY membership_status ENUM('active', 'invited', 'suspended', 'left', 'removed') NOT NULL DEFAULT 'active';
