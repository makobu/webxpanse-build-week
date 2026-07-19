# Email Assistant Plugin

Client-facing product promo and feature summary

Prepared: June 22, 2026

## Product Positioning

The Email Assistant plugin is the assisted email operations layer for WebXpanse and Clarity CRM. It helps a business owner move from a crowded inbox to a calmer system for drafting replies, handling email instructions, summarizing daily work, and keeping customer follow-up consistent.

This plugin is not just an email sender. The Email channel handles the basic ability to send and receive email. Email Assistant sits above that channel and helps the team use email more intelligently once the workspace email identity is ready.

For a client, the value is simple: Email Assistant helps turn email from a pile of messages into guided follow-through.

## Executive Summary

Email Assistant helps email-led businesses work faster without losing control of customer communication. It can support customer reply drafting, outbound draft help, inbound instruction handling, daily digest routines, CRM command skills, and review-controlled customer sends.

The plugin is designed for entrepreneurs and teams whose customers, prospects, partners, and internal operators rely on email. In many small businesses, important work arrives through email but gets buried. A customer asks for a quote. A prospect replies with a question. An owner wants to create a task by emailing the assistant. A manager wants a morning digest of tasks and recommendations. Email Assistant helps organize those moments.

It gives the workspace a setup path for assistant identity, outbound sending, inbound IMAP or Gmail connection, command skills, digest timing, tests, activity history, and advanced controlled follow-up templates. It also records assistant runs so admins can review what the system understood, what it decided, what confidence it had, what policy applied, and whether a human review was required.

The result is not "AI writing emails." The result is safer, faster, more consistent email follow-through.

## Product At A Glance

- Operating role: Assisted email drafting, inbound email instructions, customer-thread support, daily digests, and email automation governance.
- Primary audience: Founders, entrepreneurs, sales teams, customer support teams, account managers, operators, and workspace admins.
- Core promise: Turn email into a guided operating channel without removing human control.
- Key motion: Configure sender identity, connect outbound and optional inbound email, enable safe skills, test readiness, draft replies, process instructions, and review runs.
- Marketplace role: Installable assistant plugin that depends on the Email channel being ready first.
- Source page: http://localhost/crm/public/workspace_skills.php?module=email_assistant
- Runs page: http://localhost/crm/public/email_assistant_runs.php
- Capabilities page: http://localhost/crm/public/email_assistant_capabilities.php

## Plain English Pitch

Email Assistant helps the owner stop losing business momentum inside the inbox.

Instead of starting every reply from a blank page, the assistant can help draft customer responses from workspace context. Instead of relying on memory to create CRM tasks, the owner can send email instructions to the assistant when inbound processing is enabled. Instead of manually checking every task and recommendation each morning, the team can receive a daily digest.

It helps answer practical questions:

- Which customer email needs a response?
- What should we say back?
- Is this customer asking for a quote, invoice, discount, revision, or resend?
- Is the contact, deal, or invoice clear enough to act on?
- Should the assistant draft only, request approval, or block the action?
- What tasks or recommendations should the owner see today?
- Which assistant skills are enabled?
- Is outbound sending ready?
- Is inbound instruction capture ready?
- Did the assistant run safely, and what did it decide?

The owner stays in control. The assistant can suggest, draft, queue, or act only inside the workspace's configured rules.

## The Problem It Solves

Email is still where a lot of business happens.

Customers ask questions. Prospects request pricing. Existing clients send documents. Leads reply after campaigns. Internal users forward instructions. Managers ask for updates. But email can quickly become a messy operating system.

Without an assistant layer, the business faces common problems:

- Replies take too long because every message starts from scratch.
- Follow-up tasks are forgotten after reading an email.
- Customer context is spread across the inbox, contacts, deals, invoices, and notes.
- Owners spend too much time checking what needs attention.
- Team members send inconsistent customer responses.
- Operators ask for CRM work, but the instruction is not turned into a tracked action.
- AI tools may draft messages without knowing the contact, deal, invoice, or policy context.
- Customer-facing sends may happen without enough confidence or approval.
- Admins cannot easily review what the assistant did.

Email Assistant solves this by putting a controlled assistant layer above the email channel.

## What The Client Is Really Buying

The client is not buying an email bot.

The client is buying a calmer, more consistent way to manage email follow-through.

They are buying:

- Faster response drafting.
- Fewer missed email follow-ups.
- A safer way to use AI for customer communication.
- Daily visibility into tasks and AI Coach recommendations.
- Email instructions that can become CRM actions.
- Review controls before sensitive customer-facing actions.
- A record of assistant runs, decisions, warnings, and confidence.
- A setup path that makes sender identity and readiness visible.
- Better use of CRM context inside replies.
- A practical bridge between inbox activity and business execution.

## The Core Promise

Email Assistant helps answer one practical question:

What should happen next from this email?

That promise matters because business owners are not shopping for more inbox features. They are shopping for a solution that saves time, protects customer trust, and turns communication into action.

## What Email Assistant Is

Email Assistant is an installable assistant plugin inside WebXpanse and Clarity CRM.

It works after the workspace Email channel is ready. The Email channel provides the sending foundation. Email Assistant adds the assisted layer: identity, drafting, command skills, inbound processing, customer-thread support, daily digests, tests, and runtime review.

In practical terms, the plugin can help with:

- Drafting replies to customer email threads.
- Supporting customer-thread send workflows when policy allows.
- Reading CRM context before drafting a reply.
- Processing inbound admin instructions from authorized senders.
- Answering CRM questions by email when Q&A is enabled.
- Creating tasks from email instructions.
- Creating or updating contacts when enabled.
- Adding notes to contacts.
- Reporting pipeline status.
- Listing tasks.
- Scheduling events.
- Running reports.
- Sending daily digest emails.
- Recording assistant runs for review.

It is built to assist the team, not to take over the business.

## What It Helps The Entrepreneur Do

Email Assistant helps the entrepreneur turn email into a more reliable work channel.

It helps the owner:

- Respond to customers faster.
- Keep customer replies connected to CRM context.
- Draft messages with a consistent tone.
- Use email instructions to create tasks or update CRM records.
- Get a morning digest of tasks and recommendations.
- Review assistant decisions before trusting bigger actions.
- Keep sender identity and outbound readiness visible.
- Control which command skills are enabled.
- Restrict inbound instructions to authorized users or allowed senders.
- See a history of assistant runs, policy decisions, warnings, and confidence.

## Salient Features

Email Assistant is built around practical features that support real email-heavy work.

### 1. Marketplace Setup

Email Assistant is installed and configured from the Marketplace plugin page.

The setup is organized into focused sections:

- Identity.
- Outbound sending.
- Inbound IMAP.
- Skills.
- Digest.
- Tests.
- Activity.
- Advanced settings.

This gives a workspace admin a clear rollout path instead of hiding important controls in one crowded settings page.

### 2. Email Channel Dependency

Email Assistant depends on the Email plugin being ready first.

This is important because the assistant should not be allowed to send or process email until the basic email channel is configured. The Marketplace readiness flow checks whether the workspace has the right sender setup before the assistant is treated as ready.

That makes the product safer for clients. The assistant is built on a real email foundation, not a guess.

### 3. Assistant Identity

The setup includes assistant identity controls such as system email, from email, from name, and default tone.

This matters because customers should know who they are hearing from. A recognizable sender identity protects trust and makes the assistant feel like part of the business rather than a random automation.

Tone settings can support a professional, warm, or direct style, helping the team keep communication consistent.

### 4. Assistant Gmail Connection

The plugin can support Assistant Gmail through OAuth when the platform app is configured.

This gives teams a modern connection path when they prefer OAuth over manual SMTP and IMAP credentials. It can also reduce setup friction for organizations already using Google email.

### 5. Outbound Sending Setup

Email Assistant supports outbound sending through configured SMTP or a supported connected provider.

The setup includes host, port, username, password, encryption, from email, and from name. Readiness checks confirm that outbound sending is ready before the team relies on live assistant emails.

This protects the business from launching email automation before sending is actually configured.

### 6. Optional Inbound IMAP

Inbound IMAP can be enabled when the team wants the assistant to process messages sent to the system address.

Inbound setup includes host, port, username, password, protocol, encryption, folder, and allowed senders. This supports workflows where authorized users can send email instructions to the assistant.

Inbound is optional because not every business needs email instruction capture on day one.

### 7. Allowed Senders And Authorization

Email Assistant is permission-aware.

Only admin users or whitelisted sender emails can send instructions to the assistant. If a sender is not authorized, the system replies with a clear authorization message instead of executing the request.

This is a major safety feature. It helps prevent random or unauthorized senders from using the assistant to change CRM data.

### 8. Command Skills

The plugin lets the workspace choose which command skills are enabled.

Supported skills include:

- Q&A about CRM data.
- Create task.
- Create contact.
- Update contact.
- Delete contact.
- Enrich contact.
- Verify email.
- Add note.
- Pipeline status.
- List tasks.
- Schedule event.
- Run report.

Each skill can be enabled or disabled. This lets the business roll out low-risk skills first and add more powerful skills only when the team is ready.

### 9. Gradual Rollout

Email Assistant is designed for gradual activation.

A sensible rollout can begin with low-risk read-only skills such as Q&A, pipeline status, and list tasks. Then the team can enable create actions such as create task, create contact, and add note. Later, after testing, the workspace may enable mutation skills such as update contact, enrich contact, or verify email. Destructive actions such as delete contact should be treated as last-step admin decisions.

This helps the client adopt AI safely instead of turning on every capability at once.

### 10. Customer Reply Drafting

Email Assistant can draft customer replies from a conversation thread.

The assistant can look at the contact, deal, invoice or quote, recent messages, thread summary, and commercial signals. It can recognize when a customer is asking for a quote, invoice, discount, revision, terms, or resend.

This helps the team write better responses faster because the draft is based on business context, not a generic prompt.

### 11. Customer Reply Send Controls

The plugin can support sending customer replies, but only inside policy controls.

Before a customer-facing send, the system evaluates context, confidence, goal relevance, commercial policy, and governance controls. Depending on the result, the assistant may be allowed, allowed with warning, blocked, suggest-only, or required to queue approval.

That means customer communication remains controlled.

### 12. Commercial Workflow Support

Email Assistant can connect email conversations to commercial work.

When a customer thread is linked to a deal, quote, proforma, or invoice, the assistant can help plan safe actions such as drafting a proposal reply, preparing an invoice reply, handling negotiation context, creating a commercial draft, revising a quote, or sending the latest quote when policy allows.

This is valuable for businesses where sales and billing conversations happen by email.

### 13. Inbound Admin Instructions

When inbound processing is enabled, authorized users can send instructions to the assistant's system address.

The assistant can interpret the email, resolve the intended contact, deal, invoice, task, or approval, plan the action, check policy, execute what is safe, and reply with the result.

This turns email into an operating surface, not just a communication channel.

### 14. Q&A And CRM Answers

When Q&A is enabled, users can ask questions about CRM context by email.

For example, they can ask about contact counts, task counts, pipeline value, pending tasks, or other CRM status questions supported by the assistant.

This helps busy owners get answers without opening every dashboard.

### 15. Daily Digest

Email Assistant can send a daily digest.

The digest can include tasks and AI Coach recommendations, delivered to workspace admins or custom recipients at a configured send time. A CLI worker can send scheduled digests after the configured time and avoid duplicate sends for the day.

This helps the owner start the day with a clearer view of what needs attention.

### 16. Test Digest

The setup includes a live test digest flow.

Admins can send a test digest to an explicit recipient before relying on scheduled live digests. This confirms sender readiness, rendering, and delivery behavior.

That gives clients confidence before launch.

### 17. Readiness And Health Checks

Email Assistant includes readiness and health checks.

The system can show whether the assistant is enabled, outbound sending is ready, inbound setup is ready, SMTP or OAuth is configured, sender identity exists, skills are enabled, digest controls are set, and optional reopen template settings are present.

This helps the client understand what is missing before the assistant is used in live workflows.

### 18. Runs Page

The Email Assistant Runs page gives admins visibility into recent assistant decisions.

Runs can be filtered by mode, intent, policy decision, resolution status, and execution status. The page shows counts for visible runs, executed runs, review-needed runs, blocked runs, and average confidence.

This gives the business a review trail. It is easier to trust automation when the team can see what happened.

### 19. Policy Decisions And Confidence Signals

Assistant runs can include policy decisions, confidence score, context quality score, goal relevance score, warnings, approval requirements, and whether execution is allowed.

These signals help the client understand why the assistant acted, paused, or blocked an action.

This is important because the product is not trying to make AI invisible. It is trying to make AI understandable and governable.

### 20. Approval Queue Support

When a customer send or commercial action needs approval, the system can queue the action instead of forcing it through.

This keeps sensitive actions from happening too quickly. It also gives the team a way to review edge cases, high-value actions, weak context, or actions that touch quotes, invoices, discounts, and customer commitments.

### 21. Activity Logging

Email Assistant can support activity logging so the workspace can track assistant behavior.

This is useful for reviewing adoption, debugging errors, and learning which workflows are safe enough to expand.

### 22. Reopen Template

The advanced setup includes an optional reopen template for controlled follow-up messages.

This can support situations where the assistant needs a structured way to re-open or follow up on an email conversation. Admins can control template name, language, subject, body, call-to-action label, and URL.

### 23. Security For Stored Credentials

Workspace assistant configuration stores sensitive credential values carefully.

Secrets such as SMTP password, IMAP password, and access tokens are encrypted for storage and masked when displayed without secret access.

This matters because email automation touches real communication credentials.

### 24. Background Jobs

Email Assistant supports background job workflows such as daily digest sending and inbound assistant email fetching.

This allows the assistant to support ongoing email operations rather than only manual button clicks.

## Product Intentions

The intention of Email Assistant is to make email easier to manage without making customer communication careless.

It is built around a simple belief: email should lead to action, but action should still be controlled.

The plugin is intended to:

- Save time by drafting replies and summarizing daily work.
- Create consistency in customer communication.
- Reduce missed follow-up from email.
- Let authorized users turn email instructions into CRM work.
- Keep sender identity and email readiness visible.
- Require setup and tests before live use.
- Keep sensitive actions inside confidence, policy, and approval gates.
- Give admins a review trail for assistant behavior.
- Help AI Coach and the CRM use email context more effectively.

## The Result We Are Selling

Email Assistant is not selling impressive AI tricks.

It is selling a better email operating rhythm.

The client gets a system where emails can lead to clearer replies, safer decisions, tracked tasks, better daily visibility, and fewer missed customer moments. The owner spends less time digging through the inbox and more time moving customer work forward.

The result is:

- Faster email drafting.
- Better customer follow-up.
- Better inbox discipline.
- Better daily awareness.
- Better CRM action from email.
- Better AI control.
- Less work slipping through inbox cracks.

## Why This Matters To A Small Business

A small business often wins or loses trust through email.

A slow reply can make the business look disorganized. A missed follow-up can cost a deal. A confused quote response can create friction. A forgotten task can break a promise. A careless automated send can damage customer confidence.

Email Assistant helps reduce those risks by supporting a repeatable email rhythm:

- Configure the sender identity.
- Test outbound readiness.
- Enable only the skills the team trusts.
- Draft replies from CRM context.
- Process authorized instructions.
- Send daily digests.
- Review assistant runs and policy decisions.
- Expand automation only after the team sees good evidence.

That is a practical path for entrepreneurs who want AI help but still care about control.

## Best-Fit Use Cases

Email Assistant is especially useful for:

- Businesses where most customer work arrives by email.
- Founders who need help keeping up with replies.
- Sales teams that handle quotes, proposals, invoices, and follow-up by email.
- Customer support teams that need faster response drafting.
- Operators who want to create tasks or CRM updates by sending email instructions.
- Managers who want a daily digest of tasks and AI Coach recommendations.
- Teams that need a review trail before trusting AI automation.
- Workspaces already using the Email plugin and ready for assisted workflows.

## Client-Facing Value Proposition

Email Assistant gives your business a safer way to bring AI into email.

It can draft replies, process approved instructions, send daily digests, and help turn email into CRM action. It keeps sender identity, permissions, confidence, and review controls visible so your team can move faster without letting automation run wild.

In simple terms:

Your inbox becomes a place where work gets understood, organized, and followed up.

## How It Supports Sales Follow-Up

Sales follow-up often happens inside email threads.

A prospect asks for a quote. A customer requests an invoice. Someone asks for a discount. A buyer says they need changes. Email Assistant can help the team understand the thread, draft a response, connect the message to contact, deal, invoice, or quote context, and follow policy before sending or revising anything important.

That creates a stronger sales habit:

- The customer replies.
- The assistant reads the thread context.
- The system checks contact, deal, and commercial records.
- A draft is prepared.
- Policy decides whether the action can continue.
- The team reviews warnings, confidence, and approval needs.
- Follow-up happens with more consistency.

The owner gets speed without giving up judgment.

## How It Supports Human Control

Email Assistant is designed around human control.

The workspace decides which skills are active. It chooses whether inbound instructions are enabled. It controls allowed senders. It sets confidence thresholds. It can require approval for sensitive actions. It records run history. It can block actions when context is ambiguous or policy says no.

This matters because customer email is high-trust work. A useful assistant should help the team move faster, but it should also know when to pause.

Email Assistant helps the system learn before it acts.

## How It Supports AI Coach And Clarity

Email Assistant makes customer communication more usable for the rest of the workspace.

When email activity becomes structured, the CRM can better understand customer needs, tasks, pipeline movement, and follow-up urgency. AI Coach can use that operating context to produce better recommendations. Clarity can guide the owner with more awareness of what is happening in the business.

This supports the larger WebXpanse promise:

Meet your AI cofounder: discuss the plan, decide the next move, and let a diligent partner help implement it.

## Recommended Pairings

Email Assistant becomes more valuable when paired with other WebXpanse and Clarity capabilities.

Recommended pairings include:

- Email: required foundation for sending and receiving email.
- AI Coach: turns email and CRM signals into better next-step recommendations.
- Contacts: keeps customer identity and history connected to email work.
- Deals: connects sales email follow-up to pipeline context.
- Tasks: turns email instructions and follow-up needs into tracked work.
- Calendar & Meetings: supports meeting follow-up after email conversations.
- Business Intelligence: helps leadership understand communication and follow-up performance.
- Clarity Journey: keeps email follow-through aligned with the founder's business direction.

## What It Does Not Do

A clear product promise should also be honest about limits.

Email Assistant does not replace the Email plugin. It does not send safely until sender identity and outbound readiness are configured. It does not make all skills safe to enable at once. It does not remove the need for human review on sensitive customer-facing actions. It does not guarantee every customer thread has enough context to act. It does not turn weak or ambiguous instructions into reliable decisions.

Instead, it gives the workspace a safer structure:

- Complete Email setup first.
- Configure assistant identity.
- Enable outbound sending.
- Add inbound processing only if needed.
- Turn on skills gradually.
- Test before live use.
- Review assistant runs.
- Let stronger automation earn trust over time.

That is the right promise for a serious business tool.

## Buyer-Friendly Outcome Summary

If the client is asking, "Why should I care?" the answer is straightforward.

Email Assistant helps the business:

- Save time on repetitive email work.
- Reply to customers faster.
- Reduce missed follow-up.
- Turn email instructions into CRM action.
- Send daily task and recommendation digests.
- Keep customer sends inside policy and confidence checks.
- Give admins a review trail.
- Use AI without surrendering control.

It helps the owner feel less buried by the inbox and more supported by the system.

## Suggested Promo Copy

Short version:

Email Assistant turns inbox work into guided follow-through. Draft replies, process approved instructions, send daily digests, and keep AI-powered email actions inside clear identity, confidence, and review controls.

Website or proposal version:

Your inbox should not be where customer promises disappear. Email Assistant helps your team draft replies, process authorized email instructions, summarize daily work, and connect customer threads to CRM context. It keeps sender identity, readiness checks, confidence signals, and approval gates visible, so your business can move faster without losing control of customer communication.

Demo talk track:

This plugin is for businesses that run a lot of work through email. The goal is not to replace your team. The goal is to help your team respond faster and follow up more consistently. You configure the assistant identity, connect outbound sending, optionally enable inbound instructions, turn on only the skills you trust, and test readiness before live use. When the assistant drafts or acts, the runs page shows what it understood, what policy decided, and whether review was needed.

## Closing Message

Email Assistant helps turn email into action.

It gives the entrepreneur a practical way to draft faster, follow up better, and use AI with visible controls. It supports the bigger WebXpanse promise: AI automation saves time and money, CRM follow-up creates consistency, and human control helps the system learn before it acts.

With Email Assistant, the inbox stops being a place where work gets lost. It becomes a guided channel for customer response, CRM action, and daily operating focus.
