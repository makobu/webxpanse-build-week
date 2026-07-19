# Targets Page

Client-facing product promo and feature summary

Prepared: July 2, 2026

## Product Positioning

The Clarity CRM Targets page is the business focus and accountability center. It helps a founder, manager, or team turn broad ambition into measurable targets, live progress, health signals, forecasts, and next actions.

The client is not buying a target tracker. The client is buying a clearer way to know what the business is trying to achieve, whether it is on pace, and what needs attention before time runs out.

Targets helps answer one practical question: are we making real progress toward the result we said mattered?

For clients, the Targets page connects goals with CRM activity. A target can be personal, team-based, or company-wide. It can be updated manually, automatically from CRM records, or through a hybrid model. The page then shows progress, risk, blockers, milestones, forecasts, and recommended actions in one place.

## Executive Summary

The Targets page gives users a focused place to manage measurable business goals. It is built for day-to-day operating discipline: set the target, define the deadline, choose the progress source, watch the pace, and adjust before the target becomes impossible.

The main page is called Targets Intelligence. It shows all targets a user can access, including personal targets owned by the user and shared team or company targets. Users can filter by scope, progress mode, status, rollup source, health state, overdue status, and search text.

Each target card shows the target title, description, scope, progress mode, health band, target type, current progress, target value, forecast score, projected finish date, milestone progress, pace summary, blockers, next actions, and quick controls such as Open, Edit, and Delete when the user has permission.

The broader target experience includes target creation, target editing, target detail pages, a target dashboard, manual progress updates, auto-rollup progress from CRM activity, milestone tracking, advice history, and permission-aware access controls.

The intention is simple: help the business stop guessing and start managing progress from evidence.

## Product At A Glance

- Operating role: Goal intelligence, progress tracking, and accountability center.
- Primary audience: Founders, owners, managers, sales teams, service teams, operators, and implementation partners.
- Core promise: Turn business goals into measurable targets with visible progress and practical next actions.
- Business result: Better focus, earlier warnings, cleaner accountability, and fewer missed goals.
- Source page: `http://localhost/crm/public/targets.php`

## Plain English Pitch

Most businesses do not struggle because they have no ideas. They struggle because the important ideas are not converted into clear targets.

A founder may want more sales. A sales team may want more closed deals. A service team may want faster task completion. A company may want more paid invoices, more qualified leads, or better follow-up. Without a target system, those goals stay vague.

The Targets page helps the business make the goal specific. It asks: what are we trying to hit, how much progress have we made, when is the deadline, where is the progress coming from, and what should we do next?

The result is not just a list of goals. The result is a practical operating view for progress.

## What The Client Is Really Buying

Clients are not shopping for a prettier goal list. They are shopping for focus, accountability, and early warning.

They want:

- A clear place to define what success looks like.
- A way to see which goals are active, completed, behind, blocked, or at risk.
- A way to connect goals to real CRM work instead of manual guesses.
- A dashboard that shows whether the business is on pace.
- A simple way to separate personal, team, and company targets.
- A path from target status to the next action.
- A way to see blockers before the deadline arrives.
- A way to keep the team honest about progress.
- Guidance that helps the business decide what to do next.

The Targets page supports that result by turning goals into measurable operating signals.

## What The Page Is Built To Do

The Targets page is intended to help teams:

- Create measurable personal, team, and company targets.
- Track current value against target value.
- Choose a start date and target deadline.
- Use manual, auto-rollup, or hybrid progress modes.
- Roll up progress from CRM records such as deals, invoices, tasks, contacts, and communications.
- Filter targets by scope, status, progress source, rollup source, health state, overdue status, and search.
- See target health through on-track, at-risk, behind, blocked, completed, and missed bands.
- Show forecast score and projected finish date.
- Surface pace summaries, blockers, and next best actions.
- Track milestones and checkpoints.
- Open a target detail page for deeper progress review.
- Use a dashboard to understand target health across personal, team, and company work.
- Give read-only visibility to shared targets while protecting edit/delete access.
- Keep goals connected to daily CRM behavior.

## Salient Features

### Targets Intelligence Home

The main page is titled Targets Intelligence. It positions the page as more than a goal list. It is a working view of progress, risk, and recommended next steps.

The header explains the purpose clearly: track personal, team, and company targets with rollups, forecasts, and risk signals.

From the header, users can create a new target, open the dashboard, or view a page guide when the guide video is available.

### Summary Cards

The page includes high-level cards for:

- All Targets.
- Active targets.
- At Risk targets.
- Completed targets.

The All Targets card also breaks the count into personal, team, and company targets. This helps a business owner quickly understand whether the target system is being used by one person, a team, or the whole company.

### Personal, Team, And Company Scope

Targets can be personal, team-based, or company-wide.

This matters because not every goal belongs at the same level. A salesperson may own a personal target. A department may own a team target. Leadership may track a company target.

The page respects that distinction and makes it visible in filters, badges, dashboards, and permissions.

### Search And Filters

Users can search and filter targets by:

- Search text.
- Scope.
- Progress source.
- Status.
- Rollup source.
- Health state.
- Overdue-only view.

This helps a manager move quickly from "show me everything" to "show me the targets that need action."

### Progress Modes

Targets support three progress modes.

Manual progress lets a user update the current value directly. This is useful for goals that are not yet connected to system data.

Auto rollup progress calculates movement from CRM records such as deals, invoices, tasks, contacts, or communications. This is useful when progress should come from actual business activity.

Hybrid progress combines automatic rollup with a manual adjustment. This is useful when the system can measure part of the target but the team still needs to add a controlled human adjustment.

### CRM Rollup Sources

Targets can roll progress from important CRM areas:

- Deals, including closed-won count, open count, proposal and negotiation count, closed-won value, and pipeline value.
- Invoices, including paid total, sent or finalized total, paid count, and active quote or proforma count.
- Tasks, including completed task count and open task count.
- Contacts, including created contact count, qualified contact count, and won contact count.
- Communications, including outbound communication count, inbound communication count, and reply count.

This is a major client value. The business can connect a target to work already happening inside the CRM instead of updating every number by hand.

### Plugin Metric Providers

The target system can also support plugin-provided metric providers when available. This means future or installed plugins can provide additional target metrics beyond the native CRM sources.

For clients, this gives the Targets page room to grow with the business. As more systems or plugins become available, targets can become more connected.

### Target Cards

Each target appears as a rich card rather than a plain row. The card shows:

- Scope badge.
- Progress mode badge.
- Health badge.
- Target type badge.
- Title and description.
- Progress source.
- Forecast score.
- Projected finish date.
- Milestone progress.
- Current value and target value.
- Progress percentage.
- Target date and days remaining.
- Pace summary.
- Blockers.
- Next actions.
- Open, Edit, and Delete controls when allowed.

This makes each target useful at a glance. A user can understand the status without opening every target one by one.

### Health Bands

Targets use clear health bands:

- On track.
- At risk.
- Behind.
- Blocked.
- Completed.
- Missed.

The health band helps the team avoid vague language. Instead of saying "we might be okay," the page shows a clear signal based on progress, time, and pace.

### Forecast Score

Each target can show a forecast score. The score estimates how likely the target is to finish successfully based on current progress, expected progress, pace, and remaining work.

For a client, this is valuable because the business can respond earlier. The score gives a practical sense of confidence, not just a final pass or fail after the deadline.

### Projected Finish Date

Targets can show a projected completion date. If the current pace continues, the system estimates when the target may be completed.

This helps leaders ask better questions. If the projected finish date is later than the deadline, the business can correct the pace now.

### Pace Summary

The page explains progress in plain language, such as current pace per day or that no measurable pace has been established yet.

This makes target tracking easier for non-technical users. The page does not only show percentages. It explains what the numbers mean.

### Blockers

The intelligence layer can surface blockers. Examples include no source activity contributing to an auto-rollup target, no milestones cleared, or a projected completion date that is later than the deadline.

Blockers help the team understand why a target is in trouble instead of only seeing that it is in trouble.

### Next Best Actions

Targets can show recommended next actions. These recommendations are practical and tied to the target context.

For example:

- Review open deals in proposal and negotiation.
- Follow up on sent and overdue invoices.
- Close the highest-priority open tasks.
- Qualify new contacts and push active leads forward.
- Increase communication activity on the channel under target.
- Break remaining work into smaller milestones.

This helps the business move from awareness to action.

### Milestones And Checkpoints

Targets support milestones. A user can enter milestones as checkpoints with title, target value, and due date.

If no custom milestones exist, the intelligence system can suggest milestone checkpoints at 25%, 50%, and 75% of the target value.

Milestones help the business catch slippage before the final deadline.

### Target Creation

The Create Target page lets the user define:

- Target title.
- Description.
- Scope.
- Target type.
- Progress mode.
- Target value.
- Current value.
- Unit.
- Hybrid manual adjustment.
- Rollup source and metric.
- Start date.
- Target deadline.
- Milestones.
- Reminder frequency.
- Custom reminder days.

This gives the user a complete but understandable setup flow. The business can create simple manual targets or more advanced CRM-linked targets from the same page.

### Target Editing

The Edit Target page lets permitted users adjust the same important settings after creation. They can change scope, progress logic, milestones, dates, reminder frequency, and status.

This supports real business life. A target may need refinement as the team learns more.

### Target Detail View

Each target has a detailed page for deeper review. It shows progress, forecast, projected finish, days remaining, pace summary, target intelligence, blockers, next best actions, milestones, rollup evidence, advice history, and quick actions.

The detail view is where a manager can inspect the evidence behind a target and decide what to do next.

### Rollup Evidence

For auto-rollup or hybrid targets, the detail page can show supporting records. Evidence may link to deals, invoices, tasks, contacts, or communication records that contributed to progress.

This builds trust. The user can see what activity is driving the number instead of accepting a mystery calculation.

### Advice History

Targets can generate and store advice. Advice may explain whether a target is moving, at risk, blocked, behind, completed, missed, or cancelled. It can include blockers, next actions, projected completion date, forecast confidence, milestone progress, and required pace.

The advice history gives the team a record of what guidance was available over time.

### Quick Actions

On the target detail page, permitted users can:

- Update manual progress.
- Mark a target as complete.
- Refresh target intelligence.
- Edit the target.
- Refresh advice.

This keeps action close to insight. The user does not have to navigate away from the target to make common updates.

### Dashboard View

The Targets Dashboard gives a broader view across many targets. It shows:

- Active target count.
- At-risk target count.
- Completion confidence.
- Completed target count.
- Scope rollups for personal, team, and company targets.
- Risk distribution across health bands.
- Most at-risk targets.
- Recommended actions.

This helps leadership see the shape of progress, not only the details of one target.

### Permission-Aware Controls

The system respects target access. A user can view personal targets they own and shared team or company targets. Editing and deletion require ownership or target management permission.

When a user only has read-only access, the target detail page explains that the shared target is visible but only the owner or a fully authorized user can change it.

This keeps visibility broad while protecting control.

### Safe Deletion

The delete flow includes permission checks, CSRF protection, target lookup fallback, and a warning that deletion is permanent.

This protects important target history from casual or unauthorized deletion.

### Reminder Support

Targets can schedule reminders using daily, weekly, deadline, or custom reminder settings. When relevant dates or reminder frequency change, reminders can be rescheduled.

This supports accountability. A target should not be created and then forgotten.

### Lifecycle Events

The target module emits lifecycle events when targets are created, updated, or completed. This allows the broader CRM system to respond to target changes.

For clients, this means targets can become part of the operating system rather than isolated notes.

### AI Coach Connection

AI Coach can use active targets as context when creating recommendations. When the system knows what the business is trying to achieve, guidance can be more relevant.

This makes the target page important beyond its own screen. It helps the AI cofounder understand the direction of the business.

## Product Intentions

The Targets page is designed with five main intentions.

First, it creates focus. The business can name the result that matters and make it measurable.

Second, it creates accountability. Targets have owners, scope, values, deadlines, and status.

Third, it connects goals to real work. Auto-rollup and hybrid targets let CRM activity move the target forward.

Fourth, it creates early warning. Forecasts, projected finish dates, health bands, blockers, and milestones help the business act before a deadline fails.

Fifth, it keeps humans in control. Users can create, edit, refresh, complete, and delete targets according to permissions. The system advises and calculates, but the business decides.

## The Result The Page Sells

The page sells a practical result: better execution.

When Targets is used well, a business can:

- Know what the team is trying to hit.
- See progress without waiting for end-of-month reviews.
- Catch risk early.
- Connect targets to CRM activity.
- Turn vague goals into measurable targets.
- Create accountability without micromanaging.
- Give AI Coach better context.
- Keep leadership focused on the next best move.

The value is not the progress bar itself. The value is knowing whether the business is moving toward the result that matters.

## Why This Matters For A Growing Business

Small teams often run on effort. Everyone is busy, but nobody is always sure whether the right work is moving the business forward.

Targets changes the question from "Are we busy?" to "Are we progressing?"

That difference matters. A business can have many tasks, many messages, many deals, and many meetings, yet still miss the target. The Targets page helps connect daily activity to a measurable result.

For a high school level entrepreneur, the idea is simple: write down what you want to achieve, measure it, check whether you are on pace, and fix the problem before the deadline.

## AI Automation, CRM Follow-Up, And Human Control

The Targets page supports the larger WebXpanse promise.

AI automation saves time and money by helping explain progress, forecast risk, and suggest next actions.

CRM follow-up creates consistency because targets can connect to deals, invoices, tasks, contacts, and communications instead of living outside the business system.

Human control keeps the system grounded because users decide which targets matter, which progress source to trust, when to update progress, and when a target should be completed or changed.

The system helps the business learn before it acts.

## Best-Fit Use Cases

The Targets page is useful for:

- Monthly sales targets.
- Quarterly revenue targets.
- Closed-won deal goals.
- Pipeline value goals.
- Paid invoice goals.
- Quote and proforma activity goals.
- Completed task goals.
- New contact goals.
- Qualified lead goals.
- Communication activity goals.
- Founder weekly execution targets.
- Team performance targets.
- Company-wide operating goals.
- Milestone-based project progress.

Any time the business needs to measure progress against a deadline, Targets can be the operating surface.

## Example Client Stories

### Founder-Led Business

A founder sets a target to close 5 new customers this month. The target is connected to closed-won deals, so progress updates from real CRM activity. The page shows whether the founder is on pace, how many days remain, and what actions could move the target forward.

The result is clearer sales focus.

### Sales Team

A sales manager creates a team target for pipeline value. The manager can filter targets by team scope, review risk signals, and open the most at-risk targets. The dashboard shows whether the team is building enough pipeline to support future sales.

The result is earlier visibility into revenue risk.

### Operations Team

An operations lead creates a task completion target. The target rolls up completed tasks and shows whether the team is keeping pace. If the target falls behind, the page recommends closing the highest-priority open tasks first.

The result is less guessing about execution.

### Finance-Focused Business

A business owner creates a paid invoice target. The system can roll up paid invoice totals or paid invoice count. If the target is at risk, the user can follow up on sent and overdue commercial documents.

The result is better cash discipline.

## Client-Facing Value Proposition

Targets gives your business a clear way to set, measure, and manage the goals that matter.

It helps you define what success looks like, connect progress to real CRM activity, see whether you are on pace, and act before a target becomes a missed opportunity.

The page is simple enough for a small business owner and powerful enough for a growing team. You can create a target, choose how progress should be measured, monitor the risk, review the evidence, and decide the next move.

## What Makes It Different

The difference is not only that the system tracks goals. Many tools can do that.

The difference is that Targets lives inside the CRM operating system. It can connect progress to deals, invoices, tasks, contacts, communications, milestones, AI Coach recommendations, and shared team visibility.

That means the target is not separated from the work. The target is connected to the work.

## Sales Message

Use Targets when your business needs clear goals, measurable progress, and early warning before deadlines fail.

Targets helps you create personal, team, and company goals, connect them to CRM activity, forecast whether they are achievable, and see the next actions that can move them forward.

The promise is simple: set the target, watch the pace, and make the next move before it is too late.

## Short Website Blurb

Targets turns goals into operating discipline. Create measurable personal, team, or company targets, connect progress to CRM activity, track health and forecasts, and see the next actions needed to stay on pace.

## Sales One-Liner

Targets helps your business stop guessing about progress by turning goals into measurable CRM-backed action.

## Client Conversation Script

If a prospect asks what the Targets page does, say:

"Targets gives your business a clear place to define goals, measure progress, and see what needs attention. You can set personal, team, or company targets, connect progress to CRM activity like deals, invoices, tasks, contacts, and communications, then use forecasts, health signals, blockers, and next actions to stay on track. It helps the team focus on the result, not just stay busy."

## Client Readiness Checklist

Before launching Targets with a client, confirm:

- What business result needs to be measured.
- Whether the target is personal, team, or company-wide.
- Who owns the target.
- What target value should be reached.
- What unit should be used.
- What start date and deadline apply.
- Whether progress should be manual, auto-rollup, or hybrid.
- Which CRM source should drive progress if auto-rollup is used.
- Which milestones or checkpoints should be added.
- How often reminders should be sent.
- Who should be allowed to edit or delete the target.
- What dashboard review rhythm the team should use.

This keeps the target practical and tied to a real business outcome.

## Recommended Pairings

Targets becomes more valuable when paired with:

- Contacts, so lead and customer movement can support target progress.
- Deals, so pipeline and closed-won goals can be measured.
- Tasks, so execution targets can reflect completed work.
- Finance and invoices, so paid revenue and commercial document activity can support targets.
- Email and WhatsApp, so communication activity can become measurable.
- AI Coach, so recommendations can be guided by active targets.
- Workflows, so important target-related actions can become repeatable.
- Dashboard review routines, so teams discuss progress before it is too late.

## Final Product Message

The Targets page gives a growing business a better way to manage progress.

It helps the team set goals, measure real movement, see risk early, review evidence, and choose the next action. Instead of hoping effort turns into results, Targets helps the business manage the result directly.

Targets turns ambition into measurable action.
