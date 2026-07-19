const { test, expect } = require('@playwright/test');
const {
    TEST_PASSWORD,
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    ensureUserWithRole,
    gotoAndWait,
    loginAsUser,
    runFixture
} = require('./helpers');

function resetWorkspaceBilling() {
    runFixture('configure_workspace_billing', {
        settings: {
            enabled: 0,
            paystack_mode: 'test',
            default_currency: 'KES',
            email_reminders_enabled: 1,
            in_app_prompts_enabled: 1,
            auto_lock_enabled: 1,
            grace_days: 3,
            reminder_days_before_json: JSON.stringify([7, 3, 1]),
            workspace_status: 'current',
            trial_starts_at: null,
            trial_ends_at: null,
            trial_granted_by: null,
            trial_notes: null
        }
    });
}

test.describe('Smoke: Workspace Billing RBAC', () => {
    test('billing view role can access the customer billing portal without admin controls', async ({ page }) => {
        const namespace = createFixtureNamespace('billing-view');
        const health = createPageHealthMonitor(page);
        const viewerEmail = `${namespace}-viewer@example.test`;

        ensureUserWithRole(
            viewerEmail,
            `${namespace}-role-view`,
            `Billing View ${namespace}`,
            ['billing.view'],
            { namespace, profile_role: 'viewer' }
        );

        runFixture('configure_workspace_billing', {
            settings: {
                enabled: 1,
                in_app_prompts_enabled: 1,
                auto_lock_enabled: 1,
                workspace_status: 'current',
                trial_starts_at: `${new Date().toISOString().slice(0, 10)} 00:00:00`,
                trial_ends_at: `${new Date(Date.now() + (7 * 24 * 60 * 60 * 1000)).toISOString().slice(0, 10)} 23:59:59`,
                trial_granted_by: 1,
                trial_notes: `Playwright trial ${namespace}`
            }
        });
        runFixture('create_workspace_billing_charge', {
            namespace,
            amount: 4800,
            due_date: new Date(Date.now() - (10 * 24 * 60 * 60 * 1000)).toISOString().slice(0, 10)
        });

        try {
            await loginAsUser(page, viewerEmail, TEST_PASSWORD);

            await gotoAndWait(page, 'dashboard.php');
            await expect(page.locator('body')).not.toContainText('Workspace Payment Reminder');

            await gotoAndWait(page, 'settings.php?tab=billing');
            await expect(page).toHaveURL(/billing_payment_required\.php/i);
            await expect(page.locator('body')).toContainText('Workspace Billing');
            await expect(page.locator('body')).toContainText('Status');
            await expect(page.locator('body')).toContainText(/Inactive|Trialing|Active/i);
            await expect(page.locator('body')).toContainText('AI Credit Balance');
            await expect(page.locator('body')).toContainText(/Workspace packages|Buy AI Credits|AI Credits/i);
            await expect(page.getByRole('button', { name: 'Save Billing Configuration' })).toHaveCount(0);
            await expect(page.locator('text=Grant or extend trial')).toHaveCount(0);
            await expect(page.locator('text=Add one-time charge')).toHaveCount(0);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
            resetWorkspaceBilling();
        }
    });

    test('billing edit role can edit plans and charges but not billing admin controls', async ({ page }) => {
        const namespace = createFixtureNamespace('billing-edit');
        const health = createPageHealthMonitor(page);
        const editorEmail = `${namespace}-editor@example.test`;

        ensureUserWithRole(
            editorEmail,
            `${namespace}-role-edit`,
            `Billing Edit ${namespace}`,
            ['billing.view', 'billing.edit'],
            { namespace, profile_role: 'sales' }
        );

        resetWorkspaceBilling();

        try {
            await loginAsUser(page, editorEmail, TEST_PASSWORD);

            await gotoAndWait(page, 'billing_payment_required.php');
            await expect(page.locator('body')).toContainText('Workspace Billing');
            await expect(page.locator('body')).not.toContainText('Billing is in read-only mode for your role');
            await expect(page.locator('body')).toContainText(/Workspace packages|Buy AI Credits|AI Credits|Change Plan/i);
            await expect(page.locator('body')).toContainText(/Payment methods are temporarily unavailable|Payment method/i);
            await expect(page.locator('text=Grant or extend trial')).toHaveCount(0);
            await expect(page.locator('text=Add one-time charge')).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Run Maintenance Now' })).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Save Billing Configuration' })).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Save Billing Settings' })).toHaveCount(0);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
            resetWorkspaceBilling();
        }
    });

    test('trial manager role can grant trials but cannot edit charges or billing admin controls', async ({ page }) => {
        const namespace = createFixtureNamespace('billing-trial-manager');
        const health = createPageHealthMonitor(page);
        const managerEmail = `${namespace}-manager@example.test`;

        ensureUserWithRole(
            managerEmail,
            `${namespace}-role-trial`,
            `Billing Trial ${namespace}`,
            ['billing.view', 'billing.trials.manage'],
            { namespace, profile_role: 'sales' }
        );

        resetWorkspaceBilling();

        try {
            await loginAsUser(page, managerEmail, TEST_PASSWORD);

            await gotoAndWait(page, 'billing_payment_required.php');
            await expect(page.locator('body')).toContainText('Workspace Billing');
            await expect(page.locator('body')).toContainText(/Workspace packages|Buy AI Credits|AI Credits|Change Plan/i);
            await expect(page.locator('text=Grant or extend trial')).toHaveCount(0);
            await expect(page.locator('text=Add one-time charge')).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Run Maintenance Now' })).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Save Billing Settings' })).toHaveCount(0);
            await expect(page.getByRole('button', { name: /Grant Trial|Extend Trial/i })).toHaveCount(0);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
            resetWorkspaceBilling();
        }
    });
});
