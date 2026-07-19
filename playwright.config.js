/**
 * Playwright Configuration
 */

module.exports = {
    testDir: './tests',
    testMatch: '**/*.spec.js',
    timeout: 60000,
    workers: Number(process.env.CRM_PLAYWRIGHT_WORKERS || 3),
    expect: {
        timeout: 10000
    },
    use: {
        baseURL: 'http://localhost/crm/public',
        headless: true,
        viewport: { width: 1280, height: 720 },
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        trace: 'retain-on-failure'
    },
    projects: [
        {
            name: 'chromium',
            use: { 
                browserName: 'chromium',
                channel: 'chrome'
            }
        },
        {
            name: 'firefox',
            testIgnore: [
                'tests/regression/**',
                'tests/smoke/contacts.smoke.spec.js',
                'tests/smoke/deals.smoke.spec.js',
                'tests/smoke/settings.smoke.spec.js',
                'tests/smoke/targets.smoke.spec.js',
                'tests/smoke/tasks.smoke.spec.js'
            ],
            use: { 
                browserName: 'firefox'
            }
        },
        {
            name: 'webkit',
            testIgnore: [
                'tests/regression/**',
                'tests/smoke/contacts.smoke.spec.js',
                'tests/smoke/deals.smoke.spec.js',
                'tests/smoke/settings.smoke.spec.js',
                'tests/smoke/targets.smoke.spec.js',
                'tests/smoke/tasks.smoke.spec.js'
            ],
            use: { 
                browserName: 'webkit'
            }
        }
    ],
    outputDir: 'tests/screenshots'
};
