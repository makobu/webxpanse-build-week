# Refactor Backlog

Generated: 2026-05-19

This backlog is intentionally narrow. The codebase needs incremental seams around the highest-change areas before any large extraction or rewrite is safe. Tonight's scan focused on app-owned PHP/JS/CSS/SQL under `public/`, `modules/`, `services/`, `api/`, `views/`, `core/`, and `includes/`.

| Rank | Area | Finding | Impact | Risk | Effort | Recommended order |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Public page bootstrap and controllers | Many `public/*.php` files repeat bootstrap, session, authorization, request handling, queries, and HTML in one file. Current largest app files include `public/settings.php` (~646 KB), `public/workspace_skills.php` (~228 KB), `public/contact_view.php` (~165 KB), `public/dashboard.php` (~126 KB), and `public/workspace_admin.php` (~114 KB). | High: small UI changes are hard to test and can regress request handling or auth. | Medium: extraction can break global variables and include order. | Large | Start with one low-risk route at a time. Move request parsing and mutations into small services, leaving rendering in place until covered by smoke tests. |
| 2 | Schema inspection helpers | `information_schema` table and column checks remain repeated across services/modules with inconsistent casing, return shapes, and exception handling. The densest remaining cluster is in AI autonomy/runtime/diagnostics services, with additional direct checks in workspace services and public pages. | High: migrations and optional feature tables are harder to reason about consistently. | Low: can be migrated call-by-call. | Medium | Use `Database::tableExists()` and `Database::columnExists()` for new code, then replace local helpers opportunistically when editing a service. Prioritize services with private helpers before inline public-page checks. |
| 3 | JSON decoding helpers | `decodeJson`, `decodeAssoc`, and inline `json_decode(..., true) ?: []` patterns are duplicated across AI, workspace, billing, marketplace, workflow, and WhatsApp services. | Medium: malformed JSON handling differs by module and makes nullable fields fragile. | Low to medium: some callers intentionally preserve scalar decoded values. | Medium | Add a small value helper for associative-array JSON only, then migrate only places that already expect arrays. |
| 4 | Workspace marketplace and skills services | Marketplace recommendation, install, catalog, events, setup journey, adaptive signal, skill catalog, and skill install services share naming, readiness, JSON, normalization, and schema-check patterns but implement them separately. | Medium: feature work spreads duplicated behavior across several small services. | Medium: business rules are active and user-facing. | Medium | First share infrastructure helpers only. Later extract common marketplace row normalization after behavior tests exist. |
| 5 | AI diagnostics/event normalization | `AIAutomationDiagnosticsService` is over 80 KB and mixes query building, filtering, normalization, label mapping, and UI-ready summaries. | High: adding diagnostic sources risks subtle filter and timeline regressions. | Medium to high: it aggregates many optional tables. | Large | Split by source family behind a stable collector interface after adding fixture-driven tests for current normalized output. |
| 6 | Frontend JavaScript embedded in PHP pages | Workflow, invoice, settings, WhatsApp, connect-apps, and workspace pages embed large page-specific scripts directly in PHP partials/pages. | Medium: behavior is hard to lint, reuse, or independently test. | Medium: scripts depend on server-rendered IDs and globals. | Large | Extract scripts only for pages already being touched. Preserve server-rendered config as JSON script tags. |
| 7 | Mixed SQL construction patterns | Query clauses are built inline in controllers and services using repeated param arrays and ad hoc optional filters. | Medium: pagination and workspace scoping can drift between pages. | Medium: SQL behavior is easy to alter accidentally. | Medium | Introduce tiny query-builder helpers for repeated filter clauses only after identifying two or more identical consumers. |
| 8 | Generated/test artifacts in repo root | PHPUnit logs, screenshots, and large zip files sit beside source files. `.gitignore` covers many paths, but tracked/deleted generated files still create status noise. | Medium: noisy status output hides meaningful changes. | Low: ignore rules are easy; deleting tracked assets requires care. | Small | Confirm which artifacts are intentionally tracked, then remove tracked generated files in a dedicated cleanup branch. |
| 9 | Global state and static access | Static `Database`, `Auth`, `Authorization`, session globals, and include-time constants are heavily used across controllers and services. | High: tests need careful setup and state can leak between requests. | High: broad DI conversion would be disruptive. | Large | Do not convert broadly. Wrap new code with explicit service dependencies and add reset helpers for tests where needed. |
| 10 | Naming drift around skills/modules/plugins | Workspace marketplace code uses skill, module, marketplace item, and plugin terminology interchangeably in APIs and arrays. | Medium: new contributors have to infer domain boundaries from context. | Low to medium: renames can break templates and tests. | Medium | Document current terminology first, then rename only internal variables in touched files. Defer public keys and database columns. |

## Current Small Cleanup

- Added `Database::tableExists()` and `Database::columnExists()` as shared schema-inspection helpers.
- Replaced the local table-existence duplicate in `WorkspaceMarketplaceRecommendationService` with `Database::tableExists()`.
- Replaced the local table-existence duplicate in `WorkspaceMembershipService` with `Database::tableExists()`.
- Replaced local table-existence duplicates in `WorkflowAnalytics` and `OutcomeMetrics` with `Database::tableExists()`.

## 2026-05-19 Review Notes

- `rg` still finds dozens of direct `information_schema` checks. The safest next cleanup is call-by-call replacement in private helper methods in AI services, not public controller rewrites.
- `public/workspace_skills.php` has become the second-largest public page and should be treated like `settings.php`: add tests before extracting request handling or scripts.
- JSON decoding remains highly duplicated, but callers differ on whether scalars, `null`, and malformed JSON should be preserved. Add an associative-array-only helper before migrating consumers.

## Guardrails

- Avoid broad file splits until there is test coverage around the affected page or service.
- Prefer opportunistic cleanup while touching a feature area over standalone mechanical churn.
- Keep migrations separate and run them immediately after creation.
