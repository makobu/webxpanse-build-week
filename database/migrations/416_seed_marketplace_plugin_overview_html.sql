CREATE TEMPORARY TABLE marketplace_plugin_overview_html_seed (
    skill_key VARCHAR(80) PRIMARY KEY,
    overview_brief_content TEXT NOT NULL,
    overview_deep_dive_content TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO marketplace_plugin_overview_html_seed (skill_key, overview_brief_content, overview_deep_dive_content) VALUES
('email', '<h3>Keep email ready for real customer conversations</h3>
<p>Email gives the workspace a clear home for sending, inbox capture, templates, signatures, and delivery checks. It keeps channel setup separate from assistant automation, so owners can confirm the basics before messages move through the CRM.</p>
<blockquote><p>Use this when email supports follow-up, onboarding, support, or nurture work and you want the team to trust the channel before adding automation.</p></blockquote>
<ul>
<li>Connect outreach or nurture sender identities in one place.</li>
<li>Check sending and optional inbox readiness before live workflows depend on them.</li>
<li>Keep templates, signatures, queues, and email activity tied to the same channel setup.</li>
</ul>', '<h3>Email deep dive</h3>
<p>The Email plugin is the foundation for CRM email activity. It owns the practical channel pieces: sender configuration, optional inbound capture, queue visibility, templates, signatures, and readiness checks.</p>
<h4>Setup path</h4>
<ul>
<li>Choose whether the workspace needs outreach, nurture, or both sender identities.</li>
<li>Add SMTP or supported OAuth details for sending, then save optional IMAP details for incoming capture.</li>
<li>Run readiness checks before relying on bulk sends, assistant drafts, or customer follow-up queues.</li>
</ul>
<h4>What changes after activation</h4>
<table>
<thead><tr><th>Area</th><th>What the team gets</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Outbound</td><td>Configured sender details for CRM email workflows.</td><td>Provider limits, credentials, and sender identity accuracy.</td></tr>
<tr><td>Inbound</td><td>Optional inbox capture for customer context.</td><td>IMAP access and folder selection.</td></tr>
<tr><td>Operations</td><td>Templates, signatures, queued sends, and channel checks.</td><td>Testing after credential or domain changes.</td></tr>
</tbody>
</table>
<blockquote><p>Start by making one sender identity reliable, then expand to nurture, inbox capture, and assistant workflows when the readiness check is clean.</p></blockquote>'),
('whatsapp', '<h3>Bring WhatsApp into the workspace with clear setup checks</h3>
<p>WhatsApp gives teams a dedicated place to connect the Business number, webhook readiness, send queue, and activity surfaces. It focuses on the channel itself, so customer messaging can be reviewed before assistant behavior is layered on top.</p>
<blockquote><p>Use this when customers respond fastest on WhatsApp and the team needs a steady way to confirm account, webhook, and delivery readiness.</p></blockquote>
<ul>
<li>Keep Meta account, number, and webhook details visible from Marketplace setup.</li>
<li>Separate WhatsApp channel health from WhatsApp Assistant session behavior.</li>
<li>Give owners a clear install and test path before live messaging begins.</li>
</ul>', '<h3>WhatsApp deep dive</h3>
<p>The WhatsApp plugin owns the CRM channel connection. It helps the workspace understand whether a Business number, webhook, token, and message queue are ready enough for real operational use.</p>
<h4>Setup path</h4>
<ul>
<li>Connect or manually configure the WhatsApp Business account and phone number.</li>
<li>Confirm webhook and token details before sending or receiving messages.</li>
<li>Run a readiness check after each connection or provider change.</li>
</ul>
<h4>Readiness map</h4>
<table>
<thead><tr><th>Need</th><th>Unlocks</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Business number</td><td>Workspace-level WhatsApp identity.</td><td>Correct number, account ownership, and sender expectations.</td></tr>
<tr><td>Webhook route</td><td>Inbound updates and status callbacks.</td><td>Public reachability and matching verify settings.</td></tr>
<tr><td>Queue health</td><td>Safer message processing and visibility.</td><td>Failed sends, expired tokens, and provider pauses.</td></tr>
</tbody>
</table>
<blockquote><p>Make the channel boring and dependable first. Once the checks pass, assistant sessions and higher-volume workflows have a better foundation.</p></blockquote>'),
('hr_analytics_setup', '<h3>Prepare Organization Intelligence before opening the dashboard</h3>
<p>Organization Intelligence Setup helps owners confirm the people-ops foundation: settings, business functions, departments, and staff ownership. It keeps the analytics dashboard gated until the workspace has enough structure to interpret team signals responsibly.</p>
<blockquote><p>Use this when leadership wants clearer operating insight, but the workspace still needs function ownership and staff context first.</p></blockquote>
<ul>
<li>Guide admins through the minimum setup needed for organization-level insight.</li>
<li>Keep missing functions, departments, and assignments visible before analytics opens.</li>
<li>Protect users from reading team metrics without a clear operating structure.</li>
</ul>', '<h3>Organization Intelligence Setup deep dive</h3>
<p>This required setup plugin prepares the workspace for people and function analytics. It does not replace the dashboard; it confirms the dashboard has enough company structure to be useful.</p>
<h4>Setup path</h4>
<ul>
<li>Review Organization Intelligence settings from Marketplace setup.</li>
<li>Create or confirm active business functions and departments.</li>
<li>Assign active workspace members to the functions they actually own.</li>
</ul>
<h4>Readiness map</h4>
<table>
<thead><tr><th>Setup area</th><th>Why it matters</th><th>Ready when</th></tr></thead>
<tbody>
<tr><td>Settings</td><td>Defines how the workspace treats organization insight.</td><td>Core settings are saved for the workspace.</td></tr>
<tr><td>Functions</td><td>Connects work to business ownership.</td><td>At least one active function exists.</td></tr>
<tr><td>Assignments</td><td>Gives staff activity a responsible operating home.</td><td>Active members have function assignments.</td></tr>
</tbody>
</table>
<blockquote><p>Start with the functions that exist today. The model can mature as the team becomes clearer about ownership.</p></blockquote>'),
('email_assistant', '<h3>Turn email into a calmer assisted workflow</h3>
<p>Email Assistant supports drafts, replies, inbound instructions, and digest workflows after the workspace email channel is configured. It helps teams move faster while keeping sender identity, confidence, and review controls visible.</p>
<blockquote><p>Use this when email work is repetitive or easy to miss, but customer-facing messages still deserve a human review moment.</p></blockquote>
<ul>
<li>Draft replies and outbound help from workspace context instead of blank pages.</li>
<li>Use optional inbound instructions and digests to keep operators informed.</li>
<li>Keep assistant behavior tied to clear permissions, sender settings, and test checks.</li>
</ul>', '<h3>Email Assistant deep dive</h3>
<p>Email Assistant sits above the Email channel. It can help with draft support, customer-thread awareness, inbound instruction handling, and daily digest routines when the underlying sender and inbox setup are ready.</p>
<h4>Setup path</h4>
<ul>
<li>Complete the Email plugin first so the channel can send and, when needed, receive.</li>
<li>Configure assistant identity, sender settings, inbound behavior, skills, and digest preferences.</li>
<li>Run controlled tests before using it in live customer workflows.</li>
</ul>
<h4>Operating controls</h4>
<table>
<thead><tr><th>Control</th><th>Purpose</th><th>Good default</th></tr></thead>
<tbody>
<tr><td>Identity</td><td>Defines who the assistant represents.</td><td>Use a recognizable workspace sender.</td></tr>
<tr><td>Confidence</td><td>Sets the threshold for suggested work.</td><td>Start conservative and adjust from review evidence.</td></tr>
<tr><td>Digest</td><td>Summarizes useful email activity for operators.</td><td>Send to a small accountable group first.</td></tr>
</tbody>
</table>
<blockquote><p>Enable one workflow at a time, review the output, then widen the assistant''s responsibilities as the team gains confidence.</p></blockquote>'),
('whatsapp_assistant', '<h3>Support short operational loops inside WhatsApp</h3>
<p>WhatsApp Assistant helps teams use WhatsApp for internal instructions, short digests, reminders, and session-aware follow-through after the WhatsApp channel is ready. It is designed for focused operational help, not unattended customer messaging.</p>
<blockquote><p>Use this when the team already works from WhatsApp and needs assistant support that respects channel readiness and session limits.</p></blockquote>
<ul>
<li>Handle compact instruction loops from a channel operators already watch.</li>
<li>Surface short digests and reminders without opening another dashboard.</li>
<li>Keep session, webhook, and template readiness visible before relying on it.</li>
</ul>', '<h3>WhatsApp Assistant deep dive</h3>
<p>WhatsApp Assistant depends on a ready WhatsApp channel. It focuses on internal assistant workflows such as instructions, summaries, reminders, and session continuity where the setup supports it.</p>
<h4>Setup path</h4>
<ul>
<li>Complete the WhatsApp plugin first so account, number, and webhook checks are ready.</li>
<li>Configure the assistant phone number ID, access token, webhook behavior, and session settings.</li>
<li>Send controlled test messages before inviting wider team use.</li>
</ul>
<h4>Readiness map</h4>
<table>
<thead><tr><th>Area</th><th>Unlocks</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Session settings</td><td>Clear rules for when assistant messages can continue.</td><td>Template and reopen expectations.</td></tr>
<tr><td>Webhook health</td><td>Inbound instructions and status awareness.</td><td>Route availability and token freshness.</td></tr>
<tr><td>Team rollout</td><td>Short operational support in a familiar channel.</td><td>Who is allowed to trigger assistant actions.</td></tr>
</tbody>
</table>
<blockquote><p>Start with internal reminders or digests, then add instruction handling after the team understands the session boundaries.</p></blockquote>'),
('sms_channel', '<h3>Add SMS for short, time-sensitive communication</h3>
<p>SMS Channel adds a lightweight messaging route for reminders, confirmations, urgent follow-up, and compact campaign nudges. It keeps provider credentials, sender setup, queueing, and delivery checks inside Marketplace before teams depend on SMS.</p>
<blockquote><p>Use this when a brief mobile message is more appropriate than an email or WhatsApp thread, and the team can keep consent and sender expectations clear.</p></blockquote>
<ul>
<li>Connect SMS provider details without exposing the channel before it is ready.</li>
<li>Send controlled tests before using reminders or campaign nudges.</li>
<li>Track delivery health so failed sends do not disappear into the background.</li>
</ul>', '<h3>SMS Channel deep dive</h3>
<p>SMS is best for concise operational messages. This plugin keeps the sender number, provider credentials, queue behavior, and delivery status visible before any workflow relies on mobile delivery.</p>
<h4>Setup path</h4>
<ul>
<li>Add provider credentials and a verified sender number or approved messaging service.</li>
<li>Configure webhook routes when inbound replies or delivery callbacks are needed.</li>
<li>Run a controlled send test before enabling bulk, reminder, or automation workflows.</li>
</ul>
<h4>Readiness map</h4>
<table>
<thead><tr><th>Need</th><th>Unlocks</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Provider credentials</td><td>Authenticated SMS sending.</td><td>Token validity and workspace ownership.</td></tr>
<tr><td>Sender number</td><td>Recognizable outbound identity.</td><td>Regional rules and recipient expectations.</td></tr>
<tr><td>Delivery callbacks</td><td>Better visibility into sent and failed messages.</td><td>Webhook availability and retry behavior.</td></tr>
</tbody>
</table>
<blockquote><p>Keep SMS focused. It works best when the message is short, expected, and useful enough to justify the interruption.</p></blockquote>'),
('calendar_meetings', '<h3>Make meetings part of the workspace memory</h3>
<p>Calendar &amp; Meetings connects scheduling, meeting bot setup, note ingestion, and prep signals so the CRM can understand what happened before and after important calls. It gives meeting-heavy teams one place to review readiness.</p>
<blockquote><p>Use this when calls drive sales, onboarding, delivery, or account decisions and the team needs cleaner follow-through after each meeting.</p></blockquote>
<ul>
<li>Connect calendar context to contacts, deals, tasks, and follow-up work.</li>
<li>Prepare meeting bot and note ingestion settings before live calls depend on them.</li>
<li>Give AI guidance better meeting context without scattering setup across pages.</li>
</ul>', '<h3>Calendar &amp; Meetings deep dive</h3>
<p>This plugin brings schedule context, meeting automation, notes, and preparation signals into one operations module. It helps teams turn calls into usable CRM evidence instead of leaving action items in memory.</p>
<h4>Setup path</h4>
<ul>
<li>Connect Google or Outlook Calendar for the workspace user.</li>
<li>Configure meeting bot identity, provider details, and join policy when bot support is needed.</li>
<li>Confirm note ingestion and consent expectations before recording or importing meeting content.</li>
</ul>
<h4>Readiness map</h4>
<table>
<thead><tr><th>Area</th><th>Unlocks</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Calendar sync</td><td>Meeting visibility and prep context.</td><td>Correct account connection and event scope.</td></tr>
<tr><td>Meeting bot</td><td>Automated join and capture support.</td><td>Provider credentials and join policy.</td></tr>
<tr><td>Notes</td><td>Follow-up and recommendation context.</td><td>Consent, accuracy, and review before action.</td></tr>
</tbody>
</table>
<blockquote><p>Begin with calendar sync, then add bot and notes only where the team has clear expectations for capture and review.</p></blockquote>'),
('finance', '<h3>Open Finance from a clean starting point</h3>
<p>Finance stays gated until the workspace records opening figures and owner equity context. That setup protects teams from treating incomplete statements as finished financial truth.</p>
<blockquote><p>Use this when the business is ready to track cash, expenses, funding, assets, liabilities, and owner ROI with a clear opening baseline.</p></blockquote>
<ul>
<li>Save opening cash, receivables, payables, assets, loans, and equity even when values are zero.</li>
<li>Link owner equity to active owner accounts for private ROI views.</li>
<li>Unlock Finance only after the setup review is ready.</li>
</ul>', '<h3>Finance deep dive</h3>
<p>The Finance plugin creates a careful opening gate for financial statements. It asks owners to review the starting position before the workspace relies on cash, balance, expense, funding, and ROI views.</p>
<h4>Setup path</h4>
<ul>
<li>Record the opening date, currency, and cash position.</li>
<li>Add receivables, payables, loans, assets, and other balances, or explicitly mark sections as none.</li>
<li>Allocate owner equity across active owner accounts before opening Finance.</li>
</ul>
<h4>Readiness map</h4>
<table>
<thead><tr><th>Area</th><th>Unlocks</th><th>Watch for</th></tr></thead>
<tbody>
<tr><td>Opening balances</td><td>Statements grounded in an explicit baseline.</td><td>Missing zero-value confirmations.</td></tr>
<tr><td>Owner equity</td><td>Private owner ROI context.</td><td>Active owner accounts and ownership percentages.</td></tr>
<tr><td>Ledger activity</td><td>Useful cash, expense, funding, and asset views.</td><td>Imported or manual entries that need review.</td></tr>
</tbody>
</table>
<blockquote><p>It is better to enter a simple, reviewed starting position than to open Finance with assumptions nobody has confirmed.</p></blockquote>'),
('ai_api', '<h3>Give each workspace a clear AI provider path</h3>
<p>AI API lets owners add a workspace-specific provider key while the platform common key remains the first route until the shared cap is reached. It keeps provider setup visible in Marketplace instead of hiding it deep in settings.</p>
<blockquote><p>Use this when a workspace needs AI continuity beyond the shared cap or wants to bring its own OpenAI-compatible key.</p></blockquote>
<ul>
<li>Show common-key routing and workspace override setup from one Marketplace page.</li>
<li>Let owners add provider URL, model, and API key when usage requires it.</li>
<li>Make blocked AI usage easier to explain when no workspace key is available after the cap.</li>
</ul>', '<h3>AI API deep dive</h3>
<p>AI API manages how a workspace reaches an AI provider. The common platform route can serve early usage, while a workspace key provides a clear fallback when the shared cap is reached or private billing is preferred.</p>
<h4>Setup path</h4>
<ul>
<li>Keep the default workspace common provider configured for platform-funded usage.</li>
<li>Add workspace provider URL, model, and API key when the workspace needs its own route.</li>
<li>Review cap status and provider readiness before troubleshooting AI features elsewhere.</li>
</ul>
<h4>Routing map</h4>
<table>
<thead><tr><th>State</th><th>What happens</th><th>Owner action</th></tr></thead>
<tbody>
<tr><td>Common cap available</td><td>AI requests use the shared platform provider.</td><td>No workspace key required yet.</td></tr>
<tr><td>Common cap reached</td><td>Requests move to the workspace provider when configured.</td><td>Add or verify the workspace key.</td></tr>
<tr><td>No provider available</td><td>The block is explicit so users know setup is needed.</td><td>Complete provider setup before retrying.</td></tr>
</tbody>
</table>
<blockquote><p>Keep the shared route simple for new teams, then add a workspace provider when usage or ownership makes that the cleaner path.</p></blockquote>');

UPDATE workspace_skill_definitions d
JOIN marketplace_plugin_overview_html_seed s ON s.skill_key = d.skill_key
SET d.plugin_metadata_json = JSON_SET(
        COALESCE(d.plugin_metadata_json, JSON_OBJECT()),
        '$.marketplace_profile.overview_brief_format', 'html',
        '$.marketplace_profile.overview_brief_content', s.overview_brief_content,
        '$.marketplace_profile.overview_deep_dive_format', 'html',
        '$.marketplace_profile.overview_deep_dive_content', s.overview_deep_dive_content
    ),
    d.updated_at = NOW()
WHERE d.module_type = 'plugin'
  AND d.definition_source = 'platform';

UPDATE workspace_skill_catalog_overrides o
JOIN marketplace_plugin_overview_html_seed s ON s.skill_key = o.skill_key
SET o.marketplace_profile_json = JSON_SET(
        COALESCE(o.marketplace_profile_json, JSON_OBJECT()),
        '$.overview_brief_format',
            CASE
                WHEN NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.marketplace_profile_json, '$.overview_brief_content')), '')), '') IS NULL THEN 'html'
                ELSE COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(o.marketplace_profile_json, '$.overview_brief_format')), ''), 'text')
            END,
        '$.overview_brief_content',
            CASE
                WHEN NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.marketplace_profile_json, '$.overview_brief_content')), '')), '') IS NULL THEN s.overview_brief_content
                ELSE JSON_UNQUOTE(JSON_EXTRACT(o.marketplace_profile_json, '$.overview_brief_content'))
            END,
        '$.overview_deep_dive_format',
            CASE
                WHEN NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.marketplace_profile_json, '$.overview_deep_dive_content')), '')), '') IS NULL THEN 'html'
                ELSE COALESCE(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(o.marketplace_profile_json, '$.overview_deep_dive_format')), ''), 'text')
            END,
        '$.overview_deep_dive_content',
            CASE
                WHEN NULLIF(TRIM(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(o.marketplace_profile_json, '$.overview_deep_dive_content')), '')), '') IS NULL THEN s.overview_deep_dive_content
                ELSE JSON_UNQUOTE(JSON_EXTRACT(o.marketplace_profile_json, '$.overview_deep_dive_content'))
            END
    ),
    o.updated_at = NOW();

DROP TEMPORARY TABLE marketplace_plugin_overview_html_seed;
