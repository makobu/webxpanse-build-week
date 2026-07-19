# High-impact page performance audit

## Run it

From the repository root:

```powershell
npm.cmd run audit:performance
```

The audit creates an ignored JSON report in `tmp/performance-audit-<timestamp>.json`. It authenticates with the deterministic Playwright admin fixture and measures Sign in, Dashboard, Inbox, Contacts, Deals, and Tasks in Chromium. Set `CRM_PERF_SAMPLES` to change the default of three serial samples, or `CRM_PERF_AUDIT_OUTPUT` to choose the JSON destination.

The report records navigation and TTFB, DOM-content-loaded, load, FCP, request and transfer totals, third-party requests, Long Tasks, slow resources, and same-origin Server-Timing values. Its p75 is the conservative nearest-rank value. With the default three samples, p75 is the slowest sample, so cold-start and local XAMPP variance remain visible. This is a repeatable local regression baseline, not a replacement for production RUM across customer data sizes and networks.

## Baseline and verified optimization pass: 2026-07-13

Environment: local `http://localhost/crm/public`, Chromium via Playwright, three serial same-session samples per route, deterministic admin fixture.

| Rank | Page | Baseline p75 nav / FCP | Verified p75 nav / FCP | Navigation change |
| --- | --- | ---: | ---: | ---: |
| 1 | Dashboard | 1,610 / 1,712 ms | 1,403 / 1,492 ms | -12.9% |
| 2 | Deals | 1,231 / 1,308 ms | 1,082 / 1,152 ms | -12.1% |
| 3 | Inbox | 1,119 / 1,272 ms | 890 / 956 ms | -20.5% |
| 4 | Contacts | 1,053 / 1,128 ms | 877 / 952 ms | -16.7% |
| 5 | Tasks | 1,098 / 1,180 ms | 837 / 900 ms | -23.8% |
| 6 | Sign in | 70 / 732 ms | 49 / 592 ms | -30.0% |

Verified report: `tmp/performance-audit-resolved-v2.json` (ignored runtime artifact). All six pages improved by more than 10% in the final three-sample run. The authenticated pages remain server-bound: TTFB is effectively equal to navigation time, while transfer size and browser Long Tasks are small.

## Completed optimization slice

- Added debug-gated document and API Server-Timing checkpoints so the report identifies controller, layout, task, and notification costs.
- Bounded the Dashboard task query to the five rows rendered instead of fetching 50 and slicing in PHP. Its measured server total is now 82-125 ms; end-to-end duration is still affected by the browser's queued post-render requests.
- Collapsed notification unread/new counts into one scoped aggregate. The measured server total is 52-60 ms, with a 30-second session cache, 60-second polling, and hidden-tab pause.
- Released PHP's session write lock before the read-only Dashboard task, readiness, guidance, and AI Coach access queries.
- Removed operating-brief generation from Dashboard GET. The brief is generated when onboarding completes and the Dashboard reads the cached result.
- Deferred automation-battery work and AI Coach authorization until after render. The AI Coach button remains deny-by-default and is revealed only after the authorization endpoint succeeds.
- Avoided a duplicate plain-readiness request when the Dashboard document already contains its initial snapshot.

## Overnight query/schema repair: 2026-07-19

Environment: local `http://localhost/crm/public`, Chromium via Playwright, three serial same-session samples per route, deterministic admin fixture. The before and confirmation reports are `tmp/performance-audit-2026-07-19-overnight-before.json` and `tmp/performance-audit-2026-07-19-overnight-after-v2.json` (ignored runtime artifacts).

The live `session_automation_state` table had drifted away from migration 125: its primary key, `AUTO_INCREMENT`, unique `(user_id, feature_key)` key, and lookup indexes were missing. It had grown to 24,990 rows for only 235 distinct user/feature states; 24,986 rows had `id=0`. Consequently, `ON DUPLICATE KEY UPDATE` inserted instead of updating and every point lookup used a full table scan.

Migration `535_repair_session_automation_state_keys.sql` retained the newest state for each user/feature pair, restored valid IDs and all intended keys, and was run locally. Post-repair evidence:

- Rows fell from 24,990 to 235 without losing a distinct user/feature state.
- `EXPLAIN` changed from `type=ALL`, about 21,074 examined rows, to `type=const`, one row via `uniq_session_automation_user_feature`.
- 100 representative state lookups fell from 3,070.48 ms to 40.55 ms (about 76x faster).
- After two full browser audits, the table remained at 235 rows while `updated_at` advanced, confirming automation writes now update rather than append duplicates.

| Page | Before p75 / median nav | Confirmed p75 / median nav | p75 change | Median change |
| --- | ---: | ---: | ---: | ---: |
| Dashboard | 4,516 / 4,201 ms | 4,712 / 2,329 ms | +4.3% | -44.6% |
| Contacts | 3,643 / 3,518 ms | 1,307 / 1,248 ms | -64.1% | -64.5% |
| Inbox | 3,346 / 3,273 ms | 1,278 / 1,251 ms | -61.8% | -61.8% |
| Deals | 3,276 / 3,023 ms | 1,408 / 1,216 ms | -57.0% | -59.8% |
| Tasks | 3,143 / 2,932 ms | 1,279 / 1,248 ms | -59.3% | -57.4% |
| Sign in | 90 / 88 ms | 72 / 66 ms | -20.0% | -25.0% |

Dashboard still has high cold-run variance: the confirmation samples were 2,329, 4,712, and 1,390 ms. In the outlier, all database-backed sections slowed together (`dashboard_total=2,705.5 ms`, `layout_total=396.3 ms`), including AI Coach access, plain readiness, workspace readiness, and the billing snapshot. Treat the median improvement as the schema repair signal and keep the Dashboard bootstrap/caching work below as the top remaining page-level item.

## Ranked remaining optimization backlog

### 1. P0 - Split or cache the remaining Dashboard bootstrap

Evidence: Dashboard remains first at 1,403 ms p75 navigation. The cold sample's instrumented work includes `dashboard_total=471 ms` and `layout_total=105 ms`; the largest named Dashboard sections are workspace readiness (150 ms), product-demo prompt (116 ms), plain readiness (101 ms), and shared billing layout state (91 ms). The remaining gap is PHP bootstrap/template work before or outside the named sections.

Next work:

- Cache workspace readiness, demo prompt, and plain-readiness snapshots by workspace/user with explicit invalidation on their writes.
- Move non-first-viewport dashboard cards to one consolidated post-render endpoint instead of several competing requests.
- Add an outer request checkpoint before `dashboard.php` dependencies load, then profile PHP autoload/include cost if the named timings still do not explain total TTFB.

Acceptance: Dashboard p75 TTFB below 800 ms locally, with no authorization or workspace-scope change.

### 2. P1 - Consolidate Dashboard post-render hydration

Evidence: the bounded task endpoint spends only 82-125 ms on the server but takes up to 378 ms end-to-end while AI Coach access, beginner guidance, notifications, and automation battery compete in the same browser queue.

Next work:

- Return first-viewport task, guidance, and capability state from one scoped hydration endpoint.
- Start automation battery only after first-viewport hydration settles, not merely after a timer.
- Keep the per-operation Server-Timing values in the consolidated response.

Acceptance: Dashboard task content visible within 200 ms after load and no optional request delays the next navigation.

### 3. P1 - Instrument and budget session postload work

Evidence: `api/session/postload.php` was the slowest Inbox and Deals post-render request in the original run at 321-366 ms. Its browser cooldown is now 60 seconds, which reduces repetition but not the cost of the request that does run.

Next work:

- Add Server-Timing by postload operation and omit operations not needed for the current page.
- Make independent operations concurrent only after confirming session-lock safety.

Acceptance: p75 below 150 ms, with the dominant operation named whenever the budget is missed.

### 4. P2 - Remove the first-visit font dependency from Sign in

Evidence: Sign in document TTFB is healthy at 49 ms, but FCP is 592 ms and still aligns with the render-critical Font Awesome CDN stylesheet; the page also downloads a desktop background image.

Next work:

- Self-host or inline the small icon subset used by Sign in.
- Serve responsive AVIF/WebP backgrounds and avoid the desktop asset on narrow screens.

Acceptance: cold sign-in FCP below 400 ms and no render-critical third-party stylesheet.

### 5. P2 - Remove runtime CSS injection from the shared AI helper

Evidence: `ai-ui-consistency.js` is small but remains a slow queued request on Dashboard and injects shared CSS at runtime.

Next work:

- Move static rules into the versioned stylesheet.
- Load the helper only on pages that call its API.

Acceptance: no static CSS injection and no helper request on pages that do not use it.

## Regression guardrail

Run `npm.cmd run audit:performance` before and after each backlog item and compare JSON reports, not one wall-clock load. Investigate any p75 navigation regression above 10%, any new same-origin resource above 250 ms, or any loss of a Server-Timing checkpoint.
