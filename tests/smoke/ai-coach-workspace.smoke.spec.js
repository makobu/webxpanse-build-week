const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const {
    gotoAndWait,
    loginAsAdmin,
    loginAsUser,
    runFixture
} = require('./helpers');

const SCREENSHOT_DIR = path.resolve(__dirname, '..', '..', 'tmp', 'ai-coach-audit');

function coachRecommendationsPayload() {
    const recommendation = (title, reason, impact = 'High', effort = 'Low') => ({
        title,
        reason,
        impact,
        effort,
        suggested_subtasks: ['Review the workspace signal', 'Complete the next action'],
        evidence: [{ label: 'Workspace', value: 'Live CRM activity' }],
        source_context: { contact_count: 8, task_count: 3 },
        trust_signals: [{ label: 'Context', value: 'Current', tone: 'success' }],
        why_signals: [{ label: 'Priority', value: impact, tone: 'success' }],
        source_recommendation_type: 'browser_qa'
    });

    return {
        recommendations_ready: true,
        ai_coach_readiness: {
            company_context_ready: true,
            clarity_journey_ready: true,
            inherited_context_ready: true,
            strategy_ready: true,
            idea_validation_ready: false,
            recommendations_ready: true,
            missing_requirements: [{ message: 'Add pricing evidence.' }],
            marketplace_url: 'workspace_skills.php?module=ai_coach&setup_tab=workspace_readiness#setup',
            onboarding_payload: {
                products: [{ name: 'Clarity CRM' }]
            }
        },
        recommendations: {
            priorities: [
                recommendation(
                    'Add pricing to your primary offer',
                    'Coach found an offer without pricing, which is limiting revenue guidance and proposal readiness.'
                ),
                recommendation('Follow up with 3 warm contacts', 'No activity has been recorded in 5 days.')
            ],
            quick_wins: [
                recommendation('Create your first revenue target', 'Give Coach a measurable outcome to work toward.', 'Medium', 'Low')
            ],
            foundation_gaps: [],
            missing_features: [],
            why_this_matters: 'The top moves connect current workspace evidence to practical follow-through.'
        },
        generation_status: {
            source: 'deterministic_fallback',
            fallback: true,
            provider_message: 'Browser QA fixture'
        },
        diagnostics: {
            mode: 'normal',
            context_quality_score: 72,
            missing_context_flags: ['pricing evidence'],
            using_goals: []
        },
        assumption_conflicts: [],
        financial_evidence: {}
    };
}

test.describe('AI Coach daily operating workspace', () => {
    let originalCoachInstallState;
    let ownerCoachInstallState;

    test.beforeEach(() => {
        ownerCoachInstallState = null;
        originalCoachInstallState = runFixture('get_workspace_skill_install_state', {
            workspace_id: 1,
            skill_key: 'ai_coach'
        });
    });

    test.afterEach(() => {
        if (ownerCoachInstallState) {
            runFixture('restore_workspace_skill_install_state', {
                workspace_id: ownerCoachInstallState.workspaceId,
                skill_key: 'ai_coach',
                state: ownerCoachInstallState.state
            });
        }
        runFixture('restore_workspace_skill_install_state', {
            workspace_id: 1,
            skill_key: 'ai_coach',
            state: originalCoachInstallState
        });
    });

    test('does not render or request Coach when the plugin is not installed', async ({ page }) => {
        test.setTimeout(90000);
        runFixture('uninstall_workspace_skill', {
            workspace_id: 1,
            skill_key: 'ai_coach'
        });

        let coachAccessRequests = 0;
        page.on('request', (request) => {
            if (request.url().includes('/api/dashboard/ai_coach_access.php')) {
                coachAccessRequests += 1;
            }
        });

        await loginAsAdmin(page);
        await page.waitForTimeout(1000);

        await expect(page.locator('#ai-coach-open, [data-founder-command-coach]')).toHaveCount(0);
        await expect(page.locator('link[href*="ai-coach.css"]')).toHaveCount(0);
        expect(coachAccessRequests).toBe(0);
    });

    test('keeps Coach optional inside the command center and stays healthy on mobile', async ({ page }) => {
        test.setTimeout(90000);
        fs.mkdirSync(SCREENSHOT_DIR, { recursive: true });
        await page.setViewportSize({ width: 1536, height: 1024 });
        const owner = runFixture('ensure_workspace_owner', {
            email: 'pw-ai-coach-founder@example.test',
            password: 'password',
            workspace_name: 'AI Coach Founder QA'
        });
        runFixture('complete_workspace_onboarding', { workspace_id: owner.workspace_id });
        ownerCoachInstallState = {
            workspaceId: owner.workspace_id,
            state: runFixture('get_workspace_skill_install_state', {
                workspace_id: owner.workspace_id,
                skill_key: 'ai_coach'
            })
        };
        runFixture('install_workspace_skill', {
            workspace_id: owner.workspace_id,
            user_id: owner.user_id,
            skill_key: 'ai_coach',
            config: { ai_coach_enabled: true, ai_coach: { enabled: true } }
        });
        await loginAsUser(page, owner.email, 'password');
        await page.route('**/api/ai-coach/recommendations.php*', async (route) => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify(coachRecommendationsPayload())
            });
        });

        const commandCenter = page.locator('[data-founder-command-center]');
        const coachButton = page.locator('[data-founder-command-coach]');
        const cookieBanner = page.locator('#cookie-consent-banner');
        if (await cookieBanner.isVisible().catch(() => false)) {
            await page.locator('#cookie-reject').click();
        }
        await expect(commandCenter).toBeVisible({ timeout: 30000 });
        await expect(coachButton).toBeVisible({ timeout: 30000 });
        await expect(commandCenter.getByRole('heading', { name: 'Needs you' })).toBeVisible();
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'command-center-desktop.png') });

        await page.locator('#ai-coach-open').click();
        const modal = page.locator('.ai-coach-modal');
        await expect(modal).toBeVisible({ timeout: 30000 });
        const modalBox = await modal.boundingBox();
        expect(modalBox).not.toBeNull();
        expect(modalBox.width).toBeGreaterThan(950);
        await expect(modal.getByRole('tab', { name: 'Daily Focus' })).toHaveAttribute('aria-selected', 'true');
        await expect(modal.getByText('Add pricing to your primary offer', { exact: true })).toBeVisible();
        await expect(modal.locator('.ai-coach-card-queue')).toHaveCount(2);
        await page.waitForTimeout(350);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'workspace-desktop.png') });

        const firstQueueToggle = modal.locator('.ai-coach-card-queue .ai-coach-card-toggle').first();
        await firstQueueToggle.click();
        await expect(firstQueueToggle).toHaveAttribute('aria-expanded', 'true');
        await expect(modal.locator('.ai-coach-card-queue .ai-coach-card-detail').first()).toBeVisible();

        await modal.locator('.ai-coach-plan-day').click();
        await expect(modal.getByRole('heading', { name: "Build today's plan" })).toBeVisible();
        await expect(modal.locator('.ai-coach-plan-day-item')).toHaveCount(3);
        await modal.getByRole('button', { name: 'Close daily plan' }).click();

        await modal.getByRole('tab', { name: 'Idea Lab' }).click();
        await expect(modal.locator('.ai-coach-idea-validation-form')).toBeVisible({ timeout: 30000 });
        await expect(modal.getByRole('button', { name: 'Save idea context' })).toBeVisible();
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'idea-lab-desktop.png') });

        await modal.getByRole('button', { name: 'Close AI Coach' }).click();
        await page.setViewportSize({ width: 390, height: 844 });
        await gotoAndWait(page, 'dashboard.php');
        await expect(commandCenter).toBeVisible({ timeout: 30000 });
        await expect(coachButton).toBeVisible({ timeout: 30000 });
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'command-center-mobile.png') });

        await page.locator('#ai-coach-open').click();
        await expect(modal).toBeVisible({ timeout: 30000 });
        await expect(modal.getByRole('tab', { name: 'Daily Focus' })).toBeVisible();
        await page.waitForTimeout(350);
        const mobileOverflow = await modal.evaluate((element) => ({
            clientWidth: element.clientWidth,
            scrollWidth: element.scrollWidth
        }));
        expect(mobileOverflow.scrollWidth).toBeLessThanOrEqual(mobileOverflow.clientWidth + 1);
        await page.screenshot({ path: path.join(SCREENSHOT_DIR, 'workspace-mobile.png') });
    });
});
