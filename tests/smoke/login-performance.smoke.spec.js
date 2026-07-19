const { test, expect } = require('@playwright/test');
const {
    BASE_URL,
    TEST_EMAIL,
    TEST_PASSWORD,
    dismissCookieBanner,
    runFixture
} = require('./helpers');

const LOGIN_POST_LIMIT_MS = Number(process.env.CRM_PERF_LOGIN_POST_MS || 300);
const DASHBOARD_LOAD_LIMIT_MS = Number(process.env.CRM_PERF_DASHBOARD_LOAD_MS || 1200);
const POSTLOAD_LIMIT_MS = Number(process.env.CRM_PERF_POSTLOAD_MS || 250);
const NOTIFICATION_COUNT_LIMIT_MS = Number(process.env.CRM_PERF_NOTIFICATION_COUNT_MS || 150);

function serverTimingDuration(header, metricName) {
    if (!header) {
        return null;
    }

    const entry = header.split(',').map((part) => part.trim()).find((part) => part.startsWith(`${metricName};`));
    if (!entry) {
        return null;
    }

    const match = entry.match(/(?:^|;)dur=([0-9.]+)/);
    return match ? Number(match[1]) : null;
}

test.describe('Smoke: Login performance', () => {
    test.skip(!process.env.CRM_PERF_SMOKE, 'Set CRM_PERF_SMOKE=1 to run threshold-based login performance checks.');

    test('login reaches dashboard and postload APIs stay fast', async ({ page }) => {
        runFixture('ensure_user_role', { email: TEST_EMAIL, role_slug: 'admin' });
        runFixture('ensure_user', {
            email: TEST_EMAIL,
            password: TEST_PASSWORD,
            profile_role: 'admin',
            first_name: 'Launch',
            last_name: 'Admin'
        });

        await page.route('**/*', async (route) => {
            const url = route.request().url();
            const isKnownExternalAsset = /^https:\/\/(?:cdnjs\.cloudflare\.com|cdn\.quilljs\.com|cdn\.jsdelivr\.net|fonts\.googleapis\.com)\//i.test(url);
            if (isKnownExternalAsset) {
                await route.fulfill({ status: 204, body: '' }).catch(() => {});
                return;
            }
            await route.continue().catch(() => {});
        });

        const apiTimings = [];
        const requestStarts = new Map();
        page.on('request', (request) => {
            const url = request.url();
            if (/\/api\/(?:session\/postload|notifications\.php\?action=count)/i.test(url)) {
                requestStarts.set(request, Date.now());
            }
        });
        page.on('response', async (response) => {
            const request = response.request();
            const startedAt = requestStarts.get(request);
            if (startedAt) {
                const serverTiming = await response.headerValue('server-timing').catch(() => null);
                apiTimings.push({
                    url: request.url(),
                    duration: Date.now() - startedAt,
                    serverDuration: serverTimingDuration(
                        serverTiming,
                        /\/api\/session\/postload\.php/i.test(request.url()) ? 'session_postload_total' : 'notifications_total'
                    )
                });
                requestStarts.delete(request);
            }
        });

        await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'domcontentloaded' });
        await dismissCookieBanner(page);
        await page.fill('input[name="email"]', TEST_EMAIL);
        await page.fill('input[name="password"]', TEST_PASSWORD);

        const loginStartedAt = Date.now();
        const loginPost = page.waitForResponse((response) => {
            return /\/login\.php$/i.test(new URL(response.url()).pathname)
                && response.request().method() === 'POST';
        }, { timeout: 30000 });
        const dashboardDocument = page.waitForResponse((response) => {
            return /\/dashboard\.php(?:\?|$)/i.test(response.url())
                && response.request().resourceType() === 'document';
        }, { timeout: 30000 });

        await page.click('button[type="submit"]');
        const loginResponse = await loginPost;
        const loginPostMs = serverTimingDuration(await loginResponse.headerValue('server-timing'), 'login_total')
            || (Date.now() - loginStartedAt);

        const dashboardResponse = await dashboardDocument;
        await page.waitForLoadState('domcontentloaded', { timeout: 30000 });
        await expect(page.locator('.navbar')).toBeVisible({ timeout: 30000 });

        await page.waitForTimeout(3500);
        const postload = apiTimings.find((timing) => /\/api\/session\/postload\.php/i.test(timing.url));
        const notificationCount = apiTimings.find((timing) => /\/api\/notifications\.php\?action=count/i.test(timing.url));

        const navigationTiming = await page.evaluate(() => {
            const nav = performance.getEntriesByType('navigation')[0];
            return nav ? {
                responseStart: Math.round(nav.responseStart),
                domContentLoaded: Math.round(nav.domContentLoadedEventEnd),
                load: Math.round(nav.loadEventEnd)
            } : null;
        });
        const dashboardLoadedMs = navigationTiming && navigationTiming.load > 0
            ? navigationTiming.load
            : Date.now() - loginStartedAt;

        console.log(JSON.stringify({
            loginPostMs,
            dashboardLoadedMs,
            dashboardStatus: dashboardResponse.status(),
            navigationTiming,
            postloadMs: postload ? postload.duration : null,
            postloadServerMs: postload ? postload.serverDuration : null,
            notificationCountMs: notificationCount ? notificationCount.duration : null,
            notificationCountServerMs: notificationCount ? notificationCount.serverDuration : null
        }, null, 2));

        expect(loginPostMs).toBeLessThanOrEqual(LOGIN_POST_LIMIT_MS);
        expect(dashboardLoadedMs).toBeLessThanOrEqual(DASHBOARD_LOAD_LIMIT_MS);
        expect(postload, 'session postload request should run after dashboard load').toBeTruthy();
        expect(notificationCount, 'notification count request should run after dashboard load').toBeTruthy();
        expect(postload.serverDuration || postload.duration).toBeLessThanOrEqual(POSTLOAD_LIMIT_MS);
        expect(notificationCount.serverDuration || notificationCount.duration).toBeLessThanOrEqual(NOTIFICATION_COUNT_LIMIT_MS);
    });
});
