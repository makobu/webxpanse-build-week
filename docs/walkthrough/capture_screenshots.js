/**
 * Module 1 Walkthrough Screenshot Capture
 * Captures screenshots of all key Clarity CRM pages for the PDF walkthrough.
 * Run with: node capture_screenshots.js
 */

const { chromium } = require('@playwright/test');
const path = require('path');
const fs = require('fs');

const BASE_URL = 'http://localhost/crm/public';
const EMAIL = 'launch.operator.probe@example.test';
const PASSWORD = 'password';
const OUT_DIR = path.resolve(__dirname, 'screenshots');

if (!fs.existsSync(OUT_DIR)) fs.mkdirSync(OUT_DIR, { recursive: true });

async function login(page) {
    await page.goto(`${BASE_URL}/login.php`);
    await page.waitForLoadState('networkidle');
    // Fill login form
    const emailField = page.locator('input[type="email"], input[name="email"]').first();
    const passField  = page.locator('input[type="password"]').first();
    await emailField.fill(EMAIL);
    await passField.fill(PASSWORD);
    await page.keyboard.press('Enter');
    await page.waitForURL(/dashboard\.php|index\.php/i, { timeout: 10000 });
    await page.waitForLoadState('networkidle');
}

async function shot(page, filename, scrollY = 0) {
    if (scrollY > 0) {
        await page.evaluate(y => window.scrollTo(0, y), scrollY);
        await page.waitForTimeout(600);
    } else {
        await page.evaluate(() => window.scrollTo(0, 0));
        await page.waitForTimeout(600);
    }
    const filepath = path.join(OUT_DIR, filename);
    await page.screenshot({ path: filepath, fullPage: false });
    console.log(`  Saved: ${filename}`);
    return filepath;
}

(async () => {
    const browser = await chromium.launch({ headless: true, channel: 'chrome' });
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
    const page = await ctx.newPage();

    console.log('Logging in...');
    await login(page);
    console.log('Logged in.\n');

    // ── 1. Dashboard – top hero + nav ──────────────────────────────────────────
    console.log('1. Dashboard top (nav + welcome)');
    await page.goto(`${BASE_URL}/dashboard.php`);
    await page.waitForLoadState('networkidle');
    await shot(page, '01_dashboard_top.png');

    // ── 2. Dashboard – automation readiness + metrics ──────────────────────────
    console.log('2. Dashboard metrics section');
    await shot(page, '02_dashboard_metrics.png', 800);

    // ── 3. Dashboard – Today / daily focus ────────────────────────────────────
    console.log('3. Dashboard daily focus / tasks');
    await shot(page, '03_dashboard_today.png', 1800);

    // ── 4. Navigation bar zoomed ───────────────────────────────────────────────
    console.log('4. Navigation bar close-up');
    await page.evaluate(() => window.scrollTo(0, 0));
    await page.waitForTimeout(400);
    await page.screenshot({
        path: path.join(OUT_DIR, '04_navigation_bar.png'),
        clip: { x: 280, y: 0, width: 1160, height: 90 }
    });
    console.log('  Saved: 04_navigation_bar.png');

    // ── 5. Contacts ────────────────────────────────────────────────────────────
    console.log('5. Contacts page');
    await page.goto(`${BASE_URL}/contacts.php`);
    await page.waitForLoadState('networkidle');
    await shot(page, '05_contacts.png');

    // ── 6. Deals ───────────────────────────────────────────────────────────────
    console.log('6. Deals page');
    await page.goto(`${BASE_URL}/deals.php`);
    await page.waitForLoadState('networkidle');
    await shot(page, '06_deals.png');

    // ── 7. Inbox ───────────────────────────────────────────────────────────────
    console.log('7. Inbox page');
    await page.goto(`${BASE_URL}/inbox.php`);
    await page.waitForLoadState('networkidle');
    await shot(page, '07_inbox.png');

    // ── 8. Tasks ───────────────────────────────────────────────────────────────
    console.log('8. Tasks page');
    await page.goto(`${BASE_URL}/tasks.php`);
    await page.waitForLoadState('networkidle');
    await shot(page, '08_tasks.png');

    // ── 9. AI Control Center ───────────────────────────────────────────────────
    console.log('9. AI control center');
    await page.goto(`${BASE_URL}/ai_control_center.php`);
    await page.waitForLoadState('networkidle');
    await shot(page, '09_ai_control_center.png');

    // ── 10. Settings ───────────────────────────────────────────────────────────
    console.log('10. Settings page');
    await page.goto(`${BASE_URL}/settings.php`);
    await page.waitForLoadState('networkidle');
    await shot(page, '10_settings.png');

    // ── 11. Notification Preferences ──────────────────────────────────────────
    console.log('11. Notification preferences');
    await page.goto(`${BASE_URL}/notification_preferences.php`);
    await page.waitForLoadState('networkidle');
    await shot(page, '11_notification_preferences.png');

    await browser.close();
    console.log('\nAll screenshots saved to:', OUT_DIR);
})();
