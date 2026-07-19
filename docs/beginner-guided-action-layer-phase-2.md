# Phase 2: Beginner Guided Action Layer

Phase 2 keeps the dashboard visually clean and turns the existing Today's Revenue Focus card into the beginner operating loop.

## Product Rules

- The dashboard keeps the three-card command strip: Today's Revenue Focus, Activation Progress, and TTFV.
- Beginner guidance appears inside the first card only. Do not add another dashboard panel for daily recommendations.
- The first card shows one primary action, one reason, one direct CTA, and no more than two secondary hints.
- Beginner copy uses owner language: reply, follow up, send invoice, add customer, connect a channel.
- Marketing, campaign workspaces, personas, launch controls, diagnostics, and automation battery details stay out of the beginner default path.
- Advanced marketing and automation workflows remain available for advanced-mode or authorized users.

## Ranking

The deterministic guidance service ranks beginner actions in this order:

1. Reply to waiting customers or process inbound messages.
2. Finish due follow-up tasks.
3. Follow up quiet deals or jobs.
4. Prepare a quote or invoice when an open deal is ready for a money step.
5. Add the first customer when the pipeline is empty.
6. Complete setup gates that unlock action, such as channel or invoice settings.
7. Add capabilities only when a missing capability blocks the next action.

## Performance Rules

- `public/dashboard.php` renders the existing fallback immediately.
- `api/dashboard/beginner_guidance.php` is fetched after idle and updates only the first command-strip card.
- The guidance endpoint uses existing data and `BeginnerGuidanceService` cache with a five-minute TTL.
- The guidance endpoint must not call live AI.
- AI Coach may be loaded after idle or on explicit user intent, but it is not the source of the first visible recommendation.
- No new dashboard CSS or JavaScript bundle is introduced for Phase 2.
- Marketing CSS remains scoped to marketing pages.

## Measurement

Phase 2 tracks lightweight, non-blocking outcome events:

- `dashboard.guidance.viewed`
- `dashboard.guidance.clicked`

Both events use the existing CSRF-protected outcome event API. Tracking failure must not block navigation.
