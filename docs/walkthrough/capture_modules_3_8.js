/**
 * Capture screenshots for Modules 3–8 (localhost, post-seed)
 * Run: node capture_modules_3_8.js
 */

const { chromium } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const BASE_URL = 'http://localhost/crm/public';
const EMAIL    = 'launch.operator.probe@example.test';
const PASSWORD = 'password';
const SHOTS    = path.resolve(__dirname, 'screenshots');

async function mkDir(p) { if (!fs.existsSync(p)) fs.mkdirSync(p, { recursive: true }); }

async function login(page) {
    await page.goto(`${BASE_URL}/login.php`);
    await page.waitForLoadState('networkidle');
    await page.locator('input[type="email"], input[name="email"]').first().fill(EMAIL);
    await page.locator('input[type="password"]').first().fill(PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/dashboard\.php|index\.php/i, { timeout: 10000 });
    await page.waitForLoadState('networkidle');
}

async function shot(page, dir, name, shotOpts = {}) {
    const { scrollY = 0, clip = null } = shotOpts;
    if (scrollY) await page.evaluate(y => window.scrollTo(0, y), scrollY);
    else await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(700);
    const fp = path.join(dir, name);
    const screenshotArgs = { path: fp };
    if (clip) screenshotArgs.clip = clip;
    await page.screenshot(screenshotArgs);
    console.log(`  ✓ ${name}`);
}

async function nav(page, url) {
    await page.goto(`${BASE_URL}/${url}`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(600);
}

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();

    console.log('Logging in...');
    await login(page);
    console.log('Done.\n');

    // ═══════════════════════════════════════════════════════════════
    // MODULE 3 — Inbox
    // ═══════════════════════════════════════════════════════════════
    const m3 = path.join(SHOTS, 'Module 3'); mkDir(m3);
    console.log('Module 3 — Inbox');

    await nav(page, 'inbox.php');
    await shot(page, m3, '01_inbox_list.png');
    await shot(page, m3, '02_inbox_filters.png', { scrollY: 120 });

    // Try to open first conversation
    const thread = page.locator('[data-conversation-id], .conversation-item, .thread-item, tbody tr a').first();
    if (await thread.isVisible().catch(() => false)) {
        await thread.click();
        await page.waitForTimeout(800);
        await shot(page, m3, '03_inbox_conversation.png');
    } else {
        await shot(page, m3, '03_inbox_conversation.png');
    }

    await nav(page, 'email_compose.php');
    await shot(page, m3, '04_email_compose.png');

    // Email templates
    await nav(page, 'email_templates.php');
    await shot(page, m3, '05_email_templates.png');

    // ═══════════════════════════════════════════════════════════════
    // MODULE 4 — Deals
    // ═══════════════════════════════════════════════════════════════
    const m4 = path.join(SHOTS, 'Module 4'); mkDir(m4);
    console.log('\nModule 4 — Deals');

    await nav(page, 'deals.php');
    await shot(page, m4, '01_deals_list.png');
    await shot(page, m4, '02_deals_table.png', { scrollY: 300 });

    await nav(page, 'deal_create.php');
    await shot(page, m4, '03_deal_create.png');

    // Deal detail — click first deal
    await nav(page, 'deals.php');
    const firstDeal = page.locator('a[href*="deal_view"], table tbody tr td a').first();
    const dealHref = await firstDeal.getAttribute('href').catch(() => null);
    if (dealHref) {
        const url = dealHref.startsWith('http') ? dealHref : `${BASE_URL}/${dealHref.replace(/^\//,'')}`;
        await page.goto(url);
        await page.waitForLoadState('networkidle');
        await shot(page, m4, '04_deal_detail_top.png');
        await shot(page, m4, '05_deal_detail_activity.png', { scrollY: 500 });
    }

    // Pipeline stage pages
    await nav(page, 'deals_proposal.php');
    await shot(page, m4, '06_deals_proposal_stage.png');

    // ═══════════════════════════════════════════════════════════════
    // MODULE 5 — Tasks
    // ═══════════════════════════════════════════════════════════════
    const m5 = path.join(SHOTS, 'Module 5'); mkDir(m5);
    console.log('\nModule 5 — Tasks');

    await nav(page, 'tasks.php');
    await shot(page, m5, '01_tasks_list.png');
    await shot(page, m5, '02_tasks_status_cards.png', { scrollY: 250 });

    await nav(page, 'task_create.php');
    await shot(page, m5, '03_task_create.png');

    // Task detail
    await nav(page, 'tasks.php');
    const firstTask = page.locator('a[href*="task_view"], table tbody tr td a').first();
    const taskHref = await firstTask.getAttribute('href').catch(() => null);
    if (taskHref) {
        const url = taskHref.startsWith('http') ? taskHref : `${BASE_URL}/${taskHref.replace(/^\//,'')}`;
        await page.goto(url);
        await page.waitForLoadState('networkidle');
        await shot(page, m5, '04_task_detail.png');
    }

    // ═══════════════════════════════════════════════════════════════
    // MODULE 6 — AI Features
    // ═══════════════════════════════════════════════════════════════
    const m6 = path.join(SHOTS, 'Module 6'); mkDir(m6);
    console.log('\nModule 6 — AI Features');

    await nav(page, 'ai_control_center.php');
    await shot(page, m6, '01_ai_control_center.png');
    await shot(page, m6, '02_ai_surface_controls.png', { scrollY: 450 });

    await nav(page, 'email_assistant_capabilities.php');
    await shot(page, m6, '03_email_assistant.png');

    await nav(page, 'ai_learning_review.php');
    await shot(page, m6, '04_ai_learning_review.png');

    await nav(page, 'ai_automation_diagnostics.php');
    await shot(page, m6, '05_ai_diagnostics.png');

    // Email compose — AI drafting context
    await nav(page, 'email_compose.php');
    await shot(page, m6, '06_email_compose_ai.png');

    // ═══════════════════════════════════════════════════════════════
    // MODULE 7 — Notifications
    // ═══════════════════════════════════════════════════════════════
    const m7 = path.join(SHOTS, 'Module 7'); mkDir(m7);
    console.log('\nModule 7 — Notifications');

    await nav(page, 'notifications.php');
    await shot(page, m7, '01_notifications_list.png');
    await shot(page, m7, '02_notifications_scroll.png', { scrollY: 300 });

    await nav(page, 'notification_preferences.php');
    await shot(page, m7, '03_notification_prefs.png');
    await shot(page, m7, '04_notification_prefs_lower.png', { scrollY: 400 });

    // Nav bell close-up
    await nav(page, 'dashboard.php');
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(500);
    await page.screenshot({ path: path.join(m7, '05_nav_bell_closeup.png'), clip: { x: 1100, y: 0, width: 340, height: 90 } });
    console.log('  ✓ 05_nav_bell_closeup.png');

    // ═══════════════════════════════════════════════════════════════
    // MODULE 8 — Reporting
    // ═══════════════════════════════════════════════════════════════
    const m8 = path.join(SHOTS, 'Module 8'); mkDir(m8);
    console.log('\nModule 8 — Reporting');

    await nav(page, 'dashboard.php');
    await page.waitForTimeout(1000);
    await shot(page, m8, '01_dashboard_welcome.png');
    await shot(page, m8, '02_dashboard_metrics.png', { scrollY: 900 });
    await shot(page, m8, '03_dashboard_charts.png', { scrollY: 1800 });

    await nav(page, 'analytics.php');
    await shot(page, m8, '04_analytics_overview.png');
    await shot(page, m8, '05_analytics_charts.png', { scrollY: 500 });

    await nav(page, 'predictive_analytics.php');
    await shot(page, m8, '06_predictive_analytics.png');

    // Contacts with filters — saved view concept
    await nav(page, 'contacts.php');
    await shot(page, m8, '07_contacts_filter_view.png');

    await browser.close();
    console.log('\nAll screenshots captured.');
})();
