const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    ensureTargetExists,
    gotoAndWait,
    loginAsAdmin,
    loginAsUser,
    runFixture,
    TEST_PASSWORD,
    ensureUserWithRole
} = require('./helpers');

test.describe('Smoke: Targets', () => {
    test('targets list, dashboard, and detail render target intelligence without fatal errors', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsAdmin(page);
        const target = await ensureTargetExists(page);

        await gotoAndWait(page, 'targets.php');
        await expect(page.locator('body')).toContainText('Targets');

        await gotoAndWait(page, 'target_dashboard.php');
        await expect(page.locator('body')).toContainText(/Target Dashboard|Targets Dashboard/i);

        await gotoAndWait(page, `target_view.php?id=${target.id}`);
        await expect(page.locator('body')).toContainText('Scoring');
        await expect(page.locator('body')).toContainText(/Forecast|Projected|Advice/i);

        await assertPageHealthy(page, health);
    });

    test('shared targets can be opened but only owners can delete them from the list', async ({ page }) => {
        const namespace = createFixtureNamespace('targets-shared');
        const health = createPageHealthMonitor(page);
        const ownerEmail = `${namespace}-owner@example.test`;
        const viewerEmail = `${namespace}-viewer@example.test`;

        ensureUserWithRole(
            ownerEmail,
            `${namespace}-owner-role`,
            `Owner ${namespace}`,
            [],
            { namespace, profile_role: 'sales' }
        );
        ensureUserWithRole(
            viewerEmail,
            `${namespace}-viewer-role`,
            `Viewer ${namespace}`,
            [],
            { namespace, profile_role: 'viewer' }
        );

        const sharedTarget = runFixture('seed_target', {
            namespace: `${namespace}-shared`,
            email: ownerEmail,
            scope: 'team',
        });
        const ownedTarget = runFixture('seed_target', {
            namespace: `${namespace}-owned`,
            email: viewerEmail,
            scope: 'personal',
        });

        try {
            await loginAsUser(page, viewerEmail, TEST_PASSWORD);
            await gotoAndWait(page, `targets.php?search=${encodeURIComponent(namespace)}`);

            const sharedCard = page.locator('article', { hasText: sharedTarget.title }).first();
            await expect(sharedCard).toBeVisible();
            await expect(sharedCard.getByRole('link', { name: 'Open' })).toBeVisible();
            await expect(sharedCard.getByRole('button', { name: 'Delete' })).toHaveCount(0);

            await Promise.all([
                page.waitForURL(/target_view\.php\?id=/i),
                sharedCard.getByRole('link', { name: 'Open' }).click(),
            ]);
            await expect(page.locator('body')).toContainText(sharedTarget.title);
            await expect(page.locator('body')).toContainText('You have read-only access to this shared target.');
            await expect(page.getByRole('link', { name: 'Edit' })).toHaveCount(0);

            await gotoAndWait(page, `targets.php?search=${encodeURIComponent(namespace)}`);
            const ownedCard = page.locator('article', { hasText: ownedTarget.title }).first();
            await expect(ownedCard).toBeVisible();
            await expect(ownedCard.getByRole('button', { name: 'Delete' })).toHaveCount(1);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });
});
