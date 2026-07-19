# Lead Scoring Page

Client-facing product promo and feature summary

Prepared: July 2, 2026

## Product Positioning

The Clarity CRM Lead Scoring page is the engagement intelligence view for sales focus. It helps a business understand which contacts are showing real activity, which leads are getting warmer, and where the team should place attention first.

The client is not buying a scoring screen. The client is buying a clearer way to decide who needs follow-up now.

Lead Scoring helps answer one practical question: which contacts are showing enough interest or movement to deserve the next sales action?

For clients, the page turns scattered activity into a simple priority signal. Emails, calls, meetings, notes, opens, clicks, form submissions, and page visits can all contribute to an engagement score. Those rule-based engagement scores also support the broader composite lead score that can combine engagement, ML prediction, and AI insight.

## Executive Summary

The Lead Scoring page is shown in the application as Engagement Rule Scoring. It gives authorized users a clear view of recent engagement scores that feed the composite lead score.

The page shows summary cards for average engagement score, hot leads, warm leads, and cold leads. It lists the top scoring contacts, groups the full contact base into scoring bands, and explains the rules that create the engagement score.

The score is intentionally easy to understand. Contacts with 70 to 100 points are treated as hot. Contacts with 40 to 69 points are warm. Contacts with 0 to 39 points are cold. The page uses this simple language so sales and service teams can quickly understand who needs attention.

The engagement score is based on activity from the last 30 days. Scores decay over time, so recent behavior matters more than old behavior. This keeps the team focused on current interest rather than stale history.

The page also points users toward ML Models when the workspace has permission for model-backed scoring. That matters because Clarity CRM has a broader three-score system: engagement score, ML score, and AI score can be combined into a canonical composite lead score.

The intention is simple: help the business prioritize follow-up from evidence instead of guesswork.

## Product At A Glance

- Operating role: Engagement scoring and lead prioritization surface.
- Primary audience: Founders, sales managers, sales reps, customer success teams, administrators, and implementation partners.
- Core promise: Turn recent contact activity into a clear hot, warm, or cold lead signal.
- Business result: Faster follow-up, better sales focus, less wasted time, and more consistent prioritization.
- Source page: `http://localhost/crm/public/lead_scoring.php`

## Plain English Pitch

Every business has contacts. Some contacts are ready to talk. Some are only browsing. Some were active weeks ago but have gone quiet.

Without lead scoring, teams often treat these contacts the same. A salesperson may chase the wrong person while a more interested lead waits. A founder may guess who is hot based on memory. A team may celebrate a large contact list without knowing which contacts are actually moving.

The Lead Scoring page helps solve that problem. It looks at recent engagement and turns it into a simple score. If a contact opens emails, clicks links, submits forms, attends meetings, logs calls, or visits pages, the score can rise. If time passes, the score can decay.

The result is not just a number. The result is a better follow-up order.

## What The Client Is Really Buying

Clients are not shopping for an algorithm. They are shopping for better sales judgment.

They want:

- A simple way to know which contacts are most engaged.
- A way to focus the team on hot leads before interest cools.
- A way to avoid wasting time on contacts with no recent activity.
- A clear reason why a lead is considered hot, warm, or cold.
- A score that changes as behavior changes.
- A path from engagement activity to CRM follow-up.
- A scoring system that can grow from simple rules into ML and AI support.
- A way for managers to see whether the team has enough active opportunity.

The Lead Scoring page supports that result by turning contact behavior into sales focus.

## What The Page Is Built To Do

The Lead Scoring page is intended to help teams:

- View engagement-based scoring across the active workspace.
- See the average engagement score for contacts.
- Count hot, warm, and cold leads.
- Identify the top scoring contacts.
- Open a contact record directly from the top scoring list.
- Understand how contacts are distributed across scoring bands.
- Review the engagement rules that drive the score.
- See that scoring uses recent activity from the last 30 days.
- Understand that scores decay over time.
- Keep score values capped at 100 points.
- Move from the engagement rules view to ML model scoring when permitted.
- Give administrators a clear place to understand scoring behavior.
- Support the larger composite lead score used across contacts, reports, workflows, and automation.

## Salient Features

### Engagement Rule Scoring Page

The page title shown to users is Engagement Rule Scoring. That is important because this page focuses on rule-based engagement inputs, not only the final composite score.

The page explains that users are viewing recent activity scores that feed the composite lead score. This gives the page a clear role: it shows the rule layer that helps the broader scoring system understand engagement.

### Permission-Controlled Access

The page requires authentication and the `settings.scoring` permission. If a user does not have permission to manage scoring, the system redirects them away from the page.

This keeps scoring controls and scoring analysis in the hands of authorized users. For a client, this means lead scoring can be managed with proper role discipline.

### Workspace-Aware Analytics

The page uses the active analytics workspace when counting contacts and scores. Contacts are filtered by workspace, so one workspace does not see another workspace's scoring data.

This matters for multi-workspace clients. Each business area sees its own scoring picture.

### Summary Cards

The page opens with four summary cards:

- Average Engagement Score.
- Hot Leads.
- Warm Leads.
- Cold Leads.

These cards give a fast read on the health of the contact base. A manager can quickly see whether the current audience is active, lukewarm, or mostly cold.

### Hot, Warm, And Cold Lead Bands

The page uses practical scoring bands:

- Hot Leads: 70 to 100 points.
- Warm Leads: 40 to 69 points.
- Cold Leads: 0 to 39 points.

These bands make the score easy to understand. A salesperson does not need to interpret a complex model output. The business can simply ask, "Which contacts are hot enough to follow up now?"

### Top Scoring Contacts

The page shows the top scoring contacts by engagement score. Each row includes the contact name, email, and score, with a link to the contact profile.

This is one of the page's most practical features. It turns the score into a follow-up list. The user can click from the page directly into the relationship record.

### Score Distribution

The page shows contacts grouped by scoring bands. Each band includes the count and percentage of contacts in that range.

This helps leaders understand the quality of the current contact pool. If too many contacts are cold, the business may need better campaigns, stronger follow-up, or cleaner qualification. If many contacts are warm or hot, the team may need to act quickly.

### Visual Progress Bars

The score distribution uses visual progress bars to show the share of contacts in each band.

This makes the page easier to scan. A user can understand the scoring mix without reading a dense report.

### Engagement Rules

The page explains the rules that feed engagement scoring:

- Email Activity: 8 points.
- Call Logged: 12 points.
- Meeting Held: 18 points.
- Note Added: 3 points.
- Email Opened: 5 points.
- Link Clicked: 10 points.
- Form Submitted: 20 points.
- Page Visited: 15 points.

This makes scoring transparent. Users can see which actions matter and why a contact's score may rise.

### Time Decay

Most engagement rules include decay. For example, email activity and calls decay at 0.9 per day, meetings and clicks decay at 0.85 per day, notes decay at 0.95 per day, and form submissions decay at 0.8 per day.

The business value is simple: recent interest matters more than old interest. A lead that clicked yesterday should be treated differently from one that clicked last month.

### Last 30 Days Focus

The engagement score is calculated from activities in the last 30 days.

This keeps the page focused on current sales reality. The system is not trying to reward ancient activity. It is helping the team understand who is active now.

### Score Cap At 100

Engagement scores are capped between 0 and 100.

This keeps scoring easy to interpret and prevents unusually active records from creating confusing numbers. Every score fits the same simple scale.

### No-Score Empty State

If no contacts have scores yet, the Top Scoring Contacts section shows a simple empty message.

This prevents confusion for new workspaces. The business knows the scoring system needs activity before meaningful results appear.

### Page Guide

The page can show a Lead Scoring page guide button when a guide video is available.

This helps users learn scoring in context. Instead of reading a separate manual, the user can open help from the page they are using.

### ML Models Link

When a user has permission for the ML scoring dashboard, the page shows an ML Models button. The page also includes a note that the current view shows engagement and rule inputs, while model-backed scoring with predictive analytics, model training, and advanced insights lives in ML Models.

This creates a natural upgrade path. A business can start with understandable engagement rules and grow into predictive scoring when ready.

### Three-Score System

Behind the page, Clarity CRM supports a broader three-score system:

- Engagement score: rule-based activity and interaction score.
- ML score: model-backed predictive score when an active model is available.
- AI score: insight-based score from AI context.

These components can combine into the canonical composite lead score.

This is important for client positioning. The visible page is simple, but the scoring system underneath can become more advanced as the business matures.

### Composite Lead Score

The canonical `lead_score` is owned by the AI Lead Scoring service. It can combine engagement, ML, and AI into one consolidated score.

This gives the CRM a single lead priority number that can be used in contact views, reports, workflows, automations, nurture logic, and deal intelligence.

### Default Score Weights

The default composite scoring weights are:

- Engagement: 40%.
- ML: 40%.
- AI: 20%.

These weights give the business a balanced starting point. The score can value real activity, predictive model output, and AI insight without depending entirely on only one source.

### Recommended Weights

The scoring system can recommend weights based on component availability. For example, if ML is not available, weight can move toward engagement and AI. If only one component is available, that component can receive the full effective weight.

This avoids broken scoring when one source is missing. The system can still produce a useful score instead of forcing the user to wait for perfect data.

### Score Recalculation

The scoring API can recalculate a contact's scores and return the full breakdown. It updates the consolidated score, component scores, recommended weights, recalculation timestamp, and scoring metadata.

For clients, this means scoring can refresh as the relationship changes.

### Apply Weights

The scoring API can apply recommended or custom weights to a contact and then recalculate the score.

This gives advanced users control. They can accept the system's recommendation or use a custom weighting strategy when the business has a specific scoring philosophy.

### Contact Score Breakdown

On the contact view, the system can show a conversion score and model breakdown. It can display the consolidated score, engagement score, ML score, AI score, component weights, contributions, recommended weights, ML top factors, and advanced scoring actions.

This turns the Lead Scoring page into part of a larger scoring workflow. The overview shows the scoring landscape, while the contact view explains the score for one person.

### Score Events

When the composite score changes, the system can publish contact score changed events. ML score increases, decreases, threshold crossings, and conversion probability can also publish events when model-backed scoring is available.

This allows workflows and automation to respond when a lead becomes more or less important.

### Workflow And Automation Fit

Lead score can be used in workflows, filters, reports, nurture logic, deal evidence, predictive analytics, and automation actions.

This makes scoring more than a dashboard. A high lead score can become a trigger for follow-up, assignment, notification, stage review, or nurture decision.

## Product Intentions

The Lead Scoring page is designed with five main intentions.

First, it creates focus. The team can see who is showing the strongest current engagement.

Second, it creates transparency. The page shows the rules and bands behind engagement scoring.

Third, it creates urgency. Scores decay over time, which reminds the team that interest can cool.

Fourth, it creates consistency. Every contact is judged on the same engagement scale.

Fifth, it creates a bridge to intelligence. The engagement score can feed a broader composite score that includes ML and AI when those signals are available.

## The Result The Page Sells

The page sells a practical result: better follow-up priority.

When Lead Scoring is used well, a business can:

- See which contacts are hot, warm, or cold.
- Prioritize the contacts most likely to respond.
- Reduce wasted effort on stale leads.
- Explain why one lead deserves attention before another.
- Spot whether campaigns are producing engaged contacts.
- Give salespeople a clearer daily call and follow-up list.
- Support workflows that respond to score changes.
- Build toward predictive and AI-assisted sales focus.

The value is not the score itself. The value is knowing where attention should go next.

## Why This Matters For A Growing Business

A growing business usually collects more contacts than it can personally follow up with every day. That creates a prioritization problem.

If the team follows up randomly, good leads can be missed. If the team only follows up based on memory, the owner becomes the bottleneck. If the team waits too long, interest can disappear.

Lead Scoring gives the business a simple way to rank attention. It helps answer, "Who is warm right now?"

For a high school level entrepreneur, the idea is simple: people who interact more with your business should move higher on your follow-up list.

## AI Automation, CRM Follow-Up, And Human Control

The Lead Scoring page supports the larger WebXpanse promise.

AI automation saves time and money by helping turn activity, predictive model output, and AI insight into a score the team can use.

CRM follow-up creates consistency because the score lives on contacts and can support reports, workflows, nurture, and sales actions.

Human control keeps the system grounded because administrators can understand the engagement rules, use ML models when permitted, apply recommended weights, and review score breakdowns before acting.

The system helps the team decide faster without hiding the logic.

## Best-Fit Use Cases

The Lead Scoring page is useful for:

- Sales teams deciding who to call first.
- Founders reviewing which leads are most active.
- Marketing teams checking whether campaigns are creating engaged contacts.
- Customer success teams spotting high-engagement accounts.
- Admins explaining why scores changed.
- Managers reviewing hot, warm, and cold lead counts.
- Teams building workflows around lead score thresholds.
- Businesses moving from manual follow-up to data-guided follow-up.

Any time a business has more contacts than it can follow up with equally, Lead Scoring can help.

## Example Client Stories

### Founder-Led Business

A founder has 300 contacts but only time to follow up with 15 today. The Lead Scoring page shows the hottest contacts based on recent activity. The founder opens the top scoring contacts and starts there.

The result is a better use of limited time.

### Sales Team

A sales team wants to avoid chasing cold leads while active prospects wait. The team uses hot and warm scoring bands to focus daily outreach.

The result is more disciplined follow-up.

### Marketing Team

A marketing team sends emails and drives traffic to landing pages. Lead Scoring shows whether contacts are opening, clicking, submitting forms, and visiting pages.

The result is clearer campaign quality.

### Growing CRM Operation

A business begins with engagement rules, then later adds ML scoring. The Lead Scoring page remains the simple explanation of activity inputs, while ML Models adds predictive scoring.

The result is a scoring system that grows with the business.

## Client-Facing Value Proposition

Lead Scoring gives your business a simple way to know which contacts deserve attention first.

It turns recent activity into a clear score, groups contacts into hot, warm, and cold bands, and gives your team a practical follow-up order. Instead of guessing who is interested, the business can see who is engaging.

The page is simple enough for day-to-day sales focus and connected enough to support advanced scoring, ML models, AI insight, workflows, and CRM automation.

## What Makes It Different

The difference is not just that the system has a score. Many tools can attach a number to a lead.

The difference is that Clarity CRM connects the score to actual CRM behavior. Email activity, calls, meetings, notes, opens, clicks, forms, and page visits can all contribute to engagement. The score can also work with ML, AI, contact views, workflows, reports, and automation.

That means the score is not isolated. It becomes part of how the business decides what to do next.

## Sales Message

Use Lead Scoring when your business needs a smarter way to prioritize follow-up.

It helps you see which contacts are hot, warm, or cold, understand the engagement behind the score, and move your team toward the leads most likely to respond.

The promise is simple: focus on the leads showing the strongest signs of interest.

## Short Website Blurb

Lead Scoring turns contact activity into sales focus. See hot, warm, and cold leads, review top scoring contacts, understand the engagement rules behind each score, and connect scoring to smarter CRM follow-up.

## Sales One-Liner

Lead Scoring helps your team stop guessing who to follow up with by turning recent engagement into a clear priority signal.

## Client Conversation Script

If a prospect asks what the Lead Scoring page does, say:

"Lead Scoring helps your team know which contacts deserve attention first. It looks at recent engagement, such as emails, calls, meetings, clicks, forms, and page visits, then groups contacts into hot, warm, and cold leads. The page shows the top scoring contacts, score distribution, and the rules behind the score. As your business matures, the same scoring system can also work with ML and AI signals."

## Client Readiness Checklist

Before launching Lead Scoring with a client, confirm:

- The team understands what hot, warm, and cold leads mean.
- Contact activity is being logged consistently.
- Email, form, page, call, meeting, and note activity are being captured where possible.
- Salespeople know where to find top scoring contacts.
- Managers know how to read the score distribution.
- The workspace has the right permissions for scoring settings.
- The client knows that the Lead Scoring page shows engagement rules.
- The client knows that ML Models is the next layer for predictive scoring.
- Workflows or reports that depend on lead score thresholds are reviewed.
- The team agrees on how quickly hot leads should be followed up.

This keeps scoring connected to behavior, not just reporting.

## Recommended Pairings

Lead Scoring becomes more valuable when paired with:

- Contacts, so every relationship has a visible priority signal.
- Email, so opens and email activity can support engagement.
- Forms, so submissions raise intent.
- Website tracking, so page visits can add signal.
- Tasks, so hot lead follow-up becomes assigned work.
- Workflows, so score changes can trigger consistent next steps.
- ML Models, so predictive analytics can add model-backed scoring.
- AI Coach, so business guidance can consider lead quality and activity.
- Reports, so managers can review score trends and lead quality.

## Final Product Message

The Lead Scoring page gives a growing business a better way to focus attention.

It helps the team see which contacts are active, which leads are warming up, which contacts have gone cold, and which people should be followed up first. Instead of treating every lead the same, the business can act on the strongest signals.

Lead Scoring turns engagement into follow-up priority.
