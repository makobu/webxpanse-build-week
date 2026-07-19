# Design Studio production contract

The Design plugin is a conversion-site studio inside CRM. It combines a safe, code-owned component system with live CRM forms, booking, WhatsApp, publishing, versions, tracking, and brand controls.

## Product boundary

- Users compose approved blocks and structured properties. They cannot persist executable HTML, CSS, JavaScript, PHP, routes, or database instructions.
- Core CRM continues to own authentication, CSRF, forms, submissions, booking, tracking, media access, publication tokens, and authorization.
- Draft documents are mutable. A published version is an immutable snapshot until the user publishes again.
- Every persisted document declares `crm.design/v1`, is normalized server-side, and receives an optimistic-lock revision.

## Visual contract

- Chrome: white surfaces, `#f4f7fb` workspace, `#dbe3ee` dividers, `#0f67ea` primary, `#0f9f76` success, slate text.
- Desktop: 64px app rail, 280px block library, flexible centered canvas, 336px inspector, 64px top toolbar.
- Mobile: compact toolbar, single device canvas, bottom navigation, and an add-block bottom sheet.
- Typography: system/Inter stack; 13-14px editor chrome, 16-18px body, 40-56px desktop hero, 32-40px mobile hero.
- Controls are at least 40px on desktop and 44px on touch layouts. Focus states remain visible.
- Canvas copy, order, colors, spacing, responsive state, and validation status must match the public renderer contract.

## Interaction contract

- Add, select, drag, keyboard-move, duplicate, and delete blocks.
- Edit content, style, and visibility through a schema-derived inspector.
- Undo/redo is local and bounded. Autosave is debounced and revision-checked; conflicts never silently overwrite a newer draft.
- Desktop, tablet, and mobile toggles resize the canvas without changing document data.
- Templates are versioned manifests with use-case and industry metadata. Applying one is undoable.
- Publish validates the document and freezes the exact validated snapshot. Rollback restores a previous version as a new draft.

## Acceptance

- No arbitrary-code fields or unvalidated external embeds.
- Real CRM form and booking destinations render from core-owned endpoints.
- Server and editor validation cover missing conversion paths, accessibility text, malformed destinations, duplicate IDs, unknown blocks, and size limits.
- Desktop and mobile visual audits cover editor, preview, and public output.
