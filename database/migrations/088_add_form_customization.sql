-- Add form customization: logo, images, colours, layout, GDPR
ALTER TABLE forms ADD COLUMN settings JSON NULL AFTER redirect_url;
