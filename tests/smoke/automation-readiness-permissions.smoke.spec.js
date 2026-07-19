const { test, expect } = require('@playwright/test');
const {
    TEST_PASSWORD,
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    ensureUserWithRole,
    gotoAndWait,
    loginAsUser
} = require('./helpers');

test.describe('Smoke: Automation Readiness Permission Split', () => {
    test('dashboard respects separate battery, card, and legacy readiness permissions', async ({ page }) => {
        test.setTimeout(180000);
        const namespace = createFixtureNamespace('automation-readiness');
        const health = createPageHealthMonitor(page);
        const noAccessEmail = `${namespace}-none@example.test`;
        const batteryOnlyEmail = `${namespace}-battery@example.test`;
        const cardOnlyEmail = `${namespace}-card@example.test`;
        const bothEmail = `${namespace}-both@example.test`;
        const legacyEmail = `${namespace}-legacy@example.test`;

        ensureUserWithRole(
            noAccessEmail,
            `${namespace}-role-none`,
            `No Access ${namespace}`,
            [],
            { namespace, profile_role: 'sales' }
        );
        ensureUserWithRole(
            batteryOnlyEmail,
            `${namespace}-role-battery`,
            `Battery ${namespace}`,
            ['feature.automation_battery'],
            { namespace, profile_role: 'sales' }
        );
        ensureUserWithRole(
            cardOnlyEmail,
            `${namespace}-role-card`,
            `Card ${namespace}`,
            ['feature.automation_readiness_card'],
            { namespace, profile_role: 'sales' }
        );
        ensureUserWithRole(
            bothEmail,
            `${namespace}-role-both`,
            `Both ${namespace}`,
            ['feature.automation_battery', 'feature.automation_readiness_card'],
            { namespace, profile_role: 'sales' }
        );
        ensureUserWithRole(
            legacyEmail,
            `${namespace}-role-legacy`,
            `Legacy ${namespace}`,
            ['feature.automation_readiness'],
            { namespace, profile_role: 'sales' }
        );

        async function assertDashboardVisibility(email, expectsBattery, expectsCard) {
            await page.context().clearCookies();
            await loginAsUser(page, email, TEST_PASSWORD);
            await gotoAndWait(page, 'dashboard.php');

            if (expectsBattery) {
                await expect(page.locator('.hero-automation-shell')).toBeVisible();
            } else {
                await expect(page.locator('.hero-automation-shell')).toHaveCount(0);
            }

            if (expectsCard) {
                await expect(page.locator('.automation-readiness-card')).toBeVisible();
            } else {
                await expect(page.locator('.automation-readiness-card')).toHaveCount(0);
            }
        }

        try {
            await assertDashboardVisibility(noAccessEmail, false, false);
            await assertDashboardVisibility(batteryOnlyEmail, true, false);
            await assertDashboardVisibility(cardOnlyEmail, false, true);
            await assertDashboardVisibility(bothEmail, true, true);
            await assertDashboardVisibility(legacyEmail, true, false);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });
});
