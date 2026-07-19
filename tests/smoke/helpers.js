const { expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const BASE_URL = process.env.CRM_BASE_URL || 'http://localhost/crm/public';
const TEST_EMAIL = process.env.CRM_TEST_EMAIL || 'launch.operator.probe@example.test';
const TEST_PASSWORD = process.env.CRM_TEST_PASSWORD || 'password';
const PHP_EXE = process.env.CRM_TEST_PHP || 'php';
const FIXTURE_SCRIPT = path.resolve(__dirname, '..', 'browser_fixture.php');

const FATAL_TEXT_PATTERNS = [
    /Fatal error/i,
    /Warning:/i,
    /Notice:/i,
    /Uncaught/i,
    /PDOException/i,
    /SQLSTATE/i
];

function uniqueSuffix(prefix) {
    return `${prefix}-${Date.now()}-${Math.floor(Math.random() * 10000)}`;
}

function runFixture(action, payload = {}) {
    const input = JSON.stringify({ action, ...payload });
    const raw = execFileSync(PHP_EXE, [FIXTURE_SCRIPT], {
        cwd: path.resolve(__dirname, '..', '..'),
        input,
        encoding: 'utf8'
    });
    const parsed = JSON.parse(raw);
    if (!parsed.success) {
        throw new Error(parsed.error || `Fixture action failed: ${action}`);
    }
    return parsed.result || {};
}

function createFixtureNamespace(prefix) {
    return uniqueSuffix(`pw-${prefix}`);
}

function createPageHealthMonitor(page) {
    const state = {
        pageErrors: [],
        failedRequests: []
    };

    page.on('pageerror', (error) => {
        state.pageErrors.push(String(error && error.message ? error.message : error));
    });

    page.on('requestfailed', (request) => {
        const resourceType = request.resourceType();
        if (!['document', 'script', 'stylesheet', 'xhr', 'fetch'].includes(resourceType)) {
            return;
        }
        state.failedRequests.push({
            url: request.url(),
            resourceType,
            failureText: request.failure() ? request.failure().errorText : 'unknown failure'
        });
    });

    return state;
}

async function stabilizeBackgroundRequests(page) {
    if (page.__crmBackgroundRoutesInstalled) {
        return;
    }
    page.__crmBackgroundRoutesInstalled = true;

    await page.route('**/*', async (route) => {
        const url = route.request().url();
        const resourceType = route.request().resourceType();
        const isKnownExternalAsset = /^https:\/\/(?:cdnjs\.cloudflare\.com|cdn\.quilljs\.com|cdn\.jsdelivr\.net|fonts\.googleapis\.com)\//i.test(url);
        if (isKnownExternalAsset) {
            if (resourceType === 'stylesheet') {
                await route.fulfill({
                    status: 200,
                    contentType: 'text/css',
                    body: '/* external stylesheet stubbed for local smoke tests */'
                }).catch(() => {});
                return;
            }

            if (/chart\.js/i.test(url)) {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/javascript',
                    body: [
                        'window.Chart = window.Chart || function(){ return {destroy:function(){}, update:function(){}, resize:function(){}}; };',
                        'window.Chart.defaults = window.Chart.defaults || {font:{}, color:"#0f172a", plugins:{legend:{labels:{font:{}}}}, elements:{point:{}, line:{}, bar:{}, arc:{}}};',
                        'window.Chart.defaults.elements = window.Chart.defaults.elements || {point:{}, line:{}, bar:{}, arc:{}};',
                        'window.Chart.defaults.elements.point = window.Chart.defaults.elements.point || {};',
                        'window.Chart.register = window.Chart.register || function(){};'
                    ].join('\n')
                }).catch(() => {});
                return;
            }

            if (/quill\.js/i.test(url)) {
                await route.fulfill({
                    status: 200,
                    contentType: 'application/javascript',
                    body: [
                        'window.Quill = window.Quill || function(element){',
                        '  var container = typeof element === "string" ? document.querySelector(element) : element;',
                        '  container = container || document.createElement("div");',
                        '  var editor = container.querySelector(".ql-editor");',
                        '  if (!editor) {',
                        '    editor = document.createElement("div");',
                        '    editor.className = "ql-editor";',
                        '    editor.contentEditable = "true";',
                        '    container.appendChild(editor);',
                        '  }',
                        '  this.root = editor;',
                        '  var callbacks = {};',
                        '  this.root.addEventListener("input", function(){ (callbacks["text-change"] || []).forEach(function(cb){ cb(); }); });',
                        '  this.getText = function(){ return this.root.textContent || ""; };',
                        '  this.setText = function(value){ this.root.textContent = value || ""; };',
                        '  this.getContents = function(){ return {ops: []}; };',
                        '  this.setContents = function(){};',
                        '  this.getSelection = function(){ return {index: this.getText().length, length: 0}; };',
                        '  this.setSelection = function(){};',
                        '  this.getLength = function(){ return this.getText().length; };',
                        '  this.deleteText = function(){};',
                        '  this.insertText = function(index, value){ this.root.textContent = (this.root.textContent || "") + (value || ""); };',
                        '  this.insertEmbed = function(){};',
                        '  this.on = function(event, callback){ callbacks[event] = callbacks[event] || []; callbacks[event].push(callback); };',
                        '  this.getModule = function(){ return {addHandler:function(){}}; };',
                        '  this.clipboard = {dangerouslyPasteHTML: function(html){ this.root.innerHTML = html || ""; }.bind(this)};',
                        '};'
                    ].join('\n')
                }).catch(() => {});
                return;
            }

            await route.fulfill({ status: 204, body: '' }).catch(() => {});
            return;
        }

        const shouldAbort = /\/api\/notifications\.php\?action=count/i.test(url)
            || /\/api\/session\/postload\.php/i.test(url)
            || /\/api\/search\.php\?action=suggestions/i.test(url);

        if (shouldAbort) {
            await route.abort('aborted').catch(() => {});
            return;
        }

        await route.continue().catch(() => {});
    });
}

async function gotoAndWait(page, path) {
    const targetUrl = `${BASE_URL}/${path}`;
    try {
        await page.goto(targetUrl, { waitUntil: 'commit', timeout: 30000 });
    } catch (error) {
        const message = String(error && error.message ? error.message : error);
        const landedOnTarget = page.url() && page.url().includes(path.split('?')[0]);
        if (!/ERR_ABORTED/i.test(message) || !landedOnTarget) {
            throw error;
        }
    }
    await page.waitForLoadState('domcontentloaded', { timeout: 30000 }).catch(() => {});
    await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    await dismissCookieBanner(page);
    await dismissDashboardSetupPopup(page);
}

async function dismissCookieBanner(page) {
    const rejectButton = page.locator('#cookie-reject');
    if (await rejectButton.count()) {
        const banner = page.locator('#cookie-consent-banner');
        if ((await banner.count()) && await banner.first().isVisible().catch(() => false)) {
            await rejectButton.first().click({ timeout: 5000 }).catch(() => {});
            await page.waitForTimeout(250);
        }
    }
}

async function dismissDashboardSetupPopup(page) {
    const popup = page.locator('#dashboard-setup-popup.is-visible');
    if (!(await popup.count())) {
        return;
    }

    const dismissButton = page.locator('[data-dashboard-setup-dismiss]');
    if (await dismissButton.count()) {
        await dismissButton.first().click({ timeout: 5000 }).catch(() => {});
        await page.waitForTimeout(250);
    }
}

async function loginAsAdmin(page) {
    await stabilizeBackgroundRequests(page);
    runFixture('ensure_user_role', { email: TEST_EMAIL, role_slug: 'admin' });
    runFixture('ensure_user', { email: TEST_EMAIL, password: TEST_PASSWORD, profile_role: 'admin', first_name: 'Launch', last_name: 'Admin' });
    await page.goto('about:blank').catch(() => {});
    await gotoAndWait(page, 'login.php');

    const emailInput = page.locator('input[name="email"]');
    if (await emailInput.count()) {
        await emailInput.fill(TEST_EMAIL);
        await page.fill('input[name="password"]', TEST_PASSWORD);
        const navigation = page.waitForURL(/dashboard\.php|index\.php/i, { timeout: 30000 }).catch(() => null);
        await page.click('button[type="submit"]');
        await navigation;
        await page.waitForLoadState('domcontentloaded', { timeout: 30000 }).catch(() => {});
        await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    }

    await expect(page.locator('.navbar')).toBeVisible({ timeout: 30000 });
}

async function loginAsUser(page, email, password = TEST_PASSWORD) {
    await stabilizeBackgroundRequests(page);
    await page.goto('about:blank').catch(() => {});
    await gotoAndWait(page, 'login.php');

    const emailInput = page.locator('input[name="email"]');
    if (await emailInput.count()) {
        await emailInput.fill(email);
        await page.fill('input[name="password"]', password);
        const navigation = page.waitForURL(/dashboard\.php|index\.php/i, { timeout: 30000 }).catch(() => null);
        await page.click('button[type="submit"]');
        await navigation;
        await page.waitForLoadState('domcontentloaded', { timeout: 30000 }).catch(() => {});
        await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
    }

    await expect(page.locator('.navbar')).toBeVisible({ timeout: 30000 });
}

function ensureRoleWithPermissions(roleSlug, roleName, permissionKeys, payload = {}) {
    return runFixture('ensure_role_with_permissions', {
        role_slug: roleSlug,
        role_name: roleName,
        permission_keys: permissionKeys,
        ...payload
    });
}

function ensureUser(email, payload = {}) {
    return runFixture('ensure_user', {
        email,
        password: TEST_PASSWORD,
        ...payload
    });
}

function ensureUserWithRole(email, roleSlug, roleName, permissionKeys, payload = {}) {
    return runFixture('ensure_user_with_role', {
        email,
        role_slug: roleSlug,
        role_name: roleName,
        permission_keys: permissionKeys,
        password: TEST_PASSWORD,
        ...payload
    });
}

function seedContactRecord(namespace, payload = {}) {
    return runFixture('seed_contact_record', {
        namespace,
        ...payload
    });
}

function seedScopedEmailConversation(namespace, payload = {}) {
    return runFixture('seed_scoped_email_conversation', {
        namespace,
        ...payload
    });
}

async function assertPageHealthy(page, monitor, options = {}) {
    const bodyText = await page.locator('body').innerText().catch(() => '');
    const allowedPatterns = options.allowPatterns || [];
    const visibleProblems = FATAL_TEXT_PATTERNS.filter((pattern) => {
        if (!pattern.test(bodyText)) {
            return false;
        }
        return !allowedPatterns.some((allowed) => allowed.test(bodyText));
    }).map((pattern) => pattern.toString());

    expect(
        visibleProblems,
        `Visible fatal/warning text detected on ${page.url()}`
    ).toEqual([]);

    expect(
        monitor.pageErrors,
        `Uncaught page errors detected on ${page.url()}`
    ).toEqual([]);

    const failedRequests = monitor.failedRequests.filter((entry) => {
        return !/favicon\.ico/i.test(entry.url);
    }).filter((entry) => {
        const failureText = String(entry.failureText || '');
        const isBenignAbort = /ERR_ABORTED/i.test(failureText) && (
            ['fetch', 'xhr'].includes(entry.resourceType)
            || /cdn\.jsdelivr\.net\/npm\/chart\.js/i.test(entry.url)
        );
        return !isBenignAbort;
    });

    expect(
        failedRequests,
        `Critical request failures detected on ${page.url()}`
    ).toEqual([]);
}

function extractIdFromHref(href, paramName = 'id') {
    if (!href) {
        return null;
    }
    const match = href.match(new RegExp(`[?&]${paramName}=(\\d+)`, 'i'));
    return match ? Number(match[1]) : null;
}

async function getFirstHref(page, selector) {
    const link = page.locator(selector).first();
    if (await link.count()) {
        return link.getAttribute('href');
    }
    return null;
}

async function selectFirstRealOption(page, selector) {
    const options = await page.locator(`${selector} option`).evaluateAll((nodes) => {
        return nodes.map((node) => ({
            value: node.value,
            disabled: node.disabled
        }));
    });

    const option = options.find((entry) => entry.value && !entry.disabled);
    if (!option) {
        throw new Error(`No selectable option found for ${selector}`);
    }

    await page.selectOption(selector, option.value);
    return option.value;
}

async function ensureContactExists(page) {
    await gotoAndWait(page, 'contacts.php');
    let href = await getFirstHref(page, 'a[href*="contact_view.php?id="]');

    if (!href) {
        const namespace = uniqueSuffix('contact');
        const seeded = runFixture('seed_contact_record', {
            namespace,
            assigned_to_email: TEST_EMAIL,
            created_by_email: TEST_EMAIL
        });
        const seededId = Number(seeded.contact_id || 0);
        if (!seededId) {
            throw new Error('Unable to seed a contact fixture for smoke tests.');
        }
        href = `contact_view.php?id=${seededId}`;
    }

    const id = extractIdFromHref(href);
    if (!id) {
        throw new Error('Unable to resolve a contact id for smoke tests.');
    }

    return { id, href };
}

function seedEmailConversationFixture(namespace = createFixtureNamespace('conversation')) {
    return {
        namespace,
        ...runFixture('seed_email_conversation', { namespace, email: TEST_EMAIL })
    };
}

function seedNotificationsFixture(namespace = createFixtureNamespace('notifications')) {
    return {
        namespace,
        ...runFixture('seed_notifications', { namespace, email: TEST_EMAIL })
    };
}

function seedDealFixture(namespace = createFixtureNamespace('deal')) {
    return {
        namespace,
        ...runFixture('seed_deal', { namespace, email: TEST_EMAIL })
    };
}

function seedTaskFixture(namespace = createFixtureNamespace('task')) {
    return {
        namespace,
        ...runFixture('seed_task_with_subtasks', { namespace, email: TEST_EMAIL })
    };
}

function cleanupFixtureNamespace(namespace) {
    if (!namespace) {
        return null;
    }
    return runFixture('cleanup_namespace', { namespace });
}

async function ensureDealExists(page, contactId) {
    await gotoAndWait(page, 'deals.php');
    let href = await getFirstHref(page, 'a[href*="deal_view.php?id="]');

    if (!href) {
        await gotoAndWait(page, 'deal_create.php');
        await page.fill('#title', `Smoke Deal ${uniqueSuffix('deal')}`);
        await page.fill('#description', 'Smoke test deal for browser verification.');
        await page.selectOption('#contact_id', String(contactId));
        await page.selectOption('#stage', 'prospecting');
        await page.fill('#value', '1000');
        await page.fill('#probability', '20');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.click('button[type="submit"]')
        ]);

        href = page.url().includes('deal_view.php') ? page.url() : await getFirstHref(page, 'a[href*="deal_view.php?id="]');
    }

    const id = extractIdFromHref(href);
    if (!id) {
        throw new Error('Unable to resolve a deal id for smoke tests.');
    }

    return { id, href };
}

async function ensureTaskExists(page, contactId) {
    await gotoAndWait(page, 'tasks.php');
    let href = await getFirstHref(page, 'a[href*="task_view.php?id="]');

    if (!href) {
        await gotoAndWait(page, 'task_create.php');
        await page.fill('#title', `Smoke Task ${uniqueSuffix('task')}`);
        await page.fill('#description', 'Smoke test task for browser verification.');
        await page.selectOption('#contact_id', String(contactId));
        await page.selectOption('#priority', 'medium');
        await page.selectOption('#status', 'pending');

        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
            page.click('button[type="submit"]')
        ]);

        href = page.url().includes('task_view.php') ? page.url() : await getFirstHref(page, 'a[href*="task_view.php?id="]');
    }

    const id = extractIdFromHref(href);
    if (!id) {
        throw new Error('Unable to resolve a task id for smoke tests.');
    }

    return { id, href };
}

async function ensureTargetExists(page) {
    await gotoAndWait(page, 'targets.php');
    let href = await getFirstHref(page, 'a[href*="target_view.php?id="]');

    if (!href) {
        const namespace = uniqueSuffix('target');
        const seeded = runFixture('seed_target', {
            namespace,
            email: TEST_EMAIL,
            scope: 'personal',
        });

        await gotoAndWait(page, `targets.php?search=${encodeURIComponent(seeded.title)}`);
        href = await getFirstHref(page, 'a[href*="target_view.php?id="]');
    }

    const id = extractIdFromHref(href);
    if (!id) {
        throw new Error('Unable to resolve a target id for smoke tests.');
    }

    return { id, href };
}

function futureDate(days) {
    const date = new Date();
    date.setDate(date.getDate() + days);
    return date.toISOString().slice(0, 10);
}

async function findTaskWithChecklist(page) {
    await gotoAndWait(page, 'tasks.php');
    const hrefs = await page.locator('a[href*="task_view.php?id="]').evaluateAll((links) => {
        return links.map((link) => link.getAttribute('href')).filter(Boolean);
    });

    for (const href of hrefs.slice(0, 10)) {
        await gotoAndWait(page, href.replace(/^\//, ''));
        if (await page.locator('.task-subtask-toggle').count()) {
            return { href, id: extractIdFromHref(href) };
        }
    }

    throw new Error('Smoke data contract not met: expected at least one task with checklist subtasks.');
}

module.exports = {
    BASE_URL,
    FIXTURE_SCRIPT,
    PHP_EXE,
    TEST_EMAIL,
    TEST_PASSWORD,
    assertPageHealthy,
    cleanupFixtureNamespace,
    createFixtureNamespace,
    createPageHealthMonitor,
    ensureContactExists,
    ensureDealExists,
    ensureTargetExists,
    ensureTaskExists,
    extractIdFromHref,
    findTaskWithChecklist,
    futureDate,
    getFirstHref,
    gotoAndWait,
    dismissCookieBanner,
    ensureRoleWithPermissions,
    ensureUser,
    ensureUserWithRole,
    loginAsAdmin,
    loginAsUser,
    runFixture,
    seedContactRecord,
    selectFirstRealOption,
    seedDealFixture,
    seedEmailConversationFixture,
    seedScopedEmailConversation,
    seedNotificationsFixture,
    seedTaskFixture,
    stabilizeBackgroundRequests,
    uniqueSuffix
};
