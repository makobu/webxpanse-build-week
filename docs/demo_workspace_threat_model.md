# Protected Demo Workspace Threat Model

## Assets
- Real tenant workspace data, users, billing settings, integrations, exports, and API credentials.
- Demo visitor personally identifiable information: supplied name, email, WhatsApp phone, consent choices, IP and user-agent hashes.
- Visitor-private demo overlay records: contacts, communications, threads, notifications, activities, tasks, deals, queued channel artifacts, and realtime events.

## Trust Boundaries
- Public demo access endpoints accept unauthenticated traffic and must never create normal invites or normal tenant memberships.
- Demo guests are temporary authenticated users, but their permissions are limited to the protected demo workspace and the `demo_viewer` role.
- Shared public demo seed data is visible to all active demo visitors; session-private overlay data is visible only to the active `demo_session_id`.
- Triage, assistant drafts, and realtime notifications are experience features only. Database scopes and endpoint checks are the privacy boundary.

## Main Abuse And Leak Paths
- Visitor A reads Visitor B records through broad RBAC, owner scopes, direct IDs, or realtime event polling.
- A demo guest switches to a real workspace through membership recovery or normal workspace switchers.
- Demo actions reach real email or WhatsApp providers.
- Expired or revoked sessions continue to read or mutate data.
- Guest PII remains indefinitely or is written into ordinary user/contact rows.
- Admin reseed deletes a visitor's private overlay or mixes real tenant records into shared seed data.

## Controls
- Demo access creates or resumes a `demo_visitor_sessions` row and stores the active id/uuid in server session state.
- Guest users have synthetic local email addresses, unusable random passwords, `is_demo_guest = 1`, `demo_expires_at`, and only protected-demo membership.
- PII is encrypted in `demo_visitor_sessions`; hashes are used for dedupe and abuse windows.
- `DemoSessionScopeService` is the only SQL visibility builder: `public_seed` OR matching active `demo_session_id`, with superadmin/operator-only escape only where explicitly requested.
- Inbox, contacts, notifications, channel simulation, and realtime endpoints require the active session scope before owner/RBAC broadening.
- Real provider sending remains disabled in demo sessions; channel activity is simulated and private.
- Session expiry, revoke, end, and cleanup paths mark sessions inactive and purge/anonymize private overlays according to retention windows.
