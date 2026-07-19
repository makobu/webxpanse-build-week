# Core concurrency contract

Core collaborative records use `lock_version` as their optimistic concurrency token.

This currently covers contacts, conversation threads, deals, documents, tasks,
workflows, companies, products, events, invoices, company profile, custom fields,
and scheduled reports.

- User-facing edit forms and JSON write APIs must submit `expected_lock_version`.
- A successful write increments `lock_version`.
- A stale write returns or throws a conflict with the current saved record and submitted fields.
- Internal automation and workers may omit `expected_lock_version`; those writes still increment `lock_version`.
- Inbox read/archive/delete state is per-user in `communication_user_state`; conversation ownership remains shared on `conversation_threads`.
- Queue consumers must claim work atomically. Email claims carry an expiring lease and a claim token; only the current token owner may acknowledge, fail, or retry that claim.
- Read-only authenticated work should call `Session::closeWrite()` once authentication, workspace resolution, and any CSRF-dependent work are complete.
