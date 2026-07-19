const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const {
    BASE_URL,
    TEST_EMAIL,
    TEST_PASSWORD,
    runFixture,
    uniqueSuffix
} = require('../smoke/helpers');

const SAMPLE_COUNT = Math.max(1, Number(process.env.CRM_PERF_SAMPLES || 3));
const OUTPUT_PATH = process.env.CRM_PERF_AUDIT_OUTPUT
    || path.resolve(__dirname, '..', '..', 'tmp', 'customer-service-performance-audit.json');
const MARKDOWN_OUTPUT_PATH = process.env.CRM_PERF_AUDIT_MARKDOWN_OUTPUT
    || OUTPUT_PATH.replace(/\.json$/i, '.md');

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

function withAuditParams(pagePath, sample) {
    const separator = pagePath.includes('?') ? '&' : '?';
    return `${BASE_URL}/${pagePath}${separator}perf_audit=${sample}&perf_debug=1`;
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

async function ensureLoggedIn(page) {
    if (await page.locator('.navbar').count()) {
        return;
    }

    await page.goto(`${BASE_URL}/login.php`, { waitUntil: 'domcontentloaded', timeout: 60000 });
    if (!(await page.locator('input[name="email"]').count())) {
        return;
    }

    await page.locator('input[name="email"]').fill(TEST_EMAIL);
    await page.locator('input[name="password"]').fill(TEST_PASSWORD);
    await Promise.all([
        page.waitForURL(/dashboard\.php|index\.php/i, { timeout: 60000 }),
        page.locator('button[type="submit"]').click()
    ]);
}

async function measurePage(page, target, sample) {
    await ensureLoggedIn(page);
    await page.evaluate(() => {
        window.__crmPerformanceLongTasks = [];
    }).catch(() => {});

    const documentResponse = await page.goto(withAuditParams(target.path, sample), {
        waitUntil: 'load',
        timeout: 60000
    });
    await page.waitForTimeout(target.settle_ms || 1500);

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
        audit_focus: target.audit_focus,
        recommendation_tags: target.recommendation_tags,
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

function buildTargets(fixture) {
    const namespace = encodeURIComponent(fixture.namespace);
    const contactId = Number(fixture.contact_id || 0);
    const communicationId = Number(fixture.communication_id || 0);

    return [
        {
            name: 'Inbox filtered to seeded conversation',
            path: `inbox.php?search=${namespace}`,
            impact: 5,
            audit_focus: 'Primary customer-service queue with synchronous list, totals, channel stats, contact filter options, guidance, and background sync timers.',
            recommendation_tags: ['server/query work', 'deferred/background work', 'initial-render payload']
        },
        {
            name: 'Conversation detail',
            path: `conversation.php?id=${communicationId}`,
            impact: 5,
            audit_focus: 'Customer thread detail with reply tooling, contact context, conversation intelligence, and channel-specific render paths.',
            recommendation_tags: ['server/query work', 'client JS/long tasks', 'initial-render payload']
        },
        {
            name: 'Contact service view',
            path: `contact_view.php?id=${contactId}`,
            impact: 5,
            audit_focus: 'Large contact detail surface with intelligence, timeline, documents, notes, marketing/nurture panels, and tabbed secondary data.',
            recommendation_tags: ['server/query work', 'initial-render payload', 'deferred/background work']
        },
        {
            name: 'Email compose for customer',
            path: `email_compose.php?contact_id=${contactId}`,
            impact: 4,
            audit_focus: 'Service reply composition surface with templates, signatures, rich editor, and assistant hooks.',
            recommendation_tags: ['client JS/long tasks', 'assets/CSS/media', 'deferred/background work']
        },
        {
            name: 'Email list',
            path: 'emails.php',
            impact: 4,
            audit_focus: 'Email operations list for sent/customer messages and related status metadata.',
            recommendation_tags: ['server/query work', 'initial-render payload']
        },
        {
            name: 'WhatsApp compose for customer',
            path: `whatsapp_compose.php?contact_id=${contactId}`,
            impact: 4,
            audit_focus: 'WhatsApp customer reply path with templates, media controls, setup checks, and session-window guidance.',
            recommendation_tags: ['client JS/long tasks', 'assets/CSS/media', 'deferred/background work']
        },
        {
            name: 'WhatsApp messages',
            path: 'whatsapp_messages.php',
            impact: 4,
            audit_focus: 'WhatsApp operational log and queue visibility for customer messaging.',
            recommendation_tags: ['server/query work', 'initial-render payload']
        },
        {
            name: 'Customers',
            path: 'customers.php',
            impact: 4,
            audit_focus: 'Customer account/list view used as a service entry point.',
            recommendation_tags: ['server/query work', 'initial-render payload']
        },
        {
            name: 'Nurture',
            path: 'nurture.php',
            impact: 3,
            audit_focus: 'Customer success/nurture queue with program and follow-up context.',
            recommendation_tags: ['server/query work', 'assets/CSS/media', 'initial-render payload']
        }
    ];
}

function formatMs(value) {
    return value === null || value === undefined ? 'n/a' : `${value}ms`;
}

function formatBytes(value) {
    if (!Number.isFinite(value)) return 'n/a';
    if (value >= 1000000) return `${(value / 1000000).toFixed(2)} MB`;
    return `${Math.round(value / 1000)} KB`;
}

function topServerTiming(page) {
    return page.samples
        .flatMap((sample) => sample.document_server_timing || [])
        .sort((a, b) => b.duration_ms - a.duration_ms)
        .slice(0, 3);
}

function recommendationForTag(tag, page) {
    const slowServerTiming = topServerTiming(page);
    const slowResource = page.slow_resources[0];

    if (tag === 'server/query work') {
        const timing = slowServerTiming[0];
        return timing && timing.duration_ms > 0
            ? `Review ${page.page} server timing around \`${timing.name}\` (${formatMs(round(timing.duration_ms))}) and split slow list/count/detail queries behind async endpoints where possible.`
            : `Profile ${page.page} PHP queries under \`perf_debug=1\`; prioritize synchronous list/count/detail queries that block first render.`;
    }
    if (tag === 'initial-render payload') {
        return `Reduce ${page.page} first HTML payload by moving secondary panels, large option lists, and non-critical cards behind lazy async hydration.`;
    }
    if (tag === 'deferred/background work') {
        return `Delay ${page.page} background refresh, sync, guidance, and health checks until after load/idle, with visibility guards to avoid competing with first paint.`;
    }
    if (tag === 'client JS/long tasks') {
        return page.p75.long_task_ms && page.p75.long_task_ms > 0
            ? `Break up ${page.page} client work (${formatMs(page.p75.long_task_ms)} p75 long-task time) and initialize editors/assistant widgets only when visible.`
            : `Audit ${page.page} inline scripts and initialize rich widgets only after the main form/list is interactive.`;
    }
    if (tag === 'assets/CSS/media') {
        return slowResource
            ? `Inspect ${page.page} slow asset \`${slowResource.name}\` (${formatMs(slowResource.duration_ms)}); defer non-critical CSS/media and keep video/guide assets metadata-only.`
            : `Keep ${page.page} guide media, rich-editor assets, and optional styles deferred until interaction.`;
    }
    return `Review ${page.page} for low-risk deferral opportunities.`;
}

function buildMarkdownReport(report) {
    const lines = [
        '# Customer Service Performance Audit',
        '',
        `Generated: ${report.generated_at}`,
        `Base URL: ${report.base_url}`,
        `Samples per page: ${report.sample_count}`,
        '',
        '## Ranked Pages',
        '',
        '| Rank | Page | Path | Score | p75 Nav | p75 TTFB | FCP | Load | Requests | Transfer | Long Tasks |',
        '| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |'
    ];

    report.ranked_pages.forEach((page, index) => {
        lines.push([
            `| ${index + 1}`,
            page.page,
            `\`${page.path}\``,
            page.priority_score,
            formatMs(page.p75.navigation_ms),
            formatMs(page.p75.ttfb_ms),
            formatMs(page.p75.first_contentful_paint_ms),
            formatMs(page.p75.load_ms),
            page.p75.resource_count ?? 'n/a',
            formatBytes(page.p75.transfer_bytes),
            formatMs(page.p75.long_task_ms),
            '|'
        ].join(' | '));
    });

    lines.push('', '## Bottlenecks By Page', '');
    report.ranked_pages.forEach((page) => {
        lines.push(`### ${page.page}`, '');
        lines.push(`- Focus: ${page.audit_focus}`);
        lines.push(`- Slow server timings: ${topServerTiming(page).map((metric) => `${metric.name} ${formatMs(round(metric.duration_ms))}`).join(', ') || 'none reported'}`);
        lines.push(`- Slow resources: ${page.slow_resources.map((resource) => `${resource.type} ${formatMs(resource.duration_ms)} ${resource.name}`).join('; ') || 'none reported'}`);
        lines.push('');
    });

    const grouped = new Map();
    report.ranked_pages.slice(0, 5).forEach((page) => {
        page.recommendation_tags.forEach((tag) => {
            if (!grouped.has(tag)) grouped.set(tag, []);
            grouped.get(tag).push(recommendationForTag(tag, page));
        });
    });

    lines.push('## Optimization Recommendations', '');
    ['server/query work', 'initial-render payload', 'deferred/background work', 'client JS/long tasks', 'assets/CSS/media'].forEach((tag) => {
        lines.push(`### ${tag}`);
        const items = grouped.get(tag) || [`No high-priority ${tag} recommendation was triggered by the top ranked pages.`];
        [...new Set(items)].slice(0, 5).forEach((item) => lines.push(`- ${item}`));
        lines.push('');
    });

    lines.push('## Notes', '');
    lines.push('- This audit ranks pages only; it does not implement speed fixes.');
    lines.push('- Same-session samples include local dependency performance and may be warmer after the first navigation.');
    lines.push('- JSON contains raw samples and slow resource details for follow-up profiling.');
    lines.push('');

    return `${lines.join('\n')}\n`;
}

test.describe('Performance: customer-service workflow pages', () => {
    test.skip(!process.env.CRM_PERF_AUDIT, 'Set CRM_PERF_AUDIT=1 to run the repeatable customer-service performance audit.');

    test('collects a ranked customer-service baseline', async ({ page }) => {
        const namespace = uniqueSuffix('perf-service');
        test.setTimeout(Math.max(180000, (9 * SAMPLE_COUNT * 45000) + 90000));

        runFixture('ensure_user_role', { email: TEST_EMAIL, role_slug: 'admin' });
        runFixture('ensure_user', {
            email: TEST_EMAIL,
            password: TEST_PASSWORD,
            profile_role: 'admin',
            first_name: 'Performance',
            last_name: 'Service'
        });

        const fixture = {
            namespace,
            ...runFixture('seed_email_conversation', { namespace, email: TEST_EMAIL })
        };
        const targets = buildTargets(fixture);

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

        try {
            const results = [];
            for (const target of targets) {
                const samples = [];
                for (let sample = 1; sample <= SAMPLE_COUNT; sample += 1) {
                    samples.push(await measurePage(page, target, sample));
                }
                results.push(pageSummary(target, samples));
            }

            const report = {
                generated_at: new Date().toISOString(),
                base_url: BASE_URL,
                browser: 'Chromium via Playwright',
                sample_count: SAMPLE_COUNT,
                fixture: {
                    namespace,
                    contact_id: fixture.contact_id,
                    communication_id: fixture.communication_id
                },
                methodology: 'Three same-session navigations per page by default. p75 uses nearest-rank and includes local dependency performance; cached static assets may make later samples warmer than a first visit.',
                ranked_pages: results.sort((a, b) => b.priority_score - a.priority_score)
            };

            fs.mkdirSync(path.dirname(OUTPUT_PATH), { recursive: true });
            fs.writeFileSync(OUTPUT_PATH, `${JSON.stringify(report, null, 2)}\n`);
            fs.writeFileSync(MARKDOWN_OUTPUT_PATH, buildMarkdownReport(report));

            await test.info().attach('customer-service-performance-audit.json', {
                body: JSON.stringify(report, null, 2),
                contentType: 'application/json'
            });
            await test.info().attach('customer-service-performance-audit.md', {
                body: buildMarkdownReport(report),
                contentType: 'text/markdown'
            });

            expect(report.ranked_pages).toHaveLength(targets.length);
        } finally {
            runFixture('cleanup_namespace', { namespace });
        }
    });
});
