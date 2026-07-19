# Phase 1 Beginner Core Lock

Phase 1 keeps the product aligned to the first promise: an owner with basic language and computer skills can land on the dashboard, see the next useful business action, and reach more capability only when they are ready for it.

## Product Rules

- Keep the clean visual-first dashboard and homepage structure.
- Keep the command strip pattern: Today's Revenue Focus, Activation Progress, and TTFV.
- Show one primary revenue action by default, with no more than two secondary hints.
- Default normal owner-led workspaces to `ui_experience_mode=beginner`.
- Treat super admin and internal platform contexts as `advanced` unless an explicit preference says otherwise.
- Use "Add capabilities" for beginner Marketplace entry points.
- Hide the top-level Marketing workspace from beginner default navigation.
- Preserve direct Marketing access for users who have Marketing permissions and land on Marketing pages.
- Preserve advanced Marketing pages, CSS, tables, diagnostics, and workflows.
- Keep Marketing CSS loaded only on Marketing pages.
- Do not add blocking AI calls or heavier dashboard queries for Phase 1.

## Beginner Language

Beginner-facing guidance should use owner language:

- reply
- follow up
- add customer
- record expense
- send invoice
- bring customer back

Avoid exposing campaign, workspace, launch, diagnostic, or automation-battery language as the default beginner path. Those surfaces remain available in advanced Marketing and admin areas.

## Release Checks

- Beginner users can understand the next action from the dashboard without opening Marketing or Marketplace.
- Advanced and Marketing-authorized users can still reach Marketing workflows.
- Dashboard postload and existing Server-Timing behavior remain unchanged.
- No new schema migration is required for this phase.
