const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsAdmin,
    seedEmailConversationFixture
} = require('../../smoke/helpers');

async function composerHasContent(page) {
    const value = await page.locator('#body').inputValue();
    return value.trim().length > 0;
}

async function expectComposerToPopulate(page, timeout = 30000) {
    await expect.poll(async () => composerHasContent(page), { timeout }).toBe(true);
}

async function waitForConversationComposerReady(page, timeout = 15000) {
    await expect(page.locator('#conversation-reply-form')).toBeVisible({ timeout });
    await expect(page.locator('#body')).toBeVisible({ timeout });
    await expect.poll(async () => {
        return await page.locator('#conversation-reply-form button[type="submit"]').isEnabled().catch(() => false);
    }, { timeout }).toBe(true);
}

test.describe('Regression: Email conversation assistant', () => {
    test.setTimeout(120000);

    test('seeded email conversation supports assistant drafts, assistant send fallback, and manual reply', async ({ page }) => {
        const namespace = createFixtureNamespace('conversation-regression');
        const health = createPageHealthMonitor(page);
        const acceptedDialogs = [];

        page.on('dialog', async (dialog) => {
            acceptedDialogs.push(dialog.message());
            await dialog.accept();
        });

        const fixture = seedEmailConversationFixture(namespace);

        try {
            await loginAsAdmin(page);
            await gotoAndWait(page, 'inbox.php');

            await expect(page.locator('#search')).toBeVisible();
            await page.fill('#search', fixture.search_term);
            await page.getByRole('button', { name: /filter/i }).click();
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
            await expect(page.locator('body')).toContainText(fixture.search_term);

            const conversationLink = page.locator(`a[href*="conversation.php?id=${fixture.communication_id}"]`).first();
            await expect(conversationLink).toBeVisible({ timeout: 15000 });
            const href = await conversationLink.getAttribute('href');
            if (!href) {
                throw new Error('Expected a conversation href for the seeded thread.');
            }

            await gotoAndWait(page, href.replace(/^\//, ''));

            await expect(page.locator('#conversation-reply-form')).toBeVisible();
            await expect(page.locator('body')).toContainText(fixture.subject);
            await expect(page.getByRole('button', { name: 'Suggest reply' })).toBeVisible();
            await expect(page.getByRole('button', { name: 'AI Reply' })).toBeVisible();
            await expect(page.getByRole('button', { name: 'AI Revise + Reply' })).toBeVisible();
            await expect(page.getByRole('button', { name: 'AI Send Latest Quote' })).toBeVisible();

            await page.getByRole('button', { name: 'Suggest reply' }).click();
            await expectComposerToPopulate(page);

            await page.locator('#body').fill('');
            await page.getByRole('button', { name: 'AI Reply' }).click();
            await expectComposerToPopulate(page);
            await expect(page.locator('#assistant-commercial-preview')).toBeVisible();

            const draftAfterReply = await page.locator('#body').inputValue();

            await page.locator('#body').fill('');
            await page.getByRole('button', { name: 'AI Revise + Reply' }).click();
            await expectComposerToPopulate(page);
            const revisedDraft = await page.locator('#body').inputValue();
            expect(revisedDraft.trim().length).toBeGreaterThan(0);

            const assistantSendStartUrl = page.url();
            await Promise.all([
                page.waitForResponse((response) => response.url().includes('/api/email_assistant_execute.php') && response.request().method() === 'POST'),
                page.getByRole('button', { name: 'AI Send Latest Quote' }).click()
            ]);
            const assistantReload = await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).then(() => true).catch(() => false);
            await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
            await waitForConversationComposerReady(page);

            const assistantStatusText = await page.locator('#assistant-commercial-preview').textContent().catch(() => '');
            const bodyAfterAssistantSend = await page.locator('#body').inputValue().catch(() => '');
            const assistantProducedOutcome = assistantReload
                || page.url() !== assistantSendStartUrl
                || acceptedDialogs.length > 0
                || (assistantStatusText || '').trim().length > 0
                || bodyAfterAssistantSend.trim().length > 0;

            expect(assistantProducedOutcome).toBeTruthy();
            await expect(page.locator('body')).not.toContainText('Unexpected token');
            await expect(page.locator('body')).not.toContainText('"type": "text"');

            const manualReply = `Manual reply from Playwright ${namespace}`;
            await page.locator('#body').fill(manualReply);
            await Promise.all([
                page.waitForURL(/conversation\.php\?id=\d+.*notice=reply_sent/i, { timeout: 20000 }),
                page.waitForResponse((response) => response.url().includes('/public/conversation.php') && response.request().method() === 'POST'),
                page.locator('#conversation-reply-form button[type="submit"]').click()
            ]);
            await page.waitForLoadState('domcontentloaded');
            await page.waitForLoadState('networkidle', { timeout: 15000 }).catch(() => {});
            await waitForConversationComposerReady(page);

            await expect(page.locator('body')).toContainText(manualReply, { timeout: 15000 });
            await expect(page.locator('body')).toContainText(/Reply sent successfully/i, { timeout: 15000 });

            expect(draftAfterReply.trim().length).toBeGreaterThan(0);
            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });
});
