# Frequently Asked Questions (FAQ)

Common questions and answers about the Self-Hosted CRM System.

## General

### What is this CRM system?

A fully self-hosted, open-source CRM system that gives you complete control over your customer data with zero vendor lock-in.

### What technologies does it use?

- **Backend**: PHP 8.1+
- **Database**: MySQL 8.0+
- **Frontend**: HTML, CSS, JavaScript
- **Caching**: Redis (optional)

### Is it free?

Yes, the software is free to use. You only need to provide your own hosting infrastructure.

### Can I customize it?

Yes! The codebase is fully customizable. See the [Developer Guide](developer-guide.md) for details.

---

## Installation & Setup

### What are the system requirements?

- PHP 8.1 or higher
- MySQL 8.0 or higher
- Web server (Apache/Nginx)
- Composer
- Redis (optional, recommended)

### How do I install it?

See the [Installation Guide](deployment.md#installation) for step-by-step instructions.

### How do I create the first admin user?

Run the admin user creation script:
```bash
php scripts/create_admin_user.php
```

Or use the web interface if user registration is enabled.

### How do I configure email sending?

1. Navigate to **Settings** → **Email**
2. Enter your SMTP credentials:
   - SMTP Host
   - SMTP Port (usually 587 for TLS)
   - SMTP Username
   - SMTP Password
3. Test the connection
4. Save settings

### Do I need Redis?

Redis is optional but recommended for better performance. The system works without it, but caching will be disabled.

---

## Contacts

### How do I import contacts from CSV?

1. Navigate to **Contacts**
2. Click **"Import CSV"**
3. Download the sample template
4. Fill in your contact data
5. Upload the CSV file
6. Review and confirm import

### What happens if I import duplicate contacts?

The system will detect duplicates by email or phone and warn you. You can choose to skip duplicates or merge them.

### How does lead scoring work?

Lead scoring is automatic and based on:
- Contact activities (emails, calls, meetings)
- Time decay (recent activities score higher)
- Activity types (different activities have different weights)

Scores range from 0-100, with higher scores indicating hotter leads.

### Can I merge duplicate contacts?

Yes! Use the merge functionality (admin only):
1. Identify duplicate contacts
2. Access merge page
3. Select source and target contacts
4. Choose which fields to keep
5. Confirm merge

**Warning**: Merging is permanent and cannot be undone.

### How do bulk operations work?

1. Select multiple contacts using checkboxes
2. Choose a bulk action (Update Stage, Update Assigned To, Delete)
3. Apply the action
4. All selected contacts will be updated

---

## Email

### How do I create an email template?

1. Navigate to **Email Templates**
2. Click **"+ New Template"**
3. Use the WYSIWYG editor to create your template
4. Use `{variable_name}` for dynamic content (e.g., `{first_name}`)
5. Save the template

### How do I test an email template?

1. Open the email template
2. Click **"Send Test Email"**
3. Enter a test email address
4. Click **"Send Test"**

The email will be sent with sample data replacing the variables.

### How does email tracking work?

- **Open Tracking**: A tiny invisible image is embedded in emails. When the email is opened, the image loads and records the open.
- **Click Tracking**: Links in emails are replaced with tracking URLs that record clicks before redirecting.

### Can I schedule emails?

Yes! When composing an email, you can set a scheduled date and time. The email will be sent automatically at that time.

### Why aren't my emails being sent?

Check:
1. SMTP settings in **Settings** → **Email**
2. Email worker is running: `php cli/email_worker.php`
3. Email queue status
4. SMTP server logs

---

## Workflows

### What is a workflow?

A workflow is an automated sequence of actions that runs when a trigger event occurs. For example: "When a contact is created, send a welcome email."

### How do I create a workflow?

1. Navigate to **Workflows**
2. Click **"+ New Workflow"**
3. Choose a trigger (when to run)
4. Add conditions (optional)
5. Add actions (what to do)
6. Save and activate

### What triggers are available?

- Contact created
- Contact updated
- Contact stage changed
- Email received
- Task completed
- Deal won/lost

### What actions can I use?

- Send email
- Create task
- Update contact
- Assign to user
- Add tag
- Update lead score

### Can I test a workflow?

Yes, workflows can be tested manually or will run automatically when trigger conditions are met.

---

## Reports

### How do I create a custom report?

1. Navigate to **Reports**
2. Click **"+ New Report"**
3. Configure:
   - Report type
   - Data fields to include
   - Filters
   - Charts (optional)
4. Save the report

### Can I schedule reports?

Yes! Create a scheduled report:
1. Navigate to **Scheduled Reports**
2. Select a report
3. Set schedule (daily, weekly, monthly)
4. Choose recipients
5. Select format (PDF, Excel, CSV)

### What export formats are available?

- **CSV** - For spreadsheet applications
- **PDF** - For printing and sharing
- **Excel** - For advanced analysis

---

## Tasks & Calendar

### How do I create a task?

1. Navigate to **Tasks**
2. Click **"+ New Task"**
3. Fill in:
   - Title
   - Description
   - Due date
   - Priority
   - Assigned to
4. Save

### Can tasks be assigned to contacts?

Yes! When creating a task, you can associate it with a contact.

### How do recurring events work?

1. Create an event
2. Set recurrence pattern (daily, weekly, monthly, yearly)
3. Set end date or number of occurrences
4. The system will create all occurrences automatically

### How do reminders work?

When creating an event, you can set a reminder (5 minutes, 15 minutes, 1 hour, 1 day before). You'll receive an email and/or in-app notification.

---

## Documents

### What file types can I upload?

Most common file types are supported:
- Documents: PDF, DOC, DOCX, TXT
- Images: JPG, PNG, GIF
- Spreadsheets: XLS, XLSX, CSV
- Maximum file size: 10MB

### How does version control work?

1. Upload a document
2. Later, upload a new version
3. All versions are kept
4. You can view version history
5. You can restore any previous version

### Can I preview documents?

Yes! Images and PDFs can be previewed directly in the browser.

---

## Tags & Notes

### How do tags work?

Tags are color-coded labels you can assign to contacts, tasks, events, and other entities for organization and filtering.

### Can I use @mentions in notes?

Yes! Type `@` followed by a username to mention someone. They'll receive a notification.

### Are notes private or public?

Notes can be marked as private (only visible to you) or public (visible to all users).

---

## Search

### How does global search work?

Use the search bar in the navigation to search across:
- Contacts
- Tasks
- Events
- Deals
- Emails
- Notes

### Can I save searches?

The infrastructure for saved searches exists. UI implementation is coming soon.

---

## Notifications

### How do I manage notification preferences?

1. Click the ⚙️ icon in the navigation
2. Configure preferences for each notification type:
   - Email notifications
   - In-app notifications
3. Save preferences

### What notification types are available?

- Contact created/updated/deleted
- Task assigned/completed/overdue
- Event reminders
- Deal created/updated/won/lost
- Email received
- Mentioned in note
- Workflow triggered

---

## Troubleshooting

### I can't log in. What should I do?

1. Verify your credentials
2. Check if your account is active
3. Clear browser cookies
4. Contact your administrator

### Emails aren't being sent. Why?

1. Check SMTP settings in **Settings** → **Email**
2. Verify email worker is running
3. Check email queue status
4. Review SMTP server logs
5. Test SMTP connection

### The page is loading slowly. How can I improve performance?

1. Enable Redis caching
2. Optimize database queries
3. Check server resources (CPU, memory)
4. Review slow query logs
5. Consider database indexing

### I'm getting database errors. What should I do?

1. Check database connection settings in `.env`
2. Verify database exists and is accessible
3. Check database user permissions
4. Review database error logs
5. Run migrations: `php database/migrations/migrate.php`

### How do I enable debug mode?

Set in `.env`:
```
APP_DEBUG=true
```

**Warning**: Only enable in development, never in production. Keep the legacy
`DEBUG` flag unset on production hosts.

### Where are log files stored?

Log files are typically stored in:
- Application logs: `logs/` directory (if configured)
- Web server logs: Check your web server configuration
- PHP error logs: Check PHP configuration

---

## Security

### How secure is this system?

The system includes:
- CSRF protection
- SQL injection prevention
- XSS protection
- Secure password hashing
- Audit logging
- Role-based access control

### Should I use this in production?

The core features are production-ready. Ensure you:
- Use HTTPS
- Configure proper backups
- Set up monitoring
- Follow security best practices
- Keep software updated

### How do I backup my data?

1. **Database**: Use `mysqldump` or your preferred backup tool
2. **Files**: Backup the `uploads/` directory
3. **Configuration**: Backup `.env` file (securely)

See [Deployment Guide](deployment.md#backup-and-recovery) for detailed backup procedures.

---

## Performance

### How many contacts can the system handle?

The system can handle thousands of contacts efficiently. Performance depends on:
- Server resources
- Database optimization
- Caching configuration
- Query optimization

### How can I improve performance?

1. Enable Redis caching
2. Optimize database indexes
3. Use pagination for large lists
4. Enable query caching
5. Use CDN for static assets

---

## Customization

### Can I add custom fields?

Yes! Navigate to **Custom Fields** and create fields for any module (contacts, tasks, events, etc.).

### Can I customize the UI?

Yes! The UI uses a design system that can be customized. See [Developer Guide](developer-guide.md) for details.

### Can I integrate with other systems?

Yes! Use the REST API or webhooks for integrations. See [API Documentation](api.md).

---

## Integrations

### How do I set up SMS integration?

1. Sign up for a Twilio account
2. Get your Twilio SID, Auth Token, and Phone Number
3. Navigate to **Settings** → **SMS** tab
4. Enter your Twilio credentials
5. Save settings
6. Start the SMS worker: `php cli/sms_worker.php`

### How do I export my calendar?

1. Navigate to **Calendar**
2. Click **"Export iCal"**
3. Download the `.ics` file
4. Import into Google Calendar, Outlook, or other calendar apps

### Can I import events from another calendar?

Yes! Use the iCal import feature:
1. Navigate to **Calendar**
2. Click **"Import iCal"**
3. Upload an `.ics` file
4. Review and confirm import

### How do webhooks work?

Webhooks allow external systems to receive notifications when events occur in the CRM:
1. Create a webhook in **Admin** → **More** → **Webhooks**
2. Configure the webhook URL
3. Select events to trigger the webhook
4. The system will POST data to your URL when events occur

### How do I use API keys?

1. Create an API key in **Admin** → **More** → **API Keys**
2. Copy the key (it won't be shown again!)
3. Include it in API requests: `Authorization: Bearer your-api-key`
4. Set permissions and rate limits as needed

---

## Support

### Where can I get help?

- **Documentation**: See `docs/` directory
- **FAQ**: This document
- **Troubleshooting**: [Troubleshooting Guide](troubleshooting.md)
- **API Docs**: [API Documentation](api.md)
- **User Guide**: [User Guide](user-guide.md)

### How do I report bugs?

Create an issue in your repository or contact your system administrator.

### Can I contribute?

Yes! See [Contributing Guide](contributing.md) for development guidelines.

---

## Best Practices

### What are some best practices for using this CRM?

**Contact Management:**
- Use tags to organize contacts
- Keep contact information up to date
- Use custom fields for your specific needs
- Regularly review and clean duplicate contacts

**Email:**
- Create reusable email templates
- Use personalization variables
- Track email engagement
- Test templates before sending

**Workflows:**
- Start with simple workflows
- Test workflows thoroughly
- Monitor workflow execution
- Document your workflows

**Reports:**
- Create report templates for common reports
- Schedule regular reports
- Share reports with your team
- Export data for external analysis

**Security:**
- Use strong passwords
- Regularly backup your data
- Review audit logs
- Keep software updated
- Use HTTPS in production

---

*Last Updated: 2026-01-24*
