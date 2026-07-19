# Phase 5: Guided Setup Destinations

Phase 5 keeps the Home Command Center unchanged and improves what happens after a beginner owner clicks a dashboard setup or capability CTA.

Product rules:
- Dashboard CTAs must remain actionable and may carry lightweight destination context such as `source`, `action`, and `gap`.
- Beginner arrivals on `workspace_skills.php` should see one focused setup goal, one reason, one primary setup action, compact progress, and a small set of best capability matches.
- `Marketplace` remains available for advanced users; beginner users see it as `Add capabilities`.
- Full module pages, marketing workflows, automation diagnostics, catalog editing, setup forms, and advanced Marketplace controls remain preserved.
- The destination guidance layer must reuse deterministic marketplace recommendation and setup journey services. It must not call live AI or add dashboard CSS/JS.
- No database migration is required. Existing marketplace setup journey events record dashboard-sourced setup opens.

Performance rules:
- The dashboard still renders the same three-card command strip.
- Destination context is appended to existing dashboard links; no new dashboard panel or bundle is introduced.
- Destination guidance is cached for about five minutes per workspace, user, mode, source, action, gap, and module.
