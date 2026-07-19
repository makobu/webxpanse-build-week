# Authentication and Authorization Audit - 2026-05-16

## Scope traced

- Web login, logout, session bootstrap, CSRF, remember-me cookies, 2FA completion, password reset, and password updates.
- RBAC compatibility through `CRM\Authorization`, legacy role fallbacks, superadmin behavior, workspace roles, and platform-only permissions.
- Direct URL access to public PHP pages, especially operational pages that were relying on route/layout conventions.
- Server-side ownership and workspace checks for contacts, tasks, reports, exports, settings, API keys, webhooks, email assistant runs, and monitoring pages.
- Public-by-design endpoints: hosted forms/assets, tracking pixels/clicks/pageviews, external webhooks, health/status callbacks, and GDPR verification/export/delete flows.

## Fixed clear gaps

- Added a compatibility `Auth::requireLogin()` wrapper to avoid a fatal authorization bypass/availability bug in pages using that helper name.
- Added direct authentication guards to API key, webhook, draft template, draft review, monitoring alert, and error log pages that previously relied on indirect bootstrap behavior.
- Added monitoring permission checks to alerts and error logs (`settings.monitoring`).
- Scoped email assistant run list/read APIs and page views to the active workspace and required `settings.email_assistant`; added CSRF and workspace checks to the assistant resolution API.
- Enforced contact direct-view/edit access to match the default contact list rule: assigned to current user, unassigned, or `contacts.view_all`.
- Enforced contact export and contact review queue visibility to match contact list ownership rules.
- Enforced task direct-view/export/list filtering so users without `tasks.view_all` cannot request another user's assigned tasks by URL.
- Scoped the company export page's total count to the active workspace.

## Ambiguous cases left for product decisions

- Draft templates and draft reviews are not workspace-scoped in the current module methods. They may be intended as shared workspace assets, user-private assets, or global admin assets. Recommended decision: add `workspace_id` and decide whether non-admin users can see only their own drafts/templates.
- API keys are currently user-owned, not workspace-owned. In a multi-workspace account this may be correct for personal integration keys, but it should be explicit. Recommended decision: either keep personal API keys or add workspace-bound keys with separate admin management.
- Webhooks are managed through authenticated pages, but the module policy appears workspace-level and not RBAC-gated beyond login. Recommended decision: require a settings/integration permission such as `settings.webhooks` or `settings.integrations`.
- Companies are workspace-scoped but not owner-scoped. That may be intentional because companies are shared account records. Recommended decision: document that companies are workspace-visible to all authenticated members, or add `companies.view_all`/assignment rules.
- Reports appear user-owned/shared through `Reports::getViewableById()`. Natural-language reports can query broad workspace CRM data. Recommended decision: decide whether report generation should honor the same contact/task owner scopes as list and export pages.
- Public form and tracking endpoints are intentionally unauthenticated. Recommended decision: add rate limiting and signed/upload constraints where not already present, especially for file upload form fields.
- Several legacy admin-only API endpoints still compare `users.role = 'admin'` directly. Recommended decision: migrate these to RBAC permissions (`ai.operations.manage`, `ai.prompt_control.manage`, `workflows.manage`, or integration-specific settings permissions) so workspace owners/admins behave consistently.
