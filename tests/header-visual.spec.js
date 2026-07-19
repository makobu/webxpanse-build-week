/**
 * Header Visual Test Suite
 * Tests header aesthetics, spacing, and dropdown functionality
 */

const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');

const BASE_URL = 'http://localhost/crm/public';
const TEST_EMAIL = 'test@crm.local';
const TEST_PASSWORD = 'test123456';
const PHP_EXE = process.env.CRM_TEST_PHP || 'php';
const FIXTURE_SCRIPT = path.resolve(__dirname, 'browser_fixture.php');
const RATE_LIMIT_DIR = path.resolve(__dirname, '..', 'cache', 'rate_limit');

function runFixture(action, payload = {}) {
    const input = JSON.stringify({ action, ...payload });
    const raw = execFileSync(PHP_EXE, [FIXTURE_SCRIPT], {
        cwd: path.resolve(__dirname, '..'),
        input,
        encoding: 'utf8'
    });
    const parsed = JSON.parse(raw);
    if (!parsed.success) {
        throw new Error(parsed.error || `Fixture action failed: ${action}`);
    }
    return parsed.result || {};
}

function clearLoginRateLimit() {
    for (const ip of ['127.0.0.1', '::1']) {
        const file = path.join(RATE_LIMIT_DIR, `${crypto.createHash('md5').update(`login_${ip}`).digest('hex')}.json`);
        fs.rmSync(file, { force: true });
    }
}

test.describe('Header Visual Tests', () => {
    test.beforeEach(async ({ page }) => {
        clearLoginRateLimit();
        runFixture('ensure_user_role', { email: TEST_EMAIL, role_slug: 'admin' });
        runFixture('ensure_user', {
            email: TEST_EMAIL,
            password: TEST_PASSWORD,
            profile_role: 'admin',
            first_name: 'Header',
            last_name: 'Tester'
        });

        // Navigate to login page
        await page.goto(`${BASE_URL}/login.php`);
        
        // Wait for page to load
        await page.waitForLoadState('networkidle');
        
        // Login
        await page.fill('input[name="email"]', TEST_EMAIL);
        await page.fill('input[name="password"]', TEST_PASSWORD);
        
        // Click submit and wait for navigation
        await Promise.all([
            page.waitForURL('**/dashboard.php', { waitUntil: 'domcontentloaded' }),
            page.click('button[type="submit"]')
        ]);
        
        // Ensure we're on dashboard and header is visible
        await page.waitForSelector('.navbar', { timeout: 10000 });
    });

    test('should display header with proper spacing', async ({ page }) => {
        // Take full page screenshot
        await page.screenshot({ 
            path: 'tests/screenshots/header-full.png',
            fullPage: false 
        });
        
        // Get header element
        const header = page.locator('.navbar');
        await expect(header).toBeVisible();
        
        // Check header height
        const headerBox = await header.boundingBox();
        console.log(`Header height: ${headerBox.height}px`);
        
        // Verify header stays within the current single-row desktop design.
        expect(headerBox.height).toBeLessThanOrEqual(130);
    });

    test('should have search control properly separated', async ({ page }) => {
        const searchToggle = page.locator('.search-toggle-neu');
        const searchSeparator = page.locator('.main-nav-container .main-nav-separator').first();
        await expect(searchToggle).toBeVisible();
        await expect(searchSeparator).toBeVisible();

        // Check search control keeps its icon button sizing and separator.
        const searchToggleStyles = await searchToggle.evaluate((el) => {
            const styles = window.getComputedStyle(el);
            return {
                minWidth: styles.minWidth,
                height: styles.height,
                paddingLeft: styles.paddingLeft,
                paddingRight: styles.paddingRight
            };
        });
        const separatorStyles = await searchSeparator.evaluate((el) => {
            const styles = window.getComputedStyle(el);
            return {
                width: styles.width,
                height: styles.height,
                backgroundColor: styles.backgroundColor
            };
        });

        console.log('Search control styles:', searchToggleStyles);
        console.log('Search separator styles:', separatorStyles);
        expect(parseFloat(searchToggleStyles.minWidth)).toBeGreaterThanOrEqual(36);
        expect(parseFloat(searchToggleStyles.height)).toBeGreaterThanOrEqual(36);
        expect(parseFloat(separatorStyles.width)).toBeGreaterThan(0);
        expect(parseFloat(separatorStyles.height)).toBeGreaterThan(16);
        
        // Take screenshot of search area
        await searchToggle.screenshot({
            path: 'tests/screenshots/search-control.png'
        });
    });

    test('should display dropdown menus with consistent styling', async ({ page }) => {
        // Test Sales dropdown
        const salesDropdown = page.locator('#sales-dropdown');
        await salesDropdown.locator('.nav-dropdown-toggle').click();
        
        await page.waitForTimeout(300); // Wait for animation
        
        // Take screenshot of Sales dropdown
        await salesDropdown.screenshot({ 
            path: 'tests/screenshots/dropdown-sales.png' 
        });
        
        // Check dropdown menu styling
        const dropdownMenu = salesDropdown.locator('.nav-dropdown-menu');
        await expect(dropdownMenu).toBeVisible();
        
        const menuStyles = await dropdownMenu.evaluate((el) => {
            const styles = window.getComputedStyle(el);
            return {
                paddingTop: styles.paddingTop,
                paddingBottom: styles.paddingBottom,
                borderRadius: styles.borderRadius,
                boxShadow: styles.boxShadow
            };
        });
        
        console.log('Sales dropdown styles:', menuStyles);
        
        // Check dropdown items
        const items = dropdownMenu.locator('.nav-dropdown-item');
        const itemCount = await items.count();
        expect(itemCount).toBeGreaterThan(0);
        
        // Check first item styling
        const firstItem = items.first();
        const itemStyles = await firstItem.evaluate((el) => {
            const styles = window.getComputedStyle(el);
            return {
                fontSize: styles.fontSize,
                paddingTop: styles.paddingTop,
                paddingBottom: styles.paddingBottom,
                paddingLeft: styles.paddingLeft,
                paddingRight: styles.paddingRight
            };
        });
        
        console.log('Dropdown item styles:', itemStyles);
        
        // Verify consistent font size.
        expect(itemStyles.fontSize).toBe('14px');
    });

    test('should test all dropdown menus', async ({ page }) => {
        const dropdowns = [
            { id: 'sales-dropdown', name: 'Sales' },
            { id: 'communication-dropdown', name: 'Communication' },
            { id: 'analytics-dropdown', name: 'Analytics' },
            { id: 'more-dropdown', name: 'More' },
            { id: 'admin-dropdown', name: 'Admin' }
        ];
        
        for (const dropdown of dropdowns) {
            const dropdownElement = page.locator(`#${dropdown.id}`);
            
            // Check if dropdown exists (admin might not be visible)
            const isVisible = await dropdownElement.isVisible().catch(() => false);
            
            if (isVisible) {
                // Open dropdown
                await dropdownElement.locator('.nav-dropdown-toggle').click();
                await page.waitForTimeout(300);
                
                // Take screenshot
                await dropdownElement.screenshot({ 
                    path: `tests/screenshots/dropdown-${dropdown.name.toLowerCase()}.png` 
                });
                
                // Check menu items have consistent styling
                const menu = dropdownElement.locator('.nav-dropdown-menu');
                const items = menu.locator('.nav-dropdown-item');
                const itemCount = await items.count();
                
                console.log(`${dropdown.name} dropdown: ${itemCount} items`);
                
                // Check font sizes of all items
                for (let i = 0; i < itemCount; i++) {
                    const item = items.nth(i);
                    const fontSize = await item.evaluate((el) => {
                        return window.getComputedStyle(el).fontSize;
                    });
                    
                    // All items should use the current dropdown font size.
                    expect(fontSize).toBe('14px');
                }
                
                // Close dropdown
                await dropdownElement.locator('.nav-dropdown-toggle').click();
                await page.waitForTimeout(300);
            }
        }
    });

    test('should have proper hover effects on dropdown items', async ({ page }) => {
        const salesDropdown = page.locator('#sales-dropdown');
        await salesDropdown.locator('.nav-dropdown-toggle').click();
        await page.waitForTimeout(300);
        
        const firstItem = salesDropdown.locator('.nav-dropdown-item').first();
        
        // Get initial styles
        const initialStyles = await firstItem.evaluate((el) => {
            const styles = window.getComputedStyle(el);
            return {
                backgroundColor: styles.backgroundColor,
                color: styles.color
            };
        });
        
        // Hover over item
        await firstItem.hover();
        await page.waitForTimeout(200);
        
        // Get hover styles
        const hoverStyles = await firstItem.evaluate((el) => {
            const styles = window.getComputedStyle(el);
            return {
                backgroundColor: styles.backgroundColor,
                color: styles.color
            };
        });
        
        console.log('Initial styles:', initialStyles);
        console.log('Hover styles:', hoverStyles);
        
        // Verify hover effect changes color
        expect(hoverStyles.color).not.toBe(initialStyles.color);
        
        // Take screenshot of hover state
        await firstItem.screenshot({ 
            path: 'tests/screenshots/dropdown-item-hover.png' 
        });
    });

    test('should have responsive mobile menu', async ({ page }) => {
        // Set mobile viewport
        await page.setViewportSize({ width: 375, height: 667 });
        
        // Check mobile menu toggle is visible
        const mobileToggle = page.locator('#mobile-menu-toggle');
        await expect(mobileToggle).toBeVisible();
        
        // Open mobile menu
        await mobileToggle.click();
        await page.waitForTimeout(300);
        
        // Take screenshot
        await page.screenshot({ 
            path: 'tests/screenshots/mobile-menu.png' 
        });
        
        // Check mobile menu is visible
        const mobileMenu = page.locator('#mobile-menu');
        await expect(mobileMenu).toBeVisible();
    });

    test('should render affordability payment button as polished control', async ({ page }) => {
        await page.setViewportSize({ width: 1920, height: 720 });

        const supportButton = page.locator('.workspace-affordability-nav-button');
        await expect(supportButton).toBeVisible();

        const buttonStyles = await supportButton.evaluate((el) => {
            const styles = window.getComputedStyle(el);
            return {
                backgroundImage: styles.backgroundImage,
                borderStyle: styles.borderStyle,
                borderWidth: styles.borderWidth,
                boxShadow: styles.boxShadow,
                borderRadius: styles.borderRadius
            };
        });

        expect(buttonStyles.backgroundImage).not.toBe('none');
        expect(buttonStyles.borderStyle).toBe('solid');
        expect(parseFloat(buttonStyles.borderWidth)).toBeGreaterThan(0);
        expect(buttonStyles.boxShadow).not.toBe('none');
        expect(parseFloat(buttonStyles.borderRadius)).toBeGreaterThanOrEqual(12);
    });

    test('should measure header spacing and layout', async ({ page }) => {
        await page.setViewportSize({ width: 1920, height: 720 });

        const header = page.locator('.navbar');
        const shell = header.locator('.navbar-shell');
        const navStrip = header.locator('.desktop-nav');
        const brand = header.locator('.navbar-brand');
        const mainNav = header.locator('.main-nav-container');
        const secondaryNav = header.locator('.expandable-tabs-container');
        const paymentButton = header.locator('.workspace-affordability-nav-button');

        await expect(shell).toBeVisible();
        await expect(brand).toBeVisible();
        await expect(navStrip).toBeVisible();
        await expect(mainNav).toBeVisible();
        await expect(secondaryNav).toBeVisible();

        const paymentButtonCount = await paymentButton.count();
        const [shellBox, brandBox, navStripBox, mainNavBox, secondaryNavBox] = await Promise.all([
            shell.boundingBox(),
            brand.boundingBox(),
            navStrip.boundingBox(),
            mainNav.boundingBox(),
            secondaryNav.boundingBox()
        ]);
        const paymentButtonBox = paymentButtonCount > 0 ? await paymentButton.boundingBox() : null;

        expect(shellBox).not.toBeNull();
        expect(brandBox).not.toBeNull();
        expect(navStripBox).not.toBeNull();
        expect(mainNavBox).not.toBeNull();
        expect(secondaryNavBox).not.toBeNull();
        expect(brandBox.x).toBeGreaterThanOrEqual(14);
        expect(brandBox.x).toBeLessThanOrEqual(20);
        expect(secondaryNavBox.x).toBeGreaterThan(mainNavBox.x + mainNavBox.width);
        expect(secondaryNavBox.x - (mainNavBox.x + mainNavBox.width)).toBeGreaterThanOrEqual(8);
        if (paymentButtonBox) {
            expect(paymentButtonBox.x).toBeGreaterThan(secondaryNavBox.x + secondaryNavBox.width);
        }

        const brandRight = brandBox.x + brandBox.width;
        const navStripRight = navStripBox.x + navStripBox.width;
        const shellRight = shellBox.x + shellBox.width;
        const brandToNavGap = navStripBox.x - brandRight;
        const rightGutter = shellRight - navStripRight;
        const rowCenterTolerance = 2;

        expect(brandToNavGap).toBeGreaterThanOrEqual(8);
        expect(brandToNavGap).toBeLessThanOrEqual(40);
        expect(Math.abs(rightGutter)).toBeLessThanOrEqual(2);
        expect(navStripRight).toBeLessThanOrEqual(1920);
        expect(Math.abs((mainNavBox.y + mainNavBox.height / 2) - (secondaryNavBox.y + secondaryNavBox.height / 2))).toBeLessThanOrEqual(rowCenterTolerance);
        if (paymentButtonBox) {
            expect(Math.abs((mainNavBox.y + mainNavBox.height / 2) - (paymentButtonBox.y + paymentButtonBox.height / 2))).toBeLessThanOrEqual(rowCenterTolerance);
        }
        
        // Get layout measurements
        const measurements = await shell.evaluate((el) => {
            const children = Array.from(el.children);
            return children.map((child, index) => {
                const box = child.getBoundingClientRect();
                return {
                    index,
                    tagName: child.tagName,
                    className: child.className,
                    width: box.width,
                    height: box.height,
                    left: box.left,
                    top: box.top
                };
            });
        });
        
        console.log('Header layout measurements:', JSON.stringify(measurements, null, 2));
        
        // Check spacing between elements
        if (measurements.length > 1) {
            for (let i = 1; i < measurements.length; i++) {
                const gap = measurements[i].left - (measurements[i-1].left + measurements[i-1].width);
                console.log(`Gap between element ${i-1} and ${i}: ${gap}px`);
            }
        }
        
        // Take screenshot with annotations
        await page.screenshot({ 
            path: 'tests/screenshots/header-layout.png' 
        });
    });

    test('should switch to the menu before desktop navigation can clip', async ({ page }) => {
        await page.setViewportSize({ width: 1600, height: 720 });

        const header = page.locator('.navbar');
        const shell = header.locator('.navbar-shell');
        const mainNav = header.locator('.main-nav-container');
        const secondaryNav = header.locator('.expandable-tabs-container');
        const mobileToggle = header.locator('#mobile-menu-toggle');
        const mobileMenu = header.locator('#mobile-menu');

        await expect(mainNav).toBeHidden();
        await expect(secondaryNav).toBeVisible();
        await expect(mobileToggle).toBeVisible();

        const [shellBox, secondaryNavBox] = await Promise.all([
            shell.boundingBox(),
            secondaryNav.boundingBox()
        ]);

        expect(shellBox).not.toBeNull();
        expect(secondaryNavBox).not.toBeNull();
        expect(secondaryNavBox.x + secondaryNavBox.width).toBeLessThanOrEqual(shellBox.x + shellBox.width + 1);

        await mobileToggle.click();
        await expect(mobileMenu).toBeVisible();
        await expect(mobileToggle).toHaveAttribute('aria-expanded', 'true');
    });
});

test.describe('Header Aesthetic Checks', () => {
    test('should verify color consistency', async ({ page }) => {
        await page.goto(`${BASE_URL}/login.php`);
        await page.fill('input[name="email"]', TEST_EMAIL);
        await page.fill('input[name="password"]', TEST_PASSWORD);
        await page.click('button[type="submit"]');
        await page.waitForURL('**/dashboard.php');
        
        const header = page.locator('.navbar');
        
        // Check brand color
        const brand = header.locator('.navbar-brand');
        const brandColor = await brand.evaluate((el) => {
            return window.getComputedStyle(el).backgroundImage;
        });
        console.log('Brand gradient:', brandColor);
        
        // Check nav link colors
        const navLink = header.locator('.nav-link').first();
        const linkColor = await navLink.evaluate((el) => {
            return window.getComputedStyle(el).color;
        });
        console.log('Nav link color:', linkColor);
        
        // Check dropdown toggle colors
        const dropdownToggle = header.locator('.nav-dropdown-toggle').first();
        const toggleColor = await dropdownToggle.evaluate((el) => {
            return window.getComputedStyle(el).color;
        });
        console.log('Dropdown toggle color:', toggleColor);
    });
});
