CREATE TABLE IF NOT EXISTS marketplace_video_assets (
    id INT PRIMARY KEY AUTO_INCREMENT,
    title VARCHAR(160) NOT NULL,
    original_filename VARCHAR(255) NOT NULL,
    stored_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) NULL,
    file_size BIGINT UNSIGNED NULL,
    checksum_sha256 CHAR(64) NULL,
    uploaded_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_marketplace_video_assets_path (stored_path),
    UNIQUE KEY uq_marketplace_video_assets_checksum (checksum_sha256),
    KEY idx_marketplace_video_assets_uploaded_by (uploaded_by_user_id),
    CONSTRAINT fk_marketplace_video_assets_uploaded_by
        FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE marketplace_page_explainers
    ADD COLUMN video_asset_id INT NULL AFTER video_url,
    ADD KEY idx_marketplace_page_explainers_video_asset (video_asset_id),
    ADD CONSTRAINT fk_marketplace_page_explainers_video_asset
        FOREIGN KEY (video_asset_id) REFERENCES marketplace_video_assets(id) ON DELETE SET NULL;

INSERT INTO marketplace_video_assets (
    title,
    original_filename,
    stored_path,
    mime_type,
    file_size,
    checksum_sha256,
    uploaded_by_user_id
)
SELECT
    COALESCE(NULLIF(label, ''), page_key),
    SUBSTRING_INDEX(video_url, '/', -1),
    video_url,
    NULL,
    NULL,
    NULL,
    updated_by_user_id
FROM marketplace_page_explainers
WHERE COALESCE(TRIM(video_url), '') <> ''
ON DUPLICATE KEY UPDATE
    title = VALUES(title),
    updated_at = NOW();

UPDATE marketplace_page_explainers e
JOIN marketplace_video_assets a ON a.stored_path = e.video_url
SET e.video_asset_id = a.id
WHERE COALESCE(TRIM(e.video_url), '') <> ''
  AND e.video_asset_id IS NULL;
