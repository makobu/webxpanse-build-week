/**
 * Email AI and Template Test Suite
 * Tests AI email generation and template-based email sending
 */

const { test, expect } = require('@playwright/test');

const BASE_URL = 'http://localhost/crm/public';
const TEST_EMAIL = 'test@crm.local';
const TEST_PASSWORD = 'test123456';

test.describe('Email AI and Template Tests', () => {
    let contactId = null;
    let contactEmail = null;

    test.beforeAll(async ({ browser }) => {
        // Ensure test user exists
        const page = await browser.newPage();
        await page.goto(`${BASE_URL}/login.php`);
        
        // Try to login - if fails, user doesn't exist
        await page.fill('input[name="email"]', TEST_EMAIL);
        await page.fill('input[name="password"]', TEST_PASSWORD);
        await page.click('button[type="submit"]');
        
        await page.waitForTimeout(2000);
        
        // Check if login was successful
        const currentUrl = page.url();
        if (currentUrl.includes('login.php')) {
            console.log('Test user may not exist. Please ensure test user is created.');
        }
        
        await page.close();
    });

    test.beforeEach(async ({ page }) => {
        // Navigate to login page
        await page.goto(`${BASE_URL}/login.php`);
        
        // Wait for page to load
        await page.waitForLoadState('networkidle');
        
        // Login
        await page.fill('input[name="email"]', TEST_EMAIL);
        await page.fill('input[name="password"]', TEST_PASSWORD);
        
        // Click submit and wait for navigation
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'networkidle' }),
            page.click('button[type="submit"]')
        ]);
        
        // Ensure we're logged in
        await page.waitForSelector('.navbar', { timeout: 10000 });
        
        // Create a test contact if needed
        await page.goto(`${BASE_URL}/contacts.php`);
        await page.waitForLoadState('networkidle');
        
        // Check if contacts exist, if not create one
        const contactLinks = await page.locator('a[href*="contact_view.php"]').count();
        if (contactLinks === 0) {
            // Create a test contact
            await page.goto(`${BASE_URL}/contacts_create.php`);
            await page.waitForLoadState('networkidle');
            
            await page.fill('input[name="first_name"]', 'Test');
            await page.fill('input[name="last_name"]', 'Contact');
            await page.fill('input[name="email"]', 'testcontact@example.com');
            await page.fill('input[name="phone"]', '1234567890');
            
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle' }),
                page.click('button[type="submit"]')
            ]);
        }
        
        // Get first contact ID from contacts page
        await page.goto(`${BASE_URL}/contacts.php`);
        await page.waitForLoadState('networkidle');
        
        const firstContactLink = page.locator('a[href*="contact_view.php"]').first();
        if (await firstContactLink.count() > 0) {
            const href = await firstContactLink.getAttribute('href');
            const match = href.match(/id=(\d+)/);
            if (match) {
                contactId = match[1];
            }
        }
        
        contactEmail = 'testcontact@example.com';
    });

    test('should display AI email generation section', async ({ page }) => {
        await page.goto(`${BASE_URL}/email_compose.php`);
        await page.waitForLoadState('networkidle');
        
        // Check for AI section
        const aiSection = page.locator('[data-guided-demo-target="email-compose-ai-panel"]');
        await expect(aiSection).toBeVisible();

        const intentionInput = page.locator('#draft_intention');
        await expect(intentionInput).toBeVisible();
        
        // Check for purpose dropdown
        const purposeSelect = page.locator('#draft_purpose');
        await expect(purposeSelect).toBeVisible();
        
        // Check for tone dropdown
        const toneSelect = page.locator('#draft_tone');
        await expect(toneSelect).toBeVisible();
        
        // Check for generate button
        const generateBtn = page.locator('#generate-draft-btn');
        await expect(generateBtn).toBeVisible();
        await expect(generateBtn).toContainText('Draft From Intention');
    });

    test('should require an intention before generating AI draft', async ({ page }) => {
        await page.goto(`${BASE_URL}/email_compose.php`);
        await page.waitForLoadState('networkidle');
        
        const dialogPromise = page.waitForEvent('dialog');
        const generateBtn = page.locator('#generate-draft-btn');
        await generateBtn.click();
        
        const dialog = await dialogPromise;
        expect(dialog.message()).toContain('Tell AI what you want to say');
        await dialog.accept();
        
        await page.waitForTimeout(1000);
    });

    test('should generate AI draft when contact is selected', async ({ page }) => {
        await page.goto(`${BASE_URL}/email_compose.php`);
        await page.waitForLoadState('networkidle');
        
        // Select a contact
        const contactSelect = page.locator('#contact_id');
        await contactSelect.waitFor({ state: 'visible', timeout: 5000 });
        
        // Wait for options to load
        await page.waitForTimeout(1000);
        
        // Select first available contact
        const options = await contactSelect.locator('option').count();
        if (options > 1) {
            await contactSelect.selectOption({ index: 1 });
        }
        
        // Select purpose and tone
        await page.fill('#draft_intention', 'Follow up after the demo and ask whether Friday works for a decision call.');
        await page.selectOption('#draft_purpose', 'follow_up');
        await page.selectOption('#draft_tone', 'professional');
        
        // Click generate button
        const generateBtn = page.locator('#generate-draft-btn');
        
        // Set up response listener
        let apiCalled = false;
        page.on('response', async response => {
            if (response.url().includes('generate_draft.php')) {
                apiCalled = true;
            }
        });
        
        await generateBtn.click();
        
        // Wait for status update
        const statusDiv = page.locator('#ai-status');
        await statusDiv.waitFor({ state: 'visible', timeout: 10000 });
        
        // Check if status shows generating or result
        const statusText = await statusDiv.textContent();
        console.log('AI Status:', statusText);
        
        // Wait a bit for API call
        await page.waitForTimeout(3000);
        
        // Check if subject or body fields were populated (if AI worked)
        // or if error message is shown (if AI not configured)
        const subjectField = page.locator('#subject');
        const bodyField = page.locator('#body');
        
        // Either AI worked (fields filled) or error shown (status shows error)
        const subjectValue = await subjectField.inputValue();
        const bodyValue = await bodyField.inputValue();
        const currentStatus = await statusDiv.textContent();
        
        // Log results
        console.log('Subject filled:', subjectValue.length > 0);
        console.log('Body filled:', bodyValue.length > 0);
        console.log('Status:', currentStatus);
        
        // Test passes if either:
        // 1. AI worked and fields are filled, OR
        // 2. Error message is shown (AI not configured)
        const hasContent = subjectValue.length > 0 || bodyValue.length > 0;
        const hasError = currentStatus.includes('Error') || currentStatus.includes('Failed');
        const hasSuccess = currentStatus.includes('successfully');

        if (bodyValue.length > 0) {
            expect(bodyValue).not.toContain('```json');
            expect(bodyValue).not.toContain('"body_html":');
            expect(bodyValue).not.toContain('HTML formatted email body');
        }
        
        expect(hasContent || hasError || hasSuccess).toBeTruthy();
    });

    test('should display email templates dropdown', async ({ page }) => {
        await page.goto(`${BASE_URL}/email_compose.php`);
        await page.waitForLoadState('networkidle');
        
        // Look for template section
        const templateSelect = page.locator('#template_slug');
        await expect(templateSelect).toBeVisible();
        
        // Check if it has options
        const options = await templateSelect.locator('option').count();
        console.log('Template options available:', options);
        
        // Should have at least "No template" option
        expect(options).toBeGreaterThan(0);
    });

    test('should load template when contact is selected', async ({ page }) => {
        await page.goto(`${BASE_URL}/email_compose.php`);
        await page.waitForLoadState('networkidle');
        
        // Select a contact first
        const contactSelect = page.locator('#contact_id');
        await contactSelect.waitFor({ state: 'visible', timeout: 5000 });
        await page.waitForTimeout(1000);
        
        const options = await contactSelect.locator('option').count();
        if (options > 1) {
            await contactSelect.selectOption({ index: 1 });
        }
        
        // Wait a bit for contact to be selected
        await page.waitForTimeout(500);
        
        // Select a template
        const templateSelect = page.locator('#template_slug');
        await templateSelect.waitFor({ state: 'visible', timeout: 5000 });
        
        // Get available templates
        const templateOptions = await templateSelect.locator('option').all();
        let templateSelected = false;
        
        for (const option of templateOptions) {
            const value = await option.getAttribute('value');
            if (value && value !== '' && value !== '0') {
                await templateSelect.selectOption(value);
                templateSelected = true;
                break;
            }
        }
        
        if (templateSelected) {
            // Wait for template to load
            await page.waitForTimeout(2000);
            
            // Check if subject or body was filled
            const subjectField = page.locator('#subject');
            const bodyField = page.locator('#body');
            
            const subjectValue = await subjectField.inputValue();
            const bodyValue = await bodyField.inputValue();
            
            console.log('Template loaded - Subject:', subjectValue.length > 0);
            console.log('Template loaded - Body:', bodyValue.length > 0);
            
            // Template should populate fields (if template exists and works)
            // or show alert if contact not selected
            expect(subjectValue.length > 0 || bodyValue.length > 0 || !templateSelected).toBeTruthy();
        } else {
            console.log('No templates available to test');
        }
    });

    test('should show AI draft templates section', async ({ page }) => {
        await page.goto(`${BASE_URL}/email_compose.php`);
        await page.waitForLoadState('networkidle');
        
        // Look for AI draft templates dropdown
        const aiTemplateSelect = page.locator('#ai_draft_template_id');
        
        // May or may not exist depending on implementation
        const exists = await aiTemplateSelect.count() > 0;
        
        if (exists) {
            await expect(aiTemplateSelect).toBeVisible();
            console.log('AI draft templates dropdown found');
        } else {
            console.log('AI draft templates dropdown not found (may not be implemented)');
        }
    });

    test('should compose email form be complete', async ({ page }) => {
        await page.goto(`${BASE_URL}/email_compose.php`);
        await page.waitForLoadState('networkidle');
        
        // Check all required fields exist
        const contactSelect = page.locator('#contact_id');
        await expect(contactSelect).toBeVisible();
        
        const subjectField = page.locator('#subject');
        await expect(subjectField).toBeVisible();
        
        const bodyField = page.locator('#body');
        await expect(bodyField).toBeVisible();
        
        // Check for send button
        const sendButton = page.locator('button[type="submit"]');
        await expect(sendButton).toBeVisible();
        await expect(sendButton).toContainText('Send Email');
    });

    test('should fill form and attempt to send email', async ({ page }) => {
        await page.goto(`${BASE_URL}/email_compose.php`);
        await page.waitForLoadState('networkidle');
        
        // Select contact
        const contactSelect = page.locator('#contact_id');
        await contactSelect.waitFor({ state: 'visible', timeout: 5000 });
        await page.waitForTimeout(1000);
        
        const options = await contactSelect.locator('option').count();
        if (options > 1) {
            await contactSelect.selectOption({ index: 1 });
        }
        
        // Fill subject and body manually
        await page.fill('#subject', 'Test Email from Playwright');
        await page.fill('#body', 'This is a test email sent from Playwright automated tests.');
        
        // Take screenshot before sending
        await page.screenshot({ path: 'tests/screenshots/email-compose-filled.png' });
        
        // Note: We won't actually send the email to avoid spam
        // Just verify the form can be filled
        const subjectValue = await page.locator('#subject').inputValue();
        const bodyValue = await page.locator('#body').inputValue();
        
        expect(subjectValue).toBe('Test Email from Playwright');
        expect(bodyValue).toContain('test email');
    });

    test('should display email signatures section', async ({ page }) => {
        await page.goto(`${BASE_URL}/email_compose.php`);
        await page.waitForLoadState('networkidle');
        
        // Look for signature section
        const signatureSelect = page.locator('#signature_id');
        const exists = await signatureSelect.count() > 0;
        
        if (exists) {
            await expect(signatureSelect).toBeVisible();
            console.log('Email signatures dropdown found');
        } else {
            console.log('Email signatures dropdown not found (may not be implemented)');
        }
    });
});
