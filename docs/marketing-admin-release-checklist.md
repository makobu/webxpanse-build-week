# Marketing Admin Release Checklist

Use this checklist before a workspace starts using Marketing with real campaign and content data.

## 1. Confirm System Readiness

- Open `system_health.php`.
- Confirm database, migrations, writable runtime paths, and Marketing module checks are healthy.
- Open `marketing_admin.php`.
- Confirm latest migration is `407_create_marketing_live_proof_packs.sql`.
- Confirm no missing Marketing tables or hardening indexes are reported.
- Confirm Marketing AI Readiness shows six core `marketing` prompts, four operating-loop prompts, the `marketing` runtime surface is ready, prompt seed migrations `319_seed_marketing_ai_prompts.sql` and `320_seed_marketing_ai_phase_5_8_prompts.sql` are recorded, and deterministic fallback is accepted when no live provider is configured.

## 2. Confirm Access

- Viewer users can open Marketing pages but cannot create, edit, approve, archive, delete, or run cleanup.
- Marketing users can create and edit planning records, content, briefs, exports, starter data, and live preparation records.
- Owner/admin users can approve reviews, open diagnostics, preview cleanup, archive starter data, enable live controls, approve live attempts, apply live recovery, and generate proof packs.
- Manager-only actions are separated from everyday writer workflows.

## 3. Prepare Workspace Context

- Add at least one brand profile.
- Add at least one persona.
- Add offers, proof points, content pillars, default CTAs, and compliance terms in Context Hub.
- Add SEO topics when content will be planned around search intent.
- Add campaign briefs before building production content.

## 4. Use Starter Data Safely

- Starter data is opt-in only.
- Starter data is marked with `source=marketing_onboarding_starter_pack`.
- Starter data is for learning the workflow and should not be mixed into live campaign reporting.
- Preview cleanup before archiving starter data.
- Cleanup archives demo-supported records and hides demo-only brand/persona context from normal pickers.

## 5. Validate Execution Boundary

- Landing page plans can be previewed and token-published inside the CRM, but no external hosting service is called.
- Distribution and channel export bundles prepare manual publishing packages only.
- Email, SMS, WhatsApp, and allowlisted webhook execution can run live only through the controlled live gates: policy enabled, ready connector, verified secret reference, passed preflight, consent/suppression clearance, manager approval, exact final confirmation, scheduler/worker evidence, and manager-level permission.
- Dry-run and rehearsal actions do not send or publish externally.
- Live proof packs are generated only by managers/admins and must exclude raw secrets, tokens, stack traces, raw confirmation text, full private payloads, and raw recipient values.
- AI content tool suggestions are saved before apply, so a user chooses when a suggestion updates the draft and version trail.
- AI brief and landing copy generation fill reviewable fields only; those fields are not persisted until the user saves.
- AI quality checks never rewrite drafts; they store review scores, recommendations, and fallback/provider metadata only.
- AI assistant side effects remain draft-side only: draft ideas, CRM comments, and content tool-run suggestions. They do not publish or send.
- AI strategy gap analysis saves planning queue suggestions only; users still decide the next action.
- AI campaign planner, performance analysis, and integration readiness review save planning queue suggestions only; they do not create briefs, content, landing pages, external sends, live publishing, or analytics mutations.
- Integration readiness checks manual export bundles, email run checklists, CRM landing-page token readiness, UTMs, approvals, missing assets, connector setup, live gates, and destination URLs.

## 6. Before Live Use

- Create one real campaign brief.
- Create one real content item linked to the brief.
- Request and complete one review.
- Add one calendar milestone.
- Create one distribution draft or export bundle.
- Complete one dry rehearsal and confirm live promotion remains blocked until manager approval and exact final confirmation.
- Generate one live proof pack from a test/live-safe queue item and confirm it is sanitized.
- Create one weekly analytics snapshot.
- Confirm Admin Diagnostics has no release-blocking recommendations.

## 7. Do Not Enable Without Explicit Approval

- External social publishing APIs.
- Ad account APIs.
- Live SEO research/ranking APIs.
- Live social, ad, SEO, ranking, or public-hosting connector delivery.
- Public landing page hosting outside the CRM-controlled token preview/publishing flow.
- Any live execution path that bypasses live policy, verified connector secrets, preflight, approval, final confirmation, worker evidence, consent/suppression checks, or proof logging.
