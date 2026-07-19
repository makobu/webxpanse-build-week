# Refactor Backlog - 2026-05-20

Scope: overnight maintainability review focused on duplicated logic, oversized files, mixed concerns, repeated SQL patterns, helper inconsistency, and fragile global state. This pass intentionally avoided a broad refactor and made only one narrow cleanup: a shared `.env` file loader for two bootstrap paths.

## Small Cleanup Completed

- Added `config/env.php` with `loadEnvFile(string $path): void`.
- Replaced copied `.env` parsing in `public/_public_bootstrap.php` and `api/mobile/_bootstrap.php`.
- The mobile bootstrap now gets the same quoted-value handling already used by the public bootstrap.

## Ranked Backlog

| Order | Refactor Item | Evidence | Impact | Risk | Effort | Recommended First Step |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Centralize bootstrap and `.env` loading | `rg "$envFile = .*\\.env"` found 429 copied loader starts across `api`, `public`, and `cli`; `cli/ml_model_retrain.php` requires missing `config/bootstrap.php`. | High: fewer auth/env drift bugs and easier operational changes. | Medium: entry points are broad and security-sensitive. | M | Extend `config/env.php` adoption to one route family at a time, starting with CLI scripts that only load config and services. |
| 2 | Split `public/settings.php` into action handlers and view partials | File is 8,175 lines and mixes request handling, permission checks, settings orchestration, and presentation. | High: reduces regression risk for settings changes and improves reviewability. | Medium-high: many settings surfaces likely depend on shared globals. | L | Extract one low-traffic tab/action into a private handler include, add an integration smoke check, then repeat. |
| 3 | Introduce a public page controller pattern | Several public files exceed 1k lines (`workspace_skills.php` 3,735; `contact_view.php` 3,058; `dashboard.php` 2,807; `workflow_create.php` 2,239) and combine query logic, POST actions, rendering, and inline JS. | High: pages become easier to test and change safely. | Medium: presentation behavior is user-visible. | L | Pick one page with good tests, move POST handling into `services` or `modules`, and keep templates behavior-identical. |
| 4 | Standardize workspace scoping helpers | `workspaceClause()`/`workspaceId()` are duplicated in `Companies`, `Contacts`, `Deals`, `Invoices`, `Tasks`, and others despite `WorkspaceScopeService` and `AnalyticsWorkspaceService`. | High: tenant isolation mistakes become less likely. | Medium: bad abstraction can weaken isolation if rushed. | M | Add a module-facing helper wrapper around `WorkspaceScopeService`, migrate one module, and prove query parity with existing tests. |
| 5 | Normalize JSON API request/response helpers | Many API files manually read `php://input`, fall back to `$_POST`, validate CSRF, and emit JSON differently. Mobile has `mobileJson()` and `mobileRequestBody()`, but non-mobile APIs repeat the pattern. | Medium-high: consistent errors, headers, CSRF, and request parsing. | Medium: API clients may rely on current response details. | M | Create `api/_helpers.php` for request body and JSON response only; migrate low-risk API endpoints first. |
| 6 | Extract shared browser escaping helpers | `escapeHtml()` and `escapeAttribute()` are repeated in `public/assets/js` plus inline page scripts including bulk email, bulk WhatsApp, webhook logs, WhatsApp compose, and workflow create. | Medium: fewer XSS helper inconsistencies and smaller inline scripts. | Low-medium: frontend loading order needs care. | S-M | Add a tiny shared asset under `public/assets/js/escape.js`, include it on one page, then migrate repeated inline helpers. |
| 7 | Move report/activity page formatting into view helpers | Existing dirty work in `modules/Reports.php`, `public/reports.php`, `modules/Activities.php`, and `public/activities.php` suggests this area is actively changing and presentation/business logic is close together. | Medium: keeps report execution separate from display formatting. | Medium: active worktree changes should not be disturbed. | M | After current branch changes settle, extract pure display formatters with focused unit coverage. |
| 8 | Audit self-healing schema methods in modules | Several modules have `ensureTablesExist()` or `has...Column()` runtime schema probes (`Tasks`, `Targets`, `Forms`, `ApiKeys`, `Webhooks`). | Medium: runtime schema mutation/probing hides migration drift. | Medium-high: some legacy installs may depend on self-healing. | M-L | Inventory runtime schema checks, map each to a migration, then remove only checks covered by migration tests. |
| 9 | Reduce AI service class size by responsibility | `AIAutomationDiagnosticsService.php` is 1,769 lines and `AIService.php` is 1,570 lines; several AI services mix policy, orchestration, diagnostics, provider calls, and formatting. | Medium: faster AI feature changes and clearer failure modes. | Medium: AI paths have many integration points. | L | Extract pure value-formatting/diagnostic mappers first; avoid provider/runtime changes until tests are stronger. |
| 10 | Consolidate duplicate skill/contract readiness logic | `hasReadySkillContract()` appears in both `api/chat/ask.php` and `modules/AICoach.php`. | Medium: reduces drift in assistant behavior. | Low-medium: behavior affects user guidance. | S | Move readiness evaluation into a small service and cover with unit tests using existing operating-context fixtures. |

## Guardrails For Next Runs

- Do not start with `settings.php`; begin with helper consolidation or a single route family to keep risk bounded.
- Keep tenant/workspace scoping refactors behind existing tests and compare generated SQL parameters before changing behavior.
- Avoid editing currently dirty files from this branch unless the next task explicitly targets them.
- Treat runtime schema checks as compatibility behavior until a migration-backed replacement is proven.
