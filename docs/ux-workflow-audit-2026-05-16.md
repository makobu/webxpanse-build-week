# UX Workflow Audit - 2026-05-16

Automation: 7. UX Workflow Audit

## Scope Exercised

- Login and logout: `public/login.php`, `public/logout.php`
- Daily work routes: `public/dashboard.php`, `public/contacts.php`, `public/companies.php`, `public/deals.php`, `public/tasks.php`, `public/invoices.php`, `public/reports.php`, `public/search.php`
- Creation flows: `public/contacts_create.php`, `public/company_create.php`, `public/deal_create.php`, `public/task_create.php`, `public/invoice_create.php`, `public/report_create.php`
- Settings: `public/settings.php`, `public/settings.php?tab=company`, `public/settings.php?tab=general`

## Fixes Made

1. Search drawer caused page-level horizontal scroll while closed.
   - Route observed: `public/dashboard.php`
   - Fix: changed the closed search panel from negative `right` positioning to `transform`, so the hidden drawer no longer expands document width.
   - Screenshot: `docs/ux-audit-dashboard-2026-05-16.png`

2. Icon-only navigation/search controls lacked accessible labels in the daily shell.
   - Routes observed: all authenticated routes using `views/layouts/base.php`
   - Fix: added labels to the mobile menu, workspace search toggle, search panel close button, and search submit button. The mobile menu now updates `aria-expanded`.

3. Invoice creation currency labels showed corrupted or ambiguous symbols for several currencies.
   - Route observed: `public/invoice_create.php`
   - Fix: changed invoice currency option labels to `CODE - Currency Name`, avoiding symbol encoding failures in the form.
   - Screenshot: `docs/ux-audit-invoice-currency-2026-05-16.png`

4. Dashboard hero overflowed by a few pixels at 1280px.
   - Route observed: `public/dashboard.php`
   - Fix: added `box-sizing: border-box` to the hero container so padding does not widen the section beyond its parent.

## Prioritized Findings

P1 fixed: Authenticated shell horizontal overflow
- Before: the closed search drawer and dashboard hero created horizontal scroll on `dashboard.php`.
- After: fresh Chromium pass reported `overflow: 0` on dashboard, contacts, companies, deals, tasks, invoices, reports, search, and settings company routes.

P2 fixed: Search/navigation controls were unclear to assistive tooling
- Icon-only buttons now have explicit labels and the mobile navigation state is announced through `aria-expanded`.

P2 fixed: Invoice form currency choices were unclear
- The invoice form now displays labels such as `GBP - British Pound` and `EUR - Euro` instead of relying on symbols that rendered as question marks.

P3 remaining: Cookie controls appear in the DOM across every authenticated route
- The banner is expected product behavior, but it adds repeated headings/buttons to route audits and can make automated heading checks noisy. No product change made.

## Verification

- `php -l views/layouts/base.php`
- `php -l views/partials/invoice_form.php`
- `php -l public/dashboard.php`
- Fresh Chromium audit pass across the routes listed above for fatal text, horizontal overflow, unlabeled visible buttons, and invoice currency labels.
