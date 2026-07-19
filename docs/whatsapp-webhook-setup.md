# WhatsApp Webhook and Inbox Configuration Guide

## Overview

This guide will help you configure WhatsApp webhooks to receive incoming messages and status updates, which will automatically appear in your CRM inbox.

## Prerequisites

1. WhatsApp Business API account set up in Meta Business Manager
2. Phone Number ID and Access Token configured in Settings
3. Your CRM accessible via HTTPS (required for webhooks)

## Step 1: Configure Webhook Verify Token

1. Go to **Settings > WhatsApp** in your CRM
2. Set a **Webhook Verify Token** (e.g., `my_secure_token_123`)
3. Save the settings
4. **Copy the Webhook URL** shown on the settings page

## Step 2: Configure Webhook in Meta Business Manager

1. Go to [Meta Business Manager](https://business.facebook.com)
2. Navigate to **WhatsApp > Configuration > Webhooks**
3. Click **"Edit"** on your webhook or **"Add Webhook"**
4. Enter the following:
   - **Callback URL**: Your webhook URL from Settings (e.g., `https://your-domain.com/crm/api/webhooks/whatsapp.php`)
   - **Verify Token**: The same token you set in Settings
5. Click **"Verify and Save"**
   - Meta will send a GET request to verify the webhook
   - If verification succeeds, you'll see a success message

## Step 3: Subscribe to Webhook Events

After verification, subscribe to this event:

1. **messages** - Receives incoming messages from customers and delivery status updates (sent, delivered, read, failed) in the same webhook

To subscribe:
1. In Meta Business Manager, go to **WhatsApp > Configuration > Webhooks**
2. Click **"Manage"** on your webhook
3. Under **"Subscription Fields"**, select:
   - ✅ `messages`
4. Click **"Save"**

## Step 4: Test the Webhook

1. Send a test message to your WhatsApp Business number from a registered test number
2. Check your CRM **Inbox** - the message should appear automatically
3. Check **WhatsApp Messages** page to see the message details

## How It Works

### Incoming Messages

When a customer sends a WhatsApp message:

1. Meta sends a webhook POST request to `/api/webhooks/whatsapp.php`
2. The webhook handler:
   - Creates or finds the contact by phone number
   - Stores the message in `whatsapp_messages` table
   - Adds it to the unified `communications` table (inbox)
   - Logs an activity for the contact

3. The message appears in:
   - **Inbox** (`/crm/public/inbox.php`) - Unified inbox with all channels
   - **WhatsApp Messages** (`/crm/public/whatsapp_messages.php`) - WhatsApp-specific view

### Status Updates

When a message status changes (sent, delivered, read, failed):

1. Meta sends a status update webhook
2. The system updates:
   - Message status in `whatsapp_messages` table
   - Corresponding entry in `communications` table
   - Timestamps (sent_at, delivered_at, read_at)

## Troubleshooting

### Webhook Verification Fails

**Issue**: Meta can't verify your webhook

**Solutions**:
1. Ensure your webhook URL is accessible via HTTPS
2. Check that the Verify Token matches exactly (case-sensitive)
3. Verify your server can receive GET requests
4. Check server logs for errors

### Messages Not Appearing in Inbox

**Issue**: Webhook receives messages but they don't show in inbox

**Solutions**:
1. Check that webhook is subscribed to `messages` event
2. Verify the webhook handler is processing correctly (check logs)
3. Ensure the `communications` table exists and has correct structure
4. Check database connection

### Status Updates Not Working

**Issue**: Message statuses not updating

**Solutions**:
1. Ensure webhook is subscribed to `messages` event (delivery status is included)
2. Check that `whatsapp_message_id` is stored correctly when sending
3. Verify webhook handler is processing status updates

## Webhook Endpoint Details

**URL**: `/crm/api/webhooks/whatsapp.php`

**GET Request** (Verification):
- Meta sends: `GET /api/webhooks/whatsapp.php?hub.mode=subscribe&hub.verify_token=YOUR_TOKEN&hub.challenge=RANDOM_STRING`
- Your server responds: The `hub.challenge` value

**POST Request** (Events):
- Meta sends: JSON payload with message or status data
- Your server responds: `{"success": true}` with HTTP 200

## Security Considerations

1. **Verify Token**: Use a strong, random token
2. **HTTPS Only**: Webhooks require HTTPS in production
3. **Signature Verification**: Consider implementing HMAC signature verification (future enhancement)

## Testing Webhook Locally

For local development, use a tool like:
- **ngrok**: `ngrok http 80` to expose your local server
- **localtunnel**: `lt --port 80` for temporary public URL

Then use the ngrok/localtunnel URL as your webhook URL in Meta Business Manager.

## Next Steps

After configuring webhooks:

1. ✅ Test receiving messages
2. ✅ Verify messages appear in Inbox
3. ✅ Check status updates work
4. ✅ Set up automated responses (workflows)
5. ✅ Configure notifications for new messages

## Support

If you encounter issues:
1. Check the webhook logs in Meta Business Manager
2. Review server error logs
3. Test webhook endpoint manually
4. Verify all settings are correct
