# How to Send Business-Initiated WhatsApp Messages

## Overview

WhatsApp Business API has two types of messages:

1. **Template Messages (Business-Initiated)** - Can be sent anytime, no time restrictions
   - Requires pre-approved templates in WhatsApp Business Manager
   - Use for marketing, notifications, or any outbound communication
   
2. **Session Messages (Text Messages)** - Only work within 24-hour window
   - Can only be sent within 24 hours after customer's last message
   - Use for customer support conversations

## Prerequisites

1. **WhatsApp Business API Setup**
   - Configure `WHATSAPP_PHONE_NUMBER_ID` in your `.env` file
   - Configure `WHATSAPP_ACCESS_TOKEN` in your `.env` file
   - These are obtained from Meta Business Manager

2. **Approved Templates**
   - Create and approve templates in WhatsApp Business Manager
   - Templates must be approved before use
   - Template names are case-sensitive

## Sending Business-Initiated Messages

### Method 1: Using the API Endpoint

**Endpoint:** `POST /api/whatsapp_send.php`

**Example Request:**
```json
{
  "contact_id": 123,
  "message_type": "template",
  "template_name": "hello_world",
  "language_code": "en",
  "template_params": {
    "body": ["John Doe", "Acme Corp"]
  }
}
```

**Using cURL:**
```bash
curl -X POST https://your-domain.com/crm/api/whatsapp_send.php \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=your-session-id" \
  -d '{
    "contact_id": 123,
    "message_type": "template",
    "template_name": "hello_world",
    "language_code": "en",
    "template_params": {
      "body": ["John Doe"]
    }
  }'
```

### Method 2: Using PHP Code Directly

```php
use CRM\Services\WhatsAppService;

$whatsappService = new WhatsAppService();

// Send template message
$result = $whatsappService->sendTemplateMessage(
    '+1234567890',           // Phone number
    'hello_world',           // Template name
    'en',                    // Language code
    [                        // Template components (optional)
        [
            'type' => 'body',
            'parameters' => [
                ['type' => 'text', 'text' => 'John Doe']
            ]
        ]
    ]
);

// Store message in database
$uuid = $whatsappService->storeMessage(
    $contactId,
    '+1234567890',
    'template',
    'Template: hello_world',
    [
        'user_id' => $userId,
        'template_name' => 'hello_world',
        'template_params' => ['body' => ['John Doe']]
    ]
);
```

## Template Parameters

Templates can have three types of parameters:

### 1. Body Parameters
```json
{
  "template_params": {
    "body": ["Value 1", "Value 2", "Value 3"]
  }
}
```

### 2. Header Parameters
```json
{
  "template_params": {
    "header": ["Header Value"]
  }
}
```

### 3. Button Parameters
```json
{
  "template_params": {
    "buttons": ["button_payload_1", "button_payload_2"]
  }
}
```

## Common Template Examples

### Simple Text Template
```json
{
  "contact_id": 123,
  "message_type": "template",
  "template_name": "hello_world",
  "language_code": "en"
}
```

### Template with Body Parameters
```json
{
  "contact_id": 123,
  "message_type": "template",
  "template_name": "order_confirmation",
  "language_code": "en",
  "template_params": {
    "body": ["ORD-12345", "$99.99", "2-3 business days"]
  }
}
```

### Template with Header and Body
```json
{
  "contact_id": 123,
  "message_type": "template",
  "template_name": "appointment_reminder",
  "language_code": "en",
  "template_params": {
    "header": ["Dr. Smith"],
    "body": ["January 25, 2024", "2:00 PM"]
  }
}
```

## Sending Session Messages (24-Hour Window)

If the customer has messaged you in the last 24 hours, you can send regular text messages:

```json
{
  "contact_id": 123,
  "message_type": "text",
  "message": "Thanks for your inquiry! How can I help you?"
}
```

**Note:** This will fail if more than 24 hours have passed since the customer's last message.

## Error Handling

### Common Errors

1. **Template Not Found**
   ```json
   {
     "error": "WhatsApp API error: Template 'hello_world' not found"
   }
   ```
   **Solution:** Ensure template is approved in WhatsApp Business Manager

2. **Invalid Phone Number**
   ```json
   {
     "error": "Contact does not have a phone number"
   }
   ```
   **Solution:** Ensure contact has a valid phone number

3. **Text Message Outside 24-Hour Window**
   ```json
   {
     "error": "WhatsApp API error: Message failed to send"
   }
   ```
   **Solution:** Use template messages for business-initiated communication

## Best Practices

1. **Always Use Templates for Business-Initiated Messages**
   - Don't rely on the 24-hour window
   - Templates are more reliable and professional

2. **Create Reusable Templates**
   - Common templates: order confirmations, appointment reminders, notifications
   - Keep template names consistent

3. **Handle Errors Gracefully**
   - Check if template exists before sending
   - Validate phone numbers
   - Log failed attempts

4. **Queue Messages**
   - Messages are automatically queued via `WhatsAppQueue`
   - Run the WhatsApp worker: `php cli/whatsapp_worker.php`

## Testing

Test your setup:

```bash
# Test sending a template message
php -r "
require 'vendor/autoload.php';
require 'public/index.php';
use CRM\Services\WhatsAppService;

\$service = new WhatsAppService();
\$result = \$service->sendTemplateMessage('+1234567890', 'hello_world', 'en');
print_r(\$result);
"
```

## Worker Process

Messages are queued and processed by the WhatsApp worker:

```bash
php cli/whatsapp_worker.php
```

Run this as a background service or cron job to process queued messages.

## Additional Resources

- [WhatsApp Business API Documentation](https://developers.facebook.com/docs/whatsapp)
- [Template Message Guide](https://developers.facebook.com/docs/whatsapp/business-platform/guide/messages/message-templates)
- API Documentation: See `docs/api.md` for full API reference
