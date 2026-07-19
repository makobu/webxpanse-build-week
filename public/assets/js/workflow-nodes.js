/**
 * Workflow Node Definitions
 * Complete library of all available workflow nodes
 */

const WorkflowNodeDefinitions = {
    optionsLoaded: false,
    optionsLoading: null,

    triggers: {
        // Contact Triggers
        contact_created: {
            type: 'trigger',
            category: 'contact',
            label: 'Contact Created',
            icon: '👤',
            color: '#ff6b6b',
            description: 'Triggers when a new contact is created',
            config: {
                fields: []
            }
        },
        contact_updated: {
            type: 'trigger',
            category: 'contact',
            label: 'Contact Updated',
            icon: '✏️',
            color: '#ff6b6b',
            description: 'Triggers when a contact is updated',
            config: {
                fields: []
            }
        },
        contact_field_changed: {
            type: 'trigger',
            category: 'contact',
            label: 'Contact Field Changed',
            icon: '🔄',
            color: '#ff6b6b',
            description: 'Triggers when a specific contact field changes',
            config: {
                fields: [
                    { name: 'field', type: 'select', label: 'Field', required: true, options: [] }
                ]
            }
        },
        contact_tag_added: {
            type: 'trigger',
            category: 'contact',
            label: 'Tag Added',
            icon: '🏷️',
            color: '#ff6b6b',
            description: 'Triggers when a tag is added to a contact',
            config: {
                fields: [
                    { name: 'tag', type: 'select', label: 'Tag', required: false, options: [] }
                ]
            }
        },
        contact_tag_removed: {
            type: 'trigger',
            category: 'contact',
            label: 'Tag Removed',
            icon: '🏷️',
            color: '#ff6b6b',
            description: 'Triggers when a tag is removed from a contact',
            config: {
                fields: [
                    { name: 'tag', type: 'select', label: 'Tag', required: false, options: [] }
                ]
            }
        },
        contact_score_changed: {
            type: 'trigger',
            category: 'contact',
            label: 'Lead Score Changed',
            icon: '⭐',
            color: '#ff6b6b',
            description: 'Triggers when contact lead score changes',
            config: {
                fields: [
                    { name: 'threshold', type: 'number', label: 'Score Threshold', required: false }
                ]
            }
        },
        contact_enriched: {
            type: 'trigger',
            category: 'contact',
            label: 'Contact Enriched',
            icon: '🔍',
            color: '#ff6b6b',
            description: 'Triggers when contact data is enriched',
            config: {
                fields: []
            }
        },
        
        // Email Triggers
        email_opened: {
            type: 'trigger',
            category: 'email',
            label: 'Email Opened',
            icon: '📧',
            color: '#4ecdc4',
            description: 'Triggers when an email is opened',
            config: {
                fields: [
                    { name: 'email_template', type: 'select', label: 'Email Template', required: false, options: [] }
                ]
            }
        },
        email_received: {
            type: 'trigger',
            category: 'email',
            label: 'Email Received',
            icon: '📬',
            color: '#4ecdc4',
            description: 'Triggers when an email is received',
            config: {
                fields: []
            }
        },
        email_clicked: {
            type: 'trigger',
            category: 'email',
            label: 'Email Link Clicked',
            icon: '🔗',
            color: '#4ecdc4',
            description: 'Triggers when a link in an email is clicked',
            config: {
                fields: [
                    { name: 'link_url', type: 'text', label: 'Link URL', required: false }
                ]
            }
        },
        email_replied: {
            type: 'trigger',
            category: 'email',
            label: 'Email Replied',
            icon: '↩️',
            color: '#4ecdc4',
            description: 'Triggers when contact replies to an email',
            config: {
                fields: []
            }
        },
        email_bounced: {
            type: 'trigger',
            category: 'email',
            label: 'Email Bounced',
            icon: '❌',
            color: '#4ecdc4',
            description: 'Triggers when an email bounces',
            config: {
                fields: []
            }
        },
        email_unsubscribed: {
            type: 'trigger',
            category: 'email',
            label: 'Email Unsubscribed',
            icon: '🚫',
            color: '#4ecdc4',
            description: 'Triggers when contact unsubscribes',
            config: {
                fields: []
            }
        },
        no_email_opened: {
            type: 'trigger',
            category: 'email',
            label: 'No Email Opened',
            icon: '⏰',
            color: '#4ecdc4',
            description: 'Triggers when email not opened within X days',
            config: {
                fields: [
                    { name: 'days', type: 'number', label: 'Days', required: true, default: 7 }
                ]
            }
        },
        no_email_replied: {
            type: 'trigger',
            category: 'email',
            label: 'No Email Replied',
            icon: '⏰',
            color: '#4ecdc4',
            description: 'Triggers when email not replied within X days',
            config: {
                fields: [
                    { name: 'days', type: 'number', label: 'Days', required: true, default: 7 }
                ]
            }
        },
        
        // Deal Triggers
        deal_created: {
            type: 'trigger',
            category: 'deal',
            label: 'Deal Created',
            icon: '💼',
            color: '#45b7d1',
            description: 'Triggers when a new deal is created',
            config: {
                fields: []
            }
        },
        deal_updated: {
            type: 'trigger',
            category: 'deal',
            label: 'Deal Updated',
            icon: '✏️',
            color: '#45b7d1',
            description: 'Triggers when a deal is updated',
            config: {
                fields: []
            }
        },
        deal_stage_changed: {
            type: 'trigger',
            category: 'deal',
            label: 'Deal Stage Changed',
            icon: '📊',
            color: '#45b7d1',
            description: 'Triggers when deal moves to a new stage',
            config: {
                fields: [
                    { name: 'from_stage', type: 'select', label: 'From Stage', required: false, options: [] },
                    { name: 'to_stage', type: 'select', label: 'To Stage', required: false, options: [] }
                ]
            }
        },
        deal_won: {
            type: 'trigger',
            category: 'deal',
            label: 'Deal Won',
            icon: '✅',
            color: '#45b7d1',
            description: 'Triggers when a deal is won',
            config: {
                fields: []
            }
        },
        deal_lost: {
            type: 'trigger',
            category: 'deal',
            label: 'Deal Lost',
            icon: '❌',
            color: '#45b7d1',
            description: 'Triggers when a deal is lost',
            config: {
                fields: []
            }
        },
        deal_amount_changed: {
            type: 'trigger',
            category: 'deal',
            label: 'Deal Amount Changed',
            icon: '💰',
            color: '#45b7d1',
            description: 'Triggers when deal amount changes',
            config: {
                fields: []
            }
        },
        deal_closing_soon: {
            type: 'trigger',
            category: 'deal',
            label: 'Deal Closing Soon',
            icon: '⏰',
            color: '#45b7d1',
            description: 'Triggers when deal closing date is within X days',
            config: {
                fields: [
                    { name: 'days', type: 'number', label: 'Days', required: true, default: 7 }
                ]
            }
        },
        
        // Task Triggers
        task_created: {
            type: 'trigger',
            category: 'task',
            label: 'Task Created',
            icon: '📝',
            color: '#f9ca24',
            description: 'Triggers when a task is created',
            config: {
                fields: []
            }
        },
        task_completed: {
            type: 'trigger',
            category: 'task',
            label: 'Task Completed',
            icon: '✅',
            color: '#f9ca24',
            description: 'Triggers when a task is completed',
            config: {
                fields: []
            }
        },
        task_overdue: {
            type: 'trigger',
            category: 'task',
            label: 'Task Overdue',
            icon: '⚠️',
            color: '#f9ca24',
            description: 'Triggers when a task becomes overdue',
            config: {
                fields: []
            }
        },
        
        // Form Triggers
        form_submitted: {
            type: 'trigger',
            category: 'form',
            label: 'Form Submitted',
            icon: '📋',
            color: '#9b59b6',
            description: 'Triggers when a form is submitted',
            config: {
                fields: [
                    { name: 'form_id', type: 'select', label: 'Form', required: false, options: [] }
                ]
            }
        },
        form_field_filled: {
            type: 'trigger',
            category: 'form',
            label: 'Form Field Filled',
            icon: '✍️',
            color: '#9b59b6',
            description: 'Triggers when a specific form field is filled',
            config: {
                fields: [
                    { name: 'form_id', type: 'select', label: 'Form', required: true, options: [] },
                    { name: 'field', type: 'text', label: 'Field Name', required: true }
                ]
            }
        },
        form_abandoned: {
            type: 'trigger',
            category: 'form',
            label: 'Form Abandoned',
            icon: '🚫',
            color: '#9b59b6',
            description: 'Triggers when a form is started but not submitted',
            config: {
                fields: [
                    { name: 'form_id', type: 'select', label: 'Form', required: false, options: [] }
                ]
            }
        },
        
        // Stage Triggers
        stage_changed: {
            type: 'trigger',
            category: 'stage',
            label: 'Stage Changed',
            icon: '📊',
            color: '#e74c3c',
            description: 'Triggers when contact stage changes',
            config: {
                fields: [
                    { name: 'from_stage', type: 'select', label: 'From Stage', required: false, options: [] },
                    { name: 'to_stage', type: 'select', label: 'To Stage', required: false, options: [] }
                ]
            }
        },
        
        // Activity Triggers
        no_activity_for_days: {
            type: 'trigger',
            category: 'activity',
            label: 'No Activity for Days',
            icon: '⏰',
            color: '#95a5a6',
            description: 'Triggers when no activity for X days',
            config: {
                fields: [
                    { name: 'days', type: 'number', label: 'Days', required: true, default: 7 }
                ]
            }
        },
        activity_created: {
            type: 'trigger',
            category: 'activity',
            label: 'Activity Created',
            icon: '📅',
            color: '#95a5a6',
            description: 'Triggers when an activity is created',
            config: {
                fields: []
            }
        },
        
        // Scheduled Triggers
        daily_at_time: {
            type: 'trigger',
            category: 'scheduled',
            label: 'Daily at Time',
            icon: '🕐',
            color: '#3498db',
            description: 'Triggers daily at a specific time',
            config: {
                fields: [
                    { name: 'time', type: 'time', label: 'Time', required: true }
                ]
            }
        },
        weekly_on_day: {
            type: 'trigger',
            category: 'scheduled',
            label: 'Weekly on Day',
            icon: '📅',
            color: '#3498db',
            description: 'Triggers weekly on a specific day',
            config: {
                fields: [
                    { name: 'day', type: 'select', label: 'Day', required: true, options: [
                        { value: 'monday', label: 'Monday' },
                        { value: 'tuesday', label: 'Tuesday' },
                        { value: 'wednesday', label: 'Wednesday' },
                        { value: 'thursday', label: 'Thursday' },
                        { value: 'friday', label: 'Friday' },
                        { value: 'saturday', label: 'Saturday' },
                        { value: 'sunday', label: 'Sunday' }
                    ] },
                    { name: 'time', type: 'time', label: 'Time', required: true }
                ]
            }
        },
        monthly_on_date: {
            type: 'trigger',
            category: 'scheduled',
            label: 'Monthly on Date',
            icon: '📆',
            color: '#3498db',
            description: 'Triggers monthly on a specific date',
            config: {
                fields: [
                    { name: 'date', type: 'number', label: 'Date (1-31)', required: true, min: 1, max: 31 },
                    { name: 'time', type: 'time', label: 'Time', required: true }
                ]
            }
        },
        on_date: {
            type: 'trigger',
            category: 'scheduled',
            label: 'On Specific Date',
            icon: '📅',
            color: '#3498db',
            description: 'Triggers on a specific date',
            config: {
                fields: [
                    { name: 'date', type: 'date', label: 'Date', required: true },
                    { name: 'time', type: 'time', label: 'Time', required: true }
                ]
            }
        },
        contact_birthday: {
            type: 'trigger',
            category: 'scheduled',
            label: 'Contact Birthday',
            icon: '🎂',
            color: '#3498db',
            description: 'Triggers on contact birthday',
            config: {
                fields: []
            }
        },
        contact_anniversary: {
            type: 'trigger',
            category: 'scheduled',
            label: 'Contact Anniversary',
            icon: '🎉',
            color: '#3498db',
            description: 'Triggers on contact anniversary',
            config: {
                fields: []
            }
        },
        
        // Webhook Triggers
        webhook_received: {
            type: 'trigger',
            category: 'webhook',
            label: 'Webhook Received',
            icon: '🔗',
            color: '#e67e22',
            description: 'Triggers when a webhook is received',
            config: {
                fields: [
                    { name: 'webhook_url', type: 'text', label: 'Webhook URL', required: false },
                    { name: 'trigger_key', type: 'text', label: 'Trigger Key', required: false, placeholder: 'pricing-demo-request', help: 'Optional unique key for this workspace. Use letters, numbers, dots, underscores, colons, or hyphens.' }
                ]
            }
        },
        api_call: {
            type: 'trigger',
            category: 'webhook',
            label: 'API Call',
            icon: '🔌',
            color: '#e67e22',
            description: 'Triggers when API endpoint is called',
            config: {
                fields: [
                    { name: 'endpoint', type: 'text', label: 'Endpoint', required: false },
                    { name: 'trigger_key', type: 'text', label: 'Trigger Key', required: false, placeholder: 'pricing-demo-request', help: 'Optional unique key for this workspace. Use letters, numbers, dots, underscores, colons, or hyphens.' }
                ]
            }
        }
    },
    
    actions: {
        send_email: {
            type: 'action',
            category: 'communication',
            label: 'Send Email',
            icon: '📧',
            color: '#45b7d1',
            description: 'Send an email to the contact',
            config: {
                fields: [
                    { name: 'template_strategy', type: 'select', label: 'Template Selection', required: false, options: [
                        { value: 'fixed', label: 'Pick a specific template' },
                        { value: 'auto', label: 'Auto-pick best template' }
                    ], default: 'fixed' },
                    { name: 'template_id', type: 'select', label: 'Email Template', required: false, options: [] },
                    { name: 'template_intent_key', type: 'select', label: 'Workflow Intent', required: false, options: [] },
                    { name: 'template_purpose', type: 'select', label: 'Template Purpose', required: false, options: [] },
                    { name: 'template_tone', type: 'select', label: 'Tone', required: false, options: [] },
                    { name: 'template_lifecycle_stage', type: 'select', label: 'Lifecycle Stage', required: false, options: [] },
                    { name: 'template_audience', type: 'select', label: 'Audience', required: false, options: [] },
                    { name: 'subject', type: 'text', label: 'Subject', required: true },
                    { name: 'body', type: 'textarea', label: 'Body', required: true }
                ]
            }
        },
        send_whatsapp: {
            type: 'action',
            category: 'communication',
            label: 'Send WhatsApp',
            icon: '💬',
            color: '#25D366',
            description: 'Send a WhatsApp message',
            config: {
                fields: [
                    { name: 'message', type: 'textarea', label: 'Message', required: true }
                ]
            }
        },
        send_sms: {
            type: 'action',
            category: 'communication',
            label: 'Send SMS',
            icon: '📱',
            color: '#F62459',
            description: 'Send an SMS to the contact',
            config: {
                fields: [
                    { name: 'message', type: 'textarea', label: 'Message', required: true }
                ]
            }
        },
        add_tag: {
            type: 'action',
            category: 'contact',
            label: 'Add Tag',
            icon: '🏷️',
            color: '#9b59b6',
            description: 'Add a tag to the contact',
            config: {
                fields: [
                    { name: 'tag', type: 'select', label: 'Tag', required: true, options: [] }
                ]
            }
        },
        remove_tag: {
            type: 'action',
            category: 'contact',
            label: 'Remove Tag',
            icon: '🏷️',
            color: '#9b59b6',
            description: 'Remove a tag from the contact',
            config: {
                fields: [
                    { name: 'tag_name', type: 'text', label: 'Tag Name', required: true }
                ]
            }
        },
        apply_smart_tags: {
            type: 'action',
            category: 'contact',
            label: 'Apply Smart Tags',
            icon: '🤖',
            color: '#9b59b6',
            description: 'AI suggests and applies tags based on contact context',
            config: {
                fields: []
            }
        },
        change_stage: {
            type: 'action',
            category: 'contact',
            label: 'Change Stage',
            icon: '📊',
            color: '#e74c3c',
            description: 'Change contact stage',
            config: {
                fields: [
                    { name: 'stage', type: 'select', label: 'Stage', required: true, options: [] }
                ]
            }
        },
        create_task: {
            type: 'action',
            category: 'task',
            label: 'Create Task',
            icon: '📝',
            color: '#f9ca24',
            description: 'Create a new task',
            config: {
                fields: [
                    { name: 'title', type: 'text', label: 'Title', required: true },
                    { name: 'description', type: 'textarea', label: 'Description', required: false },
                    { name: 'due_date', type: 'date', label: 'Due Date', required: false },
                    { name: 'priority', type: 'select', label: 'Priority', required: false, options: [
                        { value: 'low', label: 'Low' },
                        { value: 'medium', label: 'Medium' },
                        { value: 'high', label: 'High' }
                    ] },
                    { name: 'assigned_to', type: 'select', label: 'Assigned To', required: false, options: [] }
                ]
            }
        },
        assign_to_user: {
            type: 'action',
            category: 'contact',
            label: 'Assign to User',
            icon: '👤',
            color: '#3498db',
            description: 'Assign contact to a user',
            config: {
                fields: [
                    { name: 'user_id', type: 'select', label: 'User', required: true, options: [] }
                ]
            }
        },
        wait_for_days: {
            type: 'action',
            category: 'timing',
            label: 'Wait for Days',
            icon: '⏱️',
            color: '#f9ca24',
            description: 'Wait for specified number of days',
            config: {
                fields: [
                    { name: 'days', type: 'number', label: 'Days', required: true, min: 1, default: 1 }
                ]
            }
        },
        update_contact_field: {
            type: 'action',
            category: 'contact',
            label: 'Update Contact Field',
            icon: '✏️',
            color: '#ff6b6b',
            description: 'Update a contact field',
            config: {
                fields: [
                    { name: 'field', type: 'select', label: 'Field', required: true, options: [] },
                    { name: 'value', type: 'text', label: 'Value', required: true }
                ]
            }
        },
        create_deal: {
            type: 'action',
            category: 'deal',
            label: 'Create Deal',
            icon: '💼',
            color: '#45b7d1',
            description: 'Create a new deal',
            config: {
                fields: [
                    { name: 'name', type: 'text', label: 'Deal Name', required: true },
                    { name: 'amount', type: 'number', label: 'Amount', required: false },
                    { name: 'stage', type: 'select', label: 'Stage', required: false, options: [] }
                ]
            }
        },
        update_deal_stage: {
            type: 'action',
            category: 'deal',
            label: 'Update Deal Stage',
            icon: '📊',
            color: '#45b7d1',
            description: 'Move deal to a new stage',
            config: {
                fields: [
                    { name: 'stage', type: 'select', label: 'Stage', required: true, options: [] },
                    { name: 'deal_id', type: 'number', label: 'Deal ID (optional)', required: false }
                ]
            }
        },
        add_to_deal: {
            type: 'action',
            category: 'deal',
            label: 'Add to Deal',
            icon: '➕',
            color: '#45b7d1',
            description: 'Link contact to deal or create new deal',
            config: {
                fields: [
                    { name: 'deal_id', type: 'number', label: 'Deal ID (optional)', required: false },
                    { name: 'title', type: 'text', label: 'Deal Title (if creating)', required: false },
                    { name: 'stage', type: 'select', label: 'Stage', required: false, options: [] }
                ]
            }
        },
        add_note: {
            type: 'action',
            category: 'contact',
            label: 'Add Note',
            icon: '📄',
            color: '#95a5a6',
            description: 'Add a note to the contact',
            config: {
                fields: [
                    { name: 'note', type: 'textarea', label: 'Note', required: true }
                ]
            }
        },
        create_activity: {
            type: 'action',
            category: 'contact',
            label: 'Create Activity',
            icon: '📅',
            color: '#95a5a6',
            description: 'Log an activity for the contact',
            config: {
                fields: [
                    { name: 'activity_type', type: 'text', label: 'Activity Type', required: true },
                    { name: 'description', type: 'textarea', label: 'Description', required: false }
                ]
            }
        },
        update_lead_score: {
            type: 'action',
            category: 'contact',
            label: 'Update Lead Score',
            icon: '⭐',
            color: '#f39c12',
            description: 'Update contact lead score',
            config: {
                fields: [
                    { name: 'score', type: 'number', label: 'Score', required: true },
                    { name: 'operation', type: 'select', label: 'Operation', required: false, options: [
                        { value: 'set', label: 'Set' },
                        { value: 'add', label: 'Add' },
                        { value: 'subtract', label: 'Subtract' }
                    ], default: 'set' }
                ]
            }
        },
        call_webhook: {
            type: 'action',
            category: 'integration',
            label: 'Call Webhook',
            icon: '🔗',
            color: '#e67e22',
            description: 'Call an external webhook',
            config: {
                fields: [
                    { name: 'url', type: 'text', label: 'Webhook URL', required: true },
                    { name: 'method', type: 'select', label: 'Method', required: false, options: [
                        { value: 'POST', label: 'POST' },
                        { value: 'GET', label: 'GET' },
                        { value: 'PUT', label: 'PUT' },
                        { value: 'DELETE', label: 'DELETE' }
                    ], default: 'POST' },
                    { name: 'payload', type: 'textarea', label: 'Payload (JSON)', required: false }
                ]
            }
        },
        send_in_app_notification: {
            type: 'action',
            category: 'communication',
            label: 'Send In-App Notification',
            icon: '🔔',
            color: '#9b59b6',
            description: 'Send an in-app notification to a user',
            config: {
                fields: [
                    { name: 'user_id', type: 'select', label: 'User', required: false, options: [] },
                    { name: 'title', type: 'text', label: 'Title', required: true },
                    { name: 'message', type: 'textarea', label: 'Message', required: true }
                ]
            }
        },
        remove_from_workflow: {
            type: 'action',
            category: 'workflow',
            label: 'Remove from Workflow',
            icon: '🚫',
            color: '#e74c3c',
            description: 'Stop this contact from receiving future workflow actions',
            config: {
                fields: []
            }
        }
    },
    
    conditions: {
        condition: {
            type: 'condition',
            category: 'logic',
            label: 'Condition',
            icon: '🔀',
            color: '#4ecdc4',
            description: 'Check if condition is met',
            config: {
                fields: [
                    { name: 'field', type: 'select', label: 'Field', required: true, options: [] },
                    { name: 'operator', type: 'select', label: 'Operator', required: true, options: [
                        { value: 'equals', label: 'Equals' },
                        { value: 'not_equals', label: 'Not Equals' },
                        { value: 'contains', label: 'Contains' },
                        { value: 'not_contains', label: 'Not Contains' },
                        { value: 'greater_than', label: 'Greater Than' },
                        { value: 'less_than', label: 'Less Than' },
                        { value: 'is_empty', label: 'Is Empty' },
                        { name: 'is_not_empty', label: 'Is Not Empty' }
                    ] },
                    { name: 'value', type: 'text', label: 'Value', required: false }
                ]
            },
            outputs: ['true', 'false'] // Condition nodes have two outputs
        }
    },
    
    /**
     * Get all nodes by category
     */
    getNodesByCategory(category) {
        const nodes = [];
        
        // Get triggers
        Object.keys(this.triggers).forEach(key => {
            if (this.triggers[key].category === category) {
                nodes.push({ ...this.triggers[key], key });
            }
        });
        
        // Get actions
        Object.keys(this.actions).forEach(key => {
            if (this.actions[key].category === category) {
                nodes.push({ ...this.actions[key], key });
            }
        });
        
        return nodes;
    },
    
    /**
     * Get node definition by key
     */
    getNode(key) {
        return this.triggers[key] || this.actions[key] || this.conditions[key] || null;
    },
    
    /**
     * Get all categories
     */
    getCategories() {
        const categories = new Set();
        
        Object.values(this.triggers).forEach(node => categories.add(node.category));
        Object.values(this.actions).forEach(node => categories.add(node.category));
        
        return Array.from(categories);
    },

    async parseJsonResponse(response, fallbackMessage) {
        const rawBody = await response.text();
        try {
            return JSON.parse(rawBody);
        } catch (parseError) {
            const cleanBody = rawBody.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
            throw new Error(cleanBody || fallbackMessage || `Request returned HTTP ${response.status}`);
        }
    },
    
    /**
     * Load options from backend (stages, tags, users, etc.)
     */
    async loadOptions() {
        if (this.optionsLoading) {
            return this.optionsLoading;
        }

        this.optionsLoading = (async () => {
        try {
            const response = await fetch('../api/workflows/available-nodes.php', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const data = await this.parseJsonResponse(response, `Workflow options returned HTTP ${response.status}`);
            
            if (data.success && data.options) {
                const stages = data.options.stages || [];
                const tags = data.options.tags || [];
                const users = data.options.users || [];
                const emailTemplates = data.options.email_templates || [];
                const emailTemplateFacets = data.options.email_template_facets || {};
                const customFields = data.options.custom_fields || [];

                // Update field options in node definitions
                Object.keys(this.triggers).forEach(key => {
                    const trigger = this.triggers[key];
                    if (trigger.config && trigger.config.fields) {
                        trigger.config.fields.forEach(field => {
                            if (field.name === 'stage' || field.name === 'from_stage' || field.name === 'to_stage') {
                                field.options = stages.map(s => ({ value: s, label: s }));
                            } else if (field.name === 'tag' || field.name === 'tag_id') {
                                field.options = tags.map(t => ({ value: t.id, label: t.name }));
                            } else if (field.name === 'user_id' || field.name === 'assigned_to') {
                                field.options = users.map(u => ({ value: u.id, label: u.email }));
                            } else if (field.name === 'template_id' || field.name === 'email_template') {
                                field.options = emailTemplates.map(t => ({
                                    value: t.id,
                                    label: t.name,
                                    subject: t.subject || '',
                                    body_text: t.body_text || '',
                                    body_html: t.body_html || ''
                                }));
                            } else if (field.name === 'field') {
                                // Combine custom fields with standard fields
                                const standardFields = [
                                    { value: 'stage', label: 'Stage' },
                                    { value: 'company', label: 'Company' },
                                    { value: 'email', label: 'Email' },
                                    { value: 'phone', label: 'Phone' },
                                    { value: 'lead_score', label: 'Lead Score' },
                                    { value: 'sentiment.sentiment', label: 'Message Sentiment (AI)' },
                                    { value: 'intent.intent', label: 'Message Intent (AI)' }
                                ];
                                const customFieldOptions = customFields.map(cf => ({
                                    value: 'custom_' + cf.id,
                                    label: cf.name + ' (Custom)'
                                }));
                                field.options = [...standardFields, ...customFieldOptions];
                            }
                        });
                    }
                });

                // Update condition field options
                if (this.conditions && this.conditions.condition && this.conditions.condition.config && this.conditions.condition.config.fields) {
                    this.conditions.condition.config.fields.forEach(field => {
                        if (field.name === 'field') {
                            const standardFields = [
                                { value: 'stage', label: 'Stage' },
                                { value: 'company', label: 'Company' },
                                { value: 'email', label: 'Email' },
                                { value: 'phone', label: 'Phone' },
                                { value: 'lead_score', label: 'Lead Score' },
                                { value: 'sentiment.sentiment', label: 'Message Sentiment (AI)' },
                                { value: 'intent.intent', label: 'Message Intent (AI)' }
                            ];
                            const customFieldOptions = customFields.map(cf => ({
                                value: 'custom_' + cf.id,
                                label: cf.name + ' (Custom)'
                            }));
                            field.options = [...standardFields, ...customFieldOptions];
                        }
                    });
                }

                Object.keys(this.actions).forEach(key => {
                    const action = this.actions[key];
                    if (action.config && action.config.fields) {
                        action.config.fields.forEach(field => {
                            if (field.name === 'stage') {
                                field.options = stages.map(s => ({ value: s, label: s }));
                            } else if (field.name === 'tag' || field.name === 'tag_id') {
                                field.options = tags.map(t => ({ value: t.id, label: t.name }));
                            } else if (field.name === 'user_id' || field.name === 'assigned_to') {
                                field.options = users.map(u => ({ value: u.id, label: u.email }));
                            } else if (field.name === 'template_id') {
                                field.options = emailTemplates.map(t => ({
                                    value: t.id,
                                    label: t.name,
                                    subject: t.subject || '',
                                    body_text: t.body_text || '',
                                    body_html: t.body_html || ''
                                }));
                            } else if (field.name === 'template_intent_key') {
                                const intents = [];
                                emailTemplates.forEach(t => {
                                    ((t.match_metadata && t.match_metadata.workflow_intents) || []).forEach(intent => {
                                        if (intent && !intents.includes(intent)) intents.push(intent);
                                    });
                                });
                                field.options = intents.sort().map(value => ({ value, label: value.replace(/_/g, ' ') }));
                            } else if (field.name === 'template_purpose') {
                                field.options = (emailTemplateFacets.purposes || []).map(value => ({ value, label: value.replace(/_/g, ' ') }));
                            } else if (field.name === 'template_tone') {
                                field.options = (emailTemplateFacets.tones || []).map(value => ({ value, label: value.replace(/_/g, ' ') }));
                            } else if (field.name === 'template_lifecycle_stage') {
                                field.options = (emailTemplateFacets.lifecycle_stages || []).map(value => ({ value, label: value.replace(/_/g, ' ') }));
                            } else if (field.name === 'template_audience') {
                                field.options = (emailTemplateFacets.audiences || []).map(value => ({ value, label: value.replace(/_/g, ' ') }));
                            } else if (field.name === 'field') {
                                const standardFields = [
                                    { value: 'stage', label: 'Stage' },
                                    { value: 'company', label: 'Company' },
                                    { value: 'email', label: 'Email' },
                                    { value: 'phone', label: 'Phone' }
                                ];
                                const customFieldOptions = customFields.map(cf => ({
                                    value: 'custom_' + cf.id,
                                    label: cf.name + ' (Custom)'
                                }));
                                field.options = [...standardFields, ...customFieldOptions];
                            }
                        });
                    }
                });
                
                this.optionsLoaded = true;
                return true;
            }

            this.optionsLoaded = false;
            return false;
        } catch (e) {
            console.error('Failed to load options:', e);
            this.optionsLoaded = false;
            return false;
        } finally {
            this.optionsLoading = null;
        }
        })();

        return this.optionsLoading;
    },
    
    /**
     * Verify all triggers/actions match backend
     */
    async syncWithBackend() {
        try {
            const response = await fetch('../api/workflows/available-nodes.php', {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });
            const data = await this.parseJsonResponse(response, `Workflow sync returned HTTP ${response.status}`);
            
            if (data.success) {
                const backendTriggers = data.triggers || [];
                const backendActions = data.actions || [];
                
                // Check for missing triggers
                const missingTriggers = backendTriggers.filter(t => !this.triggers[t]);
                if (missingTriggers.length > 0) {
                    console.warn('Missing triggers in visual builder:', missingTriggers);
                }
                
                // Check for missing actions
                const missingActions = backendActions.filter(a => !this.actions[a]);
                if (missingActions.length > 0) {
                    console.warn('Missing actions in visual builder:', missingActions);
                }
                
                // Check for extra triggers/actions not in backend
                const extraTriggers = Object.keys(this.triggers).filter(t => !in_array(t, backendTriggers));
                const extraActions = Object.keys(this.actions).filter(a => !in_array(a, backendActions));
                
                if (extraTriggers.length > 0) {
                    console.warn('Triggers in visual builder not in backend:', extraTriggers);
                }
                if (extraActions.length > 0) {
                    console.warn('Actions in visual builder not in backend:', extraActions);
                }
                
                return {
                    triggers: backendTriggers,
                    actions: backendActions,
                    missingTriggers,
                    missingActions
                };
            }
        } catch (e) {
            console.error('Failed to sync with backend:', e);
            return null;
        }
    }
};

// Helper function
function in_array(needle, haystack) {
    return haystack.indexOf(needle) !== -1;
}

// Make available globally
window.WorkflowNodeDefinitions = WorkflowNodeDefinitions;

// Load options on initialization
if (typeof window !== 'undefined') {
    window.addEventListener('load', () => {
        if (window.WorkflowNodeDefinitions) {
            window.WorkflowNodeDefinitions.loadOptions();
        }
    });
}
