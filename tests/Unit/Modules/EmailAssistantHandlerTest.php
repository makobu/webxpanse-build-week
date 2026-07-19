<?php
/**
 * Email Assistant Handler Tests
 * Tests intent classification (keyword fallbacks) and response semantics.
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\TestCase;
use CRM\Modules\EmailAssistantHandler;

class EmailAssistantHandlerTest extends TestCase
{
    private EmailAssistantHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->handler = new EmailAssistantHandler();
    }

    public function testClassifyIntentCreateTask(): void
    {
        $this->assertSame('create_task', $this->handler->classifyIntent('Create task: Follow up with client'));
        $this->assertSame('create_task', $this->handler->classifyIntent('Add new task for tomorrow'));
        $this->assertSame('create_task', $this->handler->classifyIntent('task: Call John'));
    }

    public function testClassifyIntentCreateContact(): void
    {
        $this->assertSame('create_contact', $this->handler->classifyIntent('Create contact John Doe john@example.com'));
        $this->assertSame('create_contact', $this->handler->classifyIntent('Add new contact'));
    }

    public function testClassifyIntentUpdateContact(): void
    {
        $this->assertSame('update_contact', $this->handler->classifyIntent('Update contact john@example.com: set company to Acme'));
        $this->assertSame('update_contact', $this->handler->classifyIntent('Edit contact John Doe'));
        $this->assertSame('update_contact', $this->handler->classifyIntent('Change contact email'));
    }

    public function testClassifyIntentDeleteContact(): void
    {
        $this->assertSame('delete_contact', $this->handler->classifyIntent('Delete contact john@example.com'));
        $this->assertSame('delete_contact', $this->handler->classifyIntent('Remove contact John Doe'));
    }

    public function testClassifyIntentEnrichContact(): void
    {
        $this->assertSame('enrich_contact', $this->handler->classifyIntent('Enrich contact john@example.com'));
        $this->assertSame('enrich_contact', $this->handler->classifyIntent('Enrich contact John'));
    }

    public function testClassifyIntentVerifyEmail(): void
    {
        $this->assertSame('verify_contact_email', $this->handler->classifyIntent('Verify email for contact John'));
        $this->assertSame('verify_contact_email', $this->handler->classifyIntent('Verify contact email'));
        $this->assertSame('verify_contact_email', $this->handler->classifyIntent('Email verification for john@example.com'));
    }

    public function testClassifyIntentAddNote(): void
    {
        $this->assertSame('add_note', $this->handler->classifyIntent('Add note to John: called him'));
        $this->assertSame('add_note', $this->handler->classifyIntent('Note to contact about meeting'));
    }

    public function testClassifyIntentGetPipeline(): void
    {
        $this->assertSame('get_pipeline', $this->handler->classifyIntent('Pipeline status'));
        $this->assertSame('get_pipeline', $this->handler->classifyIntent('How many deals do I have?'));
    }

    public function testClassifyIntentListTasks(): void
    {
        $this->assertSame('list_tasks', $this->handler->classifyIntent('List my tasks'));
        $this->assertSame('list_tasks', $this->handler->classifyIntent('Show tasks for today'));
    }

    public function testClassifyIntentScheduleEvent(): void
    {
        $this->assertSame('schedule_event', $this->handler->classifyIntent('Schedule meeting tomorrow at 2pm'));
        $this->assertSame('schedule_event', $this->handler->classifyIntent('Create event: Team sync'));
    }

    public function testClassifyIntentRunReport(): void
    {
        $this->assertSame('run_report', $this->handler->classifyIntent('Run report: Sales summary'));
    }

    public function testClassifyInvoiceIntents(): void
    {
        $this->assertSame('create_invoice', $this->handler->classifyIntent('Create quote for deal 12'));
        $this->assertSame('send_invoice', $this->handler->classifyIntent('Send invoice INV-1001 to billing@example.com'));
        $this->assertSame('finalize_invoice', $this->handler->classifyIntent('Finalize invoice INV-1001'));
        $this->assertSame('mark_invoice_paid', $this->handler->classifyIntent('Mark invoice INV-1001 paid'));
        $this->assertSame('convert_quote_to_invoice', $this->handler->classifyIntent('Convert quote Q-1001 to invoice'));
        $this->assertSame('update_invoice', $this->handler->classifyIntent('Revise quote Q-1001 with 10 discount'));
        $this->assertSame('list_invoices', $this->handler->classifyIntent('List invoices'));
    }

    public function testClassifyCommercialAutomationIntents(): void
    {
        $this->assertSame('approve_commercial_action', $this->handler->classifyIntent('Approve commercial approval 12'));
        $this->assertSame('reject_commercial_action', $this->handler->classifyIntent('Reject automation approval #13'));
        $this->assertSame('summarize_commercial_automation_state', $this->handler->classifyIntent('Show commercial automation state for deal 4'));
        $this->assertSame('draft_customer_reply', $this->handler->classifyIntent('AI reply to this customer thread'));
        $this->assertSame('send_customer_reply', $this->handler->classifyIntent('Send customer reply'));
        $this->assertSame('list_pending_commercial_approvals', $this->handler->classifyIntent('List pending commercial approvals'));
        $this->assertSame('show_last_assistant_action', $this->handler->classifyIntent('What did the assistant last send?'));
    }

    public function testClassifyIntentQuestion(): void
    {
        $this->assertSame('question', $this->handler->classifyIntent('What is the total revenue?'));
        $this->assertSame('question', $this->handler->classifyIntent('Tell me who the top contacts are'));
    }

    public function testClassifyIntentCaseInsensitive(): void
    {
        $this->assertSame('create_task', $this->handler->classifyIntent('CREATE TASK: Test'));
        $this->assertSame('delete_contact', $this->handler->classifyIntent('DELETE contact john@test.com'));
    }
}
