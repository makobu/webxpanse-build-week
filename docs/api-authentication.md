# API Authentication Guide

Complete guide for authenticating with the CRM API.

## Table of Contents

1. [Overview](#overview)
2. [Session-Based Authentication](#session-based-authentication)
3. [API Key Authentication](#api-key-authentication)
4. [CSRF Protection](#csrf-protection)
5. [Examples](#examples)
6. [Error Handling](#error-handling)
7. [Best Practices](#best-practices)

---

## Overview

The CRM API supports two authentication methods:

1. **Session-Based Authentication** - For web applications and browser-based clients
2. **API Key Authentication** - For programmatic access and third-party integrations

All state-changing operations (POST, PUT, PATCH, DELETE) require CSRF token validation.

---

## Session-Based Authentication

### How It Works

Session-based authentication uses PHP sessions and cookies. When you log in through the web interface, a session is created and a session cookie is set.

### Using Session Authentication

1. **Login via web interface**:
   ```http
   POST /crm/public/login.php
   Content-Type: application/x-www-form-urlencoded
   
   email=user@example.com&password=yourpassword
   ```

2. **Session cookie is automatically set** by the browser

3. **Include session cookie in API requests**:
   ```http
   GET /crm/api/contacts.php
   Cookie: PHPSESSID=your-session-id
   ```

### Browser-Based Requests

Browsers automatically include cookies, so no special handling is needed:

```javascript
// Cookies are automatically included
fetch('/crm/api/contacts.php')
  .then(response => response.json())
  .then(data => console.log(data));
```

### cURL Example

```bash
# First, login and save cookies
curl -c cookies.txt -X POST "https://your-domain.com/crm/public/login.php" \
  -d "email=user@example.com&password=yourpassword"

# Use saved cookies for API requests
curl -b cookies.txt "https://your-domain.com/crm/api/contacts.php"
```

### Session Lifetime

- Default session lifetime: 2 hours (7200 seconds)
- Configurable in `.env`: `SESSION_LIFETIME=7200`
- Session expires on inactivity
- Session can be extended by making authenticated requests

---

## API Key Authentication

### Creating an API Key

1. **Login to CRM** as admin
2. Navigate to **Admin → More → API Keys**
3. Click **"+ New API Key"**
4. Configure:
   - **Name**: Descriptive name for the key
   - **Expiration Date**: Optional expiration date
   - **Rate Limit**: Requests per minute (default: 60)
   - **Permissions**: Select allowed operations
5. Click **"Generate Key"**
6. **Copy the key immediately** - It won't be shown again!

### Using API Keys

Include the API key in the `Authorization` header:

```http
Authorization: Bearer your-api-key-here
```

### Example Request

```bash
curl -X GET "https://your-domain.com/crm/api/contacts.php" \
  -H "Authorization: Bearer sk_live_abc123xyz789"
```

### JavaScript Example

```javascript
fetch('/crm/api/contacts.php', {
  headers: {
    'Authorization': 'Bearer sk_live_abc123xyz789'
  }
})
  .then(response => response.json())
  .then(data => console.log(data));
```

### PHP Example

```php
$ch = curl_init('https://your-domain.com/crm/api/contacts.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer sk_live_abc123xyz789'
]);
$response = curl_exec($ch);
$contacts = json_decode($response, true);
```

### Python Example

```python
import requests

headers = {
    'Authorization': 'Bearer sk_live_abc123xyz789'
}

response = requests.get(
    'https://your-domain.com/crm/api/contacts.php',
    headers=headers
)
contacts = response.json()
```

### API Key Permissions

API keys can have specific permissions:

- `contacts.read` - Read contacts
- `contacts.write` - Create/update contacts
- `contacts.delete` - Delete contacts
- `emails.send` - Send emails
- `reports.read` - View reports
- `admin` - Full access (all permissions)

### Rate Limiting

API keys have configurable rate limits:

- **Default**: 60 requests per minute
- **Check headers** in responses:
  ```
  X-RateLimit-Limit: 60
  X-RateLimit-Remaining: 59
  X-RateLimit-Reset: 1640995200
  ```

### Rate Limit Exceeded

When rate limit is exceeded:

**Status Code**: `429 Too Many Requests`

**Response**:
```json
{
  "error": "Rate limit exceeded",
  "retry_after": 60
}
```

**Handling**:
```javascript
if (response.status === 429) {
  const retryAfter = response.headers.get('Retry-After');
  await sleep(retryAfter * 1000);
  // Retry request
}
```

---

## CSRF Protection

### When CSRF Protection is Required

CSRF tokens are required for:
- POST requests (create operations)
- PUT requests (update operations)
- PATCH requests (partial updates)
- DELETE requests (delete operations)

CSRF tokens are **NOT required** for:
- GET requests (read operations)

### Getting a CSRF Token

**Method 1: From Meta Tag** (Web Interface)
```html
<meta name="csrf-token" content="abc123xyz789">
```

**Method 2: From API Endpoint** (Future implementation)
```http
GET /crm/api/csrf-token.php
```

**Method 3: From Login Response** (Session-based)
The CSRF token is included in the login response or can be retrieved from the session.

### Using CSRF Tokens

**Option 1: Header** (Recommended)
```http
POST /crm/api/contacts.php
Content-Type: application/json
X-CSRF-Token: abc123xyz789
Authorization: Bearer sk_live_abc123xyz789
```

**Option 2: Request Body**
```json
{
  "csrf_token": "abc123xyz789",
  "first_name": "John",
  "email": "john@example.com"
}
```

### CSRF Token Lifetime

- Default lifetime: 1 hour (3600 seconds)
- Configurable in `.env`: `CSRF_TOKEN_LIFETIME=3600`
- Token expires after lifetime or on logout

### CSRF Token Errors

**Status Code**: `403 Forbidden`

**Response**:
```json
{
  "error": "Invalid CSRF token"
}
```

**Solution**: Get a new CSRF token and retry the request.

---

## Examples

### Complete Example: Create Contact with API Key

```bash
# Step 1: Get CSRF token (if needed)
# For API keys, CSRF may be optional depending on configuration

# Step 2: Create contact
curl -X POST "https://your-domain.com/crm/api/contacts.php" \
  -H "Authorization: Bearer sk_live_abc123xyz789" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: abc123xyz789" \
  -d '{
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@example.com",
    "phone": "+1234567890"
  }'
```

### Complete Example: Update Contact with Session

```javascript
// Get CSRF token from meta tag
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;

// Update contact
fetch('/crm/api/contacts.php?id=1', {
  method: 'PUT',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-Token': csrfToken
  },
  credentials: 'include', // Include session cookie
  body: JSON.stringify({
    first_name: 'Jane',
    last_name: 'Smith'
  })
})
  .then(response => response.json())
  .then(data => console.log(data));
```

### Complete Example: Delete Contact with API Key

```python
import requests

api_key = 'sk_live_abc123xyz789'
contact_id = 1

headers = {
    'Authorization': f'Bearer {api_key}',
    'X-CSRF-Token': 'abc123xyz789'  # Get from token endpoint
}

response = requests.delete(
    f'https://your-domain.com/crm/api/contacts.php?id={contact_id}',
    headers=headers
)

if response.status_code == 200:
    print('Contact deleted successfully')
else:
    print(f'Error: {response.json()}')
```

---

## Error Handling

### Authentication Errors

**401 Unauthorized**:
```json
{
  "error": "Authentication required",
  "message": "Please login or provide valid API key"
}
```

**403 Forbidden**:
```json
{
  "error": "Invalid CSRF token"
}
```
or
```json
{
  "error": "Insufficient permissions"
}
```

### Handling Errors

```javascript
async function apiRequest(url, options = {}) {
  const response = await fetch(url, {
    ...options,
    headers: {
      'Authorization': `Bearer ${apiKey}`,
      'Content-Type': 'application/json',
      ...options.headers
    }
  });
  
  if (response.status === 401) {
    // Re-authenticate
    throw new Error('Authentication required');
  }
  
  if (response.status === 403) {
    // Get new CSRF token or check permissions
    throw new Error('Permission denied');
  }
  
  if (response.status === 429) {
    // Rate limit exceeded
    const retryAfter = response.headers.get('Retry-After');
    throw new Error(`Rate limit exceeded. Retry after ${retryAfter} seconds`);
  }
  
  if (!response.ok) {
    const error = await response.json();
    throw new Error(error.message || 'API request failed');
  }
  
  return response.json();
}
```

---

## Best Practices

### Security

1. **Never expose API keys** in client-side code
2. **Use HTTPS** for all API requests
3. **Rotate API keys** regularly
4. **Use least privilege** - Only grant necessary permissions
5. **Monitor API key usage** for suspicious activity
6. **Set expiration dates** on API keys
7. **Revoke compromised keys** immediately

### Performance

1. **Cache CSRF tokens** (they're valid for 1 hour)
2. **Respect rate limits** - Implement exponential backoff
3. **Use pagination** for large datasets
4. **Batch requests** when possible
5. **Use appropriate HTTP methods** (GET for reads, POST for creates)

### Error Handling

1. **Always check status codes**
2. **Handle rate limiting** gracefully
3. **Implement retry logic** with exponential backoff
4. **Log authentication errors** for debugging
5. **Provide user-friendly error messages**

### Code Organization

1. **Create API client class** to encapsulate authentication
2. **Centralize error handling**
3. **Use environment variables** for API keys
4. **Implement request/response logging** (development)
5. **Add request timeouts**

### Example API Client Class

```php
<?php

class CRMAPIClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $csrfToken;
    
    public function __construct(string $baseUrl, string $apiKey)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
    }
    
    public function get(string $endpoint, array $params = []): array
    {
        $url = $this->baseUrl . $endpoint;
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        return $this->request('GET', $url);
    }
    
    public function post(string $endpoint, array $data): array
    {
        return $this->request('POST', $this->baseUrl . $endpoint, $data);
    }
    
    private function request(string $method, string $url, array $data = []): array
    {
        $ch = curl_init($url);
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];
        
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) {
            if (empty($this->csrfToken)) {
                $this->refreshCsrfToken();
            }
            $headers[] = 'X-CSRF-Token: ' . $this->csrfToken;
        }
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);
        
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            $error = json_decode($response, true);
            throw new \Exception($error['error'] ?? 'API request failed');
        }
        
        return json_decode($response, true);
    }
    
    private function refreshCsrfToken(): void
    {
        // Get CSRF token from token endpoint or session
        // Implementation depends on your setup
    }
}
```

---

## Troubleshooting

### "Authentication required" Error

**Causes**:
- API key is missing or invalid
- Session has expired
- API key has been revoked

**Solutions**:
1. Verify API key is correct
2. Check API key hasn't expired
3. Verify API key has required permissions
4. Re-authenticate if using sessions

### "Invalid CSRF token" Error

**Causes**:
- CSRF token is missing
- CSRF token has expired
- CSRF token is incorrect

**Solutions**:
1. Get a new CSRF token
2. Verify token is included in header or body
3. Check token hasn't expired
4. Ensure token matches session (for session auth)

### Rate Limit Exceeded

**Causes**:
- Too many requests in short time
- Rate limit set too low

**Solutions**:
1. Implement exponential backoff
2. Reduce request frequency
3. Request rate limit increase (if needed)
4. Use batch endpoints when available

---

## API Key Management

### Viewing API Keys

Navigate to **Admin → More → API Keys** to:
- View all API keys
- See usage statistics
- Check expiration dates
- View permissions

### Revoking API Keys

1. Navigate to API Keys page
2. Click **"Revoke"** next to the key
3. Confirm revocation
4. Key is immediately invalidated

### Regenerating API Keys

API keys cannot be regenerated. If you need a new key:
1. Revoke the old key
2. Create a new API key
3. Update your applications with the new key

---

## Testing Authentication

### Test Session Authentication

```bash
# Login
curl -c cookies.txt -X POST "https://your-domain.com/crm/public/login.php" \
  -d "email=test@example.com&password=password"

# Test authenticated request
curl -b cookies.txt "https://your-domain.com/crm/api/contacts.php"
```

### Test API Key Authentication

```bash
curl -X GET "https://your-domain.com/crm/api/contacts.php" \
  -H "Authorization: Bearer your-api-key"
```

### Test CSRF Protection

```bash
# This should fail without CSRF token
curl -X POST "https://your-domain.com/crm/api/contacts.php" \
  -H "Authorization: Bearer your-api-key" \
  -H "Content-Type: application/json" \
  -d '{"first_name":"Test"}'

# This should succeed with CSRF token
curl -X POST "https://your-domain.com/crm/api/contacts.php" \
  -H "Authorization: Bearer your-api-key" \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: your-csrf-token" \
  -d '{"first_name":"Test"}'
```

---

*Last Updated: 2026-01-24*
