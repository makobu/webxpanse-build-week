const { test, expect } = require('@playwright/test');
const {
    createPageHealthMonitor,
    assertPageHealthy,
    gotoAndWait,
    loginAsAdmin
} = require('./helpers');

test.describe('Smoke: Analytics V2', () => {
    test('loads the operating cockpit and async analytics sections', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsAdmin(page);
        await gotoAndWait(page, 'analytics.php');

        await expect(page.locator('[data-analytics-section-control="overview"]')).toHaveAttribute('aria-current', 'true');
        await expect(page.locator('#analytics-section-operating')).toBeVisible();
        await expect(page.locator('#analytics-section-summary')).toBeVisible();
        await expect(page.locator('#analytics-section-revenue')).toBeVisible();
        await expect(page.locator('#analytics-section-kpis')).toBeVisible();
        await expect(page.locator('#analytics-section-targets')).toBeHidden();
        await expect(page.locator('#analytics-section-operating [data-analytics-metrics] .analytics-operating-card').first()).toBeVisible({ timeout: 30000 });

        for (const sectionKey of ['channels', 'ai_automation', 'founder_journey', 'targets_tasks', 'marketplace_skills', 'operations']) {
            const section = page.locator(`[data-analytics-async-section="${sectionKey}"]`).first();

            const tagName = await section.evaluate((node) => node.tagName.toLowerCase());
            if (tagName === 'details') {
                await section.evaluate((node) => {
                    node.open = true;
                    node.dispatchEvent(new Event('toggle'));
                });
            }

            await expect(section.locator('[data-analytics-async-status]')).not.toHaveText(/Loading|Open to load/i, { timeout: 30000 });
        }

        await assertPageHealthy(page, health);
    });

    test('filters analytics sections without anchor jumps', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsAdmin(page);
        await gotoAndWait(page, 'analytics.php?timeframe=week&attribution_model=linear&user_id=&section=overview');

        await page.locator('.analytics-section-nav').scrollIntoViewIfNeeded();
        const beforeClickNavTop = await page.locator('.analytics-section-nav').boundingBox().then((box) => box ? box.y : 0);
        await page.locator('[data-analytics-section-control="channels"]').click();
        await expect(page.locator('[data-analytics-section-control="channels"]')).toHaveAttribute('aria-current', 'true');
        await expect(page.locator('#analytics-section-channels')).toBeVisible();
        await expect(page.locator('#analytics-section-revenue')).toBeHidden();
        await expect(page.locator('#analytics-section-kpis')).toBeHidden();

        const afterClickNavTop = await page.locator('.analytics-section-nav').boundingBox().then((box) => box ? box.y : 0);
        const afterClick = await page.evaluate(() => ({
            hash: window.location.hash,
            params: Object.fromEntries(new URLSearchParams(window.location.search).entries())
        }));
        expect(afterClick.hash).toBe('');
        expect(Math.abs(afterClickNavTop - beforeClickNavTop)).toBeLessThan(80);
        expect(afterClick.params.section).toBe('channels');
        expect(afterClick.params.timeframe).toBe('week');
        expect(afterClick.params.attribution_model).toBe('linear');
        expect(Object.prototype.hasOwnProperty.call(afterClick.params, 'user_id')).toBe(true);

        await page.locator('[data-analytics-section-control="revenue"]').click();
        await expect(page.locator('#analytics-section-revenue')).toBeVisible();
        await expect(page.locator('#analytics-section-channels')).toBeHidden();

        await gotoAndWait(page, 'analytics.php?section=targets');
        await expect(page.locator('[data-analytics-section-control="targets"]')).toHaveAttribute('aria-current', 'true');
        await expect(page.locator('#analytics-section-targets')).toBeVisible();
        await expect(page.locator('#analytics-section-revenue')).toBeHidden();
        await expect(page.locator('#analytics-section-targets [data-analytics-async-status]')).not.toHaveText(/Loading|Open to load/i, { timeout: 30000 });

        await assertPageHealthy(page, health);
    });
});
