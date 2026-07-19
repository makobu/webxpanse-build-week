const { test, expect } = require('@playwright/test');
const {
    BASE_URL,
    TEST_PASSWORD,
    assertPageHealthy,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsUser,
    runFixture,
    stabilizeBackgroundRequests,
    uniqueSuffix
} = require('./helpers');

test.describe('Smoke: Workspace Launch Certification', () => {
    test('workspace SaaS journey stays healthy end to end', async ({ browser, page }) => {
        test.setTimeout(120000);
        const namespace = uniqueSuffix('launch-cert');
        const workspaceSlug = `${namespace}-workspace`;
        const workspaceName = `Launch ${namespace}`;
        const ownerEmail = `${namespace}.owner@example.test`;
        const inviteeEmail = `${namespace}.invitee@example.test`;
        const ownerHealth = createPageHealthMonitor(page);
        let inviteContext = null;

        try {
            await stabilizeBackgroundRequests(page);
            await gotoAndWait(page, 'signup.php');
            await expect(page.locator('#workspace_slug')).toHaveCount(0);
            await expect(page.locator('input[name="workspace_slug"]')).toHaveCount(0);
            await page.fill('#workspace_name', workspaceName);
            await page.fill('#first_name', 'Launch');
            await page.fill('#last_name', 'Owner');
            await page.fill('#email', ownerEmail);
            await page.fill('#password', TEST_PASSWORD);

            await Promise.all([
                page.waitForURL(/onboarding\.php\?signup_success=1/i, { timeout: 30000 }),
                page.getByRole('button', { name: /create workspace/i }).click()
            ]);

            await expect(page.locator('body')).toContainText('Business setup');
            await expect(page.locator('body')).toContainText('Your new workspace is ready');
            await expect(page.locator('body')).toContainText('Workspace created. Start by giving Clarity the business context.');

            await loginAsUser(page, ownerEmail, TEST_PASSWORD);
            await gotoAndWait(page, 'billing_payment_required.php');
            await expect(page.locator('body')).toContainText('Workspace Billing');
            await expect(page.locator('body')).toContainText(/Keep workspace running|Workspace affordability|Billing and AI Credits|Trial active|Subscription update required|Workspace packages and AI Credits/);
            await expect(page.locator('body')).toContainText(/Buy AI Credits|Workspace packages/);
            await expect(page.locator('body')).not.toContainText('Billing readiness warning');

            await gotoAndWait(page, 'users.php');
            await expect(page.locator('body')).toContainText('Users');
            await expect(page.locator('body')).not.toContainText('Workspace team readiness warning');

            const workspaceContext = runFixture('get_workspace_context', {
                workspace_slug: workspaceName.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''),
                owner_email: ownerEmail
            });

            const packageCheckout = runFixture('create_workspace_checkout', {
                workspace_id: workspaceContext.workspace_id,
                user_id: workspaceContext.user_id,
                billing_plan_price_code: 'founder-plus-monthly',
                payment_mode: 'mpesa',
                customer_phone: '0712345678'
            });
            const packageVerification = runFixture('verify_workspace_checkout', {
                reference: packageCheckout.reference
            });
            expect(packageVerification.success).toBeTruthy();
            runFixture('process_workspace_checkout_webhook', {
                reference: packageCheckout.reference,
                event: 'charge.success',
                status: 'success'
            });

            const invite = runFixture('create_workspace_invite', {
                workspace_id: workspaceContext.workspace_id,
                actor_user_id: workspaceContext.user_id,
                email: inviteeEmail,
                role_slug: 'viewer'
            });

            await gotoAndWait(page, 'users.php');
            await expect(page.locator('body')).toContainText('Users');

            inviteContext = await browser.newContext();
            const invitePage = await inviteContext.newPage();
            const inviteHealth = createPageHealthMonitor(invitePage);
            await stabilizeBackgroundRequests(invitePage);
            await gotoAndWait(invitePage, `workspace_invite.php?token=${encodeURIComponent(invite.token)}`);
            await expect(invitePage.locator('body')).toContainText('Workspace Invite');
            await expect(invitePage.locator('body')).toContainText(workspaceName);

            await invitePage.fill('input[name="first_name"]', 'Invitee');
            await invitePage.fill('input[name="last_name"]', 'User');
            await invitePage.fill('input[name="password"]', TEST_PASSWORD);
            await invitePage.fill('input[name="password_confirm"]', TEST_PASSWORD);

            await Promise.all([
                invitePage.waitForURL(/dashboard\.php/i, { timeout: 15000 }),
                invitePage.getByRole('button', { name: /accept invite|join workspace|create account/i }).click()
            ]);
            await expect(invitePage.locator('.navbar')).toBeVisible({ timeout: 15000 });
            await assertPageHealthy(invitePage, inviteHealth);

            const checkout = runFixture('create_workspace_checkout', {
                workspace_id: workspaceContext.workspace_id,
                user_id: workspaceContext.user_id,
                token_pack_price_code: 'starter-ai-credits-100k',
                payment_mode: 'card'
            });
            const verification = runFixture('verify_workspace_checkout', {
                reference: checkout.reference
            });
            expect(verification.success).toBeTruthy();

            await page.goto(`${BASE_URL}/billing_callback.php?reference=${encodeURIComponent(checkout.reference)}&return_to=${encodeURIComponent('billing_payment_required.php?tab=activity')}`, {
                waitUntil: 'domcontentloaded'
            });
            await page.waitForURL(/billing_payment_required\.php.*billing_status=success/i, { timeout: 15000 });

            runFixture('process_workspace_checkout_webhook', {
                reference: checkout.reference,
                event: 'charge.success',
                status: 'success'
            });

            await gotoAndWait(page, 'billing_payment_required.php?tab=activity');
            await expect(page.locator('body')).toContainText(/Latest checkout|Recent payment activity/);
            await expect(page.locator('body')).toContainText(/Token Pack Purchase|Token Pack/);
            await expect(page.locator('body')).toContainText(/KES 150\.00|100,000|3,650,000 available/);

            await gotoAndWait(page, 'dashboard.php');
            await expect(page.locator('.navbar')).toBeVisible({ timeout: 15000 });
            await assertPageHealthy(page, ownerHealth);
        } finally {
            if (inviteContext) {
                await inviteContext.close();
            }
            runFixture('cleanup_workspace_namespace', { namespace });
        }
    });
});
