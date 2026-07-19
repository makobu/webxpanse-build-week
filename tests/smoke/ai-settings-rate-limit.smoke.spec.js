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

async function loginAsSuperAdmin(page) {
    const superEmail = `${uniqueSuffix('ai-settings-super')}@example.test`;
    runFixture('ensure_user', {
        email: superEmail,
        password: TEST_PASSWORD,
        profile_role: 'admin',
        first_name: 'AI',
        last_name: 'Settings'
    });
    runFixture('ensure_user_role', { email: superEmail, role_slug: 'superadmin' });
    await loginAsUser(page, superEmail, TEST_PASSWORD);
}

test.describe('Smoke: AI Settings Rate Limit', () => {
    test('AI settings shows daily token limit controls', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsSuperAdmin(page);
        await gotoAndWait(page, 'settings.php?tab=ai');

        const body = page.locator('body');
        const rateLimitToggle = page.locator('input[name="ai_token_rate_limit_enabled"]');

        if (await rateLimitToggle.count()) {
            await expect(body).toContainText('Daily AI Token Rate Limit');
            await expect(rateLimitToggle).toHaveCount(1);
            await expect(page.locator('input[name="ai_daily_token_limit"]')).toHaveCount(1);
            await expect(body).toContainText('Used Today');
            await expect(body).toContainText('Remaining');
        } else {
            await expect(body).toContainText('Managed AI and automation mode is on');
            await expect(body).toContainText('Auto Admin manages this workspace setup');
            await expect(body).toContainText('Auto Admin is managing this workspace settings section');
        }

        await assertPageHealthy(page, health);
    });
});
