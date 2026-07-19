# Refactor Backlog - 2026-05-16

## Review scope

- Scanned PHP entrypoints, core helpers, modules, services, tests, and existing overnight audit notes.
- Ranked maintainability debt around duplicated logic, oversized files, mixed presentation/business/database concerns, repeated SQL patterns, global state, and unclear ownership boundaries.
- Made only one low-risk cleanup: removed temporary agent debug logging from `CRM\Database::init()` so database bootstrap no longer writes `.cursor/debug.log` on every request.

## Ranked backlog

| Order | Area | Problem | Impact | Risk | Effort | Recommended next step |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Endpoint bootstrap and JSON responses | API files repeatedly call autoload/config/session/database setup, set JSON headers, validate auth/CSRF, and `echo json_encode()` locally. Search found hundreds of matches across `api/` and `public/`. | High: every new endpoint can drift on auth, status codes, headers, and error shape. | Medium: centralizing response behavior can accidentally change client-visible payloads. | M | Introduce a tiny `ApiResponse`/`ApiEndpoint` helper and migrate 3-5 low-traffic endpoints first, preserving payloads exactly. |
| 2 | Oversized page controllers | `public/settings.php` is 8,384 lines; `public/dashboard.php`, `public/contact_view.php`, `public/workflow_create.php`, and `public/conversation.php` are each 1,900+ lines and mix routing, SQL, state mutation, rendering, and JavaScript payload assembly. | High: changes are hard to review safely and regression blast radius is large. | Medium-high: pages are user-facing and likely have implicit dependencies. | L | Split by workflow: extract read-model builders and POST handlers into page-local services before touching templates. Start with one settings tab. |
| 3 | Workspace and authorization checks | Workspace scoping, owner filtering, legacy role checks, and permission checks appear in pages, APIs, modules, and services with different local shapes. The auth audit already found several direct-access gaps. | High: security and tenant-isolation fixes are easy to miss. | High: must preserve intentional sharing rules. | L | Create named policy/query helpers for contacts, tasks, reports, and integrations; add regression tests before migration. |
| 4 | Direct SQL in presentation entrypoints | Many `public/*.php` pages initialize `Database` and run queries directly before rendering. Similar list/count/filter patterns repeat across contacts, tasks, emails, reports, and dashboards. | High: query changes duplicate and presentation edits can alter business behavior. | Medium: extraction can be mechanical but test coverage must guard filters. | M-L | For each page family, extract a read repository/service with existing SQL copied verbatim, then update tests around filters and workspace scope. |
| 5 | Dashboard and analytics query assembly | `public/dashboard.php`, `public/analytics.php`, and `modules/AnalyticsDashboard.php` repeat metric/date/status aggregation concerns. | Medium-high: reporting fixes may diverge across surfaces. | Medium: metric definitions may intentionally differ. | M | Inventory metric names and owners, then consolidate shared date-window and workspace predicates first. |
| 6 | AI/service class sprawl | Several services exceed 1,000 lines, including `AIService.php`, `AIAutomationDiagnosticsService.php`, `CustomerReplyAssistantService.php`, and `AutomationBatteryService.php`. They combine provider calls, persistence, policy decisions, formatting, and diagnostics. | Medium-high: feature work risks cross-domain regressions. | Medium-high: AI behavior has subtle contracts. | L | Extract pure payload normalizers, provider adapters, and read models only where tests already exist. Avoid changing prompts and persistence together. |
| 7 | Repeated CSRF token sourcing | POST endpoints alternate among `$_POST['csrf_token']`, JSON body tokens, and `HTTP_X_CSRF_TOKEN`, often with hand-written fallback logic. | Medium: inconsistent clients and unclear failure behavior. | Low-medium: helper can preserve accepted sources. | S-M | Add `Security::csrfTokenFromRequest(array $input = [])` or similar, then migrate endpoints opportunistically. |
| 8 | Frontend data embedded from PHP | Large pages embed multiple `json_encode()` blocks directly into scripts, often from controller-local variables. | Medium: escaping mistakes and hard-to-test page state. | Medium: JavaScript expects current variable names. | M | Add a small `json_for_script()` helper using existing JSON_HEX flags and migrate one page at a time. |
| 9 | Generated/debug artifact hygiene | Root contains PHPUnit logs, screenshots, and browser artifacts; `.gitignore` has some coverage but the working tree can still collect noise. | Medium: makes automation and reviews harder. | Low: ignore-only changes are safe when scoped. | S | Audit generated artifact names from current test/browser workflows and extend `.gitignore` in a separate cleanup. |
| 10 | Migration runner fragmentation | Migration scripts exist under `database/migrations`, `scripts/`, CLI files, and root one-off runners. | Medium: schema changes are harder to reason about and run consistently. | Medium: migration execution touches live data. | M | Document the canonical migration path, then move only obsolete one-off runners after confirming they are no longer referenced. |

## Recommended order

1. Build and prove endpoint response/bootstrap helpers on a small API slice.
2. Extract one settings tab POST handler and read model from `public/settings.php`.
3. Add policy/query helpers for contacts and tasks because auth and workspace scope have known recent fixes.
4. Consolidate CSRF token extraction after endpoint helper shape is stable.
5. Tackle analytics/dashboard metric read models after the security-sensitive scope helpers exist.

## Cleanup completed

- Removed temporary instrumentation from `core/Database.php` that wrote a debug JSON record to `.cursor/debug.log` during every `Database::init()` call.
