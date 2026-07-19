const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    createPageHealthMonitor,
    gotoAndWait,
    stabilizeBackgroundRequests
} = require('./helpers');

test.describe('Smoke: Demo sign-in', () => {
    test('guest demo access form renders with premium auth layout and mobile fit', async ({ page }) => {
        await stabilizeBackgroundRequests(page);
        const health = createPageHealthMonitor(page);

        await gotoAndWait(page, 'demo.php');

        await expect(page).toHaveTitle(/Open Demo Workspace/i);
        await expect(page.locator('.demo-auth-shell')).toBeVisible();
        await expect(page.locator('.demo-access-card')).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Instant demo access' })).toBeVisible();
        await expect(page.locator('input[name="name"]')).toBeVisible();
        await expect(page.locator('input[name="email"]')).toBeVisible();
        await expect(page.locator('input[name="phone"]')).toBeVisible();
        await expect(page.getByText('Create a temporary private demo session.')).toBeVisible();
        await expect(page.locator('input[name="consent_privacy"]')).toHaveCount(0);
        await expect(page.locator('input[name="consent_contact"]')).toBeVisible();
        await expect(page.locator('input[name="consent_contact"]')).not.toBeChecked();
        await expect(page.getByText('You may contact me by email or WhatsApp about setup help, product improvements, and offers. I can opt out anytime.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Start demo' })).toBeVisible();

        const signInLinks = page.locator('a[href$="login.php"]');
        await expect(signInLinks.first()).toContainText('Back to sign in');
        await expect(signInLinks).toHaveCount(1);

        await page.setViewportSize({ width: 390, height: 844 });
        await page.waitForTimeout(150);
        const horizontalOverflow = await page.evaluate(() => {
            return document.documentElement.scrollWidth - window.innerWidth;
        });
        expect(horizontalOverflow).toBeLessThanOrEqual(1);
        await expect(page.locator('.demo-access-card')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Start demo' })).toBeVisible();

        await assertPageHealthy(page, health);
    });
});
