const { test, expect } = require('@playwright/test');
const {
    TEST_EMAIL,
    TEST_PASSWORD,
    assertPageHealthy,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsAdmin
} = require('./helpers');

async function openSessionWarning(page, secondsRemaining = 300) {
    await page.evaluate((seconds) => {
        window.CrmSessionUx.showWarning({ seconds_remaining: seconds });
    }, secondsRemaining);
    await expect(page.getByRole('heading', { name: 'Your session will expire soon' })).toBeVisible();
}

test.describe.serial('Smoke: idle session recovery', () => {
    test('Continue working renews the session without losing the current draft', async ({ page }) => {
        const health = createPageHealthMonitor(page);
        await loginAsAdmin(page);
        await gotoAndWait(page, 'settings.php?tab=general');

        const field = page.locator('#app_name');
        await field.fill('Unsaved session recovery draft');
        await field.focus();
        await openSessionWarning(page);

        const continueButton = page.getByRole('button', { name: 'Continue working' });
        await expect(continueButton).toBeFocused();
        const renewal = page.waitForResponse((response) => {
            return /\/api\/session\/status\.php/i.test(response.url())
                && response.request().method() === 'POST';
        });
        await continueButton.click();
        const response = await renewal;

        expect(response.ok()).toBeTruthy();
        await expect(page.locator('.crm-session-ux-backdrop')).toBeHidden();
        await expect(field).toHaveValue('Unsaved session recovery draft');
        await expect(field).toBeFocused();
        await assertPageHealthy(page, health);
    });

    test('Sign in again performs same-account re-authentication and restores the draft', async ({ page }) => {
        const health = createPageHealthMonitor(page);
        await loginAsAdmin(page);
        await gotoAndWait(page, 'settings.php?tab=general');

        const field = page.locator('#app_name');
        await field.fill('Draft restored after re-authentication');
        await openSessionWarning(page);
        const draftStorageKey = await page.evaluate(() => 'crm:session-ux:draft:' + window.location.pathname + window.location.search);
        const savedDraft = await page.evaluate((key) => window.sessionStorage.getItem(key), draftStorageKey);
        expect(savedDraft).toContain('Draft restored after re-authentication');

        await Promise.all([
            page.waitForURL(/login\.php\?.*reauth=1/i),
            page.getByRole('link', { name: 'Sign in again' }).click()
        ]);

        const email = page.locator('input[name="email"]');
        await expect(email).toHaveValue(TEST_EMAIL);
        await expect(email).toHaveAttribute('readonly', '');
        await page.locator('input[name="password"]').fill(TEST_PASSWORD);
        await Promise.all([
            page.waitForURL(/settings\.php\?tab=general/i),
            page.getByRole('button', { name: 'Sign In' }).click()
        ]);

        await expect.poll(() => page.evaluate((key) => window.sessionStorage.getItem(key), draftStorageKey)).toBeNull();
        await expect(page.locator('#app_name')).toHaveValue('Draft restored after re-authentication');
        await assertPageHealthy(page, health);
    });

    test('renewal connectivity failure keeps work in place and offers retry', async ({ page }) => {
        await loginAsAdmin(page);
        await gotoAndWait(page, 'settings.php?tab=general');
        const field = page.locator('#app_name');
        await field.fill('Draft survives a connection failure');
        await openSessionWarning(page);

        await page.route('**/api/session/status.php', async (route) => {
            if (route.request().method() === 'POST') {
                await route.abort('failed');
                return;
            }
            await route.continue();
        });
        await page.getByRole('button', { name: 'Continue working' }).click();

        await expect(page.getByRole('heading', { name: 'We couldn’t confirm your session' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Retry' })).toBeEnabled();
        await expect(field).toHaveValue('Draft survives a connection failure');
    });

    test('warning countdown transitions to the expired state', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });
        await loginAsAdmin(page);
        await gotoAndWait(page, 'settings.php?tab=general');
        await page.route('**/api/session/status.php', async (route) => {
            if (route.request().method() !== 'GET') {
                await route.continue();
                return;
            }
            await route.fulfill({
                status: 401,
                contentType: 'application/json',
                body: JSON.stringify({
                    success: false,
                    authenticated: false,
                    state: 'expired',
                    auth: {
                        state: 'expired',
                        authenticated: false,
                        login_url: '/crm/public/login.php?expired=1&redirect_to=%2Fcrm%2Fpublic%2Fsettings.php%3Ftab%3Dgeneral'
                    }
                })
            });
        });

        await openSessionWarning(page, 1);
        await expect(page.getByRole('heading', { name: 'Your session expired' })).toBeVisible({ timeout: 5000 });
        const dialogBounds = await page.getByRole('dialog').boundingBox();
        expect(dialogBounds).not.toBeNull();
        expect(dialogBounds.x).toBeGreaterThanOrEqual(0);
        expect(dialogBounds.x + dialogBounds.width).toBeLessThanOrEqual(390);
        await expect(page.getByRole('link', { name: 'Sign in again' })).toBeFocused();
    });
});
