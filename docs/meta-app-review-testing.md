# Meta App Review Testing Guide

This document provides information about the API endpoints created for Meta App Review testing. These endpoints make Graph API calls required for testing various Meta permissions.

## Overview

The following Meta permissions need to be tested for App Review:
- `whatsapp_business_manage_events` - Webhook subscription management
- `manage_app_solution` - App configuration management
- `email` - Email access
- `business_management` - Business account management

## API Endpoints

### 1. Webhook Subscription Management (`whatsapp_business_manage_events`)

#### Subscribe to Webhook Events
**Endpoint:** `POST /crm/api/whatsapp/webhooks/subscribe.php`

**Request Body:**
```json
{
  "object": "whatsapp_business_account",
  "callback_url": "https://your-domain.com/crm/api/webhooks/whatsapp.php",
  "fields": ["messages"]
}
```

**Response:**
```json
{
  "success": true,
  "message": "Webhook subscription created successfully",
  "data": { ... }
}
```

**Graph API Call:** `POST /{app-id}/subscriptions`

#### List Webhook Subscriptions
**Endpoint:** `GET /crm/api/whatsapp/webhooks/list.php`

**Response:**
```json
{
  "success": true,
  "subscriptions": [...],
  "count": 1
}
```

**Graph API Call:** `GET /{app-id}/subscriptions`

#### Unsubscribe from Webhook Events
**Endpoint:** `POST /crm/api/whatsapp/webhooks/unsubscribe.php`

**Request Body:**
```json
{
  "object": "whatsapp_business_account"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Webhook unsubscribed successfully",
  "data": { ... }
}
```

**Graph API Call:** `DELETE /{app-id}/subscriptions`

### 2. App Configuration Management (`manage_app_solution`)

#### Get App Configuration
**Endpoint:** `GET /crm/api/whatsapp/app/config.php`

**Query Parameters:**
- `fields` (optional): Comma-separated list of fields (default: name,category,link,privacy_policy_url)

**Response:**
```json
{
  "success": true,
  "app_config": {
    "name": "App Name",
    "category": "Business",
    "link": "https://...",
    "privacy_policy_url": "https://..."
  }
}
```

**Graph API Call:** `GET /{app-id}?fields=name,category,link,privacy_policy_url`

#### Update App Configuration
**Endpoint:** `POST /crm/api/whatsapp/app/update.php`

**Request Body:**
```json
{
  "name": "Updated App Name",
  "privacy_policy_url": "https://..."
}
```

**Response:**
```json
{
  "success": true,
  "message": "App configuration updated successfully",
  "data": { ... }
}
```

**Graph API Call:** `POST /{app-id}`

### 3. Email Permission (`email`)

#### Get User Email
**Endpoint:** `GET /crm/api/whatsapp/email/verify.php`

**Response:**
```json
{
  "success": true,
  "email": "user@example.com",
  "data": {
    "email": "user@example.com"
  }
}
```

**Graph API Call:** `GET /me?fields=email`

### 4. Business Management (`business_management`)

**Important: Business ID vs WABA ID**
- **Business ID** = Meta Business Manager account (from `GET /me/businesses`). Used for `business/get.php` and for discovering WABAs via `/{business-id}/owned_whatsapp_business_accounts`.
- **WABA ID** = WhatsApp Business Account ID (WhatsAppBusinessAccount node). Used for `phone-numbers.php`, templates, and messaging. Set as `WHATSAPP_BUSINESS_ACCOUNT_ID` in .env.

#### List Business Accounts
**Endpoint:** `GET /crm/api/whatsapp/business/list.php`

**Response:**
```json
{
  "success": true,
  "businesses": [...],
  "count": 1
}
```

**Graph API Call:** `GET /me/businesses?fields=id,name,timezone_id`

**Required scope:** `business_management`. If you see "(#100) Missing Permission", the token is missing this scope.

#### Get Business Account Details
**Endpoint:** `GET /crm/api/whatsapp/business/get.php`

**Query Parameters:**
- `business_id` (required): Business Manager ID from the list endpoint above. Do **not** use WABA ID here.
- `fields` (optional): Comma-separated list of fields (default: id,name,timezone_id)

**Response:**
```json
{
  "success": true,
  "business": {
    "id": "123456789",
    "name": "Business Name",
    "timezone_id": "America/New_York"
  }
}
```

**Graph API Call:** `GET /{business-id}?fields=id,name,timezone_id`

#### WABA-specific: List Phone Numbers
**Endpoint:** `GET /crm/api/whatsapp/business/phone-numbers.php`

**Query Parameters:**
- `waba_id` (required): WhatsApp Business Account ID (WABA), or set `WHATSAPP_BUSINESS_ACCOUNT_ID` in .env

**Response:**
```json
{
  "success": true,
  "phone_numbers": [...],
  "count": 1
}
```

**Graph API Call:** `GET /{waba-id}/phone_numbers?fields=id,display_phone_number,verified_name,quality_rating,code_verification_status,platform_type`

### 5. Comprehensive Test Endpoint

**Endpoint:** `GET /crm/api/whatsapp/test-permissions.php`

This endpoint tests all permissions at once and returns a comprehensive report.

**Response:**
```json
{
  "success": true,
  "summary": {
    "total_tests": 6,
    "passed": 5,
    "failed": 1
  },
  "results": {
    "whatsapp_business_manage_events": {
      "list_webhooks": {
        "success": true,
        "data": {...}
      }
    },
    "manage_app_solution": {
      "get_app_config": {
        "success": true,
        "data": {...}
      }
    },
    "email": {
      "get_user_email": {
        "success": true,
        "data": {...}
      }
    },
    "business_management": {
      "list_businesses": {
        "success": true,
        "data": {...}
      },
      "get_business": {
        "success": true,
        "data": {...}
      },
      "list_phone_numbers": {
        "success": true,
        "data": {...}
      }
    }
  },
  "timestamp": "2026-02-07T12:00:00+00:00"
}
```

## Configuration

### Required Environment Variables

Add these to your `.env` file:

```env
# Meta App ID (required for webhook and app management)
META_APP_ID=123456789012345

# WhatsApp Access Token (required for all API calls)
WHATSAPP_ACCESS_TOKEN=EAAxxxxxxxxxxxxx

# WhatsApp Business Account ID (required for business management)
WHATSAPP_BUSINESS_ACCOUNT_ID=123456789012345

# Webhook Verify Token (required for webhook subscription)
WHATSAPP_VERIFY_TOKEN=your_secure_token_here
```

### Finding Your Meta App ID

1. Go to [Meta App Dashboard](https://developers.facebook.com/apps/)
2. Select your app
3. Go to Settings → Basic
4. Copy the "App ID"

## Testing from Graph API Explorer

You can also test these endpoints directly from the [Graph API Explorer](https://developers.facebook.com/tools/explorer/):

1. Select your app
2. Generate an access token with the required permissions
3. Make the API calls as documented above

## Testing from Settings Page

1. Go to **Settings > WhatsApp** in your CRM
2. Click **"Test All Permissions"** button
3. Review the test results displayed on the page
4. Or click **"Open Test Endpoint"** to view the full JSON response

## Authentication

All endpoints require:
- User authentication (logged in)
- Admin role

## Error Handling

All endpoints return proper HTTP status codes:
- `200` - Success
- `400` - Bad Request (missing parameters, invalid data)
- `401` - Unauthorized (not logged in)
- `403` - Forbidden (not admin)
- `405` - Method Not Allowed
- `500` - Internal Server Error

Error responses include:
```json
{
  "success": false,
  "error": "Error message here"
}
```

## Notes for Meta Reviewers

1. All endpoints make actual Graph API calls to Meta's servers
2. The test endpoint (`/api/whatsapp/test-permissions.php`) can be used to verify all permissions at once
3. Each permission has dedicated endpoints that can be tested individually
4. All endpoints require proper authentication and admin access
5. The endpoints log API calls for debugging purposes

## Troubleshooting

### "META_APP_ID is required" Error
- Ensure `META_APP_ID` is set in your `.env` file
- You can set it in Settings > WhatsApp > Meta App ID

### "Authentication required" Error
- Make sure you're logged in
- Check that your session is valid

### "Admin access required" Error
- Ensure your user has admin role
- Check user permissions in the database

### "(#100) Missing Permission" / Graph API error 100
- This indicates **token scope**, not application code. Ensure the token includes the required permission for that call:
  - `whatsapp_business_manage_events` – webhook list/subscribe/unsubscribe
  - `manage_app_solution` – app config get/update
  - `email` – user email
  - `business_management` – list businesses, get business, list WABA phone numbers
- Generate a new token in Meta Business Manager or [Graph API Explorer](https://developers.facebook.com/tools/explorer/) with the needed permissions.

### API Call Failures
- Verify your `WHATSAPP_ACCESS_TOKEN` is valid and not expired
- Check that the token has the required permissions (see above)
- Review server error logs for detailed error messages
