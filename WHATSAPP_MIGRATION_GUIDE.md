# WhatsApp Cloud API Migration Guide

This guide explains how to use the built-in WhatsApp Cloud API migration tool in the CRM system.

## Overview

The CRM includes a complete migration tool that allows you to migrate your WhatsApp Business number from On-Premises API to Cloud API directly from the admin interface.

## Prerequisites

1. **On-Premises API Access**: Your On-Premises API must be running and accessible
2. **Cloud API Credentials**: You need:
   - WhatsApp Business Phone Number ID
   - Access Token with appropriate permissions
3. **Phone Number PIN**: The PIN for your business phone number (or disable two-step verification)

## Configuration

Add these to your `.env` file:

```env
# WhatsApp On-Premises API URL
WHATSAPP_ONPREM_API_URL=https://localhost:9090

# WhatsApp Cloud API Credentials
WHATSAPP_PHONE_NUMBER_ID=your_phone_number_id
WHATSAPP_ACCESS_TOKEN=your_access_token
```

## Migration Steps

### Step 1: Disable Two-Step Verification (If Needed)

If you don't know your phone number's PIN, you must first disable two-step verification:
- This must be done on the phone number itself
- Or ask the phone number owner to disable it

**Note**: If you know the PIN, you can skip this step.

### Step 2: Generate Phone Number Metadata

1. Navigate to: `http://localhost/crm/admin/whatsapp-migration.php` (or your admin route)
2. In the "Step 2" section:
   - Enter a secure password (this will be used to encode the metadata)
   - Click "Generate Metadata"
3. **Important**: Copy and save:
   - The metadata string (long encoded string)
   - The password you used
   - You'll need both in Step 3

### Step 3: Register Number with Cloud API

1. In the "Step 3" section:
   - Enter your phone number PIN
   - Enter the password from Step 2
   - Paste the metadata string from Step 2
2. Click "Register Number"
3. Wait for confirmation

**Note**: If the backup metadata is passed correctly, an OTP is not required.

### Step 4: Check Messaging Health Status

1. Click "Check Health Status" button
2. Verify the status:
   - **GREEN**: Phone number is healthy and ready
   - **YELLOW**: Some issues but can still send messages
   - **RED**: Cannot send messages - review restrictions

### Deregister Phone Number

**⚠️ Warning**: This action makes the number unusable with Cloud API.

1. Scroll to the "Deregister Phone Number" section
2. Read the warnings carefully
3. Check the confirmation checkbox
4. Click "Deregister Phone Number"
5. Confirm the action in the popup

**Important Limitations:**
- Cannot deregister if number is in use with both Cloud API and WhatsApp Business app
- Limited to 10 requests per number in a 72-hour window
- If you exceed the limit, you'll be blocked for 72 hours (error code 133016)
- Deregistration does NOT delete the number or message history

## Migration Status

The migration status is automatically tracked in the database. You can view the current status at the top of the migration page.

## Important Notes

### Official Business Account (OBA) Status

- If your phone number has OBA status, it will be preserved if you include the metadata from Step 2
- If you omit the metadata, the number will lose its OBA status

### Security

- The password used in Step 2 is hashed and stored securely
- Never share your access tokens or PIN
- Keep the metadata and password secure until migration is complete

### Troubleshooting

**Error: "CURL Error"**
- Check that your On-Premises API URL is correct
- Verify the API is accessible from your server
- For self-signed certificates, the service automatically disables SSL verification

**Error: "Phone Number ID not configured"**
- Add `WHATSAPP_PHONE_NUMBER_ID` to your `.env` file

**Error: "Registration failed"**
- Verify your access token has the correct permissions
- Check that the metadata hasn't been modified
- Ensure the password matches exactly

**Health Status shows RED**
- Review your WhatsApp Business Account restrictions
- Check for policy violations
- Contact WhatsApp support if needed

**Deregistration Error: Rate limit exceeded (133016)**
- You've exceeded 10 deregistration requests in 72 hours
- Wait 72 hours before trying again
- This is a WhatsApp API limitation, not a system error

**Deregistration Error: Number in use with Business app**
- The number cannot be deregistered if it's connected to both Cloud API and WhatsApp Business app
- Disconnect from one platform first

## API Endpoints

The migration tool uses these API endpoints:

- `GET /api/whatsapp/migrate.php?status=1` - Get migration status
- `GET /api/whatsapp/migrate.php?health=1` - Check health status
- `POST /api/whatsapp/migrate.php` - Perform migration steps
  - Action: `generate_metadata`
  - Action: `register_number`
  - Action: `deregister_number`

## Database

Migration data is stored in the `whatsapp_migration_log` table, which is automatically created on first use.

## Support

If you encounter issues:
1. Check the error messages in the UI
2. Review your `.env` configuration
3. Verify API credentials and permissions
4. Check the migration log in the database

For WhatsApp-specific issues, submit a Direct Support ticket with:
- Topic: WABiz: Cloud API
- Request Type: On-Premises API -> Cloud API Migration Issues
