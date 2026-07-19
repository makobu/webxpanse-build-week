-- Migration 170: Add 'mobile_app' as a valid lead_source for contacts created via the mobile app
ALTER TABLE contacts
    MODIFY COLUMN lead_source
        ENUM('form','whatsapp','ad','referral','social','import','other','mobile_app')
        DEFAULT 'form';
