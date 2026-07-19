-- Migration 185: Add 'web_assessment' as a valid lead source for landing-page readiness assessments
ALTER TABLE contacts
    MODIFY COLUMN lead_source
        ENUM('form','whatsapp','ad','referral','social','import','other','mobile_app','web_assessment')
        DEFAULT 'form';
