const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsAdmin,
    seedDealFixture,
    seedNotificationsFixture,
    seedTaskFixture,
    uniqueSuffix
} = require('../../smoke/helpers');

test.describe('Regression: Core workflows', () => {
    test('dashboard and core navigation pages render without fatal output', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsAdmin(page);

        const pageChecks = [
            ['dashboard.php', /Welcome back|Dashboard/i],
            ['inbox.php', /Inbox/i],
            ['contacts.php', /Contacts/i],
            ['deals.php', /Deals/i],
            ['tasks.php', /Tasks/i],
            ['notifications.php', /Notifications/i],
            ['settings.php?tab=company', /Company Profile|Auto Admin/i]
        ];

        for (const [path, textPattern] of pageChecks) {
            await gotoAndWait(page, path);
            await expect(page.locator('body')).toContainText(textPattern);
        }

        await assertPageHealthy(page, health);
    });

    test('notifications single-item actions only affect the targeted record', async ({ page }) => {
        const namespace = createFixtureNamespace('notifications-regression');
        const health = createPageHealthMonitor(page);
        seedNotificationsFixture(namespace);

        page.on('dialog', async (dialog) => {
            await dialog.accept();
        });

        try {
            await loginAsAdmin(page);
            await gotoAndWait(page, 'notifications.php');

            await expect(page.locator('body')).toContainText(`Playwright Notice A ${namespace}`);
            await expect(page.locator('body')).toContainText(`Playwright Notice B ${namespace}`);
            await expect(page.locator('body')).toContainText(`Playwright Notice C ${namespace}`);

            const systemGroup = page.locator('.notif-group').filter({ hasText: `Playwright Notice A ${namespace}` });
            await expect(systemGroup).toBeVisible();
            await expect(systemGroup).not.toHaveAttribute('open', '');
            await systemGroup.locator('.notif-group-summary').click();

            await Promise.all([
                page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
                systemGroup.locator('.notif-row', { hasText: `Playwright Notice A ${namespace}` }).getByRole('button', { name: 'Mark read', exact: true }).click()
            ]);
            await expect(page.locator('body')).toContainText(/marked as read|notification/i);
            await expect(page.locator('body')).toContainText(`Playwright Notice B ${namespace}`);

            const refreshedSystemGroup = page.locator('.notif-group').filter({ hasText: `Playwright Notice A ${namespace}` });
            await refreshedSystemGroup.locator('.notif-group-summary').click();

            await Promise.all([
                page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
                refreshedSystemGroup.locator('.notif-row', { hasText: `Playwright Notice A ${namespace}` }).getByRole('button', { name: 'Delete', exact: true }).click()
            ]);
            await expect(page.locator('body')).not.toContainText(`Playwright Notice A ${namespace}`);
            await expect(page.locator('body')).toContainText(`Playwright Notice B ${namespace}`);
            await expect(page.locator('body')).toContainText(`Playwright Notice C ${namespace}`);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });

    test('deal stage actions work from proposal and negotiation pages', async ({ page }) => {
        const namespace = createFixtureNamespace('deal-regression');
        const health = createPageHealthMonitor(page);
        const fixture = seedDealFixture(namespace);

        try {
            await loginAsAdmin(page);
            await gotoAndWait(page, 'deals_proposal.php');

            await expect(page.locator('body')).toContainText(/Proposal Deals|Proposal/i);
            const select = page.locator(`.deal-transition-select[data-deal-id="${fixture.deal_id}"]`).first();
            const button = page.locator(`.deal-transition-button[data-deal-id="${fixture.deal_id}"]`).first();
            await expect(select).toBeVisible();
            await select.selectOption('negotiation');
            await button.click();
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});

            await gotoAndWait(page, 'deals_negotiation.php');
            await expect(page.locator('body')).toContainText(fixture.title);
            await expect(page.locator('body')).toContainText(/Negotiation Deals|Negotiation/i);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });

    test('task checklist toggles for a seeded task', async ({ page }) => {
        const namespace = createFixtureNamespace('task-regression');
        const health = createPageHealthMonitor(page);
        const fixture = seedTaskFixture(namespace);

        try {
            await loginAsAdmin(page);
            await gotoAndWait(page, `task_view.php?id=${fixture.task_id}`);

            const checkbox = page.locator('.task-subtask-toggle').first();
            await expect(checkbox).toBeVisible();
            const wasChecked = await checkbox.isChecked();
            await checkbox.click();
            await page.waitForTimeout(1000);
            await expect(checkbox).toHaveJSProperty('checked', !wasChecked);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });

    test('company profile save and product create/delete work from settings', async ({ page }) => {
        const namespace = createFixtureNamespace('settings-regression');
        const health = createPageHealthMonitor(page);
        const productName = `Playwright Product ${uniqueSuffix(namespace)}`;

        page.on('dialog', async (dialog) => {
            await dialog.accept();
        });

        await loginAsAdmin(page);
        await gotoAndWait(page, 'settings.php?tab=company');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.getByRole('button', { name: 'Save Company Profile' }).click()
        ]);
        await expect(page.locator('body')).toContainText(/Settings saved successfully|Company profile updated successfully/i);
        await expect(page.locator('body')).not.toContainText('Invalid product action.');

        await page.getByRole('button', { name: 'Add Product' }).click();
        await page.locator('#product_name').fill(productName);
        await page.locator('#product_description').fill('Regression product created by Playwright.');
        await page.locator('#product_unit_price').fill('1499');
        await page.getByRole('button', { name: 'Save Product' }).click();
        await expect(page.locator('body')).toContainText(/Product added successfully/i);

        await expect(page.locator('body')).toContainText(productName);

        const productCard = page.locator(`text=${productName}`).locator('..').locator('..');
        await productCard.getByRole('button', { name: 'Delete' }).click();
        await expect(page.locator('body')).toContainText(/Product deleted successfully/i);
        await expect(page.locator('body')).not.toContainText(productName);

        await assertPageHealthy(page, health);
    });
});
