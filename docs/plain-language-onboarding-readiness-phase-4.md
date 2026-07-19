# Phase 4: Plain-Language Onboarding And Readiness

## Product Rule

Beginner owners should understand setup state without learning internal readiness terms. The dashboard remains the same clean three-card Home Command Center:

- Today's Revenue Focus stays focused on one work or revenue action.
- Setup Progress explains what is ready, what is blocked, and where to go next.
- TTFV remains the proof metric and does not gain heavier runtime work.

## Implementation

- `PlainLanguageReadinessService` translates onboarding, activation, channel, customer, money, AI context, and approval state into owner language.
- `api/dashboard/plain_readiness.php` returns the cached deterministic payload for idle hydration.
- `public/dashboard.php` renders translated fallback labels immediately, then hydrates only the second command-strip card after idle.
- Setup popup and setup-impact copy now use daily-work language instead of abstract operational language.
- `public/onboarding.php` frames onboarding as Business Setup while preserving the same saved data and step persistence.

## Guardrails

- No database migration.
- No live AI call on initial dashboard render or readiness hydration.
- No new dashboard CSS or JS bundle.
- Readiness CTAs must be non-dashboard, actionable setup or work routes.
- Advanced diagnostics, automation battery details, marketplace flows, and AI Coach readiness remain available on their existing surfaces.

## Measurement

The existing outcome-event API now accepts:

- `dashboard.readiness.viewed`
- `dashboard.readiness.clicked`

Tracking runs after idle hydration and never blocks navigation.
