<?php
/**
 * Analytics Dashboard Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\AnalyticsDashboard;
use CRM\Database;
use CRM\Services\WorkspaceContext;

class AnalyticsDashboardTest extends DatabaseTestCase
{
    private AnalyticsDashboard $analytics;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->analytics = new AnalyticsDashboard();
    }
    
    public function testGetRealTimeMetricsToday()
    {
        // Create test contact
        Database::execute(
            "INSERT INTO contacts (first_name, last_name, email, created_at) 
             VALUES (?, ?, ?, NOW())",
            ['John', 'Doe', 'john@example.com']
        );
        
        $metrics = $this->analytics->getRealTimeMetrics('today');
        
        $this->assertIsArray($metrics);
        $this->assertArrayHasKey('leads', $metrics);
        $this->assertArrayHasKey('emails_sent', $metrics);
        $this->assertArrayHasKey('emails_opened', $metrics);
        $this->assertArrayHasKey('form_submissions', $metrics);
    }
    
    public function testGetRealTimeMetricsWeek()
    {
        $metrics = $this->analytics->getRealTimeMetrics('week');
        
        $this->assertIsArray($metrics);
        $this->assertIsInt($metrics['leads']);
    }

    public function testEmailMetricsCountOpenedEmailsOnceAcrossMultipleTrackingEvents(): void
    {
        $workspaceId = $this->createTenantWorkspace('Email Metrics Tenant', 'email-metrics-tenant');
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (?, UUID(), 'Email', 'Metric', 'email.metric@example.test', NOW())",
            [$workspaceId]
        );
        $contactId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO emails
                (uuid, workspace_id, contact_id, to_email, from_email, subject, body, status, created_at)
             VALUES
                (UUID(), ?, ?, 'email.metric@example.test', 'sender@example.test', 'Metric test', 'Hello', 'opened', NOW())",
            [$workspaceId, $contactId]
        );
        $emailId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO email_tracking (email_id, tracking_type, tracked_at)
             VALUES (?, 'open', NOW()), (?, 'open', NOW()), (?, 'click', NOW())",
            [$emailId, $emailId, $emailId]
        );

        $metrics = $this->analytics->getRealTimeMetrics('today');

        $this->assertSame(1, (int) $metrics['emails_sent']);
        $this->assertSame(1, (int) $metrics['emails_opened']);
    }
    
    public function testGetFunnelAnalysis()
    {
        $funnel = $this->analytics->getFunnelAnalysis('2026-01-01', '2026-12-31');
        
        $this->assertIsArray($funnel);
        $this->assertArrayHasKey('new', $funnel);
        $this->assertArrayHasKey('won', $funnel);
    }

    public function testDefaultWorkspaceRevenueUsesSucceededSubscriptionCharges(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1);
        $tenantWorkspaceId = $this->createTenantWorkspace('Analytics Paid Tenant', 'analytics-paid-tenant');
        Database::execute(
            "INSERT INTO workspace_subscriptions
             (workspace_id, billing_plan_price_id, provider, provider_reference, subscription_status, current_period_start, current_period_end, next_billing_at)
             VALUES (?, 1, 'test', 'sub-analytics-paid', 'active', '2026-05-01 00:00:00', '2026-06-01 00:00:00', '2026-06-01 00:00:00')",
            [$tenantWorkspaceId]
        );
        $subscriptionId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO billing_transactions
             (workspace_id, subscription_id, provider, provider_reference, transaction_type, transaction_status, amount, currency, created_at)
             VALUES (?, ?, 'test', 'txn-analytics-paid', 'subscription_charge', 'succeeded', 2500, 'KES', '2026-05-10 12:00:00')",
            [$tenantWorkspaceId, $subscriptionId]
        );
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, company, lead_source, stage, metadata_json, created_at)
             VALUES (1, UUID(), 'Paid', 'Owner', 'paid.analytics@example.test', 'Analytics Paid Tenant', 'other', 'won', ?, NOW())",
            [json_encode([
                'source' => 'default_workspace_owner_contact',
                'owner_workspace_id' => $tenantWorkspaceId,
                'current_paying_customer' => true,
                'default_workspace_contact_scope' => 'current_paying_customer',
            ])]
        );

        $revenue = $this->analytics->getRevenueAnalytics('2026-05-01', '2026-05-31');

        $this->assertSame(2500.0, (float) $revenue['total_revenue']);
        $this->assertSame(1, (int) $revenue['deal_count']);
        $this->assertSame(2500.0, (float) $revenue['avg_deal_size']);
        $this->assertSame('2026-05', (string) ($revenue['revenue_trend'][0]['month'] ?? ''));
        $this->assertSame(2500.0, (float) ($revenue['revenue_by_source'][0]['total_revenue'] ?? 0));
    }

    public function testTenantWorkspaceRevenueRemainsDealBased(): void
    {
        $tenantWorkspaceId = $this->createTenantWorkspace('Deal Analytics Tenant', 'deal-analytics-tenant');
        WorkspaceContext::activateRuntimeWorkspace($tenantWorkspaceId);
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (UUID(), 'deal.analytics@example.test', ?, 'sales', NOW())",
            [password_hash('password', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO deals (workspace_id, title, created_by, assigned_to, stage, value, probability, actual_close_date, created_at)
             VALUES (?, 'Tenant won deal', ?, ?, 'closed_won', 1800, 100, '2026-05-11', '2026-05-01 00:00:00')",
            [$tenantWorkspaceId, $userId, $userId]
        );
        Database::execute(
            "INSERT INTO billing_transactions
             (workspace_id, provider, provider_reference, transaction_type, transaction_status, amount, currency, created_at)
             VALUES (?, 'test', 'tenant-subscription-ignored', 'subscription_charge', 'succeeded', 9900, 'KES', '2026-05-11 00:00:00')",
            [$tenantWorkspaceId]
        );

        $revenue = $this->analytics->getRevenueAnalytics('2026-05-01', '2026-05-31');

        $this->assertSame(1800.0, (float) $revenue['total_revenue']);
        $this->assertSame(1, (int) $revenue['deal_count']);
        $this->assertSame(1800.0, (float) $revenue['avg_deal_size']);
    }

    private function createTenantWorkspace(string $name, string $slug): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at)
             VALUES (UUID(), ?, ?, 'active', 'trialing', NOW())",
            [$name, $slug]
        );

        return (int) Database::lastInsertId();
    }
}
