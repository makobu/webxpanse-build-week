# Auto Admin Functionality Audit

Audit date: 2026-06-17

Scope: Auto Admin as the system-level control for managed AI and automation defaults. This audit reviewed the toggle path, managed default application, manual-control locks, safety gates, and adjacent test coverage. It did not include a full audit of unrelated admin features.

## Summary

Auto Admin is implemented as a global preference stored under `user_preferences.user_id = 1`, with `AutoAdminService` applying defaults across user preferences, AI responder config, commercial automation, cold outreach warmup, deal automation, workflow automation, and runtime controls.

The high-level lock model exists: managed settings tabs are blocked in `public/settings.php`, the workflow settings API returns `423` while managed, the deal mode API returns `423` while managed, and the AI Control Center blocks runtime-control edits while Auto Admin is enabled.

The main issues are logical consistency gaps between UI and API paths, partial enforcement for cold outreach warmup, and an AI Coach default that writes to a preference path no longer used by the workspace-level AI Coach state.

## Findings

### P1 - Settings form can promote deal automation to full auto without readiness gates

Evidence:
- `api/deals/automation_mode.php:72` blocks `full_auto` when `DealAutomationReadinessService::canPromoteToFullAuto()` is false.
- `public/settings.php:1551` saves `deal_automation_mode` directly through `DealAutomationConfig::save()`.
- `public/settings.php:8215` exposes `full_auto` in the settings form.

Expected behavior: every path that promotes deal automation to `full_auto` should apply the same readiness gate or require an explicit override with audit metadata.

Actual behavior: the API path enforces readiness, but the settings POST path does not. A settings user with `settings.deal_automation` can save `full_auto` even when audit history, transition rules, dry-run status, or terminal-stage safety are not ready.

Impact: high. This bypasses the primary safety gate for customer-impacting deal stage automation.

Recommended fix: centralize deal automation mode changes in one service method used by both `public/settings.php` and `api/deals/automation_mode.php`. Require readiness for `full_auto` unless a separate Super Admin-only force path is deliberately added with audit logging.

Test gap: add a settings POST/integration test proving `full_auto` is rejected when `DealAutomationReadinessService` reports blockers, plus an API parity test.

### P1 - Auto Admin warmup can be disabled from unmanaged Email and WhatsApp tabs

Evidence:
- `AutoAdminService::getManagedDefaults()` enables email and WhatsApp warmup with `auto_admin_warmup_enabled = true` in `services/AutoAdminService.php:89` and `services/AutoAdminService.php:97`.
- `public/settings.php:1052` and `public/settings.php:1101` only lock numeric warmup fields when Auto Admin warmup is active.
- `public/settings.php:1054`, `public/settings.php:1055`, `public/settings.php:1103`, and `public/settings.php:1104` still save the channel `enabled` and `auto_admin_warmup_enabled` checkboxes from POST.
- The UI explicitly leaves those toggles editable at `public/settings.php:3521`, `public/settings.php:3525`, `public/settings.php:4715`, and `public/settings.php:4719`.

Expected behavior: when Auto Admin is enabled, all Auto Admin-owned warmup controls should either remain managed or clearly exit managed mode through the Auto Admin toggle.

Actual behavior: an admin can keep global Auto Admin enabled but turn off the channel cap or Auto Admin warmup on the Email/WhatsApp tabs, leaving managed mode in a partially disabled state.

Impact: high. Auto Admin can appear active while brand-protection warmup is off, and the Automation Battery may overstate managed automation safety.

Recommended fix: while global Auto Admin is enabled, preserve `enabled` and `auto_admin_warmup_enabled` from the locked config in Email/WhatsApp POST handling. If local opt-out is desired, model it explicitly as a separate, audited exception rather than a normal tab save.

Test gap: add settings POST tests for email and WhatsApp confirming warmup toggles cannot be changed while global Auto Admin is active.

### P2 - Auto Admin AI Coach default writes to the wrong control plane

Evidence:
- Auto Admin defaults include `ai_coach_enabled = true` in `services/AutoAdminService.php:73`.
- `applyManagedDefaults()` writes that value through `UserPreferences::setAICoachEnabled()` for each user in `services/AutoAdminService.php:187`.
- The settings page reads AI Coach status from `AICoachWorkspaceSetupService::isWorkspaceEnabled($activeWorkspaceId)` in `public/settings.php:549`.
- `AICoachWorkspaceSetupService::isWorkspaceEnabled()` resolves the workspace skill install config, not per-user preferences, in `services/AICoachWorkspaceSetupService.php:19`.

Expected behavior: Auto Admin's AI Coach default should enable the same workspace-level AI Coach state that the UI and runtime readiness paths use.

Actual behavior: Auto Admin updates legacy/per-user AI Coach preferences, but the visible workspace status can remain disabled if the AI Coach skill install config is disabled or absent.

Impact: medium. Auto Admin can report that managed defaults were applied while AI Coach remains off for the active workspace.

Recommended fix: have Auto Admin call `AICoachWorkspaceSetupService::setWorkspaceEnabled()` for the active/default workspace, or remove `ai_coach_enabled` from managed defaults if workspace Marketplace setup is intentionally separate.

Test gap: add a service/integration test that enables Auto Admin and verifies `AICoachWorkspaceSetupService::isWorkspaceEnabled()` becomes true for the intended workspace.

### P2 - Global Auto Admin state is stored as a user preference for hardcoded user 1

Evidence:
- `AutoAdminService::GLOBAL_USER_ID` is hardcoded to `1` in `services/AutoAdminService.php:14`.
- `isEnabled()` and `setEnabled()` read/write `auto_admin_enabled` through `UserPreferences` for that user in `services/AutoAdminService.php:32` and `services/AutoAdminService.php:42`.
- `UserPreferences::setPreference()` silently no-ops on persistence errors in `modules/UserPreferences.php:75`.

Expected behavior: a platform-wide automation control should live in an explicit system/platform settings table with reliable persistence semantics and audit metadata.

Actual behavior: the global control is tied to an arbitrary user id and inherits user preference behavior, including silent write failure when the table is unavailable.

Impact: medium. This makes the control fragile around user lifecycle, installs where user 1 is not the platform admin, duplicate preference cleanup, and operational auditing.

Recommended fix: move Auto Admin enabled state to a platform settings table or existing system settings mechanism, and log enable/disable events with actor id and before/after state. Keep a migration/backfill from `user_preferences` if this ships.

Test gap: add tests for missing user id 1, duplicate preference rows, failed persistence, and actor audit metadata.

### P3 - Managed tab navigation is labeled but not redirected

Evidence:
- `AutoAdminService::normalizeRequestedSettingsTab()` can redirect managed tabs to the first unlocked tab in `services/AutoAdminService.php:144`.
- `public/settings.php` does not call that method; managed tabs remain visible with a badge at `public/settings.php:2529`.
- Managed POSTs are blocked server-side at `public/settings.php:966`, and controls are disabled in individual tab UIs.

Expected behavior: either managed tabs should be intentionally readable, or the existing redirect helper should be used consistently.

Actual behavior: the service exposes redirect behavior that is not wired into the settings page. Product copy is also mixed: older disabled copy says managed tabs are hidden, while current UI leaves them visible.

Impact: low. The server-side lock exists, but the unused helper and mixed copy make future behavior harder to reason about.

Recommended fix: choose one behavior. If readable managed tabs are desired, remove or stop exposing the redirect helper and update copy. If hidden/redirected tabs are desired, wire `normalizeRequestedSettingsTab()` into settings tab resolution.

Test gap: add a smoke/source test that locks the chosen navigation behavior.

## Confirmed Controls That Work

- Auto Admin toggle submission is restricted to Super Admin in `public/settings.php:943`.
- Managed settings sections are blocked server-side in `public/settings.php:966`.
- Workflow automation API returns `423` while Auto Admin manages workflow automation in `api/workflows/settings.php:52`.
- Deal automation mode API returns `423` while Auto Admin manages deal automation in `api/deals/automation_mode.php:65`.
- AI Control Center blocks runtime-control POSTs while Auto Admin is enabled in `public/ai_control_center.php:55`.
- Auto Admin clears existing runtime controls after applying managed defaults in `services/AutoAdminService.php:251`.

## Test Execution

Attempted commands:

```powershell
vendor\bin\phpunit.bat tests\Unit\Services\AutomationBatteryServiceTest.php tests\Unit\Services\WorkflowAutomationGenerationServiceTest.php tests\Unit\Services\DealAutomationRulesEngineTest.php tests\Unit\Modules\AIAutoResponderConfigTest.php tests\Unit\Services\CommercialAutomationPolicyServiceTest.php
vendor\bin\phpunit.bat tests\Unit\Services\AutomationBatteryServiceTest.php
vendor\bin\phpunit.bat tests\Unit\Services\WorkflowAutomationGenerationServiceTest.php tests\Unit\Services\DealAutomationRulesEngineTest.php tests\Unit\Modules\AIAutoResponderConfigTest.php tests\Unit\Services\CommercialAutomationPolicyServiceTest.php
vendor\bin\phpunit.bat --no-coverage tests\Unit\Modules\AIAutoResponderConfigTest.php
npx playwright test tests/smoke/settings.smoke.spec.js tests/smoke/ai-settings-rate-limit.smoke.spec.js --project=chromium --workers=1 --timeout=60000
npx.cmd playwright test tests/smoke/settings.smoke.spec.js tests/smoke/ai-settings-rate-limit.smoke.spec.js --project=chromium --workers=1 --timeout=60000
```

Results:
- PHPUnit is installed and responds to `vendor\bin\phpunit.bat --version`.
- Playwright is installed and responds to `npx.cmd playwright --version`.
- The targeted PHPUnit runs timed out at 2-3 minutes without producing assertion output.
- The first Playwright attempt was blocked by PowerShell script execution policy for `npx.ps1`.
- The `npx.cmd` Playwright attempt also timed out at 3 minutes.

Environment note: timed-out phpunit/playwright processes from this audit were stopped after inspection. Existing unrelated dirty files and prior workspace processes were left untouched.

## Recommended Next Work

1. Fix the P1 safety bypass in deal automation settings.
2. Fix the P1 warmup partial-management issue.
3. Decide whether Auto Admin should own workspace AI Coach enablement or leave it to Marketplace setup.
4. Move global Auto Admin state out of `user_preferences.user_id = 1`.
5. Add dedicated Auto Admin tests for enable/disable, managed locks, API `423` responses, full-auto readiness parity, warmup lock parity, workspace scoping, and cache invalidation.
