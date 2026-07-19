const { test, expect } = require('@playwright/test');

function demoStep(overrides = {}, actionOverrides = {}) {
    const action = {
        key: 'simulate_reply_received',
        label: 'Add reply',
        completed_label: 'Reply added',
        required: true,
        prompt: 'What did the buyer ask?',
        working_label: 'Adding the reply...',
        choices: [
            { key: 'ask_price', label: 'Price' },
            { key: 'ask_next_step', label: 'Next step' },
            { key: 'ask_short_version', label: 'Short version' }
        ],
        ...actionOverrides
    };

    return {
        key: action.key,
        phase: 'inbox',
        phase_label: 'Inbox',
        page: 'inbox.php',
        route: 'inbox.php',
        target: '[data-guided-demo-target="inbox-founder-thread"]',
        fallback_target: 'main',
        title: 'Add a buyer reply',
        body: 'Pick what the buyer asked. I\'ll add a demo reply.',
        why: 'The reply shows what to do next.',
        action_label: 'Continue',
        progress: { index: 5, total: 14, is_final: false },
        demo_action: action,
        ...overrides
    };
}

function fixtureHtml(config) {
    const safeConfig = JSON.stringify(config).replace(/</g, '\\u003c');
    return `<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/crm/public/assets/css/guided-demo.css">
    <style>
        body {
            margin: 0;
            min-height: 860px;
            background: #eef2f7;
            color: #0f172a;
            font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }
        .fixture-nav {
            position: sticky;
            top: 0;
            height: 86px;
            margin: 20px 8vw 0;
            border-radius: 14px;
            background: #fff;
            box-shadow: 0 18px 42px rgba(15, 23, 42, 0.14);
        }
        main {
            padding: 88px 24px 160px;
        }
        .fixture-filters {
            height: 128px;
            margin-bottom: 96px;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 6px 22px rgba(15, 23, 42, 0.08);
        }
        [data-guided-demo-target="inbox-founder-thread"] {
            display: grid;
            place-items: center;
            min-height: 168px;
            border-radius: 12px;
            background: #fff;
            box-shadow: 0 0 0 1px #dbeafe inset;
        }
    </style>
    <script>
        window.__refreshInboxCalls = [];
        window.refreshInboxListAsync = function(options) {
            window.__refreshInboxCalls.push(options || {});
            return Promise.resolve(true);
        };
        window.GuidedFounderDemo = ${safeConfig};
    </script>
    <script defer src="/crm/public/assets/js/guided-demo.js"></script>
</head>
<body>
    <div class="fixture-nav" aria-hidden="true"></div>
    <main>
        <section class="fixture-filters" aria-hidden="true"></section>
        <section data-guided-demo-target="inbox-founder-thread">No communications found.</section>
    </main>
</body>
</html>`;
}

async function mountGuidedDemoFixture(page, { step, completedStep, responseDelay = 20 }) {
    const config = {
        state: {
            step,
            session: { metadata: {} }
        },
        csrfToken: 'csrf-guided-demo',
        actionUrl: '/crm/public/mock-guided-demo-action',
        advanceUrl: '/crm/public/mock-guided-demo-advance',
        endUrl: '/crm/public/mock-guided-demo-end',
        eventUrl: '/crm/public/mock-guided-demo-event'
    };

    await page.route('**/__guided_demo_fixture.html', async (route) => {
        await route.fulfill({
            contentType: 'text/html',
            body: fixtureHtml(config)
        });
    });
    await page.route('**/mock-guided-demo-action', async (route) => {
        await new Promise((resolve) => setTimeout(resolve, responseDelay));
        await route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({
                success: true,
                data: {
                    message: 'I added the buyer reply.',
                    state: {
                        step: completedStep,
                        session: { metadata: { simulated_reply_communication_id: 123 } }
                    },
                    result: {
                        selected_choice_key: 'ask_price',
                        communication_id: 123,
                        simulation_only: true,
                        reply_preview: 'SIMULATED ONLY: This is interesting. What would the starter version cost?',
                        result_title: 'Reply added',
                        result_body: 'Next, you can add the follow-up task.'
                    },
                    created_records: { communications: [123] }
                }
            })
        });
    });
    await page.route('**/mock-guided-demo-advance', async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) });
    });
    await page.route('**/mock-guided-demo-end', async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) });
    });
    await page.route('**/mock-guided-demo-event', async (route) => {
        await route.fulfill({ contentType: 'application/json', body: JSON.stringify({ success: true, data: {} }) });
    });

    await page.goto('/crm/public/__guided_demo_fixture.html');
    await expect(page.locator('.guided-demo-bubble')).toBeVisible();
}

async function expectBubbleInsideViewport(page) {
    const box = await page.locator('.guided-demo-bubble').boundingBox();
    const viewport = page.viewportSize();
    expect(box).not.toBeNull();
    expect(viewport).not.toBeNull();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.y).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(viewport.width + 1);
    expect(box.y + box.height).toBeLessThanOrEqual(viewport.height + 1);
}

async function expectElementVisibleInsideBubble(page, selector) {
    const isVisibleInside = await page.locator(selector).evaluate((element) => {
        const bubble = element.closest('.guided-demo-bubble');
        if (!bubble) return false;
        const bubbleRect = bubble.getBoundingClientRect();
        const elementRect = element.getBoundingClientRect();
        return elementRect.top >= bubbleRect.top
            && elementRect.bottom <= bubbleRect.bottom
            && elementRect.left >= bubbleRect.left
            && elementRect.right <= bubbleRect.right;
    });
    expect(isVisibleInside).toBe(true);
}

async function expectElementVisibleInsideScrollRegion(page, selector) {
    const isVisibleInside = await page.locator(selector).evaluate((element) => {
        const scrollRegion = element.closest('[data-guided-demo-scroll]');
        if (!scrollRegion) return false;
        const scrollRect = scrollRegion.getBoundingClientRect();
        const elementRect = element.getBoundingClientRect();
        return elementRect.top >= scrollRect.top - 1
            && elementRect.bottom <= scrollRect.bottom + 1
            && elementRect.left < scrollRect.right
            && elementRect.right > scrollRect.left;
    });
    expect(isVisibleInside).toBe(true);
}

test.describe('Regression: guided demo action feedback', () => {
    test('manual buyer reply action shows visible progress and keeps the bubble in view', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 440 });

        const initialStep = demoStep();
        const completedStep = demoStep({}, {
            completed: true,
            selected_choice_key: 'ask_price'
        });
        await mountGuidedDemoFixture(page, { step: initialStep, completedStep });

        await page.getByRole('button', { name: 'Price' }).click();
        await page.getByRole('button', { name: 'Add reply' }).click();

        const working = page.locator('[data-guided-demo-working]');
        await expect(working).toBeVisible();
        await expect(working).toContainText('Adding the reply...');
        await expect(page.locator('[data-guided-demo-action-panel]')).toHaveAttribute('aria-busy', 'true');
        await expect(page.getByRole('button', { name: /Adding the reply/i })).toBeVisible();

        await page.waitForTimeout(500);
        await expect(working).toBeVisible();
        await expectBubbleInsideViewport(page);

        const result = page.locator('[data-guided-demo-result]');
        await expect(result).toContainText('Reply added', { timeout: 2000 });
        await expect(result).toContainText('Next, you can add the follow-up task.');
        await expect(working).not.toBeVisible();
        await expect.poll(async () => page.evaluate(() => window.__refreshInboxCalls.length)).toBe(1);
        const refreshCall = await page.evaluate(() => window.__refreshInboxCalls[0]);
        expect(refreshCall).toMatchObject({
            page: 1,
            background: true,
            updateHistory: false,
            showLoading: false
        });
        await expectBubbleInsideViewport(page);
        await expectElementVisibleInsideBubble(page, '[data-guided-demo-result]');
    });

    test('auto-run choices show visible action progress without a separate action button', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 440 });

        const initialStep = demoStep(
            { key: 'create_follow_up_task', title: 'Add the next task' },
            {
                key: 'create_follow_up_task',
                auto_run_on_choice: true,
                label: 'Add task',
                completed_label: 'Task added',
                prompt: 'What should you do next?',
                working_label: 'Adding the task...',
                choices: [
                    { key: 'send_details', label: 'Send details' },
                    { key: 'book_call', label: 'Book call' },
                    { key: 'prepare_quote', label: 'Prepare quote' }
                ]
            }
        );
        const completedStep = demoStep(
            { key: 'create_follow_up_task', title: 'Add the next task' },
            {
                key: 'create_follow_up_task',
                auto_run_on_choice: true,
                completed: true,
                selected_choice_key: 'send_details',
                completed_label: 'Task added',
                prompt: 'What should you do next?',
                working_label: 'Adding the task...',
                choices: [
                    { key: 'send_details', label: 'Send details' },
                    { key: 'book_call', label: 'Book call' },
                    { key: 'prepare_quote', label: 'Prepare quote' }
                ]
            }
        );
        await mountGuidedDemoFixture(page, { step: initialStep, completedStep });

        await page.getByRole('button', { name: 'Send details' }).click();

        const working = page.locator('[data-guided-demo-working]');
        await expect(working).toBeVisible();
        await expect(working).toContainText('Adding the task...');
        await expect(page.locator('.guided-demo-choice.is-working')).toContainText('Send details');
        await expect(page.locator('[data-guided-demo-action-panel]')).toHaveAttribute('aria-busy', 'true');
        await page.waitForTimeout(500);
        await expect(working).toBeVisible();
        await expectBubbleInsideViewport(page);
    });

    test('compressed bubble scrolls content while footer actions stay reachable', async ({ page }) => {
        await page.setViewportSize({ width: 448, height: 360 });

        const compressedStep = demoStep(
            {
                body: 'Pick what the buyer asked. I\'ll add a demo reply. This longer text makes the bubble shorter on small screens so the text, choices, and note must scroll inside the bubble.',
                why: 'The reply shows what to do next. The main buttons should still stay easy to reach.'
            },
            {
                completed: true,
                selected_choice_key: 'ask_price'
            }
        );
        await mountGuidedDemoFixture(page, { step: compressedStep, completedStep: compressedStep });

        const bubble = page.locator('.guided-demo-bubble');
        const scrollRegion = page.locator('[data-guided-demo-scroll]');
        const continueButton = page.getByRole('button', { name: 'Continue' });

        await expect(scrollRegion).toBeVisible();
        await expect(continueButton).toBeVisible();
        await expect(continueButton).toBeEnabled();
        await expectBubbleInsideViewport(page);

        const metrics = await scrollRegion.evaluate((element) => ({
            clientHeight: element.clientHeight,
            scrollHeight: element.scrollHeight,
            scrollTop: element.scrollTop
        }));
        expect(metrics.scrollHeight).toBeGreaterThan(metrics.clientHeight + 8);

        await scrollRegion.hover();
        await page.mouse.wheel(0, 420);
        await expect.poll(async () => scrollRegion.evaluate((element) => element.scrollTop)).toBeGreaterThan(metrics.scrollTop);

        await page.locator('.guided-demo-lesson').evaluate((element) => {
            element.scrollIntoView({ block: 'start' });
        });
        await expectElementVisibleInsideScrollRegion(page, '.guided-demo-lesson span');
        await expect(continueButton).toBeVisible();
        await expect(continueButton).toBeEnabled();
        await expectElementVisibleInsideBubble(page, '[data-guided-demo-action="next"]');

        const outerOverflow = await bubble.evaluate((element) => window.getComputedStyle(element).overflowY);
        const innerOverflow = await scrollRegion.evaluate((element) => window.getComputedStyle(element).overflowY);
        expect(outerOverflow).toBe('hidden');
        expect(innerOverflow).toBe('auto');
    });

    test('mobile active action keeps choices and footer actions visible before scrolling', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 844 });

        const activeStep = demoStep(
            {
                body: 'Pick what the buyer asked. I\'ll add a demo reply and keep the next buttons visible on a phone.',
                why: 'This step should show the main buttons before you scroll.'
            }
        );
        const completedStep = demoStep({}, {
            completed: true,
            selected_choice_key: 'ask_price'
        });
        await mountGuidedDemoFixture(page, { step: activeStep, completedStep });

        const actionButton = page.getByRole('button', { name: 'Add reply' });
        await expect(page.getByRole('button', { name: 'Price' })).toBeVisible();
        await expect(actionButton).toBeVisible();
        await expect(actionButton).toBeDisabled();
        await expect(page.getByRole('button', { name: 'Exit' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Back' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Continue' })).toBeVisible();
        await expectBubbleInsideViewport(page);
        await expectElementVisibleInsideBubble(page, '[data-guided-demo-action="run-step-action"]');
        await expectElementVisibleInsideBubble(page, '[data-guided-demo-action="exit"]');
        await expectElementVisibleInsideBubble(page, '[data-guided-demo-action="back"]');
        await expectElementVisibleInsideBubble(page, '[data-guided-demo-action="next"]');

        const metrics = await page.locator('.guided-demo-bubble').evaluate((element) => {
            const actions = element.querySelector('.guided-demo-actions');
            const bubbleRect = element.getBoundingClientRect();
            const actionsRect = actions ? actions.getBoundingClientRect() : null;
            return {
                bubbleBottom: bubbleRect.bottom,
                actionsBottom: actionsRect ? actionsRect.bottom : 0,
                viewportHeight: window.innerHeight
            };
        });
        expect(metrics.bubbleBottom).toBeLessThanOrEqual(metrics.viewportHeight + 1);
        expect(metrics.actionsBottom).toBeLessThanOrEqual(metrics.viewportHeight + 1);
    });
});
