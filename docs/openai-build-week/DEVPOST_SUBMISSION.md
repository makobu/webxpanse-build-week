# Devpost submission draft

This copy is written in Dennis Makobu's founder voice. Replace every bracketed placeholder before submission.

## Project name

WebXpanse — Clarity

## Tagline

The evidence-backed operating rhythm for solo founders.

## Track

Work & Productivity

## Inspiration

I have spent several months building WebXpanse as a solo founder. The more capable the product became, the more clearly I saw the founder's real problem: I did not need another screen telling me everything that was happening. I needed a reliable way to decide what mattered most, understand why, and turn that decision into action.

Solo founders work across customer conversations, tasks, pipeline, finance, strategy, and product delivery. General AI can offer ideas, while traditional CRMs can store activity, but neither automatically creates a daily operating rhythm from the founder's real business evidence. I built Clarity to join those two worlds.

## What it does

Clarity is the AI co-founder experience inside WebXpanse. It builds a structured picture of the business, combines it with current CRM evidence, and ranks one founder constraint at a time.

The dashboard shows:

- the highest-priority constraint and why it matters now;
- the evidence behind the ranking;
- work that still needs founder judgment;
- only completed, verifiable automation in the “handled for you” lane;
- one current commitment and a measurable growth loop.

When I click “Ask Clarity why,” the visible constraint, reason, and evidence are sent into the Clarity conversation. The model explains what the evidence means, what it cannot decide for me, and the next measurable action. I can then open the related commitment or task and continue the work inside the CRM.

## How I built it

WebXpanse is a self-hosted PHP and MySQL application with workspace tenancy, CRM records, tasks, communication channels, pipelines, reporting, permissions, and automation services.

For Clarity, I added a deterministic context and decision layer around the model:

1. BusinessContextSnapshotService assembles company, strategy, customer, financial, journey, and founder-loop context with source and readiness information.
2. Founder attention providers turn current evidence into normalized signals.
3. FounderCommandCenterService ranks those signals and keeps the visible list intentionally small.
4. AIContextAssemblyService sends source-labelled workspace and page context to Clarity.
5. AIService uses the OpenAI Responses API on official OpenAI endpoints, with safe compatibility and deterministic fallback paths.
6. The dashboard sends the exact ranked constraint to Clarity and connects the result to a dated founder commitment.

I also built a temporary, resettable presentation workspace for judging. It installs only demo-scoped Clarity capabilities, prevents live delivery by default, and seeds a coherent solo-founder scenario with complete business context, three active opportunities, zero false wins, and one outreach commitment.

I used Codex with GPT-5.6 for the Build Week implementation, debugging, focused tests, browser verification, and submission preparation. Earlier product work also used ChatGPT and Codex with GPT-5.4 and GPT-5.5. I worked solo and made the final product decisions.

## What existed before Build Week

WebXpanse existed before the event and has been under development for several months. The pre-existing foundation includes the multi-workspace CRM, contacts, deals, tasks, email and WhatsApp surfaces, reporting, permissions, and automation infrastructure.

My Build Week contribution focused that foundation into the Clarity entry: the AI business operating-system positioning, founder operating-rhythm command center, evidence-backed constraint flow, Founder Loop, mobile Decision Center parity, direct “Ask Clarity why” handoff, resettable solo-founder judging workspace, and product hardening. The repository includes a Build Week change log with milestone hashes from the original July 14–19, 2026 development history.

## Challenges I ran into

The hardest problem was not generating more advice. It was deciding what the model should never be trusted to invent. I separated deterministic evidence collection and ranking from model-based explanation, and I created a distinct lane for work that was actually completed and verified.

Another challenge was making a large existing product understandable in three minutes. I narrowed the demo to one founder, one constraint, three live opportunities, and one commitment. The presentation seed and browser tests helped remove contradictory signals such as false won deals or incomplete setup warnings.

## Accomplishments I am proud of

- Clarity can explain a priority using the same evidence the founder sees.
- The product distinguishes founder judgment from verified automation.
- The recommendation leads directly to a dated commitment and CRM task.
- The demo workspace is temporary, resettable, simulation-first, and isolated from normal customer workspaces.
- The Build Week experience sits inside a substantial working product rather than a disconnected prototype.

## What I learned

AI becomes more useful when the product does more work before the prompt. Clean context, explicit provenance, small decision surfaces, and strong action boundaries matter as much as model capability.

I also learned that “AI co-founder” should describe an operating relationship, not unrestricted autonomy. The founder should see the evidence, understand the recommendation, and remain responsible for commercial judgment.

## What's next

Next I want to measure whether the operating rhythm shortens time-to-decision and improves commitment completion for real solo founders. I will add feedback-driven ranking evaluation, deeper outcome attribution, and carefully bounded automations that can graduate from suggestion to execution only when evidence and user controls support it.

## Links

- Try it: **[ADD LIVE DEMO URL, if public]**
- Source code: https://github.com/makobu/webxpanse-build-week
- Demo video: **[ADD PUBLIC YOUTUBE URL]**
- OpenAI feedback: `019f74ad-26fd-7713-a474-cb83d9cb101e`

## Technologies

PHP, MySQL, JavaScript, OpenAI Responses API, GPT-5.6, PHPUnit, Playwright, Apache/XAMPP, Codex, ChatGPT
