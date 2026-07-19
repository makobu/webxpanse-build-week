-- Link tasks to targets (optional)
-- Enables AI Coach recommendations to create tasks that explicitly support a target

ALTER TABLE tasks
    ADD COLUMN target_id INT DEFAULT NULL AFTER contact_id,
    ADD INDEX idx_target_id (target_id),
    ADD CONSTRAINT fk_tasks_target_id FOREIGN KEY (target_id) REFERENCES targets(id) ON DELETE SET NULL;
