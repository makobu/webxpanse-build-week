const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    ensureContactExists,
    ensureUser,
    gotoAndWait,
    loginAsAdmin,
    seedDealFixture,
    seedEmailConversationFixture,
    seedTaskFixture,
    TEST_EMAIL
} = require('./helpers');

test.describe('Smoke: Business Continuity', () => {
    test('critical business routes remain usable with takeover audit coverage in place', async ({ page }) => {
        test.setTimeout(90000);

        const inboxNamespace = createFixtureNamespace('takeover-inbox');
        const dealNamespace = createFixtureNamespace('takeover-deal');
        const taskNamespace = createFixtureNamespace('takeover-task');
        const health = createPageHealthMonitor(page);
        ensureUser(TEST_EMAIL, {
            profile_role: 'admin',
            first_name: 'Launch',
            last_name: 'Admin'
        });
        const deal = seedDealFixture(dealNamespace);
        const task = seedTaskFixture(taskNamespace);
        seedEmailConversationFixture(inboxNamespace);

        page.on('dialog', async (dialog) => {
            await dialog.accept().catch(() => {});
        });

        try {
            await loginAsAdmin(page);

            await gotoAndWait(page, 'dashboard.php');
            await expect(page.locator('.hero-automation-shell')).toBeVisible();
            await expect(page.locator('body')).toContainText(/Activation Progress|Revenue Momentum/);

            await gotoAndWait(page, 'contacts.php');
            const contact = await ensureContactExists(page);
            await gotoAndWait(page, `contact_view.php?id=${contact.id}`);
            await expect(page.locator('body')).toContainText('Relationship');

            await gotoAndWait(page, 'deals_proposal.php');
            await expect(page.locator('body')).toContainText(/Proposal Deals|Proposal/i);
            const transitionSelect = page.locator(`.deal-transition-select[data-deal-id="${deal.deal_id}"]`).first();
            const transitionButton = page.locator(`.deal-transition-button[data-deal-id="${deal.deal_id}"]`).first();
            await expect(transitionSelect).toBeVisible();
            await expect(transitionButton).toBeVisible();
            await transitionSelect.selectOption('negotiation');
            await transitionButton.click();
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
            await gotoAndWait(page, 'deals_negotiation.php');
            await expect(page.locator('body')).toContainText(deal.title);

            await gotoAndWait(page, 'inbox.php');
            await expect(page.locator('#search')).toBeVisible();
            await page.fill('#search', inboxNamespace);
            await page.getByRole('button', { name: /filter/i }).click();
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
            await expect(page.locator('body')).toContainText(inboxNamespace);
            const conversationLink = page.locator('a[href*="conversation.php?id="]').first();
            await expect(conversationLink).toBeVisible({ timeout: 15000 });
            const href = await conversationLink.getAttribute('href');
            if (!href) {
                throw new Error('Expected a conversation link after filtering the inbox continuity fixture.');
            }
            await gotoAndWait(page, href.replace(/^\//, ''));
            await expect(page.locator('#conversation-reply-form')).toBeVisible();

            await gotoAndWait(page, `task_view.php?id=${task.task_id}`);
            const checkbox = page.locator('.task-subtask-toggle').first();
            await expect(checkbox).toBeVisible();
            const wasChecked = await checkbox.isChecked();
            await checkbox.click();
            await page.waitForTimeout(750);
            await expect(checkbox).toHaveJSProperty('checked', !wasChecked);

            await gotoAndWait(page, 'settings.php');
            await expect(page.locator('body')).toContainText('Settings');
            await page.getByRole('button', { name: 'Change section' }).click();
            await expect(page.getByRole('link', { name: 'AI Services' })).toBeVisible();
            await expect(page.getByRole('link', { name: 'Email', exact: true })).toBeVisible();

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(inboxNamespace);
            cleanupFixtureNamespace(dealNamespace);
            cleanupFixtureNamespace(taskNamespace);
        }
    });
});
