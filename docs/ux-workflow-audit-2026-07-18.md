# UX Workflow Audit - 2026-07-18

Automation: 7. UX Workflow Audit

## Scope exercised

- Authentication: `public/login.php`, authenticated dashboard landing, and `public/logout.php`
- Daily routes: `public/dashboard.php`, `public/leads.php`, `public/customers.php`, `public/contacts.php`, `public/deals.php`, `public/tasks.php`, `public/invoices.php`, `public/reports.php`, `public/search.php`, and `public/settings.php`
- Interactive checks: contact search and stage filtering; deal search and assignee filtering; task status and priority filtering; invoice type and status filtering; empty report-question validation; workspace search; settings navigation; logout
- Responsive checks: every daily route above at a 390 x 844 viewport, including page overflow and element-bound checks

## Prioritized findings

### P1 fixed: CSRF tokens leaked into read-only filter URLs

- Routes observed: `public/deals.php`, `public/tasks.php`, and `public/invoices.php`
- Before: the shared application script injected a hidden CSRF field into every form, including GET filter forms. Submitting a filter copied the session token into the address bar, browser history, and server logs.
- Fix: CSRF injection now targets POST forms only. The authenticated layout also versions `assets/js/app.js`, so browsers receive the corrected script immediately.
- After: all three filters retain their selected search/status values without a `csrf_token` query parameter.

### P2 fixed: Workspace Team settings clipped on mobile

- Route observed: `public/settings.php` at 390 x 844.
- Before: `.content-card` used content-box sizing while also taking full width, so its horizontal padding pushed the Workspace Team section past its container. The parent hid overflow, clipping copy and controls on the right.
- Fix: settings content cards now use border-box sizing with a bounded width and zero minimum width. The settings stylesheet URL was versioned for immediate delivery.
- Before screenshot: [mobile settings clipping](ux-audit-settings-mobile-2026-07-18.png)
- After screenshot: [mobile settings fixed](ux-audit-settings-mobile-fixed-2026-07-18.png)

### P2 fixed: Report validation feedback was not announced

- Route observed: `public/reports.php`
- Before: an empty report question produced clear visual feedback, but the dynamic status element had no live-region semantics and was not connected to the question field.
- Fix: the question now references the status with `aria-describedby`; the status uses `role="status"`, `aria-live="polite"`, and `aria-atomic="true"`.
- Screenshot: [report assistant validation](ux-audit-reports-validation-2026-07-18.png)

### P3 remaining: local assignee lists contain extensive test-fixture accounts

- Routes observed: `public/deals.php` and `public/tasks.php`
- Impact: the Assigned To chooser is noisy in this local default workspace because browser and integration fixtures have accumulated as active users.
- Recommendation: clean or isolate fixture users in a separate test workspace/database. No records were deleted during this audit.

### P3 remaining: settings smoke expectations have drifted from current product routing

- Test route references: `tests/smoke/settings.smoke.spec.js:66`, `:115`, and `:194`
- Current product behavior routes legacy email setup into the plugin workspace, uses the newer package/AI Credit model, and renders the page-video surface without the older expected heading.
- Recommendation: update the settings smoke contract in a dedicated test-maintenance pass after confirming those three product decisions. These failures were not caused by the UX fixes in this audit.

## Verification

- Rendered desktop route pass: all requested daily routes returned the correct title and primary heading, no fatal error text, and no page-wide horizontal overflow.
- Rendered mobile route pass at 390 x 844: all requested routes had zero document overflow after the settings fix.
- Repeated live filter submissions on Deals, Tasks, and Invoices: clean URLs with no CSRF token.
- Empty report question: input refocused, message displayed, and live-region attributes confirmed.
- Logout returned to `public/login.php` with the expected sign-in form.
- `php -l public/reports.php`
- `php -l public/settings.php`
- `php -l views/layouts/base.php`
- `vendor/bin/phpunit.bat tests/Unit/Frontend/DesignSystemTest.php`: 11 tests, 103 assertions passed.
- Existing Playwright smoke selection: 6 passed (auth, contacts, deals, billing settings, super-admin billing hub, tasks); 3 settings tests failed on the stale expectations documented above.
- Dashboard screenshot: [audited dashboard](ux-audit-dashboard-2026-07-18.png)
