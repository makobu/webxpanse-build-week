<?php

declare(strict_types=1);

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Auth;
use CRM\Services\GuidedDemoPackageIntentService;
use CRM\Services\GuidedDemoStepCatalog;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceOnboardingService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class GuidedFounderDemoModeTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testCompletedWorkspaceCanReachDashboardAndSeeOptionalProductDemoPrompt(): void
    {
        $seed = $this->seedWorkspace('guided-demo-gate');
        $this->completeOnboarding($seed);

        $response = $this->runWebEndpoint('public/dashboard.php', $this->webSession($seed), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), $body);
        $this->assertStringContainsString('id="dashboard-product-demo-prompt"', $body);
        $this->assertStringContainsString('View product demo', $body);
        $this->assertStringNotContainsString('Explore a normal CRM workday', $body);
    }

    public function testGuidedDemoStartSeedsWorkspacePluginsAndCleanupRestoresState(): void
    {
        $seed = $this->seedWorkspace('guided-demo-lifecycle');
        $this->completeOnboarding($seed);
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];

        (new WorkspaceSkillCatalogService())->syncDefinitions();
        Database::execute(
            "INSERT INTO workspace_skill_installs
                (workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at)
             VALUES (?, ?, 'installed', ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                status = 'installed',
                config_json = VALUES(config_json),
                updated_by_user_id = VALUES(updated_by_user_id),
                disabled_at = NULL,
                uninstalled_at = NULL",
            [
                $workspaceId,
                WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                json_encode(['source' => 'preexisting']),
                $userId,
                $userId,
            ]
        );

        $start = $this->runWebEndpoint('api/guided_demo/start.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => ['csrf_token' => 'csrf-guided-demo'],
        ]);
        $this->assertSame(200, (int) ($start['status'] ?? 0), (string) ($start['body'] ?? ''));
        $payload = json_decode((string) ($start['body'] ?? '{}'), true);
        $this->assertTrue((bool) ($payload['success'] ?? false), (string) ($payload['error'] ?? ''));
        $this->assertStringContainsString('dashboard.php', (string) ($payload['data']['redirect_url'] ?? ''));

        $session = Database::queryOne(
            "SELECT * FROM guided_demo_sessions WHERE workspace_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1",
            [$workspaceId, $userId]
        );
        $this->assertNotNull($session);
        $this->assertSame('active', (string) ($session['status'] ?? ''));
        $runId = (int) ($session['demo_run_id'] ?? 0);
        $this->assertGreaterThan(0, $runId);

        $founderContacts = Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = ? AND email LIKE ?",
            [$workspaceId, '%.founder.' . $runId . '@example.test']
        );
        $otherWorkspaceFounderContacts = Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE workspace_id <> ? AND email LIKE ?",
            [$workspaceId, '%.founder.' . $runId . '@example.test']
        );
        $sessionMetadata = json_decode((string) ($session['metadata_json'] ?? '{}'), true) ?: [];
        $this->assertSame(0, (int) ($founderContacts['c'] ?? 0));
        $this->assertSame(0, (int) ($otherWorkspaceFounderContacts['c'] ?? 0));
        $this->assertSame(0, (int) ($sessionMetadata['primary_contact_id'] ?? 0));
        $this->assertSame(0, (int) ($sessionMetadata['seed_summary']['created']['tasks'] ?? 0));

        $seededDemoTasks = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM demo_seed_registry
             WHERE run_id = ?
               AND table_name = 'tasks'",
            [$runId]
        );
        $this->assertSame(0, (int) ($seededDemoTasks['c'] ?? -1));

        $demoInstall = Database::queryOne(
            "SELECT config_json FROM workspace_skill_installs WHERE workspace_id = ? AND skill_key = ? LIMIT 1",
            [$workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS]
        );
        $demoConfig = json_decode((string) ($demoInstall['config_json'] ?? '{}'), true);
        $this->assertSame('guided_demo', (string) ($demoConfig['source'] ?? ''));
        $this->assertTrue((bool) ($demoConfig['simulation_only'] ?? false));

        $blockedAction = $this->runWebEndpoint('api/guided_demo/action.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-guided-demo',
                'step_key' => 'add_demo_contact',
                'action_key' => 'add_demo_contact',
            ],
        ]);
        $this->assertSame(422, (int) ($blockedAction['status'] ?? 0), (string) ($blockedAction['body'] ?? ''));

        foreach ([
            'add_demo_contact',
            'preview_outreach_message',
            'simulate_reply_received',
            'create_follow_up_task',
            'prepare_demo_quote',
        ] as $stepKey) {
            $stepAction = (new GuidedDemoStepCatalog())->actionFor($stepKey) ?? [];
            Database::execute(
                "UPDATE guided_demo_sessions SET current_step_key = ? WHERE id = ?",
                [$stepKey, (int) $session['id']]
            );
            $action = $this->runWebEndpoint('api/guided_demo/action.php', $this->webSession($seed), [
                'method' => 'POST',
                'post' => [
                    'csrf_token' => 'csrf-guided-demo',
                    'step_key' => $stepKey,
                    'action_key' => $stepKey,
                    'choice_key' => $this->firstChoiceKey($stepAction),
                ],
            ]);
            $this->assertSame(200, (int) ($action['status'] ?? 0), (string) ($action['body'] ?? ''));
            $actionPayload = json_decode((string) ($action['body'] ?? '{}'), true);
            $this->assertTrue((bool) ($actionPayload['success'] ?? false), (string) ($actionPayload['error'] ?? ''));
        }

        $createdDemoContacts = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM demo_seed_registry r
             INNER JOIN contacts c ON c.id = r.record_id AND c.workspace_id = ?
             WHERE r.run_id = ? AND r.table_name = 'contacts'",
            [$workspaceId, $runId]
        );
        $this->assertSame(1, (int) ($createdDemoContacts['c'] ?? 0));

        $actionCount = Database::queryOne(
            "SELECT COUNT(*) AS c FROM guided_demo_action_runs WHERE session_id = ? AND status = 'completed'",
            [(int) $session['id']]
        );
        $this->assertSame(5, (int) ($actionCount['c'] ?? 0));

        $actionRegistryRows = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM demo_seed_registry
             WHERE run_id = ?
               AND table_name IN ('contacts', 'communications', 'tasks', 'invoices')",
            [$runId]
        );
        $this->assertGreaterThanOrEqual(4, (int) ($actionRegistryRows['c'] ?? 0));

        $end = $this->runWebEndpoint('api/guided_demo/end.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-guided-demo',
                'reason' => 'exited',
            ],
        ]);
        $this->assertSame(200, (int) ($end['status'] ?? 0), (string) ($end['body'] ?? ''));

        $registry = Database::queryOne('SELECT COUNT(*) AS c FROM demo_seed_registry WHERE run_id = ?', [$runId]);
        $remainingContacts = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM contacts
             WHERE workspace_id = ?
               AND (email LIKE ? OR email LIKE ?)",
            [
                $workspaceId,
                '%.founder.' . $runId . '@example.test',
                '%.demo.' . $runId . '@example.test',
            ]
        );
        $remainingActionContacts = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM contacts
             WHERE workspace_id = ?
               AND metadata_json LIKE ?",
            [$workspaceId, '%guided_demo_action%']
        );
        $restoredInstall = Database::queryOne(
            "SELECT status, config_json FROM workspace_skill_installs WHERE workspace_id = ? AND skill_key = ? LIMIT 1",
            [$workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS]
        );
        $restoredConfig = json_decode((string) ($restoredInstall['config_json'] ?? '{}'), true);

        $this->assertSame(0, (int) ($registry['c'] ?? -1));
        $this->assertSame(0, (int) ($remainingContacts['c'] ?? -1));
        $this->assertSame(0, (int) ($remainingActionContacts['c'] ?? -1));
        $this->assertSame('installed', (string) ($restoredInstall['status'] ?? ''));
        $this->assertSame('preexisting', (string) ($restoredConfig['source'] ?? ''));
    }

    public function testGuidedDemoWalksEveryPageActionAndCleanupInOrder(): void
    {
        $seed = $this->seedWorkspace('guided-demo-page-audit');
        $this->completeOnboarding($seed);
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];
        $catalog = new GuidedDemoStepCatalog();
        $steps = $catalog->steps();

        (new WorkspaceSkillCatalogService())->syncDefinitions();
        Database::execute(
            "INSERT INTO workspace_skill_installs
                (workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at)
             VALUES (?, ?, 'installed', ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                status = 'installed',
                config_json = VALUES(config_json),
                updated_by_user_id = VALUES(updated_by_user_id),
                disabled_at = NULL,
                uninstalled_at = NULL",
            [
                $workspaceId,
                WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                json_encode(['source' => 'preexisting']),
                $userId,
                $userId,
            ]
        );

        $start = $this->runWebEndpoint('api/guided_demo/start.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => ['csrf_token' => 'csrf-guided-demo'],
        ]);
        $state = $this->assertGuidedDemoJsonSuccess($start);
        $sessionId = (int) ($state['session']['id'] ?? 0);
        $runId = (int) ($state['session']['metadata']['seed_summary']['run_id'] ?? 0);
        if ($runId <= 0) {
            $session = Database::queryOne('SELECT demo_run_id FROM guided_demo_sessions WHERE id = ? LIMIT 1', [$sessionId]) ?? [];
            $runId = (int) ($session['demo_run_id'] ?? 0);
        }
        $this->assertGreaterThan(0, $sessionId);
        $this->assertGreaterThan(0, $runId);
        $this->assertSame($catalog->firstKey(), (string) ($state['step']['key'] ?? ''));
        $this->assertSame(0, (int) ($state['session']['metadata']['primary_contact_id'] ?? 0));

        $initialFounderContacts = Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = ? AND email LIKE ?",
            [$workspaceId, '%.founder.' . $runId . '@example.test']
        );
        $this->assertSame(0, (int) ($initialFounderContacts['c'] ?? 0));
        $this->assertSame(0, (int) ($state['session']['metadata']['seed_summary']['created']['tasks'] ?? 0));

        $initialDemoTasks = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM demo_seed_registry
             WHERE run_id = ?
               AND table_name = 'tasks'",
            [$runId]
        );
        $this->assertSame(0, (int) ($initialDemoTasks['c'] ?? -1));

        foreach ($steps as $index => $expectedStep) {
            $expectedKey = (string) ($expectedStep['key'] ?? '');
            $expectedPage = (string) ($expectedStep['page'] ?? '');

            $state = $this->currentGuidedDemoState($seed);
            $this->assertSame($expectedKey, (string) ($state['step']['key'] ?? ''), 'Unexpected current step at audit index ' . ($index + 1));
            $this->assertSame($index + 1, (int) ($state['step']['progress']['index'] ?? 0));
            $this->assertSame(count($steps), (int) ($state['step']['progress']['total'] ?? 0));
            $this->assertSame((string) ($expectedStep['phase'] ?? ''), (string) ($state['step']['phase'] ?? ''), 'Phase mismatch for ' . $expectedKey);
            $this->assertSame((string) ($expectedStep['phase_label'] ?? ''), (string) ($state['step']['phase_label'] ?? ''), 'Phase label mismatch for ' . $expectedKey);
            $this->assertSame((string) ($expectedStep['why'] ?? ''), (string) ($state['step']['why'] ?? ''), 'Lesson copy mismatch for ' . $expectedKey);
            $this->assertNotSame('', trim((string) ($state['step']['why'] ?? '')), 'Lesson copy should be visible for ' . $expectedKey);
            $this->assertRedirectTargetsStepPage($state, $expectedPage, $expectedStep);
            $this->assertGuidedDemoPageRenders($seed, $state, $expectedStep);

            $action = is_array($expectedStep['action'] ?? null) ? $expectedStep['action'] : null;
            if ($action !== null && !empty($action['required'])) {
                $blocked = $this->advanceGuidedDemo($seed, 'next');
                $this->assertSame(422, (int) ($blocked['status'] ?? 0), (string) ($blocked['body'] ?? ''));
                $blockedPayload = json_decode((string) ($blocked['body'] ?? '{}'), true);
                $this->assertStringContainsString('Complete the demo action', (string) ($blockedPayload['error'] ?? ''));
                $this->assertSame($expectedKey, (string) ($this->currentGuidedDemoState($seed)['step']['key'] ?? ''));

                $missingChoiceResponse = $this->runWebEndpoint('api/guided_demo/action.php', $this->webSession($seed), [
                    'method' => 'POST',
                    'post' => [
                        'csrf_token' => 'csrf-guided-demo',
                        'step_key' => $expectedKey,
                        'action_key' => (string) ($action['key'] ?? ''),
                    ],
                ]);
                $this->assertSame(422, (int) ($missingChoiceResponse['status'] ?? 0), (string) ($missingChoiceResponse['body'] ?? ''));
                $missingChoicePayload = json_decode((string) ($missingChoiceResponse['body'] ?? '{}'), true);
                $this->assertStringContainsString('Choose one option', (string) ($missingChoicePayload['error'] ?? ''));

                $choiceKey = $this->firstChoiceKey($action);

                $actionResponse = $this->runWebEndpoint('api/guided_demo/action.php', $this->webSession($seed), [
                    'method' => 'POST',
                    'post' => [
                        'csrf_token' => 'csrf-guided-demo',
                        'step_key' => $expectedKey,
                        'action_key' => (string) ($action['key'] ?? ''),
                        'choice_key' => $choiceKey,
                    ],
                ]);
                $actionPayload = $this->assertGuidedDemoJsonSuccess($actionResponse);
                $this->assertSame($expectedKey, (string) ($actionPayload['state']['step']['key'] ?? ''));
                $this->assertSame((string) ($expectedStep['phase'] ?? ''), (string) ($actionPayload['state']['step']['phase'] ?? ''));
                $this->assertSame((string) ($expectedStep['why'] ?? ''), (string) ($actionPayload['state']['step']['why'] ?? ''));
                $this->assertContains((string) ($action['key'] ?? ''), (array) ($actionPayload['state']['completed_actions'] ?? []));
                $this->assertTrue((bool) ($actionPayload['state']['step']['demo_action']['completed'] ?? false));
                $this->assertSame($choiceKey, (string) ($actionPayload['state']['step']['demo_action']['selected_choice_key'] ?? ''));
                $this->assertSame($choiceKey, (string) ($actionPayload['result']['selected_choice_key'] ?? ''));
                if ($expectedKey === 'preview_outreach_message') {
                    $draftBody = (string) ($actionPayload['result']['draft_body'] ?? '');
                    $this->assertSame('I added the draft. Nothing was sent.', (string) ($actionPayload['message'] ?? ''));
                    $this->assertNotSame('', trim((string) ($actionPayload['result']['draft_subject'] ?? '')));
                    $this->assertNotSame('', trim($draftBody));
                    $this->assertGreaterThanOrEqual(240, strlen($draftBody));
                    $this->assertMatchesRegularExpression('/\n\s*\n/', $draftBody);
                    $this->assertGreaterThanOrEqual(2, substr_count($draftBody, "\n\n"));
                    $this->assertSame($draftBody, (string) ($actionPayload['result']['draft_preview'] ?? ''));
                }
                if ($expectedKey === 'add_demo_contact') {
                    $contactId = (int) ($actionPayload['result']['contact_id'] ?? 0);
                    $this->assertGreaterThan(0, $contactId);
                    $this->assertSame($contactId, (int) ($actionPayload['state']['session']['metadata']['primary_contact_id'] ?? 0));
                    $createdContact = Database::queryOne(
                        'SELECT id, email FROM contacts WHERE workspace_id = ? AND id = ? LIMIT 1',
                        [$workspaceId, $contactId]
                    );
                    $this->assertNotNull($createdContact);
                    $createdContactEmail = (string) ($createdContact['email'] ?? '');
                    $this->assertStringEndsWith('@example.com', $createdContactEmail);
                    $this->assertStringNotContainsString('@example.test', $createdContactEmail);

                    $registeredContacts = Database::queryOne(
                        "SELECT COUNT(*) AS c
                         FROM demo_seed_registry r
                         INNER JOIN contacts c ON c.id = r.record_id AND c.workspace_id = ?
                         WHERE r.run_id = ? AND r.table_name = 'contacts'",
                        [$workspaceId, $runId]
                    );
                    $this->assertSame(1, (int) ($registeredContacts['c'] ?? 0));
                }
                if ($expectedKey === 'simulate_reply_received') {
                    $replyPreview = (string) ($actionPayload['result']['reply_preview'] ?? '');
                    $communicationId = (int) ($actionPayload['result']['communication_id'] ?? 0);
                    $this->assertSame('Reply added', (string) ($actionPayload['result']['result_title'] ?? ''));
                    $this->assertSame(
                        'Next, you can add the follow-up task.',
                        (string) ($actionPayload['result']['result_body'] ?? '')
                    );
                    $this->assertNotSame('', trim($replyPreview));
                    $this->assertStringContainsString('starter version cost', $replyPreview);
                    $this->assertGreaterThan(0, $communicationId);

                    $createdCommunication = Database::queryOne(
                        'SELECT id, direction, body, status FROM communications WHERE workspace_id = ? AND id = ? LIMIT 1',
                        [$workspaceId, $communicationId]
                    );
                    $this->assertNotNull($createdCommunication);
                    $this->assertSame('inbound', (string) ($createdCommunication['direction'] ?? ''));
                    $this->assertSame('read', (string) ($createdCommunication['status'] ?? ''));
                    $this->assertSame($replyPreview, (string) ($createdCommunication['body'] ?? ''));

                    $blockedInboxApiResponse = $this->runWebEndpoint('api/inbox.php', $this->webSession($seed), [
                        'method' => 'GET',
                        'query' => [
                            'list' => '1',
                            'status' => 'all',
                            'owner_scope' => 'mine_unassigned',
                        ],
                    ]);
                    $this->assertSame(403, (int) ($blockedInboxApiResponse['status'] ?? 0), (string) ($blockedInboxApiResponse['body'] ?? ''));

                    $inboxApiResponse = $this->runWebEndpoint('api/inbox.php', $this->webSession($seed), [
                        'method' => 'GET',
                        'query' => [
                            'list' => '1',
                            'status' => 'all',
                            'owner_scope' => 'mine_unassigned',
                            'guided_demo' => '1',
                        ],
                    ]);
                    $this->assertSame(200, (int) ($inboxApiResponse['status'] ?? 0), (string) ($inboxApiResponse['body'] ?? ''));
                    $inboxPayload = json_decode((string) ($inboxApiResponse['body'] ?? '{}'), true);
                    $inboxCommunicationIds = array_map(
                        static fn(array $row): int => (int) ($row['id'] ?? 0),
                        (array) ($inboxPayload['communications'] ?? [])
                    );
                    $this->assertContains($communicationId, $inboxCommunicationIds);
                    $this->assertGreaterThanOrEqual(1, (int) ($inboxPayload['channel_stats']['email'] ?? 0));
                }
                if ($expectedKey === 'create_follow_up_task') {
                    $taskId = (int) ($actionPayload['result']['task_id'] ?? 0);
                    $this->assertGreaterThan(0, $taskId);
                    $this->assertSame($taskId, (int) ($actionPayload['state']['session']['metadata']['simulated_follow_up_task_id'] ?? 0));

                    $createdTask = Database::queryOne(
                        'SELECT id, title, status, assigned_to FROM tasks WHERE workspace_id = ? AND id = ? LIMIT 1',
                        [$workspaceId, $taskId]
                    );
                    $this->assertNotNull($createdTask);
                    $this->assertSame('pending', (string) ($createdTask['status'] ?? ''));
                    $this->assertSame($userId, (int) ($createdTask['assigned_to'] ?? 0));

                    $registeredTasks = Database::queryOne(
                        "SELECT COUNT(*) AS c
                         FROM demo_seed_registry
                         WHERE run_id = ?
                           AND table_name = 'tasks'",
                        [$runId]
                    );
                    $this->assertSame(1, (int) ($registeredTasks['c'] ?? 0));

                    $repeatResponse = $this->runWebEndpoint('api/guided_demo/action.php', $this->webSession($seed), [
                        'method' => 'POST',
                        'post' => [
                            'csrf_token' => 'csrf-guided-demo',
                            'step_key' => $expectedKey,
                            'action_key' => (string) ($action['key'] ?? ''),
                            'choice_key' => $choiceKey,
                        ],
                    ]);
                    $repeatPayload = $this->assertGuidedDemoJsonSuccess($repeatResponse);
                    $this->assertSame($taskId, (int) ($repeatPayload['result']['task_id'] ?? 0));

                    $registeredTasksAfterRepeat = Database::queryOne(
                        "SELECT COUNT(*) AS c
                         FROM demo_seed_registry
                         WHERE run_id = ?
                           AND table_name = 'tasks'",
                        [$runId]
                    );
                    $this->assertSame(1, (int) ($registeredTasksAfterRepeat['c'] ?? 0));
                }
                if ($expectedKey === 'prepare_demo_quote') {
                    $invoiceId = (int) ($actionPayload['result']['invoice_id'] ?? 0);
                    $documentTitle = (string) ($actionPayload['result']['document_title'] ?? '');
                    $this->assertGreaterThan(0, $invoiceId);
                    $this->assertNotSame('', trim($documentTitle));
                    $this->assertSame('Document ready', (string) ($actionPayload['result']['result_title'] ?? ''));
                    $this->assertSame(
                        'You can see the draft in your document list. Nothing was sent.',
                        (string) ($actionPayload['result']['result_body'] ?? '')
                    );
                    $this->assertStringContainsString('invoice_view.php?id=' . $invoiceId, (string) ($actionPayload['result']['document_url'] ?? ''));
                    $this->assertSame($invoiceId, (int) ($actionPayload['state']['session']['metadata']['simulated_invoice_id'] ?? 0));
                    $createdInvoice = Database::queryOne(
                        "SELECT id, status FROM invoices WHERE workspace_id = ? AND id = ? LIMIT 1",
                        [$workspaceId, $invoiceId]
                    );
                    $this->assertNotNull($createdInvoice);
                    $this->assertSame('draft', (string) ($createdInvoice['status'] ?? ''));

                    $invoiceListQuery = ['guided_demo' => '1', 'guided_demo_result' => 'prepared_document'];
                    $invoiceListResponse = $this->runWebEndpoint('public/invoices.php', $this->webSession($seed), [
                        'method' => 'GET',
                        'query' => $invoiceListQuery,
                        'server' => [
                            'REQUEST_URI' => '/public/invoices.php?' . http_build_query($invoiceListQuery),
                            'SCRIPT_NAME' => '/public/invoices.php',
                        ],
                    ]);
                    $invoiceListBody = (string) ($invoiceListResponse['body'] ?? '');
                    $workspaceDocumentCount = (int) (Database::queryOne(
                        'SELECT COUNT(*) AS c FROM invoices WHERE workspace_id = ?',
                        [$workspaceId]
                    )['c'] ?? 0);
                    $this->assertSame(200, (int) ($invoiceListResponse['status'] ?? 0), $invoiceListBody);
                    $this->assertGreaterThanOrEqual(1, $workspaceDocumentCount);
                    $this->assertStringContainsString($documentTitle, $invoiceListBody);
                    $this->assertStringContainsString('data-guided-demo-prepared-document-visible="1"', $invoiceListBody);
                    $this->assertStringContainsString('data-guided-demo-result-marker="prepared_document"', $invoiceListBody);
                    $this->assertStringContainsString('data-guided-demo-prepared-row="1"', $invoiceListBody);
                    $this->assertStringContainsString('Demo draft ready', $invoiceListBody);
                    $this->assertStringContainsString(
                        '<div class="invoice-highlight-metric-value">' . number_format($workspaceDocumentCount) . '</div>',
                        $invoiceListBody
                    );
                    $this->assertStringNotContainsString('No commercial documents found for the current filters.', $invoiceListBody);

                    $invoiceViewQuery = ['id' => $invoiceId, 'guided_demo' => '1'];
                    $invoiceViewResponse = $this->runWebEndpoint('public/invoice_view.php', $this->webSession($seed), [
                        'method' => 'GET',
                        'query' => $invoiceViewQuery,
                        'server' => [
                            'REQUEST_URI' => '/public/invoice_view.php?' . http_build_query($invoiceViewQuery),
                            'SCRIPT_NAME' => '/public/invoice_view.php',
                        ],
                    ]);
                    $this->assertSame(200, (int) ($invoiceViewResponse['status'] ?? 0), (string) ($invoiceViewResponse['body'] ?? ''));
                    $this->assertStringContainsString($documentTitle, (string) ($invoiceViewResponse['body'] ?? ''));
                }
            }

            if ($index < count($steps) - 1) {
                $nextPayload = $this->assertGuidedDemoJsonSuccess($this->advanceGuidedDemo($seed, 'next'));
                $this->assertSame((string) ($steps[$index + 1]['key'] ?? ''), (string) ($nextPayload['step']['key'] ?? ''));

                $backPayload = $this->assertGuidedDemoJsonSuccess($this->advanceGuidedDemo($seed, 'back'));
                $this->assertSame($expectedKey, (string) ($backPayload['step']['key'] ?? ''));

                $nextPayload = $this->assertGuidedDemoJsonSuccess($this->advanceGuidedDemo($seed, 'next'));
                $this->assertSame((string) ($steps[$index + 1]['key'] ?? ''), (string) ($nextPayload['step']['key'] ?? ''));
            }
        }

        $completedActions = Database::queryOne(
            "SELECT COUNT(*) AS c FROM guided_demo_action_runs WHERE session_id = ? AND status = 'completed'",
            [$sessionId]
        );
        $this->assertSame(5, (int) ($completedActions['c'] ?? 0));

        $end = $this->runWebEndpoint('api/guided_demo/end.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-guided-demo',
                'reason' => 'completed',
            ],
        ]);
        $endPayload = $this->assertGuidedDemoJsonSuccess($end);
        $this->assertStringContainsString('dashboard.php?demo=complete', (string) ($endPayload['redirect_url'] ?? ''));

        $stateAfterCleanup = $this->runWebEndpoint('api/guided_demo/state.php', $this->webSession($seed), [
            'method' => 'GET',
        ]);
        $stateAfterCleanupPayload = json_decode((string) ($stateAfterCleanup['body'] ?? '{}'), true);
        $this->assertSame(200, (int) ($stateAfterCleanup['status'] ?? 0), (string) ($stateAfterCleanup['body'] ?? ''));
        $this->assertTrue((bool) ($stateAfterCleanupPayload['success'] ?? false));
        $this->assertNull($stateAfterCleanupPayload['data'] ?? null);

        $registry = Database::queryOne('SELECT COUNT(*) AS c FROM demo_seed_registry WHERE run_id = ?', [$runId]);
        $remainingFounderContacts = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM contacts
             WHERE workspace_id = ?
               AND (email LIKE ? OR email LIKE ?)",
            [
                $workspaceId,
                '%.founder.' . $runId . '@example.test',
                '%.demo.' . $runId . '@example.test',
            ]
        );
        $remainingActionContacts = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM contacts
             WHERE workspace_id = ?
               AND metadata_json LIKE ?",
            [$workspaceId, '%guided_demo_action%']
        );
        $restoredInstall = Database::queryOne(
            "SELECT status, config_json FROM workspace_skill_installs WHERE workspace_id = ? AND skill_key = ? LIMIT 1",
            [$workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS]
        );
        $restoredConfig = json_decode((string) ($restoredInstall['config_json'] ?? '{}'), true);

        $this->assertSame(0, (int) ($registry['c'] ?? -1));
        $this->assertSame(0, (int) ($remainingFounderContacts['c'] ?? -1));
        $this->assertSame(0, (int) ($remainingActionContacts['c'] ?? -1));
        $this->assertSame('installed', (string) ($restoredInstall['status'] ?? ''));
        $this->assertSame('preexisting', (string) ($restoredConfig['source'] ?? ''));
    }

    public function testPackageChooserRecordsManualPackageIntent(): void
    {
        $seed = $this->seedWorkspace('guided-demo-package');
        $this->completeOnboarding($seed);

        $response = $this->runWebEndpoint('public/billing_choose_package.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-guided-demo',
                'package_code' => GuidedDemoPackageIntentService::PACKAGE_SOLO_LAUNCH,
                'contact_method' => 'whatsapp',
                'notes' => 'Ready for a 30-day guided launch.',
            ],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $intent = Database::queryOne(
            "SELECT package_code, status, contact_method, notes
             FROM guided_demo_package_intents
             WHERE workspace_id = ? AND user_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        );

        $this->assertNotNull($intent);
        $this->assertSame(GuidedDemoPackageIntentService::PACKAGE_SOLO_LAUNCH, (string) ($intent['package_code'] ?? ''));
        $this->assertSame('checkout_started', (string) ($intent['status'] ?? ''));
        $this->assertSame('whatsapp', (string) ($intent['contact_method'] ?? ''));
        $this->assertStringContainsString('30-day', (string) ($intent['notes'] ?? ''));
    }

    public function testPackageChooserUsesSupportCalendarSlotsForNegotiatedPackageBooking(): void
    {
        $supportUserId = (int) Auth::createUser(
            'guided-demo-package-support@example.test',
            'P@ssword123!',
            'admin',
            'Package',
            'Support'
        );
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $supportUserId, 'superadmin', true, $supportUserId);
        $seed = $this->seedWorkspace('guided-demo-package-slots');
        $this->completeOnboarding($seed);

        $response = $this->runWebEndpoint('public/billing_choose_package.php', $this->webSession($seed), [
            'method' => 'GET',
        ]);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('data-package-booking-trigger', $body);
        $this->assertStringContainsString('data-package-booking-calendar', $body);
        $this->assertStringContainsString('data-package-booking-slot', $body);
        $this->assertStringContainsString('name="meeting_slot"', $body);
        $this->assertStringNotContainsString('<select name="meeting_slot"', $body);
        $this->assertStringContainsString('Account and workspace details will be added automatically.', $body);
        $this->assertStringNotContainsString('name="meeting_date"', $body);
        $this->assertStringNotContainsString('name="meeting_time"', $body);
    }

    public function testWorkspaceSkillsGuidedDemoAccessIsLimitedToActiveModuleStep(): void
    {
        $seed = $this->seedWorkspace('guided-demo-module-access');
        $this->completeOnboarding($seed);
        $workspaceId = (int) $seed['workspace_id'];
        $userId = (int) $seed['user_id'];

        (new WorkspaceSkillCatalogService())->syncDefinitions();
        $this->revokeRolePermissions('owner', ['workspace.skills.view', 'workspace.skills.manage']);

        $start = $this->runWebEndpoint('api/guided_demo/start.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => ['csrf_token' => 'csrf-guided-demo'],
        ]);
        $state = $this->assertGuidedDemoJsonSuccess($start);
        $sessionId = (int) ($state['session']['id'] ?? 0);
        $this->assertGreaterThan(0, $sessionId);

        $emailModuleQuery = ['guided_demo' => '1', 'module' => 'email'];
        $wrongStepResponse = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed), [
            'method' => 'GET',
            'query' => $emailModuleQuery,
            'server' => [
                'REQUEST_URI' => '/public/workspace_skills.php?' . http_build_query($emailModuleQuery),
                'SCRIPT_NAME' => '/public/workspace_skills.php',
            ],
        ]);
        $this->assertSame(403, (int) ($wrongStepResponse['status'] ?? 0), (string) ($wrongStepResponse['body'] ?? ''));
        $this->assertStringContainsString('Access denied: Marketplace access requires the Workspace Skills access profile.', (string) ($wrongStepResponse['body'] ?? ''));

        Database::execute(
            "UPDATE guided_demo_sessions SET current_step_key = 'modules_channels' WHERE id = ?",
            [$sessionId]
        );

        $activeStepResponse = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed), [
            'method' => 'GET',
            'query' => $emailModuleQuery,
            'server' => [
                'REQUEST_URI' => '/public/workspace_skills.php?' . http_build_query($emailModuleQuery),
                'SCRIPT_NAME' => '/public/workspace_skills.php',
            ],
        ]);
        $activeStepBody = (string) ($activeStepResponse['body'] ?? '');
        $this->assertSame(200, (int) ($activeStepResponse['status'] ?? 0), $activeStepBody);
        $this->assertStringNotContainsString('Access denied: Marketplace access requires the Workspace Skills access profile.', $activeStepBody);
        $this->assertStringContainsString('window.GuidedFounderDemo', $activeStepBody);
        $this->assertStringContainsString('data-guided-demo-target="module-email-whatsapp"', $activeStepBody);

        $wrongModuleQuery = ['guided_demo' => '1', 'module' => 'finance'];
        $wrongModuleResponse = $this->runWebEndpoint('public/workspace_skills.php', $this->webSession($seed), [
            'method' => 'GET',
            'query' => $wrongModuleQuery,
            'server' => [
                'REQUEST_URI' => '/public/workspace_skills.php?' . http_build_query($wrongModuleQuery),
                'SCRIPT_NAME' => '/public/workspace_skills.php',
            ],
        ]);
        $this->assertSame(403, (int) ($wrongModuleResponse['status'] ?? 0), (string) ($wrongModuleResponse['body'] ?? ''));
        $this->assertStringContainsString('Access denied: Marketplace access requires the Workspace Skills access profile.', (string) ($wrongModuleResponse['body'] ?? ''));

        $end = $this->runWebEndpoint('api/guided_demo/end.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-guided-demo',
                'reason' => 'exited',
            ],
        ]);
        $endPayload = $this->assertGuidedDemoJsonSuccess($end);
        $this->assertStringContainsString('dashboard.php?demo=exited', (string) ($endPayload['redirect_url'] ?? ''));

        $sessionAfterCleanup = Database::queryOne(
            "SELECT status, cleanup_status FROM guided_demo_sessions WHERE workspace_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1",
            [$workspaceId, $userId]
        );
        $this->assertSame('exited', (string) ($sessionAfterCleanup['status'] ?? ''));
        $this->assertSame('succeeded', (string) ($sessionAfterCleanup['cleanup_status'] ?? ''));
    }

    /**
     * @return array{workspace_id:int,user_id:int,workspace_uuid:string,workspace_slug:string,workspace_name:string,membership_id:int}
     */
    private function seedWorkspace(string $prefix): array
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Guided Demo Workspace',
            'workspace_slug' => $prefix . '-' . $suffix,
            'first_name' => 'Demo',
            'last_name' => 'Founder',
            'email' => $prefix . '.' . $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, 'owner');
        $workspace = Database::queryOne('SELECT uuid, slug, name FROM workspaces WHERE id = ?', [$workspaceId]) ?? [];
        $membership = Database::queryOne(
            'SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1',
            [$workspaceId, $userId]
        ) ?? [];

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'membership_id' => (int) ($membership['id'] ?? 0),
        ];
    }

    private function completeOnboarding(array $seed): void
    {
        (new WorkspaceOnboardingService())->completeQuickStart((int) $seed['workspace_id'], (int) $seed['user_id'], [
            'company_name' => 'Guided Demo Co',
            'company_industry' => 'Founder launch',
        ]);
    }

    private function webSession(array $seed): array
    {
        return [
            'user_id' => (int) $seed['user_id'],
            'user_uuid' => 'guided-demo-user',
            'user_email' => 'guided.demo.owner@example.test',
            'user_role' => 'admin',
            'active_workspace_id' => (int) $seed['workspace_id'],
            'active_workspace_uuid' => (string) $seed['workspace_uuid'],
            'active_workspace_slug' => (string) $seed['workspace_slug'],
            'active_workspace_name' => (string) $seed['workspace_name'],
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => (int) $seed['membership_id'],
            'csrf_token' => 'csrf-guided-demo',
            '__remember_restore_attempted' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function assertGuidedDemoJsonSuccess(array $response): array
    {
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $payload = json_decode((string) ($response['body'] ?? '{}'), true);
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['success'] ?? false), (string) ($payload['error'] ?? ''));
        $data = $payload['data'] ?? [];
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * @return array<string,mixed>
     */
    private function currentGuidedDemoState(array $seed): array
    {
        $response = $this->runWebEndpoint('api/guided_demo/state.php', $this->webSession($seed), [
            'method' => 'GET',
        ]);

        return $this->assertGuidedDemoJsonSuccess($response);
    }

    private function advanceGuidedDemo(array $seed, string $direction): array
    {
        return $this->runWebEndpoint('api/guided_demo/advance.php', $this->webSession($seed), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-guided-demo',
                'direction' => $direction,
            ],
        ]);
    }

    /**
     * @param array<string,mixed> $state
     */
    private function assertRedirectTargetsStepPage(array $state, string $expectedPage, array $expectedStep = []): void
    {
        [$page, $query] = $this->routeParts((string) ($state['redirect_url'] ?? ''));
        $this->assertSame($expectedPage, $page);
        $this->assertSame('1', (string) ($query['guided_demo'] ?? ''));
        foreach ((array) ($expectedStep['query'] ?? []) as $queryKey => $queryValue) {
            $this->assertSame((string) $queryValue, (string) ($query[$queryKey] ?? ''), 'Missing route query for ' . (string) ($expectedStep['key'] ?? $expectedPage));
        }

        if ($expectedPage === 'contact_view.php') {
            $primaryContactId = (int) ($state['session']['metadata']['primary_contact_id'] ?? 0);
            $this->assertGreaterThan(0, $primaryContactId);
            $this->assertSame($primaryContactId, (int) ($query['id'] ?? 0));
        }
        if ($expectedPage === 'email_compose.php') {
            $primaryContactId = (int) ($state['session']['metadata']['primary_contact_id'] ?? 0);
            $this->assertGreaterThan(0, $primaryContactId);
            $this->assertSame($primaryContactId, (int) ($query['contact_id'] ?? 0));
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $expectedStep
     */
    private function assertGuidedDemoPageRenders(array $seed, array $state, array $expectedStep): void
    {
        [$page, $query] = $this->routeParts((string) ($state['redirect_url'] ?? ''));
        $response = $this->runWebEndpoint('public/' . $page, $this->webSession($seed), [
            'method' => 'GET',
            'query' => $query,
            'server' => [
                'REQUEST_URI' => '/public/' . $page . ($query !== [] ? '?' . http_build_query($query) : ''),
                'SCRIPT_NAME' => '/public/' . $page,
            ],
        ]);

        $body = (string) ($response['body'] ?? '');
        $this->assertSame(
            200,
            (int) ($response['status'] ?? 0),
            sprintf(
                "Expected step %s to render %s, got status %s with headers:\n%s\n\n%s",
                (string) ($expectedStep['key'] ?? ''),
                $page,
                (string) ($response['status'] ?? ''),
                implode("\n", (array) ($response['headers'] ?? [])),
                $body
            )
        );
        $this->assertNotSame('', trim($body), 'Rendered body is blank for ' . $page);
        $this->assertStringContainsString('window.GuidedFounderDemo', $body, 'Guided demo client state missing on ' . $page);
        $this->assertStringContainsString((string) ($expectedStep['phase_label'] ?? ''), $body, 'Guided demo phase label missing on ' . $page);
        $this->assertStringContainsString((string) ($expectedStep['why'] ?? ''), $body, 'Guided demo lesson copy missing on ' . $page);
        $this->assertStringContainsString(
            $this->guidedDemoTargetNeedle((string) ($expectedStep['target'] ?? '')),
            $body,
            'Guided demo target missing on ' . $page . ' for step ' . (string) ($expectedStep['key'] ?? '')
        );
        if ((string) ($expectedStep['key'] ?? '') === 'modules_ai_coach') {
            $this->assertStringContainsString('var isGuidedDemoVisit = true;', $body);
            $this->assertStringContainsString('if (requestedLockedModuleKey && !isGuidedDemoVisit)', $body);
        }
        if ((string) ($expectedStep['key'] ?? '') === 'wrap_up') {
            $this->assertStringContainsString('Open Dashboard', $body);
            $this->assertStringNotContainsString('Clean the demo data', $body);
            $this->assertStringNotContainsString('Clean Up and Choose Package', $body);
            $this->assertStringNotContainsString('delete the demo-only records', $body);
            $this->assertStringNotContainsString('I clear demo data', $body);
        }
        $this->assertStringNotContainsString('Location: login.php', implode("\n", (array) ($response['headers'] ?? [])));
    }

    /**
     * @return array{0:string,1:array<string,mixed>}
     */
    private function routeParts(string $url): array
    {
        $parts = parse_url($url);
        $path = (string) ($parts['path'] ?? $url);
        $queryString = (string) ($parts['query'] ?? '');
        $query = [];
        parse_str($queryString, $query);

        return [basename($path), $query];
    }

    private function guidedDemoTargetNeedle(string $selector): string
    {
        if (preg_match('/data-guided-demo-target="([^"]+)"/', $selector, $matches) === 1) {
            return 'data-guided-demo-target="' . $matches[1] . '"';
        }

        return $selector;
    }

    /**
     * @param array<string,mixed> $action
     */
    private function firstChoiceKey(array $action): string
    {
        $choices = (array) ($action['choices'] ?? []);
        $firstChoice = is_array($choices[0] ?? null) ? $choices[0] : [];
        $choiceKey = (string) ($firstChoice['key'] ?? '');
        $this->assertNotSame('', $choiceKey, 'Required guided demo action should provide a choice.');

        return $choiceKey;
    }

    /**
     * @param list<string> $permissionKeys
     */
    private function revokeRolePermissions(string $roleSlug, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            Database::execute(
                "UPDATE role_permissions rp
                 JOIN roles r ON r.id = rp.role_id
                 JOIN permissions p ON p.id = rp.permission_id
                 SET rp.can_access = 0
                 WHERE r.slug = ?
                   AND p.permission_key = ?",
                [$roleSlug, $permissionKey]
            );
        }
    }
}
