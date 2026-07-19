# CRM User Guide

Complete guide to using the Self-Hosted CRM System.

## Table of Contents

1. [Getting Started](#getting-started)
2. [Contact Management](#contact-management)
3. [Email Management](#email-management)
4. [Workflow Automation](#workflow-automation)
5. [Reports and Analytics](#reports-and-analytics)
6. [Settings and Configuration](#settings-and-configuration)
7. [Tips and Best Practices](#tips-and-best-practices)

---

## Getting Started

### First Login

1. Navigate to your CRM installation URL (e.g., `http://localhost/crm/public/`)
2. Enter your admin credentials
3. You'll be redirected to the Dashboard

### Dashboard Overview

The dashboard provides:
- **Key Metrics** - Leads, emails, tasks, deals
- **Recent Contacts** - Latest contact additions
- **Recent Activities** - Activity timeline
- **My Tasks** - Your assigned tasks
- **Upcoming Events** - Calendar events
- **Recent Notes** - Latest notes and comments

### Navigation

The main navigation includes:
- **Contacts** - Manage contacts and leads
- **Activities** - View activity timeline
- **Tasks** - Task management
- **Calendar** - Events and appointments
- **Emails** - Email management
- **Email Templates** - Template management
- **Inbox** - Unified inbox
- **Dashboard** - Main dashboard
- **Analytics** - Analytics and metrics
- **Reports** - Custom reports
- **Deals** - Sales pipeline
- **Tags** - Tag management
- **Settings** - System configuration

---

## Contact Management

### Creating a Contact

1. Click **"Contacts"** in the navigation
2. Click **"+ New Contact"**
3. Fill in the required fields:
   - **First Name** (required)
   - **Email** (required)
   - **Last Name** (optional)
   - **Phone** (optional)
   - **Company** (optional)
   - **Lead Source** (optional)
4. Click **"Create Contact"**

**Note**: The system will automatically detect duplicate contacts by email or phone.

### Editing a Contact

1. Navigate to the contact list
2. Click **"Edit"** next to the contact
3. Make your changes
4. Click **"Update Contact"**

### Viewing Contact Details

1. Click on a contact name or **"View"** button
2. The contact view page shows:
   - Contact information
   - Custom fields
   - Activity timeline
   - Notes and comments
   - Documents
   - Related deals
   - Tags

### Bulk Operations

1. Select contacts using checkboxes
2. Choose a bulk action:
   - **Update Stage** - Change stage for multiple contacts
   - **Update Assigned To** - Reassign contacts
   - **Delete** - Remove multiple contacts
3. Click **"Apply"**

### Merging Duplicate Contacts

1. Navigate to **"Contacts"**
2. Identify duplicate contacts
3. Access merge functionality (admin only)
4. Select source and target contacts
5. Choose field preferences
6. Confirm merge

**Warning**: Merging contacts is permanent and cannot be undone.

### Importing Contacts

1. Click **"Import CSV"** on the contacts page
2. Download the sample CSV template
3. Fill in your contact data
4. Upload the CSV file
5. Review import results
6. Confirm import

### Exporting Contacts

1. Click **"Export CSV"** on the contacts page
2. Apply filters if needed
3. Click export
4. Download the CSV file

---

## Email Management

### Composing an Email

1. Navigate to **"Emails"**
2. Click **"Compose Email"**
3. Select a contact (or enter email address)
4. Choose an email template (optional)
5. Fill in subject and body
6. Click **"Send Email"**

### Using Email Templates

1. Navigate to **"Email Templates"**
2. Click **"+ New Template"**
3. Fill in template details:
   - **Name** - Template name
   - **Subject** - Email subject (use {variables})
   - **HTML Body** - Use WYSIWYG editor
   - **Variables** - Available template variables
4. Click **"Create Template"**

**Template Variables**: Use `{first_name}`, `{last_name}`, `{email}`, `{company}`, etc.

### Testing Email Templates

1. Open an email template
2. Click **"Send Test Email"**
3. Enter test email address
4. Click **"Send Test"**

### Email Tracking

- **Open Tracking** - Automatically tracks when emails are opened
- **Click Tracking** - Tracks links clicked in emails
- View tracking data in the email view page

### Scheduled Emails

1. Compose an email
2. Set a scheduled date/time
3. Email will be sent automatically at the scheduled time

---

## Workflow Automation

### Creating a Workflow

1. Navigate to **"Workflows"**
2. Click **"+ New Workflow"**
3. Configure workflow:
   - **Name** - Workflow name
   - **Trigger** - When workflow runs (e.g., contact created)
   - **Conditions** - Optional conditions
   - **Actions** - What to do (e.g., send email, assign task)
4. Click **"Create Workflow"**

### Workflow Triggers

Available triggers:
- Contact created
- Contact updated
- Contact stage changed
- Email received
- Task completed
- Deal won/lost

### Workflow Actions

Available actions:
- Send email
- Create task
- Update contact
- Assign to user
- Add tag
- Update lead score

### Editing and Deleting Workflows

1. Navigate to **"Workflows"**
2. Click **"Edit"** or **"Delete"** next to a workflow
3. Make changes or confirm deletion

---

## Reports and Analytics

### Creating a Custom Report

1. Navigate to **"Reports"**
2. Click **"+ New Report"**
3. Configure report:
   - **Name** - Report name
   - **Report Type** - Contacts, Activities, Sales, etc.
   - **Query Configuration** - Define data to include
   - **Chart Configuration** - Optional charts
   - **Filters** - Apply filters
4. Click **"Create Report"**

### Viewing Reports

1. Navigate to **"Reports"**
2. Click on a report name
3. View report data and charts
4. Export if needed (PDF, Excel, CSV)

### Scheduled Reports

1. Navigate to **"Scheduled Reports"**
2. Click **"+ New Scheduled Report"**
3. Configure:
   - **Report** - Select report
   - **Schedule** - Frequency (daily, weekly, monthly)
   - **Recipients** - Email recipients
   - **Format** - PDF, Excel, or CSV
4. Click **"Create Schedule"**

### Analytics Dashboard

1. Navigate to **"Analytics"**
2. View:
   - **Real-time Metrics** - Current statistics
   - **Funnel Analysis** - Conversion funnels
   - **Charts** - Visual data representation
   - **Trends** - Historical data

---

## Settings and Configuration

### General Settings

1. Navigate to **"Settings"**
2. Configure:
   - **Application Name**
   - **Timezone**
   - **Date Format**
   - **Currency**

### Email Settings

1. Navigate to **"Settings"**
2. Go to **"Email"** section
3. Configure:
   - **SMTP Host**
   - **SMTP Port**
   - **SMTP Username**
   - **SMTP Password**
   - **From Email**
   - **From Name**

### WhatsApp Settings (Optional)

1. Navigate to **"Settings"**
2. Go to **"WhatsApp"** section
3. Configure:
   - **API URL**
   - **API Token**
   - **Webhook URL**

### Personal Assistant Settings

1. Navigate to **"Settings"**
2. Go to **"Email Assistant"** section
3. Configure:
   - **System Email Address** - The address that receives instructions (must differ from main CRM email)
   - **Skills** - Enable Q&A, create task, create contact, add note, pipeline status, list tasks, schedule event, run report
   - **Whitelisted sender emails** - Comma-separated list of emails allowed to send instructions; leave empty to allow all admin users. Use "Quick add admins" to populate with all admin emails.
   - **Daily Digest** - Enable and set send time and recipients

### Notification Preferences

1. Navigate to **"Notification Preferences"** (⚙️ icon in navigation)
2. Configure preferences for each notification type:
   - **Email** - Receive email notifications
   - **In-App** - Receive in-app notifications
3. Click **"Save Preferences"**

### User Management (Admin Only)

1. Navigate to **"Users"**
2. Click **"+ New User"**
3. Fill in user details:
   - **Email** (used as username)
   - **Password**
   - **Role** (admin or user)
4. Click **"Create User"**

---

## Tips and Best Practices

### Contact Management

- **Use Tags** - Organize contacts with color-coded tags
- **Custom Fields** - Add custom fields for your specific needs
- **Lead Scoring** - Let the system score leads automatically
- **Activity Logging** - Keep detailed activity logs

### Email Best Practices

- **Use Templates** - Create reusable email templates
- **Personalize** - Use template variables for personalization
- **Track Opens** - Monitor email engagement
- **Test First** - Always test templates before sending

### Workflow Automation

- **Start Simple** - Begin with simple workflows
- **Test Thoroughly** - Test workflows before activating
- **Monitor Performance** - Review workflow execution logs
- **Iterate** - Improve workflows based on results

### Reporting

- **Create Templates** - Save common reports as templates
- **Schedule Reports** - Automate regular reporting
- **Export Data** - Export reports for external analysis
- **Share Reports** - Make reports public for team access

### Security

- **Strong Passwords** - Use strong, unique passwords
- **Regular Backups** - Backup your database regularly
- **Audit Logs** - Review audit logs periodically
- **User Permissions** - Assign appropriate roles

### Performance

- **Use Filters** - Filter large lists for better performance
- **Bulk Operations** - Use bulk operations for efficiency
- **Cache** - Enable Redis caching for better performance
- **Indexes** - Database indexes are automatically created

---

## Tasks Management

### Creating a Task

1. Navigate to **"Tasks"**
2. Click **"+ New Task"**
3. Fill in task details:
   - **Title** (required)
   - **Description** (optional)
   - **Due Date** (optional)
   - **Priority** (Low, Medium, High, Urgent)
   - **Status** (Not Started, In Progress, Completed, Cancelled)
   - **Assigned To** (optional)
   - **Related Contact** (optional)
4. Click **"Create Task"**

### Managing Tasks

- **Filter Tasks** - Use filters to view tasks by status, priority, assignee, or due date
- **Bulk Actions** - Select multiple tasks and update status or assignee
- **Export Tasks** - Export tasks to CSV for external analysis

### Task Dashboard

View your tasks on the main dashboard:
- **My Tasks** - Tasks assigned to you
- **Overdue Tasks** - Tasks past their due date
- **Upcoming Tasks** - Tasks due soon

---

## Calendar & Events

### Creating an Event

1. Navigate to **"Calendar"**
2. Click **"+ New Event"** or click on a date/time slot
3. Fill in event details:
   - **Title** (required)
   - **Description** (optional)
   - **Start Date/Time** (required)
   - **End Date/Time** (required)
   - **Event Type** (Meeting, Call, Email, Other)
   - **Location** (optional)
   - **Assigned To** (optional)
   - **Related Contact** (optional)
   - **Reminders** (optional)
4. Click **"Create Event"**

### Calendar Views

- **Month View** - See all events in a monthly calendar
- **Week View** - View events by week
- **Day View** - Detailed daily schedule
- **List View** - List of all upcoming events

### Recurring Events

1. When creating an event, enable **"Recurring"**
2. Choose recurrence pattern:
   - Daily
   - Weekly
   - Monthly
   - Yearly
3. Set end date or number of occurrences
4. Save the event

### Event Reminders

Set reminders for events:
- **5 minutes before**
- **15 minutes before**
- **1 hour before**
- **1 day before**

Reminders are sent via email and in-app notifications.

### Exporting Calendar

1. Navigate to **"Calendar"**
2. Click **"Export iCal"**
3. Download the `.ics` file
4. Import into Google Calendar, Outlook, or other calendar applications

### Importing Calendar

1. Navigate to **"Calendar"**
2. Click **"Import iCal"**
3. Upload an `.ics` file
4. Review imported events
5. Confirm import

---

## Deals & Opportunities

### Creating a Deal

1. Navigate to **"Deals"**
2. Click **"+ New Deal"**
3. Fill in deal details:
   - **Deal Name** (required)
   - **Contact** (required)
   - **Value** (required)
   - **Currency** (required)
   - **Stage** (New, Qualified, Proposal, Negotiation, Won, Lost)
   - **Probability** (0-100%)
   - **Expected Close Date** (optional)
   - **Assigned To** (optional)
4. Click **"Create Deal"**

### Managing Deals

- **Pipeline View** - Visual pipeline showing deals by stage
- **Deal Stages** - Move deals through stages: New → Qualified → Proposal → Negotiation → Won/Lost
- **Deal Value** - Track deal values in multiple currencies
- **Deal Statistics** - View total pipeline value, win rate, average deal size

### Deal Stages

- **New** - Initial contact or lead
- **Qualified** - Lead has been qualified
- **Proposal** - Proposal sent to prospect
- **Negotiation** - Negotiating terms
- **Won** - Deal closed successfully
- **Lost** - Deal lost to competitor or cancelled

---

## Notes & Comments

### Creating a Note

1. Navigate to a contact, deal, task, or event
2. Scroll to the **"Notes"** section
3. Click **"+ Add Note"**
4. Fill in note details:
   - **Title** (optional)
   - **Content** - Use rich text editor
   - **Visibility** - Private (only you) or Public (all users)
5. Click **"Save Note"**

### Using @Mentions

1. In a note, type `@` followed by a username
2. Select the user from the dropdown
3. The mentioned user will receive a notification
4. Save the note

### Rich Text Editor

The note editor supports:
- **Bold**, *Italic*, <u>Underline</u>
- Bullet and numbered lists
- Links
- Text alignment
- Text color

---

## Documents & Files

### Uploading a Document

1. Navigate to a contact, deal, task, or event
2. Scroll to the **"Documents"** section
3. Click **"+ Upload Document"**
4. Select a file (max 10MB)
5. Choose a category (optional)
6. Add a description (optional)
7. Click **"Upload"**

### Document Categories

Organize documents with categories:
- Create custom categories
- Assign colors to categories
- Filter documents by category

### Document Version Control

1. Upload a document
2. Later, upload a new version of the same document
3. All versions are preserved
4. View version history
5. Restore any previous version

### File Preview

Supported file types for preview:
- **Images** - JPG, PNG, GIF
- **PDFs** - PDF documents
- Other files can be downloaded

---

## Tags & Labels

### Creating Tags

1. Navigate to **"Tags"**
2. Click **"+ New Tag"**
3. Fill in tag details:
   - **Name** (required)
   - **Color** (choose from color picker)
   - **Description** (optional)
4. Click **"Create Tag"**

### Using Tags

Tags can be assigned to:
- Contacts
- Tasks
- Events
- Deals
- Documents

### Filtering by Tags

1. Navigate to any list (Contacts, Tasks, etc.)
2. Use the tag filter dropdown
3. Select one or more tags
4. View filtered results

---

## Unified Inbox

### Viewing Messages

1. Navigate to **"Inbox"**
2. View all messages from:
   - Email
   - WhatsApp
   - SMS
3. Messages are organized by conversation thread

### Conversation Threading

- Messages are automatically grouped by contact
- View full conversation history
- Reply directly from the inbox

### Message Actions

- **Reply** - Reply to a message
- **Mark as Read/Unread** - Change read status
- **Archive** - Archive conversations
- **Delete** - Delete messages

---

## SMS Integration

### Sending SMS

1. Navigate to a contact
2. Click **"Send SMS"**
3. Compose your message
4. Click **"Send"**

### SMS Settings

1. Navigate to **"Settings"** → **"SMS"** tab
2. Configure Twilio credentials:
   - **Twilio SID**
   - **Twilio Auth Token**
   - **Twilio Phone Number**
3. Save settings

### SMS Queue

SMS messages are queued and sent by the SMS worker:
- Messages are processed in the background
- Failed messages are retried automatically
- Delivery status is tracked

---

## Custom Fields

### Creating Custom Fields

1. Navigate to **"Custom Fields"**
2. Click **"+ New Field"**
3. Configure field:
   - **Field Name** (required)
   - **Field Type** (Text, Number, Date, Dropdown, Checkbox, etc.)
   - **Module** (Contacts, Tasks, Events, Deals)
   - **Required** (optional)
   - **Default Value** (optional)
4. Click **"Create Field"**

### Using Custom Fields

Custom fields appear automatically in:
- Contact forms
- Task forms
- Event forms
- Deal forms
- Contact view pages

### Field Types

- **Text** - Single line text
- **Textarea** - Multi-line text
- **Number** - Numeric values
- **Date** - Date picker
- **Dropdown** - Select from options
- **Checkbox** - Boolean yes/no
- **Radio** - Single choice from options

---

## Lead Scoring

### Understanding Lead Scores

Lead scores range from 0-100:
- **0-30** - Cold lead
- **31-60** - Warm lead
- **61-80** - Hot lead
- **81-100** - Very hot lead

### How Scores are Calculated

Scores are based on:
- **Activities** - Emails sent/received, calls, meetings
- **Time Decay** - Recent activities score higher
- **Activity Types** - Different activities have different weights
- **Engagement** - Email opens, link clicks

### Viewing Lead Scores

- View scores on contact list
- Filter contacts by score range
- View score history on contact detail page

---

## Advanced Search

### Global Search

1. Use the search bar in the navigation
2. Type your search term
3. View results across:
   - Contacts
   - Tasks
   - Events
   - Deals
   - Emails
   - Notes

### Saved Searches

1. Perform a search
2. Click **"Save Search"**
3. Name your search
4. Access saved searches from the search page

### Search Filters

- Filter by entity type
- Filter by date range
- Filter by tags
- Filter by assigned user

---

## Notifications

### Notification Types

You'll receive notifications for:
- **Contact Updates** - Contact created, updated, deleted
- **Task Updates** - Task assigned, completed, overdue
- **Event Reminders** - Upcoming events
- **Deal Updates** - Deal created, updated, won, lost
- **Email Received** - New emails in inbox
- **Mentions** - When someone mentions you in a note
- **Workflow Triggers** - When workflows are executed

### Managing Notifications

1. Click the notification bell icon
2. View unread notifications
3. Mark as read
4. Click to view related item

### Notification Preferences

1. Navigate to **"Notification Preferences"** (⚙️ icon)
2. Configure preferences:
   - **Email Notifications** - Receive via email
   - **In-App Notifications** - Receive in-app
3. Save preferences

---

## Email Signatures

### Creating an Email Signature

1. Navigate to **"Email Signatures"**
2. Click **"+ New Signature"**
3. Use the WYSIWYG editor to create your signature
4. Set as default (optional)
5. Click **"Save Signature"**

### Using Signatures

- Select signature when composing emails
- Default signature is automatically added
- Edit signatures anytime

---

## Webhooks

### Creating a Webhook

1. Navigate to **"Webhooks"** (Admin → More → Webhooks)
2. Click **"+ New Webhook"**
3. Configure:
   - **Name** (required)
   - **URL** (required) - Endpoint to receive webhooks
   - **Events** - Select events to trigger webhook
   - **Secret Key** - For webhook signing
   - **Headers** - Custom headers (optional)
4. Click **"Create Webhook"**

### Webhook Events

Available events:
- `contact.created`, `contact.updated`, `contact.deleted`
- `deal.created`, `deal.updated`, `deal.won`, `deal.lost`
- `task.created`, `task.completed`, `task.overdue`
- `event.created`, `event.updated`
- `email.sent`, `email.opened`, `email.clicked`
- `form.submitted`
- `workflow.triggered`

### Testing Webhooks

1. Open a webhook
2. Click **"Test Webhook"**
3. View test results and logs

---

## API Keys

### Creating an API Key

1. Navigate to **"API Keys"** (Admin → More → API Keys)
2. Click **"+ New API Key"**
3. Configure:
   - **Name** (required)
   - **Expiration Date** (optional)
   - **Rate Limit** - Requests per minute
   - **Permissions** - Select allowed operations
4. Click **"Generate Key"**
5. **Copy the key immediately** - It won't be shown again!

### Using API Keys

Include the API key in requests:
```
Authorization: Bearer your-api-key-here
```

### API Key Management

- View usage statistics
- Revoke keys
- Set expiration dates
- Monitor rate limits

---

## Currency Management

### Managing Currencies

1. Navigate to **"Currencies"** (Admin → More → Currencies)
2. View all currencies
3. Create new currencies
4. Set default currency
5. Activate/deactivate currencies

### Currency Formatting

Currencies are automatically formatted with:
- Correct symbol placement (before/after)
- Decimal places
- Thousands and decimal separators

---

## Keyboard Shortcuts

- **Ctrl+K** - Global search (where available)
- **Esc** - Close modals
- **Enter** - Submit forms
- **/** - Focus search (on some pages)

---

## Getting Help

- **FAQ** - See [FAQ](faq.md) for common questions
- **Troubleshooting** - See [Troubleshooting Guide](troubleshooting.md)
- **API Documentation** - See [API Documentation](api.md)
- **Documentation** - Browse all documentation in the `docs/` directory

---

*Last Updated: 2026-01-24*
