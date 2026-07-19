# UX Workflow Audit - 2026-05-20

Automation: 7. UX Workflow Audit

## Scope Exercised

- Reviewed the daily CRM workflow routes by static and CLI pass: `public/dashboard.php`, `public/leads.php`, `public/customers.php`, `public/contacts.php`, `public/deals.php`, `public/tasks.php`, `public/invoices.php`, `public/reports.php`, `public/search.php`, `public/settings.php`, `public/logout.php`, and the shared navigation layout.
- Checked main route links and form controls for broken PHP targets, missing labels, unsafe result URLs, and inconsistent action controls.
- Browser execution against `http://localhost/crm/public` was blocked by the Codex in-app browser security policy during this run, so no fresh screenshots were captured.

## Fixes Made

1. Search workflow accessibility and link safety
   - Route: `public/search.php`
   - Added an explicit screen-reader label for the global search field.
   - Connected the saved-search modal label to its required input.
   - Escaped generated search-result href values before rendering them.

2. Reports assistant input label
   - Route: `public/reports.php`
   - Added an explicit screen-reader label for the natural-language report question input.

3. Shared hidden-label utility
   - File: `public/assets/css/main.css`
   - Added a global `.sr-only` utility because daily pages now use it and the marketplace page already referenced it.

4. Footer action consistency
   - File: `views/layouts/base.php`
   - Replaced the `javascript:void(0)` Cookie Settings link with a real button, keeping the same behavior while avoiding pseudo-navigation.

## Prioritized Findings

P1 blocked: Full browser UX workflow pass could not run
- The in-app browser refused the local `http://localhost` target. A follow-up should capture desktop/mobile screenshots for login, dashboard, contact filtering, deal movement, invoice filtering, report asking, settings, and logout once local browser access is available.

P2 fixed: Search and report assistant fields depended on placeholder-only labeling
- Placeholder-only controls are harder to use with assistive technology and lose their visible cue once a user starts typing.

P2 fixed: Search result URLs were rendered without escaping
- The URLs are generated internally, but escaping keeps the result list robust and consistent with the rest of the app.

P3 fixed: Footer Cookie Settings behaved like navigation but executed an action
- The control now uses button semantics while preserving the cookie settings workflow.

## Verification

- `php -l public/search.php`
- `php -l public/reports.php`
- `php -l views/layouts/base.php`
- `vendor\bin\phpunit tests\Unit\Frontend\MainNavigationTest.php tests\Unit\Frontend\DesignSystemTest.php`
- Static main-route target check found no missing PHP targets across the audited route set and shared layout.
