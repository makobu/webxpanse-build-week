# Phase 3: Actionable Guidance Integrity

Phase 3 hardens the dashboard guidance card so every visible CTA moves the owner to a real work surface. If the system cannot identify a trustworthy action target, the card shows the guidance text without a button.

## Product Rules

- The dashboard keeps the same three-card command strip: Today's Revenue Focus, Activation Progress, and TTFV.
- The Today's Revenue Focus card must never show a CTA that links back to the current dashboard page.
- Same-page dashboard links are non-actionable and must be hidden.
- Advanced mode can expose more product areas, but dashboard recommendations still need useful action targets.
- AI Coach remains optional and lazy-loaded. It must not become the default CTA for the card.

## Routing Rules

- Waiting customer reply: `conversation.php` when a conversation row is available, otherwise no guessed conversation route.
- Due task or follow-up: `task_view.php` when a task row is available, otherwise `tasks.php`.
- Quiet deal or job: `deal_view.php` when a deal row is available, otherwise `deals.php`.
- Money step: `invoice_create.php` when an invoice-ready deal is available, otherwise `invoices.php`.
- Add/import customer: `contacts_create.php` or `contacts_import.php`.
- Unmappable guidance: no `href` and no `cta_label`.

## Performance Rules

- No database migration is required.
- No live AI calls are allowed during dashboard PHP render or guidance endpoint execution.
- The guidance cache key is versioned so old same-page CTA payloads are not reused.
- The dashboard JavaScript must update only the existing first card after idle.
- Marketing CSS and advanced dashboard bundles must remain absent from the dashboard.

## Measurement

- `dashboard.guidance.viewed` includes whether a real CTA was present.
- `dashboard.guidance.clicked` is tracked only when the CTA has an actionable, non-dashboard target.
- Tracking failure must not block navigation.
