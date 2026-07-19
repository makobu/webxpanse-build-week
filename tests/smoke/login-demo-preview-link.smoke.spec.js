const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    createPageHealthMonitor,
    gotoAndWait,
    stabilizeBackgroundRequests
} = require('./helpers');

test.describe('Smoke: Login demo preview link', () => {
    test('demo preview link is visible, enhanced, and mobile-safe', async ({ page }) => {
        await stabilizeBackgroundRequests(page);
        const health = createPageHealthMonitor(page);

        await gotoAndWait(page, 'login.php');

        const demoLink = page.locator('.demo-workspace-link');
        await expect(demoLink).toBeVisible();
        await expect(demoLink).toContainText('Preview the demo workspace');
        await expect(demoLink).toHaveAttribute('href', 'demo.php');

        const visualState = await demoLink.evaluate((element) => {
            const style = window.getComputedStyle(element);
            const arrow = element.querySelector('.demo-workspace-link-arrow');
            const arrowStyle = arrow ? window.getComputedStyle(arrow) : null;
            const hasReducedMotionRule = Array.from(document.styleSheets).some((sheet) => {
                try {
                    return Array.from(sheet.cssRules || []).some((rule) => {
                        if (!rule.media || !rule.conditionText.includes('prefers-reduced-motion')) {
                            return false;
                        }

                        return Array.from(rule.cssRules || []).some((nestedRule) => {
                            const selector = nestedRule.selectorText || '';
                            const animation = nestedRule.style
                                ? nestedRule.style.getPropertyValue('animation')
                                : '';

                            return selector.includes('.demo-workspace-link')
                                && selector.includes('.demo-workspace-link-arrow')
                                && animation.includes('none');
                        });
                    });
                } catch (error) {
                    return false;
                }
            });

            return {
                animationName: style.animationName,
                arrowAnimationName: arrowStyle ? arrowStyle.animationName : '',
                backgroundColor: style.backgroundColor,
                borderColor: style.borderColor,
                boxShadow: style.boxShadow,
                color: style.color,
                hasReducedMotionRule
            };
        });

        expect(visualState.animationName).toBe('demoLinkGlow');
        expect(visualState.arrowAnimationName).toBe('demoLinkArrowNudge');
        expect(visualState.backgroundColor).not.toBe('rgba(0, 0, 0, 0)');
        expect(visualState.borderColor).not.toBe('rgba(0, 0, 0, 0)');
        expect(visualState.boxShadow).not.toBe('none');
        expect(visualState.hasReducedMotionRule).toBe(true);

        await page.setViewportSize({ width: 390, height: 844 });
        await page.waitForTimeout(150);
        const horizontalOverflow = await page.evaluate(() => {
            return document.documentElement.scrollWidth - window.innerWidth;
        });
        expect(horizontalOverflow).toBeLessThanOrEqual(1);
        await expect(demoLink).toBeVisible();

        await assertPageHealthy(page, health);
    });
});
