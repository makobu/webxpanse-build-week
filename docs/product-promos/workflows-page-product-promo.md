# Workflows Page

Client-facing product promo and feature summary

Prepared: June 25, 2026

## Product Positioning

The Clarity CRM Workflows page is the automation control room for the business. It helps a team decide what should happen next when a customer takes an action, when a deal moves forward, when an email is opened, when a form is submitted, when a task becomes overdue, or when a relationship has gone quiet for too long.

The client is not buying a flowchart. The client is buying a reliable way to make sure important follow-up work happens without depending on memory, sticky notes, scattered chats, or one busy person holding the whole process together.

Workflows helps answer one practical question: when something important happens in the business, what should happen next, who should know, and what can safely be automated?

For clients, the Workflows page turns business memory into a repeatable operating system. It helps the team create consistent follow-up, reduce manual work, protect customer relationships, and keep humans in control before automation acts on sensitive work.

## Executive Summary

The Workflows page gives users a clear place to build, manage, test, and improve the business rules that move customer work forward. It brings together active workflows, draft workflows, recommended templates, visual workflow building, template-driven setup, analytics, test tools, execution history, queue reliability, retry handling, and human-controlled automation.

At a glance, users can see which workflows are active, which ones are inactive, what triggers them, how many actions they contain, how often they have run, how many times they succeeded or failed, whether any retries are pending, and whether the execution queue is healthy.

Execution reliability is visible throughout the workflow experience, so teams can see whether automation is helping the business or needs attention.

The page also supports a Guided Workflow Workbench where users can build automations visually. Instead of writing code, a user can choose triggers, connect conditions, add actions, validate the workflow, and save it. The system also keeps a compatibility form nearby for teams that need the older structured form approach.

The intention is simple: help the business move from scattered manual follow-up to reliable, visible, and human-controlled automation.

## Product At A Glance

- Operating role: Automation command center for customer, sales, task, email, WhatsApp, form, stage, deal, and activity workflows.
- Primary audience: Founders, operators, sales teams, customer success teams, administrators, and implementation partners.
- Core promise: Turn repeated business decisions into consistent follow-up actions.
- Business result: Less missed follow-up, less manual coordination, cleaner handoffs, faster response, and more trust in daily operations.
- Source page: `http://localhost/crm/public/workflows.php`

## Plain English Pitch

Every small business has work that repeats. A new lead arrives. A customer replies. A deal moves stage. Someone fills out a form. A client has not been contacted for several days. A task becomes overdue. A new opportunity needs an owner.

Without a workflow system, the team has to remember what to do each time. That works for a while, then the business gets busier. Leads slip. Follow-up gets uneven. Good intentions turn into missed opportunities.

The Workflows page gives the business a simple way to say, "When this happens, do that." It helps the team build the habit once, then let the system help repeat it.

The result is not just automation. The result is consistency.

## What The Client Is Really Buying

Clients are not shopping for a CRM feature called workflows. They are shopping for relief from messy operations.

They want:

- A business that follows up even when the owner is busy.
- A team that knows what happens next.
- Customer conversations that do not disappear.
- Sales and support steps that happen in the right order.
- Automation that helps without taking reckless control.
- A system that can learn, suggest, and assist before acting.
- A way to grow without adding confusion at the same speed.

The Workflows page is built to support that result. It gives the business a practical path from "we should remember to do this" to "the system helps us do this every time."

## What The Page Is Built To Do

The Workflows page is intended to help teams:

- Create repeatable follow-up rules for common business events.
- See every workflow in one organized list.
- Activate or deactivate automation safely.
- Build workflows visually with a canvas-based workbench.
- Start from templates instead of rebuilding common automation logic.
- Validate workflow structure before saving.
- Test workflows before trusting them in live operations.
- Track executions, failures, retries, queue lag, and node-level issues.
- Use analytics to improve reliability over time.
- Connect workflow actions to CRM follow-up, email, WhatsApp, assignments, stage changes, tags, tasks, and wait steps.
- Keep AI-assisted actions under human control when the action needs review.
- Give administrators a clear way to manage automation settings, approvals, templates, and diagnostics.

## Salient Features

### Workflow Control Room

The Workflows page acts as the main control room for automations. A user can review all workflows in the active workspace from one screen, understand what each workflow does, and decide whether it should be running.

Each workflow row shows practical operating information, including the workflow name, active or inactive status, workflow mode, migration status, trigger type, action count, graph version, node count, execution count, success count, failure count, queue lag, pending retries, and creation date.

This matters because automation should never feel invisible. A business owner should be able to look at the page and know what is running, what is paused, what is healthy, and what needs attention.

### Active And Inactive Control

Users can activate or deactivate workflows directly from the page. This gives the team a safe way to pause an automation without deleting it.

For clients, this is important because business processes change. A campaign may end, a sales motion may shift, or a team may want to test a workflow before using it again. Activation control lets users keep the workflow design while deciding when it should run.

### Clear Trigger Visibility

Every workflow shows the trigger that starts it. A trigger is the event that tells the system, "Now is the time to act."

Example triggers include:

- A contact is created.
- An email is opened, clicked, received, replied to, bounced, or ignored for a set number of days.
- A WhatsApp message is received.
- A form is submitted or abandoned.
- A deal is created, updated, won, lost, or moved to a new stage.
- A task is created, completed, or overdue.
- A contact stage changes.
- There has been no activity for a set number of days.
- A scheduled daily, weekly, monthly, birthday, anniversary, or specific-date event occurs.
- A webhook or API event is received.

This helps users understand what business moment each workflow is designed to handle.

### Action Count And Workflow Shape

The list shows how many actions each workflow contains. It also shows graph version and node count so users and administrators can understand whether the workflow is simple or more advanced.

This gives the team a quick sense of workflow size. A short workflow may only send a reminder or create a task. A larger workflow may check conditions, wait, assign a user, send a message, change a stage, and record activity.

### Execution Reliability

The Workflows page is designed to make reliability visible. Users can see execution counts, successes, failures, queue lag, and pending retries.

This is more than reporting. It helps the business trust the automation. If a workflow is failing, lagging, or retrying too often, the team can see the signal and investigate before customer follow-up suffers.

### Queue And Retry Awareness

Workflows can run through a queue instead of forcing every action to happen at once. This supports more stable operations when many events happen at the same time.

If a temporary issue occurs, such as a timeout, connection problem, rate limit, or service availability problem, the system can treat the issue as retryable. Retry handling helps protect the business from losing work because of a short-lived technical problem.

For a client, the business meaning is simple: the system is designed to keep trying when it is reasonable to try again, and to surface problems when human attention is needed.

### Recommended For You

The Workflows page includes a recommendation area that can suggest useful templates for the workspace. Recommendations help users move faster by showing the next likely workflow instead of forcing them to start from a blank page.

The recommendation card can show why a workflow is being suggested, what impact it may create, and whether it matches available email template content.

This supports a guided product experience. The user does not need to know every possible automation idea before they begin.

### Create Workflow Entry Point

The page gives users a direct path to create a new workflow. This keeps automation creation close to automation management.

When a user clicks into creation, they enter a Guided Workflow Workbench that helps them build a workflow visually, save it, and keep compatibility with older form-based workflow payloads when needed.

### Guided Workflow Workbench

The Guided Workflow Workbench is the visual builder for creating and refining workflows. It is designed for people who understand their business process but do not want to write code.

The workbench includes:

- A setup area for workflow name, activation choice, and workflow type.
- A node palette for triggers, conditions, and actions.
- A canvas where users can place and connect workflow blocks.
- Validation tools that check whether the workflow is structurally ready.
- Import and export options for workflow JSON.
- A save rail that shows editing state, template source, canvas stats, and graph health.
- A compatibility form for teams that need a structured form version of the workflow.

This gives both newer and more technical users a practical way to build automation.

### Workflow Type Selection

Users can choose workflow modes such as Mixed Platform, CRM Ops, and Journey Automation. These modes help shape the kind of workflow being built.

For the client, this means the page is not treating every automation as the same. A simple CRM operations workflow, a customer journey workflow, and a mixed channel workflow may need different suggested blocks and validation rules.

### Node Palette

The node palette gives users a menu of building blocks. A user can search or filter the palette, then drag blocks into the canvas.

The available node categories support common business events and actions. This helps users think in business terms: contact event, email behavior, WhatsApp response, form submission, deal movement, task status, schedule, activity gap, webhook, condition, or action.

### Visual Canvas

The workflow canvas lets users see the process as a connected path. Instead of reading a long settings page, they can see the starting event, decision points, actions, and wait steps in one visual space.

This helps users explain automation to the team. A workflow becomes something that can be reviewed, discussed, tested, improved, and trusted.

### Validation Before Save

The builder includes workflow validation. Validation checks whether the graph is structurally ready before the user saves it.

If the workflow has issues, the user can resolve them before the workflow becomes part of live operations. If validation passes, the user receives a clear signal that the graph is ready to save.

For clients, validation reduces the fear of automation mistakes.

### Save Rail

The save rail gives the user a live summary while building. It can show current status, editing state, template source, canvas stats, and graph health.

This makes the builder feel less like a blank technical tool and more like a guided operating surface. The user can see whether they are editing a new draft, refining an existing workflow, or starting from a template.

### Import And Export

The builder includes import and export options for workflow JSON. This is useful for advanced users, implementation teams, and support teams that need to move workflows between environments or preserve a workflow design.

For most clients, the important value is portability and backup. The business is not locked into rebuilding a workflow by hand every time.

### Safe Clear Builder Action

The builder can clear the current canvas, but the action is treated carefully. The interface warns users that clearing removes the current nodes and connections from the canvas and does not change saved workflows until the user saves again.

This is a small but important trust feature. It makes the product safer for users who are experimenting.

### Compatibility Form

The Workflows builder keeps a compatibility form available for advanced or legacy editing needs. The form allows users to define the workflow name, trigger, conditions, actions, and activation state in a structured way.

This is useful for teams that prefer form-based setup, support staff who need a precise payload view, or existing workflows created before the visual builder became the main authoring path.

### Conditions

Conditions help workflows run only when the right business situation is true.

For example, a workflow might run when:

- A contact is in a certain stage.
- A lead score is above a threshold.
- A deal moved from one stage to another.
- A customer has had no activity for a defined number of days.
- A specific form field or customer property matches the rule.

Conditions stop workflows from acting too broadly. They help the business avoid sending the wrong message or assigning the wrong follow-up at the wrong time.

### Common Workflow Actions

Workflows can perform practical business actions after the trigger and conditions are satisfied.

Common actions include:

- Send an email.
- Send a WhatsApp message.
- Change a contact or deal stage.
- Assign a contact or task to a user.
- Create a task.
- Add or remove tags.
- Wait for a number of days.
- Record activity or notes.
- Apply smart tags when appropriate.
- Call connected automation or webhook actions when configured.

The value is not that the system has a long list of actions. The value is that the system can help move the relationship forward in a consistent way.

### Email Template Support

When a workflow sends an email, it can work with email templates. Users can pick a specific template or let the system auto-pick the best template based on intent, purpose, tone, lifecycle stage, audience, and workflow context.

This helps the business keep messages consistent while still matching the right moment in the customer journey.

### WhatsApp Follow-Up Support

Workflows can include WhatsApp actions where the channel is configured. This supports businesses that rely on conversational follow-up, fast replies, reminders, or customer updates through WhatsApp.

For many small businesses, WhatsApp is not an extra channel. It is where customer relationships actually happen. Workflow support helps make that communication more consistent.

### Assignments And Handoffs

Workflow actions can assign work to users. This helps move work from the system to the right person.

For example, a workflow can create or assign follow-up when a lead reaches a certain stage, when a form is submitted, or when a customer has not been contacted recently.

This supports one of the most important business results: fewer dropped handoffs.

### Wait Steps

Workflows can include wait steps. A wait step tells the system to pause before continuing.

This is useful for follow-up sequences. The system can wait a few days before checking whether a customer replied, opened a message, completed a step, or needs another touch.

Wait steps help automation feel timed instead of rushed.

### Workflow Template Library

The Workflow Template Library helps users build faster with proven automation patterns. Instead of starting with an empty canvas, a user can choose a shared template or a smart workflow template generated from workspace context.

The library is designed around categories, recommended workflows, and ready-to-customize cards. It supports common contact, sales, nurture, and re-engagement flows.

Templates are important because many businesses need similar workflow patterns but do not want to invent each one from scratch.

### Smart Workflow Pack

The template library can connect to a smart pack built from the business context. When the workspace has the right setup information, it can generate tailored workflow templates that know the company's strategy, positioning, validated idea, and related email templates.

For the client, this means the system can become more personal over time. It does not only offer generic automation ideas. It can recommend workflows that fit the actual business.

### AI Shortlist And Recommendations

Recommended workflows give the user a shortlist of likely next automations. This helps clients avoid staring at a catalog and wondering what to do first.

The system can suggest relevant templates and show why they may matter. That moves the user from feature browsing to decision making.

### Workflow Detail Page

Each workflow can be opened for review. The detail page shows its status, trigger, graph summary, actions, creation date, graph preview, execution history, and node hotspots.

This page helps the team understand a workflow after it has been created. It also supports troubleshooting because users can see where a workflow has run and where it may be failing.

### Test Workflow

The workflow detail page includes a test workflow tool. A user can choose a contact or use a test contact, then run the workflow in test mode.

The test result can show:

- Whether conditions were met.
- Whether the workflow would execute.
- Which conditions passed or failed.
- Which actions would run.
- Which actions would be skipped.

This is one of the strongest trust features in the workflow system. It lets the team check the logic before relying on it.

### Execution History

Workflow execution history shows recent workflow runs. It can include the related contact, status, timestamp, and error message when something fails.

This gives teams accountability. If a customer asks why a follow-up happened or did not happen, the business can review the workflow history instead of guessing.

### Node Hotspots

Node hotspots show which workflow nodes ran and which ones failed. This helps administrators find the part of the workflow that needs attention.

Without node-level visibility, troubleshooting can be slow. With hotspots, teams can focus on the actual problem area.

### Workflow Analytics

The Workflow Analytics page gives users a broader performance view. It shows performance metrics, execution reliability, queue lag, retries, and failure hotspots.

Analytics can show:

- Total executions.
- Success rate.
- Failed executions.
- Average execution time.
- Average queue lag.
- Retry counts.
- Top errors.
- Node failure hotspots.

This helps clients improve automation over time. A workflow is not just built once and forgotten. It can be measured, tuned, and trusted.

### Diagnostics Link

The analytics page can open diagnostics for deeper review. Diagnostics are useful when a workflow needs investigation, especially around reliability, queue performance, AI behavior, or automation governance.

This makes the workflow system more supportable for teams and implementation partners.

### Human-Controlled Automation

Human-controlled automation is one of the most important intentions of the Workflows page. The system is designed to help before it blindly acts.

Some actions are safe enough to run automatically. Other actions, especially customer-facing messages or actions with low confidence, may require review, approval, or suggestion-only handling.

The system can evaluate the context, check confidence, consider channel and recipient, respect workspace automation settings, and decide whether to apply the action, request approval, suggest only, or reject the action.

This helps clients get the time savings of automation without losing judgment.

### AI-Safe Handling

The builder and execution services are designed to surface AI-related states without breaking workflow saving or execution. The workflow system can work with AI proposals, automation settings, approvals, and diagnostic modes.

For clients, the key message is simple: AI can help propose or execute parts of a workflow, but the business stays in control.

### Automation Settings And Approvals

The Workflows page can link users to automation settings and approvals when the workspace has the related capability enabled.

This supports governance. Administrators can decide how automation should behave, what needs approval, and how much autonomy the system should have.

### Page Guidance

The page can include a Workflows page guide. This supports onboarding and client education by giving users guidance in the page where the work happens.

For a product built for entrepreneurs and growing teams, guidance matters. Users should not have to leave the workflow screen to understand why the page exists.

## Product Intentions

The Workflows page is built with several clear intentions.

### 1. Make Follow-Up Consistent

The page helps a business build rules for repeatable follow-up. This protects relationships because the system remembers what the team might otherwise forget.

### 2. Reduce Manual Coordination

Repeated work should not require repeated manual planning. Workflows help the team set up the pattern once, then let the system help carry it out.

### 3. Keep Humans In Control

Automation should not mean giving up judgment. The workflow system supports approvals, suggestion-only behavior, diagnostics, confidence thresholds, and safe blocking when the action should not run automatically.

### 4. Make Automation Visible

Users can see workflow status, execution results, queue lag, failures, retries, and analytics. This visibility makes automation easier to trust.

### 5. Help Users Start Faster

Templates, recommendations, and smart workflow packs reduce the blank-page problem. The system can help users choose the next useful workflow instead of forcing them to design every process from scratch.

### 6. Support Growth Without Chaos

As a business grows, manual follow-up becomes harder to manage. Workflows help keep the operation organized without needing every process to live in someone's head.

## The Result We Are Selling

The Workflows page is selling a better operating rhythm.

It helps a business:

- Respond faster.
- Follow up more consistently.
- Reduce forgotten tasks.
- Keep customer journeys moving.
- Protect handoffs between team members.
- Give founders more confidence that daily work is being handled.
- Use AI and automation without losing human control.
- Spot problems before they become customer problems.

The best way to explain the value is this: Workflows helps turn business memory into repeatable action.

## Why This Matters For Entrepreneurs

Entrepreneurs often begin by doing everything themselves. They remember the leads, the calls, the messages, the follow-ups, and the next steps. That is possible when the business is small.

As the business grows, memory stops being enough. More customers means more promises. More promises means more chances for something to slip.

The Workflows page helps the entrepreneur build a system around those promises. It gives the business a way to say:

- When a lead comes in, start the right follow-up.
- When a customer goes quiet, bring them back to attention.
- When a deal moves forward, assign the next step.
- When a message is opened but not answered, follow up later.
- When a form is submitted, alert the right person.
- When AI is confident, let it help.
- When AI is not confident, ask a human first.

This is the difference between running a business by memory and running it by a repeatable process.

## CRM Follow-Up, AI Automation, And Human Control

The Workflows page supports three major promises of the Clarity system.

### CRM Follow-Up Creates Consistency

Workflows connect customer events to practical follow-up. The system can create tasks, assign users, send messages, update stages, tag contacts, and wait for the right timing.

This makes customer follow-up more consistent across the team.

### AI Automation Saves Time And Money

Automation can reduce repeated manual work. Instead of asking a team member to check the same trigger every day, the system can watch for the trigger and help prepare or perform the next step.

This saves time, reduces administrative drag, and lets people focus on higher-value conversations.

### Human Control Helps The System Learn Before It Acts

Human-controlled automation lets the system learn from decisions. Some workflow actions can be blocked, suggested, or sent for approval when the context is sensitive.

This gives the business a practical balance: use AI where it helps, but keep humans responsible for judgment.

## Best-Fit Use Cases

### Lead Capture Follow-Up

When a new lead enters the CRM, a workflow can create a task, assign an owner, send a first message, or move the contact into the right stage.

Client result: fewer new leads go cold.

### Re-Engagement

When a contact has no activity for several days, a workflow can remind the team, send a check-in, or assign a follow-up.

Client result: quiet opportunities come back into view.

### Sales Stage Movement

When a deal or contact moves to a new stage, the workflow can create the next step, assign the right user, or send the right message.

Client result: sales handoffs become more consistent.

### Email Behavior Follow-Up

When a customer opens, clicks, replies, bounces, or does not respond to an email, the workflow can guide the next action.

Client result: the team responds to customer behavior instead of guessing.

### WhatsApp Response Handling

When a WhatsApp message is received, the workflow can help route the conversation, assign a user, or continue the follow-up path.

Client result: conversational sales and support become easier to manage.

### Task Escalation

When a task is overdue or completed, workflows can help trigger the next action.

Client result: work does not stall quietly.

### Form Submission Response

When a customer submits a form, the workflow can notify the team, assign the contact, send a confirmation, or start a journey.

Client result: form submissions become active opportunities.

## Recommended Pairings

The Workflows page becomes stronger when paired with other parts of the system.

### Contacts Page

Contacts provide the people and relationship history that workflows act on.

### Tasks Page

Tasks turn workflow outcomes into assigned human follow-up.

### Email Assistant And Email Plugin

Email tools support template-driven outreach, email actions, and customer communication.

### WhatsApp Assistant And WhatsApp Plugin

WhatsApp tools support conversational follow-up and message-based automation.

### Calendar And Meetings Plugin

Calendar context can help workflows connect follow-up to meeting activity and scheduled business moments.

### Business Intelligence Plugin

Business intelligence helps the team understand which workflow outcomes are improving performance.

### AI Coach And Clarity Journey

AI guidance and business strategy context can help recommend better workflows and smarter next actions.

## What Makes The Workflows Page Different

The Workflows page is not only a builder. It is a full operating surface for automation.

It combines:

- A management list.
- A visual builder.
- A compatibility form.
- A template library.
- Smart recommendations.
- Test tools.
- Execution history.
- Analytics.
- Queue and retry visibility.
- Human approval controls.
- AI-safe automation behavior.

That combination matters because real businesses need more than "if this, then that." They need automation they can create, understand, test, measure, pause, improve, and trust.

## Client Value Proposition

The Workflows page helps clients build a business that follows through.

It gives the team a way to turn repeated business moments into dependable action. It helps users start with templates, build visually, validate before saving, test before trusting, and monitor after launch.

It also respects the reality that automation can be powerful and risky. That is why it supports human-controlled automation, approvals, diagnostics, and safe handling for AI-assisted work.

For a founder or growing team, the page delivers a simple promise: your business should not depend on everyone remembering everything.

## Buyer Outcome Summary

The buyer should understand the Workflows page as a solution for:

- Missed follow-up.
- Uneven customer communication.
- Manual handoffs.
- Repeated administrative work.
- Slow response to customer behavior.
- Automation that is hard to trust.
- AI actions that need human oversight.
- Growth that creates operational confusion.

The Workflows page helps turn those problems into a more consistent operating rhythm.

## Suggested Product Promo Copy

### Short Website Blurb

Workflows turns repeated business moments into reliable action. Build automations visually, start from smart templates, test before launch, and keep humans in control when AI-assisted actions need judgment.

### Sales One-Liner

Workflows helps your business follow up, assign, remind, message, and move customers forward without depending on memory.

### Client Conversation Script

"Most growing businesses do not lose opportunities because they do not care. They lose them because follow-up depends on memory. The Workflows page gives your team a repeatable way to decide what should happen next when a customer takes action. It can send messages, create tasks, assign owners, change stages, wait for the right timing, and recommend useful templates. Most importantly, it keeps automation visible and controlled, so AI can help without taking over judgment."

## Client Checklist

A client is likely ready for the Workflows page when they say:

- "We keep forgetting follow-ups."
- "Leads are coming in, but we do not always respond fast enough."
- "Our team handles the same steps over and over."
- "We need a better handoff process."
- "We want automation, but we do not want it to run wild."
- "We need to know what automation is working and what is failing."
- "We want AI to help, but we still want approval where it matters."

## Final Message

The Workflows page is about dependable execution. It gives a growing business a way to turn customer events, sales steps, messages, form submissions, task changes, and quiet periods into clear next actions.

It sells the result, not the machinery: fewer missed moments, stronger follow-up, safer automation, and a business that can grow without relying on memory alone.
