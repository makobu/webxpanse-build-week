# CRM API Documentation

Complete REST API reference for the WebXpanse business operating platform.

## Base URL

```
http://your-domain.com/crm/api/
```

## Authentication

All API endpoints require authentication. Include your session cookie or use API key authentication (if implemented).

### Session-Based Authentication

Include your session cookie in requests (automatically handled by browsers).

### CSRF Protection

For POST, PUT, PATCH, and DELETE requests, include a CSRF token:

**Header:**
```
X-CSRF-Token: your-csrf-token
```

**Or in request body:**
```json
{
  "csrf_token": "your-csrf-token"
}
```

Get CSRF token from: `GET /crm/public/` (check meta tag or API endpoint)

---

## Contacts API

### Get All Contacts

```http
GET /api/contacts.php
```

**Query Parameters:**
- `limit` (optional) - Number of results (default: 50)
- `offset` (optional) - Pagination offset (default: 0)
- `stage` (optional) - Filter by stage (new, contacted, qualified, etc.)
- `search` (optional) - Search term

**Response:**
```json
{
  "results": [
    {
      "id": 1,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "first_name": "John",
      "last_name": "Doe",
      "email": "john.doe@example.com",
      "phone": "+1234567890",
      "company": "Acme Corp",
      "stage": "new",
      "lead_score": 45,
      "created_at": "2026-01-24 10:00:00"
    }
  ]
}
```

### Get Contact by ID

```http
GET /api/contacts.php?id=1
```

**Response:**
```json
{
  "id": 1,
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "first_name": "John",
  "last_name": "Doe",
  "email": "john.doe@example.com",
  "phone": "+1234567890",
  "company": "Acme Corp",
  "stage": "new",
  "lead_score": 45,
  "created_at": "2026-01-24 10:00:00"
}
```

### Get Contact by UUID

```http
GET /api/contacts.php?uuid=550e8400-e29b-41d4-a716-446655440000
```

### Create Contact

```http
POST /api/contacts.php
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "first_name": "Jane",
  "last_name": "Smith",
  "email": "jane.smith@example.com",
  "phone": "+1234567891",
  "company": "Tech Corp",
  "lead_source": "website",
  "stage": "new"
}
```

**Response:**
```json
{
  "status": "success",
  "id": 2,
  "uuid": "550e8400-e29b-41d4-a716-446655440001"
}
```

**Or if duplicate found:**
```json
{
  "status": "duplicate",
  "matches": {
    "email": [
      {
        "id": 1,
        "email": "jane.smith@example.com"
      }
    ]
  }
}
```

### Update Contact

```http
PUT /api/contacts.php?id=1
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "first_name": "John",
  "last_name": "Doe Updated",
  "stage": "contacted"
}
```

**Response:**
```json
{
  "success": true
}
```

### Delete Contact

```http
DELETE /api/contacts.php?id=1
X-CSRF-Token: your-csrf-token
```

**Response:**
```json
{
  "success": true
}
```

### Search Contacts

```http
GET /api/contacts.php?search=john
```

---

## Bulk Operations API

### Bulk Update Contacts

```http
POST /api/contacts_bulk.php
Content-Type: application/x-www-form-urlencoded
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```
action=bulk_update
contact_ids=[1,2,3]
stage=contacted
assigned_to=5
```

**Response:**
```json
{
  "success": true,
  "updated": 3
}
```

### Bulk Delete Contacts

```http
POST /api/contacts_bulk.php
Content-Type: application/x-www-form-urlencoded
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```
action=bulk_delete
contact_ids=[1,2,3]
```

**Response:**
```json
{
  "success": true,
  "deleted": 3
}
```

### Merge Contacts

```http
POST /api/contacts_bulk.php
Content-Type: application/x-www-form-urlencoded
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```
action=merge
source_id=1
target_id=2
field_preferences={"first_name":"target","email":"target"}
```

**Response:**
```json
{
  "success": true,
  "message": "Contacts merged successfully"
}
```

---

## Activities API

### Get Activities

```http
GET /api/activities.php
```

**Query Parameters:**
- `contact_id` (optional) - Filter by contact
- `type` (optional) - Filter by activity type
- `limit` (optional) - Number of results
- `offset` (optional) - Pagination offset

**Response:**
```json
{
  "results": [
    {
      "id": 1,
      "contact_id": 1,
      "user_id": 1,
      "activity_type": "contact_created",
      "description": "Contact created",
      "created_at": "2026-01-24 10:00:00"
    }
  ]
}
```

### Create Activity

```http
POST /api/activities.php
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "contact_id": 1,
  "activity_type": "note",
  "description": "Followed up with customer"
}
```

---

## Email Templates API

### Get All Templates

```http
GET /api/email_templates.php
```

**Response:**
```json
{
  "templates": [
    {
      "id": 1,
      "name": "Welcome Email",
      "slug": "welcome",
      "subject": "Welcome, {first_name}!",
      "category": "welcome",
      "is_active": 1
    }
  ]
}
```

### Get Template by ID

```http
GET /api/email_templates.php/1
```

### Create Template

```http
POST /api/email_templates.php
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "name": "Follow Up",
  "subject": "Hello {first_name}",
  "body_html": "<h1>Hello {first_name}!</h1>",
  "body_text": "Hello {first_name}!",
  "category": "follow_up",
  "variables": ["first_name", "last_name"],
  "is_active": 1
}
```

### Update Template

```http
PUT /api/email_templates.php/1
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

### Delete Template

```http
DELETE /api/email_templates.php/1
X-CSRF-Token: your-csrf-token
```

### Test Email Template

```http
POST /api/email_template_test.php
Content-Type: application/x-www-form-urlencoded
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```
template_id=1
test_email=test@example.com
```

**Response:**
```json
{
  "success": true,
  "message": "Test email sent successfully"
}
```

---

## Search API

### Global Search

```http
GET /api/search.php?q=john
```

**Query Parameters:**
- `q` (required) - Search query
- `limit` (optional) - Number of results (default: 10)

**Response:**
```json
{
  "results": [
    {
      "type": "contact",
      "id": 1,
      "title": "John Doe",
      "subtitle": "john.doe@example.com",
      "url": "/crm/public/contact_view.php?id=1"
    }
  ]
}
```

### Search Autocomplete

```http
GET /api/search.php?q=john&autocomplete=1
```

Returns quick suggestions for search queries.

---

## Inbox API

### Get Inbox Messages

```http
GET /api/inbox.php
```

**Query Parameters:**
- `status` (optional) - Filter by status (unread, read, all)
- `channel` (optional) - Filter by channel (email, whatsapp)
- `limit` (optional) - Number of results
- `offset` (optional) - Pagination offset

**Response:**
```json
{
  "messages": [
    {
      "id": 1,
      "channel": "email",
      "subject": "Hello",
      "from": "customer@example.com",
      "to": "support@example.com",
      "body": "Message content",
      "is_read": false,
      "created_at": "2026-01-24 10:00:00"
    }
  ]
}
```

### Mark Message as Read

```http
POST /api/inbox.php
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "action": "mark_read",
  "message_id": 1
}
```

---

## Notifications API

### Get Notifications

```http
GET /api/notifications.php
```

**Query Parameters:**
- `unread_only` (optional) - Only unread notifications (1 or 0)
- `limit` (optional) - Number of results
- `offset` (optional) - Pagination offset

**Response:**
```json
{
  "notifications": [
    {
      "id": 1,
      "type": "contact_created",
      "title": "New Contact Created",
      "message": "A new contact has been created",
      "is_read": false,
      "link": "/crm/public/contact_view.php?id=1",
      "created_at": "2026-01-24 10:00:00"
    }
  ],
  "unread_count": 5
}
```

### Mark Notification as Read

```http
POST /api/notifications.php
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "action": "mark_read",
  "notification_id": 1
}
```

### Mark All as Read

```http
POST /api/notifications.php
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "action": "mark_all_read"
}
```

---

## Custom Fields API

### Get Custom Fields

```http
GET /api/custom_fields.php
```

**Query Parameters:**
- `module` (optional) - Filter by module (contacts, tasks, etc.)

**Response:**
```json
{
  "fields": [
    {
      "id": 1,
      "module": "contacts",
      "field_name": "custom_field_1",
      "field_type": "text",
      "label": "Custom Field",
      "is_required": 0
    }
  ]
}
```

### Create Custom Field

```http
POST /api/custom_fields.php
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "module": "contacts",
  "field_type": "text",
  "label": "Custom Field",
  "is_required": 0
}
```

---

## Tracking API

### Track Page View

```http
GET /api/track/pageview.php?uuid=contact-uuid&url=/page&referrer=https://google.com
```

**Query Parameters:**
- `uuid` (required) - Contact UUID
- `url` (required) - Page URL
- `referrer` (optional) - Referrer URL
- `utm_source` (optional) - UTM source
- `utm_medium` (optional) - UTM medium
- `utm_campaign` (optional) - UTM campaign

### Track Form Submit

```http
POST /api/track/formsubmit.php
Content-Type: application/json
```

**Request Body:**
```json
{
  "uuid": "contact-uuid",
  "form_name": "contact_form",
  "form_data": {
    "name": "John Doe",
    "email": "john@example.com"
  }
}
```

### Track Email Open

```http
GET /api/track/email/open.php?token=tracking-token
```

### Track Email Click

```http
GET /api/track/email/click.php?token=tracking-token&url=https://example.com
```

---

## WhatsApp API

### Send WhatsApp Message

```http
POST /api/whatsapp_send.php
Content-Type: application/json
```

Send business-initiated WhatsApp messages to contacts.

**Important Notes:**
- **Business-initiated messages** require approved template messages (no 24-hour window restriction)
- **Session messages** (regular text) only work within 24 hours of customer's last message
- Templates must be pre-approved in WhatsApp Business Manager

**Request Body (Template Message - Business-Initiated):**
```json
{
  "contact_id": 123,
  "message_type": "template",
  "template_name": "hello_world",
  "language_code": "en",
  "template_params": {
    "body": ["John", "Acme Corp"],
    "header": ["Welcome!"],
    "buttons": ["button_payload"]
  }
}
```

**Request Body (Text Message - Session Only):**
```json
{
  "contact_id": 123,
  "message_type": "text",
  "message": "Hello! How can I help you today?"
}
```

**Alternative (using phone number directly):**
```json
{
  "phone_number": "+1234567890",
  "message_type": "template",
  "template_name": "hello_world",
  "language_code": "en"
}
```

**Parameters:**
- `contact_id` (optional) - Contact ID from CRM
- `phone_number` (optional) - Phone number (required if contact_id not provided)
- `message_type` (required) - `"template"` for business-initiated or `"text"` for session messages
- `template_name` (required for template) - Name of approved WhatsApp template
- `language_code` (optional) - Template language code (default: "en")
- `template_params` (optional) - Template parameters:
  - `body` - Array of body parameter values
  - `header` - Array of header parameter values
  - `buttons` - Array of button payloads
- `message` (required for text) - Message text content

**Response (Success):**
```json
{
  "status": "success",
  "message": "Template message sent successfully",
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "whatsapp_message_id": "wamid.xxx",
  "note": "Template messages are business-initiated and can be sent anytime (no 24-hour window restriction)"
}
```

**Response (Error):**
```json
{
  "error": "template_name is required for business-initiated messages",
  "note": "You must use an approved WhatsApp template. Regular text messages only work within 24 hours of customer's last message."
}
```

**Example Usage:**

```bash
# Send business-initiated template message
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

# Send session text message (within 24-hour window)
curl -X POST https://your-domain.com/crm/api/whatsapp_send.php \
  -H "Content-Type: application/json" \
  -H "Cookie: PHPSESSID=your-session-id" \
  -d '{
    "contact_id": 123,
    "message_type": "text",
    "message": "Thanks for your inquiry!"
  }'
```

---

## Webhooks

### WhatsApp Webhook

```http
POST /api/webhooks/whatsapp.php
```

Receives WhatsApp Business API webhooks for incoming messages.

---

## Health Check

### System Health

```http
GET /api/health.php
```

**Response:**
```json
{
  "status": "healthy",
  "database": "connected",
  "cache": "connected",
  "timestamp": "2026-01-24T10:00:00Z"
}
```

---

## Error Responses

All endpoints may return error responses in the following format:

```json
{
  "error": "Error message description"
}
```

### HTTP Status Codes

- `200 OK` - Success
- `201 Created` - Resource created
- `400 Bad Request` - Invalid request
- `401 Unauthorized` - Authentication required
- `403 Forbidden` - Invalid CSRF token or insufficient permissions
- `404 Not Found` - Resource not found
- `405 Method Not Allowed` - HTTP method not supported
- `500 Internal Server Error` - Server error

---

## Rate Limiting

API endpoints may implement rate limiting. Check response headers:
- `X-RateLimit-Limit` - Request limit
- `X-RateLimit-Remaining` - Remaining requests
- `X-RateLimit-Reset` - Reset time

---

## Examples

### JavaScript (Fetch API)

```javascript
// Get contacts
fetch('/crm/api/contacts.php', {
  method: 'GET',
  credentials: 'include'
})
.then(response => response.json())
.then(data => console.log(data));

// Create contact
fetch('/crm/api/contacts.php', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': getCsrfToken()
  },
  credentials: 'include',
  body: JSON.stringify({
    first_name: 'John',
    email: 'john@example.com'
  })
})
.then(response => response.json())
.then(data => console.log(data));
```

### cURL

```bash
# Get contacts
curl -X GET "http://localhost/crm/api/contacts.php" \
  -H "Cookie: PHPSESSID=your-session-id"

# Create contact
curl -X POST "http://localhost/crm/api/contacts.php" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: your-csrf-token" \
  -H "Cookie: PHPSESSID=your-session-id" \
  -d '{
    "first_name": "John",
    "email": "john@example.com"
  }'
```

### PHP

```php
// Get contacts
$ch = curl_init('http://localhost/crm/api/contacts.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIE, 'PHPSESSID=your-session-id');
$response = curl_exec($ch);
$contacts = json_decode($response, true);
```

---

## Calendar API

### Export Calendar (iCal)

```http
GET /api/calendar/export.php
```

**Query Parameters:**
- `start_date` (optional) - Start date (YYYY-MM-DD)
- `end_date` (optional) - End date (YYYY-MM-DD)
- `user_id` (optional) - Filter by user

**Response:**
Returns an `.ics` file (iCal format) that can be imported into calendar applications.

---

## Webhooks API

### Webhook Test Endpoint

```http
POST /api/webhook_test.php
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "webhook_id": 1,
  "test_data": {
    "event": "contact.created",
    "data": {
      "id": 1,
      "email": "test@example.com"
    }
  }
}
```

**Response:**
```json
{
  "success": true,
  "status_code": 200,
  "response_body": "...",
  "execution_time": 0.123
}
```

### SMS Webhook

```http
POST /api/webhooks/sms.php
Content-Type: application/json
```

**Request Body:**
```json
{
  "MessageSid": "SM123456789",
  "From": "+1234567890",
  "To": "+0987654321",
  "Body": "Hello",
  "Status": "received"
}
```

**Response:**
```json
{
  "success": true
}
```

### WhatsApp Webhook

```http
POST /api/webhooks/whatsapp.php
Content-Type: application/json
```

**Request Body:**
```json
{
  "entry": [{
    "changes": [{
      "value": {
        "messages": [{
          "from": "1234567890",
          "text": {
            "body": "Hello"
          }
        }]
      }
    }]
  }]
}
```

**Response:**
```json
{
  "success": true
}
```

---

## Search API

### Global Search

```http
GET /api/search.php?q=search+term
```

**Query Parameters:**
- `q` (required) - Search query
- `type` (optional) - Filter by entity type (contacts, tasks, events, deals)
- `limit` (optional) - Number of results per type

**Response:**
```json
{
  "contacts": [
    {
      "id": 1,
      "first_name": "John",
      "last_name": "Doe",
      "email": "john@example.com"
    }
  ],
  "tasks": [],
  "events": [],
  "deals": []
}
```

---

## Health Check API

### System Health

```http
GET /api/health.php
```

**Response:**
```json
{
  "status": "healthy",
  "database": "connected",
  "cache": "connected",
  "timestamp": "2026-01-24T10:00:00Z"
}
```

---

## Email Templates API

### Get All Templates

```http
GET /api/email_templates.php
```

**Response:**
```json
{
  "results": [
    {
      "id": 1,
      "name": "Welcome Email",
      "subject": "Welcome {first_name}!",
      "body": "<html>...</html>",
      "variables": ["first_name", "last_name", "email"]
    }
  ]
}
```

### Get Template by ID

```http
GET /api/email_templates.php?id=1
```

### Create Template

```http
POST /api/email_templates.php
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "name": "Welcome Email",
  "subject": "Welcome {first_name}!",
  "body": "<html><body>Hello {first_name}!</body></html>",
  "variables": ["first_name"]
}
```

### Update Template

```http
PUT /api/email_templates.php?id=1
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

### Delete Template

```http
DELETE /api/email_templates.php?id=1
X-CSRF-Token: your-csrf-token
```

### Test Template

```http
POST /api/email_template_test.php
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "template_id": 1,
  "test_email": "test@example.com",
  "test_data": {
    "first_name": "John",
    "last_name": "Doe"
  }
}
```

---

## Custom Fields API

### Get Custom Fields

```http
GET /api/custom_fields.php?module=contacts
```

**Query Parameters:**
- `module` (required) - Module name (contacts, tasks, events, deals)

**Response:**
```json
{
  "results": [
    {
      "id": 1,
      "name": "Industry",
      "type": "dropdown",
      "options": ["Technology", "Healthcare", "Finance"],
      "required": false
    }
  ]
}
```

### Create Custom Field

```http
POST /api/custom_fields.php
Content-Type: application/json
X-CSRF-Token: your-csrf-token
```

**Request Body:**
```json
{
  "module": "contacts",
  "name": "Industry",
  "type": "dropdown",
  "options": ["Technology", "Healthcare", "Finance"],
  "required": false
}
```

---

## API Versioning

Current API version: **v1**

API endpoints may change in future versions. Check version headers in responses.

---

## API Authentication (API Keys)

### Using API Keys

Include the API key in the Authorization header:

```http
Authorization: Bearer your-api-key-here
```

### API Key Permissions

API keys can have specific permissions:
- `contacts.read` - Read contacts
- `contacts.write` - Create/update contacts
- `contacts.delete` - Delete contacts
- `emails.send` - Send emails
- `reports.read` - View reports
- `admin` - Full access

### Rate Limiting with API Keys

API keys have configurable rate limits:
- Default: 60 requests per minute
- Check `X-RateLimit-*` headers in responses

---

*Last Updated: 2026-01-24*
