/**
 * webXpanse — All 8 Modules Screenshot Capture
 * Run: node capture_all_modules.js
 */

const { chromium } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const BASE_URL = 'http://localhost/crm/public';
const EMAIL    = 'launch.operator.probe@example.test';
const PASSWORD = 'password';
const OUT_DIR  = path.resolve(__dirname, 'screenshots');

if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });

async function login(page) {
    await page.goto(`${BASE_URL}/login.php`);
    await page.waitForLoadState('networkidle');
    await page.locator('input[type="email"], input[name="email"]').first().fill(EMAIL);
    await page.locator('input[type="password"]').first().fill(PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/dashboard\.php|index\.php/i, { timeout: 10000 });
    await page.waitForLoadState('networkidle');
}

async function shot(page, filename, opts = {}) {
    const { scrollY = 0, clip = null, fullPage = false } = opts;
    if (scrollY > 0) {
        await page.evaluate(y => window.scrollTo(0, y), scrollY);
    } else {
        await page.evaluate(() => window.scrollTo(0, 0));
    }
    await page.waitForTimeout(700);
    const filepath = path.join(OUT_DIR, filename);
    await page.screenshot({ path: filepath, fullPage, clip });
    console.log(`  ✓ ${filename}`);
}

async function nav(page, url) {
    await page.goto(`${BASE_URL}/${url}`);
    await page.waitForLoadState('networkidle');
    await page.waitForTimeout(500);
}

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();

    console.log('Logging in...');
    await login(page);
    console.log('Done.\n');

    // ── MODULE 1: Getting Oriented ────────────────────────────────────────────
    console.log('Module 1 — Getting Oriented');
    await nav(page, 'dashboard.php');
    await shot(page, 'm1_dashboard_hero.png');          // top welcome + battery
    await shot(page, 'm1_dashboard_metrics.png', { scrollY: 900 }); // metric cards
    // nav bar clip
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(OUT_DIR, 'm1_nav_bar.png'), clip: { x: 270, y: 0, width: 1170, height: 88 } });
    console.log('  ✓ m1_nav_bar.png');
    await nav(page, 'settings.php');
    await shot(page, 'm1_settings.png');
    await nav(page, 'notification_preferences.php');
    await shot(page, 'm1_notif_prefs.png');

    // ── MODULE 2: Contacts ────────────────────────────────────────────────────
    console.log('\nModule 2 — Contacts');
    await nav(page, 'contacts.php');
    await shot(page, 'm2_contacts_list.png');
    await nav(page, 'contacts_create.php');
    await shot(page, 'm2_contact_create.png');
    await nav(page, 'contacts_import.php');
    await shot(page, 'm2_contact_import.png');
    // try to get a contact detail
    await nav(page, 'contacts.php');
    const firstContact = page.locator('table tbody tr a, .contact-row a, [data-href], a[href*="contact_view"]').first();
    const contactHref = await firstContact.getAttribute('href').catch(() => null);
    if (contactHref) {
        await page.goto(contactHref.startsWith('http') ? contactHref : `${BASE_URL}/${contactHref.replace(/^\//, '')}`);
        await page.waitForLoadState('networkidle');
        await shot(page, 'm2_contact_detail.png');
    } else {
        // fallback: take contacts page scrolled to show table
        await nav(page, 'contacts.php');
        await shot(page, 'm2_contact_detail.png', { scrollY: 200 });
    }
    // contact merge page
    await nav(page, 'contact_merge.php');
    await shot(page, 'm2_contact_merge.png');

    // ── MODULE 3: Inbox ───────────────────────────────────────────────────────
    console.log('\nModule 3 — Inbox');
    await nav(page, 'inbox.php');
    await shot(page, 'm3_inbox_full.png');
    await shot(page, 'm3_inbox_filters.png', { scrollY: 150 });
    // try to open a conversation
    const firstThread = page.locator('.conversation-item, .inbox-row, .thread-row, [data-conversation-id]').first();
    const hasThread = await firstThread.isVisible().catch(() => false);
    if (hasThread) {
        await firstThread.click();
        await page.waitForTimeout(600);
        await shot(page, 'm3_inbox_conversation.png');
    } else {
        await shot(page, 'm3_inbox_conversation.png');
    }
    // email compose
    await nav(page, 'email_compose.php');
    await shot(page, 'm3_email_compose.png');

    // ── MODULE 4: Deals ───────────────────────────────────────────────────────
    console.log('\nModule 4 — Deals');
    await nav(page, 'deals.php');
    await shot(page, 'm4_deals_list.png');
    await nav(page, 'deals_proposal.php');
    await shot(page, 'm4_deals_proposal.png');
    await nav(page, 'deal_create.php');
    await shot(page, 'm4_deal_create.png');
    // deal detail
    await nav(page, 'deals.php');
    const firstDeal = page.locator('a[href*="deal_view"], .deal-row a, table tbody tr a').first();
    const dealHref = await firstDeal.getAttribute('href').catch(() => null);
    if (dealHref) {
        await page.goto(dealHref.startsWith('http') ? dealHref : `${BASE_URL}/${dealHref.replace(/^\//, '')}`);
        await page.waitForLoadState('networkidle');
        await shot(page, 'm4_deal_detail.png');
    } else {
        await shot(page, 'm4_deal_detail.png');
    }

    // ── MODULE 5: Tasks ───────────────────────────────────────────────────────
    console.log('\nModule 5 — Tasks');
    await nav(page, 'tasks.php');
    await shot(page, 'm5_tasks_list.png');
    await shot(page, 'm5_tasks_overview.png', { scrollY: 250 });
    await nav(page, 'task_create.php');
    await shot(page, 'm5_task_create.png');
    // task detail
    await nav(page, 'tasks.php');
    const firstTask = page.locator('a[href*="task_view"], .task-row a, table tbody tr a').first();
    const taskHref = await firstTask.getAttribute('href').catch(() => null);
    if (taskHref) {
        await page.goto(taskHref.startsWith('http') ? taskHref : `${BASE_URL}/${taskHref.replace(/^\//, '')}`);
        await page.waitForLoadState('networkidle');
        await shot(page, 'm5_task_detail.png');
    } else {
        await shot(page, 'm5_task_detail.png');
    }

    // ── MODULE 6: AI Features ─────────────────────────────────────────────────
    console.log('\nModule 6 — AI Features');
    await nav(page, 'ai_control_center.php');
    await shot(page, 'm6_ai_control.png');
    await shot(page, 'm6_ai_surfaces.png', { scrollY: 400 });
    await nav(page, 'email_assistant_capabilities.php');
    await shot(page, 'm6_ai_email_capabilities.png');
    await nav(page, 'ai_learning_review.php');
    await shot(page, 'm6_ai_learning.png');
    await nav(page, 'ai_automation_diagnostics.php');
    await shot(page, 'm6_ai_diagnostics.png');
    // email compose for AI draft demo
    await nav(page, 'email_compose.php');
    await shot(page, 'm6_email_compose.png');

    // ── MODULE 7: Notifications ───────────────────────────────────────────────
    console.log('\nModule 7 — Notifications');
    await nav(page, 'notifications.php');
    await shot(page, 'm7_notifications.png');
    await nav(page, 'notification_preferences.php');
    await shot(page, 'm7_notif_prefs.png');
    // nav bell close-up
    await nav(page, 'dashboard.php');
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(400);
    await page.screenshot({ path: path.join(OUT_DIR, 'm7_nav_bell.png'), clip: { x: 1130, y: 0, width: 310, height: 88 } });
    console.log('  ✓ m7_nav_bell.png');

    // ── MODULE 8: Reporting ───────────────────────────────────────────────────
    console.log('\nModule 8 — Reporting');
    await nav(page, 'analytics.php');
    await shot(page, 'm8_analytics.png');
    await shot(page, 'm8_analytics_lower.png', { scrollY: 500 });
    await nav(page, 'dashboard.php');
    await shot(page, 'm8_dashboard_top.png');
    await shot(page, 'm8_dashboard_metrics.png', { scrollY: 900 });
    await nav(page, 'predictive_analytics.php');
    await shot(page, 'm8_predictive.png');

    await browser.close();
    console.log(`\nAll screenshots saved to: ${OUT_DIR}`);
})();
