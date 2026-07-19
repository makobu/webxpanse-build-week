-- Migration 100: Add product media fields
-- Allows storing product image and demo video URLs in Company Profile products.

ALTER TABLE products ADD COLUMN product_image_url VARCHAR(500) NULL AFTER benefits;
ALTER TABLE products ADD COLUMN product_demo_video_url VARCHAR(500) NULL AFTER product_image_url;

