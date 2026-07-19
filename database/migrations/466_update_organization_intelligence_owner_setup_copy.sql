-- Align persisted Organization Intelligence Marketplace copy with owner-account responsibility coverage.

UPDATE workspace_skill_definitions
SET summary = 'Required organization intelligence for business areas, workload, coaching signals, and optional HR team structure.',
    plugin_metadata_json = JSON_SET(
        COALESCE(plugin_metadata_json, JSON_OBJECT()),
        '$.job_hints',
        JSON_ARRAY('Review business areas and optional team structure before opening the dashboard.'),
        '$.marketplace_profile.thumbnail_alt',
        'Organization Intelligence with guided business area and team structure checks.',
        '$.marketplace_profile.benefit_bullets',
        JSON_ARRAY(
            'Gives owners one guided setup path for business areas and optional team structure.',
            'Prevents analytics from opening before the workspace has enough business context.',
            'Keeps formal departments optional until HR-style reporting is useful.'
        ),
        '$.marketplace_profile.use_case_bullets',
        JSON_ARRAY(
            'Launching Organization Intelligence for a workspace.',
            'Checking whether missing business areas block the dashboard.',
            'Helping owners interpret workload, coaching, and team signals responsibly.'
        ),
        '$.marketplace_profile.outcome_bullets',
        JSON_ARRAY(
            'Default Organization Intelligence scoring is available.',
            'At least one active business area exists.',
            'Owner responsibility coverage is maintained from account and invite flows.'
        ),
        '$.marketplace_profile.recommendations',
        JSON_ARRAY(
            'Complete this before using Organization Intelligence to coach people or compare teams.',
            'Create business areas that match what the business does today.',
            'Keep responsibilities managed from user creation, invites, and owner access.'
        ),
        '$.marketplace_profile.prerequisites',
        JSON_ARRAY(
            'Workspace owner or admin access.',
            'At least one business area for the workspace.'
        ),
        '$.marketplace_profile.setup_guide',
        JSON_ARRAY(
            'Continue into the guided Organization Intelligence setup page.',
            'Create or confirm active business areas.',
            'Add departments only when formal HR structure is useful.',
            'Open Organization Intelligence after required setup passes.'
        ),
        '$.marketplace_profile.overview_brief_format',
        'html',
        '$.marketplace_profile.overview_brief_content',
        '<h3>Prepare Organization Intelligence before opening the dashboard</h3>
<p>Organization Intelligence helps owners confirm the operating foundation: active business areas, optional teams, and supportive HR signals. Owner responsibility coverage is handled from user accounts, so founder-led and multi-owner workspaces can open the dashboard without a separate plugin assignment step.</p>
<blockquote><p>Use this when leadership wants clearer operating insight without turning setup into technical configuration.</p></blockquote>
<ul>
<li>Guide owners through the minimum setup needed for organization-level insight.</li>
<li>Keep missing business areas and optional teams visible before analytics opens.</li>
<li>Protect users from reading workload, coaching, or team metrics without a clear operating structure.</li>
</ul>',
        '$.marketplace_profile.overview_deep_dive_format',
        'html',
        '$.marketplace_profile.overview_deep_dive_content',
        '<h3>Organization Intelligence deep dive</h3>
<p>This required plugin prepares the workspace for people and responsibility analytics. It does not replace the dashboard; it confirms the dashboard has enough company structure to be useful while user and invite flows maintain responsibility ownership.</p>
<h4>Guided setup path</h4>
<ul>
<li>Review the default scoring profile from Marketplace when advanced tuning is needed.</li>
<li>Create or confirm active business areas.</li>
<li>Add departments only when formal HR structure helps reporting and coaching.</li>
</ul>
<h4>Setup progress map</h4>
<table>
<thead><tr><th>Setup area</th><th>Why it matters</th><th>Ready when</th></tr></thead>
<tbody>
<tr><td>Default scoring</td><td>Defines how the workspace treats organization insight.</td><td>Core settings are available for the workspace.</td></tr>
<tr><td>Business areas</td><td>Connects work to real responsibility.</td><td>At least one active area exists.</td></tr>
<tr><td>Owner coverage</td><td>Gives founder-led and multi-owner teams a responsible operating home.</td><td>Owners receive full active business-area coverage from account and invite flows.</td></tr>
<tr><td>Teams & HR structure</td><td>Adds department comparison and manager context.</td><td>Optional departments exist when the business needs them.</td></tr>
</tbody>
</table>
<blockquote><p>Start with the business areas that exist today. The model can mature as the team becomes clearer about formal structure.</p></blockquote>'
    )
WHERE skill_key = 'hr_analytics_setup';

UPDATE workspace_skill_catalog_overrides
SET marketplace_profile_json = JSON_SET(
        COALESCE(marketplace_profile_json, JSON_OBJECT()),
        '$.overview_brief_format',
        'html',
        '$.overview_brief_content',
        '<h3>Prepare Organization Intelligence before opening the dashboard</h3>
<p>Organization Intelligence helps owners confirm the operating foundation: active business areas, optional teams, and supportive HR signals. Owner responsibility coverage is handled from user accounts, so founder-led and multi-owner workspaces can open the dashboard without a separate plugin assignment step.</p>
<blockquote><p>Use this when leadership wants clearer operating insight without turning setup into technical configuration.</p></blockquote>
<ul>
<li>Guide owners through the minimum setup needed for organization-level insight.</li>
<li>Keep missing business areas and optional teams visible before analytics opens.</li>
<li>Protect users from reading workload, coaching, or team metrics without a clear operating structure.</li>
</ul>',
        '$.overview_deep_dive_format',
        'html',
        '$.overview_deep_dive_content',
        '<h3>Organization Intelligence deep dive</h3>
<p>This required plugin prepares the workspace for people and responsibility analytics. It does not replace the dashboard; it confirms the dashboard has enough company structure to be useful while user and invite flows maintain responsibility ownership.</p>
<h4>Guided setup path</h4>
<ul>
<li>Review the default scoring profile from Marketplace when advanced tuning is needed.</li>
<li>Create or confirm active business areas.</li>
<li>Add departments only when formal HR structure helps reporting and coaching.</li>
</ul>
<h4>Setup progress map</h4>
<table>
<thead><tr><th>Setup area</th><th>Why it matters</th><th>Ready when</th></tr></thead>
<tbody>
<tr><td>Default scoring</td><td>Defines how the workspace treats organization insight.</td><td>Core settings are available for the workspace.</td></tr>
<tr><td>Business areas</td><td>Connects work to real responsibility.</td><td>At least one active area exists.</td></tr>
<tr><td>Owner coverage</td><td>Gives founder-led and multi-owner teams a responsible operating home.</td><td>Owners receive full active business-area coverage from account and invite flows.</td></tr>
<tr><td>Teams & HR structure</td><td>Adds department comparison and manager context.</td><td>Optional departments exist when the business needs them.</td></tr>
</tbody>
</table>
<blockquote><p>Start with the business areas that exist today. The model can mature as the team becomes clearer about formal structure.</p></blockquote>'
    )
WHERE skill_key = 'hr_analytics_setup';
