# Production Readiness Defaults Matrix

Last updated: 2026-07-05

## Current Position

The production-hardening work is past the safety baseline and into the foundation for production defaults, context, cleanup, and verification gates.

Recent baseline commits:

| Commit | Purpose |
| --- | --- |
| `f7456c38` | Checkpoint current app state before production hardening. |
| `fdaf09bd` | Repair default workspace production baseline. |
| `df014bcc` | Add System Health production preflight. |
| `971c9a67` | Add production preflight CLI gate. |
| `04dd3b44` | Prepare runtime paths before production preflight. |
| `0687f2ff` | Add production defaults matrix. |
| `ebcaf819` | Add Super Admin automation catalog foundation. |
| `b19e86be` | Add Super Admin automation review queue. |
| `09f34bd7` | Add automation detector run deduplication. |
| `82b6c674` | Add automation review escalation policy. |
| `63bf6870` | Add demo quarantine verification. |
| `fc4bbf3d` | Preserve dashboard metric icon weight fix before Phase 1 blocker work. |

Current branch: `codex/workspace-negotiated-packages`

## Phase Status

| Phase | Status | Notes |
| --- | --- | --- |
| Phase 0 Safety Baseline | Complete | Current app state was checkpointed before production edits. |
| Phase 1 Production Defaults Audit | In progress | This document is the durable audit matrix. Local live-blocker burn-down has cleared standard preflight criticals; security endpoint warnings still need deeper endpoint-by-endpoint review. |
| Phase 2 Context Model | Partial | Default workspace now has Platform Ops context and operating boundaries. `SystemContextRegistryService` centralizes the first workspace, system, contact-scope, template-baseline, and domain automation defaults, with System Health validation for missing/dummy context. More callers still need migration onto it. |
| Phase 3 Data Cleanup and Demo Separation | Started | Exact default-workspace smoke/presentation contacts and deals were removed. Read-only demo quarantine and production cleanup verification now check protected-demo/session/presentation data isolation, staged generated artifacts, and public setup/debug artifact warnings. Broader cleanup remediation remains pending. |
| Phase 4 Template System Upgrade | Started | Platform Ops templates were repaired. Read-only email/workflow template validation is available through CLI and System Health. WhatsApp, invoice, marketing reusable, and draft-template validation remain pending. |
| Phase 5 Settings Hardening | Partial | Safer Auto Admin and outbound defaults are in place for the default workspace. Security/RBAC hardening and integration readiness audits are available through CLI and System Health. Public endpoint/setup-script cleanup and provider credential repair remain pending. |
| Phase 6 Automation Battery Foundation | Partial | Automation catalog schema/service, run trail, controls, first read-only detector execution service, JSON review API, System Health review queue, run deduplication, escalation policy, and Super Admin escalation notifications are in place. |
| Phase 7 First 20 Automations | Wired | The first 20 detector definitions are cataloged in safe modes and all 20 are wired into the read-only detector runner. Remediation automation remains pending. |
| Phase 8 Expand Toward 100 Automations | Not started | Depends on Phase 6 catalog and controls. |
| Phase 9 Production Verification | Started early | CLI and System Health preflight exist. Template validation, security hardening audit, integration readiness, demo quarantine, and backup/restore readiness checks are available. Local standard preflight is blocker-free with operator backup env values; strict live smoke and dry-run reports remain pending. |
| Phase 10 Live Server Prep | Started | Runtime path preparation, refreshed deployment helper, live upload checklist, strict gate order, runtime permissions, migration order, and post-upload smoke expectations exist. Actual live `.env`, fresh backups, provider credentials, and warning burn-down remain pending. |

## Defaults Matrix

| Area | Current Production Default | Evidence | Production Risk | Next Action |
| --- | --- | --- | --- | --- |
| Workspace context | Default workspace is `Clarity Platform Operations HQ` with `workspace_purpose=platform_ops`; registry-backed Platform Ops context now exists for AI context, System Health, owner-contact sync, template baselines, owner policy scopes, and domain automation defaults. System Health validates required registry sections, default workspace settings, template baseline, owner scopes, and concrete dummy language. | `SystemContextRegistryService`; `SystemReadinessService` `system_context_registry`; migration `489_repair_default_workspace_production_baseline.sql`; System Health default workspace and context registry checks. | Some older services and UI copy still read settings/migration assumptions directly. | Continue migrating callers onto the registry and add validation for remaining context consumers. |
| Default workspace boundary | Default workspace is for existing customer/trial owner nurturing, billing, onboarding, support, security, and tenant ops. | Migration 489 settings, onboarding launch summary, operating brief. | Some UI copy and older service assumptions may still treat default workspace as generic CRM/sales. | Audit default workspace copy and workflow entry points for generic sales assumptions. |
| Demo/test data | Known smoke/presentation contacts, notifications, and deals in default workspace are removed; read-only demo quarantine verification checks default workspace contamination from `demo_visibility`/`demo_session_id`, concrete protected-demo/MetroDrive/presentation markers, demo sessions/entities, presentation rows, and demo guest access. Valid default-workspace owner conversion pipeline deals are no longer treated as smoke unless concrete demo/presentation markers are present. The production cleanup verifier summarizes default/protected demo rows, public setup artifacts, and staged generated files in one release-facing report. | Migrations 489 and 498 cleanup; preflight `smoke_contacts`/`smoke_deals` checks; `DemoQuarantineVerificationService`; `ProductionCleanupVerificationService`; `scripts/verify_demo_quarantine.php`; `scripts/verify_production_cleanup.php`; Composer `demo:quarantine`, `cleanup:verify`; System Health `demo_quarantine`. | Demo systems still exist and may seed protected demo and presentation workspaces. The verifiers observe and report only; they do not move or delete data. | Review any `demo:quarantine` or `cleanup:verify` findings, quarantine/remove contaminated default-workspace rows after approval, and later connect stable findings to Super Admin remediation proposals. |
| Public installer | Public `install_once.php` has been removed. | Commit `df014bcc`; preflight blocks if it returns; security hardening and production cleanup audits flag setup/test/debug/migrate/seed script candidates. | Any future public installer or one-time setup endpoint would be high risk. | Remove or gate flagged setup/test public scripts after reviewing each warning. |
| Runtime paths | Required ignored runtime paths are `cache`, `logs`, `tmp`, `uploads`. | `RuntimePathService`, `scripts/prepare_runtime_paths.php`, System Health writable paths check. | Server permissions can still differ from local. | Keep deploy script running `runtime:prepare`; verify permissions after upload. |
| Environment | `.env` is required; preflight warns if `APP_URL` is missing/local, `APP_ENV` is not production, or debug is enabled. Live upload checklist documents required production keys and forbids uploading filled env files. | `SystemReadinessService::productionEnvironmentSummary`; `env.production.template`; `docs/live-upload-checklist.md`. | Local `.env` currently lacks `APP_URL`; live `.env` must set the final HTTPS URL and real secrets on the server. | Create live `.env` from `env.production.template`, fill host/provider secrets, and run strict preflight on the live server before opening traffic. |
| Database migrations | All local migration files are applied through 498. | `composer run migrate`; System Health migration check. | New migrations must always be run after creation. | Continue using `composer run migrate` after migration creation; include migration run log in deployment report. |
| Production preflight | Browser and CLI gates exist. Standard mode fails critical blockers; strict mode also fails warnings. Local standard mode currently exits 0 with no blockers when backup offsite/restore-drill env values are supplied. A read-only automation detector runner can convert preflight, integration, communication, queue/job, billing, RBAC, workspace health, marketplace/template, and AI runtime signals into Super Admin evidence runs. System Health includes the context registry check, review queue with repeated-run refresh counts, escalation state, urgent/overdue Super Admin notifications, template validation counts/findings, security hardening audit counts/findings, integration readiness domains/findings, demo quarantine counts/findings, and backup/restore readiness evidence. | `public/system_health.php`, `api/automation_review_queue.php`, `scripts/production_preflight.php`, `scripts/run_automation_detectors.php`, `scripts/validate_templates.php`, `scripts/security_hardening_audit.php`, `scripts/check_integration_readiness.php`, `scripts/verify_demo_quarantine.php`, `scripts/verify_production_cleanup.php`, `scripts/check_backup_restore_readiness.php`, Composer `preflight:production`, `templates:validate`, `security:audit`, `integrations:check`, `demo:quarantine`, `cleanup:verify`, `backup:check`, and `automation:detectors`. | Strict mode will intentionally fail until live env warnings/security/integration warnings are cleared. Current local warnings are missing `APP_URL`, Google/calendar provider credentials, public setup/debug artifacts, and security hardening audit findings. Detectors, context validation, template validation, security hardening audit, integration readiness, demo quarantine, production cleanup verification, and backup/restore readiness remain observe/suggest/prepare only, except migration 497's RBAC baseline repair and migration 498's narrow readiness repairs. | Use standard mode locally; use strict mode on deployment after setting live env values. Remove/gate public endpoint warnings, configure live Google/calendar if calendar automation is needed, and add remediation automation only after evidence is stable. |
| Deployment script | Production deploy helper now installs production dependencies, prepares runtime paths, applies baseline permissions, checks backup readiness before migrations, runs migrations, executes readiness gates, probes DB connectivity, and performs an HTTP health check when `APP_URL` is set. Legacy `scripts/deploy.sh` delegates to it. | `scripts/deploy_to_production.sh`; `scripts/deploy.sh`; `docs/live-upload-checklist.md`. | Script still depends on the host having shell, Composer, PHP, and a complete live `.env`; manual upload hosts must follow the checklist steps explicitly. | Use `docs/live-upload-checklist.md` for manual uploads; use `scripts/deploy_to_production.sh` only after live `.env`, backups, and provider settings are ready. |
| Email templates | Default workspace has 11 active Platform Ops templates. Generic default email templates are inactive/cleared for default workspace. Email template validator checks empty subject/body, invalid JSON, undeclared/missing placeholders, demo language, and active generic defaults. | Migration 489; preflight counts `platform_ops_email_templates` and `stale_email_templates`; `TemplateValidationService`; `composer run templates:validate`; System Health `template_validation`. | WhatsApp, invoice, marketing reusable, and draft-template scopes still need validation. Older library templates can still create warnings that require review before strict live upload. | Extend validator to remaining template channels and connect stable findings to Super Admin review automation. |
| Workflow templates | Default workspace has 3 Platform Ops workflow templates; starter public workflow templates remain as intentional starter content. Workflow template validator checks invalid JSON, empty actions, and unusable send-email actions/placeholders. | Migration 489; preflight counts `platform_ops_workflow_templates` and `starter_workflow_templates`; `TemplateValidationService`; System Health `template_validation`. | Need distinguish intentional starter catalog from workspace-specific production recipes in deeper remediation flows. | Document curated public starter catalog and add duplicate/unsupported workflow remediation after evidence stabilizes. |
| Auto Admin platform | Platform Auto Admin is enabled and default workspace Auto Admin is enabled. | Migration 489; `workspace_auto_admin_settings`. | Auto Admin has broad power and needs cataloged controls before more automation is enabled. | Build Phase 6 automation catalog with risk level, mode, evidence, rollback, and kill switch. |
| Auto Admin modes | Default effective modes: deal `suggest_only`, workflow `auto_safe`, autoresponder `draft_only`, commercial `auto_safe`. | Migration 489; preflight automation safety summary. | Some services may still have legacy global config fallbacks. | Audit global config fallbacks and ensure workspace-scoped config is authoritative. |
| Cold outreach | Default workspace cold outreach warmup is disabled for email and WhatsApp. | Migration 489; preflight blocks enabled cold outreach. | Future onboarding or settings changes could re-enable it without review. | Add automation detector for cold outreach enabled in Platform Ops workspace. |
| Commercial automation | Enabled in `auto_safe`, but customer-facing `auto_send_enabled=0`. | Migration 489; preflight blocks commercial auto-send. | Full-auto/customer-facing sends are high-risk without approval. | Require approval-gate evidence before any customer-facing auto-send promotion. |
| AI autoresponder | Default workspace autoresponder mode is `draft_only`. | Migration 489; preflight blocks `full_auto`. | Hybrid/full-auto can affect customers directly. | Keep full-auto blocked by default; add detector for unsafe autoresponder promotions. |
| Deal automation | Default workspace deal automation is `suggest_only`. | Migration 489; preflight warns/blocks unsafe modes. | Full-auto stage changes require rollback and audit confidence. | Keep rollback path visible; add automation mode drift detector. |
| Workflow autonomy | Workflow execution domain is `auto_safe`, not full-auto. | Migration 489; `ai_autonomy_domain_controls`. | Customer-facing workflow actions need a clearer human checkpoint model. | Formalize automation policy levels and customer-facing risk gates. |
| Roles and permissions | Canonical Super Admin role is active, has all permissions, high-risk permissions are sensitive, and known non-Super-Admin platform/operator grants are removed. | `SecurityRoleHardeningAuditService`; migration `497_repair_security_role_hardening_baseline.sql`; Composer `security:audit`; System Health `security_hardening`; `permission_drift_detector` and `superadmin_access_repair_suggestion`. | Audit currently reports warnings for public endpoints, setup/test scripts, some non-Super-Admin sensitive grants, export/admin authorization patterns, and Super Admin accounts without 2FA. | Review warnings one by one, enforce 2FA for Super Admins, and gate/remove public setup/export/admin actions before live upload. |
| Marketplace/plugins | Marketplace and skill catalog exist; default workspace products/services were seeded as Platform Ops assets. | `WorkspaceSkillCatalogService`, migration 489 products; `broken_marketplace_setup_detector` now checks setup/bundle evidence. | Visibility rules still need a full production matrix; detector only surfaces broken setup signals. | Add marketplace visibility matrix and later approved repair actions. |
| Billing gates | Platform Ops billing/service products were seeded; finance and billing surfaces exist. | Migration 489 products; billing services; `billing_package_mismatch_detector` now checks subscription/price/wallet signals. | Live payment provider mode, plan codes, webhooks, and package defaults still need live confirmation. | Add billing/package remediation actions and live payment checklist after review evidence stabilizes. |
| Integrations | Dedicated read-only integration readiness checks now cover SMTP/email, WhatsApp, Google/calendar, Paystack/M-Pesa/payment modes, AI provider, cron/job health, and runtime queues. Missing/broken credentials, expiring OAuth tokens, stale jobs, and queue backlogs surface in CLI and System Health without provider calls or secret exposure. Credentialless Paystack/M-Pesa defaults are opt-in and the broken enabled calendar row has been disabled/reconnect-required. | `IntegrationReadinessService`; migration 498; `scripts/check_integration_readiness.php`; Composer `integrations:check`; System Health `integration_readiness`; `integration_credential_missing_detector`; `expiring_integration_credential_warning`. | Current local integration readiness has one warning: Google/calendar provider credentials are not configured and no ready enabled calendar integration exists. Readiness checks do not reconnect OAuth grants or verify providers over the network. | Configure live Google/calendar OAuth credentials if calendar automation is needed, confirm SMTP/WhatsApp/payment/AI live values, confirm cron workers, and run strict checks before upload. |
| Backups/restore | Read-only backup/restore readiness checks verify database backup freshness, uploads archive coverage, restore tooling, retention, backup location, offsite configuration, restore drill date, and live runbook presence. Fresh local DB and uploads artifacts were created under ignored `backups/`, and backup readiness is green when live-operator offsite and restore-drill env values are supplied. | `BackupRestoreReadinessService`; `scripts/check_backup_restore_readiness.php`; Composer `backup:check`; System Health `backup_restore_readiness`; `docs/backup-restore-readiness.md`; `env.production.template`. | The checker does not upload offsite copies or perform a restore. Live host backup scheduling and offsite storage still need real provider configuration. | Configure live `BACKUP_DIR`, offsite storage, and restore-drill date; run `composer run backup:check -- --json`; perform a staging restore drill before upload. |
| Logs/audit | Runtime `logs` path is prepared; multiple audit/event tables exist. | `RuntimePathService`; existing audit services. | Automation-specific evidence and review queues are not unified. | Build automation execution log and superadmin review queue in Phase 6. |

## Must Remove Or Quarantine Before Live Upload

| Item | Current State | Handling |
| --- | --- | --- |
| `public/install_once.php` | Removed. | Preflight critical blocker if present again. |
| Default workspace smoke contacts using `@example.test`/`@demo.local.invalid` patterns | Removed for exact known Phase 2 patterns. | Preflight critical blocker if found in default workspace. |
| Default workspace smoke/presentation deals | Removed for exact known Phase 2 patterns; operational owner conversion pipeline deals are allowed when they lack concrete demo/presentation markers. | Preflight critical blocker if demo/presentation markers are found in default workspace deals. |
| Public command-runner/setup scripts | Security audit now flags public setup/test/debug/migrate/seed candidates. | Review and remove, gate, or document each warning before live upload. |
| Generated screenshots/logs/temp artifacts | `.gitignore` covers common paths. Some old tracked screenshots remain outside this hardening scope. | Do not delete tracked artifacts without a separate cleanup decision. |
| Demo workspace data | Useful demo features still exist. | Preserve only in protected demo workspace/session/presentation scopes; verify with `composer run demo:quarantine -- --json` before live upload. |

## Production Gaps

| Gap | Phase | Recommended Priority |
| --- | --- | --- |
| Expand registry adoption across remaining services and UI copy. | Phase 2 | High |
| Extend template validation to WhatsApp, invoice, marketing reusable, and draft templates. | Phase 4/9 | High |
| Resolve security hardening audit warnings: Super Admin 2FA, public endpoints, setup/test scripts, export/admin gates. | Phase 5/9 | High |
| Resolve integration readiness findings: live Google/calendar provider values if calendar automation is enabled, plus final live SMTP/WhatsApp/payment/AI confirmation. | Phase 5/9 | High |
| Strict preflight pass with real live `.env`, provider credentials, current backups, and security warning burn-down. | Phase 9/10 | High |
| Demo workspace quarantine remediation proposals after read-only verification stabilizes. | Phase 3/9 | Medium |
| Marketplace/plugin visibility and broken setup checks. | Phase 5/7 | Medium |
| Backup remediation automation after backup/restore readiness evidence stabilizes. | Phase 9/10 | Medium |
| Execute the finalized live upload checklist on the target host and record smoke evidence. | Phase 10 | High |

## Current Verification Commands

Run these before live upload or before continuing hardening work:

```bash
composer run runtime:prepare
composer run migrate
composer run preflight:production -- --json
composer run templates:validate -- --json
composer run security:audit -- --json
composer run integrations:check -- --json
composer run demo:quarantine -- --json
composer run cleanup:verify -- --json
composer run backup:check -- --json
composer run automation:detectors -- --dry-run --json
composer run preflight:production -- --strict
composer run lint:php
vendor/bin/phpunit tests/Unit/Services/RuntimePathServiceTest.php tests/Unit/Services/SystemReadinessServiceTest.php tests/Unit/Services/TemplateValidationServiceTest.php tests/Unit/Services/SecurityRoleHardeningAuditServiceTest.php tests/Unit/Services/IntegrationReadinessServiceTest.php tests/Unit/Services/DemoQuarantineVerificationServiceTest.php tests/Unit/Services/BackupRestoreReadinessServiceTest.php tests/Unit/Services/AutomationCatalogServiceTest.php tests/Unit/Services/AutomationDetectorExecutionServiceTest.php tests/Unit/Services/AutomationReviewQueueServiceTest.php tests/Unit/Scripts/ProductionPreflightScriptTest.php tests/Unit/Frontend/SystemHealthPageTest.php tests/Unit/Documentation/LiveUploadChecklistTest.php tests/Unit/Api/AutomationReviewQueueApiTest.php tests/Integration/AutomationReviewQueueEndpointTest.php --stop-on-failure
```

For manual live deployment after upload, dependency install, runtime prep, fresh backups, and migrations:

```bash
php scripts/production_preflight.php --strict
```

For shell-capable hosts where the deployment helper owns those steps:

```bash
bash scripts/deploy_to_production.sh
```

## Recommended Next Implementation

The live upload runbook now exists. The next implementation should burn down the highest-risk warning backlog before executing it on a live host:

1. Gate or remove public setup/test/debug/migrate/seed scripts reported by `composer run security:audit -- --json`.
2. Repair or disable broken active calendar provider rows and configure live SMTP/WhatsApp/payment/AI values reported by `composer run integrations:check -- --json`.
3. Run `composer run demo:quarantine -- --json` and review any protected-demo/default-workspace contamination findings before live upload.
4. Run `composer run backup:check -- --json`, create fresh DB/uploads backups, configure offsite backup storage, and record a restore drill date.
5. Require or repair Super Admin 2FA enrollment before production.
6. Review export/admin/public endpoint warnings and add explicit authorization/CSRF gates where missing.
7. Extend template validation to the remaining template channels and add approved remediation proposals once evidence stabilizes.
