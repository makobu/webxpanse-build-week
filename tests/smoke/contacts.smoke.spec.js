const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    createPageHealthMonitor,
    ensureContactExists,
    gotoAndWait,
    loginAsAdmin,
    uniqueSuffix
} = require('./helpers');

test.describe('Smoke: Contacts', () => {
    test('contacts list and detail load, and adding a note works', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsAdmin(page);
        const contact = await ensureContactExists(page);

        await gotoAndWait(page, `contact_view.php?id=${contact.id}`);
        await expect(page.getByRole('heading', { name: 'Current relationship health' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Share Calendar' })).toBeVisible();

        await page.getByRole('tab', { name: 'Meeting Prep' }).click();
        await expect(page.getByRole('heading', { name: 'Prep the next conversation' })).toBeVisible();
        await expect(page.locator('body')).toContainText('Generate on demand');

        await page.getByRole('tab', { name: 'Timeline' }).click();
        await expect(page.getByRole('heading', { name: 'Relationship Timeline' })).toBeVisible();

        await page.getByRole('tab', { name: 'Documents' }).click();
        await expect(page.getByRole('heading', { name: 'Documents' })).toBeVisible();

        await page.getByRole('tab', { name: 'Notes' }).click();
        await expect(page.getByRole('heading', { name: 'Notes' })).toBeVisible();

        const noteTitle = `Smoke Note ${uniqueSuffix('note')}`;
        const noteBody = 'Smoke note body for browser verification.';
        await page.fill('input[name="note_title"]', noteTitle);
        await page.locator('.rich-text-editor .ql-editor').first().click();
        await page.locator('.rich-text-editor .ql-editor').first().fill(noteBody);

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.getByRole('button', { name: 'Add Note' }).click()
        ]);

        await expect(page.locator('body')).toContainText(noteTitle);
        await expect(page.locator('body')).toContainText(noteBody);
        await expect(page.locator('body')).toContainText('Relationship');

        await page.getByRole('tab', { name: 'Meeting Prep' }).click();
        await expect(page.getByRole('heading', { name: 'Prep the next conversation' })).toBeVisible();
        await expect(page.locator('body')).toContainText('Generate on demand');

        await assertPageHealthy(page, health);
    });
});
