const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsAdmin,
    runFixture,
    seedDealFixture
} = require('./helpers');

test.describe('Smoke: Deals', () => {
    test('deals page loads and a non-terminal stage transition works', async ({ page }) => {
        const namespace = createFixtureNamespace('smoke-deal');
        const health = createPageHealthMonitor(page);
        const deal = seedDealFixture(namespace);

        try {
            await loginAsAdmin(page);
            await gotoAndWait(page, 'deals_proposal.php');
            await expect(page.locator('body')).toContainText(/Proposal Deals|Proposal/i);

            const transitionSelect = page.locator(`.deal-transition-select[data-deal-id="${deal.deal_id}"]`).first();
            const transitionButton = page.locator(`.deal-transition-button[data-deal-id="${deal.deal_id}"]`).first();

            await expect(transitionSelect).toBeVisible();
            await expect(transitionButton).toBeVisible();

            await transitionSelect.selectOption('negotiation');
            await transitionButton.click();
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
            await expect
                .poll(() => {
                    return runFixture('get_deal', { deal_id: deal.deal_id }).stage;
                }, { timeout: 15000 })
                .toBe('negotiation');

            await page.goto('about:blank').catch(() => {});
            await gotoAndWait(page, 'deals_negotiation.php');
            await expect(page.locator('body')).toContainText(deal.title);
            await expect(page.locator('body')).toContainText(/Negotiation Deals|Negotiation/i);
            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });
});
