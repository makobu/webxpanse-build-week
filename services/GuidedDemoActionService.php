<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Invoices;

class GuidedDemoActionService
{
    public function __construct(
        private ?GuidedDemoStepCatalog $steps = null,
        private ?GuidedDemoSessionService $sessions = null
    ) {
        $this->steps = $steps ?? new GuidedDemoStepCatalog();
        $this->sessions = $sessions ?? new GuidedDemoSessionService($this->steps);
    }

    /**
     * @return array<string,mixed>
     */
    public function perform(int $workspaceId, int $userId, string $stepKey, string $actionKey, string $choiceKey = ''): array
    {
        if (!Database::tableExists('guided_demo_action_runs')) {
            throw new \RuntimeException('Guided demo actions are not installed.');
        }

        $session = $this->sessions->activeSession($workspaceId, $userId);
        if ($session === null) {
            throw new \RuntimeException('No active guided demo session was found.');
        }

        $currentStep = (string) ($session['current_step_key'] ?? $this->steps->firstKey());
        if ($currentStep !== $stepKey) {
            throw new \RuntimeException('This demo action is only available on the current step.');
        }

        $stepAction = $this->steps->actionFor($stepKey);
        if ($stepAction === null || (string) ($stepAction['key'] ?? '') !== $actionKey) {
            throw new \RuntimeException('This step does not allow that demo action.');
        }

        $sessionId = (int) ($session['id'] ?? 0);
        $existing = Database::queryOne(
            "SELECT *
             FROM guided_demo_action_runs
             WHERE session_id = ? AND action_key = ?
             LIMIT 1",
            [$sessionId, $actionKey]
        );
        if (($existing['status'] ?? '') === 'completed') {
            return $this->responseFromCompleted($session, (array) $existing);
        }

        $selectedChoice = $this->resolveSelectedChoice($stepAction, $choiceKey);

        $runId = (int) ($session['demo_run_id'] ?? 0);
        if ($runId <= 0) {
            throw new \RuntimeException('The guided demo seed run is missing.');
        }

        $actionRunId = $this->upsertActionRun($sessionId, $workspaceId, $userId, $stepKey, $actionKey);
        if ($actionRunId <= 0) {
            throw new \RuntimeException('Could not create guided demo action run.');
        }

        try {
            $result = match ($actionKey) {
                'add_demo_contact' => $this->addDemoContact($session, $selectedChoice, $runId, $workspaceId, $userId),
                'define_offer_pricing' => $this->defineOfferPricing($session, $runId, $workspaceId, $userId),
                'preview_outreach_message' => $this->previewOutreachMessage($session, $selectedChoice),
                'simulate_outreach_sent' => $this->simulateOutreachSent($session, $runId, $workspaceId),
                'simulate_reply_received' => $this->simulateReplyReceived($session, $selectedChoice, $runId, $workspaceId),
                'create_follow_up_task' => $this->createFollowUpTask($session, $selectedChoice, $runId, $workspaceId, $userId),
                'prepare_demo_quote' => $this->prepareDemoQuote($session, $selectedChoice, $runId, $workspaceId, $userId),
                'complete_weekly_review' => $this->completeWeeklyReview($session, $runId, $workspaceId, $userId),
                default => throw new \RuntimeException('Unsupported guided demo action.'),
            };
            $resultMetadata = (array) ($result['metadata'] ?? []);
            if ($selectedChoice !== []) {
                $resultMetadata['selected_choice_key'] = (string) ($selectedChoice['key'] ?? '');
                $resultMetadata['selected_choice_label'] = (string) ($selectedChoice['label'] ?? '');
                $this->mergeSessionMetadata($session, [
                    'guided_demo_choices' => [
                        $actionKey => [
                            'key' => (string) ($selectedChoice['key'] ?? ''),
                            'label' => (string) ($selectedChoice['label'] ?? ''),
                        ],
                    ],
                ]);
            }

            Database::execute(
                "UPDATE guided_demo_action_runs
                 SET status = 'completed',
                     result_metadata_json = ?,
                     created_records_json = ?,
                     error_message = NULL,
                     completed_at = NOW(),
                     updated_at = NOW()
                WHERE id = ?",
                [
                    json_encode($resultMetadata, JSON_UNESCAPED_SLASHES),
                    json_encode((array) ($result['created_records'] ?? []), JSON_UNESCAPED_SLASHES),
                    $actionRunId,
                ]
            );
            $this->sessions->recordEvent($sessionId, $workspaceId, $userId, $stepKey, 'action_completed', $this->steps->pageFor($stepKey), [
                'action_key' => $actionKey,
                'choice_key' => (string) ($selectedChoice['key'] ?? ''),
                'message' => (string) ($result['message'] ?? ''),
            ]);

            $state = $this->sessions->state($workspaceId, $userId);
            return [
                'message' => (string) ($result['message'] ?? 'Demo action completed.'),
                'result' => $resultMetadata,
                'created_records' => (array) ($result['created_records'] ?? []),
                'state' => $state,
            ];
        } catch (\Throwable $e) {
            Database::execute(
                "UPDATE guided_demo_action_runs
                 SET status = 'failed',
                     error_message = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$e->getMessage(), $actionRunId]
            );
            $this->sessions->recordEvent($sessionId, $workspaceId, $userId, $stepKey, 'action_failed', $this->steps->pageFor($stepKey), [
                'action_key' => $actionKey,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function responseFromCompleted(array $session, array $row): array
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $userId = (int) ($session['user_id'] ?? 0);

        return [
            'message' => 'This is already set up.',
            'result' => $this->decodeAssoc($row['result_metadata_json'] ?? null),
            'created_records' => $this->decodeAssoc($row['created_records_json'] ?? null),
            'state' => $this->sessions->state($workspaceId, $userId),
        ];
    }

    /**
     * @return array{key:string,label:string}|array{}
     */
    private function resolveSelectedChoice(array $action, string $choiceKey): array
    {
        $choices = array_values(array_filter(
            (array) ($action['choices'] ?? []),
            static fn(mixed $choice): bool => is_array($choice)
                && trim((string) ($choice['key'] ?? '')) !== ''
                && trim((string) ($choice['label'] ?? '')) !== ''
        ));
        if ($choices === []) {
            return [];
        }
        $choiceKey = trim($choiceKey);
        if ($choiceKey === '') {
            throw new \RuntimeException('Choose one option before I set this up.');
        }
        foreach ($choices as $choice) {
            if ((string) ($choice['key'] ?? '') === $choiceKey) {
                return [
                    'key' => (string) ($choice['key'] ?? ''),
                    'label' => (string) ($choice['label'] ?? ''),
                ];
            }
        }

        throw new \RuntimeException('Choose one of the available options before I continue.');
    }

    private function upsertActionRun(int $sessionId, int $workspaceId, int $userId, string $stepKey, string $actionKey): int
    {
        $idempotencyKey = sha1($sessionId . ':' . $stepKey . ':' . $actionKey);
        Database::execute(
            "INSERT INTO guided_demo_action_runs
                (session_id, workspace_id, user_id, step_key, action_key, status, idempotency_key)
             VALUES (?, ?, ?, ?, ?, 'pending', ?)
             ON DUPLICATE KEY UPDATE
                step_key = VALUES(step_key),
                status = IF(status = 'completed', status, 'pending'),
                error_message = NULL,
                updated_at = NOW()",
            [$sessionId, $workspaceId, $userId, $stepKey, $actionKey, $idempotencyKey]
        );

        $row = Database::queryOne(
            "SELECT id FROM guided_demo_action_runs WHERE idempotency_key = ? LIMIT 1",
            [$idempotencyKey]
        );

        return (int) ($row['id'] ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function addDemoContact(array $session, array $selectedChoice, int $runId, int $workspaceId, int $userId): array
    {
        $choiceKey = (string) ($selectedChoice['key'] ?? 'warm_referral');
        $profiles = [
            'warm_referral' => [
                'first_name' => 'Riley',
                'last_name' => 'Referral',
                'email' => 'riley.referral@example.com',
                'company' => 'Warm Path Co',
                'lead_source' => 'referral',
                'note' => 'A safe demo contact from a warm referral.',
            ],
            'past_customer' => [
                'first_name' => 'Morgan',
                'last_name' => 'Past',
                'email' => 'morgan.past@example.com',
                'company' => 'Past Customer Studio',
                'lead_source' => 'other',
                'note' => 'A safe demo contact who already knows your work.',
            ],
            'new_lead' => [
                'first_name' => 'Taylor',
                'last_name' => 'Lead',
                'email' => 'taylor.lead@example.com',
                'company' => 'New Lead Works',
                'lead_source' => 'form',
                'note' => 'A safe demo contact from a new lead.',
            ],
        ];
        $profile = $profiles[$choiceKey] ?? $profiles['warm_referral'];

        $contactId = $this->insertRow('contacts', [
            'workspace_id' => $workspaceId,
            'uuid' => $this->uuid(),
            'first_name' => $profile['first_name'],
            'last_name' => $profile['last_name'],
            'email' => $profile['email'],
            'phone' => '+254711' . str_pad((string) ($runId % 100000), 5, '0', STR_PAD_LEFT),
            'company' => $profile['company'],
            'lead_source' => $profile['lead_source'],
            'stage' => 'new',
            'assigned_to' => $userId,
            'created_by' => $userId,
            'lead_score' => 45,
            'metadata_json' => json_encode([
                'source' => 'guided_demo_action',
                'simulation_only' => true,
                'action' => 'add_demo_contact',
                'note' => $profile['note'],
            ], JSON_UNESCAPED_SLASHES),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->registerSeed($runId, 'contacts', $contactId);

        $this->mergeSessionMetadata($session, [
            'primary_contact_id' => $contactId,
            'workday_contact_id' => $contactId,
        ]);

        return [
            'message' => 'I added the demo contact.',
            'metadata' => [
                'contact_id' => $contactId,
                'contact_name' => trim($profile['first_name'] . ' ' . $profile['last_name']),
                'simulation_only' => true,
            ],
            'created_records' => ['contacts' => [$contactId]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function defineOfferPricing(array $session, int $runId, int $workspaceId, int $userId): array
    {
        $metadata = $this->sessionMetadata($session);
        $productId = (int) ($metadata['launch_product_id'] ?? 0);
        $productPayload = [
            'workspace_id' => $workspaceId,
            'name' => '30-Day AI Cofounder Launch',
            'description' => 'A guided 30-day launch path for first offer, first outreach, follow-up, and weekly review.',
            'category' => 'Founder Launch',
            'features' => json_encode(['Offer clarity', 'First customer list', 'Outreach follow-up', 'Weekly founder review']),
            'pricing_info' => '$149-$299 one-time launch package; Sprint entry $49-$99.',
            'target_audience' => 'Side-hustlers, recent graduates, employed builders, and first-time founders.',
            'use_cases' => 'Clarify the offer, test demand, and pursue a first paid signal.',
            'benefits' => 'Clear launch plan, consistent action, safer automation, and visible buyer evidence.',
            'unit_price' => 299,
            'display_order' => 1,
            'is_active' => 1,
        ];

        $created = [];
        if ($productId > 0 && $this->rowBelongsToWorkspace('products', $productId, $workspaceId)) {
            $this->updateRow('products', $productId, $workspaceId, $productPayload);
        } else {
            $productId = $this->insertRow('products', $productPayload);
            $this->registerSeed($runId, 'products', $productId);
            $created['products'] = [$productId];
        }

        $taskId = (int) ($metadata['first_offer_task_id'] ?? 0);
        if ($taskId > 0 && $this->rowBelongsToWorkspace('tasks', $taskId, $workspaceId)) {
            $this->updateRow('tasks', $taskId, $workspaceId, [
                'status' => 'completed',
                'metadata_json' => json_encode(['guided_demo_action' => 'define_offer_pricing', 'product_id' => $productId]),
            ]);
        }

        $this->mergeSessionMetadata($session, ['launch_product_id' => $productId]);

        return [
            'message' => 'I saved your demo offer.',
            'metadata' => ['product_id' => $productId, 'task_id' => $taskId, 'offer_name' => '30-Day AI Cofounder Launch'],
            'created_records' => $created,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function previewOutreachMessage(array $session, array $selectedChoice = []): array
    {
        $metadata = $this->sessionMetadata($session);
        $contactName = $this->contactName((int) ($metadata['primary_contact_id'] ?? 0));
        $choiceKey = (string) ($selectedChoice['key'] ?? '');
        $draftSubject = match ($choiceKey) {
            'problem' => 'Quick note on the next step',
            'quick_win' => 'A quick win to review',
            'invite' => 'Could we look at this together?',
            default => 'Quick next step',
        };
        $draftBody = match ($choiceKey) {
            'problem' => "Hi {$contactName},\n\nI noticed that the next step after a first conversation can get scattered quickly, especially when there are notes, questions, and timing details living in different places.\n\nI put together a short follow-up that keeps the decision simple: what we heard, why it matters, and the one practical step that would help you decide whether this is worth exploring.\n\nWould it be helpful if I sent the short version for you to review?",
            'quick_win' => "Hi {$contactName},\n\nI found one quick win that could make the next conversation easier without adding a long thread or asking you to review a full proposal upfront.\n\nThe idea is simple: clarify the immediate outcome, give you a small next step to react to, and keep the decision lightweight so momentum does not fade.\n\nWould you like me to send the quick version and a suggested next step?",
            'invite' => "Hi {$contactName},\n\nI have a concise next step ready that turns the notes from our conversation into something easier to review.\n\nIt outlines the opportunity, the practical first move, and what we would need from you before booking anything more formal. That way, you can react to the substance before committing more time.\n\nWould you be open to taking a quick look this week?",
            default => "Hi {$contactName},\n\nI have a simple next step ready that keeps the conversation focused and easy to answer.\n\nIt summarizes the useful context, the practical next move, and the decision I would need from you before anything else happens.\n\nWould you like me to send the short version?",
        };

        return [
            'message' => 'I added the draft. Nothing was sent.',
            'metadata' => [
                'preview_channel' => 'email',
                'simulation_only' => true,
                'draft_subject' => $draftSubject,
                'draft_body' => $draftBody,
                'draft_preview' => $draftBody,
            ],
            'created_records' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function simulateOutreachSent(array $session, int $runId, int $workspaceId): array
    {
        $metadata = $this->sessionMetadata($session);
        $contactId = (int) ($metadata['primary_contact_id'] ?? 0);
        if ($contactId <= 0) {
            throw new \RuntimeException('No demo contact is available for outreach simulation.');
        }

        $communicationId = $this->insertRow('communications', [
            'uuid' => $this->uuid(),
            'workspace_id' => $workspaceId,
            'contact_id' => $contactId,
            'channel' => 'email',
            'direction' => 'outbound',
            'subject' => 'Demo: 30-Day AI Cofounder Launch outline',
            'body' => 'SIMULATED ONLY: Shared the launch offer, price range, and first-week outcome with the warm prospect.',
            'status' => 'sent',
            'metadata' => json_encode(['source' => 'guided_demo_action', 'simulation_only' => true, 'action' => 'simulate_outreach_sent']),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->registerSeed($runId, 'communications', $communicationId);
        $this->mergeSessionMetadata($session, ['simulated_outreach_communication_id' => $communicationId]);

        return [
            'message' => 'I logged the reach-out. No live message was sent.',
            'metadata' => ['communication_id' => $communicationId, 'simulation_only' => true],
            'created_records' => ['communications' => [$communicationId]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function simulateReplyReceived(array $session, array $selectedChoice, int $runId, int $workspaceId): array
    {
        $metadata = $this->sessionMetadata($session);
        $contactId = (int) ($metadata['primary_contact_id'] ?? 0);
        if ($contactId <= 0) {
            throw new \RuntimeException('No demo contact is available for reply simulation.');
        }
        $choiceKey = (string) ($selectedChoice['key'] ?? 'ask_price');
        $reply = match ($choiceKey) {
            'ask_next_step' => 'SIMULATED ONLY: This sounds useful. What should happen next?',
            'ask_short_version' => 'SIMULATED ONLY: Can you send the short version first?',
            default => 'SIMULATED ONLY: This is interesting. What would the starter version cost?',
        };

        $communicationId = $this->insertRow('communications', [
            'uuid' => $this->uuid(),
            'workspace_id' => $workspaceId,
            'contact_id' => $contactId,
            'channel' => 'email',
            'direction' => 'inbound',
            'subject' => 'Re: Quick next step',
            'body' => $reply,
            'status' => 'read',
            'metadata' => json_encode(['source' => 'guided_demo_action', 'simulation_only' => true, 'action' => 'simulate_reply_received']),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $this->registerSeed($runId, 'communications', $communicationId);
        $this->mergeSessionMetadata($session, ['simulated_reply_communication_id' => $communicationId]);

        return [
            'message' => 'I added the buyer reply.',
            'metadata' => [
                'communication_id' => $communicationId,
                'simulation_only' => true,
                'reply_preview' => $reply,
                'result_title' => 'Reply added',
                'result_body' => 'Next, you can add the follow-up task.',
            ],
            'created_records' => ['communications' => [$communicationId]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function createFollowUpTask(array $session, array $selectedChoice, int $runId, int $workspaceId, int $userId): array
    {
        $metadata = $this->sessionMetadata($session);
        $contactId = (int) ($metadata['primary_contact_id'] ?? 0);
        if ($contactId <= 0) {
            throw new \RuntimeException('No demo contact is available for follow-up.');
        }
        $choiceKey = (string) ($selectedChoice['key'] ?? 'send_details');
        $task = match ($choiceKey) {
            'book_call' => [
                'title' => 'Demo follow-up: book a call',
                'description' => 'SIMULATED ONLY: Offer two times and keep the next step simple.',
                'priority' => 'high',
            ],
            'prepare_quote' => [
                'title' => 'Demo follow-up: prepare a quote',
                'description' => 'SIMULATED ONLY: Prepare a simple quote before sending anything live.',
                'priority' => 'high',
            ],
            default => [
                'title' => 'Demo follow-up: send details',
                'description' => 'SIMULATED ONLY: Send the short details and ask for one clear answer.',
                'priority' => 'medium',
            ],
        };

        $taskId = $this->insertRow('tasks', [
            'workspace_id' => $workspaceId,
            'title' => $task['title'],
            'description' => $task['description'],
            'contact_id' => $contactId,
            'assigned_to' => $userId,
            'created_by' => $userId,
            'status' => 'pending',
            'priority' => $task['priority'],
            'due_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'metadata_json' => json_encode(['source' => 'guided_demo_action', 'simulation_only' => true]),
        ]);
        $this->registerSeed($runId, 'tasks', $taskId);
        $this->mergeSessionMetadata($session, ['simulated_follow_up_task_id' => $taskId]);

        return [
            'message' => 'I added the follow-up task.',
            'metadata' => ['task_id' => $taskId],
            'created_records' => ['tasks' => [$taskId]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function prepareDemoQuote(array $session, array $selectedChoice, int $runId, int $workspaceId, int $userId): array
    {
        $metadata = $this->sessionMetadata($session);
        $contactId = (int) ($metadata['primary_contact_id'] ?? 0);
        if ($contactId <= 0) {
            throw new \RuntimeException('No demo contact is available for the document.');
        }

        $choiceKey = (string) ($selectedChoice['key'] ?? 'starter_quote');
        $profile = match ($choiceKey) {
            'deposit_invoice' => [
                'document_type' => 'invoice',
                'title' => 'Demo deposit invoice',
                'line' => 'Demo deposit',
                'amount' => 150.00,
                'label' => 'deposit invoice',
            ],
            'simple_proposal' => [
                'document_type' => 'proforma',
                'title' => 'Demo simple proposal',
                'line' => 'Demo proposal',
                'amount' => 500.00,
                'label' => 'simple proposal',
            ],
            default => [
                'document_type' => 'quote',
                'title' => 'Demo starter quote',
                'line' => 'Demo starter quote',
                'amount' => 350.00,
                'label' => 'starter quote',
            ],
        };

        $invoiceId = (new Invoices())->create([
            'document_type' => $profile['document_type'],
            'status' => 'draft',
            'contact_id' => $contactId,
            'assigned_to' => $userId,
            'created_by' => $userId,
            'currency' => 'USD',
            'title' => $profile['title'],
            'intro_text' => 'SIMULATED ONLY: A safe demo document you can inspect.',
            'notes' => 'No real quote or invoice was sent.',
            'terms' => 'Demo only. No payment is due.',
            'tax_mode' => 'none',
            'tax_rate' => 0,
            'line_items' => [
                [
                    'description' => $profile['line'],
                    'quantity' => 1,
                    'unit_price' => $profile['amount'],
                    'pricing_context' => 'guided_demo',
                ],
            ],
        ], 'system', $userId);
        $this->registerSeed($runId, 'invoices', $invoiceId);
        $this->mergeSessionMetadata($session, ['simulated_invoice_id' => $invoiceId]);

        return [
            'message' => 'I made the demo document. Nothing was sent.',
            'metadata' => [
                'invoice_id' => $invoiceId,
                'document_type' => $profile['document_type'],
                'document_label' => $profile['label'],
                'document_title' => $profile['title'],
                'document_url' => 'invoice_view.php?id=' . $invoiceId . '&guided_demo=1',
                'result_title' => 'Document ready',
                'result_body' => 'You can see the draft in your document list. Nothing was sent.',
                'simulation_only' => true,
            ],
            'created_records' => ['invoices' => [$invoiceId]],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function completeWeeklyReview(array $session, int $runId, int $workspaceId, int $userId): array
    {
        $metadata = $this->sessionMetadata($session);
        $reviewId = (int) ($metadata['weekly_review_id'] ?? 0);
        $created = [];
        if (!Database::tableExists('founder_weekly_reviews')) {
            $reviewTaskId = (int) ($metadata['weekly_review_task_id'] ?? 0);
            if ($reviewTaskId > 0 && $this->rowBelongsToWorkspace('tasks', $reviewTaskId, $workspaceId)) {
                $this->updateRow('tasks', $reviewTaskId, $workspaceId, ['status' => 'completed']);
            }

            return [
                'message' => 'I closed the review as a demo. Review storage is not available here.',
                'metadata' => ['review_id' => 0, 'task_id' => $reviewTaskId, 'storage_available' => false],
                'created_records' => [],
            ];
        }

        if ($reviewId > 0 && $this->rowBelongsToWorkspace('founder_weekly_reviews', $reviewId, $workspaceId)) {
            $this->updateRow('founder_weekly_reviews', $reviewId, $workspaceId, [
                'wins' => 'Simulated first paid signal, offer clarity, and a follow-up task from buyer reply.',
                'blockers' => 'Credentials remain unconfigured until a paid package is selected.',
                'customer_conversations' => 6,
                'leads_created' => 8,
                'deals_opened' => 3,
                'paid_revenue' => 0,
                'next_week_focus' => 'Convert the warm sprint prospect or run a focused Launch package.',
                'review_status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'updated_by' => $userId,
            ]);
        } else {
            $reviewId = $this->insertRow('founder_weekly_reviews', [
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'week_start' => date('Y-m-d', strtotime('monday this week')),
                'week_end' => date('Y-m-d', strtotime('sunday this week')),
                'wins' => 'Simulated first paid signal, offer clarity, and a follow-up task from buyer reply.',
                'blockers' => 'Credentials remain unconfigured until a paid package is selected.',
                'customer_conversations' => 6,
                'leads_created' => 8,
                'deals_opened' => 3,
                'paid_revenue' => 0,
                'next_week_focus' => 'Convert the warm sprint prospect or run a focused Launch package.',
                'review_status' => 'completed',
                'completed_at' => date('Y-m-d H:i:s'),
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
            $this->registerSeed($runId, 'founder_weekly_reviews', $reviewId);
            $created['founder_weekly_reviews'] = [$reviewId];
        }

        $reviewTaskId = (int) ($metadata['weekly_review_task_id'] ?? 0);
        if ($reviewTaskId > 0 && $this->rowBelongsToWorkspace('tasks', $reviewTaskId, $workspaceId)) {
            $this->updateRow('tasks', $reviewTaskId, $workspaceId, ['status' => 'completed']);
        }
        $this->mergeSessionMetadata($session, ['weekly_review_id' => $reviewId]);

        return [
            'message' => 'I saved the review and next focus.',
            'metadata' => ['review_id' => $reviewId, 'task_id' => $reviewTaskId],
            'created_records' => $created,
        ];
    }

    private function rowBelongsToWorkspace(string $tableName, int $id, int $workspaceId): bool
    {
        if ($id <= 0 || !Database::tableExists($tableName)) {
            return false;
        }
        if (!Database::columnExists($tableName, 'workspace_id')) {
            return true;
        }

        $row = Database::queryOne(
            "SELECT id FROM `{$tableName}` WHERE id = ? AND workspace_id = ? LIMIT 1",
            [$id, $workspaceId]
        );

        return $row !== null;
    }

    private function updateRow(string $tableName, int $id, int $workspaceId, array $data): void
    {
        if ($id <= 0 || !Database::tableExists($tableName)) {
            return;
        }
        $columns = $this->tableColumns($tableName);
        $sets = [];
        $params = [];
        foreach ($data as $column => $value) {
            if ($column === 'id' || !isset($columns[$column])) {
                continue;
            }
            $sets[] = "`{$column}` = ?";
            $params[] = $value;
        }
        if ($sets === []) {
            return;
        }

        $where = 'id = ?';
        $params[] = $id;
        if (isset($columns['workspace_id'])) {
            $where .= ' AND workspace_id = ?';
            $params[] = $workspaceId;
        }

        Database::execute(
            "UPDATE `{$tableName}` SET " . implode(', ', $sets) . " WHERE {$where}",
            $params
        );
    }

    private function insertRow(string $tableName, array $data): int
    {
        if (!Database::tableExists($tableName)) {
            throw new \RuntimeException('Demo table is not available: ' . $tableName);
        }

        $columns = $this->tableColumns($tableName);
        $filtered = [];
        foreach ($data as $column => $value) {
            if (isset($columns[$column])) {
                $filtered[$column] = $value;
            }
        }
        if ($filtered === []) {
            throw new \RuntimeException('No valid demo columns for table: ' . $tableName);
        }

        $columnNames = array_keys($filtered);
        Database::execute(
            'INSERT INTO `' . $tableName . '` (`' . implode('`, `', $columnNames) . '`) VALUES (' . implode(', ', array_fill(0, count($columnNames), '?')) . ')',
            array_values($filtered)
        );

        return (int) Database::lastInsertId();
    }

    private function registerSeed(int $runId, string $tableName, int $recordId): void
    {
        if ($runId <= 0 || $recordId <= 0 || !Database::tableExists('demo_seed_registry')) {
            return;
        }

        Database::execute(
            'INSERT INTO demo_seed_registry (run_id, table_name, record_id) VALUES (?, ?, ?)',
            [$runId, $tableName, $recordId]
        );
    }

    /**
     * @return array<string,bool>
     */
    private function tableColumns(string $tableName): array
    {
        static $cache = [];
        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $rows = Database::query(
            'SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?',
            [$tableName]
        );
        $columns = [];
        foreach ($rows as $row) {
            $name = (string) ($row['COLUMN_NAME'] ?? '');
            if ($name !== '') {
                $columns[$name] = true;
            }
        }

        $cache[$tableName] = $columns;
        return $columns;
    }

    /**
     * @return array<string,mixed>
     */
    private function sessionMetadata(array $session): array
    {
        return $this->decodeAssoc($session['metadata_json'] ?? null);
    }

    private function mergeSessionMetadata(array $session, array $updates): void
    {
        $sessionId = (int) ($session['id'] ?? 0);
        $freshSession = $sessionId > 0
            ? (Database::queryOne('SELECT metadata_json FROM guided_demo_sessions WHERE id = ? LIMIT 1', [$sessionId]) ?? $session)
            : $session;
        $metadata = array_replace_recursive($this->decodeAssoc($freshSession['metadata_json'] ?? null), $updates);
        Database::execute(
            'UPDATE guided_demo_sessions SET metadata_json = ?, updated_at = NOW() WHERE id = ?',
            [json_encode($metadata, JSON_UNESCAPED_SLASHES), $sessionId]
        );
    }

    private function contactName(int $contactId): string
    {
        if ($contactId <= 0 || !Database::tableExists('contacts')) {
            return 'there';
        }
        $row = Database::queryOne('SELECT first_name, last_name FROM contacts WHERE id = ? LIMIT 1', [$contactId]) ?? [];
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        return $name !== '' ? $name : 'there';
    }

    private function decodeAssoc(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
