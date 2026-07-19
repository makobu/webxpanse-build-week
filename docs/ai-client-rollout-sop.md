# AI Client Rollout SOP

## Purpose

This SOP defines:
- the recommended initial AI settings baseline
- the runtime-control baseline
- the rollout sequence to use for each client
- the launch gate and weekly review checklist

Use this SOP for every new client install unless there is a documented exception.

## Default Baseline Settings

### AI governance

- AI context strictness: `strict`
- Lock AI mode: `auto`
- Minimum confidence for AI advice: `0.90`
- Minimum confidence for AI actions: `0.94`
- Minimum goal relevance for proactive guidance: `0.72`
- Allow AI auto-complete tasks: `enabled`
- Auto-complete confidence threshold: `0.97`
- Enable autonomous AI threshold tuning: `enabled`

### Incident alerting

- Enable AI incident alerts: `enabled`
- Enable AI incident checks: `enabled`
- Medium cooldown: `360` minutes
- High cooldown: `240` minutes
- Critical cooldown: `120` minutes

## Runtime Control Baseline

Set these in the AI Control Center at first deployment:

- Global: `normal`
- Coach: `normal`
- Clarity Chat: `normal`
- Assistant: `suggest_only`
- Customer Thread: `suggest_only`
- Commercial Assistant: `suggest_only`
- Task Automation: `normal`
- Autonomous Tuning: `diagnostics_only`

## Client Rollout Tiers

### Tier A: Early-stage / low-process client

Use when:
- low data quality
- few users
- no dedicated operator
- incomplete products, pipeline, or invoicing setup

Starting posture:
- Assistant: `suggest_only`
- Customer Thread: `suggest_only`
- Commercial Assistant: `suggest_only`
- Autonomous Tuning: `diagnostics_only`

### Tier B: Operationally mature client

Use when:
- good deal/contact hygiene
- invoicing configured
- operator available
- scheduler and alerts monitored

Starting posture:
- Assistant: `suggest_only`
- Customer Thread: `suggest_only`
- Commercial Assistant: `suggest_only`
- Autonomous Tuning: `diagnostics_only`

Promotion to normal can happen earlier than Tier A after review.

### Tier C: High-volume / high-risk client

Use when:
- larger team
- higher message volume
- revenue-impacting automation is active
- rapid issue propagation is possible

Starting posture:
- Assistant: `suggest_only`
- Customer Thread: `suggest_only`
- Commercial Assistant: `suggest_only`
- Autonomous Tuning: `diagnostics_only`
- Keep stricter review cadence and do not accelerate rollout without explicit signoff.

## Standard Rollout Sequence

### Stage 0: Internal validation

Required before client-visible rollout:
- migrations complete
- all scheduler jobs running
- incident alerts tested
- prompt rollback tested
- AI Control Center accessible
- diagnostics workbench accessible

Keep:
- Assistant: `suggest_only`
- Customer Thread: `suggest_only`
- Commercial Assistant: `suggest_only`
- Autonomous Tuning: `diagnostics_only`

### Stage 1: Guided usage

Enable:
- Coach
- Clarity Chat
- Task Automation

Keep:
- Assistant: `suggest_only`
- Customer Thread: `suggest_only`
- Commercial Assistant: `suggest_only`

Daily review:
- AI incidents
- blocked spikes
- prompt warnings
- stale jobs
- approval spikes

Minimum duration:
- 5 business days

### Stage 2: Limited execution

Move to:
- Assistant: `normal`

Keep:
- Customer Thread: `suggest_only`
- Commercial Assistant: `suggest_only`
- Autonomous Tuning: `diagnostics_only` unless review is clean

Promotion requirements:
- no unresolved high AI incidents
- no prompt regression high risk
- no stale calibration or reconciliation jobs
- low blocked spike rate

Minimum duration:
- 5 business days

### Stage 3: Controlled client-facing execution

Move to:
- Customer Thread: `normal`

Keep Commercial Assistant on `suggest_only` until:
- invoicing is configured
- contact/deal linkage is reliable
- approval workflow is active
- rejection and reversal rates are acceptable

Minimum duration:
- 5 business days

### Stage 4: Commercial execution

Move to:
- Commercial Assistant: `normal`
- Autonomous Tuning: `normal`

Only if:
- no unresolved high incidents
- approval rejection rate is acceptable
- prompt regression is not active
- operator is actively monitoring incidents and diagnostics

## Hard Promotion Rules

Do not broaden a client rollout unless all of the following are true:

- incident alerts are configured and tested
- `ai_incident_check.php` is running every 15 minutes
- `ai_outcome_reconciliation.php` is healthy
- `ai_confidence_calibration.php` is healthy
- `workflow_scheduler.php` is healthy
- `ai_runtime_control_cleanup.php` is healthy
- no unresolved `high` or `critical` AI incidents
- prompt rollback has been tested
- diagnostics and control center are accessible to the operator

## Launch Gate Checklist

Before launch for any client, confirm:

- company profile completed
- products and pricing configured
- invoicing configured if commercial assistant will be used
- email/inbox working
- permissions and admin access verified
- prompt control page accessible
- diagnostics page accessible
- incident workbench accessible
- alert channels validated
- runtime controls tested

## Weekly Review Checklist

Review once per week per active client:

- active incidents count
- blocked decision spikes
- approval-required spikes
- prompt regression warnings
- stale-context and overload rates
- scheduler health
- autonomous tuning changes in last 7 days
- fallback usage trends
- commercial approval rejection rate

## Safe Mode Policy

If a client experiences repeated degradation:

Set immediately:
- Assistant: `suggest_only`
- Customer Thread: `suggest_only`
- Commercial Assistant: `suggest_only`
- Autonomous Tuning: `diagnostics_only`

If issues persist:
- pause affected surface
- keep diagnostics active
- investigate incidents, prompt regression, job health, and prompt rollback options

## Recommended Default Profile

Use this as the default profile for most clients:

- AI context strictness: `strict`
- AI mode lock: `auto`
- Advice confidence: `0.90`
- Action confidence: `0.94`
- Goal relevance: `0.72`
- Auto task completion confidence: `0.97`
- Assistant: `suggest_only`
- Customer Thread: `suggest_only`
- Commercial Assistant: `suggest_only`
- Autonomous Tuning: `diagnostics_only`

This is the default launch-safe baseline.
