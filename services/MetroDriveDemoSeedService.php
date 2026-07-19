<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class MetroDriveDemoSeedService
{
    private const SOURCE = 'metrodrive_demo_seed_v1';

    /**
     * @return array{purged:int,seeded:array<string,mixed>}
     */
    public function reseed(int $workspaceId, int $actorUserId = 0): array
    {
        if ($workspaceId <= 0) {
            return ['purged' => 0, 'seeded' => ['created' => 0, 'skipped' => true]];
        }

        Database::beginTransaction();
        try {
            $purged = $this->purgePublicSeed($workspaceId);
            $seeded = $this->ensureSeeded($workspaceId, $actorUserId, true);
            Database::commit();
            return ['purged' => $purged, 'seeded' => $seeded];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function ensureSeeded(int $workspaceId, int $actorUserId = 0, bool $force = false): array
    {
        if ($workspaceId <= 0) {
            return ['created' => 0, 'skipped' => true];
        }

        $actorUserId = $this->resolveActorUserId($workspaceId, $actorUserId);
        $baseline = $this->baseline($workspaceId);
        if (!$force && !empty($baseline['complete'])) {
            $this->ensureAssistantDigestConfig($workspaceId, $actorUserId);
            return ['created' => 0, 'skipped' => true, 'baseline' => $baseline];
        }

        $ownsTransaction = !Database::getInstance()->inTransaction();
        if ($ownsTransaction) {
            Database::beginTransaction();
        }

        try {
            if ($force || !empty($baseline['partial'])) {
                $this->purgePublicSeed($workspaceId);
            }

            $contacts = $this->seedContacts($workspaceId, $actorUserId);
            $threads = $this->seedThreadsAndMessages($workspaceId, $actorUserId, $contacts);
            $tasks = $this->seedTasks($workspaceId, $actorUserId, $contacts);
            $deals = $this->seedDeals($workspaceId, $actorUserId, $contacts);
            $activities = $this->seedActivities($workspaceId, $actorUserId, $contacts);
            $invoices = $this->seedInvoices($workspaceId, $actorUserId, $contacts, $deals);
            $reports = $this->seedReports($workspaceId, $actorUserId);
            $this->ensureAssistantDigestConfig($workspaceId, $actorUserId);

            if ($ownsTransaction) {
                Database::commit();
            }

            return [
                'created' => count($contacts),
                'skipped' => false,
                'contacts' => count($contacts),
                'threads' => $threads['threads'],
                'communications' => $threads['communications'],
                'channel_artifacts' => $threads['channel_artifacts'],
                'tasks' => $tasks,
                'deals' => count($deals),
                'activities' => $activities,
                'invoices' => $invoices,
                'reports' => $reports,
            ];
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array{contacts:int,communications:int,tasks:int,deals:int,complete:bool,partial:bool}
     */
    private function baseline(int $workspaceId): array
    {
        $contacts = $this->countSeedRows($workspaceId, 'contacts');
        $communications = $this->countSeedRows($workspaceId, 'communications');
        $tasks = $this->countSeedRows($workspaceId, 'tasks');
        $deals = $this->countSeedRows($workspaceId, 'deals');

        return [
            'contacts' => $contacts,
            'communications' => $communications,
            'tasks' => $tasks,
            'deals' => $deals,
            'complete' => $contacts >= 48 && $communications >= 90 && $tasks >= 24 && $deals >= 24,
            'partial' => $contacts > 0 || $communications > 0 || $tasks > 0 || $deals > 0,
        ];
    }

    private function countSeedRows(int $workspaceId, string $table): int
    {
        if (!Database::tableExists($table) || !Database::columnExists($table, 'demo_visibility')) {
            return 0;
        }

        $sourceClauses = [];
        foreach (['metadata_json', 'metadata', 'custom_fields'] as $column) {
            if (Database::columnExists($table, $column)) {
                $sourceClauses[] = "{$column} LIKE ?";
            }
        }
        $params = [$workspaceId];
        foreach ($sourceClauses as $_) {
            $params[] = '%' . self::SOURCE . '%';
        }
        $sourceSql = $sourceClauses !== [] ? ' AND (' . implode(' OR ', $sourceClauses) . ')' : '';

        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM `{$table}`
             WHERE workspace_id = ?
               AND demo_visibility = 'public_seed'
               {$sourceSql}",
            $params
        )['c'] ?? 0);
    }

    private function purgePublicSeed(int $workspaceId): int
    {
        $deleted = 0;

        if (Database::tableExists('scheduled_reports') && Database::columnExists('scheduled_reports', 'workspace_id')) {
            $deleted += Database::execute(
                "DELETE FROM scheduled_reports
                 WHERE workspace_id = ?
                   AND (schedule_name LIKE 'MetroDrive%' OR schedule_config LIKE ?)",
                [$workspaceId, '%' . self::SOURCE . '%']
            );
        }

        if (Database::tableExists('reports') && Database::columnExists('reports', 'workspace_id')) {
            $deleted += Database::execute(
                "DELETE FROM reports
                 WHERE workspace_id = ?
                   AND (name LIKE 'MetroDrive%' OR query_config LIKE ?)",
                [$workspaceId, '%' . self::SOURCE . '%']
            );
        }

        if (Database::tableExists('invoices')) {
            $deleted += Database::execute(
                "DELETE FROM invoices
                 WHERE workspace_id = ?
                   AND (invoice_number LIKE ? OR source_snapshot_json LIKE ?)",
                [$workspaceId, 'MDA-' . $workspaceId . '-%', '%' . self::SOURCE . '%']
            );
        }

        $tables = [
            'demo_realtime_events',
            'notifications',
            'email_queue',
            'whatsapp_queue',
            'emails',
            'whatsapp_messages',
            'activities',
            'tasks',
            'deals',
            'communications',
            'conversation_threads',
            'contacts',
        ];

        foreach ($tables as $table) {
            if (!Database::tableExists($table)) {
                continue;
            }

            if ($table === 'demo_realtime_events') {
                $deleted += Database::execute(
                    "DELETE FROM demo_realtime_events
                     WHERE workspace_id = ?
                       AND demo_session_id IS NULL",
                    [$workspaceId]
                );
                continue;
            }

            if (!Database::columnExists($table, 'demo_visibility')) {
                continue;
            }

            $deleted += Database::execute(
                "DELETE FROM `{$table}`
                 WHERE workspace_id = ?
                   AND demo_visibility = 'public_seed'",
                [$workspaceId]
            );
        }

        return $deleted;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function seedContacts(int $workspaceId, int $actorUserId): array
    {
        $names = [
            ['Amina', 'Otieno'], ['Brian', 'Mwangi'], ['Grace', 'Wanjiru'], ['David', 'Kariuki'],
            ['Fatuma', 'Hassan'], ['Joseph', 'Ochieng'], ['Linet', 'Achieng'], ['Samuel', 'Njoroge'],
            ['Miriam', 'Chebet'], ['Peter', 'Kamau'], ['Nadia', 'Said'], ['Victor', 'Onyango'],
            ['Mercy', 'Wambui'], ['Ibrahim', 'Ali'], ['Stella', 'Nyambura'], ['Collins', 'Kiptoo'],
            ['Joyce', 'Muthoni'], ['Kevin', 'Maina'], ['Ruth', 'Njeri'], ['George', 'Odhiambo'],
            ['Hellen', 'Wairimu'], ['Patrick', 'Mutua'], ['Caroline', 'Atieno'], ['Oscar', 'Kimani'],
            ['Esther', 'Nyokabi'], ['Daniel', 'Langat'], ['Naomi', 'Wekesa'], ['Martin', 'Omondi'],
            ['Beatrice', 'Moraa'], ['Isaac', 'Njenga'], ['Sofia', 'Abdi'], ['Emmanuel', 'Barasa'],
            ['Ivy', 'Nyawira'], ['Caleb', 'Kibet'], ['Janet', 'Mbithe'], ['Lawrence', 'Okello'],
            ['Teresa', 'Wanjiku'], ['Hussein', 'Mohamed'], ['Rose', 'Akoth'], ['Felix', 'Karanja'],
            ['Agnes', 'Jelimo'], ['Simon', 'Mbugua'], ['Purity', 'Naliaka'], ['Edwin', 'Otieno'],
            ['Monica', 'Wangechi'], ['Nicholas', 'Cheruiyot'], ['Lucy', 'Mumbi'], ['Vincent', 'Munyao'],
        ];
        $packages = ['Beginner 20-lesson package', 'Refresher 5-lesson pack', 'NTSA test-prep sprint', 'Defensive driving course', 'Corporate fleet training'];
        $branches = ['Westlands', 'Upper Hill', 'Karen', 'Thika Road', 'Mombasa Road'];
        $stages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
        $sources = ['whatsapp', 'form', 'referral', 'social', 'ad'];
        $contacts = [];

        foreach ($names as $index => $name) {
            $package = $packages[$index % count($packages)];
            $branch = $branches[$index % count($branches)];
            $stage = $stages[$index % count($stages)];
            $source = $sources[$index % count($sources)];
            $phone = '+254711' . str_pad((string) (600000 + $index), 6, '0', STR_PAD_LEFT);
            $email = strtolower($name[0] . '.' . $name[1] . '@metrodrive-demo.example');
            $company = $package === 'Corporate fleet training' ? $name[1] . ' Logistics Team' : 'MetroDrive Learner Account';
            $metadata = [
                'source' => self::SOURCE,
                'package' => $package,
                'branch' => $branch,
                'preferred_slot' => $index % 3 === 0 ? 'weekday mornings' : ($index % 3 === 1 ? 'evenings' : 'Saturday practicals'),
                'document_status' => $index % 4 === 0 ? 'missing_id_photo' : 'complete',
                'payment_status' => $index % 5 === 0 ? 'deposit_pending' : 'ok',
            ];

            Database::execute(
                "INSERT INTO contacts
                    (workspace_id, demo_visibility, demo_session_id, uuid, first_name, last_name, email, phone,
                     whatsapp_opt_in_status, whatsapp_opt_in_source, whatsapp_opt_in_at, company, lead_source, stage,
                     assigned_to, created_by, lead_score, location, timezone, email_verified, metadata_json, created_at, updated_at)
                 VALUES (?, 'public_seed', NULL, ?, ?, ?, ?, ?, 'opted_in', 'presentation_demo_seed', DATE_SUB(NOW(), INTERVAL ? DAY),
                         ?, ?, ?, ?, ?, ?, ?, 'Africa/Nairobi', 1, ?, DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? HOUR))",
                [
                    $workspaceId,
                    $this->uuid(),
                    $name[0],
                    $name[1],
                    $email,
                    $phone,
                    min(30, $index + 1),
                    $company,
                    $source,
                    $stage,
                    $actorUserId,
                    $actorUserId,
                    min(98, 58 + ($index % 38)),
                    $branch . ', Nairobi',
                    json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    24 - ($index % 18),
                    2 + ($index % 36),
                ]
            );

            $contacts[] = [
                'id' => (int) Database::lastInsertId(),
                'first_name' => $name[0],
                'last_name' => $name[1],
                'email' => $email,
                'phone' => $phone,
                'company' => $company,
                'package' => $package,
                'branch' => $branch,
                'stage' => $stage,
                'source' => $source,
            ];
        }

        return $contacts;
    }

    /**
     * @param array<int,array<string,mixed>> $contacts
     * @return array{threads:int,communications:int,channel_artifacts:int}
     */
    private function seedThreadsAndMessages(int $workspaceId, int $actorUserId, array $contacts): array
    {
        $threadCount = 0;
        $communicationCount = 0;
        $artifactCount = 0;
        $templates = [
            'lead_inquiry' => [
                'subject' => 'Driving lesson inquiry',
                'inbound' => 'Hi MetroDrive, I want to start driving lessons. Which package fits a beginner and how soon can I start?',
                'outbound' => 'Thanks for reaching out. For a beginner we recommend the 20-lesson package. I can hold two starter slots and send the document checklist now.',
                'followup' => 'Please hold the evening slot and send the payment details.',
            ],
            'document_followup' => [
                'subject' => 'Documents for learner file',
                'inbound' => 'I have paid the deposit but I am not sure which documents you still need.',
                'outbound' => 'You are almost ready. We still need your ID photo and NTSA eCitizen profile screenshot before the first road lesson.',
                'followup' => 'I will send the ID photo today. Please keep my Saturday lesson.',
            ],
            'payment_reminder' => [
                'subject' => 'Payment confirmation',
                'inbound' => 'Can I pay the balance after the first two lessons?',
                'outbound' => 'Yes. Your deposit secures the slot, and the remaining balance is due before lesson three. I have added a reminder for accounts.',
                'followup' => 'That works. Please send the paybill instructions.',
            ],
            'escalation' => [
                'subject' => 'Instructor concern',
                'inbound' => 'I was not comfortable with today\'s instructor and want someone else for my next lesson.',
                'outbound' => 'I am sorry about that experience. I have escalated this to the operations lead and paused your next lesson until they review it.',
                'followup' => 'Thank you. I would prefer a female instructor if possible.',
            ],
            'test_prep' => [
                'subject' => 'NTSA test prep',
                'inbound' => 'My driving test is next Friday. Can I do two mock test sessions before then?',
                'outbound' => 'Yes. I found two mock-test windows this week and created a test-prep task for your instructor.',
                'followup' => 'Book both. I want to practice parking and hill start.',
            ],
        ];

        foreach (array_slice($contacts, 0, 34) as $index => $contact) {
            $channel = $index % 2 === 0 ? 'whatsapp' : 'email';
            $scenarioKey = array_keys($templates)[$index % count($templates)];
            $template = $templates[$scenarioKey];
            $threadKey = 'metrodrive-public-' . ($index + 1) . '-' . $channel;
            $priority = $scenarioKey === 'escalation' ? 'urgent' : ($index % 3 === 0 ? 'high' : 'medium');
            $isEscalated = $scenarioKey === 'escalation';

            Database::execute(
                "INSERT INTO conversation_threads
                    (workspace_id, demo_visibility, demo_session_id, contact_id, channel, thread_key, last_message_at,
                     status, current_owner_id, priority, response_due_at, last_inbound_at, last_outbound_at, last_channel,
                     unresolved_item_count, escalation_status, metadata_json, message_count)
                 VALUES (?, 'public_seed', NULL, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? MINUTE),
                         'open', ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), DATE_SUB(NOW(), INTERVAL ? MINUTE),
                         DATE_SUB(NOW(), INTERVAL ? MINUTE), ?, ?, ?, ?, 3)",
                [
                    $workspaceId,
                    (int) $contact['id'],
                    $channel,
                    $threadKey,
                    18 + $index,
                    $actorUserId,
                    $priority,
                    4 + ($index % 8),
                    28 + $index,
                    22 + $index,
                    $channel,
                    $isEscalated ? 2 : 1,
                    $isEscalated ? 'needs_human_review' : null,
                    json_encode(['source' => self::SOURCE, 'scenario' => $scenarioKey, 'package' => $contact['package']], JSON_UNESCAPED_SLASHES),
                ]
            );
            $threadCount++;

            $messages = [
                ['direction' => 'inbound', 'body' => $template['inbound'], 'minutes' => 34 + $index, 'priority' => $priority],
                ['direction' => 'outbound', 'body' => $template['outbound'], 'minutes' => 26 + $index, 'priority' => 'medium'],
                ['direction' => 'inbound', 'body' => $template['followup'], 'minutes' => 18 + $index, 'priority' => $priority],
            ];

            foreach ($messages as $messageIndex => $message) {
                $communicationId = $this->insertCommunication(
                    $workspaceId,
                    (int) $contact['id'],
                    $threadKey,
                    $channel,
                    (string) $message['direction'],
                    (string) $template['subject'],
                    str_replace('MetroDrive', 'MetroDrive Academy', (string) $message['body']),
                    (string) $message['priority'],
                    $scenarioKey,
                    (int) $message['minutes'],
                    $contact
                );
                $communicationCount++;
                $artifactCount += $this->insertChannelArtifact($workspaceId, $actorUserId, $contact, $channel, (string) $message['direction'], (string) $template['subject'], (string) $message['body'], $communicationId, (int) $message['minutes']);
            }
        }

        return ['threads' => $threadCount, 'communications' => $communicationCount, 'channel_artifacts' => $artifactCount];
    }

    /**
     * @param array<string,mixed> $contact
     */
    private function insertCommunication(
        int $workspaceId,
        int $contactId,
        string $threadKey,
        string $channel,
        string $direction,
        string $subject,
        string $body,
        string $priority,
        string $scenarioKey,
        int $minutesAgo,
        array $contact
    ): int {
        Database::execute(
            "INSERT INTO communications
                (workspace_id, demo_visibility, demo_session_id, uuid, contact_id, thread_key, channel, direction, subject, body,
                 metadata, status, read_at, triage_priority, triage_score, triage_confidence, triage_status, triage_reason_codes,
                 triage_decided_at, from_email, to_email, message_id, created_at)
             VALUES (?, 'public_seed', NULL, ?, ?, ?, ?, ?, ?, ?, ?, 'delivered',
                     CASE WHEN ? = 'inbound' THEN NULL ELSE DATE_SUB(NOW(), INTERVAL ? MINUTE) END,
                     ?, ?, ?, 'suggested', ?, DATE_SUB(NOW(), INTERVAL ? MINUTE), ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? MINUTE))",
            [
                $workspaceId,
                $this->uuid(),
                $contactId,
                $threadKey,
                $channel,
                $direction,
                $subject,
                $body,
                json_encode(['source' => self::SOURCE, 'scenario' => $scenarioKey, 'package' => $contact['package']], JSON_UNESCAPED_SLASHES),
                $direction,
                max(1, $minutesAgo - 1),
                $priority,
                $priority === 'urgent' ? 96.0 : 86.0,
                $priority === 'urgent' ? 94.0 : 89.0,
                json_encode([$scenarioKey, 'metrodrive_public_seed'], JSON_UNESCAPED_SLASHES),
                max(1, $minutesAgo - 1),
                $direction === 'inbound' ? (string) $contact['email'] : 'hello@metrodrive.demo.local',
                $direction === 'inbound' ? 'hello@metrodrive.demo.local' : (string) $contact['email'],
                'metrodrive-' . $channel . '-' . bin2hex(random_bytes(6)) . '@demo.local',
                $minutesAgo,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param array<string,mixed> $contact
     */
    private function insertChannelArtifact(
        int $workspaceId,
        int $actorUserId,
        array $contact,
        string $channel,
        string $direction,
        string $subject,
        string $body,
        int $communicationId,
        int $minutesAgo
    ): int {
        if ($channel === 'email' && Database::tableExists('emails')) {
            Database::execute(
                "INSERT INTO emails
                    (workspace_id, demo_visibility, demo_session_id, uuid, contact_id, user_id, to_email, from_email, from_name,
                     sender_profile, subject, body, status, sent_at, delivered_at, message_id, created_at)
                 VALUES (?, 'public_seed', NULL, ?, ?, ?, ?, ?, 'MetroDrive Academy', 'assistant', ?, ?, 'delivered',
                         DATE_SUB(NOW(), INTERVAL ? MINUTE), DATE_SUB(NOW(), INTERVAL ? MINUTE), ?, DATE_SUB(NOW(), INTERVAL ? MINUTE))",
                [
                    $workspaceId,
                    $this->uuid(),
                    (int) $contact['id'],
                    $actorUserId,
                    $direction === 'inbound' ? 'hello@metrodrive.demo.local' : (string) $contact['email'],
                    $direction === 'inbound' ? (string) $contact['email'] : 'hello@metrodrive.demo.local',
                    $subject,
                    $body,
                    $minutesAgo,
                    max(1, $minutesAgo - 1),
                    'metrodrive-email-' . $communicationId,
                    $minutesAgo,
                ]
            );
            return 1;
        }

        if ($channel === 'whatsapp' && Database::tableExists('whatsapp_messages')) {
            Database::execute(
                "INSERT INTO whatsapp_messages
                    (workspace_id, demo_visibility, demo_session_id, uuid, contact_id, user_id, to_number, from_number,
                     message_type, message_body, whatsapp_message_id, status, sent_at, delivered_at, direction, created_at)
                 VALUES (?, 'public_seed', NULL, ?, ?, ?, ?, ?, 'text', ?, ?, 'delivered',
                         DATE_SUB(NOW(), INTERVAL ? MINUTE), DATE_SUB(NOW(), INTERVAL ? MINUTE), ?, DATE_SUB(NOW(), INTERVAL ? MINUTE))",
                [
                    $workspaceId,
                    $this->uuid(),
                    (int) $contact['id'],
                    $actorUserId,
                    $direction === 'inbound' ? '254700000475' : (string) $contact['phone'],
                    $direction === 'inbound' ? (string) $contact['phone'] : '254700000475',
                    $body,
                    'metrodrive-wa-' . $communicationId,
                    $minutesAgo,
                    max(1, $minutesAgo - 1),
                    $direction,
                    $minutesAgo,
                ]
            );
            return 1;
        }

        return 0;
    }

    /**
     * @param array<int,array<string,mixed>> $contacts
     */
    private function seedTasks(int $workspaceId, int $actorUserId, array $contacts): int
    {
        $tasks = [
            ['Collect ID photo and eCitizen screenshot', 'high', '+4 hours'],
            ['Confirm beginner package deposit', 'medium', '+6 hours'],
            ['Assign instructor for first practical lesson', 'high', '+1 day'],
            ['Review instructor complaint before next lesson', 'urgent', '+2 hours'],
            ['Send mock test schedule', 'high', '+5 hours'],
            ['Prepare weekly owner digest summary', 'medium', '+1 day'],
            ['Call parent about guardian consent form', 'medium', '+2 days'],
            ['Reconcile payment confirmation', 'high', '+8 hours'],
        ];

        $created = 0;
        foreach (array_slice($contacts, 0, 32) as $index => $contact) {
            $task = $tasks[$index % count($tasks)];
            $due = $this->mysqlRelativeDate((string) $task[2]);
            Database::execute(
                "INSERT INTO tasks
                    (workspace_id, demo_visibility, demo_session_id, title, description, metadata_json, source_surface,
                     contact_id, assigned_to, created_by, status, priority, due_date, created_at, updated_at)
                 VALUES (?, 'public_seed', NULL, ?, ?, ?, 'metrodrive_demo', ?, ?, ?, ?, ?, {$due}, DATE_SUB(NOW(), INTERVAL ? DAY), NOW())",
                [
                    $workspaceId,
                    (string) $task[0] . ' - ' . (string) $contact['first_name'],
                    'MetroDrive Academy demo task for ' . $contact['package'] . ' at ' . $contact['branch'] . '.',
                    json_encode(['source' => self::SOURCE, 'package' => $contact['package'], 'branch' => $contact['branch']], JSON_UNESCAPED_SLASHES),
                    (int) $contact['id'],
                    $actorUserId,
                    $actorUserId,
                    $index % 6 === 0 ? 'in_progress' : 'pending',
                    (string) $task[1],
                    max(0, $index % 9),
                ]
            );
            $created++;
        }

        return $created;
    }

    /**
     * @param array<int,array<string,mixed>> $contacts
     * @return array<int,array<string,mixed>>
     */
    private function seedDeals(int $workspaceId, int $actorUserId, array $contacts): array
    {
        $stageMap = ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
        $values = [
            'Beginner 20-lesson package' => 42000,
            'Refresher 5-lesson pack' => 14500,
            'NTSA test-prep sprint' => 18500,
            'Defensive driving course' => 26000,
            'Corporate fleet training' => 180000,
        ];
        $deals = [];

        foreach (array_slice($contacts, 0, 30) as $index => $contact) {
            $stage = $stageMap[$index % count($stageMap)];
            $value = (float) ($values[(string) $contact['package']] ?? 25000);
            Database::execute(
                "INSERT INTO deals
                    (workspace_id, demo_visibility, demo_session_id, title, description, contact_id, assigned_to, created_by,
                     stage, value, probability, expected_close_date, actual_close_date, currency, lead_source, tags,
                     custom_fields, created_at, updated_at)
                 VALUES (?, 'public_seed', NULL, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL ? DAY),
                         ?, 'KES', ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY), NOW())",
                [
                    $workspaceId,
                    (string) $contact['package'] . ' for ' . (string) $contact['first_name'] . ' ' . (string) $contact['last_name'],
                    'Structured driving-school opportunity seeded for the MetroDrive presentation demo.',
                    (int) $contact['id'],
                    $actorUserId,
                    $actorUserId,
                    $stage,
                    $value,
                    min(95, 30 + ($index % 7) * 10),
                    2 + ($index % 20),
                    $stage === 'closed_won' ? date('Y-m-d', strtotime('-' . (1 + ($index % 5)) . ' days')) : null,
                    (string) $contact['source'],
                    json_encode(['metrodrive', strtolower(str_replace(' ', '_', (string) $contact['package']))], JSON_UNESCAPED_SLASHES),
                    json_encode(['source' => self::SOURCE, 'branch' => $contact['branch'], 'lesson_package' => $contact['package']], JSON_UNESCAPED_SLASHES),
                    18 - ($index % 12),
                ]
            );
            $deals[] = ['id' => (int) Database::lastInsertId(), 'contact' => $contact, 'value' => $value, 'stage' => $stage];
        }

        return $deals;
    }

    /**
     * @param array<int,array<string,mixed>> $contacts
     */
    private function seedActivities(int $workspaceId, int $actorUserId, array $contacts): int
    {
        $types = ['note', 'call', 'meeting', 'status_change', 'email'];
        $created = 0;
        foreach ($contacts as $index => $contact) {
            Database::execute(
                "INSERT INTO activities
                    (workspace_id, demo_visibility, demo_session_id, contact_id, user_id, activity_type, description, metadata, created_at)
                 VALUES (?, 'public_seed', NULL, ?, ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? HOUR))",
                [
                    $workspaceId,
                    (int) $contact['id'],
                    $actorUserId,
                    $types[$index % count($types)],
                    'MetroDrive follow-up activity for ' . $contact['package'] . ' at ' . $contact['branch'] . '.',
                    json_encode(['source' => self::SOURCE, 'branch' => $contact['branch']], JSON_UNESCAPED_SLASHES),
                    2 + ($index % 72),
                ]
            );
            $created++;
        }

        return $created;
    }

    /**
     * @param array<int,array<string,mixed>> $contacts
     * @param array<int,array<string,mixed>> $deals
     */
    private function seedInvoices(int $workspaceId, int $actorUserId, array $contacts, array $deals): int
    {
        if (!Database::tableExists('invoices')) {
            return 0;
        }

        $created = 0;
        foreach (array_slice($deals, 0, 10) as $index => $deal) {
            $contact = (array) ($deal['contact'] ?? $contacts[$index] ?? []);
            $total = (float) ($deal['value'] ?? 25000);
            $paid = $index % 3 === 0 ? $total : ($index % 3 === 1 ? round($total * 0.4, 2) : 0.0);
            $status = $paid >= $total ? 'paid' : ($paid > 0 ? 'partially_paid' : ($index % 4 === 0 ? 'overdue' : 'sent'));
            Database::execute(
                "INSERT INTO invoices
                    (workspace_id, document_type, status, invoice_number, deal_id, contact_id, assigned_to, created_by, currency,
                     issue_date, due_date, payment_terms_days, subtotal, discount_total, tax_total, grand_total, amount_paid,
                     balance_due, tax_mode, title, intro_text, notes, terms, billing_name, billing_email, billing_phone,
                     source_snapshot_json, last_sent_at, paid_at, created_at, updated_at)
                 VALUES (?, 'invoice', ?, ?, ?, ?, ?, ?, 'KES', DATE_SUB(CURDATE(), INTERVAL ? DAY),
                         DATE_ADD(CURDATE(), INTERVAL ? DAY), 7, ?, 0, 0, ?, ?, ?, 'none', ?, ?, ?, ?,
                         ?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY), ?, DATE_SUB(NOW(), INTERVAL ? DAY), NOW())",
                [
                    $workspaceId,
                    $status,
                    'MDA-' . $workspaceId . '-' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT),
                    (int) ($deal['id'] ?? 0),
                    (int) ($contact['id'] ?? 0),
                    $actorUserId,
                    $actorUserId,
                    7 + $index,
                    3 + ($index % 10),
                    $total,
                    $total,
                    $paid,
                    max(0, $total - $paid),
                    (string) ($contact['package'] ?? 'Driving lessons'),
                    'Thank you for choosing MetroDrive Academy.',
                    'Seeded invoice for presentation reporting and payment follow-up.',
                    'Payment due before the next practical lesson unless an approved plan is recorded.',
                    trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? '')),
                    (string) ($contact['email'] ?? ''),
                    (string) ($contact['phone'] ?? ''),
                    json_encode(['source' => self::SOURCE, 'deal_stage' => $deal['stage'] ?? 'proposal'], JSON_UNESCAPED_SLASHES),
                    5 + ($index % 5),
                    $status === 'paid' ? date('Y-m-d H:i:s', strtotime('-' . (1 + $index) . ' days')) : null,
                    8 + $index,
                ]
            );
            $created++;
        }

        return $created;
    }

    private function seedReports(int $workspaceId, int $actorUserId): int
    {
        if (!Database::tableExists('reports')) {
            return 0;
        }

        $definitions = [
            ['MetroDrive Weekly Lead Movement', 'contacts', 'Weekly lead volume, source mix, and conversion movement.'],
            ['MetroDrive Instructor Utilization', 'tasks', 'Upcoming lesson tasks and instructor follow-up load.'],
            ['MetroDrive Payments and Escalations', 'sales', 'Open balances, payment reminders, and human-review items.'],
        ];
        $created = 0;
        foreach ($definitions as $index => $definition) {
            Database::execute(
                "INSERT INTO reports
                    (workspace_id, name, description, report_type, query_config, filters, columns, chart_config, is_template,
                     is_scheduled, schedule_config, created_by, is_public, created_at, updated_at, last_run_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?, ?, 1, DATE_SUB(NOW(), INTERVAL ? DAY), NOW(), DATE_SUB(NOW(), INTERVAL ? HOUR))",
                [
                    $workspaceId,
                    $definition[0],
                    $definition[2],
                    $definition[1],
                    json_encode(['source' => self::SOURCE, 'workspace_profile' => 'metrodrive'], JSON_UNESCAPED_SLASHES),
                    json_encode(['date_range' => 'last_7_days'], JSON_UNESCAPED_SLASHES),
                    json_encode(['name', 'stage', 'source', 'owner', 'next_action'], JSON_UNESCAPED_SLASHES),
                    json_encode(['type' => $index === 0 ? 'line' : 'bar'], JSON_UNESCAPED_SLASHES),
                    json_encode(['cadence' => $index === 2 ? 'monthly' : 'weekly', 'source' => self::SOURCE], JSON_UNESCAPED_SLASHES),
                    $actorUserId,
                    7 - $index,
                    2 + $index,
                ]
            );
            $reportId = (int) Database::lastInsertId();
            $created++;

            if (Database::tableExists('scheduled_reports')) {
                Database::execute(
                    "INSERT INTO scheduled_reports
                        (workspace_id, report_id, schedule_name, schedule_type, schedule_config, recipients, format,
                         is_active, last_run_at, next_run_at, created_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, 'pdf', 1, DATE_SUB(NOW(), INTERVAL ? DAY), DATE_ADD(NOW(), INTERVAL ? DAY), ?, NOW(), NOW())",
                    [
                        $workspaceId,
                        $reportId,
                        $definition[0] . ' Digest',
                        $index === 2 ? 'monthly' : 'weekly',
                        json_encode(['day' => $index === 2 ? 'first_monday' : 'monday', 'time' => '07:30', 'source' => self::SOURCE], JSON_UNESCAPED_SLASHES),
                        json_encode(['owner@metrodrive.demo.local'], JSON_UNESCAPED_SLASHES),
                        3 + $index,
                        2 + $index,
                        $actorUserId,
                    ]
                );
            }
        }

        return $created;
    }

    private function ensureAssistantDigestConfig(int $workspaceId, int $actorUserId): void
    {
        if (!Database::tableExists('workspace_assistant_configs')) {
            return;
        }

        $configs = new WorkspaceAssistantConfigService();
        $configs->save($workspaceId, 'email', [
            'digest_enabled' => '1',
            'digest_time' => '07:30',
            'digest_recipients' => 'admins',
            'from_email' => 'hello@metrodrive.demo.local',
            'from_name' => 'MetroDrive Academy',
        ], true, $actorUserId);

        $configs->save($workspaceId, 'whatsapp', [
            'enabled' => '1',
            'digest_enabled' => '1',
            'digest_time' => '07:35',
            'assistant_phone_number' => '+254700000475',
            'max_message_chars' => '550',
            'max_message_chunks' => '4',
            'auto_reopen_enabled' => '0',
        ], true, $actorUserId);
    }

    private function resolveActorUserId(int $workspaceId, int $actorUserId): int
    {
        if ($actorUserId > 0) {
            return $actorUserId;
        }

        $membership = Database::queryOne(
            "SELECT user_id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
             ORDER BY is_owner DESC, id ASC
             LIMIT 1",
            [$workspaceId]
        );
        if (!empty($membership['user_id'])) {
            return (int) $membership['user_id'];
        }

        $user = Database::queryOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
        if (!empty($user['id'])) {
            return (int) $user['id'];
        }

        throw new \RuntimeException('MetroDrive seed requires at least one user for ownership fields.');
    }

    private function mysqlRelativeDate(string $relative): string
    {
        if (preg_match('/^\+(\d+)\s+hours?$/', $relative, $m)) {
            return 'DATE_ADD(NOW(), INTERVAL ' . max(1, (int) $m[1]) . ' HOUR)';
        }
        if (preg_match('/^\+(\d+)\s+days?$/', $relative, $m)) {
            return 'DATE_ADD(NOW(), INTERVAL ' . max(1, (int) $m[1]) . ' DAY)';
        }

        return 'DATE_ADD(NOW(), INTERVAL 1 DAY)';
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
