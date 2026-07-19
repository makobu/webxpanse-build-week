const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const {
    BASE_URL,
    TEST_EMAIL,
    TEST_PASSWORD,
    runFixture
} = require('../smoke/helpers');

const SAMPLE_COUNT = Math.max(1, Number(process.env.CRM_PERF_SAMPLES || 3));
const OUTPUT_PATH = process.env.CRM_PERF_AUDIT_OUTPUT
    || path.resolve(__dirname, '..', '..', 'tmp', 'performance-audit.json');
const PAGE_TARGETS = [
    { name: 'Sign in', path: 'login.php', impact: 5 },
    { name: 'Dashboard', path: 'dashboard.php', impact: 5 },
    { name: 'Inbox', path: 'inbox.php', impact: 5 },
    { name: 'Contacts', path: 'contacts.php', impact: 5 },
    { name: 'Deals', path: 'deals.php', impact: 4 },
    { name: 'Tasks', path: 'tasks.php', impact: 4 }
];

function nearestRank(values, percentile) {
    const sorted = values.filter(Number.isFinite).sort((a, b) => a - b);
    if (!sorted.length) return null;
    return sorted[Math.min(sorted.length - 1, Math.ceil(percentile * sorted.length) - 1)];
}

function median(values) {
    return nearestRank(values, 0.5);
}

function round(value) {
    return Number.isFinite(value) ? Math.round(value) : null;
}

function parseServerTiming(header) {
    if (!header) return [];
    return header.split(',').map((part) => {
        const [namePart, ...attributes] = part.trim().split(';');
        const durationAttribute = attributes.find((attribute) => attribute.trim().startsWith('dur='));
        const duration = durationAttribute ? Number(durationAttribute.trim().slice(4)) : 0;
        return {
            name: namePart.trim(),
            duration_ms: Number.isFinite(duration) ? duration : 0
        };
    }).filter((metric) => metric.name !== '');
}

function summarizeSample(data, sample) {
    const { navigation, paint, long_tasks: longTasks, resources } = data;
    const applicationOrigin = new URL(BASE_URL).origin;
    const resourceRows = resources.map((resource) => ({
        name: resource.name,
        duration_ms: round(resource.duration),
        transfer_bytes: resource.transferSize || 0,
        type: resource.initiatorType || 'other',
        third_party: new URL(resource.name).origin !== applicationOrigin,
        server_timing: resource.serverTiming || []
    }));
    const slowResources = resourceRows
        .filter((resource) => resource.duration_ms !== null)
        .sort((a, b) => b.duration_ms - a.duration_ms)
        .slice(0, 5);

    return {
        sample,
        url: data.url,
        navigation_ms: round(navigation.response_end),
        ttfb_ms: round(navigation.response_start),
        document_server_timing: navigation.server_timing || [],
        dom_content_loaded_ms: round(navigation.dom_content_loaded),
        load_ms: round(navigation.load),
        first_contentful_paint_ms: round(paint.first_contentful_paint),
        resource_count: resourceRows.length,
        transfer_bytes: resourceRows.reduce((total, resource) => total + resource.transfer_bytes, 0),
        third_party_request_count: resourceRows.filter((resource) => resource.third_party).length,
        long_task_count: longTasks.length,
        long_task_ms: round(longTasks.reduce((total, entry) => total + entry.duration, 0)),
        slow_resources: slowResources
    };
}

async function measurePage(page, target, sample) {
    const documentResponse = await page.goto(`${BASE_URL}/${target.path}?perf_audit=${sample}&perf_debug=1`, {
        waitUntil: 'load',
        timeout: 60000
    });
    await page.waitForTimeout(1500);

    const data = await page.evaluate(() => {
        const navigation = performance.getEntriesByType('navigation')[0];
        const paint = performance.getEntriesByType('paint');
        return {
            navigation: navigation ? {
                response_start: navigation.responseStart,
                response_end: navigation.responseEnd,
                dom_content_loaded: navigation.domContentLoadedEventEnd,
                load: navigation.loadEventEnd,
                server_timing: Array.from(navigation.serverTiming || []).map((metric) => ({
                    name: metric.name,
                    duration_ms: metric.duration
                }))
            } : {},
            paint: {
                first_contentful_paint: (paint.find((entry) => entry.name === 'first-contentful-paint') || {}).startTime || null
            },
            long_tasks: window.__crmPerformanceLongTasks || [],
            url: window.location.href,
            resources: performance.getEntriesByType('resource').map((entry) => ({
                name: entry.name,
                duration: entry.duration,
                transferSize: entry.transferSize,
                initiatorType: entry.initiatorType,
                serverTiming: Array.from(entry.serverTiming || []).map((metric) => ({
                    name: metric.name,
                    duration_ms: metric.duration
                }))
            }))
        };
    });
    data.navigation.server_timing = parseServerTiming(
        documentResponse ? await documentResponse.headerValue('server-timing') : null
    );

    return summarizeSample(data, sample);
}

function pageSummary(target, samples) {
    const metric = (key) => samples.map((sample) => sample[key]);
    const p75Navigation = nearestRank(metric('navigation_ms'), 0.75) || 0;
    const p75Ttfb = nearestRank(metric('ttfb_ms'), 0.75) || 0;
    const p75LongTask = nearestRank(metric('long_task_ms'), 0.75) || 0;
    const p75Transfer = nearestRank(metric('transfer_bytes'), 0.75) || 0;
    const priorityScore = Math.round(target.impact * (
        p75Navigation / 1000
        + p75Ttfb / 1000
        + p75LongTask / 1000
        + p75Transfer / 500000
    ) * 10);

    return {
        page: target.name,
        path: target.path,
        impact_weight: target.impact,
        priority_score: priorityScore,
        p75: {
            navigation_ms: p75Navigation,
            ttfb_ms: p75Ttfb,
            dom_content_loaded_ms: nearestRank(metric('dom_content_loaded_ms'), 0.75),
            load_ms: nearestRank(metric('load_ms'), 0.75),
            first_contentful_paint_ms: nearestRank(metric('first_contentful_paint_ms'), 0.75),
            resource_count: nearestRank(metric('resource_count'), 0.75),
            transfer_bytes: p75Transfer,
            third_party_request_count: nearestRank(metric('third_party_request_count'), 0.75),
            long_task_count: nearestRank(metric('long_task_count'), 0.75),
            long_task_ms: p75LongTask
        },
        median: {
            navigation_ms: median(metric('navigation_ms')),
            transfer_bytes: median(metric('transfer_bytes'))
        },
        slow_resources: samples.flatMap((sample) => sample.slow_resources)
            .sort((a, b) => b.duration_ms - a.duration_ms)
            .slice(0, 5),
        samples
    };
}

test.describe('Performance: high-impact CRM pages', () => {
    test.skip(!process.env.CRM_PERF_AUDIT, 'Set CRM_PERF_AUDIT=1 to run the repeatable performance audit.');

    test('collects a ranked baseline', async ({ page }) => {
        test.setTimeout(Math.max(120000, (PAGE_TARGETS.length * SAMPLE_COUNT * 30000) + 60000));
        runFixture('ensure_user_role', { email: TEST_EMAIL, role_slug: 'admin' });
        runFixture('ensure_user', {
            email: TEST_EMAIL,
            password: TEST_PASSWORD,
            profile_role: 'admin',
            first_name: 'Performance',
            last_name: 'Audit'
        });

        await page.addInitScript(() => {
            window.__crmPerformanceLongTasks = [];
            try {
                new PerformanceObserver((list) => {
                    window.__crmPerformanceLongTasks.push(...list.getEntries().map((entry) => ({ duration: entry.duration })));
                }).observe({ type: 'longtask', buffered: true });
            } catch (_) {
                // Long Tasks API is unavailable in some browser/runtime combinations.
            }
        });

        const results = [];
        for (const target of PAGE_TARGETS) {
            const samples = [];
            for (let sample = 1; sample <= SAMPLE_COUNT; sample += 1) {
                if (target.path !== 'login.php' && !(await page.locator('.navbar').count())) {
                    await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
                    await page.locator('input[name="email"]').fill(TEST_EMAIL);
                    await page.locator('input[name="password"]').fill(TEST_PASSWORD);
                    await Promise.all([
                        page.waitForURL(/dashboard\.php|index\.php/i, { timeout: 60000 }),
                        page.locator('button[type="submit"]').click()
                    ]);
                }
                samples.push(await measurePage(page, target, sample));
            }
            results.push(pageSummary(target, samples));
        }

        const report = {
            generated_at: new Date().toISOString(),
            base_url: BASE_URL,
            browser: 'Chromium via Playwright',
            sample_count: SAMPLE_COUNT,
            methodology: 'Three same-session navigations per page by default. p75 uses nearest-rank and includes local dependency performance; cached static assets may make later samples warmer than a first visit.',
            ranked_pages: results.sort((a, b) => b.priority_score - a.priority_score)
        };

        fs.mkdirSync(path.dirname(OUTPUT_PATH), { recursive: true });
        fs.writeFileSync(OUTPUT_PATH, `${JSON.stringify(report, null, 2)}\n`);
        await test.info().attach('performance-audit.json', {
            body: JSON.stringify(report, null, 2),
            contentType: 'application/json'
        });

        expect(report.ranked_pages).toHaveLength(PAGE_TARGETS.length);
    });
});
