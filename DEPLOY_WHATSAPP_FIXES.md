# WhatsApp Incoming Messages - Deployment Checklist

## Files Changed

### 1. `services/WhatsAppWebhook.php`
**Fixes:**
- ✅ Phone number normalization (removes +, spaces, dashes for matching)
- ✅ Contact creation with required email field (placeholder email)
- ✅ Status field set to 'delivered' (valid ENUM value)
- ✅ Debug logging added for troubleshooting

**Key Changes:**
- Line ~137: Phone number normalization
- Line ~154-159: Improved contact lookup with normalized phone
- Line ~177-185: Contact creation with placeholder email
- Line ~264: Status set to 'delivered' instead of invalid 'unread'

### 2. `public/whatsapp_messages.php`
**Fixes:**
- ✅ Now shows both inbound and outbound messages
- ✅ Added filter buttons (All, Incoming, Sent)
- ✅ Displays "From" for inbound, "To" for outbound

**Key Changes:**
- Line ~41: Removed hardcoded `direction = 'outbound'` filter
- Line ~39: Added `$direction` filter parameter
- Added filter UI buttons

## Deployment Steps

1. **Backup current files** (recommended):
   ```bash
   cp services/WhatsAppWebhook.php services/WhatsAppWebhook.php.backup
   cp public/whatsapp_messages.php public/whatsapp_messages.php.backup
   ```

2. **Upload changed files to live server:**
   - `services/WhatsAppWebhook.php`
   - `public/whatsapp_messages.php`

3. **Verify webhook endpoint is accessible:**
   - Test: `https://crm.makdennis.dev/api/webhooks/whatsapp.php?hub.mode=subscribe&hub.verify_token=YOUR_TOKEN&hub.challenge=test123`
   - Should return: `test123`

4. **Check webhook configuration in Meta Business Manager:**
   - Go to: Meta Business Manager → WhatsApp → Configuration → Webhooks
   - Verify webhook URL: `https://crm.makdennis.dev/api/webhooks/whatsapp.php`
   - Verify subscribed to: `messages` events
   - Verify token matches `WHATSAPP_VERIFY_TOKEN` in `.env`

5. **Test incoming message processing:**
   - Send a test WhatsApp message to your business number
   - Check inbox: `https://crm.makdennis.dev/public/inbox.php?channel=whatsapp`
   - Check WhatsApp messages: `https://crm.makdennis.dev/public/whatsapp_messages.php?direction=inbound`

6. **Monitor debug logs** (if needed):
   - Check: `.cursor/debug.log` on server
   - Or server error logs: `/var/log/apache2/error.log` or similar

## Verification

After deployment, verify:
- ✅ Incoming messages appear in unified inbox
- ✅ Incoming messages appear in WhatsApp Messages page
- ✅ Contacts are created/found correctly
- ✅ Phone numbers match regardless of format (+254, 254, etc.)

## Troubleshooting

If messages still don't appear:

1. **Check webhook is receiving requests:**
   - Check server access logs for POST requests to `/api/webhooks/whatsapp.php`
   - Check if webhook verification is working

2. **Check database:**
   ```sql
   SELECT COUNT(*) FROM whatsapp_messages WHERE direction = 'inbound';
   SELECT COUNT(*) FROM communications WHERE channel = 'whatsapp' AND direction = 'inbound';
   ```

3. **Check error logs:**
   - PHP error log
   - Apache/Nginx error log
   - `.cursor/debug.log` (if exists)

4. **Test webhook manually:**
   - Use the test script: `php scripts/test_whatsapp_webhook.php`
   - Or send a test POST request with sample payload

## Rollback

If issues occur, restore backups:
```bash
cp services/WhatsAppWebhook.php.backup services/WhatsAppWebhook.php
cp public/whatsapp_messages.php.backup public/whatsapp_messages.php
```
