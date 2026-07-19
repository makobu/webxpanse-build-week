<?php
/**
 * Documentation Content Store
 * Full documentation for each product feature.
 */

namespace CRM\Modules;

class DocumentationContent
{
    private array $content = [];

    public function __construct()
    {
        $this->content = [
            'dashboard' => [
                'overview' => 'The Dashboard is your growth command center. It shows key metrics at a glance: contact counts, deal pipeline value, pending tasks, and recent activity. Use it to quickly assess revenue momentum and take action.',
                'how_to' => [
                    'View summary widgets for contacts, deals, and tasks.',
                    'Use the AI Coach button for personalized recommendations.',
                    'Navigate to any section via the quick links.',
                ],
                'tips' => ['Check the dashboard daily to stay on top of your pipeline.'],
            ],
            'contacts' => [
                'overview' => 'Contacts are your leads and customers. Store names, emails, companies, and track their stage in your sales process. Add notes, log activities, and link contacts to deals.',
                'how_to' => [
                    'Create a contact from the "New Contact" button.',
                    'Search and filter by name, email, company, or stage.',
                    'Use bulk actions to update multiple contacts at once.',
                    'View a contact to see full details, notes, and activity history.',
                ],
                'tips' => ['Assign stages to track where each lead is in your funnel.'],
            ],
            'companies' => [
                'overview' => 'Companies represent organizations. Link multiple contacts to a company to track all relationships with that account.',
                'how_to' => [
                    'Create a company from the Companies page.',
                    'Link contacts to companies when editing a contact.',
                    'View a company to see all associated contacts.',
                ],
                'tips' => ['Use companies for account-based selling and B2B tracking.'],
            ],
            'deals' => [
                'overview' => 'Deals represent sales opportunities. Track stages from New to Won or Lost, set values, and forecast revenue. Each deal is linked to a contact.',
                'how_to' => [
                    'Create a deal from the Deals page or from a contact view.',
                    'Move deals through stages by dragging or editing.',
                    'Set deal value to track pipeline and forecast.',
                    'Use the pipeline view to see all deals by stage.',
                ],
                'tips' => ['Update deal stages regularly for accurate forecasting.'],
            ],
            'inbox' => [
                'overview' => 'The Inbox unifies email, WhatsApp, and SMS in one place. View conversations by channel, filter by contact, and reply directly.',
                'how_to' => [
                    'Switch between Email, WhatsApp, and SMS using the channel filter.',
                    'Click a conversation to view the thread and reply.',
                    'Use the Fetch Emails button to pull new messages from your server.',
                ],
                'tips' => ['Check the inbox regularly to respond to leads quickly.'],
            ],
            'targets' => [
                'overview' => 'Targets let you set goals and track progress. Define sales targets (e.g. 5 qualified leads this month) and monitor your progress.',
                'how_to' => [
                    'Create a target from the Targets page.',
                    'Set a goal type (e.g. leads, deals) and target value.',
                    'Track progress on the target dashboard.',
                ],
                'tips' => ['Set realistic targets to help focus your efforts.'],
            ],
            'tasks' => [
                'overview' => 'Tasks help you stay on top of follow-ups. Create tasks with due dates and priorities, assign them to yourself or others, and add subtasks when needed.',
                'how_to' => [
                    'Create a task from the Tasks page or from a contact/deal.',
                    'Set due date, priority, and assignee.',
                    'Add subtasks for multi-step tasks.',
                    'Mark tasks complete when done.',
                ],
                'tips' => ['Link tasks to contacts or deals for context.'],
            ],
            'activities' => [
                'overview' => 'Activities log your interactions: calls, meetings, notes, and status changes. Log activities to keep a history of what you did with each contact.',
                'how_to' => [
                    'Create an activity from the Activities page or from a contact view.',
                    'Choose an activity type (call, meeting, note, etc.).',
                    'Add a description and optional date.',
                ],
                'tips' => ['Log activities regularly for a complete contact history.'],
            ],
            'calendar' => [
                'overview' => 'The Calendar shows your events and meetings. Create events, set reminders, and optionally sync with Google Calendar.',
                'how_to' => [
                    'Create an event from the Calendar page.',
                    'Set start and end times, and add a description.',
                    'Configure calendar sync in Settings (Google Calendar).',
                ],
                'tips' => ['Sync your calendar to avoid double-booking.'],
            ],
            'emails' => [
                'overview' => 'Send and track emails from the AI Business Incubator. Compose emails to contacts, use templates, and see sent email history.',
                'how_to' => [
                    'Go to Emails to compose a new message.',
                    'Select a contact and use AI to draft or write manually.',
                    'Use email templates for common messages.',
                ],
                'tips' => ['Use templates for faster outreach.'],
            ],
            'email_templates' => [
                'overview' => 'Email templates are reusable message drafts. Create templates with variables (e.g. {{first_name}}) for personalization.',
                'how_to' => [
                    'Create a template from Email Templates.',
                    'Use variables like {{first_name}} and {{company}}.',
                    'Select the template when composing an email.',
                ],
                'tips' => ['Build a library of templates for different scenarios.'],
            ],
            'bulk_email' => [
                'overview' => 'Bulk Email lets you send the same email to multiple contacts at once. Select contacts, choose a template or write a message, and send.',
                'how_to' => [
                    'Go to Bulk Email.',
                    'Select contacts by filtering or choosing individually.',
                    'Compose or select a template.',
                    'Send to all selected contacts.',
                ],
                'tips' => ['Use filters to target the right audience.'],
            ],
            'bulk_sms' => [
                'overview' => 'Bulk SMS sends text messages to multiple contacts. Useful for reminders, updates, or quick outreach.',
                'how_to' => [
                    'Go to Bulk SMS.',
                    'Select contacts with phone numbers.',
                    'Compose your message and send.',
                ],
                'tips' => ['Keep SMS messages short and actionable.'],
            ],
            'bulk_whatsapp' => [
                'overview' => 'Bulk WhatsApp sends WhatsApp messages to multiple contacts. Requires WhatsApp Business API configuration.',
                'how_to' => [
                    'Go to Bulk WhatsApp.',
                    'Select contacts.',
                    'Compose or use a template.',
                    'Send to all selected contacts.',
                ],
                'tips' => ['Ensure WhatsApp is configured in Settings first.'],
            ],
            'campaigns' => [
                'overview' => 'Campaign Automation helps you run repeatable outbound campaigns from one place. You can create a campaign draft, configure the first outreach message, launch it, pause/resume execution, and monitor enrollment volume and status in real time.',
                'how_to' => [
                    'Open Campaign Automation from the main navigation or by visiting the Campaigns page.',
                    'Create a draft by entering campaign name, attribution model, description, Step 1 email subject, and Step 1 email body.',
                    'Review the campaign in the Existing Campaigns list and confirm status is draft before launch.',
                    'Launch a campaign to start enrollments and outbound execution for matching contacts.',
                    'Pause an active campaign when you need to stop enrollments or message sends temporarily.',
                    'Resume a paused campaign to continue from its current state.',
                    'Open a campaign detail view to inspect campaign-level information and execution progress.',
                    'Use the top summary stats to track total campaigns, active/paused counts, and total enrollments.',
                    'If an action fails, review the error message shown at the top of the page and retry with corrected input.',
                ],
                'lifecycle' => [
                    'Draft: Newly created campaigns start in draft state and can be edited before launch.',
                    'Active: Launch moves the campaign to active, enabling enrollments and step execution.',
                    'Paused: Pause temporarily halts campaign progress without deleting campaign setup.',
                    'Resumed: Resume returns a paused campaign to active execution.',
                    'Monitoring: Track status and enrollments from the campaign list and detail page.',
                ],
                'troubleshooting' => [
                    'Campaign launch fails: Confirm required fields are filled and the campaign id is valid.',
                    'Action blocked by security: Refresh the page and retry if the CSRF token expired.',
                    'No enrollments after launch: Check targeting/filter criteria and contact readiness.',
                    'Unexpected status behavior: Open campaign details and verify current state before action.',
                    'Feature unavailable: Confirm campaign automation is enabled in environment settings.',
                ],
                'tips' => [
                    'Start with small pilot campaigns before scaling outreach to larger audiences.',
                    'Use clear naming conventions (e.g. channel + segment + month) so campaigns are easy to identify.',
                    'Keep Step 1 subject lines concise and personalized for better open rates.',
                    'Write campaign descriptions that explain objective, audience, and expected outcome for team clarity.',
                    'Pause campaigns immediately when offer details or messaging become outdated.',
                    'Track enrollments after launch to validate audience targeting and campaign health.',
                    'Use one campaign per objective to simplify performance analysis and attribution.',
                    'Coordinate campaign launches with email/SMS/WhatsApp channel readiness in Settings.',
                ],
            ],
            'analytics' => [
                'overview' => 'Analytics provides dashboards and reports on your business data. View trends, conversion rates, and performance metrics.',
                'how_to' => [
                    'Open Analytics to see default dashboards.',
                    'Filter by date range.',
                    'Use reports for deeper analysis.',
                ],
                'tips' => ['Review analytics weekly to spot trends.'],
            ],
            'reports' => [
                'overview' => 'Reports let you create custom reports. Choose fields, filters, and output format. Export to CSV or view in the app.',
                'how_to' => [
                    'Create a report from the Reports page.',
                    'Select a report type and fields.',
                    'Add filters if needed.',
                    'Run and export or schedule the report.',
                ],
                'tips' => ['Schedule reports for regular delivery.'],
            ],
            'tags' => [
                'overview' => 'Tags help you organize contacts. Create tags (e.g. VIP, Newsletter) and assign them to contacts for filtering and segmentation.',
                'how_to' => [
                    'Create tags from the Tags page.',
                    'Assign tags to contacts when editing a contact.',
                    'Filter contacts by tag in the Contacts list.',
                ],
                'tips' => ['Use consistent tag naming for easier filtering.'],
            ],
            'settings' => [
                'overview' => 'Settings control company-wide configuration. Configure email (SMTP/IMAP), WhatsApp, company profile, and integrations. Admin only.',
                'how_to' => [
                    'Open Settings and switch between tabs.',
                    'Configure SMTP for sending emails.',
                    'Configure IMAP for fetching emails.',
                    'Set up WhatsApp Business API if needed.',
                ],
                'tips' => ['Test email and WhatsApp after configuration.'],
            ],
            'notifications' => [
                'overview' => 'Notifications show in-app alerts for events like new contacts, task assignments, and deal updates. Manage preferences to control what you receive.',
                'how_to' => [
                    'View notifications in the bell icon or Notifications page.',
                    'Click a notification to go to the related item.',
                    'Adjust preferences in Notification Preferences.',
                ],
                'tips' => ['Enable in-app notifications for time-sensitive events.'],
            ],
            'users' => [
                'overview' => 'Users are workspace accounts. Admins can create users, assign roles (admin, sales, marketing, viewer), and manage access.',
                'how_to' => [
                    'Create a user from the Users page.',
                    'Assign a role (admin, sales, marketing, viewer).',
                    'Users receive login credentials via email.',
                ],
                'tips' => ['Use the viewer role for read-only access.'],
            ],
            'custom_fields' => [
                'overview' => 'Custom fields let you add extra fields to contacts and deals. Create text, number, date, or dropdown fields.',
                'how_to' => [
                    'Create a custom field from Custom Fields.',
                    'Choose entity (contact or deal) and field type.',
                    'Fields appear when editing contacts or deals.',
                ],
                'tips' => ['Plan your fields before adding many.'],
            ],
            'workflows' => [
                'overview' => 'Workflows automate tasks. Configure triggers (e.g. contact created) and actions (e.g. send email, create task) to save time.',
                'how_to' => [
                    'Create a workflow from the Workflows page.',
                    'Choose a trigger (e.g. contact created, deal updated).',
                    'Add actions (send email, create task, etc.).',
                    'Activate the workflow.',
                ],
                'tips' => ['Start with simple workflows and expand over time.'],
            ],
            'webhooks' => [
                'overview' => 'Webhooks send platform events to external URLs. Use them to integrate with other tools or build custom automations.',
                'how_to' => [
                    'Create a webhook from the Webhooks page.',
                    'Enter the URL and event types.',
                    'Optionally add a secret for signature verification.',
                ],
                'tips' => ['Test webhooks with a tool like webhook.site.'],
            ],
            'api_keys' => [
                'overview' => 'API Keys allow external access to the platform API. Create keys for integrations, scripts, or mobile apps.',
                'how_to' => [
                    'Create an API key from the API Keys page.',
                    'Give it a descriptive name.',
                    'Use the key in the Authorization header (Bearer token).',
                ],
                'tips' => ['Revoke keys that are no longer needed.'],
            ],
            'audit_logs' => [
                'overview' => 'Audit Logs record system changes. View who did what and when for compliance and debugging.',
                'how_to' => [
                    'Open Audit Logs to see recent changes.',
                    'Filter by user, entity, or date.',
                    'Export logs if needed.',
                ],
                'tips' => ['Review audit logs regularly for security.'],
            ],
        ];
    }

    /**
     * Get documentation content for a feature by slug.
     */
    public function get(string $slug): ?array
    {
        return $this->content[$slug] ?? null;
    }
}
