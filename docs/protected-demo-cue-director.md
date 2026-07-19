# Protected Demo Cue Director

The protected demo experience is cue-first and time-assisted. Page visits,
visible sections, active thread context, idle state, and visitor focus decide
which Riverside moment is eligible. Scheduled deadlines remain as fallback
pacing so the story can continue if the visitor stalls.

## Cue Taxonomy

- `page_enter`: visitor lands on a protected demo page.
- `section_visible`: a demo story section or proof card is visible.
- `entity_opened`: visitor opens a Riverside thread, task, contact, target, or feed.
- `idle`: visitor is still long enough for a gentle prompt or one allowed auto-action.
- `fallback_time`: a deadline makes the next moment eligible without a cue.

## Privacy Rules

- Every cue-created entity must be scoped by `workspace_id` and `demo_session_id`.
- Triage can enrich the story but never defines the privacy boundary.
- If a visitor skips ahead, missing predecessors are backfilled only inside that visitor session.
- Real email and WhatsApp providers stay disabled in protected demo mode.

## Auto-Open Rules

- Only the featured Riverside inbox thread may auto-open.
- Auto-open is allowed once per demo session/browser.
- Auto-open requires idle state, visible inbox, and no focused composer or video.
- Auto-open must be recorded in scheduler state so reloads cannot repeat it.

## Pause And Fallback Rules

- Pause cue emissions while the tab is hidden, a form/composer is focused, a video is open, or the user is actively typing.
- Keep at least 18 seconds between visible toast moments.
- Cue-triggered records may be created immediately, but visible toasts remain paced and non-repetitive.
- Deadline fallbacks should advance the story quietly and contextually, not as random notifications.
