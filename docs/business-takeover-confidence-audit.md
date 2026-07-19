# Business Takeover Confidence Audit

This audit gives one conservative answer to a high-risk question: is the CRM ready to learn and safely take over broad business operations without hiding regressions behind infrastructure noise.

## What It Checks

The audit is read-only in v1 and scores these fixed domains:

- `foundations`
- `learning`
- `customer_facing`
- `internal_ops`
- `runtime`
- `governance`

The result always includes:

- `overall_status`
- `failure_class`
- `platform_score`
- `takeover_ready`
- `domains`
- `hard_gates`
- `recommended_actions`
- `verification_snapshot`

## Local Usage

Run the human-readable report:

```bash
composer audit:takeover
```

Run the machine-readable report:

```bash
php cli/business_takeover_audit.php --json
```

Scope the audit to one operator or one lane:

```bash
php cli/business_takeover_audit.php --user-id=123 --scope=all
php cli/business_takeover_audit.php --user-id=123 --scope=customer_facing
php cli/business_takeover_audit.php --user-id=123 --scope=internal_ops
```

## Exit Codes

- `0`: audit passed and takeover readiness is true
- `2`: blocked by environment prerequisites only
- `3`: blocked by product regressions only
- `4`: blocked by a mix of prerequisite and product issues

## Failure Class Guide

- `none`: all hard gates passed
- `environment_prerequisite`: runtime trust is blocked by prerequisites such as DB connectivity, provider configuration, or unhealthy workers
- `product_regression`: the app is reachable, but the business evidence, rollout state, or governance controls are below the required gate
- `mixed`: both prerequisite and product issues are present

`verification_snapshot` intentionally preserves transient prerequisite drift. For example, a configured database host may succeed while `localhost` or `127.0.0.1` intermittently fails. That should be visible in the snapshot instead of being flattened into a generic pass/fail story.

## Repeatable Validation

Run the focused PHP suite:

```bash
composer test:takeover
```

Run the broad browser continuity check:

```bash
npm run test:takeover:smoke
```

## CI Usage

Recommended pipeline order:

1. `composer test:takeover`
2. `php cli/business_takeover_audit.php --json`
3. `npm run test:takeover:smoke`

Use the CLI exit code for gating and archive the JSON output so the domain scores, hard gates, and verification snapshot are easy to review.

## Scheduled Execution

Examples:

- Cron on Linux:

```bash
0 */6 * * * cd /path/to/crm && php cli/business_takeover_audit.php --json
```

- Windows Task Scheduler:

```powershell
php C:\path\to\crm\cli\business_takeover_audit.php --json
```

If the environment has shown transient DB drift before, schedule the audit often enough to catch that volatility in `verification_snapshot`.

## Notes

- v1 does not execute new autonomous actions.
- v1 does not require schema changes.
- The audit is meant to be conservative. A single hard-gate failure blocks takeover readiness.
