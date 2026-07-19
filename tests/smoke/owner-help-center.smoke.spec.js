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

test.describe('Smoke: Owner Help Center', () => {
    test.setTimeout(180000);

    test('owner can request setup help and expert help, admin can see lane filters', async ({ page }) => {
        const health = createPageHealthMonitor(page);
        const suffix = uniqueSuffix('owner-help');
        const ownerEmail = `${suffix}@example.test`;
        const adminEmail = `${suffix}.admin@example.test`;
        const expertEmail = `${suffix}.expert@example.test`;
        const setupSubject = `Setup quote ${suffix}`;

        runFixture('ensure_workspace_owner', {
            email: ownerEmail,
            password: TEST_PASSWORD,
            workspace_name: `Owner Help ${suffix}`
        });
        runFixture('ensure_user', {
            email: adminEmail,
            password: TEST_PASSWORD,
            profile_role: 'admin',
            first_name: 'Help',
            last_name: 'Admin'
        });
        runFixture('ensure_user_role', { email: adminEmail, role_slug: 'superadmin' });
        runFixture('ensure_owner_help_expert', { email: expertEmail });

        await loginAsUser(page, ownerEmail, TEST_PASSWORD);
        await gotoAndWait(page, 'owner_support.php');
        await expect(page.locator('body')).toContainText('Help Center');
        await expect(page.locator('#help-dropdown')).toContainText('Help Center');
        await expect(page.getByRole('link', { name: /System issue/i })).toBeVisible();
        await expect(page.getByRole('link', { name: /Setup help/i })).toBeVisible();
        await expect(page.getByRole('link', { name: /Hire an expert/i })).toBeVisible();
        await expect(page.locator('body')).toContainText('Free support');

        await gotoAndWait(page, 'owner_support.php?tab=setup');
        await page.locator('input[name="subject"]').fill(setupSubject);
        await page.locator('textarea[name="message"]').fill('Please quote a workspace setup audit and launch readiness review.');
        await page.locator('input[name="preferred_time"]').fill('This week');
        await Promise.all([
            page.waitForURL(/owner_support\.php.*tab=requests/i, { timeout: 30000 }),
            page.getByRole('button', { name: /Request setup quote/i }).click()
        ]);
        await expect(page.locator('body')).toContainText(setupSubject);
        await expect(page.locator('body')).toContainText('Quote Required');

        await gotoAndWait(page, 'owner_support.php?tab=experts');
        await expect(page.locator('body')).toContainText('Paid expert help');
        await expect(page.locator('body')).toContainText('Verified internal setup and launch support');
        await page.getByText('Profile and CV').first().click();
        await expect(page.locator('body')).toContainText('Workspace setup');
        await page.locator('textarea[name="message"]').first().fill('Please match us with a mentor for strategy and installation.');
        await Promise.all([
            page.waitForURL(/owner_support\.php.*tab=requests/i, { timeout: 30000 }),
            page.getByRole('button', { name: /Request this expert/i }).first().click()
        ]);
        await expect(page.locator('body')).toContainText('Expert help request');

        await gotoAndWait(page, 'logout.php');
        await loginAsUser(page, adminEmail, TEST_PASSWORD);
        await gotoAndWait(page, 'owner_support_admin.php?lane=setup_help');
        await expect(page.locator('body')).toContainText('Owner Support Admin');
        await expect(page.locator('body')).toContainText('Triage and quote');
        await expect(page.locator('body')).toContainText(setupSubject);
        await expect(page.locator('select[name="lane"]').first()).toHaveValue('setup_help');

        await assertPageHealthy(page, health);
    });
});
