const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsAdmin,
    seedNotificationsFixture
} = require('./helpers');

test.describe('Smoke: Notifications', () => {
    test('notifications load and mark-read action works without page failure', async ({ page }) => {
        const namespace = createFixtureNamespace('smoke-notifications');
        const health = createPageHealthMonitor(page);
        seedNotificationsFixture(namespace);

        page.on('dialog', async (dialog) => {
            await dialog.accept();
        });

        try {
            await loginAsAdmin(page);
            await gotoAndWait(page, 'notifications.php');

            await expect(page.locator('.notifications-page')).toBeVisible();
            await expect(page.locator('.notifications-toolbar')).toBeVisible();
            const firstGroup = page.locator('.notif-group').first();
            await expect(firstGroup).toBeVisible();
            await expect(firstGroup).not.toHaveAttribute('open', '');
            await firstGroup.locator('.notif-group-summary').click();
            await expect(page.locator('.notif-row').first()).toBeVisible();
            await expect(page.locator('body')).toContainText(`Playwright Notice A ${namespace}`);

            const markReadButton = page.locator('button.action-read:visible').first();
            await expect(markReadButton).toBeVisible();

            await Promise.all([
                page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
                markReadButton.click()
            ]);

            await expect(page.locator('body')).toContainText(/marked as read|notifications/i);
            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });

    test('compact notification toolbar fits desktop and mobile widths', async ({ page }) => {
        const namespace = createFixtureNamespace('smoke-notifications-layout');
        const health = createPageHealthMonitor(page);
        seedNotificationsFixture(namespace);

        try {
            await loginAsAdmin(page);

            await page.setViewportSize({ width: 1280, height: 800 });
            await gotoAndWait(page, 'notifications.php');
            await expect(page.locator('.notifications-toolbar')).toBeVisible();
            await expect(page.locator('.notif-group').first()).toBeVisible();
            await expect(async () => {
                const fits = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
                expect(fits).toBeTruthy();
            }).toPass();

            await page.setViewportSize({ width: 390, height: 820 });
            await expect(page.locator('.notifications-toolbar')).toBeVisible();
            await expect(async () => {
                const fits = await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);
                expect(fits).toBeTruthy();
            }).toPass();

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });
});
