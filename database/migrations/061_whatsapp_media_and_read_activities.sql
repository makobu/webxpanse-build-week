-- Add whatsapp_message_read to activities for customer read tracking
ALTER TABLE activities
MODIFY COLUMN activity_type ENUM(
    'email','call','note','meeting','status_change','form_submit',
    'email_opened','link_clicked','page_visited','whatsapp_message_read'
) NOT NULL;

-- Add sticker (and reaction) to whatsapp_messages.message_type for media visibility
ALTER TABLE whatsapp_messages
MODIFY COLUMN message_type ENUM(
    'text','template','image','document','audio','video','sticker','reaction'
) DEFAULT 'text';
