const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsAdmin,
    seedEmailConversationFixture
} = require('./helpers');

test.describe('Smoke: Inbox', () => {
    test('inbox loads, filters render, and a conversation can be opened', async ({ page }) => {
        const namespace = createFixtureNamespace('smoke-inbox');
        const health = createPageHealthMonitor(page);
        seedEmailConversationFixture(namespace);

        page.on('dialog', async (dialog) => {
            await dialog.accept();
        });

        try {
            await loginAsAdmin(page);
            await gotoAndWait(page, 'inbox.php');

            const headerActions = page.locator('.page-header-actions');
            await expect(headerActions.getByRole('link', { name: /whatsapp/i })).toHaveAttribute('href', /whatsapp_compose\.php/);
            await expect(headerActions.getByRole('link', { name: /email/i })).toHaveAttribute('href', /email_compose\.php/);
            await expect(headerActions.getByRole('button', { name: /refresh/i })).toHaveCount(0);
            await expect(headerActions.getByRole('button', { name: /fetch emails/i })).toHaveCount(0);
            await expect(page.locator('#search')).toBeVisible();
            await expect(page.locator('#triage_status')).toBeVisible();
            await page.fill('#search', namespace);
            await page.getByRole('button', { name: /filter/i }).click();
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
            await expect(page.locator('body')).toContainText(namespace);

            const conversationLink = page.locator('a[href*="conversation.php?id="]').first();
            await expect(conversationLink).toBeVisible({ timeout: 15000 });
            const href = await conversationLink.getAttribute('href');
            if (!href) {
                throw new Error('Expected a conversation href after inbox filtering.');
            }

            await gotoAndWait(page, href.replace(/^\//, ''));

            await expect(page.locator('#conversation-reply-form')).toBeVisible();
            await expect(page.locator('body')).toContainText(namespace);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });
});
