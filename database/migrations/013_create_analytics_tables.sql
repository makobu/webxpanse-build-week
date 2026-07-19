-- Analytics Tables
CREATE TABLE IF NOT EXISTS analytics_daily (
    date DATE PRIMARY KEY,
    contacts_created INT DEFAULT 0,
    emails_sent INT DEFAULT 0,
    emails_opened INT DEFAULT 0,
    emails_clicked INT DEFAULT 0,
    whatsapp_sent INT DEFAULT 0,
    form_submissions INT DEFAULT 0,
    deals_won INT DEFAULT 0,
    revenue DECIMAL(12,2) DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS funnel_analytics (
    stage VARCHAR(50),
    date DATE,
    entered_count INT,
    exited_count INT,
    converted_count INT,
    avg_duration_hours DECIMAL(10,2),
    PRIMARY KEY (stage, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
