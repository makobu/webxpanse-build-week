# Voice & Call Center production v1

## Runtime boundary

The CRM controls call state, routing, callbacks, policy, usage estimates, and asynchronous intelligence. Africa's Talking carries the media and bridges calls to verified agent telephone or SIP endpoints. No PHP request remains open for the duration of a call.

Production v1 supports one Africa's Talking provider account per workspace, a maximum platform-tested ceiling of 40 active calls per provider account, and one new outbound release per second. Package limits can be lower.

## Client-owned provider costs

Each customer owns and funds their Africa's Talking account, virtual number, and SIP endpoints. The customer also uses the OpenAI provider key already saved under workspace Settings. The plugin does not read `OPENAI_API_KEY` from the server environment and does not create a second AI-key store.

CRM revenue comes from the Growth Studio Voice and Scale Voice package prices plus onboarding and separately quoted routing work. Provider usage shown in the plugin is an estimate, not an invoice.

## Required production processes

1. Run `php cli/voice_migration_preflight.php`, retain the row-count/lock evidence, take a verified backup, and schedule a controlled window if the existing communications enums still need the additive `voice` value. Then run migrations with `php database/migrations/migrate.php`.
2. Set `VOICE_CALL_CENTER_ENABLED=true` only after staging verification.
3. Install Voice & Call Center for the intended workspace and assign a voice-enabled package price.
4. Save the workspace-owned Africa's Talking username, API key, and virtual number in Marketplace setup.
5. Configure the distinct Africa's Talking callback and events URLs displayed in Provider setup. Treat the opaque route token, exact account/number correlation, replay controls, rate limits, and provider IP allowlist as layered controls; Africa's Talking does not provide a Twilio-style callback signature.
6. Add verified agent endpoints and save named queues, ordered queue members, business hours, the default route, and cycle-safe alternate/verified-number fallbacks.
7. Configure recording consent and have a workspace owner accept the recording-responsibility acknowledgement before enabling recording. The acknowledgement is tied to the exact consent, retention, destination, and automation policy and must be renewed after material changes.
8. Save and enable the workspace OpenAI key in Settings before enabling transcription.
9. Run the persistent worker under the production process supervisor:

   `php cli/voice_worker.php`

10. Keep the workspace, inbound, outbound, recording, transcription, AI-application, and Customer Voice switches disabled until their controlled tests pass.

## Safety and privacy defaults

- Recording is disabled until consent is configured and a workspace owner confirms that the organisation has assessed the requirements applicable to its agents, call participants, locations, and intended use. The CRM does not claim jurisdiction-specific compliance.
- Explicit keypad consent is the default, while workspaces retain operational control over the available notice or consent mode.
- Recording and transcription apply to consented inbound calls. Africa's Talking agent-first outbound calls remain unrecorded because the documented flow does not prove customer-leg consent before recording starts.
- Unknown callers remain unmatched until an authorized user links them.
- Kenya destinations are the default allowlist. International calling requires owner configuration; premium Kenyan prefixes remain blocked.
- Provider and Settings keys are encrypted at rest and never returned to browser JavaScript.
- Recording URLs remain server-side. Authorized playback is proxied through `api/voice/recording.php` with TLS, provider-host, MIME, size, workspace, and permission checks.
- Transcripts are application-encrypted. Authorized access is workspace- and call-scoped.
- Authorized transcript exports are explicit JSON downloads. Workspace administrators can delete CRM-held raw transcript content and the recording pointer without deleting the immutable call/event history.
- Retention cleanup removes the CRM recording pointer and encrypted transcript content. Provider-account retention remains authoritative and must also be configured with Africa's Talking.
- Workspace automation is controlled independently for call summaries, contact context, follow-up tasks, deal-stage suggestions, follow-up messages, and Customer Voice. Direct field replacement, automatic deal movement, unattended outbound calling, and automatic campaign publication remain unavailable in production v1.
- Voice intelligence is layered: protected raw evidence, structured call insight, policy-approved CRM context, and anonymized aggregate Customer Voice. Raw transcripts are never injected into general CRM AI context.
- Approval-required context is applied additively with call/date/confidence provenance. Automatic-safe follow-up tasks use a per-call dedupe key, and retried workers or reviews cannot duplicate them.
- Every matched completed call creates one idempotent contact activity and one `voice` communication even when recording is off. A later AI result upgrades those same records instead of creating duplicates.
- Africa's Talking has no documented remote hangup endpoint. Agents end calls from the connected telephone or SIP client; the CRM UI exposes transfer only when the provider capability is available.
- Provider-held waiting and voicemail are not enabled merely because the schema/provider adapter can represent them. Keep immediate alternate-queue, verified-number, or reject fallback until Africa's Talking proves the exact Enqueue/Dequeue and recording behavior on the client account.

## Health and rollback

The Marketplace overview reports provider, consent, Settings AI key, worker, callback, usage, and concurrency readiness. Alert operationally when the voice worker heartbeat is older than five minutes, transcription backlog age exceeds ten minutes, provider authentication repeatedly fails, or concurrency reaches 80 percent of entitlement.

Rollback is operational: disable the independent switches, route the provider number to a verified fallback, stop the voice worker, and preserve call/event evidence. Do not roll back or drop the additive voice schema during an incident.

## Verification commands

- Fast contracts: `php vendor/phpunit/phpunit/phpunit tests/Unit/Services/AfricaTalkingVoiceProviderTest.php tests/Unit/Services/OpenAIVoiceTranscriptionProviderTest.php tests/Unit/Services/VoiceCallPolicyServiceTest.php tests/Unit/Services/VoiceCallStateMachineServiceTest.php tests/Unit/Services/WorkspaceVoiceConfigServiceTest.php tests/Integration/VoiceCallCenterSourceContractTest.php`
- Database isolation: `php vendor/phpunit/phpunit/phpunit tests/Integration/VoiceCallCenterDatabaseTest.php`
- Enum migration preflight: `php cli/voice_migration_preflight.php`
- Rollback-only capacity check: `php scripts/verify_voice_capacity.php`

The capacity harness refuses to run unless the configured database name contains `test` and rolls back every simulated call and event.

## Beta gates still requiring real accounts

Before enabling a customer workspace, verify current Africa's Talking callback-origin guidance, obtain and use a provider test number or approved live number (the public Voice sandbox is currently not operational), test insufficient-balance and endpoint-unavailable failures, prove consented recording delivery, and run a complete transcript with the workspace Settings key. Provider-held Enqueue/Dequeue and voicemail require their own live proof before those fallback modes may be exposed. Raise concurrency progressively from 2 to 5 to 10, then 20 and 40 only with stable evidence.
