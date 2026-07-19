# WhatsApp Inbox Deployment Checklist

## Files to Upload to Live Server (crm.makdennis.dev)

Upload these files to make WhatsApp messages appear in the inbox:

### 1. Core Service Files (REQUIRED)
These files add WhatsApp messages to the communications table:

- ✅ **`services/WhatsAppService.php`**
  - Adds outbound messages to communications table when sending
  - Location: `services/WhatsAppService.php`

- ✅ **`services/WhatsAppWebhook.php`**
  - Adds inbound messages to communications table when received
  - Location: `services/WhatsAppWebhook.php`

### 2. UI Files (REQUIRED)
These files update the inbox interface:

- ✅ **`public/inbox.php`**
  - Adds WhatsApp compose button in header
  - Adds "Reply via WhatsApp" links for WhatsApp messages
  - Location: `public/inbox.php`

- ✅ **`public/settings.php`**
  - Adds Webhook Verify Token field
  - Shows webhook URL and configuration instructions
  - Location: `public/settings.php`

### 3. Webhook Handler (REQUIRED)
- ✅ **`api/webhooks/whatsapp.php`**
  - Handles webhook verification and incoming messages
  - Location: `api/webhooks/whatsapp.php`

### 4. Sync Script (OPTIONAL - Run Once)
- ✅ **`scripts/sync_whatsapp_to_inbox.php`**
  - Syncs existing messages from `whatsapp_messages` to `communications` table
  - Run this ONCE after uploading files to sync existing messages
  - Location: `scripts/sync_whatsapp_to_inbox.php`

---

## Deployment Steps

### Step 1: Upload Files
Upload all files listed above to your live server using FTP/SFTP or your deployment method.

**File paths on server:**
```
/services/WhatsAppService.php
/services/WhatsAppWebhook.php
/public/inbox.php
/public/settings.php
/api/webhooks/whatsapp.php
/scripts/sync_whatsapp_to_inbox.php (optional)
```

**Note:** If your live server uses a `/crm` subfolder, adjust paths accordingly. The webhook URL in Settings will automatically detect the correct path.

### Step 2: Sync Existing Messages (One-Time)
After uploading, run the sync script ONCE to migrate existing messages:

**Via SSH:**
```bash
cd /path/to/crm
php scripts/sync_whatsapp_to_inbox.php
```

**Via Browser (if PHP CLI not available):**
Create a temporary file `sync_now.php` in the root:
```php
<?php
require_once __DIR__ . '/scripts/sync_whatsapp_to_inbox.php';
```
Visit: `https://crm.makdennis.dev/sync_now.php`
Then delete the file after running.

### Step 3: Verify
1. Go to Settings > WhatsApp
2. Make sure Webhook Verify Token is set
3. Send a test WhatsApp message
4. Check Inbox - message should appear immediately

---

## What Each File Does

### `services/WhatsAppService.php`
- **What it does:** When you send a WhatsApp message, it now also adds it to the `communications` table
- **Why needed:** Without this, sent messages won't appear in inbox

### `services/WhatsAppWebhook.php`
- **What it does:** When a WhatsApp message is received via webhook, it adds it to the `communications` table
- **Why needed:** Without this, received messages won't appear in inbox

### `public/inbox.php`
- **What it does:** 
  - Shows WhatsApp compose button in header
  - Shows "Reply" link for WhatsApp messages
  - Displays WhatsApp messages in the inbox
- **Why needed:** UI updates to interact with WhatsApp messages

### `public/settings.php`
- **What it does:** Adds webhook verify token field and configuration instructions
- **Why needed:** To configure webhook verification with Meta

### `api/webhooks/whatsapp.php`
- **What it does:** Handles webhook verification and processes incoming messages
- **Why needed:** Required for receiving messages from Meta

### `scripts/sync_whatsapp_to_inbox.php`
- **What it does:** One-time script to sync existing messages from `whatsapp_messages` to `communications`
- **Why needed:** Migrates existing messages that were sent before the update

---

## Quick Upload Checklist

- [ ] Upload `services/WhatsAppService.php`
- [ ] Upload `services/WhatsAppWebhook.php`
- [ ] Upload `public/inbox.php`
- [ ] Upload `public/settings.php`
- [ ] Upload `api/webhooks/whatsapp.php`
- [ ] Upload `scripts/sync_whatsapp_to_inbox.php` (optional)
- [ ] Run sync script once (if you have existing messages)
- [ ] Test by sending a WhatsApp message
- [ ] Verify message appears in inbox

---

## Troubleshooting

### Messages still not appearing?
1. Check server error logs for INSERT errors
2. Verify database connection is working
3. Check that `communications` table exists
4. Run sync script to migrate existing messages
5. Check that `WHATSAPP_VERIFY_TOKEN` is set in `.env` on live server

### Getting errors?
- Check PHP error logs on server
- Verify all files uploaded correctly
- Make sure file permissions are correct (644 for files, 755 for directories)
