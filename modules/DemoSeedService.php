<?php

declare(strict_types=1);

namespace CRM\Modules;

use CRM\Database;

class DemoSeedService
{
    private ?string $demoCurrencyCode = null;

    public function seedEverything(int $runId, int $adminUserId, string $seedProfile = 'full', array $context = []): array
    {
        if ($seedProfile === 'interiors_contractor') {
            return $this->seedInteriorsContractorScenario($runId, $adminUserId);
        }
        if ($seedProfile === 'whatsapp_heavy_smb') {
            return $this->seedWhatsAppHeavySmbScenario($runId, $adminUserId);
        }
        if ($seedProfile === 'agencies') {
            return $this->seedAgencyScenario($runId, $adminUserId);
        }
        if ($seedProfile === 'distributors_wholesalers') {
            return $this->seedDistributorWholesalerScenario($runId, $adminUserId);
        }
        if ($seedProfile === 'solo_founder_launch') {
            return $this->seedSoloFounderLaunchScenario($runId, $adminUserId, $context);
        }

        $summary = [
            'run_id' => $runId,
            'seed_profile' => $seedProfile,
            'created' => [],
            'errors' => [],
        ];

        $ids = [
            'users' => [$adminUserId],
            'contacts' => [],
            'companies' => [],
            'deals' => [],
            'tasks' => [],
            'events' => [],
            'forms' => [],
            'ml_models' => [],
        ];

        $demoSalesUserId = $this->ensureDemoUser($runId, 'sales', 'sales', 'Demi', 'Seller', $summary);
        $demoMarketingUserId = $this->ensureDemoUser($runId, 'marketing', 'marketing', 'Mara', 'Market', $summary);
        if ($demoSalesUserId > 0) { $ids['users'][] = $demoSalesUserId; }
        if ($demoMarketingUserId > 0) { $ids['users'][] = $demoMarketingUserId; }

        $ownerUserId = $demoSalesUserId > 0 ? $demoSalesUserId : $adminUserId;

        $companyId = 0;
        try {
            if ($this->tableExists('companies')) {
                $companyId = $this->insertAndRegister('companies', [
                    'uuid' => $this->uuid(),
                    'name' => 'DemoWorks Holdings',
                    'website' => 'https://demo.example.com',
                    'phone' => '+15550001111',
                    'industry' => 'Technology',
                    'size' => '51-200',
                    'assigned_to' => $ownerUserId,
                ], $runId);
                $ids['companies'][] = $companyId;
                $summary['created']['companies'] = ($summary['created']['companies'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'companies: ' . $e->getMessage();
        }

        $leadStages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
        for ($i = 1; $i <= 12; $i++) {
            try {
                if (!$this->tableExists('contacts')) { break; }
                $contactId = $this->insertAndRegister('contacts', [
                    'uuid' => $this->uuid(),
                    'first_name' => 'Demo',
                    'last_name' => 'Contact ' . $i,
                    'email' => 'demo.contact.' . $runId . '.' . $i . '@example.test',
                    'phone' => '+1555100' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                    'company' => 'DemoWorks',
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'lead_source' => $i % 2 === 0 ? 'whatsapp' : 'form',
                    'stage' => $leadStages[$i % count($leadStages)],
                    'assigned_to' => $ownerUserId,
                    'ml_score' => (float) (30 + ($i * 5)),
                    'ml_conversion_probability' => (float) min(0.95, 0.2 + ($i * 0.05)),
                    'ml_churn_probability' => (float) max(0.01, 0.6 - ($i * 0.04)),
                    'ml_score_confidence' => 0.82,
                    'ml_score_updated_at' => $this->daysAgo($i * 2),
                    'created_at' => $this->daysAgo(90 - ($i * 6)),
                ], $runId);
                $ids['contacts'][] = $contactId;
                $summary['created']['contacts'] = ($summary['created']['contacts'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $summary['errors'][] = 'contacts #' . $i . ': ' . $e->getMessage();
            }
        }

        foreach ($ids['contacts'] as $idx => $contactId) {
            $this->seedContactJourney($runId, $adminUserId, $ownerUserId, $contactId, $idx, $ids, $summary, $companyId);
        }

        $this->seedTags($runId, $adminUserId, $ids, $summary);
        $this->seedWorkflows($runId, $ids, $summary);
        $this->seedForms($runId, $adminUserId, $ids, $summary);
        $this->seedTargets($runId, $ownerUserId, $ids, $summary);
        $this->seedNotifications($runId, $ids, $summary);
        $this->seedMl($runId, $adminUserId, $ids, $summary);
        $this->seedOutcomeAndActivation($runId, $ownerUserId, $ids, $summary);

        return $summary;
    }

    private function seedInteriorsContractorScenario(int $runId, int $adminUserId): array
    {
        $summary = [
            'run_id' => $runId,
            'seed_profile' => 'interiors_contractor',
            'created' => [],
            'errors' => [],
        ];

        $ids = [
            'users' => [$adminUserId],
            'contacts' => [],
            'companies' => [],
            'deals' => [],
            'tasks' => [],
            'events' => [],
        ];

        $salesUserId = $this->ensureDemoUser($runId, 'sitelead', 'sales', 'Nia', 'Builder', $summary);
        if ($salesUserId > 0) {
            $ids['users'][] = $salesUserId;
        }
        $ownerUserId = $salesUserId > 0 ? $salesUserId : $adminUserId;

        try {
            if ($this->tableExists('companies')) {
                $companyId = $this->insertAndRegister('companies', [
                    'uuid' => $this->uuid(),
                    'name' => 'Summit Interiors & Build',
                    'website' => 'https://summit-interiors.example.test',
                    'phone' => '+254700555001',
                    'industry' => 'Interiors / Contracting',
                    'size' => '11-50',
                    'assigned_to' => $ownerUserId,
                ], $runId);
                $ids['companies'][] = $companyId;
                $summary['created']['companies'] = ($summary['created']['companies'] ?? 0) + 1;
            } else {
                $companyId = 0;
            }
        } catch (\Throwable $e) {
            $companyId = 0;
            $summary['errors'][] = 'interiors company: ' . $e->getMessage();
        }

        $contactFixtures = [
            [
                'first_name' => 'Amina',
                'last_name' => 'Otieno',
                'email' => 'amina.'.$runId.'@example.test',
                'phone' => '+254711000101',
                'company' => 'Riverside Residence',
                'lead_source' => 'whatsapp',
                'stage' => 'proposal',
                'note' => 'Quote sent for a kitchen remodel. Client asked for revised stone finish pricing.',
            ],
            [
                'first_name' => 'Daniel',
                'last_name' => 'Mwangi',
                'email' => 'daniel.'.$runId.'@example.test',
                'phone' => '+254711000102',
                'company' => 'Greenpark Offices',
                'lead_source' => 'email',
                'stage' => 'qualified',
                'note' => 'Site visit confirmed for office fit-out measurements.',
            ],
            [
                'first_name' => 'Rose',
                'last_name' => 'Kamau',
                'email' => 'rose.'.$runId.'@example.test',
                'phone' => '+254711000103',
                'company' => 'Hillview Apartments',
                'lead_source' => 'referral',
                'stage' => 'negotiation',
                'note' => 'Client verbally approved wardrobes but procurement sign-off is pending.',
            ],
            [
                'first_name' => 'Kevin',
                'last_name' => 'Shah',
                'email' => 'kevin.'.$runId.'@example.test',
                'phone' => '+254711000104',
                'company' => 'Northgate Villas',
                'lead_source' => 'instagram',
                'stage' => 'new',
                'note' => 'Fresh inbound inquiry from Instagram asking for aluminium window pricing.',
            ],
        ];

        foreach ($contactFixtures as $fixture) {
            try {
                if (!$this->tableExists('contacts')) {
                    break;
                }
                $contactId = $this->insertAndRegister('contacts', [
                    'uuid' => $this->uuid(),
                    'first_name' => $fixture['first_name'],
                    'last_name' => $fixture['last_name'],
                    'email' => $fixture['email'],
                    'phone' => $fixture['phone'],
                    'company' => $fixture['company'],
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'lead_source' => $fixture['lead_source'],
                    'stage' => $fixture['stage'],
                    'assigned_to' => $ownerUserId,
                ], $runId);
                $ids['contacts'][] = $contactId;
                $summary['created']['contacts'] = ($summary['created']['contacts'] ?? 0) + 1;

                if ($this->tableExists('activities')) {
                    $this->insertAndRegister('activities', [
                        'contact_id' => $contactId,
                        'user_id' => $ownerUserId,
                        'activity_type' => 'note',
                        'description' => $fixture['note'],
                        'metadata' => json_encode(['source' => 'interiors_contractor_demo']),
                        'created_at' => $this->daysAgo(4),
                    ], $runId);
                    $summary['created']['activities'] = ($summary['created']['activities'] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = 'interiors contact: ' . $e->getMessage();
            }
        }

        $dealFixtures = [
            [
                'title' => 'Kitchen Remodel Quote',
                'contact_index' => 0,
                'stage' => 'proposal',
                'value' => 18500,
                'probability' => 70,
                'description' => 'Quote ready, waiting on finish confirmation and revised appliance allowance.',
            ],
            [
                'title' => 'Office Fit-Out Site Visit',
                'contact_index' => 1,
                'stage' => 'qualification',
                'value' => 32000,
                'probability' => 45,
                'description' => 'Measurements and client brief scheduled before quote issue.',
            ],
            [
                'title' => 'Wardrobe Installation Approval',
                'contact_index' => 2,
                'stage' => 'negotiation',
                'value' => 12600,
                'probability' => 82,
                'description' => 'Procurement approval is stalled after verbal go-ahead.',
            ],
        ];

        foreach ($dealFixtures as $fixture) {
            try {
                if (!$this->tableExists('deals') || empty($ids['contacts'][$fixture['contact_index']])) {
                    continue;
                }
                $dealId = $this->insertAndRegister('deals', [
                    'title' => $fixture['title'],
                    'description' => $fixture['description'],
                    'contact_id' => $ids['contacts'][$fixture['contact_index']],
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'stage' => $fixture['stage'],
                    'value' => $fixture['value'],
                    'probability' => $fixture['probability'],
                    'expected_close_date' => date('Y-m-d', strtotime('+10 days')),
                    'currency' => $this->getDemoCurrencyCode(),
                    'lead_source' => 'demo_mode',
                    'created_at' => $this->daysAgo(9),
                ], $runId);
                $ids['deals'][] = $dealId;
                $summary['created']['deals'] = ($summary['created']['deals'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $summary['errors'][] = 'interiors deals: ' . $e->getMessage();
            }
        }

        $taskFixtures = [
            [
                'title' => 'Follow up on kitchen quote revision',
                'contact_index' => 0,
                'priority' => 'urgent',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'description' => 'Client has not replied since the revised finish option was sent.',
            ],
            [
                'title' => 'Prepare site visit checklist',
                'contact_index' => 1,
                'priority' => 'high',
                'status' => 'in_progress',
                'due_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
                'description' => 'Confirm drawings, measurements, and owner attendance before the visit.',
            ],
            [
                'title' => 'Chase pending approval',
                'contact_index' => 2,
                'priority' => 'high',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('+2 days')),
                'description' => 'Procurement approval has stalled after verbal acceptance.',
            ],
        ];

        foreach ($taskFixtures as $fixture) {
            try {
                if (!$this->tableExists('tasks') || empty($ids['contacts'][$fixture['contact_index']])) {
                    continue;
                }
                $taskId = $this->insertAndRegister('tasks', [
                    'title' => $fixture['title'],
                    'description' => $fixture['description'],
                    'contact_id' => $ids['contacts'][$fixture['contact_index']],
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'status' => $fixture['status'],
                    'priority' => $fixture['priority'],
                    'due_date' => $fixture['due_date'],
                    'created_at' => $this->daysAgo(3),
                ], $runId);
                $ids['tasks'][] = $taskId;
                $summary['created']['tasks'] = ($summary['created']['tasks'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $summary['errors'][] = 'interiors tasks: ' . $e->getMessage();
            }
        }

        $this->seedInteriorsCommunications($runId, $ownerUserId, $ids, $summary);
        $this->seedInteriorsTagsAndNotifications($runId, $adminUserId, $ownerUserId, $ids, $summary);
        $this->seedOutcomeAndActivation($runId, $ownerUserId, $ids, $summary);

        return $summary;
    }

    private function seedInteriorsCommunications(int $runId, int $ownerUserId, array $ids, array &$summary): void
    {
        if (empty($ids['contacts'])) {
            return;
        }

        try {
            if ($this->tableExists('communications')) {
                $contactId = $ids['contacts'][0];
                $firstCommId = $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $contactId,
                    'channel' => 'whatsapp',
                    'direction' => 'inbound',
                    'subject' => 'Kitchen quote follow-up',
                    'body' => 'Hi, can you revise the quote with the matte stone finish before I confirm?',
                    'status' => 'read',
                    'metadata' => json_encode(['source' => 'demo_mode', 'scenario' => 'quote_revision']),
                    'created_at' => $this->daysAgo(2),
                ], $runId);
                $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $contactId,
                    'channel' => 'email',
                    'direction' => 'outbound',
                    'subject' => 'Revised kitchen quote attached',
                    'body' => 'Attached is the updated quote with the new finish and revised delivery timeline.',
                    'status' => 'sent',
                    'metadata' => json_encode(['source' => 'demo_mode', 'reply_to' => $firstCommId]),
                    'created_at' => $this->daysAgo(1),
                ], $runId);
                $summary['created']['communications'] = ($summary['created']['communications'] ?? 0) + 2;
            }

            if ($this->tableExists('conversation_threads')) {
                foreach (array_slice($ids['contacts'], 0, 3) as $index => $contactId) {
                    $this->insertAndRegister('conversation_threads', [
                        'contact_id' => $contactId,
                        'channel' => $index === 1 ? 'email' : 'whatsapp',
                        'last_message_at' => $this->daysAgo($index),
                        'message_count' => 4 + $index,
                        'is_resolved' => 0,
                        'created_at' => $this->daysAgo(7),
                    ], $runId);
                    $summary['created']['conversation_threads'] = ($summary['created']['conversation_threads'] ?? 0) + 1;
                }
            }

            if ($this->tableExists('emails')) {
                $this->insertAndRegister('emails', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $ids['contacts'][2] ?? $ids['contacts'][0],
                    'user_id' => $ownerUserId,
                    'to_email' => 'approvals.'.$runId.'@example.test',
                    'from_email' => 'sales@example.test',
                    'from_name' => 'Summit Interiors',
                    'subject' => 'Approval needed before fabrication slot expires',
                    'body' => 'We can hold the fabrication slot until Friday. Please confirm the internal approval status.',
                    'body_html' => '<p>We can hold the fabrication slot until Friday. Please confirm the internal approval status.</p>',
                    'status' => 'sent',
                    'sent_at' => $this->daysAgo(1),
                    'created_at' => $this->daysAgo(1),
                ], $runId);
                $summary['created']['emails'] = ($summary['created']['emails'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'interiors communications: ' . $e->getMessage();
        }
    }

    private function seedInteriorsTagsAndNotifications(int $runId, int $adminUserId, int $ownerUserId, array $ids, array &$summary): void
    {
        try {
            if ($this->tableExists('tags') && $this->tableExists('tag_assignments')) {
                $tagRows = [
                    ['name' => 'quote-sent', 'color' => '#f59e0b', 'description' => 'Quote has been issued and follow-up is required.'],
                    ['name' => 'approval-pending', 'color' => '#7c3aed', 'description' => 'Waiting on internal or client approval.'],
                ];
                $createdTags = [];
                foreach ($tagRows as $row) {
                    $tagId = $this->insertAndRegister('tags', [
                        'name' => $row['name'] . '-' . $runId,
                        'color' => $row['color'],
                        'description' => $row['description'],
                        'created_by' => $adminUserId,
                    ], $runId);
                    $createdTags[] = $tagId;
                    $summary['created']['tags'] = ($summary['created']['tags'] ?? 0) + 1;
                }
                if (!empty($createdTags[0]) && !empty($ids['contacts'][0])) {
                    $this->insertAndRegister('tag_assignments', [
                        'tag_id' => $createdTags[0],
                        'entity_type' => 'contact',
                        'entity_id' => $ids['contacts'][0],
                    ], $runId);
                    $summary['created']['tag_assignments'] = ($summary['created']['tag_assignments'] ?? 0) + 1;
                }
                if (!empty($createdTags[1]) && !empty($ids['contacts'][2])) {
                    $this->insertAndRegister('tag_assignments', [
                        'tag_id' => $createdTags[1],
                        'entity_type' => 'contact',
                        'entity_id' => $ids['contacts'][2],
                    ], $runId);
                    $summary['created']['tag_assignments'] = ($summary['created']['tag_assignments'] ?? 0) + 1;
                }
            }

            if ($this->tableExists('notifications')) {
                $this->insertAndRegister('notifications', [
                    'user_id' => $ownerUserId,
                    'type' => 'demo_mode',
                    'title' => 'Demo storyline ready',
                    'message' => 'Quote follow-up, site visit, and delayed approval scenarios are ready for the walkthrough.',
                    'is_read' => 0,
                ], $runId);
                $summary['created']['notifications'] = ($summary['created']['notifications'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'interiors tags: ' . $e->getMessage();
        }
    }

    private function seedSoloFounderLaunchScenario(int $runId, int $adminUserId, array $context = []): array
    {
        $workspaceId = max(0, (int) ($context['workspace_id'] ?? 0));
        $contextOwnerUserId = max(0, (int) ($context['owner_user_id'] ?? 0));
        $deferContactsUntilAction = !empty($context['defer_contacts_until_action']);
        $deferTasks = !empty($context['defer_tasks']);
        $withWorkspace = static function (array $row) use ($workspaceId): array {
            if ($workspaceId > 0) {
                $row['workspace_id'] = $workspaceId;
            }
            return $row;
        };

        $summary = [
            'run_id' => $runId,
            'seed_profile' => 'solo_founder_launch',
            'workspace_id' => $workspaceId > 0 ? $workspaceId : null,
            'defer_contacts_until_action' => $deferContactsUntilAction,
            'defer_tasks' => $deferTasks,
            'created' => [],
            'errors' => [],
        ];

        $ids = [
            'users' => [$adminUserId],
            'contacts' => [],
            'companies' => [],
            'deals' => [],
            'tasks' => [],
            'products' => [],
            'founder_first_customer_sprints' => [],
            'founder_weekly_reviews' => [],
            'founder_weekly_review_commitments' => [],
        ];

        $founderUserId = 0;
        if ($workspaceId <= 0) {
            $founderUserId = $this->ensureDemoUser($runId, 'founderlead', 'sales', 'Nia', 'Founder', $summary);
            if ($founderUserId > 0) {
                $ids['users'][] = $founderUserId;
            }
        }
        $ownerUserId = $contextOwnerUserId > 0 ? $contextOwnerUserId : ($founderUserId > 0 ? $founderUserId : $adminUserId);

        try {
            if ($this->tableExists('companies')) {
                $companyId = $this->insertAndRegister('companies', $withWorkspace([
                    'uuid' => $this->uuid(),
                    'name' => 'Bright Path Launch Studio',
                    'website' => 'https://bright-path-founder.example.test',
                    'phone' => '+254700660001',
                    'industry' => 'Founder Launch / Solo Business',
                    'size' => '1-10',
                    'assigned_to' => $ownerUserId,
                ]), $runId);
                $ids['companies'][] = $companyId;
                $summary['created']['companies'] = ($summary['created']['companies'] ?? 0) + 1;
            } else {
                $companyId = 0;
            }
        } catch (\Throwable $e) {
            $companyId = 0;
            $summary['errors'][] = 'solo founder company: ' . $e->getMessage();
        }

        $contactFixtures = [
            [
                'first_name' => 'Amina',
                'last_name' => 'Otieno',
                'email' => 'amina.founder.' . $runId . '@example.test',
                'phone' => '+254711444501',
                'company' => 'Warm Referral',
                'lead_source' => 'referral',
                'stage' => 'qualified',
                'note' => 'Warm prospect interested in a paid clarity sprint for a service business idea.',
            ],
            [
                'first_name' => 'Daniel',
                'last_name' => 'Muriuki',
                'email' => 'daniel.founder.' . $runId . '@example.test',
                'phone' => '+254711444502',
                'company' => 'LinkedIn Prospect',
                'lead_source' => 'linkedin',
                'stage' => 'new',
                'note' => 'First outreach prospect who matches the founder audience but has not replied yet.',
            ],
            [
                'first_name' => 'Grace',
                'last_name' => 'Wanjiku',
                'email' => 'grace.founder.' . $runId . '@example.test',
                'phone' => '+254711444503',
                'company' => 'Graduate Builder',
                'lead_source' => 'founder_community',
                'stage' => 'proposal',
                'note' => 'Asked for details after seeing the launch offer message in a founder community.',
            ],
            [
                'first_name' => 'Leo',
                'last_name' => 'Kimani',
                'email' => 'leo.founder.' . $runId . '@example.test',
                'phone' => '+254711444504',
                'company' => 'Side Hustle Buyer',
                'lead_source' => 'whatsapp',
                'stage' => 'negotiation',
                'note' => 'Considering the 30-day launch program after a WhatsApp conversation about pricing.',
            ],
            [
                'first_name' => 'Maya',
                'last_name' => 'Shah',
                'email' => 'maya.founder.' . $runId . '@example.test',
                'phone' => '+254711444505',
                'company' => 'Former Colleague',
                'lead_source' => 'warm_network',
                'stage' => 'qualified',
                'note' => 'Worked with the founder before and asked for a concise one-page offer.',
            ],
            [
                'first_name' => 'Brian',
                'last_name' => 'Ochieng',
                'email' => 'brian.founder.' . $runId . '@example.test',
                'phone' => '+254711444506',
                'company' => 'Small Agency Lead',
                'lead_source' => 'referral',
                'stage' => 'new',
                'note' => 'Referral lead who needs proof that the offer can create a fast operational win.',
            ],
            [
                'first_name' => 'Irene',
                'last_name' => 'Njeri',
                'email' => 'irene.founder.' . $runId . '@example.test',
                'phone' => '+254711444507',
                'company' => 'Campus Founder',
                'lead_source' => 'founder_community',
                'stage' => 'new',
                'note' => 'Recent graduate comparing a low-cost sprint with a longer launch package.',
            ],
            [
                'first_name' => 'Samuel',
                'last_name' => 'Kariuki',
                'email' => 'samuel.founder.' . $runId . '@example.test',
                'phone' => '+254711444508',
                'company' => 'WhatsApp Referral',
                'lead_source' => 'whatsapp',
                'stage' => 'new',
                'note' => 'Asked whether the founder can start with evenings and weekends only.',
            ],
        ];

        if (!$deferContactsUntilAction) {
            foreach ($contactFixtures as $fixture) {
                try {
                    if (!$this->tableExists('contacts')) {
                        break;
                    }
                    $contactId = $this->insertAndRegister('contacts', $withWorkspace([
                        'uuid' => $this->uuid(),
                        'first_name' => $fixture['first_name'],
                        'last_name' => $fixture['last_name'],
                        'email' => $fixture['email'],
                        'phone' => $fixture['phone'],
                        'company' => $fixture['company'],
                        'company_id' => $companyId > 0 ? $companyId : null,
                        'lead_source' => $fixture['lead_source'],
                        'stage' => $fixture['stage'],
                        'assigned_to' => $ownerUserId,
                    ]), $runId);
                    $ids['contacts'][] = $contactId;
                    $summary['created']['contacts'] = ($summary['created']['contacts'] ?? 0) + 1;

                    if ($this->tableExists('activities')) {
                        $this->insertAndRegister('activities', $withWorkspace([
                            'contact_id' => $contactId,
                            'user_id' => $ownerUserId,
                            'activity_type' => 'note',
                            'description' => $fixture['note'],
                            'metadata' => json_encode(['source' => 'solo_founder_launch_demo']),
                            'created_at' => $this->daysAgo(3),
                        ]), $runId);
                        $summary['created']['activities'] = ($summary['created']['activities'] ?? 0) + 1;
                    }
                } catch (\Throwable $e) {
                    $summary['errors'][] = 'solo founder contacts: ' . $e->getMessage();
                }
            }
        }

        try {
            if ($workspaceId > 0 && $this->tableExists('products')) {
                $productId = $this->insertAndRegister('products', $withWorkspace([
                    'name' => '30-Day AI Cofounder Launch',
                    'description' => 'A guided 30-day founder launch path for offer clarity, first outreach, follow-up, and weekly review.',
                    'category' => 'Founder Launch',
                    'features' => json_encode(['Offer clarity', 'Warm outreach', 'Follow-up automation', 'Weekly review']),
                    'pricing_info' => '$149-$299 one-time launch package; Sprint entry $49-$99.',
                    'target_audience' => 'Side-hustlers, recent graduates, employed builders, and first-time founders.',
                    'use_cases' => 'Turn an idea into a first offer, first customer list, and first paid signal.',
                    'benefits' => 'Clarity of action, safer automation, visible buyer evidence, and a repeatable weekly rhythm.',
                    'unit_price' => 299,
                    'display_order' => 1,
                    'is_active' => 1,
                ]), $runId);
                $ids['products'][] = $productId;
                $summary['created']['products'] = ($summary['created']['products'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'solo founder product: ' . $e->getMessage();
        }

        $dealFixtures = [
            [
                'title' => 'First Paid Sprint - Warm Prospect',
                'contact_index' => 0,
                'stage' => 'proposal',
                'value' => 99,
                'probability' => 70,
                'description' => 'Warm prospect is considering a paid Clarity Sprint after the founder audit.',
                'lead_source' => 'referral',
            ],
            [
                'title' => 'Launch Offer Follow-Up',
                'contact_index' => 3,
                'stage' => 'negotiation',
                'value' => 299,
                'probability' => 58,
                'description' => 'Prospect has the 30-day launch offer and needs a clear follow-up before deciding.',
                'lead_source' => 'whatsapp',
            ],
            [
                'title' => 'Exploratory Launch Fit - Graduate Builder',
                'contact_index' => 6,
                'stage' => 'new',
                'value' => 49,
                'probability' => 24,
                'description' => 'Early exploratory lead comparing a short Clarity Sprint with the full launch package.',
                'lead_source' => 'founder_community',
            ],
        ];

        foreach ($dealFixtures as $fixture) {
            try {
                if (!$this->tableExists('deals') || empty($ids['contacts'][$fixture['contact_index']])) {
                    continue;
                }
                $dealId = $this->insertAndRegister('deals', $withWorkspace([
                    'title' => $fixture['title'],
                    'description' => $fixture['description'],
                    'contact_id' => $ids['contacts'][$fixture['contact_index']],
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'stage' => $fixture['stage'],
                    'value' => $fixture['value'],
                    'probability' => $fixture['probability'],
                    'expected_close_date' => date('Y-m-d', strtotime('+7 days')),
                    'currency' => 'USD',
                    'lead_source' => $fixture['lead_source'],
                    'created_at' => $this->daysAgo(4),
                ]), $runId);
                $ids['deals'][] = $dealId;
                $summary['created']['deals'] = ($summary['created']['deals'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $summary['errors'][] = 'solo founder deals: ' . $e->getMessage();
            }
        }

        $taskFixtures = [
            [
                'title' => 'Finalize first offer and pricing',
                'contact_index' => 0,
                'priority' => 'urgent',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('-1 hour')),
                'description' => 'Define the Clarity Sprint outcome, price, audience, and first proof point.',
            ],
            [
                'title' => 'Send first 20 warm outreach messages',
                'contact_index' => 1,
                'priority' => 'high',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
                'description' => 'Send the launch offer to the first warm prospect list and record replies.',
            ],
            [
                'title' => 'Follow up after launch offer message',
                'contact_index' => 3,
                'priority' => 'high',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('+2 days')),
                'description' => 'Follow up with the prospect who has the 30-day launch offer.',
            ],
            [
                'title' => 'Complete first weekly review',
                'contact_index' => 2,
                'priority' => 'medium',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('+7 days')),
                'description' => 'Record customer signals, pricing evidence, follow-up outcomes, and next weekly commitments.',
            ],
            [
                'title' => 'Check plugin safety before live outreach',
                'contact_index' => 0,
                'priority' => 'medium',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('+3 days')),
                'description' => 'Confirm email and WhatsApp remain simulated until real credentials are connected.',
            ],
            [
                'title' => 'Record pricing evidence from warm prospect',
                'contact_index' => 0,
                'priority' => 'high',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('+4 days')),
                'description' => 'Capture whether the prospect prefers Sprint, Launch, or a lower-risk first step.',
            ],
            [
                'title' => 'Review inbox for buyer replies',
                'contact_index' => 3,
                'priority' => 'medium',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('+5 days')),
                'description' => 'Use the inbox to identify replies that should become follow-up tasks.',
            ],
            [
                'title' => 'Set next-week founder commitment',
                'contact_index' => 4,
                'priority' => 'medium',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('+8 days')),
                'description' => 'Choose the next weekly commitment based on outreach, reply, and pricing evidence.',
            ],
        ];

        if (!$deferTasks) {
            foreach ($taskFixtures as $fixture) {
                try {
                    if (!$this->tableExists('tasks') || empty($ids['contacts'][$fixture['contact_index']])) {
                        continue;
                    }
                    $taskId = $this->insertAndRegister('tasks', $withWorkspace([
                        'title' => $fixture['title'],
                        'description' => $fixture['description'],
                        'contact_id' => $ids['contacts'][$fixture['contact_index']],
                        'assigned_to' => $ownerUserId,
                        'created_by' => $adminUserId,
                        'status' => $fixture['status'],
                        'priority' => $fixture['priority'],
                        'due_date' => $fixture['due_date'],
                        'created_at' => $this->daysAgo(2),
                    ]), $runId);
                    $ids['tasks'][] = $taskId;
                    $summary['created']['tasks'] = ($summary['created']['tasks'] ?? 0) + 1;
                } catch (\Throwable $e) {
                    $summary['errors'][] = 'solo founder tasks: ' . $e->getMessage();
                }
            }
        }

        $this->seedSoloFounderLaunchFounderLoop($runId, $adminUserId, $ownerUserId, $ids, $summary, $workspaceId);
        $this->seedSoloFounderLaunchCommunications($runId, $ids, $summary, $workspaceId);
        $this->seedSoloFounderLaunchTagsAndNotifications($runId, $adminUserId, $ownerUserId, $ids, $summary, $workspaceId);
        $this->seedOutcomeAndActivation($runId, $ownerUserId, $ids, $summary);

        $summary['seeded_ids'] = [
            'contacts' => $ids['contacts'],
            'companies' => $ids['companies'],
            'deals' => $ids['deals'],
            'tasks' => $ids['tasks'],
            'products' => $ids['products'],
            'founder_first_customer_sprints' => $ids['founder_first_customer_sprints'],
            'founder_weekly_reviews' => $ids['founder_weekly_reviews'],
            'founder_weekly_review_commitments' => $ids['founder_weekly_review_commitments'],
        ];

        return $summary;
    }

    private function seedSoloFounderLaunchFounderLoop(int $runId, int $adminUserId, int $ownerUserId, array &$ids, array &$summary, int $workspaceId = 0): void
    {
        if ($workspaceId <= 0) {
            return;
        }
        $withWorkspace = static function (array $row) use ($workspaceId): array {
            $row['workspace_id'] = $workspaceId;
            return $row;
        };

        try {
            if ($this->tableExists('founder_first_customer_sprints')) {
                $existing = Database::queryOne(
                    'SELECT id FROM founder_first_customer_sprints WHERE workspace_id = ? AND user_id = ? LIMIT 1',
                    [$workspaceId, $ownerUserId]
                );
                if (!$existing) {
                    $sprintId = $this->insertAndRegister('founder_first_customer_sprints', $withWorkspace([
                        'user_id' => $ownerUserId,
                        'target_customer_segment' => 'Side-hustlers, graduates, employed builders, and first-time founders',
                        'offer_pitch' => '30-Day AI Cofounder Launch: clarify the offer, send warm outreach, and pursue one paid signal.',
                        'outreach_channel' => 'Warm email, WhatsApp follow-up, and founder community posts',
                        'weekly_outreach_target' => 20,
                        'demo_booking_target' => 3,
                        'paid_customer_target' => 1,
                        'status' => 'active',
                        'created_by' => $adminUserId,
                        'updated_by' => $adminUserId,
                    ]), $runId);
                    $ids['founder_first_customer_sprints'][] = $sprintId;
                    $summary['created']['founder_first_customer_sprints'] = ($summary['created']['founder_first_customer_sprints'] ?? 0) + 1;
                }
            }

            if ($this->tableExists('founder_weekly_reviews')) {
                $weekStart = date('Y-m-d', strtotime('monday this week'));
                $existing = Database::queryOne(
                    'SELECT id FROM founder_weekly_reviews WHERE workspace_id = ? AND user_id = ? AND week_start = ? LIMIT 1',
                    [$workspaceId, $ownerUserId, $weekStart]
                );
                if (!$existing) {
                    $reviewId = $this->insertAndRegister('founder_weekly_reviews', $withWorkspace([
                        'user_id' => $ownerUserId,
                        'week_start' => $weekStart,
                        'week_end' => date('Y-m-d', strtotime('sunday this week')),
                        'wins' => 'Demo seeded warm prospects and one visible paid-signal opportunity.',
                        'blockers' => 'Live credentials are intentionally blocked until payment and setup.',
                        'customer_conversations' => 4,
                        'leads_created' => 8,
                        'deals_opened' => 3,
                        'deals_won' => 0,
                        'paid_revenue' => 0,
                        'expenses' => 0,
                        'runway_months' => 3,
                        'burn_rate' => 0,
                        'break_even_deals' => 1,
                        'pricing_concern' => 'Prospects may need a low-risk Sprint before committing to the full Launch package.',
                        'next_week_focus' => 'Follow up with the warm prospect and ask for a yes/no paid sprint decision.',
                        'finance_snapshot_json' => json_encode(['source' => 'guided_demo_seed', 'simulation_only' => true]),
                        'review_status' => 'draft',
                        'created_by' => $adminUserId,
                        'updated_by' => $adminUserId,
                    ]), $runId);
                    $ids['founder_weekly_reviews'][] = $reviewId;
                    $summary['created']['founder_weekly_reviews'] = ($summary['created']['founder_weekly_reviews'] ?? 0) + 1;
                }
            }

            if ($this->tableExists('founder_weekly_review_commitments') && !empty($ids['founder_weekly_reviews'][0])) {
                $reviewId = (int) $ids['founder_weekly_reviews'][0];
                $commitments = [
                    ['task_index' => 1, 'title' => 'Send first 20 warm outreach messages'],
                    ['task_index' => 2, 'title' => 'Follow up after launch offer message'],
                    ['task_index' => 7, 'title' => 'Set next-week founder commitment'],
                ];
                foreach ($commitments as $commitment) {
                    $taskId = !empty($ids['tasks'][$commitment['task_index']]) ? (int) $ids['tasks'][$commitment['task_index']] : null;
                    $commitmentId = $this->insertAndRegister('founder_weekly_review_commitments', $withWorkspace([
                        'review_id' => $reviewId,
                        'user_id' => $ownerUserId,
                        'task_id' => $taskId,
                        'title' => $commitment['title'],
                        'description' => 'Demo commitment created for the founder launch walkthrough.',
                        'due_date' => date('Y-m-d', strtotime('+7 days')),
                        'status' => 'pending',
                    ]), $runId);
                    $ids['founder_weekly_review_commitments'][] = $commitmentId;
                    $summary['created']['founder_weekly_review_commitments'] = ($summary['created']['founder_weekly_review_commitments'] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'solo founder weekly review: ' . $e->getMessage();
        }
    }

    private function seedSoloFounderLaunchCommunications(int $runId, array $ids, array &$summary, int $workspaceId = 0): void
    {
        if (empty($ids['contacts'])) {
            return;
        }
        $withWorkspace = static function (array $row) use ($workspaceId): array {
            if ($workspaceId > 0) {
                $row['workspace_id'] = $workspaceId;
            }
            return $row;
        };

        try {
            if ($this->tableExists('communications')) {
                $warmLeadId = $ids['contacts'][0];
                $launchLeadId = $ids['contacts'][3] ?? $warmLeadId;
                $messageFixtures = [
                    [
                        'contact_id' => $warmLeadId,
                        'channel' => 'whatsapp',
                        'direction' => 'inbound',
                        'subject' => 'Sprint interest from warm referral',
                        'body' => 'SIMULATED: I like the focused clarity sprint. Can you send the price and what I get from it?',
                        'status' => 'read',
                        'scenario' => 'first_paid_sprint',
                        'days_ago' => 0,
                    ],
                    [
                        'contact_id' => $warmLeadId,
                        'channel' => 'email',
                        'direction' => 'outbound',
                        'subject' => 'Clarity Sprint outline',
                        'body' => 'SIMULATED: Here is the $49-$99 Sprint outline, week-one outcome, and the decision point.',
                        'status' => 'sent',
                        'scenario' => 'first_paid_sprint_reply',
                        'days_ago' => 0,
                    ],
                    [
                        'contact_id' => $launchLeadId,
                        'channel' => 'email',
                        'direction' => 'outbound',
                        'subject' => '30-day launch offer shared',
                        'body' => 'SIMULATED: Sharing the 30-day AI Cofounder Launch outline, price range, and first-week outcomes we discussed.',
                        'status' => 'sent',
                        'scenario' => 'launch_offer_followup',
                        'days_ago' => 1,
                    ],
                    [
                        'contact_id' => $launchLeadId,
                        'channel' => 'whatsapp',
                        'direction' => 'inbound',
                        'subject' => 'Launch follow-up question',
                        'body' => 'SIMULATED: I can start evenings and weekends. What would we finish in the first seven days?',
                        'status' => 'read',
                        'scenario' => 'launch_reply',
                        'days_ago' => 0,
                    ],
                    [
                        'contact_id' => $ids['contacts'][4] ?? $warmLeadId,
                        'channel' => 'email',
                        'direction' => 'inbound',
                        'subject' => 'One-page offer request',
                        'body' => 'SIMULATED: Send me the one-page version. I want to understand the outcome before a call.',
                        'status' => 'read',
                        'scenario' => 'offer_page_request',
                        'days_ago' => 2,
                    ],
                    [
                        'contact_id' => $ids['contacts'][6] ?? $warmLeadId,
                        'channel' => 'email',
                        'direction' => 'outbound',
                        'subject' => 'Sprint vs Launch options',
                        'body' => 'SIMULATED: The Sprint is the lower-risk first step; Launch is the guided 30-day path after the idea is ready.',
                        'status' => 'sent',
                        'scenario' => 'graduate_builder_options',
                        'days_ago' => 3,
                    ],
                ];

                foreach ($messageFixtures as $fixture) {
                    $this->insertAndRegister('communications', $withWorkspace([
                        'uuid' => $this->uuid(),
                        'contact_id' => $fixture['contact_id'],
                        'channel' => $fixture['channel'],
                        'direction' => $fixture['direction'],
                        'subject' => $fixture['subject'],
                        'body' => $fixture['body'],
                        'status' => $fixture['status'],
                        'metadata' => json_encode([
                            'source' => 'demo_mode',
                            'simulation_only' => true,
                            'scenario' => $fixture['scenario'],
                        ]),
                        'created_at' => $this->daysAgo((int) $fixture['days_ago']),
                    ]), $runId);
                }
                $summary['created']['communications'] = ($summary['created']['communications'] ?? 0) + count($messageFixtures);
            }

            if ($this->tableExists('conversation_threads')) {
                $threads = [
                    ['contact_index' => 0, 'channel' => 'whatsapp', 'message_count' => 4],
                    ['contact_index' => 3, 'channel' => 'email', 'message_count' => 2],
                ];
                foreach ($threads as $index => $thread) {
                    if (empty($ids['contacts'][$thread['contact_index']])) {
                        continue;
                    }
                    $this->insertAndRegister('conversation_threads', $withWorkspace([
                        'contact_id' => $ids['contacts'][$thread['contact_index']],
                        'channel' => $thread['channel'],
                        'last_message_at' => $this->daysAgo($index),
                        'message_count' => $thread['message_count'],
                        'is_resolved' => 0,
                        'created_at' => $this->daysAgo(4),
                    ]), $runId);
                    $summary['created']['conversation_threads'] = ($summary['created']['conversation_threads'] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'solo founder communications: ' . $e->getMessage();
        }
    }

    private function seedSoloFounderLaunchTagsAndNotifications(int $runId, int $adminUserId, int $ownerUserId, array $ids, array &$summary, int $workspaceId = 0): void
    {
        $withWorkspace = static function (array $row) use ($workspaceId): array {
            if ($workspaceId > 0) {
                $row['workspace_id'] = $workspaceId;
            }
            return $row;
        };

        try {
            if ($this->tableExists('tags') && $this->tableExists('tag_assignments')) {
                $tagRows = [
                    ['name' => 'idea-clarity', 'color' => '#2563eb', 'description' => 'Founder is still clarifying customer, problem, or offer.'],
                    ['name' => 'offer-priced', 'color' => '#22c55e', 'description' => 'First offer has a visible price or pricing path.'],
                    ['name' => 'first-outreach', 'color' => '#f59e0b', 'description' => 'First prospect list or outreach action is active.'],
                    ['name' => 'paid-signal', 'color' => '#dc2626', 'description' => 'A serious buyer conversation, quote, deposit, or paid sprint signal is pending.'],
                ];
                $createdTags = [];
                foreach ($tagRows as $row) {
                    $tagId = $this->insertAndRegister('tags', $withWorkspace([
                        'name' => $row['name'] . '-' . $runId,
                        'color' => $row['color'],
                        'description' => $row['description'],
                        'created_by' => $adminUserId,
                    ]), $runId);
                    $createdTags[$row['name']] = $tagId;
                    $summary['created']['tags'] = ($summary['created']['tags'] ?? 0) + 1;
                }

                $assignments = [
                    ['tag' => 'paid-signal', 'contact_index' => 0],
                    ['tag' => 'first-outreach', 'contact_index' => 1],
                    ['tag' => 'idea-clarity', 'contact_index' => 2],
                    ['tag' => 'offer-priced', 'contact_index' => 3],
                ];
                foreach ($assignments as $assignment) {
                    if (!empty($createdTags[$assignment['tag']]) && !empty($ids['contacts'][$assignment['contact_index']])) {
                        $this->insertAndRegister('tag_assignments', $withWorkspace([
                            'tag_id' => $createdTags[$assignment['tag']],
                            'entity_type' => 'contact',
                            'entity_id' => $ids['contacts'][$assignment['contact_index']],
                        ]), $runId);
                        $summary['created']['tag_assignments'] = ($summary['created']['tag_assignments'] ?? 0) + 1;
                    }
                }
            }

            if ($this->tableExists('notifications')) {
                $this->insertAndRegister('notifications', $withWorkspace([
                    'user_id' => $ownerUserId,
                    'type' => 'demo_mode',
                    'title' => 'Founder launch demo ready',
                    'message' => 'Offer, pricing, first outreach, follow-up, and weekly review scenarios are ready for the AI Cofounder walkthrough.',
                    'is_read' => 0,
                ]), $runId);
                $summary['created']['notifications'] = ($summary['created']['notifications'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'solo founder tags: ' . $e->getMessage();
        }
    }

    private function seedWhatsAppHeavySmbScenario(int $runId, int $adminUserId): array
    {
        $summary = [
            'run_id' => $runId,
            'seed_profile' => 'whatsapp_heavy_smb',
            'created' => [],
            'errors' => [],
        ];

        $ids = [
            'users' => [$adminUserId],
            'contacts' => [],
            'companies' => [],
            'deals' => [],
            'tasks' => [],
        ];

        $salesUserId = $this->ensureDemoUser($runId, 'chatlead', 'sales', 'Wema', 'Replies', $summary);
        if ($salesUserId > 0) {
            $ids['users'][] = $salesUserId;
        }
        $ownerUserId = $salesUserId > 0 ? $salesUserId : $adminUserId;

        try {
            if ($this->tableExists('companies')) {
                $companyId = $this->insertAndRegister('companies', [
                    'uuid' => $this->uuid(),
                    'name' => 'Pulse Commerce',
                    'website' => 'https://pulse-whatsapp.example.test',
                    'phone' => '+254700880001',
                    'industry' => 'WhatsApp-led SMB Sales',
                    'size' => '1-10',
                    'assigned_to' => $ownerUserId,
                ], $runId);
                $ids['companies'][] = $companyId;
                $summary['created']['companies'] = ($summary['created']['companies'] ?? 0) + 1;
            } else {
                $companyId = 0;
            }
        } catch (\Throwable $e) {
            $companyId = 0;
            $summary['errors'][] = 'whatsapp company: ' . $e->getMessage();
        }

        $contactFixtures = [
            [
                'first_name' => 'Jane',
                'last_name' => 'Kariuki',
                'email' => 'jane.chat.' . $runId . '@example.test',
                'phone' => '+254711111201',
                'company' => 'Retail Buyer',
                'lead_source' => 'whatsapp',
                'stage' => 'new',
                'note' => 'Fresh WhatsApp lead asking if the product is available and how fast delivery can happen.',
            ],
            [
                'first_name' => 'Brian',
                'last_name' => 'Omondi',
                'email' => 'brian.chat.' . $runId . '@example.test',
                'phone' => '+254711111202',
                'company' => 'Neighborhood Shop',
                'lead_source' => 'instagram',
                'stage' => 'qualified',
                'note' => 'Qualified chat lead who confirmed quantities and preferred payment method.',
            ],
            [
                'first_name' => 'Lydia',
                'last_name' => 'Mutheu',
                'email' => 'lydia.chat.' . $runId . '@example.test',
                'phone' => '+254711111203',
                'company' => 'Walk-in Referral',
                'lead_source' => 'referral',
                'stage' => 'proposal',
                'note' => 'Pricing was shared in chat and the customer promised to confirm later today.',
            ],
            [
                'first_name' => 'Kevin',
                'last_name' => 'Mwita',
                'email' => 'kevin.chat.' . $runId . '@example.test',
                'phone' => '+254711111204',
                'company' => 'Facebook Inquiry',
                'lead_source' => 'facebook',
                'stage' => 'negotiation',
                'note' => 'Stalled WhatsApp conversation after a discount request and no reply for 48 hours.',
            ],
        ];

        foreach ($contactFixtures as $fixture) {
            try {
                if (!$this->tableExists('contacts')) {
                    break;
                }
                $contactId = $this->insertAndRegister('contacts', [
                    'uuid' => $this->uuid(),
                    'first_name' => $fixture['first_name'],
                    'last_name' => $fixture['last_name'],
                    'email' => $fixture['email'],
                    'phone' => $fixture['phone'],
                    'company' => $fixture['company'],
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'lead_source' => $fixture['lead_source'],
                    'stage' => $fixture['stage'],
                    'assigned_to' => $ownerUserId,
                ], $runId);
                $ids['contacts'][] = $contactId;
                $summary['created']['contacts'] = ($summary['created']['contacts'] ?? 0) + 1;

                if ($this->tableExists('activities')) {
                    $this->insertAndRegister('activities', [
                        'contact_id' => $contactId,
                        'user_id' => $ownerUserId,
                        'activity_type' => 'note',
                        'description' => $fixture['note'],
                        'metadata' => json_encode(['source' => 'whatsapp_heavy_smb_demo']),
                        'created_at' => $this->daysAgo(3),
                    ], $runId);
                    $summary['created']['activities'] = ($summary['created']['activities'] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = 'whatsapp contacts: ' . $e->getMessage();
            }
        }

        $dealFixtures = [
            [
                'title' => 'Pricing Shared - Fast Close Opportunity',
                'contact_index' => 2,
                'stage' => 'proposal',
                'value' => 8500,
                'probability' => 68,
                'description' => 'Offer and pricing shared in WhatsApp, awaiting customer confirmation.',
            ],
            [
                'title' => 'Stalled WhatsApp Follow-Up',
                'contact_index' => 3,
                'stage' => 'negotiation',
                'value' => 9400,
                'probability' => 56,
                'description' => 'Lead requested a better price then stopped replying in chat.',
            ],
        ];

        foreach ($dealFixtures as $fixture) {
            try {
                if (!$this->tableExists('deals') || empty($ids['contacts'][$fixture['contact_index']])) {
                    continue;
                }
                $dealId = $this->insertAndRegister('deals', [
                    'title' => $fixture['title'],
                    'description' => $fixture['description'],
                    'contact_id' => $ids['contacts'][$fixture['contact_index']],
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'stage' => $fixture['stage'],
                    'value' => $fixture['value'],
                    'probability' => $fixture['probability'],
                    'expected_close_date' => date('Y-m-d', strtotime('+7 days')),
                    'currency' => $this->getDemoCurrencyCode(),
                    'lead_source' => 'whatsapp',
                    'created_at' => $this->daysAgo(4),
                ], $runId);
                $ids['deals'][] = $dealId;
                $summary['created']['deals'] = ($summary['created']['deals'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $summary['errors'][] = 'whatsapp deals: ' . $e->getMessage();
            }
        }

        $taskFixtures = [
            [
                'title' => 'Reply to fresh WhatsApp inquiry',
                'contact_index' => 0,
                'priority' => 'urgent',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'description' => 'Respond within SLA and capture the next step.',
            ],
            [
                'title' => 'Follow up after pricing shared',
                'contact_index' => 2,
                'priority' => 'high',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('+6 hours')),
                'description' => 'Check whether the lead has reviewed the offer sent in chat.',
            ],
            [
                'title' => 'Re-engage stalled WhatsApp chat',
                'contact_index' => 3,
                'priority' => 'high',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'description' => 'Lead went quiet after a discount request and needs a recovery message.',
            ],
        ];

        foreach ($taskFixtures as $fixture) {
            try {
                if (!$this->tableExists('tasks') || empty($ids['contacts'][$fixture['contact_index']])) {
                    continue;
                }
                $taskId = $this->insertAndRegister('tasks', [
                    'title' => $fixture['title'],
                    'description' => $fixture['description'],
                    'contact_id' => $ids['contacts'][$fixture['contact_index']],
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'status' => $fixture['status'],
                    'priority' => $fixture['priority'],
                    'due_date' => $fixture['due_date'],
                    'created_at' => $this->daysAgo(2),
                ], $runId);
                $ids['tasks'][] = $taskId;
                $summary['created']['tasks'] = ($summary['created']['tasks'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $summary['errors'][] = 'whatsapp tasks: ' . $e->getMessage();
            }
        }

        $this->seedWhatsAppHeavyCommunications($runId, $ownerUserId, $ids, $summary);
        $this->seedWhatsAppHeavyTagsAndNotifications($runId, $adminUserId, $ownerUserId, $ids, $summary);
        $this->seedOutcomeAndActivation($runId, $ownerUserId, $ids, $summary);

        return $summary;
    }

    private function seedWhatsAppHeavyCommunications(int $runId, int $ownerUserId, array $ids, array &$summary): void
    {
        if (empty($ids['contacts'])) {
            return;
        }

        try {
            if ($this->tableExists('communications')) {
                $newLeadId = $ids['contacts'][0];
                $pricingLeadId = $ids['contacts'][2] ?? $newLeadId;
                $stalledLeadId = $ids['contacts'][3] ?? $newLeadId;

                $firstCommId = $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $newLeadId,
                    'channel' => 'whatsapp',
                    'direction' => 'inbound',
                    'subject' => 'New WhatsApp inquiry',
                    'body' => 'Hi, is this still available and how much is it if I order today?',
                    'status' => 'read',
                    'metadata' => json_encode(['source' => 'demo_mode', 'scenario' => 'new_inquiry']),
                    'created_at' => $this->daysAgo(0),
                ], $runId);
                $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $pricingLeadId,
                    'channel' => 'whatsapp',
                    'direction' => 'outbound',
                    'subject' => 'Pricing shared in chat',
                    'body' => 'Here is the price list and delivery option we discussed. Let me know which one works for you.',
                    'status' => 'delivered',
                    'metadata' => json_encode(['source' => 'demo_mode', 'reply_to' => $firstCommId]),
                    'created_at' => $this->daysAgo(1),
                ], $runId);
                $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $stalledLeadId,
                    'channel' => 'whatsapp',
                    'direction' => 'inbound',
                    'subject' => 'Discount request',
                    'body' => 'Can you do better on the price if I pay today?',
                    'status' => 'read',
                    'metadata' => json_encode(['source' => 'demo_mode', 'scenario' => 'stalled_discount']),
                    'created_at' => $this->daysAgo(2),
                ], $runId);
                $summary['created']['communications'] = ($summary['created']['communications'] ?? 0) + 3;
            }

            if ($this->tableExists('conversation_threads')) {
                foreach (array_slice($ids['contacts'], 0, 4) as $index => $contactId) {
                    $this->insertAndRegister('conversation_threads', [
                        'contact_id' => $contactId,
                        'channel' => 'whatsapp',
                        'last_message_at' => $this->daysAgo($index),
                        'message_count' => 3 + $index,
                        'is_resolved' => 0,
                        'created_at' => $this->daysAgo(5),
                    ], $runId);
                    $summary['created']['conversation_threads'] = ($summary['created']['conversation_threads'] ?? 0) + 1;
                }
            }

            if ($this->tableExists('emails')) {
                $this->insertAndRegister('emails', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $ids['contacts'][2] ?? $ids['contacts'][0],
                    'user_id' => $ownerUserId,
                    'to_email' => 'pricing.followup.' . $runId . '@example.test',
                    'from_email' => 'sales@example.test',
                    'from_name' => 'Pulse Commerce',
                    'subject' => 'Pricing summary after WhatsApp chat',
                    'body' => 'Sharing the same pricing details by email so the customer can review them later.',
                    'body_html' => '<p>Sharing the same pricing details by email so the customer can review them later.</p>',
                    'status' => 'sent',
                    'sent_at' => $this->daysAgo(1),
                    'created_at' => $this->daysAgo(1),
                ], $runId);
                $summary['created']['emails'] = ($summary['created']['emails'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'whatsapp communications: ' . $e->getMessage();
        }
    }

    private function seedWhatsAppHeavyTagsAndNotifications(int $runId, int $adminUserId, int $ownerUserId, array $ids, array &$summary): void
    {
        try {
            if ($this->tableExists('tags') && $this->tableExists('tag_assignments')) {
                $tagRows = [
                    ['name' => 'whatsapp-new', 'color' => '#22c55e', 'description' => 'Fresh WhatsApp inquiry awaiting first reply.'],
                    ['name' => 'pricing-shared', 'color' => '#f59e0b', 'description' => 'Pricing has been sent in chat and follow-up is next.'],
                    ['name' => 'reply-overdue', 'color' => '#dc2626', 'description' => 'Reply SLA or follow-up window has been missed.'],
                    ['name' => 'high-intent-chat', 'color' => '#2563eb', 'description' => 'Lead is showing strong buying intent.'],
                ];
                $createdTags = [];
                foreach ($tagRows as $row) {
                    $tagId = $this->insertAndRegister('tags', [
                        'name' => $row['name'] . '-' . $runId,
                        'color' => $row['color'],
                        'description' => $row['description'],
                        'created_by' => $adminUserId,
                    ], $runId);
                    $createdTags[$row['name']] = $tagId;
                    $summary['created']['tags'] = ($summary['created']['tags'] ?? 0) + 1;
                }

                $assignments = [
                    ['tag' => 'whatsapp-new', 'contact_index' => 0],
                    ['tag' => 'pricing-shared', 'contact_index' => 2],
                    ['tag' => 'reply-overdue', 'contact_index' => 3],
                    ['tag' => 'high-intent-chat', 'contact_index' => 2],
                ];
                foreach ($assignments as $assignment) {
                    if (!empty($createdTags[$assignment['tag']]) && !empty($ids['contacts'][$assignment['contact_index']])) {
                        $this->insertAndRegister('tag_assignments', [
                            'tag_id' => $createdTags[$assignment['tag']],
                            'entity_type' => 'contact',
                            'entity_id' => $ids['contacts'][$assignment['contact_index']],
                        ], $runId);
                        $summary['created']['tag_assignments'] = ($summary['created']['tag_assignments'] ?? 0) + 1;
                    }
                }
            }

            if ($this->tableExists('notifications')) {
                $this->insertAndRegister('notifications', [
                    'user_id' => $ownerUserId,
                    'type' => 'demo_mode',
                    'title' => 'WhatsApp lead-follow-up demo ready',
                    'message' => 'Fresh inquiry, pricing-shared lead, and stalled WhatsApp follow-up scenarios are ready for the walkthrough.',
                    'is_read' => 0,
                ], $runId);
                $summary['created']['notifications'] = ($summary['created']['notifications'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'whatsapp tags: ' . $e->getMessage();
        }
    }

    private function seedAgencyScenario(int $runId, int $adminUserId): array
    {
        $summary = [
            'run_id' => $runId,
            'seed_profile' => 'agencies',
            'created' => [],
            'errors' => [],
        ];

        $ids = [
            'users' => [$adminUserId],
            'contacts' => [],
            'companies' => [],
            'deals' => [],
            'tasks' => [],
        ];

        $salesUserId = $this->ensureDemoUser($runId, 'agencylead', 'sales', 'Asha', 'Strategist', $summary);
        if ($salesUserId > 0) {
            $ids['users'][] = $salesUserId;
        }
        $ownerUserId = $salesUserId > 0 ? $salesUserId : $adminUserId;

        try {
            if ($this->tableExists('companies')) {
                $companyId = $this->insertAndRegister('companies', [
                    'uuid' => $this->uuid(),
                    'name' => 'Northstar Creative',
                    'website' => 'https://northstar-agency.example.test',
                    'phone' => '+254700990001',
                    'industry' => 'Agency Services',
                    'size' => '11-50',
                    'assigned_to' => $ownerUserId,
                ], $runId);
                $ids['companies'][] = $companyId;
                $summary['created']['companies'] = ($summary['created']['companies'] ?? 0) + 1;
            } else {
                $companyId = 0;
            }
        } catch (\Throwable $e) {
            $companyId = 0;
            $summary['errors'][] = 'agency company: ' . $e->getMessage();
        }

        $contactFixtures = [
            [
                'first_name' => 'Maya',
                'last_name' => 'Njeri',
                'email' => 'maya.agency.' . $runId . '@example.test',
                'phone' => '+254711222301',
                'company' => 'Bloom Wellness',
                'lead_source' => 'website',
                'stage' => 'prospecting',
                'note' => 'Fresh inbound inquiry asking for a proposal for a website refresh and launch campaign.',
            ],
            [
                'first_name' => 'Caleb',
                'last_name' => 'Otis',
                'email' => 'caleb.agency.' . $runId . '@example.test',
                'phone' => '+254711222302',
                'company' => 'Summit Logistics',
                'lead_source' => 'linkedin',
                'stage' => 'qualification',
                'note' => 'Discovery completed. Budget and approval chain confirmed before proposal.',
            ],
            [
                'first_name' => 'Tracy',
                'last_name' => 'Kamau',
                'email' => 'tracy.agency.' . $runId . '@example.test',
                'phone' => '+254711222303',
                'company' => 'Cedar Homes',
                'lead_source' => 'referral',
                'stage' => 'proposal',
                'note' => 'Proposal and scope shared. Client asked for two days to review internally.',
            ],
            [
                'first_name' => 'David',
                'last_name' => 'Maina',
                'email' => 'david.agency.' . $runId . '@example.test',
                'phone' => '+254711222304',
                'company' => 'Orbit Retail',
                'lead_source' => 'email',
                'stage' => 'negotiation',
                'note' => 'Client verbally approved the retainer, but kickoff date and final sign-off are still pending.',
            ],
        ];

        foreach ($contactFixtures as $fixture) {
            try {
                if (!$this->tableExists('contacts')) {
                    break;
                }
                $contactId = $this->insertAndRegister('contacts', [
                    'uuid' => $this->uuid(),
                    'first_name' => $fixture['first_name'],
                    'last_name' => $fixture['last_name'],
                    'email' => $fixture['email'],
                    'phone' => $fixture['phone'],
                    'company' => $fixture['company'],
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'lead_source' => $fixture['lead_source'],
                    'stage' => $fixture['stage'],
                    'assigned_to' => $ownerUserId,
                ], $runId);
                $ids['contacts'][] = $contactId;
                $summary['created']['contacts'] = ($summary['created']['contacts'] ?? 0) + 1;

                if ($this->tableExists('activities')) {
                    $this->insertAndRegister('activities', [
                        'contact_id' => $contactId,
                        'user_id' => $ownerUserId,
                        'activity_type' => 'note',
                        'description' => $fixture['note'],
                        'metadata' => json_encode(['source' => 'agency_demo']),
                        'created_at' => $this->daysAgo(3),
                    ], $runId);
                    $summary['created']['activities'] = ($summary['created']['activities'] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = 'agency contacts: ' . $e->getMessage();
            }
        }

        $dealFixtures = [
            [
                'title' => 'Website Refresh Proposal',
                'contact_index' => 2,
                'stage' => 'proposal',
                'value' => 4200,
                'probability' => 68,
                'description' => 'Proposal issued for website refresh and launch support.',
            ],
            [
                'title' => 'Retainer Approval and Kickoff',
                'contact_index' => 3,
                'stage' => 'negotiation',
                'value' => 6800,
                'probability' => 82,
                'description' => 'Retainer verbally accepted, but kickoff ownership and final approval are still pending.',
            ],
        ];

        foreach ($dealFixtures as $fixture) {
            try {
                if (!$this->tableExists('deals') || empty($ids['contacts'][$fixture['contact_index']])) {
                    continue;
                }
                $dealId = $this->insertAndRegister('deals', [
                    'title' => $fixture['title'],
                    'description' => $fixture['description'],
                    'contact_id' => $ids['contacts'][$fixture['contact_index']],
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'stage' => $fixture['stage'],
                    'value' => $fixture['value'],
                    'probability' => $fixture['probability'],
                    'expected_close_date' => date('Y-m-d', strtotime('+9 days')),
                    'currency' => $this->getDemoCurrencyCode(),
                    'lead_source' => 'demo_mode',
                    'created_at' => $this->daysAgo(6),
                ], $runId);
                $ids['deals'][] = $dealId;
                $summary['created']['deals'] = ($summary['created']['deals'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $summary['errors'][] = 'agency deals: ' . $e->getMessage();
            }
        }

        $taskFixtures = [
            [
                'title' => 'Reply to new inbound agency inquiry',
                'contact_index' => 0,
                'priority' => 'high',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'description' => 'Acknowledge the brief and lock in the discovery call owner.',
            ],
            [
                'title' => 'Follow up on proposal review',
                'contact_index' => 2,
                'priority' => 'urgent',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'description' => 'Client review window has passed and approval is still pending.',
            ],
            [
                'title' => 'Confirm kickoff handoff',
                'contact_index' => 3,
                'priority' => 'high',
                'status' => 'in_progress',
                'due_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
                'description' => 'Finalize kickoff owner, date, and first deliverables after verbal approval.',
            ],
        ];

        foreach ($taskFixtures as $fixture) {
            try {
                if (!$this->tableExists('tasks') || empty($ids['contacts'][$fixture['contact_index']])) {
                    continue;
                }
                $taskId = $this->insertAndRegister('tasks', [
                    'title' => $fixture['title'],
                    'description' => $fixture['description'],
                    'contact_id' => $ids['contacts'][$fixture['contact_index']],
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'status' => $fixture['status'],
                    'priority' => $fixture['priority'],
                    'due_date' => $fixture['due_date'],
                    'created_at' => $this->daysAgo(2),
                ], $runId);
                $ids['tasks'][] = $taskId;
                $summary['created']['tasks'] = ($summary['created']['tasks'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $summary['errors'][] = 'agency tasks: ' . $e->getMessage();
            }
        }

        $this->seedAgencyCommunications($runId, $ownerUserId, $ids, $summary);
        $this->seedAgencyTagsAndNotifications($runId, $adminUserId, $ownerUserId, $ids, $summary);
        $this->seedOutcomeAndActivation($runId, $ownerUserId, $ids, $summary);

        return $summary;
    }

    private function seedAgencyCommunications(int $runId, int $ownerUserId, array $ids, array &$summary): void
    {
        if (empty($ids['contacts'])) {
            return;
        }

        try {
            if ($this->tableExists('communications')) {
                $newLeadId = $ids['contacts'][0];
                $proposalLeadId = $ids['contacts'][2] ?? $newLeadId;
                $kickoffLeadId = $ids['contacts'][3] ?? $newLeadId;

                $firstCommId = $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $newLeadId,
                    'channel' => 'email',
                    'direction' => 'inbound',
                    'subject' => 'New agency inquiry',
                    'body' => 'Hi, we need a proposal for a new website and launch support. Are you taking new projects this month?',
                    'status' => 'read',
                    'metadata' => json_encode(['source' => 'demo_mode', 'scenario' => 'new_agency_inquiry']),
                    'created_at' => $this->daysAgo(0),
                ], $runId);
                $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $proposalLeadId,
                    'channel' => 'email',
                    'direction' => 'outbound',
                    'subject' => 'Proposal and scope shared',
                    'body' => 'Sharing the proposal, scope, and timeline we discussed on the discovery call.',
                    'status' => 'sent',
                    'metadata' => json_encode(['source' => 'demo_mode', 'reply_to' => $firstCommId]),
                    'created_at' => $this->daysAgo(1),
                ], $runId);
                $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $kickoffLeadId,
                    'channel' => 'whatsapp',
                    'direction' => 'inbound',
                    'subject' => 'Kickoff timing question',
                    'body' => 'We are aligned internally. Can you hold Tuesday for kickoff while we finish the paperwork?',
                    'status' => 'read',
                    'metadata' => json_encode(['source' => 'demo_mode', 'scenario' => 'kickoff_pending']),
                    'created_at' => $this->daysAgo(2),
                ], $runId);
                $summary['created']['communications'] = ($summary['created']['communications'] ?? 0) + 3;
            }

            if ($this->tableExists('conversation_threads')) {
                $channels = ['email', 'email', 'email', 'whatsapp'];
                foreach (array_slice($ids['contacts'], 0, 4) as $index => $contactId) {
                    $this->insertAndRegister('conversation_threads', [
                        'contact_id' => $contactId,
                        'channel' => $channels[$index] ?? 'email',
                        'last_message_at' => $this->daysAgo($index),
                        'message_count' => 4 + $index,
                        'is_resolved' => 0,
                        'created_at' => $this->daysAgo(6),
                    ], $runId);
                    $summary['created']['conversation_threads'] = ($summary['created']['conversation_threads'] ?? 0) + 1;
                }
            }

            if ($this->tableExists('emails')) {
                $this->insertAndRegister('emails', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $ids['contacts'][2] ?? $ids['contacts'][0],
                    'user_id' => $ownerUserId,
                    'to_email' => 'proposal.review.' . $runId . '@example.test',
                    'from_email' => 'hello@northstar.example.test',
                    'from_name' => 'Northstar Creative',
                    'subject' => 'Proposal recap and next steps',
                    'body' => 'Recapping the proposal, approval blockers, and the earliest available kickoff window.',
                    'body_html' => '<p>Recapping the proposal, approval blockers, and the earliest available kickoff window.</p>',
                    'status' => 'sent',
                    'sent_at' => $this->daysAgo(1),
                    'created_at' => $this->daysAgo(1),
                ], $runId);
                $summary['created']['emails'] = ($summary['created']['emails'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'agency communications: ' . $e->getMessage();
        }
    }

    private function seedAgencyTagsAndNotifications(int $runId, int $adminUserId, int $ownerUserId, array $ids, array &$summary): void
    {
        try {
            if ($this->tableExists('tags') && $this->tableExists('tag_assignments')) {
                $tagRows = [
                    ['name' => 'new-brief', 'color' => '#2563eb', 'description' => 'Fresh agency inquiry or brief awaiting first response.'],
                    ['name' => 'proposal-sent', 'color' => '#f59e0b', 'description' => 'Proposal is out and client follow-up is due.'],
                    ['name' => 'approval-pending', 'color' => '#7c3aed', 'description' => 'Approval or procurement sign-off is still pending.'],
                    ['name' => 'kickoff-pending', 'color' => '#dc2626', 'description' => 'Client said yes but kickoff is not fully locked in.'],
                ];
                $createdTags = [];
                foreach ($tagRows as $row) {
                    $tagId = $this->insertAndRegister('tags', [
                        'name' => $row['name'] . '-' . $runId,
                        'color' => $row['color'],
                        'description' => $row['description'],
                        'created_by' => $adminUserId,
                    ], $runId);
                    $createdTags[$row['name']] = $tagId;
                    $summary['created']['tags'] = ($summary['created']['tags'] ?? 0) + 1;
                }

                $assignments = [
                    ['tag' => 'new-brief', 'contact_index' => 0],
                    ['tag' => 'proposal-sent', 'contact_index' => 2],
                    ['tag' => 'approval-pending', 'contact_index' => 2],
                    ['tag' => 'kickoff-pending', 'contact_index' => 3],
                ];
                foreach ($assignments as $assignment) {
                    if (!empty($createdTags[$assignment['tag']]) && !empty($ids['contacts'][$assignment['contact_index']])) {
                        $this->insertAndRegister('tag_assignments', [
                            'tag_id' => $createdTags[$assignment['tag']],
                            'entity_type' => 'contact',
                            'entity_id' => $ids['contacts'][$assignment['contact_index']],
                        ], $runId);
                        $summary['created']['tag_assignments'] = ($summary['created']['tag_assignments'] ?? 0) + 1;
                    }
                }
            }

            if ($this->tableExists('notifications')) {
                $this->insertAndRegister('notifications', [
                    'user_id' => $ownerUserId,
                    'type' => 'demo_mode',
                    'title' => 'Agency proposal and kickoff demo ready',
                    'message' => 'Discovery, proposal follow-up, stalled approval, and kickoff handoff scenarios are ready for the walkthrough.',
                    'is_read' => 0,
                ], $runId);
                $summary['created']['notifications'] = ($summary['created']['notifications'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'agency tags: ' . $e->getMessage();
        }
    }

    private function seedDistributorWholesalerScenario(int $runId, int $adminUserId): array
    {
        $summary = [
            'run_id' => $runId,
            'seed_profile' => 'distributors_wholesalers',
            'created' => [],
            'errors' => [],
        ];

        $ids = [
            'users' => [$adminUserId],
            'contacts' => [],
            'companies' => [],
            'deals' => [],
            'tasks' => [],
        ];

        $salesUserId = $this->ensureDemoUser($runId, 'distributorlead', 'sales', 'Joel', 'Supply', $summary);
        if ($salesUserId > 0) {
            $ids['users'][] = $salesUserId;
        }
        $ownerUserId = $salesUserId > 0 ? $salesUserId : $adminUserId;

        try {
            if ($this->tableExists('companies')) {
                $companyId = $this->insertAndRegister('companies', [
                    'uuid' => $this->uuid(),
                    'name' => 'Eastline Distribution',
                    'website' => 'https://eastline-distribution.example.test',
                    'phone' => '+254700770001',
                    'industry' => 'Distribution / Wholesale',
                    'size' => '11-50',
                    'assigned_to' => $ownerUserId,
                ], $runId);
                $ids['companies'][] = $companyId;
                $summary['created']['companies'] = ($summary['created']['companies'] ?? 0) + 1;
            } else {
                $companyId = 0;
            }
        } catch (\Throwable $e) {
            $companyId = 0;
            $summary['errors'][] = 'distribution company: ' . $e->getMessage();
        }

        $contactFixtures = [
            [
                'first_name' => 'Peter',
                'last_name' => 'Mutiso',
                'email' => 'peter.wholesale.' . $runId . '@example.test',
                'phone' => '+254711333401',
                'company' => 'Kisumu Hardware',
                'lead_source' => 'whatsapp',
                'stage' => 'prospecting',
                'note' => 'Fresh stock inquiry asking whether cement boards are available in bulk this week.',
            ],
            [
                'first_name' => 'Naomi',
                'last_name' => 'Wairimu',
                'email' => 'naomi.wholesale.' . $runId . '@example.test',
                'phone' => '+254711333402',
                'company' => 'Metro Electricals',
                'lead_source' => 'email',
                'stage' => 'qualification',
                'note' => 'Buyer confirmed quantities, delivery county, and preferred payment terms.',
            ],
            [
                'first_name' => 'Brian',
                'last_name' => 'Ogola',
                'email' => 'brian.wholesale.' . $runId . '@example.test',
                'phone' => '+254711333403',
                'company' => 'Westside Traders',
                'lead_source' => 'phone',
                'stage' => 'proposal',
                'note' => 'Price list was shared for a repeat monthly order and buyer promised to confirm by afternoon.',
            ],
            [
                'first_name' => 'Lucy',
                'last_name' => 'Achieng',
                'email' => 'lucy.wholesale.' . $runId . '@example.test',
                'phone' => '+254711333404',
                'company' => 'County Retail Stores',
                'lead_source' => 'referral',
                'stage' => 'negotiation',
                'note' => 'Repeat buyer has gone quiet after asking for revised delivery dates on a reorder.',
            ],
        ];

        foreach ($contactFixtures as $fixture) {
            try {
                if (!$this->tableExists('contacts')) {
                    break;
                }
                $contactId = $this->insertAndRegister('contacts', [
                    'uuid' => $this->uuid(),
                    'first_name' => $fixture['first_name'],
                    'last_name' => $fixture['last_name'],
                    'email' => $fixture['email'],
                    'phone' => $fixture['phone'],
                    'company' => $fixture['company'],
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'lead_source' => $fixture['lead_source'],
                    'stage' => $fixture['stage'],
                    'assigned_to' => $ownerUserId,
                ], $runId);
                $ids['contacts'][] = $contactId;
                $summary['created']['contacts'] = ($summary['created']['contacts'] ?? 0) + 1;

                if ($this->tableExists('activities')) {
                    $this->insertAndRegister('activities', [
                        'contact_id' => $contactId,
                        'user_id' => $ownerUserId,
                        'activity_type' => 'note',
                        'description' => $fixture['note'],
                        'metadata' => json_encode(['source' => 'distributors_wholesalers_demo']),
                        'created_at' => $this->daysAgo(3),
                    ], $runId);
                    $summary['created']['activities'] = ($summary['created']['activities'] ?? 0) + 1;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = 'distribution contacts: ' . $e->getMessage();
            }
        }

        $dealFixtures = [
            [
                'title' => 'Bulk Price List Follow-Up',
                'contact_index' => 2,
                'stage' => 'proposal',
                'value' => 9500,
                'probability' => 72,
                'description' => 'Buyer has the price list and is deciding whether to confirm this week’s bulk order.',
            ],
            [
                'title' => 'Repeat Buyer Reorder Confirmation',
                'contact_index' => 3,
                'stage' => 'negotiation',
                'value' => 14800,
                'probability' => 79,
                'description' => 'Repeat buyer asked for revised delivery timing and has not confirmed the reorder yet.',
            ],
        ];

        foreach ($dealFixtures as $fixture) {
            try {
                if (!$this->tableExists('deals') || empty($ids['contacts'][$fixture['contact_index']])) {
                    continue;
                }
                $dealId = $this->insertAndRegister('deals', [
                    'title' => $fixture['title'],
                    'description' => $fixture['description'],
                    'contact_id' => $ids['contacts'][$fixture['contact_index']],
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'stage' => $fixture['stage'],
                    'value' => $fixture['value'],
                    'probability' => $fixture['probability'],
                    'expected_close_date' => date('Y-m-d', strtotime('+7 days')),
                    'currency' => $this->getDemoCurrencyCode(),
                    'lead_source' => 'demo_mode',
                    'created_at' => $this->daysAgo(5),
                ], $runId);
                $ids['deals'][] = $dealId;
                $summary['created']['deals'] = ($summary['created']['deals'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $summary['errors'][] = 'distribution deals: ' . $e->getMessage();
            }
        }

        $taskFixtures = [
            [
                'title' => 'Reply to stock availability request',
                'contact_index' => 0,
                'priority' => 'high',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('-2 hours')),
                'description' => 'Buyer is waiting for a stock and quantity confirmation.',
            ],
            [
                'title' => 'Follow up after price list was shared',
                'contact_index' => 2,
                'priority' => 'urgent',
                'status' => 'pending',
                'due_date' => date('Y-m-d H:i:s', strtotime('-1 day')),
                'description' => 'Price list is out and the buyer has not confirmed order quantities yet.',
            ],
            [
                'title' => 'Re-engage repeat buyer before reorder slips',
                'contact_index' => 3,
                'priority' => 'high',
                'status' => 'in_progress',
                'due_date' => date('Y-m-d H:i:s', strtotime('+1 day')),
                'description' => 'Repeat buyer has gone quiet after discussing delivery timing for the next order.',
            ],
        ];

        foreach ($taskFixtures as $fixture) {
            try {
                if (!$this->tableExists('tasks') || empty($ids['contacts'][$fixture['contact_index']])) {
                    continue;
                }
                $taskId = $this->insertAndRegister('tasks', [
                    'title' => $fixture['title'],
                    'description' => $fixture['description'],
                    'contact_id' => $ids['contacts'][$fixture['contact_index']],
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'status' => $fixture['status'],
                    'priority' => $fixture['priority'],
                    'due_date' => $fixture['due_date'],
                    'created_at' => $this->daysAgo(2),
                ], $runId);
                $ids['tasks'][] = $taskId;
                $summary['created']['tasks'] = ($summary['created']['tasks'] ?? 0) + 1;
            } catch (\Throwable $e) {
                $summary['errors'][] = 'distribution tasks: ' . $e->getMessage();
            }
        }

        $this->seedDistributorWholesalerCommunications($runId, $ownerUserId, $ids, $summary);
        $this->seedDistributorWholesalerTagsAndNotifications($runId, $adminUserId, $ownerUserId, $ids, $summary);
        $this->seedOutcomeAndActivation($runId, $ownerUserId, $ids, $summary);

        return $summary;
    }

    private function seedDistributorWholesalerCommunications(int $runId, int $ownerUserId, array $ids, array &$summary): void
    {
        if (empty($ids['contacts'])) {
            return;
        }

        try {
            if ($this->tableExists('communications')) {
                $newLeadId = $ids['contacts'][0];
                $pricingLeadId = $ids['contacts'][2] ?? $newLeadId;
                $reorderLeadId = $ids['contacts'][3] ?? $newLeadId;

                $firstCommId = $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $newLeadId,
                    'channel' => 'whatsapp',
                    'direction' => 'inbound',
                    'subject' => 'Bulk stock inquiry',
                    'body' => 'Hi, do you still have 400 units available this week and what is your best bulk price?',
                    'status' => 'read',
                    'metadata' => json_encode(['source' => 'demo_mode', 'scenario' => 'stock_inquiry']),
                    'created_at' => $this->daysAgo(0),
                ], $runId);
                $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $pricingLeadId,
                    'channel' => 'email',
                    'direction' => 'outbound',
                    'subject' => 'Price list and availability shared',
                    'body' => 'Sharing the price list, available quantities, and earliest dispatch window for this order.',
                    'status' => 'sent',
                    'metadata' => json_encode(['source' => 'demo_mode', 'reply_to' => $firstCommId]),
                    'created_at' => $this->daysAgo(1),
                ], $runId);
                $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $reorderLeadId,
                    'channel' => 'whatsapp',
                    'direction' => 'inbound',
                    'subject' => 'Reorder delivery question',
                    'body' => 'Can you still deliver by Friday if we repeat last month’s order size?',
                    'status' => 'read',
                    'metadata' => json_encode(['source' => 'demo_mode', 'scenario' => 'reorder_pending']),
                    'created_at' => $this->daysAgo(2),
                ], $runId);
                $summary['created']['communications'] = ($summary['created']['communications'] ?? 0) + 3;
            }

            if ($this->tableExists('conversation_threads')) {
                $channels = ['whatsapp', 'email', 'email', 'whatsapp'];
                foreach (array_slice($ids['contacts'], 0, 4) as $index => $contactId) {
                    $this->insertAndRegister('conversation_threads', [
                        'contact_id' => $contactId,
                        'channel' => $channels[$index] ?? 'email',
                        'last_message_at' => $this->daysAgo($index),
                        'message_count' => 3 + $index,
                        'is_resolved' => 0,
                        'created_at' => $this->daysAgo(5),
                    ], $runId);
                    $summary['created']['conversation_threads'] = ($summary['created']['conversation_threads'] ?? 0) + 1;
                }
            }

            if ($this->tableExists('emails')) {
                $this->insertAndRegister('emails', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $ids['contacts'][2] ?? $ids['contacts'][0],
                    'user_id' => $ownerUserId,
                    'to_email' => 'orders.' . $runId . '@example.test',
                    'from_email' => 'sales@eastline.example.test',
                    'from_name' => 'Eastline Distribution',
                    'subject' => 'Availability recap and reorder timing',
                    'body' => 'Recapping stock availability, unit pricing, and the reorder timing we discussed.',
                    'body_html' => '<p>Recapping stock availability, unit pricing, and the reorder timing we discussed.</p>',
                    'status' => 'sent',
                    'sent_at' => $this->daysAgo(1),
                    'created_at' => $this->daysAgo(1),
                ], $runId);
                $summary['created']['emails'] = ($summary['created']['emails'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'distribution communications: ' . $e->getMessage();
        }
    }

    private function seedDistributorWholesalerTagsAndNotifications(int $runId, int $adminUserId, int $ownerUserId, array $ids, array &$summary): void
    {
        try {
            if ($this->tableExists('tags') && $this->tableExists('tag_assignments')) {
                $tagRows = [
                    ['name' => 'stock-request', 'color' => '#2563eb', 'description' => 'Buyer is asking about stock or quantities.'],
                    ['name' => 'price-list-sent', 'color' => '#f59e0b', 'description' => 'Pricing has been shared and confirmation is pending.'],
                    ['name' => 'repeat-buyer', 'color' => '#22c55e', 'description' => 'Existing buyer with reorder potential.'],
                    ['name' => 'reorder-risk', 'color' => '#dc2626', 'description' => 'Repeat order or confirmation is at risk of stalling.'],
                ];
                $createdTags = [];
                foreach ($tagRows as $row) {
                    $tagId = $this->insertAndRegister('tags', [
                        'name' => $row['name'] . '-' . $runId,
                        'color' => $row['color'],
                        'description' => $row['description'],
                        'created_by' => $adminUserId,
                    ], $runId);
                    $createdTags[$row['name']] = $tagId;
                    $summary['created']['tags'] = ($summary['created']['tags'] ?? 0) + 1;
                }

                $assignments = [
                    ['tag' => 'stock-request', 'contact_index' => 0],
                    ['tag' => 'price-list-sent', 'contact_index' => 2],
                    ['tag' => 'repeat-buyer', 'contact_index' => 3],
                    ['tag' => 'reorder-risk', 'contact_index' => 3],
                ];
                foreach ($assignments as $assignment) {
                    if (!empty($createdTags[$assignment['tag']]) && !empty($ids['contacts'][$assignment['contact_index']])) {
                        $this->insertAndRegister('tag_assignments', [
                            'tag_id' => $createdTags[$assignment['tag']],
                            'entity_type' => 'contact',
                            'entity_id' => $ids['contacts'][$assignment['contact_index']],
                        ], $runId);
                        $summary['created']['tag_assignments'] = ($summary['created']['tag_assignments'] ?? 0) + 1;
                    }
                }
            }

            if ($this->tableExists('notifications')) {
                $this->insertAndRegister('notifications', [
                    'user_id' => $ownerUserId,
                    'type' => 'demo_mode',
                    'title' => 'Distributor reorder demo ready',
                    'message' => 'Stock inquiry, pricing follow-up, and repeat buyer reorder scenarios are ready for the walkthrough.',
                    'is_read' => 0,
                ], $runId);
                $summary['created']['notifications'] = ($summary['created']['notifications'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'distribution tags: ' . $e->getMessage();
        }
    }

    private function seedContactJourney(int $runId, int $adminUserId, int $ownerUserId, int $contactId, int $idx, array &$ids, array &$summary, int $companyId): void
    {
        try {
            if ($this->tableExists('deals')) {
                $dealId = $this->insertAndRegister('deals', [
                    'title' => 'Demo Deal #' . ($idx + 1),
                    'description' => 'Simulated pipeline opportunity for demo mode',
                    'contact_id' => $contactId,
                    'company_id' => $companyId > 0 ? $companyId : null,
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'stage' => ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'][$idx % 6],
                    'value' => 5000 + ($idx * 1250),
                    'probability' => 20 + (($idx * 7) % 80),
                    'expected_close_date' => date('Y-m-d', strtotime('+' . (7 + $idx) . ' days')),
                    'currency' => $this->getDemoCurrencyCode(),
                    'lead_source' => 'demo_mode',
                    'created_at' => $this->daysAgo(45 - $idx),
                ], $runId);
                $ids['deals'][] = $dealId;
                $summary['created']['deals'] = ($summary['created']['deals'] ?? 0) + 1;
            }
        } catch (\Throwable $e) { $summary['errors'][] = 'deals contact ' . $contactId . ': ' . $e->getMessage(); }

        try {
            if ($this->tableExists('tasks')) {
                $taskId = $this->insertAndRegister('tasks', [
                    'title' => 'Follow-up demo task #' . ($idx + 1),
                    'description' => 'Demo mode seeded task for contact workflow',
                    'contact_id' => $contactId,
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'status' => ['pending', 'in_progress', 'completed'][$idx % 3],
                    'priority' => ['medium', 'high', 'low', 'urgent'][$idx % 4],
                    'due_date' => date('Y-m-d H:i:s', strtotime('+' . (1 + $idx) . ' days')),
                    'completed_at' => ($idx % 3 === 2) ? $this->daysAgo(1) : null,
                    'created_at' => $this->daysAgo(30 - $idx),
                ], $runId);
                $ids['tasks'][] = $taskId;
                $summary['created']['tasks'] = ($summary['created']['tasks'] ?? 0) + 1;

                if ($this->tableExists('task_subtasks')) {
                    for ($s = 1; $s <= 2; $s++) {
                        $this->insertAndRegister('task_subtasks', [
                            'task_id' => $taskId,
                            'title' => 'Checklist item ' . $s,
                            'is_completed' => ($s === 1) ? 1 : 0,
                            'order' => $s,
                        ], $runId);
                        $summary['created']['task_subtasks'] = ($summary['created']['task_subtasks'] ?? 0) + 1;
                    }
                }
            }
        } catch (\Throwable $e) { $summary['errors'][] = 'tasks contact ' . $contactId . ': ' . $e->getMessage(); }
        try {
            if ($this->tableExists('activities')) {
                $this->insertAndRegister('activities', [
                    'contact_id' => $contactId,
                    'user_id' => $ownerUserId,
                    'activity_type' => 'note',
                    'description' => 'Demo activity logged for walkthrough',
                    'metadata' => json_encode(['source' => 'demo_mode']),
                    'created_at' => $this->daysAgo(15 - ($idx % 8)),
                ], $runId);
                $summary['created']['activities'] = ($summary['created']['activities'] ?? 0) + 1;
            }
        } catch (\Throwable $e) { $summary['errors'][] = 'activities contact ' . $contactId . ': ' . $e->getMessage(); }

        try {
            if ($this->tableExists('events')) {
                $eventId = $this->insertAndRegister('events', [
                    'title' => 'Demo call #' . ($idx + 1),
                    'description' => 'Scheduled event generated by demo mode',
                    'event_type' => 'call',
                    'contact_id' => $contactId,
                    'assigned_to' => $ownerUserId,
                    'created_by' => $adminUserId,
                    'start_time' => date('Y-m-d H:i:s', strtotime('+' . (2 + $idx) . ' days 10:00:00')),
                    'end_time' => date('Y-m-d H:i:s', strtotime('+' . (2 + $idx) . ' days 10:30:00')),
                    'status' => 'scheduled',
                    'created_at' => $this->daysAgo(10 - ($idx % 5)),
                ], $runId);
                $ids['events'][] = $eventId;
                $summary['created']['events'] = ($summary['created']['events'] ?? 0) + 1;
            }
        } catch (\Throwable $e) { $summary['errors'][] = 'events contact ' . $contactId . ': ' . $e->getMessage(); }

        try {
            if ($this->tableExists('communications')) {
                $commInboundId = $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $contactId,
                    'channel' => 'email',
                    'direction' => 'inbound',
                    'subject' => 'Question about pricing',
                    'body' => 'Hi team, can we discuss pricing options?',
                    'status' => 'read',
                    'metadata' => json_encode(['source' => 'demo_mode']),
                    'created_at' => $this->daysAgo(6 - ($idx % 4)),
                ], $runId);
                $this->insertAndRegister('communications', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $contactId,
                    'channel' => 'whatsapp',
                    'direction' => 'outbound',
                    'subject' => 'Demo follow-up',
                    'body' => 'Thanks for your interest. Sharing proposal details.',
                    'status' => 'delivered',
                    'metadata' => json_encode(['source' => 'demo_mode', 'reply_to' => $commInboundId]),
                    'created_at' => $this->daysAgo(5 - ($idx % 4)),
                ], $runId);
                $summary['created']['communications'] = ($summary['created']['communications'] ?? 0) + 2;
            }
        } catch (\Throwable $e) { $summary['errors'][] = 'communications contact ' . $contactId . ': ' . $e->getMessage(); }

        try {
            if ($this->tableExists('conversation_threads')) {
                $this->insertAndRegister('conversation_threads', [
                    'contact_id' => $contactId,
                    'channel' => 'whatsapp',
                    'last_message_at' => $this->daysAgo(2),
                    'message_count' => 3 + $idx,
                    'is_resolved' => ($idx % 4 === 0) ? 1 : 0,
                    'resolved_at' => ($idx % 4 === 0) ? $this->daysAgo(1) : null,
                    'created_at' => $this->daysAgo(10),
                ], $runId);
                $summary['created']['conversation_threads'] = ($summary['created']['conversation_threads'] ?? 0) + 1;
            }
        } catch (\Throwable $e) { $summary['errors'][] = 'conversation_threads contact ' . $contactId . ': ' . $e->getMessage(); }

        try {
            if ($this->tableExists('emails')) {
                $emailId = $this->insertAndRegister('emails', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $contactId,
                    'user_id' => $ownerUserId,
                    'to_email' => 'demo.contact.' . $runId . '.' . ($idx + 1) . '@example.test',
                    'from_email' => 'hello@example.test',
                    'from_name' => 'Demo Team',
                    'subject' => 'Demo nurture email #' . ($idx + 1),
                    'body' => 'This is a demo seeded email body.',
                    'body_html' => '<p>This is a <strong>demo seeded</strong> email body.</p>',
                    'status' => 'sent',
                    'sent_at' => $this->daysAgo(4),
                    'created_at' => $this->daysAgo(4),
                ], $runId);
                $summary['created']['emails'] = ($summary['created']['emails'] ?? 0) + 1;

                if ($this->tableExists('email_queue')) {
                    $this->insertAndRegister('email_queue', [
                        'email_id' => $emailId,
                        'priority' => 0,
                        'attempts' => 1,
                        'max_attempts' => 3,
                        'scheduled_at' => $this->daysAgo(4),
                        'processed_at' => $this->daysAgo(4),
                        'status' => 'completed',
                    ], $runId);
                    $summary['created']['email_queue'] = ($summary['created']['email_queue'] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) { $summary['errors'][] = 'emails contact ' . $contactId . ': ' . $e->getMessage(); }

        try {
            if ($this->tableExists('sms_messages')) {
                $smsId = $this->insertAndRegister('sms_messages', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $contactId,
                    'user_id' => $ownerUserId,
                    'to_number' => '+1555100' . str_pad((string) ($idx + 1), 4, '0', STR_PAD_LEFT),
                    'from_number' => '+15559990000',
                    'message_body' => 'Demo SMS follow-up message',
                    'message_type' => 'text',
                    'status' => 'sent',
                    'direction' => 'outbound',
                    'provider' => 'demo',
                    'provider_message_id' => 'demo-sms-' . $runId . '-' . $idx,
                    'created_at' => $this->daysAgo(3),
                ], $runId);
                $summary['created']['sms_messages'] = ($summary['created']['sms_messages'] ?? 0) + 1;

                if ($this->tableExists('sms_queue')) {
                    $this->insertAndRegister('sms_queue', [
                        'message_id' => $smsId,
                        'priority' => 0,
                        'status' => 'completed',
                        'attempts' => 1,
                        'max_attempts' => 3,
                        'scheduled_at' => $this->daysAgo(3),
                        'last_attempt_at' => $this->daysAgo(3),
                    ], $runId);
                    $summary['created']['sms_queue'] = ($summary['created']['sms_queue'] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) { $summary['errors'][] = 'sms_messages contact ' . $contactId . ': ' . $e->getMessage(); }

        try {
            if ($this->tableExists('whatsapp_messages')) {
                $waId = $this->insertAndRegister('whatsapp_messages', [
                    'uuid' => $this->uuid(),
                    'contact_id' => $contactId,
                    'user_id' => $ownerUserId,
                    'to_number' => '1555100' . str_pad((string) ($idx + 1), 4, '0', STR_PAD_LEFT),
                    'from_number' => '15550000000',
                    'message_type' => 'text',
                    'message_body' => 'Demo WhatsApp seeded message',
                    'status' => 'sent',
                    'direction' => 'outbound',
                    'whatsapp_message_id' => 'wamid.demo.' . $runId . '.' . $idx,
                    'sent_at' => $this->daysAgo(2),
                    'created_at' => $this->daysAgo(2),
                ], $runId);
                $summary['created']['whatsapp_messages'] = ($summary['created']['whatsapp_messages'] ?? 0) + 1;

                if ($this->tableExists('whatsapp_queue')) {
                    $this->insertAndRegister('whatsapp_queue', [
                        'message_id' => $waId,
                        'priority' => 0,
                        'attempts' => 1,
                        'max_attempts' => 3,
                        'scheduled_at' => $this->daysAgo(2),
                        'processed_at' => $this->daysAgo(2),
                        'status' => 'completed',
                    ], $runId);
                    $summary['created']['whatsapp_queue'] = ($summary['created']['whatsapp_queue'] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) { $summary['errors'][] = 'whatsapp_messages contact ' . $contactId . ': ' . $e->getMessage(); }

        try {
            if ($this->tableExists('notes')) {
                $this->insertAndRegister('notes', [
                    'entity_type' => 'contact',
                    'entity_id' => $contactId,
                    'title' => 'Demo note',
                    'content' => 'This note is part of seeded demo data.',
                    'is_private' => 0,
                    'created_by' => $ownerUserId,
                    'created_at' => $this->daysAgo(7),
                ], $runId);
                $summary['created']['notes'] = ($summary['created']['notes'] ?? 0) + 1;
            }
        } catch (\Throwable $e) { $summary['errors'][] = 'notes contact ' . $contactId . ': ' . $e->getMessage(); }
    }
    private function seedTags(int $runId, int $adminUserId, array $ids, array &$summary): void
    {
        try {
            if (!$this->tableExists('tags')) { return; }
            $tagId = $this->insertAndRegister('tags', [
                'name' => 'demo-run-' . $runId,
                'color' => '#2563eb',
                'description' => 'Seeded demo tag',
                'created_by' => $adminUserId,
            ], $runId);
            $summary['created']['tags'] = ($summary['created']['tags'] ?? 0) + 1;

            if (!empty($ids['contacts']) && $this->tableExists('tag_assignments')) {
                foreach (array_slice($ids['contacts'], 0, 5) as $contactId) {
                    $this->insertAndRegister('tag_assignments', [
                        'tag_id' => $tagId,
                        'entity_type' => 'contact',
                        'entity_id' => $contactId,
                    ], $runId);
                    $summary['created']['tag_assignments'] = ($summary['created']['tag_assignments'] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'tags: ' . $e->getMessage();
        }
    }

    private function seedWorkflows(int $runId, array $ids, array &$summary): void
    {
        try {
            if ($this->tableExists('workflows')) {
                $workflowId = $this->insertAndRegister('workflows', [
                    'name' => 'Demo Follow-up Workflow',
                    'trigger_config' => json_encode(['type' => 'contact_created']),
                    'conditions' => json_encode([]),
                    'actions' => json_encode([
                        ['type' => 'create_task', 'title' => 'Demo follow-up task', 'priority' => 'high'],
                        ['type' => 'send_email', 'subject' => 'Welcome from Demo Workflow', 'body' => 'Thanks for joining.'],
                    ]),
                    'is_active' => 1,
                    'created_at' => $this->daysAgo(20),
                ], $runId);
                $summary['created']['workflows'] = ($summary['created']['workflows'] ?? 0) + 1;

                if (!empty($ids['contacts']) && $this->tableExists('workflow_executions')) {
                    foreach (array_slice($ids['contacts'], 0, 3) as $contactId) {
                        $this->insertAndRegister('workflow_executions', [
                            'workflow_id' => $workflowId,
                            'contact_id' => $contactId,
                            'status' => 'completed',
                            'executed_at' => $this->daysAgo(5),
                            'completed_at' => $this->daysAgo(5),
                        ], $runId);
                        $summary['created']['workflow_executions'] = ($summary['created']['workflow_executions'] ?? 0) + 1;
                    }
                }
            }

            if ($this->tableExists('workflow_templates')) {
                $this->insertAndRegister('workflow_templates', [
                    'name' => 'Demo Escalation Template',
                    'description' => 'Template generated for demo mode',
                    'category' => 'demo',
                    'trigger_config' => json_encode(['type' => 'deal_stage_changed', 'to_stage' => 'proposal']),
                    'conditions' => json_encode([]),
                    'actions' => json_encode([
                        ['type' => 'create_task', 'title' => 'Escalate demo deal', 'priority' => 'urgent'],
                        ['type' => 'send_whatsapp', 'message' => 'Checking in on the proposal.'],
                    ]),
                    'variables' => json_encode(['first_name', 'deal.title']),
                    'is_public' => 1,
                    'usage_count' => 3,
                    'rating' => 4.8,
                ], $runId);
                $summary['created']['workflow_templates'] = ($summary['created']['workflow_templates'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'workflows: ' . $e->getMessage();
        }
    }

    private function seedForms(int $runId, int $adminUserId, array &$ids, array &$summary): void
    {
        try {
            if (!$this->tableExists('forms')) { return; }
            $formId = $this->insertAndRegister('forms', [
                'uuid' => $this->uuid(),
                'name' => 'Demo Lead Capture Form',
                'fields' => json_encode([
                    ['name' => 'first_name', 'type' => 'text', 'required' => true],
                    ['name' => 'email', 'type' => 'email', 'required' => true],
                    ['name' => 'message', 'type' => 'textarea', 'required' => false],
                ]),
                'success_message' => 'Thanks! Our demo team will reach out.',
                'created_by' => $adminUserId,
            ], $runId);
            $ids['forms'][] = $formId;
            $summary['created']['forms'] = ($summary['created']['forms'] ?? 0) + 1;

            if ($this->tableExists('form_submissions') && !empty($ids['contacts'])) {
                $this->insertAndRegister('form_submissions', [
                    'visitor_id' => 'demo-visitor-' . $runId,
                    'contact_id' => $ids['contacts'][0],
                    'form_id' => 'demo_form_' . $runId,
                    'form_definition_id' => $formId,
                    'form_data' => json_encode(['first_name' => 'Demo', 'email' => 'lead@example.test', 'message' => 'Interested in a walkthrough']),
                    'page_path' => '/demo/signup',
                    'submitted_at' => $this->daysAgo(3),
                    'ai_tags' => json_encode(['hot_lead', 'demo_mode']),
                    'ai_follow_up' => 'Schedule enterprise onboarding walkthrough',
                    'ai_suggested_stage' => 'qualified',
                ], $runId);
                $summary['created']['form_submissions'] = ($summary['created']['form_submissions'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'forms/form_submissions: ' . $e->getMessage();
        }
    }

    private function seedTargets(int $runId, int $ownerUserId, array &$ids, array &$summary): void
    {
        try {
            if (!$this->tableExists('targets')) { return; }
            $targetId = $this->insertAndRegister('targets', [
                'user_id' => $ownerUserId,
                'title' => 'Close 12 Demo Deals',
                'description' => 'Seeded target for demo walkthrough',
                'target_type' => 'sales',
                'target_value' => 12,
                'current_value' => 5,
                'unit' => 'deals',
                'start_date' => date('Y-m-d', strtotime('-30 days')),
                'target_date' => date('Y-m-d', strtotime('+30 days')),
                'status' => 'active',
                'reminder_frequency' => 'weekly',
            ], $runId);
            $ids['targets'][] = $targetId;
            $summary['created']['targets'] = ($summary['created']['targets'] ?? 0) + 1;

            if ($this->tableExists('target_reminders')) {
                $this->insertAndRegister('target_reminders', [
                    'target_id' => $targetId,
                    'reminder_type' => 'weekly',
                    'scheduled_at' => date('Y-m-d H:i:s', strtotime('+2 days')),
                    'status' => 'pending',
                ], $runId);
                $summary['created']['target_reminders'] = ($summary['created']['target_reminders'] ?? 0) + 1;
            }

            if ($this->tableExists('target_advice')) {
                $this->insertAndRegister('target_advice', [
                    'target_id' => $targetId,
                    'advice_text' => 'Focus first on high-intent qualified leads to improve conversion velocity.',
                    'advice_type' => 'tactical',
                ], $runId);
                $summary['created']['target_advice'] = ($summary['created']['target_advice'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'targets: ' . $e->getMessage();
        }
    }

    private function seedNotifications(int $runId, array $ids, array &$summary): void
    {
        try {
            if (!$this->tableExists('notifications')) { return; }
            foreach (array_unique($ids['users']) as $userId) {
                if ((int) $userId <= 0) { continue; }
                $this->insertAndRegister('notifications', [
                    'user_id' => $userId,
                    'type' => 'system_alert',
                    'title' => 'Demo Mode Seed Complete',
                    'message' => 'Demo mode seeded operational data for walkthroughs.',
                    'entity_type' => 'demo_run',
                    'entity_id' => $runId,
                    'link' => 'settings.php?tab=general',
                    'is_read' => 0,
                ], $runId);
                $summary['created']['notifications'] = ($summary['created']['notifications'] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'notifications: ' . $e->getMessage();
        }
    }
    private function seedMl(int $runId, int $adminUserId, array &$ids, array &$summary): void
    {
        try {
            if (!$this->tableExists('ml_models')) { return; }
            $modelId = $this->insertAndRegister('ml_models', [
                'model_type' => 'conversion',
                'version' => 'demo-v' . $runId,
                'algorithm' => 'random_forest',
                'model_data' => 'DEMO_MODEL_PAYLOAD',
                'feature_list' => json_encode(['engagement_score', 'response_time', 'deal_value']),
                'hyperparameters' => json_encode(['n_estimators' => 100, 'max_depth' => 8]),
                'is_active' => 1,
                'accuracy' => 0.8421,
                'precision_score' => 0.8111,
                'recall_score' => 0.7999,
                'f1_score' => 0.8054,
                'auc_roc' => 0.8822,
                'log_loss' => 0.4231,
                'training_samples' => 1200,
                'validation_samples' => 250,
                'test_samples' => 250,
                'trained_at' => $this->daysAgo(7),
                'trained_by' => $adminUserId,
                'notes' => 'Seeded demo ML model',
            ], $runId);
            $ids['ml_models'][] = $modelId;
            $summary['created']['ml_models'] = ($summary['created']['ml_models'] ?? 0) + 1;

            if (!empty($ids['contacts']) && $this->tableExists('ml_predictions')) {
                foreach (array_slice($ids['contacts'], 0, 5) as $cIdx => $contactId) {
                    $predictionId = $this->insertAndRegister('ml_predictions', [
                        'contact_id' => $contactId,
                        'model_id' => $modelId,
                        'prediction_score' => 55 + ($cIdx * 7),
                        'probability' => min(0.95, 0.45 + ($cIdx * 0.08)),
                        'confidence' => 0.80,
                        'top_factors' => json_encode(['activity_level', 'email_opens']),
                        'feature_values' => json_encode(['activity_level' => 0.6 + ($cIdx * 0.1)]),
                        'explanation' => 'Seeded prediction for demo.',
                        'cached_at' => $this->daysAgo(2),
                    ], $runId);
                    $summary['created']['ml_predictions'] = ($summary['created']['ml_predictions'] ?? 0) + 1;

                    if ($this->tableExists('ml_prediction_outcomes')) {
                        $this->insertAndRegister('ml_prediction_outcomes', [
                            'prediction_id' => $predictionId,
                            'contact_id' => $contactId,
                            'model_id' => $modelId,
                            'predicted_probability' => min(0.95, 0.45 + ($cIdx * 0.08)),
                            'actual_outcome' => ($cIdx % 2 === 0) ? 1 : 0,
                            'outcome_type' => 'conversion',
                            'outcome_date' => date('Y-m-d', strtotime('-' . (1 + $cIdx) . ' days')),
                            'outcome_value' => 1000 + ($cIdx * 300),
                            'prediction_error' => 0.12,
                            'validated_at' => $this->daysAgo(1),
                        ], $runId);
                        $summary['created']['ml_prediction_outcomes'] = ($summary['created']['ml_prediction_outcomes'] ?? 0) + 1;
                    }
                }
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'ml_scoring: ' . $e->getMessage();
        }
    }

    private function seedOutcomeAndActivation(int $runId, int $ownerUserId, array $ids, array &$summary): void
    {
        try {
            if ($this->tableExists('outcome_events')) {
                foreach (array_slice($ids['contacts'], 0, 4) as $i => $contactId) {
                    $this->insertAndRegister('outcome_events', [
                        'user_id' => $ownerUserId,
                        'contact_id' => $contactId,
                        'deal_id' => $ids['deals'][$i] ?? null,
                        'event_key' => 'demo_followup_completed',
                        'event_source' => 'demo_mode',
                        'event_at' => $this->daysAgo(2 + $i),
                        'metadata' => json_encode(['run_id' => $runId, 'index' => $i]),
                    ], $runId);
                    $summary['created']['outcome_events'] = ($summary['created']['outcome_events'] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'outcome_events: ' . $e->getMessage();
        }

        try {
            if ($this->tableExists('activation_progress')) {
                foreach (array_unique($ids['users']) as $userId) {
                    if ((int) $userId <= 0) { continue; }
                    $existingActivation = Database::queryOne(
                        'SELECT id FROM activation_progress WHERE user_id = ? LIMIT 1',
                        [$userId]
                    );
                    if (!empty($existingActivation['id'])) {
                        continue;
                    }
                    $this->insertAndRegister('activation_progress', [
                        'user_id' => $userId,
                        'first_login_at' => $this->daysAgo(20),
                        'connected_channel_at' => $this->daysAgo(18),
                        'first_contact_at' => $this->daysAgo(16),
                        'first_inbound_at' => $this->daysAgo(15),
                        'first_followup_task_completed_at' => $this->daysAgo(10),
                        'first_deal_created_at' => $this->daysAgo(14),
                        'first_deal_advanced_at' => $this->daysAgo(8),
                    ], $runId);
                    $summary['created']['activation_progress'] = ($summary['created']['activation_progress'] ?? 0) + 1;
                }
            }

            if ($this->tableExists('page_views') && !empty($ids['contacts'])) {
                foreach (array_slice($ids['contacts'], 0, 4) as $i => $contactId) {
                    $this->insertAndRegister('page_views', [
                        'visitor_id' => 'demo-visitor-' . $runId,
                        'contact_id' => $contactId,
                        'page_path' => '/pricing',
                        'referrer' => 'https://google.com',
                        'utm_source' => 'demo',
                        'utm_medium' => 'seed',
                        'utm_campaign' => 'demo_mode',
                        'viewed_at' => $this->daysAgo(1 + $i),
                    ], $runId);
                    $summary['created']['page_views'] = ($summary['created']['page_views'] ?? 0) + 1;
                }
            }
        } catch (\Throwable $e) {
            $summary['errors'][] = 'activation/page_views: ' . $e->getMessage();
        }
    }

    private function ensureDemoUser(int $runId, string $slug, string $role, string $firstName, string $lastName, array &$summary): int
    {
        if (!$this->tableExists('users')) { return 0; }

        $email = 'demo.' . $slug . '.' . $runId . '@example.test';
        $existing = Database::queryOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
        if (!empty($existing['id'])) {
            return (int) $existing['id'];
        }

        $userId = (int) $this->insertRow('users', [
            'uuid' => $this->uuid(),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password_hash' => password_hash('DemoMode#' . $runId, PASSWORD_DEFAULT),
            'role' => $role,
        ]);
        $summary['created']['users'] = ($summary['created']['users'] ?? 0) + 1;

        return $userId;
    }

    private function insertAndRegister(string $tableName, array $data, int $runId): int
    {
        $id = $this->insertRow($tableName, $data);
        if ($id > 0 && $this->tableExists('demo_seed_registry')) {
            Database::execute('INSERT INTO demo_seed_registry (run_id, table_name, record_id) VALUES (?, ?, ?)', [$runId, $tableName, $id]);
        }
        return $id;
    }

    private function insertRow(string $tableName, array $data): int
    {
        $columns = $this->tableColumns($tableName);
        if (empty($columns)) {
            throw new \RuntimeException('Table does not exist or has no columns: ' . $tableName);
        }

        $filtered = [];
        foreach ($data as $column => $value) {
            if (isset($columns[$column])) {
                $filtered[$column] = $value;
            }
        }

        if (
            $tableName === 'conversation_threads'
            && isset($columns['thread_key'])
            && empty($filtered['thread_key'])
        ) {
            $filtered['thread_key'] = sprintf(
                'demo-thread-%s-%s',
                (string) ($filtered['contact_id'] ?? $this->uuid()),
                (string) ($filtered['channel'] ?? 'unknown')
            );
        }

        if (empty($filtered)) {
            throw new \RuntimeException('No valid insertable columns for table: ' . $tableName);
        }

        $cols = array_keys($filtered);
        $placeholders = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO `' . $tableName . '` (`' . implode('`, `', $cols) . '`) VALUES (' . $placeholders . ')';
        Database::execute($sql, array_values($filtered));

        return (int) Database::lastInsertId();
    }

    private function tableColumns(string $tableName): array
    {
        static $cache = [];
        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $rows = Database::query('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?', [$tableName]);
        $columns = [];
        foreach ($rows as $row) {
            $name = (string) ($row['COLUMN_NAME'] ?? '');
            if ($name !== '') { $columns[$name] = true; }
        }

        $cache[$tableName] = $columns;
        return $columns;
    }

    private function tableExists(string $tableName): bool
    {
        static $existsCache = [];
        if (array_key_exists($tableName, $existsCache)) {
            return $existsCache[$tableName];
        }

        $row = Database::queryOne('SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$tableName]);
        $existsCache[$tableName] = ((int) ($row['cnt'] ?? 0)) > 0;
        return $existsCache[$tableName];
    }

    private function getDemoCurrencyCode(): string
    {
        if ($this->demoCurrencyCode !== null) {
            return $this->demoCurrencyCode;
        }

        $fallback = 'USD';
        try {
            if (!$this->tableExists('currencies')) {
                return $this->demoCurrencyCode = $fallback;
            }

            $row = Database::queryOne('SELECT code FROM currencies WHERE is_default = 1 ORDER BY id ASC LIMIT 1');
            $code = strtoupper(trim((string) ($row['code'] ?? '')));
            $this->demoCurrencyCode = $code !== '' ? $code : $fallback;
        } catch (\Throwable $e) {
            $this->demoCurrencyCode = $fallback;
        }

        return $this->demoCurrencyCode;
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime('-' . max(0, $days) . ' days'));
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
