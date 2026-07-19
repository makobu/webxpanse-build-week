# Marketing Release Validation Runbook

Internal checklist for validating the Marketing release candidate after the live-execution phases.

## Scope

Marketing now supports controlled live execution for email, SMS, WhatsApp, and allowlisted webhooks when every live gate passes. The product still plans, drafts, reviews, exports, and reports marketing work inside the CRM, and no external execution may happen through hidden shortcuts.

Controlled live execution requires: live policy enabled, ready connector, verified secret reference, passed preflight, consent/suppression clearance, manager approval, exact final confirmation, active worker/scheduler evidence, and manager-level permission.

Social posting, ad account execution, live SEO APIs, and external public hosting remain out of scope for this release.

## Baseline

- Branch: `codex/integration` or a non-`master` release branch.
- Latest required migration: `407_create_marketing_live_proof_packs.sql`.
- Next migration number, only if a schema fix is discovered: `408`.
- Core permissions: `marketing.read`, `marketing.write`, `marketing.manage`.

## Migration Gate

Run:

```powershell
php database\migrations\migrate.php
```

Expected:

- No migration failure.
- `marketing_admin.php` reports latest migration `407_create_marketing_live_proof_packs.sql`.
- `marketing_admin.php` reports Marketing AI Readiness with six core prompt entries, four operating-loop prompt entries, the `marketing` runtime surface available, and deterministic fallback ready.
- Missing tables or hardening indexes show as diagnostics recommendations, not raw errors.

## Controlled Live Execution Gate

- Controlled live email, SMS, WhatsApp, and webhook execution is allowed only when every live gate passes.
- Execution Center shows Live Setup Wizard, Dry-Run And Live Rehearsals, Scheduler Validation, Recovery Controls, Consent And Suppression Review, Outcome Reconciliation Events, and Live Proof Packs.
- Writers may prepare records, request review, request recovery, and run dry-side work where allowed.
- Managers/admins enable policy, verify manager-only controls, approve live execution, confirm with the exact phrase, run workers, apply recovery, and generate proof packs.
- Dry-run and rehearsal flows must not call external providers.
- Live adapter attempts store sanitized evidence: adapter key, connector id, queue id, idempotency proof, provider reference when available, request/response summaries, failure class, and latency.
- Proof packs include policy state, connector readiness, secret verification proof, approval actor, confirmation hash, queue item, adapter attempts, sanitized provider result, delivery proof, outcome reconciliation, and recovery events.
- Proof packs never include raw secrets, tokens, passwords, raw confirmation text, stack traces, full private payloads, or raw recipient values.

## Marketing AI Manual-First Gate

- Content tool suggestions are saved in tool-run history before a user applies one to a draft.
- Campaign brief generation fills reviewable fields only; nothing is persisted until the user saves the brief.
- Landing page copy generation fills reviewable fields only; nothing is persisted until the user saves the page.
- Quality checks never rewrite drafts; they store scores, recommendations, AI/fallback metadata, and review evidence.
- Assistant side effects remain draft-side only: content planners can create draft ideas, copywriters can create tool-run suggestions, and performance analysts can create CRM comments.
- Strategy gap analysis creates planning queue suggestions only; humans decide whether to assign, complete, or archive those opportunities.
- Campaign planner, performance analysis, and integration readiness reviews create planning queue suggestions only; no briefs, content, landing pages, exports, analytics snapshots, sends, or publish states are changed automatically.
- Phase 8 is pre-integration readiness, not connector delivery: it checks manual/export prerequisites such as assets, approvals, UTMs, destination fields, email checklists, channel bundles, and CRM landing-page token readiness.
- A live AI provider is not required for release validation when deterministic fallback is active and diagnostics show the prompt registry and runtime surface are ready.

## Automated Test Gate

Run:

```powershell
php -l modules\Marketing.php
php -l views\layouts\base.php
vendor\bin\phpunit tests\Unit\Frontend\MainNavigationTest.php tests\Unit\Modules\MarketingTest.php
vendor\bin\phpunit tests\Unit\Modules\MarketingTest.php --filter Live
vendor\bin\phpunit tests\Unit\Documentation\MarketingReleaseDocsTest.php
git diff --check
```

Expected:

- No PHP syntax errors.
- Marketing and navigation tests pass.
- Documentation boundary tests pass.
- No whitespace errors.

## Browser Smoke URLs

Open these URLs in a browser. If logged out, a safe redirect to login is acceptable. If logged in with the required permission, the page should render without fatal errors or stack traces.

- `http://localhost/crm/public/system_health.php`
- `http://localhost/crm/public/marketing.php`
- `http://localhost/crm/public/marketing_onboarding.php`
- `http://localhost/crm/public/marketing_content.php`
- `http://localhost/crm/public/marketing_briefs.php`
- `http://localhost/crm/public/marketing_calendar.php`
- `http://localhost/crm/public/marketing_reviews.php`
- `http://localhost/crm/public/marketing_operations.php`
- `http://localhost/crm/public/marketing_channel_exports.php`
- `http://localhost/crm/public/marketing_execution.php`
- `http://localhost/crm/public/marketing_email_runs.php`
- `http://localhost/crm/public/marketing_distribution.php`
- `http://localhost/crm/public/marketing_performance.php`
- `http://localhost/crm/public/marketing_admin.php`

## Role Matrix

- Unauthenticated users are redirected to login.
- Viewer users can read Marketing dashboard, lists, details, reports, and setup status.
- Marketing users can create and edit content, briefs, context, planning records, exports, starter data, and live preparation records.
- Marketing users cannot access manager-only diagnostics, destructive cleanup, live policy enablement, live approval/confirmation, live retry execution, proof-pack generation, or hard-delete actions.
- Owner/admin users can access all Marketing release surfaces, including diagnostics, cleanup, live controls, worker validation, recovery execution, and proof packs.

## End-To-End Workflow Smoke

1. Open Marketing Setup and create the opt-in starter pack.
2. Confirm starter records are marked as demo data and scoped to the active workspace.
3. Open Context Hub, Personas, Briefs, Content Studio, Calendar, Distribution, Quality, Operations, and Admin Diagnostics.
4. Create or review one content item.
5. Generate a campaign brief draft and confirm it remains unsaved until the user saves the brief.
6. Generate landing page copy and confirm it remains unsaved until the user saves the landing page.
7. Run a content tool, confirm the suggestion is saved first, then apply the selected suggestion and confirm the version trail.
8. Request review, then approve or reject with an owner/admin user.
9. Create or verify one planning queue item and one distribution export bundle.
10. Run a quality check and confirm it records recommendations without overwriting the draft automatically.
11. Run a Marketing assistant and confirm any side effects stay draft-side only.
12. Run integration readiness review and confirm it creates planning queue suggestions only.
13. Open Execution Center and confirm the Live Setup Wizard shows blockers until policy, connector, secret, preflight, throttle, rehearsal, approval, and worker evidence exist.
14. Run a dry rehearsal and confirm no external send/publish happens.
15. Promote a rehearsed item to the live queue only as a manager/admin, then confirm the exact live phrase is required before any live attempt.
16. Run live outcome sync and confirm repeated runs do not duplicate reconciliation events.
17. Generate a live proof pack and confirm it includes sanitized evidence while excluding secrets and raw recipient values.
18. Create a weekly or monthly analytics snapshot.
19. Use Admin Diagnostics to preview starter cleanup.
20. Archive starter data only when demo records are no longer needed.

## Cleanup Process

- Always preview cleanup first in `marketing_admin.php`.
- Cleanup archives supported starter records and marks demo-only metadata as archived.
- Cleanup does not hard delete real user records.
- After cleanup, archived starter brand/persona records should not appear in normal brand, persona, or content-planning pickers.
- Cross-workspace starter data must remain isolated.

## Known Non-Goals

- No live execution without policy, connector readiness, verified secret reference, preflight, consent/suppression clearance, manager approval, exact confirmation, scheduler/worker evidence, and manager-level permission.
- No external social publishing.
- No ad account API integration.
- No live SEO API integration.
- No live social, ad, SEO, ranking, or public-hosting connector delivery in this release.
- No public landing page hosting outside the CRM tokenized publishing foundation.
- No automatic replacement of user drafts by AI quality or assistant tools.
