CREATE TABLE IF NOT EXISTS demo_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    run_uuid CHAR(36) NOT NULL UNIQUE,
    status ENUM('active','inactive','failed','purged') NOT NULL DEFAULT 'active',
    seed_profile VARCHAR(50) NOT NULL DEFAULT 'full',
    created_by INT NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deactivated_at DATETIME NULL,
    INDEX idx_demo_runs_status (status),
    INDEX idx_demo_runs_created_at (created_at),
    CONSTRAINT fk_demo_runs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demo_mode_state (
    id TINYINT PRIMARY KEY,
    is_enabled TINYINT(1) NOT NULL DEFAULT 0,
    active_run_id INT NULL,
    simulation_only TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_demo_mode_state_single CHECK (id = 1),
    CONSTRAINT fk_demo_mode_state_active_run FOREIGN KEY (active_run_id) REFERENCES demo_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_demo_mode_state_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO demo_mode_state (id, is_enabled, active_run_id, simulation_only, updated_by)
VALUES (1, 0, NULL, 1, NULL)
ON DUPLICATE KEY UPDATE id = VALUES(id);

CREATE TABLE IF NOT EXISTS demo_seed_registry (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    run_id INT NOT NULL,
    table_name VARCHAR(128) NOT NULL,
    record_id BIGINT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_demo_seed_registry_run (run_id),
    INDEX idx_demo_seed_registry_table_record (table_name, record_id),
    CONSTRAINT fk_demo_seed_registry_run FOREIGN KEY (run_id) REFERENCES demo_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS demo_operation_logs (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    run_id INT NULL,
    operation ENUM('seed','toggle_on','toggle_off','purge','reseed') NOT NULL,
    result ENUM('success','failed') NOT NULL,
    message TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_demo_operation_logs_run (run_id),
    INDEX idx_demo_operation_logs_created (created_at),
    CONSTRAINT fk_demo_operation_logs_run FOREIGN KEY (run_id) REFERENCES demo_runs(id) ON DELETE SET NULL,
    CONSTRAINT fk_demo_operation_logs_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
