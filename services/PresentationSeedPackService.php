<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class PresentationSeedPackService
{
    private const SOURCE = 'presentation_seed_pack_v1';

    /** @var array<string,array<string,array<string,mixed>>> */
    private array $columnCache = [];

    /**
     * @return array<string,array<string,mixed>>
     */
    public function catalog(): array
    {
        return [
            'solo_founder' => [
                'label' => 'Solo Founder Operating Rhythm',
                'audience' => 'Solo founders and early-stage builders',
                'company_name' => 'WebXpanse Demo Studio',
                'industry' => 'Founder productivity software',
                'summary' => 'A complete founder context, one evidence-backed constraint, a measurable weekly commitment, and a safe AI handoff through Clarity.',
                'from_email' => 'founder@webxpanse.example',
                'from_phone' => '254711020300',
                'currency' => 'USD',
                'founder_story' => true,
                'services' => [
                    ['name' => 'Clarity Sprint', 'category' => 'Pilot', 'price' => 99, 'description' => 'A low-risk paid pilot that turns scattered business context into one measurable next move.'],
                    ['name' => 'Solo Founder Launch', 'category' => 'Launch', 'price' => 299, 'description' => 'A 30-day operating rhythm for customer evidence, follow-up, and weekly decisions.'],
                    ['name' => 'Founder Plus', 'category' => 'Growth', 'price' => 499, 'description' => 'A shared operating workspace for a founder and first collaborators.'],
                ],
                'scenario_subjects' => [
                    'new_lead' => 'Review the strongest founder constraint',
                    'qualification' => 'Qualifying a paid founder pilot',
                    'booking_or_meeting' => 'Send first 20 warm outreach messages',
                    'document_followup' => 'Capture buyer evidence',
                    'payment_reminder' => 'Follow up on the paid pilot decision',
                    'email_digest' => 'WebXpanse founder digest',
                    'whatsapp_digest' => 'WebXpanse WhatsApp founder digest',
                    'human_escalation' => 'Review an automation exception',
                    'report_snapshot' => 'Founder operating snapshot ready',
                ],
                'segments' => ['solo founder', 'side-hustle builder', 'consultant', 'micro-business owner', 'first-time founder'],
            ],
            'sales_pipeline' => [
                'label' => 'Sales Pipeline',
                'audience' => 'Sales-led teams',
                'company_name' => 'Northstar Solar Solutions',
                'industry' => 'Renewable energy sales',
                'summary' => 'Leads, deals, follow-ups, proposals, and payment reminders for a high-touch sales pipeline.',
                'from_email' => 'hello@northstar.example',
                'from_phone' => '254711020301',
                'currency' => 'KES',
                'services' => [
                    ['name' => 'Home Solar Assessment', 'category' => 'Assessment', 'price' => 8500, 'description' => 'Site visit, load profile, and battery sizing recommendation.'],
                    ['name' => 'Hybrid Solar Starter Kit', 'category' => 'Package', 'price' => 185000, 'description' => 'Panels, inverter, batteries, installation, and commissioning.'],
                    ['name' => 'Commercial Energy Audit', 'category' => 'B2B', 'price' => 45000, 'description' => 'Usage analysis and ROI proposal for small commercial sites.'],
                    ['name' => 'Maintenance Plan', 'category' => 'Recurring', 'price' => 12000, 'description' => 'Quarterly inspection, cleaning, and warranty checks.'],
                    ['name' => 'Financing Follow-up', 'category' => 'Workflow', 'price' => 0, 'description' => 'Structured follow-up for deposit, financing, and document readiness.'],
                ],
                'scenario_subjects' => [
                    'new_lead' => 'Solar assessment inquiry',
                    'qualification' => 'Qualifying energy use and budget',
                    'booking_or_meeting' => 'Solar site assessment confirmed',
                    'document_followup' => 'Missing ID and KRA PIN for financing',
                    'payment_reminder' => 'Deposit reminder for installation slot',
                    'email_digest' => 'Northstar owner digest',
                    'whatsapp_digest' => 'Northstar WhatsApp digest',
                    'human_escalation' => 'Financing complaint needs human review',
                    'report_snapshot' => 'Pipeline report snapshot ready',
                ],
                'segments' => ['homeowner', 'landlord', 'small business', 'property manager', 'school administrator'],
            ],
            'service_business' => [
                'label' => 'Service Business',
                'audience' => 'Booking and repeat-service teams',
                'company_name' => 'UrbanFix Appliance Care',
                'industry' => 'Appliance repair and maintenance',
                'summary' => 'Bookings, support issues, invoices, repeat customers, reminders, and complaint escalation.',
                'from_email' => 'support@urbanfix.example',
                'from_phone' => '254711020302',
                'currency' => 'KES',
                'services' => [
                    ['name' => 'Diagnostic Visit', 'category' => 'Booking', 'price' => 2500, 'description' => 'Technician diagnosis and repair estimate.'],
                    ['name' => 'Fridge Repair', 'category' => 'Repair', 'price' => 14500, 'description' => 'Compressor, thermostat, fan, and leak repair support.'],
                    ['name' => 'Washer Repair', 'category' => 'Repair', 'price' => 9500, 'description' => 'Drum, pump, drainage, and electrical repairs.'],
                    ['name' => 'Annual Maintenance', 'category' => 'Recurring', 'price' => 18000, 'description' => 'Preventive inspection and priority booking bundle.'],
                    ['name' => 'Landlord Fleet Support', 'category' => 'B2B', 'price' => 60000, 'description' => 'Recurring maintenance for managed apartments.'],
                ],
                'scenario_subjects' => [
                    'new_lead' => 'Urgent appliance repair inquiry',
                    'qualification' => 'Confirming appliance model and fault',
                    'booking_or_meeting' => 'Technician visit confirmed',
                    'document_followup' => 'Warranty card and appliance photo needed',
                    'payment_reminder' => 'Repair invoice payment reminder',
                    'email_digest' => 'UrbanFix owner digest',
                    'whatsapp_digest' => 'UrbanFix WhatsApp digest',
                    'human_escalation' => 'Repeat fault complaint needs human review',
                    'report_snapshot' => 'Service operations report ready',
                ],
                'segments' => ['homeowner', 'tenant', 'landlord', 'office manager', 'restaurant operator'],
            ],
            'education_training' => [
                'label' => 'Education And Training',
                'audience' => 'Schools, academies, and training providers',
                'company_name' => 'Summit Skills Institute',
                'industry' => 'Professional training',
                'summary' => 'Inquiries, scheduling, documents, payments, parent or guardian concerns, digests, and reports.',
                'from_email' => 'admissions@summit-skills.example',
                'from_phone' => '254711020303',
                'currency' => 'KES',
                'services' => [
                    ['name' => 'Beginner Certification', 'category' => 'Course', 'price' => 38000, 'description' => 'Four-week starter course with practical assessments.'],
                    ['name' => 'Weekend Refresher', 'category' => 'Course', 'price' => 12000, 'description' => 'Two-day refresher for returning learners.'],
                    ['name' => 'Exam Prep Bootcamp', 'category' => 'Course', 'price' => 22000, 'description' => 'Focused preparation, mock exams, and coaching.'],
                    ['name' => 'Corporate Team Training', 'category' => 'B2B', 'price' => 150000, 'description' => 'Custom training for company teams.'],
                    ['name' => 'Document Readiness Check', 'category' => 'Workflow', 'price' => 0, 'description' => 'Checklist for ID, registration, consent, and payment evidence.'],
                ],
                'scenario_subjects' => [
                    'new_lead' => 'Course inquiry from learner',
                    'qualification' => 'Confirming course fit and schedule',
                    'booking_or_meeting' => 'Orientation session confirmed',
                    'document_followup' => 'Missing ID and registration form',
                    'payment_reminder' => 'Tuition balance reminder',
                    'email_digest' => 'Summit Skills owner digest',
                    'whatsapp_digest' => 'Summit Skills WhatsApp digest',
                    'human_escalation' => 'Parent concern needs human review',
                    'report_snapshot' => 'Enrollment report ready',
                ],
                'segments' => ['student', 'parent', 'guardian', 'HR manager', 'school coordinator'],
            ],
            'professional_services' => [
                'label' => 'Professional Services',
                'audience' => 'Consultants, advisors, and service firms',
                'company_name' => 'LedgerWise Advisory',
                'industry' => 'Accounting and advisory services',
                'summary' => 'Consultations, proposals, document requests, client follow-ups, invoices, and escalation context.',
                'from_email' => 'partners@ledgerwise.example',
                'from_phone' => '254711020304',
                'currency' => 'KES',
                'services' => [
                    ['name' => 'Compliance Health Check', 'category' => 'Consultation', 'price' => 25000, 'description' => 'Tax, payroll, and statutory compliance review.'],
                    ['name' => 'Monthly Bookkeeping', 'category' => 'Retainer', 'price' => 55000, 'description' => 'Monthly accounts, reconciliations, and management reports.'],
                    ['name' => 'Funding Readiness Pack', 'category' => 'Advisory', 'price' => 90000, 'description' => 'Investor documents, forecasts, and due diligence checklist.'],
                    ['name' => 'Payroll Setup', 'category' => 'Implementation', 'price' => 40000, 'description' => 'Payroll configuration and compliance setup.'],
                    ['name' => 'Board Reporting', 'category' => 'Retainer', 'price' => 75000, 'description' => 'Monthly board pack and commentary.'],
                ],
                'scenario_subjects' => [
                    'new_lead' => 'Advisory consultation inquiry',
                    'qualification' => 'Scoping advisory needs',
                    'booking_or_meeting' => 'Consultation meeting confirmed',
                    'document_followup' => 'Missing statements and KRA PIN',
                    'payment_reminder' => 'Retainer invoice reminder',
                    'email_digest' => 'LedgerWise partner digest',
                    'whatsapp_digest' => 'LedgerWise WhatsApp digest',
                    'human_escalation' => 'Scope dispute needs partner review',
                    'report_snapshot' => 'Client portfolio report ready',
                ],
                'segments' => ['founder', 'finance manager', 'director', 'operations lead', 'investor relations lead'],
            ],
            'support_operations' => [
                'label' => 'Support Operations',
                'audience' => 'Support, success, and operations teams',
                'company_name' => 'CloudCare Support Desk',
                'industry' => 'B2B SaaS support',
                'summary' => 'Inbox triage, SLA tasks, escalations, owner digests, customer health, and report-ready metrics.',
                'from_email' => 'care@cloudcare.example',
                'from_phone' => '254711020305',
                'currency' => 'USD',
                'services' => [
                    ['name' => 'Priority Support Plan', 'category' => 'Support', 'price' => 450, 'description' => 'Priority queue, SLA monitoring, and escalation routing.'],
                    ['name' => 'Customer Success Review', 'category' => 'Success', 'price' => 300, 'description' => 'Monthly adoption and risk review.'],
                    ['name' => 'Implementation Desk', 'category' => 'Onboarding', 'price' => 1200, 'description' => 'Guided setup and migration support.'],
                    ['name' => 'Incident Response Retainer', 'category' => 'Support', 'price' => 900, 'description' => 'Critical incident response and reporting.'],
                    ['name' => 'SLA Digest Automation', 'category' => 'Workflow', 'price' => 0, 'description' => 'Digest and escalation workflow for open tickets.'],
                ],
                'scenario_subjects' => [
                    'new_lead' => 'Priority support inquiry',
                    'qualification' => 'Qualifying support scope and SLA',
                    'booking_or_meeting' => 'Success review confirmed',
                    'document_followup' => 'Missing admin access details',
                    'payment_reminder' => 'Support retainer renewal reminder',
                    'email_digest' => 'CloudCare support digest',
                    'whatsapp_digest' => 'CloudCare WhatsApp digest',
                    'human_escalation' => 'SLA breach complaint needs review',
                    'report_snapshot' => 'Support operations report ready',
                ],
                'segments' => ['admin', 'operations manager', 'support lead', 'customer success manager', 'founder'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function pack(string $packKey): array
    {
        $catalog = $this->catalog();
        $packKey = trim($packKey) !== '' ? trim($packKey) : 'sales_pipeline';
        if (!isset($catalog[$packKey])) {
            throw new \InvalidArgumentException('Unknown presentation seed pack.');
        }

        return ['key' => $packKey] + $catalog[$packKey];
    }

    /**
     * @return array<string,array<string,string>>
     */
    public function scenarioCatalog(?string $packKey = null): array
    {
        $pack = $this->pack($packKey ?: 'sales_pipeline');
        $subjects = (array) ($pack['scenario_subjects'] ?? []);
        $defaults = [
            'new_lead' => ['label' => 'New Lead', 'channel' => 'whatsapp', 'summary' => 'Create a realistic inbound lead and follow-up trail.'],
            'qualification' => ['label' => 'Qualification', 'channel' => 'email', 'summary' => 'Show AI extracting fit, urgency, budget, and next action.'],
            'booking_or_meeting' => ['label' => 'Booking Or Meeting', 'channel' => 'email', 'summary' => 'Confirm a meeting or booking and create the related task.'],
            'document_followup' => ['label' => 'Document Follow-up', 'channel' => 'whatsapp', 'summary' => 'Ask for missing documents with a clear checklist.'],
            'payment_reminder' => ['label' => 'Payment Reminder', 'channel' => 'email', 'summary' => 'Send or simulate a polite balance reminder.'],
            'email_digest' => ['label' => 'Email Digest', 'channel' => 'email', 'summary' => 'Owner digest with tasks, risks, pipeline, and report context.'],
            'whatsapp_digest' => ['label' => 'WhatsApp Digest', 'channel' => 'whatsapp', 'summary' => 'Concise WhatsApp digest for the same signals.'],
            'human_escalation' => ['label' => 'Human Escalation', 'channel' => 'whatsapp', 'summary' => 'Route sensitive, high-confidence-but-human issue to review.'],
            'report_snapshot' => ['label' => 'Report Snapshot', 'channel' => 'email', 'summary' => 'Create a report-ready notification and audit trail.'],
        ];

        foreach ($defaults as $key => &$scenario) {
            $scenario['subject'] = (string) ($subjects[$key] ?? $scenario['label']);
            $scenario['pack_label'] = (string) ($pack['label'] ?? '');
        }
        unset($scenario);

        return $defaults;
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,string>
     */
    public function scenarioMessage(string $packKey, string $scenarioKey, array $context = []): array
    {
        $pack = $this->pack($packKey);
        $subjects = (array) ($pack['scenario_subjects'] ?? []);
        $companyName = (string) ($pack['company_name'] ?? 'Presentation Workspace');
        $guestName = trim((string) ($context['name'] ?? 'there')) ?: 'there';
        $prospectCompany = trim((string) ($context['company'] ?? ''));
        $companyLine = $prospectCompany !== '' ? ' for ' . $prospectCompany : '';
        $service = (array) (($pack['services'] ?? [])[0] ?? ['name' => 'starter service']);

        return match ($scenarioKey) {
            'new_lead' => [
                'channel' => 'whatsapp',
                'subject' => (string) ($subjects['new_lead'] ?? 'New lead inquiry'),
                'body' => "Hi {$companyName}, I am interested in {$service['name']}{$companyLine}. Can you share pricing, availability, and the next step?",
            ],
            'qualification' => [
                'channel' => 'email',
                'subject' => (string) ($subjects['qualification'] ?? 'Qualification follow-up'),
                'body' => "Hi {$guestName},\n\nThanks for the inquiry. I captured the key qualification points: timeline, budget range, preferred channel, and decision owner. The workspace now has a contact, deal, follow-up task, and triage note so the presenter can show how high-confidence issues are handled automatically.\n\nReply with any missing detail and the team will pick it up.",
            ],
            'booking_or_meeting' => [
                'channel' => 'email',
                'subject' => (string) ($subjects['booking_or_meeting'] ?? 'Booking confirmed'),
                'body' => "Hi {$guestName},\n\nYour {$companyName} session is confirmed for the next available slot. The system created the task, contact timeline, deal movement, and reminder context in the workspace.\n\nThe team can still intervene if anything looks unusual.",
            ],
            'document_followup' => [
                'channel' => 'whatsapp',
                'subject' => (string) ($subjects['document_followup'] ?? 'Missing documents'),
                'body' => "{$companyName}: your file is almost ready. Please send the missing document/photo and any payment evidence so the team can keep the next step on schedule.",
            ],
            'payment_reminder' => [
                'channel' => 'email',
                'subject' => (string) ($subjects['payment_reminder'] ?? 'Payment reminder'),
                'body' => "Hi {$guestName},\n\nFriendly reminder that the outstanding balance is due before the next milestone. The workspace has the invoice, contact history, and follow-up task ready for review.\n\nIf this needs human handling, reply here and it will be escalated.",
            ],
            'email_digest' => [
                'channel' => 'email',
                'subject' => (string) ($subjects['email_digest'] ?? 'Owner digest'),
                'body' => "{$companyName} owner digest\n\nHandled automatically:\n- 18 messages triaged\n- 7 follow-ups prepared\n- 5 records updated\n- 4 deals moved forward\n\nNeeds human review:\n- 2 sensitive escalations\n- 1 payment dispute\n\nReports ready:\n- Pipeline movement\n- Response SLA\n- Follow-up completion\n- Revenue risk",
            ],
            'whatsapp_digest' => [
                'channel' => 'whatsapp',
                'subject' => (string) ($subjects['whatsapp_digest'] ?? 'WhatsApp digest'),
                'body' => "{$companyName} digest: 18 messages triaged, 7 follow-ups prepared, 4 deals moved. Human review: 2 escalations + 1 payment dispute. Reports are ready.",
            ],
            'human_escalation' => [
                'channel' => 'whatsapp',
                'subject' => (string) ($subjects['human_escalation'] ?? 'Human review needed'),
                'body' => "I am unhappy with the last interaction and want a manager to review this before anything else happens.",
            ],
            'report_snapshot' => [
                'channel' => 'email',
                'subject' => (string) ($subjects['report_snapshot'] ?? 'Report snapshot ready'),
                'body' => "{$companyName} report snapshot\n\nWeekly view:\n- Response speed improved 24%\n- Open follow-ups down 18%\n- High-confidence automation handled routine issues\n- Human review stayed focused on sensitive cases\n\nMonthly view:\n- Best-performing segment: " . (string) (($pack['segments'] ?? ['priority leads'])[0] ?? 'priority leads') . "\n- Biggest risk: delayed documents and payment exceptions\n- Recommended action: review escalations and unblock the oldest open tasks",
            ],
            default => [
                'channel' => 'email',
                'subject' => 'Presentation scenario',
                'body' => 'Presentation scenario is ready for review.',
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    public function seed(int $workspaceId, int $actorUserId, string $packKey, ?int $sessionId = null, bool $reset = false): array
    {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('Workspace is required.');
        }
        if ($actorUserId <= 0) {
            throw new \InvalidArgumentException('Seed actor is required.');
        }

        $pack = $this->pack($packKey);
        if ($reset) {
            $this->cleanupWorkspaceSeeds($workspaceId);
        }

        Database::execute(
            "INSERT INTO presentation_seed_runs
                (workspace_id, presentation_session_id, seed_pack_key, status, reset_existing, created_by, metadata_json)
             VALUES (?, ?, ?, 'running', ?, ?, ?)",
            [
                $workspaceId,
                $sessionId,
                (string) $pack['key'],
                $reset ? 1 : 0,
                $actorUserId,
                json_encode(['source' => self::SOURCE, 'pack_label' => $pack['label']], JSON_UNESCAPED_SLASHES),
            ]
        );
        $seedRunId = (int) Database::lastInsertId();

        try {
            $this->applyWorkspaceShowcaseSettings($workspaceId, $pack);
            $created = $this->createSeedRecords($workspaceId, $actorUserId, $seedRunId, $pack);

            Database::execute(
                "UPDATE presentation_seed_runs
                 SET status = 'completed',
                     entity_count = ?,
                     completed_at = NOW(),
                     metadata_json = ?
                 WHERE id = ?",
                [
                    (int) ($created['entity_count'] ?? 0),
                    json_encode(['source' => self::SOURCE, 'summary' => $created['summary'] ?? []], JSON_UNESCAPED_SLASHES),
                    $seedRunId,
                ]
            );

            return [
                'success' => true,
                'seed_run_id' => $seedRunId,
                'workspace_id' => $workspaceId,
                'seed_pack_key' => (string) $pack['key'],
                'entity_count' => (int) ($created['entity_count'] ?? 0),
                'created' => $created['summary'] ?? [],
            ];
        } catch (\Throwable $e) {
            Database::execute(
                "UPDATE presentation_seed_runs
                 SET status = 'failed',
                     error_message = ?,
                     completed_at = NOW()
                 WHERE id = ?",
                [mb_substr($e->getMessage(), 0, 1000), $seedRunId]
            );
            throw $e;
        }
    }

    public function cleanupWorkspaceSeeds(int $workspaceId): int
    {
        if ($workspaceId <= 0) {
            return 0;
        }

        $rows = Database::query(
            "SELECT *
             FROM presentation_seed_entities
             WHERE workspace_id = ?
               AND cleanup_status = 'active'
             ORDER BY id DESC",
            [$workspaceId]
        );

        $deleted = 0;
        foreach ($rows as $row) {
            $table = (string) ($row['table_name'] ?? '');
            $recordId = (int) ($row['record_id'] ?? 0);
            $entityId = (int) ($row['id'] ?? 0);
            if (!$this->safeIdentifier($table) || $recordId <= 0 || !Database::tableExists($table)) {
                Database::execute("UPDATE presentation_seed_entities SET cleanup_status = 'skipped' WHERE id = ?", [$entityId]);
                continue;
            }

            try {
                $where = Database::columnExists($table, 'workspace_id')
                    ? "workspace_id = ? AND id = ?"
                    : "id = ?";
                $params = Database::columnExists($table, 'workspace_id')
                    ? [$workspaceId, $recordId]
                    : [$recordId];
                $deleted += Database::execute("DELETE FROM `{$table}` WHERE {$where} LIMIT 1", $params);
                Database::execute("UPDATE presentation_seed_entities SET cleanup_status = 'deleted' WHERE id = ?", [$entityId]);
            } catch (\Throwable $e) {
                Database::execute("UPDATE presentation_seed_entities SET cleanup_status = 'skipped' WHERE id = ?", [$entityId]);
            }
        }

        Database::execute(
            "UPDATE presentation_seed_runs
             SET status = 'reset',
                 completed_at = COALESCE(completed_at, NOW())
             WHERE workspace_id = ?
               AND status = 'completed'",
            [$workspaceId]
        );

        return $deleted;
    }

    /**
     * @param array<string,mixed> $pack
     */
    private function applyWorkspaceShowcaseSettings(int $workspaceId, array $pack): void
    {
        Database::execute(
            "UPDATE workspaces
             SET settings_json = JSON_SET(
                    COALESCE(NULLIF(settings_json, ''), JSON_OBJECT()),
                    '$.presentation_seed_pack_key', ?,
                    '$.presentation_audience_key', ?,
                    '$.presentation_showcase_company', ?,
                    '$.presentation_showcase_industry', ?,
                    '$.presentation_showcase_summary', ?,
                    '$.presentation_showcase_seeded_at', NOW()
                 ),
                 updated_at = NOW()
             WHERE id = ?",
            [
                (string) ($pack['key'] ?? ''),
                (string) ($pack['audience'] ?? ''),
                (string) ($pack['company_name'] ?? ''),
                (string) ($pack['industry'] ?? ''),
                (string) ($pack['summary'] ?? ''),
                $workspaceId,
            ]
        );
    }

    /**
     * @param array<string,mixed> $pack
     * @return array{entity_count:int,summary:array<string,int>}
     */
    private function createSeedRecords(int $workspaceId, int $actorUserId, int $seedRunId, array $pack): array
    {
        $summary = [
            'companies' => 0,
            'products' => 0,
            'contacts' => 0,
            'threads' => 0,
            'communications' => 0,
            'emails' => 0,
            'whatsapp_messages' => 0,
            'tasks' => 0,
            'deals' => 0,
            'invoices' => 0,
            'events' => 0,
            'activities' => 0,
            'notifications' => 0,
            'scheduled_reports' => 0,
            'company_profiles' => 0,
            'strategy_profiles' => 0,
            'startup_journeys' => 0,
            'startup_journey_stages' => 0,
            'founder_sprints' => 0,
            'founder_reviews' => 0,
            'founder_commitments' => 0,
        ];
        $entityCount = 0;
        $fromEmail = (string) ($pack['from_email'] ?? 'hello@presentation.example');
        $fromPhone = (string) ($pack['from_phone'] ?? '254711020300');
        $currency = (string) ($pack['currency'] ?? 'KES');

        $companyId = $this->insertTracked($seedRunId, $workspaceId, 'companies', [
            'workspace_id' => $workspaceId,
            'uuid' => $this->uuid(),
            'name' => (string) ($pack['company_name'] ?? 'Presentation Company'),
            'website' => 'https://example.test/' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) ($pack['key'] ?? 'presentation'))),
            'phone' => '+' . $fromPhone,
            'address' => 'Nairobi, Kenya',
            'industry' => (string) ($pack['industry'] ?? ''),
            'size' => '11-50',
            'assigned_to' => $actorUserId,
        ], 'presentation-company');
        if ($companyId > 0) {
            $summary['companies']++;
            $entityCount++;
        }

        foreach ((array) ($pack['services'] ?? []) as $index => $service) {
            if (!is_array($service)) {
                continue;
            }
            $id = $this->insertTracked($seedRunId, $workspaceId, 'products', [
                'workspace_id' => $workspaceId,
                'name' => (string) ($service['name'] ?? 'Service'),
                'description' => (string) ($service['description'] ?? ''),
                'category' => (string) ($service['category'] ?? 'Service'),
                'features' => json_encode(['AI triage ready', 'Digest-ready follow-up', 'Reportable revenue signal'], JSON_UNESCAPED_SLASHES),
                'pricing_info' => $currency . ' ' . number_format((float) ($service['price'] ?? 0), 2),
                'target_audience' => implode(', ', (array) ($pack['segments'] ?? [])),
                'use_cases' => 'Presentation workspace seed pack context',
                'benefits' => 'Shows follow-up, automation confidence, and human escalation.',
                'seed_metadata_json' => json_encode(['source' => self::SOURCE, 'pack' => $pack['key']], JSON_UNESCAPED_SLASHES),
                'is_active' => 1,
                'display_order' => $index + 1,
                'unit_price' => (float) ($service['price'] ?? 0),
            ], 'product-' . ($index + 1));
            if ($id > 0) {
                $summary['products']++;
                $entityCount++;
            }
        }

        $contacts = [];
        foreach ($this->contactSeeds($pack) as $index => $contact) {
            $contactId = $this->insertTracked($seedRunId, $workspaceId, 'contacts', [
                'workspace_id' => $workspaceId,
                'uuid' => $this->uuid(),
                'first_name' => $contact['first_name'],
                'last_name' => $contact['last_name'],
                'email' => $contact['email'],
                'phone' => $contact['phone'],
                'whatsapp_opt_in_status' => 'opted_in',
                'whatsapp_opt_in_source' => 'presentation_seed_pack',
                'whatsapp_opt_in_at' => $this->relativeDate(-20 + ($index % 8), '10:00:00'),
                'company' => $contact['company'],
                'company_id' => $companyId > 0 && $index < 12 ? $companyId : null,
                'lead_source' => $contact['lead_source'],
                'stage' => $contact['stage'],
                'assigned_to' => $actorUserId,
                'created_by' => $actorUserId,
                'created_at' => $this->relativeDate(-45 + $index, '09:15:00'),
                'lead_score' => $contact['lead_score'],
                'company_industry' => (string) ($pack['industry'] ?? ''),
                'job_title' => $contact['job_title'],
                'location' => $contact['location'],
                'metadata_json' => json_encode(['source' => self::SOURCE, 'pack' => $pack['key'], 'segment' => $contact['segment']], JSON_UNESCAPED_SLASHES),
                'engagement_score' => min(100, 45 + ($index % 45)),
                'ai_score' => min(100, 52 + ($index % 41)),
            ], 'contact-' . ($index + 1));
            if ($contactId > 0) {
                $contacts[] = ['id' => $contactId] + $contact;
                $summary['contacts']++;
                $entityCount++;
            }
        }

        if ($companyId > 0 && isset($contacts[0])) {
            Database::execute("UPDATE companies SET primary_contact_id = ? WHERE workspace_id = ? AND id = ?", [(int) $contacts[0]['id'], $workspaceId, $companyId]);
        }

        $threadContacts = array_slice($contacts, 0, 25);
        foreach ($threadContacts as $index => $contact) {
            $channel = $index % 2 === 0 ? 'whatsapp' : 'email';
            $threadKey = 'presentation-seed-' . $seedRunId . '-' . $pack['key'] . '-' . ($index + 1) . '-' . $channel;
            $threadId = $this->insertTracked($seedRunId, $workspaceId, 'conversation_threads', [
                'workspace_id' => $workspaceId,
                'contact_id' => (int) $contact['id'],
                'channel' => $channel,
                'thread_key' => $threadKey,
                'last_message_at' => $this->relativeDate(-4 + ($index % 4), '16:30:00'),
                'status' => $index % 7 === 0 ? 'open' : 'active',
                'current_owner_id' => $actorUserId,
                'priority' => $index % 8 === 0 ? 'urgent' : ($index % 3 === 0 ? 'high' : 'medium'),
                'response_due_at' => $this->relativeDate(!empty($pack['founder_story']) ? (($index % 3) + 1) : ($index % 3), '17:00:00'),
                'last_inbound_at' => $this->relativeDate(-2 + ($index % 3), '15:00:00'),
                'last_outbound_at' => $this->relativeDate(-1 + ($index % 2), '12:30:00'),
                'last_channel' => $channel,
                'unresolved_item_count' => $index % 6 === 0 ? 2 : 1,
                'escalation_status' => $index % 11 === 0 ? 'needs_human_review' : null,
                'metadata_json' => json_encode(['source' => self::SOURCE, 'pack' => $pack['key']], JSON_UNESCAPED_SLASHES),
                'message_count' => 4,
                'is_resolved' => $index % 9 === 0 ? 1 : 0,
            ], 'thread-' . ($index + 1));
            if ($threadId > 0) {
                $summary['threads']++;
                $entityCount++;
            }

            foreach ($this->threadMessages($pack, $contact, $index, $channel) as $messageIndex => $message) {
                $direction = (string) $message['direction'];
                $communicationId = $this->insertTracked($seedRunId, $workspaceId, 'communications', [
                    'workspace_id' => $workspaceId,
                    'uuid' => $this->uuid(),
                    'contact_id' => (int) $contact['id'],
                    'thread_key' => $threadKey,
                    'channel' => $channel,
                    'direction' => $direction,
                    'subject' => (string) $message['subject'],
                    'body' => (string) $message['body'],
                    'metadata' => json_encode(['source' => self::SOURCE, 'pack' => $pack['key'], 'thread_index' => $index], JSON_UNESCAPED_SLASHES),
                    'status' => 'delivered',
                    'created_at' => $this->relativeDate(-6 + ($index % 5), sprintf('%02d:%02d:00', 9 + $messageIndex, 10 + $messageIndex)),
                    'triage_priority' => $index % 8 === 0 ? 'urgent' : ($index % 3 === 0 ? 'high' : 'medium'),
                    'triage_score' => $index % 8 === 0 ? 96.50 : 84.00,
                    'triage_confidence' => $index % 8 === 0 ? 93.25 : 88.75,
                    'triage_status' => 'suggested',
                    'triage_reason_codes' => json_encode(['presentation_seed', (string) ($message['kind'] ?? 'follow_up')], JSON_UNESCAPED_SLASHES),
                    'triage_decided_at' => $this->relativeDate(-5 + ($index % 4), '14:00:00'),
                    'from_email' => $direction === 'inbound' ? (string) $contact['email'] : $fromEmail,
                    'to_email' => $direction === 'inbound' ? $fromEmail : (string) $contact['email'],
                    'message_id' => 'presentation-seed-' . $seedRunId . '-' . $index . '-' . $messageIndex . '@example.test',
                ], 'communication-' . $index . '-' . $messageIndex);
                if ($communicationId > 0) {
                    $summary['communications']++;
                    $entityCount++;
                }

                if ($channel === 'email') {
                    $emailId = $this->insertTracked($seedRunId, $workspaceId, 'emails', [
                        'workspace_id' => $workspaceId,
                        'uuid' => $this->uuid(),
                        'contact_id' => (int) $contact['id'],
                        'user_id' => $actorUserId,
                        'to_email' => $direction === 'inbound' ? $fromEmail : (string) $contact['email'],
                        'from_email' => $direction === 'inbound' ? (string) $contact['email'] : $fromEmail,
                        'from_name' => $direction === 'inbound' ? trim($contact['first_name'] . ' ' . $contact['last_name']) : (string) ($pack['company_name'] ?? 'Presentation Workspace'),
                        'sender_profile' => 'assistant',
                        'subject' => (string) $message['subject'],
                        'body' => (string) $message['body'],
                        'status' => 'delivered',
                        'sent_at' => $this->relativeDate(-5 + ($index % 4), '11:30:00'),
                        'delivered_at' => $this->relativeDate(-5 + ($index % 4), '11:31:00'),
                        'message_id' => 'seed-email-' . $seedRunId . '-' . $index . '-' . $messageIndex,
                    ], 'email-' . $index . '-' . $messageIndex);
                    if ($emailId > 0) {
                        $summary['emails']++;
                        $entityCount++;
                    }
                } else {
                    $waId = $this->insertTracked($seedRunId, $workspaceId, 'whatsapp_messages', [
                        'workspace_id' => $workspaceId,
                        'uuid' => $this->uuid(),
                        'contact_id' => (int) $contact['id'],
                        'user_id' => $actorUserId,
                        'to_number' => $direction === 'inbound' ? $fromPhone : preg_replace('/\D+/', '', (string) $contact['phone']),
                        'from_number' => $direction === 'inbound' ? preg_replace('/\D+/', '', (string) $contact['phone']) : $fromPhone,
                        'message_type' => 'text',
                        'message_body' => (string) $message['body'],
                        'whatsapp_message_id' => 'seed-wa-' . $seedRunId . '-' . $index . '-' . $messageIndex,
                        'status' => 'delivered',
                        'sent_at' => $this->relativeDate(-5 + ($index % 4), '11:30:00'),
                        'delivered_at' => $this->relativeDate(-5 + ($index % 4), '11:31:00'),
                        'direction' => $direction,
                    ], 'whatsapp-' . $index . '-' . $messageIndex);
                    if ($waId > 0) {
                        $summary['whatsapp_messages']++;
                        $entityCount++;
                    }
                }
            }
        }

        $taskIds = [];
        foreach (array_slice($contacts, 0, 20) as $index => $contact) {
            $taskId = $this->insertTracked($seedRunId, $workspaceId, 'tasks', [
                'workspace_id' => $workspaceId,
                'title' => $this->taskTitle($pack, $index),
                'description' => 'Seeded presentation follow-up with enough context for digests, automation confidence, and owner review.',
                'metadata_json' => json_encode(['source' => self::SOURCE, 'pack' => $pack['key'], 'automation_confidence' => $index % 5 === 0 ? 0.62 : 0.91], JSON_UNESCAPED_SLASHES),
                'source_surface' => 'presentation_seed_pack',
                'contact_id' => (int) $contact['id'],
                'assigned_to' => $actorUserId,
                'created_by' => $actorUserId,
                'status' => $index % 6 === 0 ? 'in_progress' : 'pending',
                'priority' => $index % 7 === 0 ? 'urgent' : ($index % 3 === 0 ? 'high' : 'medium'),
                'due_date' => $this->relativeDate(!empty($pack['founder_story']) ? (($index % 5) + 1) : (($index % 5) - 1), '15:00:00'),
                'created_at' => $this->relativeDate(-12 + $index, '10:20:00'),
            ], 'task-' . ($index + 1));
            if ($taskId > 0) {
                $taskIds[] = $taskId;
                $summary['tasks']++;
                $entityCount++;
            }
        }

        $dealContacts = array_slice($contacts, 0, !empty($pack['founder_story']) ? 3 : 16);
        foreach ($dealContacts as $index => $contact) {
            $dealValue = ((float) (($pack['services'][$index % max(1, count((array) $pack['services']))]['price'] ?? 25000))) * (1 + ($index % 3));
            $dealId = $this->insertTracked($seedRunId, $workspaceId, 'deals', [
                'workspace_id' => $workspaceId,
                'title' => $this->dealTitle($pack, $contact, $index),
                'description' => 'Presentation seed opportunity for pack-aware pipeline, reports, and payment follow-up.',
                'contact_id' => (int) $contact['id'],
                'assigned_to' => $actorUserId,
                'created_by' => $actorUserId,
                'stage' => (!empty($pack['founder_story'])
                    ? ['prospecting', 'qualification', 'proposal', 'negotiation'][$index % 4]
                    : ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won'][$index % 5]),
                'value' => $dealValue,
                'probability' => [25, 45, 60, 75, 100][$index % 5],
                'expected_close_date' => date('Y-m-d', strtotime('+' . (7 + $index) . ' days')),
                'currency' => $currency,
                'lead_source' => $contact['lead_source'],
                'tags' => json_encode(['presentation', (string) $pack['key'], (string) $contact['segment']], JSON_UNESCAPED_SLASHES),
                'custom_fields' => json_encode(['source' => self::SOURCE, 'automation_signal' => $index % 4 === 0 ? 'needs_review' : 'auto_follow_up_ready'], JSON_UNESCAPED_SLASHES),
                'created_at' => $this->relativeDate(-20 + $index, '13:20:00'),
            ], 'deal-' . ($index + 1));
            if ($dealId > 0) {
                $summary['deals']++;
                $entityCount++;
            }

            if ($dealId > 0 && $index < 8) {
                $invoiceId = $this->insertTracked($seedRunId, $workspaceId, 'invoices', [
                    'workspace_id' => $workspaceId,
                    'document_type' => $index % 3 === 0 ? 'quote' : 'invoice',
                    'status' => (!empty($pack['founder_story'])
                        ? ['sent', 'viewed', 'overdue', 'sent']
                        : ['sent', 'viewed', 'partially_paid', 'overdue'])[$index % 4],
                    'invoice_number' => 'PRES-' . date('ymd') . '-' . str_pad((string) $seedRunId, 5, '0', STR_PAD_LEFT) . '-' . str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'deal_id' => $dealId,
                    'contact_id' => (int) $contact['id'],
                    'assigned_to' => $actorUserId,
                    'created_by' => $actorUserId,
                    'currency' => $currency,
                    'issue_date' => date('Y-m-d', strtotime('-' . (8 - $index) . ' days')),
                    'due_date' => date('Y-m-d', strtotime('+' . (5 + $index) . ' days')),
                    'subtotal' => $dealValue,
                    'tax_total' => round($dealValue * 0.16, 2),
                    'grand_total' => round($dealValue * 1.16, 2),
                    'amount_paid' => !empty($pack['founder_story']) ? 0 : ($index % 4 === 2 ? round($dealValue * 0.4, 2) : 0),
                    'balance_due' => !empty($pack['founder_story'])
                        ? round($dealValue * 1.16, 2)
                        : ($index % 4 === 2 ? round($dealValue * 0.76, 2) : round($dealValue * 1.16, 2)),
                    'tax_mode' => 'exclusive',
                    'tax_rate' => 16.0000,
                    'title' => $this->dealTitle($pack, $contact, $index),
                    'intro_text' => 'Presentation invoice seeded for payment reminder and reporting workflows.',
                    'billing_name' => trim($contact['first_name'] . ' ' . $contact['last_name']),
                    'billing_email' => (string) $contact['email'],
                    'billing_phone' => (string) $contact['phone'],
                    'source_snapshot_json' => json_encode(['source' => self::SOURCE, 'pack' => $pack['key']], JSON_UNESCAPED_SLASHES),
                    'created_at' => $this->relativeDate(-10 + $index, '12:00:00'),
                ], 'invoice-' . ($index + 1));
                if ($invoiceId > 0) {
                    $summary['invoices']++;
                    $entityCount++;
                }
            }
        }

        foreach (array_slice($contacts, 0, 8) as $index => $contact) {
            $eventId = $this->insertTracked($seedRunId, $workspaceId, 'events', [
                'workspace_id' => $workspaceId,
                'title' => $this->eventTitle($pack, $index),
                'description' => 'Seeded calendar item for booking, digest, and report demonstration.',
                'event_type' => $index % 2 === 0 ? 'meeting' : 'call',
                'contact_id' => (int) $contact['id'],
                'assigned_to' => $actorUserId,
                'created_by' => $actorUserId,
                'start_time' => $this->relativeDate(($index % 6) + 1, sprintf('%02d:00:00', 9 + ($index % 6))),
                'end_time' => $this->relativeDate(($index % 6) + 1, sprintf('%02d:45:00', 9 + ($index % 6))),
                'location' => $index % 2 === 0 ? 'Google Meet' : 'Phone',
                'status' => 'scheduled',
            ], 'event-' . ($index + 1));
            if ($eventId > 0) {
                $summary['events']++;
                $entityCount++;
            }
        }

        foreach (array_slice($contacts, 0, 20) as $index => $contact) {
            $activityId = $this->insertTracked($seedRunId, $workspaceId, 'activities', [
                'workspace_id' => $workspaceId,
                'contact_id' => (int) $contact['id'],
                'user_id' => $actorUserId,
                'activity_type' => ['note', 'email', 'call', 'meeting'][$index % 4],
                'description' => 'Presentation seed activity: ' . $this->taskTitle($pack, $index),
                'metadata' => json_encode(['source' => self::SOURCE, 'pack' => $pack['key']], JSON_UNESCAPED_SLASHES),
                'created_at' => $this->relativeDate(-15 + $index, '16:15:00'),
            ], 'activity-' . ($index + 1));
            if ($activityId > 0) {
                $summary['activities']++;
                $entityCount++;
            }
        }

        foreach ($this->notificationSeeds($pack) as $index => $notification) {
            $notificationId = $this->insertTracked($seedRunId, $workspaceId, 'notifications', [
                'workspace_id' => $workspaceId,
                'user_id' => $actorUserId,
                'type' => (string) $notification['type'],
                'title' => (string) $notification['title'],
                'message' => (string) $notification['message'],
                'entity_type' => 'presentation',
                'entity_id' => $seedRunId,
                'link' => 'dashboard.php',
                'severity' => (string) $notification['severity'],
                'ai_insight' => (string) $notification['insight'],
                'ai_action' => (string) $notification['action'],
                'is_read' => $index > 1 ? 0 : 1,
                'created_at' => $this->relativeDate(-3 + $index, '08:30:00'),
            ], 'notification-' . ($index + 1));
            if ($notificationId > 0) {
                $summary['notifications']++;
                $entityCount++;
            }
        }

        foreach (['weekly', 'monthly'] as $index => $cadence) {
            $reportId = $this->insertOptionalTracked($seedRunId, $workspaceId, 'scheduled_reports', [
                'workspace_id' => $workspaceId,
                'report_id' => 1,
                'schedule_name' => ucfirst($cadence) . ' Presentation Snapshot - ' . (string) ($pack['label'] ?? 'Pack'),
                'schedule_type' => $cadence,
                'schedule_config' => json_encode(['source' => self::SOURCE, 'cadence' => $cadence], JSON_UNESCAPED_SLASHES),
                'recipients' => json_encode([$fromEmail], JSON_UNESCAPED_SLASHES),
                'format' => $index === 0 ? 'pdf' : 'excel',
                'is_active' => 1,
                'next_run_at' => $this->relativeDate($index + 1, '07:30:00'),
                'created_by' => $actorUserId,
            ], 'scheduled-report-' . $cadence);
            if ($reportId > 0) {
                $summary['scheduled_reports']++;
                $entityCount++;
            }
        }

        if (!empty($pack['founder_story'])) {
            $founderStory = $this->createFounderStoryRecords(
                $workspaceId,
                $actorUserId,
                $seedRunId,
                $taskIds
            );
            $entityCount += (int) ($founderStory['entity_count'] ?? 0);
            foreach ((array) ($founderStory['summary'] ?? []) as $key => $count) {
                $summary[$key] = (int) ($summary[$key] ?? 0) + (int) $count;
            }
        }

        return ['entity_count' => $entityCount, 'summary' => $summary];
    }

    /**
     * Seed the context -> constraint -> decision -> action evidence chain used
     * by the WebXpanse founder command-center presentation.
     *
     * @param list<int> $taskIds
     * @return array{entity_count:int,summary:array<string,int>}
     */
    private function createFounderStoryRecords(int $workspaceId, int $userId, int $seedRunId, array $taskIds): array
    {
        $summary = [
            'company_profiles' => 0,
            'strategy_profiles' => 0,
            'startup_journeys' => 0,
            'startup_journey_stages' => 0,
            'founder_sprints' => 0,
            'founder_reviews' => 0,
            'founder_commitments' => 0,
        ];
        $entityCount = 0;

        $companyProfileId = $this->insertTracked($seedRunId, $workspaceId, 'company_profile', [
            'workspace_id' => $workspaceId,
            'company_name' => 'WebXpanse Demo Studio',
            'company_tagline' => 'A calm operating rhythm for solo founders',
            'company_description' => 'WebXpanse turns business context and live CRM evidence into one ranked founder priority, an explainable decision, and a measurable next action.',
            'company_mission' => 'Help solo founders make fewer, better decisions and follow through consistently.',
            'company_values' => 'Clarity, evidence, human judgment, and safe automation.',
            'owner_company_context' => 'A solo founder is validating a paid Clarity Sprint before expanding the product and team.',
            'company_website' => 'https://webxpanse.example.test',
            'company_email' => 'founder@webxpanse.example',
            'company_phone' => '+254711020300',
            'company_location' => 'Nairobi, Kenya',
            'company_timezone' => 'Africa/Nairobi',
            'company_industry' => 'Founder productivity software',
            'company_size' => '1-10',
            'is_active' => 1,
        ], 'founder-company-profile');
        if ($companyProfileId > 0) {
            $summary['company_profiles']++;
            $entityCount++;
        }

        $strategyProfileId = $this->insertTracked($seedRunId, $workspaceId, 'user_strategy_profiles', [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'target_market_focus' => 'Solo founders and first-time builders running service businesses in Africa and other mobile-first markets.',
            'ideal_customer_profile' => 'A solo founder with real customer conversations but too many competing priorities and no consistent operating rhythm.',
            'offer_angle' => 'Turn scattered context into the one business move that deserves attention now, with evidence and a measurable next action.',
            'segment_focus' => 'Solo founders validating their first repeatable offer.',
            'sales_motion' => 'Founder-led warm outreach followed by a low-risk paid Clarity Sprint.',
            'deal_movement_strategy' => 'Move qualified prospects to a paid pilot by showing the decision-to-action loop with their own context.',
            'outreach_posture' => 'Warm, specific, permission-based, and evidence-seeking.',
            'positioning_notes' => 'Not another dashboard: a calm daily operating rhythm that keeps the founder in control.',
            'market_view' => 'Founders have plenty of tools but still struggle to decide what matters and close the loop on work.',
            'strategy_hypothesis' => 'If the product explains one priority from trusted evidence, founders will act more consistently than when given another list of recommendations.',
            'lean_problem' => 'Solo founders lose momentum because context, CRM activity, tasks, and strategic decisions live in separate places.',
            'lean_customer_segments' => 'Solo founders, consultants, and first-time business builders.',
            'lean_unique_value_proposition' => 'One evidence-backed founder priority, explained and connected to action.',
            'lean_solution' => 'A founder command center, Clarity reasoning, weekly commitments, and safe automation evidence.',
            'lean_channels' => 'Warm outreach, founder communities, referrals, and product-led demonstrations.',
            'lean_revenue_streams' => 'Paid Clarity Sprints and recurring founder operating plans.',
            'lean_cost_structure' => 'AI usage, hosting, support, and founder-led customer development.',
            'lean_key_metrics' => 'Qualified conversations, paid pilots, commitment completion, and time-to-decision.',
            'lean_unfair_advantage' => 'The operating loop is built from the CRM evidence and founder context already inside WebXpanse.',
        ], 'founder-strategy-profile');
        if ($strategyProfileId > 0) {
            $summary['strategy_profiles']++;
            $entityCount++;
        }

        $journeyId = $this->insertTracked($seedRunId, $workspaceId, 'startup_journeys', [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'status' => 'completed',
            'current_stage_key' => 'okrs',
        ], 'founder-clarity-journey');
        if ($journeyId > 0) {
            $summary['startup_journeys']++;
            $entityCount++;
        }

        $stageResponses = [
            'customer_discovery' => [
                'target_customer' => 'Solo founders managing customer work and growth alone.',
                'interview_count' => '12',
                'observed_problem' => 'They know many things matter but cannot reliably select and finish the highest-leverage move.',
                'evidence' => 'Repeated interviews described tool switching, stale tasks, and unclear daily priorities.',
                'riskiest_assumption' => 'Founders will trust a ranked recommendation only when its evidence is visible.',
            ],
            'jobs_to_be_done' => [
                'job_statement' => 'When the business feels noisy, help me decide what deserves my attention and move it forward.',
                'triggers' => 'A new workday, an overdue commitment, a customer signal, or a changed constraint.',
                'current_alternatives' => 'Spreadsheets, chat history, task lists, CRM dashboards, and intuition.',
                'desired_outcomes' => 'A calm priority, a reason to trust it, and a next action that can be completed.',
                'success_criteria' => 'The founder acts on the right item and can see what changed afterward.',
            ],
            'value_proposition' => [
                'customer_jobs' => 'Choose priorities, protect customer follow-up, and learn from weekly execution.',
                'pains' => 'Fragmented context, recommendation overload, and weak follow-through.',
                'gains' => 'Confidence, focus, visible progress, and safe automation.',
                'products_services' => 'Founder command center, Clarity Journey, AI Coach, CRM, and Founder Loop.',
                'pain_relievers' => 'Ranks only evidence-backed exceptions and keeps explanations on demand.',
                'gain_creators' => 'Connects each decision to a commitment, task, and measurable business signal.',
            ],
            'lean_canvas' => [
                'problem' => 'Solo founders lose momentum across disconnected tools and competing priorities.',
                'customer_segments' => 'Solo founders and first-time service-business builders.',
                'unique_value_proposition' => 'One evidence-backed founder priority, explained and connected to action.',
                'solution' => 'A calm command center plus Clarity reasoning and weekly execution loops.',
                'channels' => 'Warm outreach, founder communities, referrals, and product demonstrations.',
                'revenue_streams' => 'Clarity Sprints and founder operating plans.',
                'cost_structure' => 'AI usage, hosting, support, and customer development.',
                'key_metrics' => 'Paid pilots, commitment completion, and time-to-decision.',
                'unfair_advantage' => 'Recommendations are grounded in the founder context and live CRM evidence already in WebXpanse.',
            ],
            'mvp' => [
                'mvp_hypothesis' => 'A founder will act faster when shown one explainable priority rather than another dashboard.',
                'smallest_test' => 'Run the command-center loop with a founder and ask for a paid Clarity Sprint decision.',
                'required_features' => 'Context snapshot, ranked constraint, evidence disclosure, Clarity handoff, and commitment tracking.',
                'success_metric' => 'A qualified founder completes the next action and agrees to a paid pilot.',
                'experiment_budget' => 'Two weeks and 20 warm outreach conversations.',
            ],
            'go_to_market' => [
                'beachhead_segment' => 'Solo founders already using several business tools but lacking an operating rhythm.',
                'message' => 'Stop managing another dashboard. Start each day with the one move your evidence supports.',
                'channels' => 'Warm network, founder communities, WhatsApp, and live demonstrations.',
                'sales_motion' => 'Founder-led discovery to paid Clarity Sprint to recurring plan.',
                'launch_plan' => 'Contact 20 warm prospects, demonstrate the loop, and capture yes/no pilot evidence.',
                'conversion_goal' => 'Three qualified demonstrations and one paid pilot.',
            ],
            'aarrr' => [
                'acquisition' => 'Warm founder outreach and referrals.',
                'activation' => 'The founder sees a credible priority from their business context.',
                'retention' => 'A repeatable daily and weekly operating rhythm.',
                'referral' => 'Share the visible before-and-after decision outcome.',
                'revenue' => 'Clarity Sprint conversion followed by founder plan expansion.',
            ],
            'okrs' => [
                'objective' => 'Prove that an explainable founder operating rhythm creates paid demand.',
                'key_result_1' => 'Complete 20 warm founder outreach conversations.',
                'key_result_2' => 'Run three qualified product demonstrations.',
                'key_result_3' => 'Close one paid Clarity Sprint.',
                'review_cadence' => 'Weekly founder review every Sunday.',
            ],
        ];
        if ($journeyId > 0) {
            foreach ($stageResponses as $index => $responses) {
                $stageKey = (string) $index;
                $stageId = $this->insertTracked($seedRunId, $workspaceId, 'startup_journey_stage_responses', [
                    'journey_id' => $journeyId,
                    'workspace_id' => $workspaceId,
                    'user_id' => $userId,
                    'stage_key' => $stageKey,
                    'stage_order' => array_search($stageKey, array_keys($stageResponses), true) + 1,
                    'status' => 'completed',
                    'responses_json' => json_encode($responses, JSON_UNESCAPED_SLASHES),
                    'notes' => 'Presentation seed: validated founder operating-rhythm context.',
                    'completed_at' => date('Y-m-d H:i:s'),
                ], 'founder-journey-' . $stageKey);
                if ($stageId > 0) {
                    $summary['startup_journey_stages']++;
                    $entityCount++;
                }
            }
        }

        $sprintId = $this->insertTracked($seedRunId, $workspaceId, 'founder_first_customer_sprints', [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'target_customer_segment' => 'Solo founders validating their first repeatable offer',
            'offer_pitch' => 'A paid Clarity Sprint that converts scattered business evidence into one decision and measurable next action.',
            'outreach_channel' => 'Warm email, WhatsApp, referrals, and founder communities',
            'weekly_outreach_target' => 20,
            'demo_booking_target' => 3,
            'paid_customer_target' => 1,
            'status' => 'active',
            'created_by' => $userId,
            'updated_by' => $userId,
        ], 'founder-first-customer-sprint');
        if ($sprintId > 0) {
            $summary['founder_sprints']++;
            $entityCount++;
        }

        $weekStart = date('Y-m-d', strtotime('monday this week'));
        $reviewId = $this->insertTracked($seedRunId, $workspaceId, 'founder_weekly_reviews', [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'week_start' => $weekStart,
            'week_end' => date('Y-m-d', strtotime('sunday this week')),
            'wins' => 'The founder context is complete and warm-prospect evidence is visible in the CRM.',
            'blockers' => 'The offer still needs a clear paid-pilot decision from the warm prospect list.',
            'customer_conversations' => 12,
            'leads_created' => 8,
            'deals_opened' => 3,
            'deals_won' => 0,
            'paid_revenue' => 0,
            'expenses' => 0,
            'runway_months' => 4,
            'burn_rate' => 0,
            'break_even_deals' => 1,
            'pricing_concern' => 'Buyers may prefer a low-risk Clarity Sprint before a recurring plan.',
            'next_week_focus' => 'Validate pricing while starting outreach.',
            'finance_snapshot_json' => json_encode(['source' => self::SOURCE, 'simulation_only' => true], JSON_UNESCAPED_SLASHES),
            'review_status' => 'draft',
            'created_by' => $userId,
            'updated_by' => $userId,
        ], 'founder-current-week');
        if ($reviewId > 0) {
            $summary['founder_reviews']++;
            $entityCount++;

            $commitmentId = $this->insertTracked($seedRunId, $workspaceId, 'founder_weekly_review_commitments', [
                'review_id' => $reviewId,
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'task_id' => $taskIds[1] ?? ($taskIds[0] ?? null),
                'title' => 'Send first 20 warm outreach messages',
                'description' => 'Use the paid Clarity Sprint offer, record replies, and bring the strongest buyer objection back into the next review.',
                'due_date' => date('Y-m-d', strtotime('+3 days')),
                'status' => 'pending',
            ], 'founder-current-commitment');
            if ($commitmentId > 0) {
                $summary['founder_commitments']++;
                $entityCount++;
            }
        }

        return ['entity_count' => $entityCount, 'summary' => $summary];
    }

    /**
     * @param array<string,mixed> $pack
     * @return array<int,array<string,mixed>>
     */
    private function contactSeeds(array $pack): array
    {
        $names = [
            ['Amina', 'Odhiambo'], ['Brian', 'Mwangi'], ['Catherine', 'Njeri'], ['David', 'Mutiso'], ['Esther', 'Wanjiku'],
            ['Farah', 'Ali'], ['Grace', 'Atieno'], ['Hassan', 'Omar'], ['Ivy', 'Kamau'], ['Joseph', 'Kiptoo'],
            ['Karen', 'Achieng'], ['Leon', 'Muthoni'], ['Miriam', 'Wairimu'], ['Noah', 'Otieno'], ['Olivia', 'Cherono'],
            ['Peter', 'Maina'], ['Queen', 'Akinyi'], ['Rita', 'Wambui'], ['Samuel', 'Kariuki'], ['Talia', 'Chebet'],
            ['Uma', 'Naliaka'], ['Victor', 'Kimani'], ['Winnie', 'Nyambura'], ['Xavier', 'Barasa'], ['Yvonne', 'Moraa'],
            ['Zain', 'Abdi'], ['Alice', 'Musyoka'], ['Benard', 'Ochieng'], ['Clara', 'Koech'], ['Daniel', 'Mbugua'],
            ['Eunice', 'Wekesa'], ['Felix', 'Njenga'], ['Gloria', 'Chege'], ['Henry', 'Langat'], ['Imani', 'Njoroge'],
            ['Joy', 'Awuor'], ['Kevin', 'Sang'], ['Lilian', 'Muli'], ['Martin', 'Githinji'], ['Nadia', 'Suleiman'],
            ['Oscar', 'Onyango'], ['Patricia', 'Juma'], ['Quincy', 'Kuria'], ['Rose', 'Kilonzo'], ['Simon', 'Macharia'],
            ['Teresa', 'Miano'], ['Umar', 'Noor'], ['Valerie', 'Nyokabi'], ['William', 'Karanja'], ['Zara', 'Mohamed'],
        ];
        $segments = array_values((array) ($pack['segments'] ?? ['customer']));
        $companies = ['Acacia Group', 'Blue Ridge Ltd', 'Kifaru Holdings', 'Mtaa Ventures', 'Savanna Partners', 'Nairobi Works', 'Lakeview Co', 'Greenfield Team'];
        $stages = !empty($pack['founder_story'])
            ? ['new', 'contacted', 'qualified', 'proposal', 'negotiation']
            : ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
        $sources = !empty($pack['founder_story'])
            ? ['whatsapp', 'referral']
            : ['whatsapp', 'form', 'referral', 'social', 'other'];
        $jobs = !empty($pack['founder_story'])
            ? ['Founder', 'Solo Founder', 'Consultant', 'Owner', 'Independent Builder', 'Studio Founder', 'Micro-business Owner']
            : ['Operations Lead', 'Owner', 'Finance Manager', 'Director', 'Coordinator', 'Administrator', 'Procurement Lead'];

        $rows = [];
        foreach ($names as $index => $name) {
            $segment = (string) $segments[$index % max(1, count($segments))];
            $rows[] = [
                'first_name' => $name[0],
                'last_name' => $name[1],
                'email' => strtolower($name[0] . '.' . $name[1] . '.' . ($index + 1) . '@presentation.example'),
                'phone' => '+2547' . str_pad((string) (11020000 + $index), 8, '0', STR_PAD_LEFT),
                'company' => !empty($pack['founder_story']) && $index === 0
                    ? 'Solo Founder Studio'
                    : $companies[$index % count($companies)],
                'lead_source' => $sources[$index % count($sources)],
                'stage' => $stages[$index % count($stages)],
                'lead_score' => 40 + ($index % 56),
                'job_title' => $jobs[$index % count($jobs)],
                'location' => ['Nairobi', 'Kiambu', 'Mombasa', 'Nakuru', 'Kisumu'][$index % 5],
                'segment' => $segment,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $pack
     * @param array<string,mixed> $contact
     * @return array<int,array<string,string>>
     */
    private function threadMessages(array $pack, array $contact, int $index, string $channel): array
    {
        $company = (string) ($pack['company_name'] ?? 'the team');
        $service = (array) (($pack['services'] ?? [])[($index % max(1, count((array) ($pack['services'] ?? []))))] ?? ['name' => 'service']);
        $firstName = (string) ($contact['first_name'] ?? 'there');
        $subject = (string) (($pack['scenario_subjects']['qualification'] ?? 'Follow-up'));

        return [
            ['direction' => 'inbound', 'kind' => 'inquiry', 'subject' => $subject, 'body' => "Hi {$company}, I need help with {$service['name']}. What information do you need from me?"],
            ['direction' => 'outbound', 'kind' => 'qualification', 'subject' => $subject, 'body' => "Hi {$firstName}, thanks for reaching out. I can help. Please share your preferred timeline, budget range, and any document or booking constraints."],
            ['direction' => 'inbound', 'kind' => 'context', 'subject' => $subject, 'body' => "Timeline is this week. Budget is flexible if the package is clear. I can send documents today."],
            ['direction' => 'outbound', 'kind' => 'next_step', 'subject' => $subject, 'body' => "Perfect. I captured that and created the follow-up. If confidence is high, routine reminders can go automatically; sensitive items will be escalated."],
        ];
    }

    /**
     * @param array<string,mixed> $pack
     */
    private function taskTitle(array $pack, int $index): string
    {
        $subjects = (array) ($pack['scenario_subjects'] ?? []);
        $titles = [
            $subjects['new_lead'] ?? 'Qualify new lead',
            $subjects['booking_or_meeting'] ?? 'Confirm booking or meeting',
            $subjects['document_followup'] ?? 'Follow up on missing documents',
            $subjects['payment_reminder'] ?? 'Review payment reminder',
            $subjects['human_escalation'] ?? 'Review escalation',
        ];

        return (string) $titles[$index % count($titles)];
    }

    /**
     * @param array<string,mixed> $pack
     * @param array<string,mixed> $contact
     */
    private function dealTitle(array $pack, array $contact, int $index): string
    {
        $services = array_values((array) ($pack['services'] ?? []));
        $service = (array) ($services[$index % max(1, count($services))] ?? ['name' => 'Presentation Package']);
        return (string) ($service['name'] ?? 'Presentation Package') . ' - ' . trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? ''));
    }

    /**
     * @param array<string,mixed> $pack
     */
    private function eventTitle(array $pack, int $index): string
    {
        $subjects = (array) ($pack['scenario_subjects'] ?? []);
        return (string) ([$subjects['booking_or_meeting'] ?? 'Meeting confirmed', $subjects['qualification'] ?? 'Qualification call'][$index % 2]);
    }

    /**
     * @param array<string,mixed> $pack
     * @return array<int,array<string,string>>
     */
    private function notificationSeeds(array $pack): array
    {
        $company = (string) ($pack['company_name'] ?? 'Presentation Workspace');
        return [
            ['type' => 'ai_coach_nudge', 'severity' => 'info', 'title' => 'Automation battery ready', 'message' => "{$company} has enough seeded activity to show inbox, tasks, pipeline, digest, and report automation.", 'insight' => 'High-confidence routine actions are ready to demonstrate.', 'action' => 'Run a pack-aware scenario.'],
            ['type' => 'warning', 'severity' => 'warning', 'title' => 'Human escalation queued', 'message' => 'A sensitive complaint is routed to human review instead of automatic resolution.', 'insight' => 'The automation boundary is visible for the presentation.', 'action' => 'Review escalation.'],
            ['type' => 'task_assigned', 'severity' => 'info', 'title' => 'Daily digest inputs ready', 'message' => 'Tasks, unresolved threads, invoices, and report snapshots are available.', 'insight' => 'Digest scenarios have live workspace records to summarize.', 'action' => 'Preview digest.'],
            ['type' => 'deal', 'severity' => 'success', 'title' => 'Pipeline context seeded', 'message' => 'Deals span qualification, proposal, negotiation, and won stages.', 'insight' => 'Reports and dashboards should show movement.', 'action' => 'Open deals.'],
            ['type' => 'email', 'severity' => 'info', 'title' => 'Email and WhatsApp history seeded', 'message' => 'Conversation records include both channels and triage scores.', 'insight' => 'Inbox automation can be demonstrated from real rows.', 'action' => 'Open inbox.'],
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insertOptionalTracked(int $seedRunId, int $workspaceId, string $table, array $data, ?string $recordKey = null): int
    {
        try {
            return $this->insertTracked($seedRunId, $workspaceId, $table, $data, $recordKey);
        } catch (\Throwable $e) {
            error_log('Presentation optional seed insert skipped for ' . $table . ': ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insertTracked(int $seedRunId, int $workspaceId, string $table, array $data, ?string $recordKey = null): int
    {
        $id = $this->insertRow($table, $data);
        if ($id <= 0) {
            return 0;
        }

        Database::execute(
            "INSERT INTO presentation_seed_entities
                (seed_run_id, workspace_id, table_name, record_id, record_key, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $seedRunId,
                $workspaceId,
                $table,
                $id,
                $recordKey,
                json_encode(['source' => self::SOURCE], JSON_UNESCAPED_SLASHES),
            ]
        );

        return $id;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function insertRow(string $table, array $data): int
    {
        if (!$this->safeIdentifier($table) || !Database::tableExists($table)) {
            return 0;
        }

        if (!array_key_exists('id', $data)) {
            $manualId = $this->manualPrimaryKeyIfNeeded($table);
            if ($manualId !== null) {
                $data = ['id' => $manualId] + $data;
            }
        }

        $columns = $this->columns($table);
        $filtered = [];
        foreach ($data as $column => $value) {
            if (isset($columns[$column])) {
                $filtered[$column] = $value;
            }
        }
        if ($filtered === []) {
            return 0;
        }

        $columnSql = implode(', ', array_map(fn(string $column): string => '`' . $column . '`', array_keys($filtered)));
        $placeholders = implode(', ', array_fill(0, count($filtered), '?'));
        Database::execute("INSERT INTO `{$table}` ({$columnSql}) VALUES ({$placeholders})", array_values($filtered));

        return isset($filtered['id']) ? (int) $filtered['id'] : (int) Database::lastInsertId();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function columns(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        $columns = [];
        foreach (Database::query("SHOW COLUMNS FROM `{$table}`") as $row) {
            $columns[(string) $row['Field']] = $row;
        }
        $this->columnCache[$table] = $columns;
        return $columns;
    }

    private function manualPrimaryKeyIfNeeded(string $table): ?int
    {
        if (!$this->safeIdentifier($table)) {
            return null;
        }

        $columns = $this->columns($table);
        $id = $columns['id'] ?? null;
        if (!$id || stripos((string) ($id['Extra'] ?? ''), 'auto_increment') !== false) {
            return null;
        }

        return (int) (Database::queryOne("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM `{$table}`")['next_id'] ?? 1);
    }

    private function relativeDate(int $days, string $time): string
    {
        return date('Y-m-d ' . $time, strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
    }

    private function safeIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $value) === 1;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
