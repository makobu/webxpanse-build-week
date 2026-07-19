-- Support inbound WhatsApp shared contacts and inferred contact-share messages.

ALTER TABLE whatsapp_messages
MODIFY COLUMN message_type ENUM(
    'text',
    'template',
    'image',
    'document',
    'audio',
    'video',
    'sticker',
    'reaction',
    'interactive',
    'location',
    'contacts',
    'contact_share'
) DEFAULT 'text';
