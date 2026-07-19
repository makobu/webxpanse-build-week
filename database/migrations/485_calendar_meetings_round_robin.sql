-- Calendar & Meetings V2: team round-robin booking profiles.

ALTER TABLE meeting_booking_profiles
    ADD COLUMN booking_mode ENUM('single_host', 'round_robin') NOT NULL DEFAULT 'single_host' AFTER owner_user_id;

CREATE TABLE IF NOT EXISTS meeting_booking_profile_hosts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    profile_id INT NOT NULL,
    user_id INT NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    last_assigned_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_meeting_booking_profile_host (profile_id, user_id),
    KEY idx_meeting_booking_profile_hosts_profile_enabled (profile_id, is_enabled, sort_order),
    KEY idx_meeting_booking_profile_hosts_user (user_id, is_enabled),
    CONSTRAINT fk_meeting_booking_profile_hosts_profile FOREIGN KEY (profile_id) REFERENCES meeting_booking_profiles(id) ON DELETE CASCADE,
    CONSTRAINT fk_meeting_booking_profile_hosts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE meeting_booking_requests
    ADD COLUMN assigned_host_user_id INT DEFAULT NULL AFTER event_id,
    ADD COLUMN assignment_strategy VARCHAR(80) DEFAULT NULL AFTER assigned_host_user_id,
    ADD COLUMN assigned_at DATETIME DEFAULT NULL AFTER assignment_strategy,
    ADD COLUMN assignment_metadata_json JSON DEFAULT NULL AFTER assigned_at,
    ADD KEY idx_meeting_booking_assigned_host (workspace_id, assigned_host_user_id, status, scheduled_start),
    ADD CONSTRAINT fk_meeting_booking_assigned_host FOREIGN KEY (assigned_host_user_id) REFERENCES users(id) ON DELETE SET NULL;

INSERT INTO meeting_booking_profile_hosts (profile_id, user_id, is_enabled, sort_order, last_assigned_at)
SELECT p.id, p.owner_user_id, 1, 0, NULL
FROM meeting_booking_profiles p
WHERE p.owner_user_id IS NOT NULL
  AND p.owner_user_id > 0
ON DUPLICATE KEY UPDATE
    is_enabled = VALUES(is_enabled),
    updated_at = CURRENT_TIMESTAMP;

UPDATE meeting_booking_requests b
JOIN meeting_booking_profiles p ON p.id = b.profile_id
SET b.assigned_host_user_id = p.owner_user_id,
    b.assignment_strategy = COALESCE(b.assignment_strategy, 'single_host'),
    b.assigned_at = COALESCE(b.assigned_at, b.created_at)
WHERE b.assigned_host_user_id IS NULL
  AND p.owner_user_id IS NOT NULL
  AND p.owner_user_id > 0;
