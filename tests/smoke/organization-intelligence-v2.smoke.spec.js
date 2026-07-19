const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsUser,
    runFixture,
    TEST_PASSWORD,
    uniqueSuffix
} = require('./helpers');

const VIEWPORTS = [
    { width: 390, height: 844 },
    { width: 768, height: 900 },
    { width: 1440, height: 1000 }
];

const ROOMS = [
    { query: 'tab=brief&timeframe=month', heading: 'Organization Intelligence' },
    { query: 'tab=people&timeframe=month', heading: 'People & HR' },
    { query: 'tab=analytics&timeframe=month', heading: 'Executive Analytics' },
    { query: 'tab=priorities&priority_view=leadership&timeframe=month', heading: 'Leadership Priorities' },
    { query: 'tab=settings&timeframe=month', heading: 'Settings & Methodology' }
];

test.describe('Organization Intelligence V2 responsive contract', () => {
    test('rooms, graphs, tabs and Clarity remain accessible at supported widths', async ({ page }) => {
        const health = createPageHealthMonitor(page);
        const email = `${uniqueSuffix('oi-v2-smoke')}@example.test`;
        runFixture('ensure_user', {
            email,
            password: TEST_PASSWORD,
            profile_role: 'admin',
            first_name: 'OI',
            last_name: 'Reviewer'
        });
        runFixture('ensure_user_role', { email, role_slug: 'superadmin' });
        await loginAsUser(page, email, TEST_PASSWORD);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            for (const room of ROOMS) {
                await gotoAndWait(page, `hr_analytics.php?${room.query}`);
                await expect(page.getByRole('heading', { name: room.heading, exact: true })).toBeVisible();
                const dimensions = await page.evaluate(() => ({
                    viewport: document.documentElement.clientWidth,
                    scrollWidth: document.documentElement.scrollWidth,
                    roomCount: document.querySelectorAll('.hr-executive-rooms .hr-tab').length,
                    clarityVisible: Boolean(document.querySelector('.chat-bubble-btn'))
                }));
                expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.viewport + 1);
                expect(dimensions.roomCount).toBeGreaterThanOrEqual(4);
                expect(dimensions.clarityVisible).toBeTruthy();
            }
        }

        await page.setViewportSize({ width: 390, height: 844 });
        await gotoAndWait(page, 'hr_analytics.php?tab=brief&timeframe=month');
        await expect(page.locator('.hr-trend-svg[role="img"]')).toHaveCount(1);
        await expect(page.locator('.hr-component-bullets svg[role="img"]')).toHaveCount(1);
        await expect(page.locator('.hr-component-bullets table')).toHaveCount(1);

        await gotoAndWait(page, 'hr_analytics.php?tab=analytics&timeframe=month');
        await expect(page.getByRole('heading', { name: 'Pipeline Stage Distribution', exact: true })).toBeVisible();
        await expect(page.locator('[aria-label="Responsibility coverage heatmap"]')).toHaveCount(1);

        await page.keyboard.press('Tab');
        expect(await page.evaluate(() => document.activeElement !== document.body)).toBeTruthy();
        await assertPageHealthy(page, health);
    });
});
