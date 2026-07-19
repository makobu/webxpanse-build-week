const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsAdmin,
    runFixture,
    uniqueSuffix
} = require('./helpers');

test.describe('Smoke: Forms', () => {
    test('forms list renders as a compact work surface', async ({ page }) => {
        const namespace = uniqueSuffix('forms');
        const health = createPageHealthMonitor(page);

        await loginAsAdmin(page);
        runFixture('cleanup_namespace', { namespace });
        const form = runFixture('seed_form', { namespace });
        const formName = form.name || `Smoke Form ${namespace}`;

        try {
            await gotoAndWait(page, 'forms.php');

            await expect(page.getByRole('heading', { name: 'Forms' })).toBeVisible();
            await expect(page.getByText('Build lead capture forms and review submissions.')).toBeVisible();
            await expect(page.getByRole('link', { name: 'New Form' })).toBeVisible();
            await expect(page.locator('.forms-stats-grid')).toHaveCount(0);
            await expect(page.locator('body')).not.toContainText('Form Library');
            await expect(page.locator('body')).not.toContainText('Add another');

            await expect(page.getByRole('link', { name: formName, exact: true })).toBeVisible();
            await expect(page.getByRole('link', { name: `Preview ${formName}` })).toBeVisible();
            await expect(page.getByRole('link', { name: `Edit ${formName}` })).toBeVisible();
            await expect(page.getByRole('link', { name: `Delete ${formName}` })).toBeVisible();
            await expect(page.locator('.forms-list-table')).toBeVisible();

            const horizontalOverflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
            expect(horizontalOverflow).toBeLessThanOrEqual(1);

            await assertPageHealthy(page, health);
        } finally {
            runFixture('cleanup_namespace', { namespace });
        }
    });
});
