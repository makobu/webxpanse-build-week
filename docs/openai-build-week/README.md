# WebXpanse — OpenAI Build Week 2026

## The entry

WebXpanse is the platform; Clarity is the AI co-founder experience entered in the Work & Productivity track.

Solo founders rarely lack software. They lack a calm operating rhythm that can answer: What matters most today, why does it matter, what still requires my judgment, and what should happen next?

Clarity answers those questions from structured business context and live CRM evidence. The Build Week experience joins five steps into one loop:

1. Understand the business through company context, strategy, Lean Canvas, and the Clarity Journey.
2. Combine that context with contacts, deals, tasks, finance signals, and commitments.
3. Rank the highest evidence-backed constraint.
4. Let the founder ask Clarity why and receive an explanation tied to the visible evidence.
5. Turn the decision into a dated action while keeping automated work explicitly verified and separate.

The demonstration workspace tells one specific story: a solo founder has complete business context, three active opportunities, no paid proof yet, and a current commitment to send the first 20 warm outreach messages.

## Why this is useful

Traditional CRMs make founders interpret dashboards and decide what to do next. General chat tools can give advice, but they usually lack the founder's operating state. Clarity connects both sides. It uses the business model, current evidence, and work already inside WebXpanse to produce one priority with provenance, not another unranked list.

The human stays in control. Clarity recommends and explains. The founder makes the commercial judgment. Actions shown as handled are limited to completed work with evidence.

## OpenAI implementation

- `api/chat/ask.php` assembles the current page, workspace, role, conversation, business, and operating context for Clarity.
- `services/AIContextAssemblyService.php` builds source-labelled context blocks and controls what reaches the model.
- `services/AIService.php` supports the OpenAI Responses API for official OpenAI endpoints, with a compatibility fallback for older OpenAI-compatible gateways.
- `services/BusinessContextSnapshotService.php` creates the structured business snapshot used by the founder experience.
- `services/FounderCommandCenterService.php` combines ranked founder-attention signals, current commitments, verified activity, and growth evidence.
- `public/dashboard.php` renders the operating rhythm and hands the exact visible constraint, reason, and evidence to Clarity when the founder asks why.

The OpenAI model is used for contextual reasoning and explanation. Deterministic services enforce the data boundaries, calculate readiness, rank evidence, and preserve a safe fallback. This makes the experience useful even when a model request is unavailable and prevents the model from inventing completed business actions.

## Build Week contribution

WebXpanse existed before Build Week and has been developed by founder Dennis Makobu over several months. Its pre-existing foundation includes workspace tenancy, CRM records, communications, tasks, pipelines, reporting, permissions, and automation infrastructure.

The Build Week work concentrates that foundation into the submitted experience. The [Build Week change log](BUILD_WEEK_CHANGELOG.md) records the milestone hashes from the original July 14–19, 2026 development history, including:

- Clarity's AI business operating-system positioning;
- the calm founder operating-rhythm command center;
- evidence-backed constraint ranking and the Founder Loop;
- mobile Decision Center API parity;
- the focused solo-founder presentation workspace and seed pack;
- direct constraint-to-Clarity handoff;
- UX, input, tenant, concurrency, and communication-boundary hardening.

Codex with GPT-5.6 was used for the Build Week implementation, debugging, focused tests, browser verification, and submission preparation. Earlier product work also used ChatGPT and Codex with GPT-5.4 and GPT-5.5. Dennis remained the sole builder and made the product and submission decisions.

## Run locally

Requirements: PHP 8.1+, MySQL 8+, Composer, and Apache or another web server configured for the `public/` directory.

```bash
cp .env.example .env
composer install
php database/migrations/migrate.php
php scripts/create_admin_user.php
```

On Windows PowerShell, use `Copy-Item .env.example .env` instead of `cp`. Create the MySQL database named in `.env` before running migrations.

With this repository at `C:\xampp\htdocs\crm`, open:

```text
http://localhost/crm/public/
```

AI configuration is workspace-scoped. Save and enable an OpenAI-compatible provider in workspace Settings. Official OpenAI endpoints can use the Responses API path in `AIService`.

## Create the judging workspace

After creating an owner user, generate the isolated, simulation-first judging workspace with:

```bash
php scripts/create_build_week_demo.php --presenter-email=owner@example.com --ttl-hours=24
```

The command prints a one-use magic-login URL plus temporary credentials. Open the URL in the same local installation. The resulting workspace has complete founder context, three active deals, no false won deals, AI Coach enabled for the presentation only, and a dated outreach commitment. It expires automatically and does not arm live message delivery.

## Focused verification

```bash
vendor/bin/phpunit tests/Unit/Services/PresentationFounderWorkspaceServiceTest.php
vendor/bin/phpunit tests/Unit/Services/FounderCommandCenterServiceTest.php
vendor/bin/phpunit tests/Unit/Services/ClarityResponsePresentationServiceTest.php
vendor/bin/phpunit tests/Unit/Services/BusinessContextSnapshotServiceTest.php
```

The presentation test verifies that demo-only capabilities cannot be installed in a normal workspace, the Clarity Journey is complete, the solo-founder seed has no false won-customer signal, assumption conflicts are absent, and the command center has a 100% business-context snapshot plus a current commitment.

## Submission links

- Devpost project: https://devpost.com/software/webxpanse
- Public source repository: **[ADD PUBLIC REPOSITORY URL]**
- Three-minute video: **[ADD PUBLIC YOUTUBE URL]**
- OpenAI `/feedback` ID: `019f74ad-26fd-7713-a474-cb83d9cb101e`

Do not submit until the repository and video placeholders above are replaced and each link opens in a logged-out browser.
