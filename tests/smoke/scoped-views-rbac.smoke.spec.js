const { test, expect } = require('@playwright/test');
const {
    TEST_PASSWORD,
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    ensureUserWithRole,
    gotoAndWait,
    loginAsAdmin,
    loginAsUser,
    runFixture,
    seedContactRecord,
    seedScopedEmailConversation
} = require('./helpers');

test.describe('Smoke: Scoped Views and RBAC', () => {
    test('restricted users see scoped contacts, deals, inbox, dashboard, and analytics controls', async ({ page }) => {
        const namespace = createFixtureNamespace('scoped');
        const health = createPageHealthMonitor(page);
        const restrictedEmail = `${namespace}-restricted@example.test`;
        const otherEmail = `${namespace}-other@example.test`;

        ensureUserWithRole(
            restrictedEmail,
            `${namespace}-restricted-role`,
            `Restricted ${namespace}`,
            [],
            { namespace, profile_role: 'sales' }
        );
        ensureUserWithRole(
            otherEmail,
            `${namespace}-other-role`,
            `Other ${namespace}`,
            ['analytics.view_all'],
            { namespace, profile_role: 'sales' }
        );

        const ownedContact = seedContactRecord(`${namespace}-owned`, {
            assigned_to_email: restrictedEmail,
            created_by_email: restrictedEmail
        });
        const unassignedContact = seedContactRecord(`${namespace}-unassigned`, {
            created_by_email: otherEmail
        });
        seedContactRecord(`${namespace}-hidden`, {
            assigned_to_email: otherEmail,
            created_by_email: otherEmail
        });

        const deal = runFixture('seed_deal', { namespace: `${namespace}-deal`, email: restrictedEmail });
        const visibleConversation = seedScopedEmailConversation(`${namespace}-visible-thread`, {
            created_by_email: restrictedEmail,
            thread_owner_email: restrictedEmail
        });
        seedScopedEmailConversation(`${namespace}-unassigned-thread`, {
            created_by_email: otherEmail
        });
        const hiddenConversation = seedScopedEmailConversation(`${namespace}-hidden-thread`, {
            created_by_email: otherEmail,
            thread_owner_email: otherEmail
        });

        try {
            await loginAsUser(page, restrictedEmail, TEST_PASSWORD);

            await gotoAndWait(page, `contacts.php?search=${encodeURIComponent(namespace)}`);
            await expect(page.locator('#owner_scope')).toHaveValue('mine_unassigned');
            await expect(page.locator('#owner_scope')).not.toContainText('All Contacts');
            await expect(page.locator('body')).toContainText(ownedContact.email);
            await expect(page.locator('body')).toContainText(unassignedContact.email);
            await expect(page.locator('body')).not.toContainText(`${namespace}-hidden@example.test`);

            await gotoAndWait(page, 'deals.php');
            await expect(page.locator('#assigned_to')).toHaveValue(String(runFixture('ensure_user', { email: restrictedEmail }).id));
            await expect(page.locator('body')).toContainText(deal.title);
            await page.getByRole('link', { name: /(?:list|pipeline) view/i }).click();
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
            await expect(page.locator('#assigned_to')).toHaveValue(String(runFixture('ensure_user', { email: restrictedEmail }).id));

            await gotoAndWait(page, 'inbox.php');
            await page.fill('#search', namespace);
            await page.getByRole('button', { name: /filter/i }).click();
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
            await expect(page.locator('body')).toContainText(`${namespace}-visible-thread`);
            await expect(page.locator('body')).toContainText(`${namespace}-unassigned-thread`);
            await expect(page.locator('body')).not.toContainText(`${namespace}-hidden-thread`);

            await gotoAndWait(page, `conversation.php?id=${visibleConversation.communication_id}`);
            await expect(page.locator('#conversation-reply-form')).toBeVisible();

            await gotoAndWait(page, `conversation.php?id=${hiddenConversation.communication_id}`);
            await expect(page).toHaveURL(/inbox\.php/i);

            await gotoAndWait(page, 'dashboard.php');
            await expect(page.locator('body')).toContainText('Viewing: My Dashboard');
            await expect(page.locator('.hero-automation-shell')).not.toBeVisible();
            await expect(page.locator('text=Automation Readiness')).not.toBeVisible();

            await gotoAndWait(page, 'analytics.php');
            const userScopeFilter = page.locator('#user_scope_filter');
            if (await userScopeFilter.count()) {
                await expect(userScopeFilter).toContainText('My Analytics');
                await expect(userScopeFilter).not.toContainText('All Users');
            } else {
                await expect(page.locator('body')).toContainText(/My Analytics|Analytics/i);
            }

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });

    test('owner uses Workspace Team invitations while non-owner manage-only users are blocked', async ({ page }) => {
        const namespace = createFixtureNamespace('users-rbac');
        const health = createPageHealthMonitor(page);
        const ownerEmail = `${namespace}-owner@example.test`;
        const managerEmail = `${namespace}-manager@example.test`;

        const ownerWorkspace = runFixture('ensure_workspace_owner', {
            email: ownerEmail,
            password: TEST_PASSWORD,
            workspace_name: `PW ${namespace} Owner Workspace`
        });
        runFixture('complete_workspace_onboarding', { workspace_id: ownerWorkspace.workspace_id });
        ensureUserWithRole(
            managerEmail,
            `${namespace}-role-manage`,
            `Manage ${namespace}`,
            ['admin.users.manage'],
            { namespace, profile_role: 'sales' }
        );
        ensureUserWithRole(
            `${namespace}-target-high@example.test`,
            `${namespace}-role-high`,
            `High ${namespace}`,
            ['admin.users.manage', 'admin.users.edit'],
            { namespace, profile_role: 'admin' }
        );

        try {
            await loginAsUser(page, ownerEmail, TEST_PASSWORD);
            await gotoAndWait(page, 'users.php');
            await expect(page.locator('a[href="user_create.php"]')).toHaveCount(0);
            await expect(page.locator('a[href="settings.php?tab=workspace_governance"]')).toBeVisible();
            await gotoAndWait(page, 'user_create.php');
            await expect(page).toHaveURL(/settings\.php\?tab=workspace_governance/i);
            await expect(page.getByRole('heading', { name: 'Workspace Team' })).toBeVisible();
            await expect(page.getByRole('heading', { name: 'Invite Member' })).toBeVisible();

            await gotoAndWait(page, 'logout.php');
            await loginAsUser(page, managerEmail, TEST_PASSWORD);
            await gotoAndWait(page, 'users.php');
            await expect(page).toHaveURL(/dashboard\.php/i);

            await gotoAndWait(page, 'user_create.php');
            await expect(page).toHaveURL(/dashboard\.php/i);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });

    test('edit-only user can edit allowed targets but is blocked from create and higher-privilege edits', async ({ page }) => {
        const namespace = createFixtureNamespace('users-edit');
        const health = createPageHealthMonitor(page);
        const editorEmail = `${namespace}-editor@example.test`;
        const lowTargetEmail = `${namespace}-target-low@example.test`;
        const highTargetEmail = `${namespace}-target-high@example.test`;

        ensureUserWithRole(
            editorEmail,
            `${namespace}-role-edit`,
            `Edit ${namespace}`,
            ['admin.users.edit'],
            { namespace, profile_role: 'sales' }
        );
        ensureUserWithRole(
            lowTargetEmail,
            `${namespace}-role-low`,
            `Low ${namespace}`,
            [],
            { namespace, profile_role: 'viewer' }
        );
        ensureUserWithRole(
            highTargetEmail,
            `${namespace}-role-high`,
            `High ${namespace}`,
            ['admin.users.manage', 'admin.users.edit'],
            { namespace, profile_role: 'admin' }
        );

        const lowTarget = runFixture('ensure_user', { email: lowTargetEmail });
        const highTarget = runFixture('ensure_user', { email: highTargetEmail });

        try {
            await loginAsUser(page, editorEmail, TEST_PASSWORD);
            await gotoAndWait(page, 'users.php');
            await expect(page).toHaveURL(/dashboard\.php/i);

            await gotoAndWait(page, `user_view.php?id=${lowTarget.id}`);
            await expect(page).toHaveURL(/dashboard\.php/i);

            await gotoAndWait(page, `user_edit.php?id=${lowTarget.id}`);
            await expect(page).toHaveURL(/dashboard\.php/i);

            await gotoAndWait(page, 'user_create.php');
            await expect(page).toHaveURL(/dashboard\.php/i);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });

    test('delete-only user can delete allowed targets but is blocked from higher-privilege deletes', async ({ page }) => {
        const namespace = createFixtureNamespace('users-delete');
        const health = createPageHealthMonitor(page);
        const deleterEmail = `${namespace}-deleter@example.test`;
        const lowTargetEmail = `${namespace}-target-low@example.test`;
        const highTargetEmail = `${namespace}-target-high@example.test`;

        ensureUserWithRole(
            deleterEmail,
            `${namespace}-role-delete`,
            `Delete ${namespace}`,
            ['admin.users.delete'],
            { namespace, profile_role: 'sales' }
        );
        ensureUserWithRole(
            lowTargetEmail,
            `${namespace}-role-low`,
            `Low ${namespace}`,
            [],
            { namespace, profile_role: 'viewer' }
        );
        ensureUserWithRole(
            highTargetEmail,
            `${namespace}-role-high`,
            `High ${namespace}`,
            ['admin.users.manage', 'admin.users.edit'],
            { namespace, profile_role: 'admin' }
        );

        const lowTarget = runFixture('ensure_user', { email: lowTargetEmail });
        const highTarget = runFixture('ensure_user', { email: highTargetEmail });

        try {
            await loginAsUser(page, deleterEmail, TEST_PASSWORD);
            await gotoAndWait(page, 'users.php');
            await expect(page).toHaveURL(/dashboard\.php/i);

            await gotoAndWait(page, `user_delete.php?id=${lowTarget.id}`);
            await expect(page).toHaveURL(/dashboard\.php/i);

            await assertPageHealthy(page, health);
        } finally {
            cleanupFixtureNamespace(namespace);
        }
    });
});
