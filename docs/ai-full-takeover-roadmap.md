# AI Full-Takeover Roadmap

## Summary
Goal: evolve the CRM from threshold-gated automation into a system that learns from operator and client behavior well enough to take over bounded domains first, then expand toward whole-system autonomy.

Success definition:
- The system learns from real human actions, not only explicit feedback.
- It builds tenant-specific operating policies.
- It can run one high-value business domain end-to-end in `suggest_only`, then `auto_safe`, then bounded `full_auto`.
- It proves quality through outcome metrics, rollback safety, and end-to-end evaluation before expanding scope.

## Phase 1: Demonstration Capture
- Add an operator-demonstration pipeline that converts manual user work into training records.
- Record for each captured action:
  - actor, tenant, surface, entity IDs, prior state snapshot, chosen action, optional free-text reason, timestamp
  - resulting state after action
  - whether the action was later reversed, edited, or completed successfully
- Capture demonstrations first for:
  - inbox/commercial negotiation actions
  - quote/proforma/invoice changes and sends
  - deal-stage transitions
  - task completion and follow-through actions

## Phase 2: Tenant Policy Memory
- Add tenant-scoped policy memory that stores:
  - preferred channels
  - acceptable commercial changes
  - approval habits
  - follow-up timing
  - common negotiation outcomes
  - operator-specific action preferences
- Build this memory from:
  - demonstrations
  - explicit AI feedback
  - approvals/rejections
  - reversals and edits
  - successful manual follow-through outcomes

## Phase 3: MVP Autonomy Domain
- First autonomy domain: `commercial automation + inbox negotiation + resend flow`
- Implement one planner/executor for that domain:
  - observe inbound context
  - retrieve tenant policy memory and prior examples
  - propose or execute create/revise/send/resend/convert/finalize actions
  - log decision context, confidence, outcome, and rollback
- Autonomy modes:
  - `suggest_only`
  - `auto_safe`
  - `full_auto`
- Hard blockers remain mandatory:
  - missing recipient
  - invalid billing/commercial state
  - unsupported transport/provider capability
  - stale or missing required context
  - action outside tenant policy bounds

## Phase 4: Confidence and Learning Quality
- Replace coarse confidence with per-action confidence derived from:
  - similarity to prior successful demonstrations
  - context completeness
  - historical success rate for this tenant and action
  - edit/reversal history
  - action risk class
- Add calibration and drift checks per tenant and per action type.
- Keep threshold tuning secondary to demonstration-backed confidence.

## Phase 5: Safety, Rollback, and Governance
- Add autonomy envelopes per tenant:
  - allowed domains
  - allowed actions
  - max customer-facing risk
  - required human checkpoints
  - max daily auto-actions
- Add reversible execution where possible:
  - resend cooldowns
  - duplicate-action prevention
  - compensating actions
  - human recovery queue
- Record explainability for every autonomous action.

## Phase 6: Evaluation Harness
- Add end-to-end evaluation runs for the MVP domain using realistic historical scenarios.
- Required outputs:
  - precision at threshold
  - reversal rate
  - edit-after-autonomy rate
  - approval override rate
  - duplicate-action rate
  - business completion rate
  - time-to-outcome versus human baseline
- Gate rollout progression on measured outcomes.

## Phase 7: Expansion After MVP Stability
- Expand only after the MVP domain is stable for a full rollout window.
- Next domains:
  1. deal progression and follow-up orchestration
  2. task automation and completion inference
  3. customer reply automation beyond commercial threads
  4. broader workflow-generated execution

## Implementation Foundation
The first implementation slice for this roadmap adds:
- roadmap documentation in this file
- database tables for demonstrations, tenant policy memory, evaluation runs, and similarity retrieval
- services for recording demonstrations, storing tenant policy memory, and logging evaluation runs
- hooks in commercial automation so the MVP domain starts generating learning data immediately

## Assumptions
- MVP takeover stays limited to one domain first.
- The first domain is `commercial automation + inbox negotiation + resend flow`.
- Learning from human demonstrations is primary; threshold tuning remains secondary.
- Tenant-specific behavior matters more than global policy defaults.
- Full-system takeover should wait until one domain is stable under bounded `full_auto`.
