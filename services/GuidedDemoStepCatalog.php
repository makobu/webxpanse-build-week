<?php

declare(strict_types=1);

namespace CRM\Services;

class GuidedDemoStepCatalog
{
    /**
     * @return list<array<string,mixed>>
     */
    public function steps(): array
    {
        return [
            [
                'key' => 'dashboard_today',
                'phase' => 'workday',
                'phase_label' => 'Workday',
                'page' => 'dashboard.php',
                'target' => '[data-guided-demo-target="dashboard-ai-cofounder"]',
                'fallback_target' => 'main',
                'title' => 'See today\'s work',
                'body' => 'I put today\'s important work in one place.',
                'why' => 'Start here when you are not sure what to do next.',
                'action_label' => 'Continue',
            ],
            [
                'key' => 'add_demo_contact',
                'phase' => 'contacts',
                'phase_label' => 'Contacts',
                'page' => 'contacts.php',
                'target' => '[data-guided-demo-target="contacts-workday-actions"]',
                'fallback_target' => '[data-guided-demo-target="contacts-founder-list"]',
                'title' => 'Add one person',
                'body' => 'Pick a contact type. I\'ll add a demo contact.',
                'why' => 'CRM work starts with one person.',
                'action_label' => 'Continue',
                'action' => [
                    'key' => 'add_demo_contact',
                    'label' => 'Add contact',
                    'completed_label' => 'Contact added',
                    'required' => true,
                    'prompt' => 'Who should we add?',
                    'working_label' => 'Adding the contact...',
                    'choices' => [
                        ['key' => 'warm_referral', 'label' => 'Warm referral'],
                        ['key' => 'past_customer', 'label' => 'Past customer'],
                        ['key' => 'new_lead', 'label' => 'New lead'],
                    ],
                ],
            ],
            [
                'key' => 'contact_detail',
                'phase' => 'contacts',
                'phase_label' => 'Contacts',
                'page' => 'contact_view.php',
                'target' => '[data-guided-demo-target="contact-founder-context"]',
                'fallback_target' => 'main',
                'title' => 'View one person',
                'body' => 'Here are the notes, history, and next step.',
                'why' => 'You do not need to remember it all.',
                'action_label' => 'Continue',
            ],
            [
                'key' => 'preview_outreach_message',
                'phase' => 'messages',
                'phase_label' => 'Messages',
                'page' => 'email_compose.php',
                'target' => '[data-guided-demo-target="email-compose-body"]',
                'fallback_target' => '[data-guided-demo-target="email-compose-ai-panel"]',
                'title' => 'Draft a message',
                'body' => 'Pick a message type. I\'ll put a draft here.',
                'why' => 'You can read it before anything is sent.',
                'action_label' => 'Continue',
                'action' => [
                    'key' => 'preview_outreach_message',
                    'label' => 'Place draft',
                    'completed_label' => 'Draft added',
                    'required' => true,
                    'prompt' => 'What should the message say first?',
                    'working_label' => 'Adding the draft...',
                    'choices' => [
                        ['key' => 'problem', 'label' => 'Problem'],
                        ['key' => 'quick_win', 'label' => 'Quick win'],
                        ['key' => 'invite', 'label' => 'Invite'],
                    ],
                ],
            ],
            [
                'key' => 'simulate_reply_received',
                'phase' => 'inbox',
                'phase_label' => 'Inbox',
                'page' => 'inbox.php',
                'target' => '[data-guided-demo-target="inbox-founder-thread"]',
                'fallback_target' => 'main',
                'title' => 'Add a buyer reply',
                'body' => 'Pick what the buyer asked. I\'ll add a demo reply.',
                'why' => 'The reply shows what to do next.',
                'action_label' => 'Continue',
                'action' => [
                    'key' => 'simulate_reply_received',
                    'label' => 'Add reply',
                    'completed_label' => 'Reply added',
                    'required' => true,
                    'prompt' => 'What did the buyer ask?',
                    'working_label' => 'Adding the reply...',
                    'choices' => [
                        ['key' => 'ask_price', 'label' => 'Price'],
                        ['key' => 'ask_next_step', 'label' => 'Next step'],
                        ['key' => 'ask_short_version', 'label' => 'Short version'],
                    ],
                ],
            ],
            [
                'key' => 'create_follow_up_task',
                'phase' => 'tasks',
                'phase_label' => 'Tasks',
                'page' => 'dashboard.php',
                'target' => '[data-guided-demo-target="dashboard-founder-priorities"]',
                'fallback_target' => 'main',
                'title' => 'Add the next task',
                'body' => 'Pick a task. I\'ll add it to your list.',
                'why' => 'One clear task keeps things moving.',
                'action_label' => 'Continue',
                'action' => [
                    'key' => 'create_follow_up_task',
                    'label' => 'Add task',
                    'completed_label' => 'Task added',
                    'required' => true,
                    'auto_run_on_choice' => true,
                    'prompt' => 'What should you do next?',
                    'working_label' => 'Adding the task...',
                    'choices' => [
                        ['key' => 'send_details', 'label' => 'Send details'],
                        ['key' => 'book_call', 'label' => 'Book call'],
                        ['key' => 'prepare_quote', 'label' => 'Prepare quote'],
                    ],
                ],
            ],
            [
                'key' => 'prepare_demo_quote',
                'phase' => 'money',
                'phase_label' => 'Money',
                'page' => 'invoices.php',
                'target' => '[data-guided-demo-target="invoice-demo-documents"]',
                'fallback_target' => 'main',
                'title' => 'Make a quote',
                'body' => 'Pick a money step. I\'ll make a demo document.',
                'why' => 'A quote makes the next step clear.',
                'action_label' => 'Continue',
                'action' => [
                    'key' => 'prepare_demo_quote',
                    'label' => 'Make document',
                    'completed_label' => 'Document ready',
                    'required' => true,
                    'prompt' => 'What should I make?',
                    'working_label' => 'Making the document...',
                    'choices' => [
                        ['key' => 'starter_quote', 'label' => 'Starter quote'],
                        ['key' => 'deposit_invoice', 'label' => 'Deposit invoice'],
                        ['key' => 'simple_proposal', 'label' => 'Simple proposal'],
                    ],
                ],
            ],
            [
                'key' => 'dashboard_rollup',
                'phase' => 'workday',
                'phase_label' => 'Workday',
                'page' => 'dashboard.php',
                'target' => '[data-guided-demo-target="dashboard-first-paid-signal"]',
                'fallback_target' => '[data-guided-demo-target="dashboard-ai-cofounder"]',
                'title' => 'See the update',
                'body' => 'I bring the contact, reply, task, and quote back here.',
                'why' => 'This helps you choose the next move.',
                'action_label' => 'Continue',
            ],
            [
                'key' => 'modules_channels',
                'phase' => 'modules',
                'phase_label' => 'Modules',
                'page' => 'workspace_skills.php',
                'query' => ['module' => 'email'],
                'target' => '[data-guided-demo-target="module-email-whatsapp"]',
                'fallback_target' => '[data-guided-demo-target="marketplace-demo-plugins"]',
                'title' => 'See Email and WhatsApp',
                'body' => 'Use Email and WhatsApp to talk to customers.',
                'why' => 'Connect them when you are ready to send real messages.',
                'action_label' => 'Continue',
            ],
            [
                'key' => 'modules_calendar',
                'phase' => 'modules',
                'phase_label' => 'Modules',
                'page' => 'workspace_skills.php',
                'query' => ['module' => 'calendar_meetings'],
                'target' => '[data-guided-demo-target="module-calendar"]',
                'fallback_target' => '[data-guided-demo-target="marketplace-demo-plugins"]',
                'title' => 'See meetings',
                'body' => 'Calendar helps you prep, meet, and keep notes.',
                'why' => 'Meeting notes are easier when they stay with the customer.',
                'action_label' => 'Continue',
            ],
            [
                'key' => 'modules_finance',
                'phase' => 'modules',
                'phase_label' => 'Modules',
                'page' => 'workspace_skills.php',
                'query' => ['module' => 'finance'],
                'target' => '[data-guided-demo-target="module-finance"]',
                'fallback_target' => '[data-guided-demo-target="marketplace-demo-plugins"]',
                'title' => 'See money tools',
                'body' => 'Finance shows quotes and invoices in one place.',
                'why' => 'You can see money work before it becomes urgent.',
                'action_label' => 'Continue',
            ],
            [
                'key' => 'modules_ai_coach',
                'phase' => 'modules',
                'phase_label' => 'Modules',
                'page' => 'workspace_skills.php',
                'query' => ['module' => 'ai_coach'],
                'target' => '[data-guided-demo-target="module-ai-coach"]',
                'fallback_target' => '[data-guided-demo-target="marketplace-demo-plugins"]',
                'title' => 'See AI help',
                'body' => 'AI Coach helps you choose what to do next.',
                'why' => 'Use it when a page feels busy.',
                'action_label' => 'Continue',
            ],
            [
                'key' => 'weekly_review',
                'phase' => 'review',
                'phase_label' => 'Review',
                'page' => 'founder_operating_loop.php',
                'target' => '[data-guided-demo-target="founder-loop-weekly-review"]',
                'fallback_target' => 'main',
                'title' => 'Review the week',
                'body' => 'This page shows what happened and what to focus on next.',
                'why' => 'A short review keeps the plan simple.',
                'action_label' => 'Continue',
            ],
            [
                'key' => 'wrap_up',
                'phase' => 'next',
                'phase_label' => 'Next',
                'page' => 'guided_demo_wrap.php',
                'target' => '[data-guided-demo-target="guided-demo-wrap-up"]',
                'fallback_target' => 'main',
                'title' => 'Return to your dashboard',
                'body' => 'Next, I\'ll take you back to your workspace dashboard.',
                'why' => 'You leave with a clear place to start.',
                'action_label' => 'Open dashboard',
            ],
        ];
    }

    public function firstKey(): string
    {
        return (string) ($this->steps()[0]['key'] ?? 'dashboard_today');
    }

    public function step(string $key): array
    {
        foreach ($this->steps() as $step) {
            if ((string) ($step['key'] ?? '') === $key) {
                return $step;
            }
        }

        return $this->steps()[0];
    }

    public function nextKey(string $key): string
    {
        $steps = $this->steps();
        foreach ($steps as $index => $step) {
            if ((string) ($step['key'] ?? '') === $key) {
                return (string) ($steps[min($index + 1, count($steps) - 1)]['key'] ?? $key);
            }
        }

        return $this->firstKey();
    }

    public function previousKey(string $key): string
    {
        $steps = $this->steps();
        foreach ($steps as $index => $step) {
            if ((string) ($step['key'] ?? '') === $key) {
                return (string) ($steps[max($index - 1, 0)]['key'] ?? $key);
            }
        }

        return $this->firstKey();
    }

    public function isFinal(string $key): bool
    {
        $steps = $this->steps();
        return (string) ($steps[count($steps) - 1]['key'] ?? '') === $key;
    }

    public function actionFor(string $key): ?array
    {
        $action = $this->step($key)['action'] ?? null;
        return is_array($action) ? $action : null;
    }

    public function requiresAction(string $key): bool
    {
        $action = $this->actionFor($key);
        return $action !== null && !empty($action['required']);
    }

    public function routeFor(string $key, array $session = []): string
    {
        $step = $this->step($key);
        $page = (string) ($step['page'] ?? 'dashboard.php');
        $metadata = $this->decodeAssoc($session['metadata_json'] ?? null);
        $query = ['guided_demo' => '1'];
        foreach ((array) ($step['query'] ?? []) as $queryKey => $queryValue) {
            if (is_string($queryKey) && trim($queryKey) !== '') {
                $query[$queryKey] = (string) $queryValue;
            }
        }

        if ($page === 'contact_view.php' || $page === 'email_compose.php') {
            $contactId = (int) ($metadata['primary_contact_id'] ?? 0);
            if ($contactId > 0) {
                $query = $page === 'contact_view.php'
                    ? array_merge($query, ['id' => $contactId])
                    : array_merge($query, ['contact_id' => $contactId]);
            }
        }

        return $page . '?' . http_build_query($query);
    }

    public function pageFor(string $key): string
    {
        return (string) ($this->step($key)['page'] ?? 'dashboard.php');
    }

    /**
     * @param list<string> $completedActions
     * @param list<array<string,mixed>> $actionRuns
     * @return array<string,mixed>
     */
    public function clientStep(string $key, array $session = [], array $completedActions = [], array $actionRuns = []): array
    {
        $steps = $this->steps();
        $step = $this->step($key);
        $keys = array_map(static fn(array $item): string => (string) ($item['key'] ?? ''), $steps);
        $index = max(0, array_search((string) ($step['key'] ?? ''), $keys, true));
        $action = $this->shapeAction((array) ($step['action'] ?? []), $completedActions, $actionRuns);

        return [
            'key' => (string) ($step['key'] ?? $key),
            'phase' => (string) ($step['phase'] ?? ''),
            'phase_label' => (string) ($step['phase_label'] ?? ''),
            'title' => (string) ($step['title'] ?? ''),
            'body' => (string) ($step['body'] ?? ''),
            'why' => (string) ($step['why'] ?? ''),
            'target' => (string) ($step['target'] ?? 'main'),
            'fallback_target' => (string) ($step['fallback_target'] ?? 'main'),
            'action_label' => (string) ($step['action_label'] ?? 'Next'),
            'route' => $this->routeFor((string) ($step['key'] ?? $key), $session),
            'demo_action' => $action,
            'progress' => [
                'index' => $index + 1,
                'total' => count($steps),
                'is_final' => $this->isFinal((string) ($step['key'] ?? $key)),
            ],
        ];
    }

    /**
     * @param list<string> $completedActions
     * @return list<array<string,mixed>>
     */
    public function checklist(string $currentKey, array $completedActions = []): array
    {
        $items = [];
        $currentSeen = false;

        foreach ($this->steps() as $step) {
            $key = (string) ($step['key'] ?? '');
            $action = (array) ($step['action'] ?? []);
            $actionKey = (string) ($action['key'] ?? '');
            $isCurrent = $key === $currentKey;
            $actionDone = $actionKey !== '' && in_array($actionKey, $completedActions, true);
            if ($isCurrent) {
                $currentSeen = true;
            }

            $status = 'upcoming';
            if ($actionKey !== '' && $actionDone) {
                $status = 'complete';
            } elseif (!$currentSeen && !$isCurrent) {
                $status = 'complete';
            } elseif ($isCurrent) {
                $status = 'current';
            }

            $items[] = [
                'key' => $key,
                'title' => (string) ($step['title'] ?? ''),
                'phase_label' => (string) ($step['phase_label'] ?? ''),
                'has_action' => $actionKey !== '',
                'action_required' => !empty($action['required']),
                'status' => $status,
            ];
        }

        return $items;
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    public function phases(): array
    {
        $seen = [];
        foreach ($this->steps() as $step) {
            $key = (string) ($step['phase'] ?? '');
            if ($key === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = [
                'key' => $key,
                'label' => (string) ($step['phase_label'] ?? $key),
            ];
        }

        return array_values($seen);
    }

    /**
     * @param list<string> $completedActions
     * @return array<string,mixed>|null
     */
    private function shapeAction(array $action, array $completedActions, array $actionRuns): ?array
    {
        $key = (string) ($action['key'] ?? '');
        if ($key === '') {
            return null;
        }
        $completed = in_array($key, $completedActions, true);
        $selectedChoiceKey = $this->selectedChoiceKey($key, $actionRuns);

        return [
            'key' => $key,
            'label' => (string) ($action['label'] ?? 'Run demo action'),
            'completed_label' => (string) ($action['completed_label'] ?? 'Completed'),
            'required' => !empty($action['required']),
            'completed' => $completed,
            'auto_run_on_choice' => !empty($action['auto_run_on_choice']),
            'prompt' => (string) ($action['prompt'] ?? ''),
            'choices' => $this->shapeChoices((array) ($action['choices'] ?? [])),
            'selected_choice_key' => $selectedChoiceKey,
            'working_label' => (string) ($action['working_label'] ?? 'I\'m setting this up...'),
        ];
    }

    /**
     * @param list<array<string,mixed>> $choices
     * @return list<array{key:string,label:string}>
     */
    private function shapeChoices(array $choices): array
    {
        $shaped = [];
        foreach ($choices as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $key = trim((string) ($choice['key'] ?? ''));
            $label = trim((string) ($choice['label'] ?? ''));
            if ($key === '' || $label === '') {
                continue;
            }
            $shaped[] = ['key' => $key, 'label' => $label];
        }

        return $shaped;
    }

    /**
     * @param list<array<string,mixed>> $actionRuns
     */
    private function selectedChoiceKey(string $actionKey, array $actionRuns): string
    {
        foreach ($actionRuns as $run) {
            if ((string) ($run['action_key'] ?? '') !== $actionKey) {
                continue;
            }
            $result = is_array($run['result'] ?? null) ? $run['result'] : [];
            return (string) ($result['selected_choice_key'] ?? '');
        }

        return '';
    }

    private function decodeAssoc(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
