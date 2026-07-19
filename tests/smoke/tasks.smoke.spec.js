const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsAdmin,
    seedTaskFixture
} = require('./helpers');

test.describe('Smoke: Tasks', () => {
    test('tasks list loads and a checklist item can be toggled', async ({ page }) => {
        const namespace = createFixtureNamespace('smoke-task');
        const health = createPageHealthMonitor(page);
        const task = seedTaskFixture(namespace);

        page.on('dialog', async (dialog) => {
            throw new Error(`Unexpected dialog while toggling task checklist: ${dialog.message()}`);
        });

        try {
            await loginAsAdmin(page);
            await gotoAndWait(page, `task_view.php?id=${task.task_id}`);

            const checkbox = page.locator('.task-subtask-toggle').first();
            const wasChecked = await checkbox.isChecked();
            await checkbox.click();
            await page.waitForTimeout(1000);
            await expect(checkbox).toHaveJSProperty('checked', !wasChecked);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });
});
