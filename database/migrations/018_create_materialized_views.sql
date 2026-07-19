-- Materialized Views
CREATE TABLE IF NOT EXISTS mv_daily_stats (
    date DATE PRIMARY KEY,
    user_id INT,
    contacts_added INT,
    emails_sent INT,
    tasks_completed INT,
    INDEX idx_user_date (user_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
