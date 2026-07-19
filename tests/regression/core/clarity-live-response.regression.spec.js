const { test, expect } = require('@playwright/test');

function fixtureHtml() {
    return `<!doctype html>
<html><head><meta charset="utf-8"><meta name="csrf-token" content="fixture-token"></head>
<body>
<script>
window.organizationIntelligenceChatContext = {
    page_key: 'organization_intelligence', conversation_id: 22, room: 'brief', sub_room: '',
    timeframe: 'month', role: '', department: '', user_id: null
};
window.AIUiConsistency = {
    renderStatusPanel: function () { return '<div>stale helper</div>'; }
};
</script>
<div id="chat-bubble-container" data-current-page="hr_analytics.php" data-api-base="/crm">
    <button id="chat-bubble-btn" type="button" aria-expanded="false">Ask Clarity</button>
    <div id="chat-bubble-panel" aria-hidden="true">
        <div class="chat-bubble-header"><h3>Clarity</h3><button id="chat-bubble-close" type="button">Close</button></div>
        <div id="chat-bubble-messages"></div>
        <input id="chat-bubble-input" type="text">
        <button id="chat-bubble-send" type="button">Send</button>
    </div>
</div>
<script src="/crm/public/assets/js/chat-bubble.js?v=clarity-live-response-fixture"></script>
</body></html>`;
}

async function mountFixture(page, historyResponder, answerResponder) {
    await page.route('**/__clarity_live_response_fixture.html', async (route) => {
        await route.fulfill({ contentType: 'text/html', body: fixtureHtml() });
    });
    await page.route('**/api/chat/organization_intelligence_conversation.php*', historyResponder);
    await page.route('**/api/chat/ask.php', answerResponder);
    await page.goto('/crm/public/__clarity_live_response_fixture.html');
    await page.locator('#chat-bubble-btn').click();
    await expect(page.locator('#chat-bubble-panel')).toHaveAttribute('aria-hidden', 'false');
}

test.describe('Clarity live response rendering', () => {
    test('renders a successful answer when a stale optional UI helper lacks the new method', async ({ page }) => {
        const pageErrors = [];
        page.on('pageerror', (error) => pageErrors.push(error.message));

        await mountFixture(
            page,
            async (route) => route.fulfill({
                contentType: 'application/json',
                body: JSON.stringify({ conversation_id: 22, messages: [] })
            }),
            async (route) => route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    answer: 'The live answer rendered without a refresh.',
                    conversation_id: 22,
                    message_id: 91,
                    ai_status: { state: 'ready', message: 'AI completed successfully.' },
                    diagnostics: { guidance_run_id: 4, message_hash: 'fixture-hash' },
                    metadata: { operator_controls_enabled: false }
                })
            })
        );

        await page.locator('#chat-bubble-input').fill('Why is the risk high?');
        await page.locator('#chat-bubble-send').click();

        await expect(page.locator('.chat-bubble-answer-text')).toContainText('The live answer rendered without a refresh.');
        await expect(page.locator('.ai-ui-status-panel')).toHaveCount(0);
        await expect(page.locator('.chat-bubble-feedback')).toHaveCount(0);
        await expect(page.getByText('Something went wrong. Please try again.')).toHaveCount(0);
        expect(pageErrors.filter((message) => message.includes('renderExecutionStatus'))).toEqual([]);
    });

    test('shows execution status and feedback only when the default workspace capability is true', async ({ page }) => {
        await mountFixture(
            page,
            async (route) => route.fulfill({
                contentType: 'application/json',
                body: JSON.stringify({ conversation_id: 22, messages: [] })
            }),
            async (route) => route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    answer: 'The direct answer.',
                    conversation_id: 22,
                    ai_status: { state: 'ready', message: 'AI completed successfully.' },
                    diagnostics: { guidance_run_id: 4, message_hash: 'fixture-hash' },
                    metadata: { operator_controls_enabled: true }
                })
            })
        );
        await page.evaluate(() => {
            window.AIUiConsistency.renderExecutionStatus = function () {
                return '<div class="ai-ui-status-panel">Ready</div>';
            };
        });

        await page.locator('#chat-bubble-input').fill('Why is the risk high?');
        await page.locator('#chat-bubble-send').click();

        await expect(page.locator('.ai-ui-status-panel')).toHaveText('Ready');
        await expect(page.getByRole('button', { name: 'Helpful' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Create task' })).toBeVisible();
    });

    test('recovers a persisted Organization Intelligence answer after transport failure', async ({ page }) => {
        let historyRequests = 0;
        await mountFixture(
            page,
            async (route) => {
                historyRequests += 1;
                const messages = historyRequests === 1 ? [] : [
                    { id: 100, role: 'user', text: 'What is due today?', room: 'brief', sub_room: '' },
                    { id: 101, role: 'assistant', text: 'Two leadership tasks are due today.', room: 'brief', sub_room: '' }
                ];
                await route.fulfill({
                    contentType: 'application/json',
                    body: JSON.stringify({ conversation_id: 22, messages })
                });
            },
            async (route) => route.abort('failed')
        );

        await expect.poll(() => historyRequests).toBe(1);
        await page.locator('#chat-bubble-input').fill('What is due today?');
        await page.locator('#chat-bubble-send').click();

        await expect(page.getByText('Two leadership tasks are due today.')).toBeVisible();
        await expect(page.getByText('Something went wrong. Please try again.')).toHaveCount(0);
        await expect(page.getByText('What is due today?')).toHaveCount(1);
    });
});
