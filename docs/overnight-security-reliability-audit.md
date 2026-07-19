# Overnight Security And Reliability Audit

Date: 2026-05-15

## Completed

- Removed production-looking database and SMTP secrets from deployment docs and environment templates.
- Removed tracked `php_errorlog` files that exposed production database identifiers, and ignored future `php_errorlog` artifacts.
- Replaced runtime hardcoded `/crm/public` and `/crm/api` URL construction with `publicUrl()` and `apiUrl()`.
- Tightened Email Assistant resolver and thread context lookups so active workspace scope is applied before resolving deals, contacts, invoices, approvals, and communications.
- Added a regression test proving Email Assistant does not resolve foreign-workspace deal/thread records.
- Made mobile payload construction fall back from stale/inactive workspace hints instead of failing the whole payload.
- Made tenant endpoint drift tests drop foreign keys by actual metadata name instead of assuming one constraint name.
- Reworked database tests to create one migrated template database per test process and clone it for individual test databases.
- Added CI gates for Composer validation, Composer audit, PHP lint, migrations, and a named fast workspace isolation smoke suite.

## Verification

- `composer validate --strict --no-check-publish`: passed.
- `composer audit`: passed, no advisories found.
- `composer lint:php`: passed.
- `composer test:workspace-smoke`: passed, 33 tests / 142 assertions in 4:42.
- Current-tree secret grep for the known leaked values: clean.
- Current PHP grep for `/crm/public` and `/crm/api`: clean.
- Full `composer test`: timed out after 15 minutes and remains a reliability target.

## High Risk / Needs Decision

1. Git history still contains the leaked values in commit `61eb8f3`.
   - Current tree is scrubbed, but a true history scrub requires coordinated history rewrite and force-push.
   - Rotate the exposed DB, SMTP, and any adjacent hosting credentials before rewriting history.
   - Recommended command path after rotation and team freeze: use `git filter-repo` with replacements, then force-push protected branches and invalidate old clones.

2. Full PHPUnit runtime is still too slow for a normal CI gate.
   - The workspace smoke suite is now usable, but full test runtime exceeded 15 minutes locally.
   - Next step is splitting slow integration/browser/network-style tests from deterministic unit/integration suites.

3. Workspace isolation should keep expanding around async and assistant flows.
   - Resolver, invoice endpoints, mobile token payloads, workflow queue mismatches, and tenant endpoints now have smoke coverage.
   - Remaining best targets are scheduled workflow triggers, commercial automation approvals, email assistant execution mutations, and AI cross-domain orchestration.

## Run Commands

```bash
composer install --no-progress --prefer-dist --optimize-autoloader
composer validate --strict --no-check-publish
composer audit
composer lint:php
php database/migrations/migrate.php
composer test:workspace-smoke
composer test
```

## Next Overnight Tasks

1. Perform the coordinated credential rotation and history rewrite after a short branch freeze.
2. Split `composer test` into fast deterministic suites and slow quarantined suites, then make the deterministic suite pass under a hard timeout.
3. Add workspace smoke cases for scheduled workflow workers and email assistant execution writes.
4. Add a pre-commit or CI secret grep for the known leaked values and common `.env` credential patterns.
5. Remove or regenerate any remaining tracked operational artifacts that are not source code.
