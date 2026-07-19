<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class DemoWorkspaceSeedService
{
    /**
     * Rebuild the shared public demo story without touching session-private visitor overlays.
     *
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
            $seeded = $this->ensureSeeded($workspaceId, $actorUserId);
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return ['purged' => $purged, 'seeded' => $seeded];
    }

    public function ensureSeeded(int $workspaceId, int $actorUserId = 0): array
    {
        if ($workspaceId <= 0) {
            return ['created' => 0, 'skipped' => true];
        }

        $fixtures = $this->fixtures();
        $baseline = $this->publicSeedBaseline($workspaceId, count($fixtures));
        if (!empty($baseline['complete'])) {
            return ['created' => 0, 'skipped' => true, 'baseline' => $baseline];
        }

        $created = 0;
        $purged = 0;
        $ownsTransaction = !Database::getInstance()->inTransaction();
        if ($ownsTransaction) {
            Database::beginTransaction();
        }
        try {
            if (!empty($baseline['partial'])) {
                $purged = $this->purgePublicSeed($workspaceId);
            }

            foreach ($fixtures as $index => $fixture) {
                $contactId = $this->insertContact($workspaceId, $actorUserId, $fixture);
                $threadKey = 'protected-demo-public-' . ($index + 1);
                $this->insertThread($workspaceId, $contactId, $fixture, $threadKey);
                $this->insertCommunication($workspaceId, $contactId, $fixture, $threadKey, $index);
                $created++;
            }
            if ($ownsTransaction) {
                Database::commit();
            }
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'created' => $created,
            'skipped' => false,
            'purged' => $purged,
            'repaired' => !empty($baseline['partial']),
            'baseline' => $baseline,
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function fixtures(): array
    {
        return [
            [
                'first_name' => 'Amina',
                'last_name' => 'Otieno',
                'email' => 'amina.otieno@riverside-residence.example',
                'phone' => '+254711000101',
                'company' => 'Riverside Residence',
                'lead_source' => 'whatsapp',
                'stage' => 'proposal',
                'channel' => 'whatsapp',
                'subject' => 'Kitchen remodel WhatsApp lead',
                'body' => 'Hi, can you revise the stone finish pricing and send the updated kitchen remodel quote today?',
                'priority' => 'high',
                'reason' => 'demo_whatsapp_lead',
            ],
            [
                'first_name' => 'Daniel',
                'last_name' => 'Mwangi',
                'email' => 'daniel.mwangi@greenpark-offices.example',
                'phone' => '+254711000102',
                'company' => 'Greenpark Offices',
                'lead_source' => 'form',
                'stage' => 'qualified',
                'channel' => 'email',
                'subject' => 'Office fit-out site visit',
                'body' => 'We need a proposal for the Greenpark office fit-out. Can your team confirm a site visit window?',
                'priority' => 'urgent',
                'reason' => 'demo_inbound_email',
            ],
            [
                'first_name' => 'Rose',
                'last_name' => 'Kamau',
                'email' => 'rose.kamau@hillview-apartments.example',
                'phone' => '+254711000103',
                'company' => 'Hillview Apartments',
                'lead_source' => 'referral',
                'stage' => 'negotiation',
                'channel' => 'email',
                'subject' => 'Proposal follow-up',
                'body' => 'Procurement liked the wardrobes proposal but wants payment terms before they sign.',
                'priority' => 'medium',
                'reason' => 'proposal_follow_up',
            ],
            [
                'first_name' => 'Kevin',
                'last_name' => 'Shah',
                'email' => 'kevin.shah@northgate-villas.example',
                'phone' => '+254711000104',
                'company' => 'Northgate Villas',
                'lead_source' => 'social',
                'stage' => 'new',
                'channel' => 'whatsapp',
                'subject' => 'Fresh WhatsApp inquiry',
                'body' => 'Do you supply aluminium windows for a villa project? I can send measurements.',
                'priority' => 'high',
                'reason' => 'fresh_whatsapp_lead',
            ],
        ];
    }

    /**
     * @return array{contacts:int,threads:int,communications:int,expected:int,complete:bool,partial:bool}
     */
    private function publicSeedBaseline(int $workspaceId, int $expected): array
    {
        $contacts = $this->countPublicSeedRows($workspaceId, 'contacts');
        $threads = $this->countPublicSeedRows($workspaceId, 'conversation_threads');
        $communications = $this->countPublicSeedRows($workspaceId, 'communications');
        $complete = $contacts === $expected && $threads === $expected && $communications === $expected;

        return [
            'contacts' => $contacts,
            'threads' => $threads,
            'communications' => $communications,
            'expected' => $expected,
            'complete' => $complete,
            'partial' => !$complete && ($contacts > 0 || $threads > 0 || $communications > 0),
        ];
    }

    private function countPublicSeedRows(int $workspaceId, string $table): int
    {
        if (!Database::tableExists($table) || !Database::columnExists($table, 'demo_visibility')) {
            return 0;
        }

        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM `{$table}`
             WHERE workspace_id = ?
               AND demo_visibility = 'public_seed'",
            [$workspaceId]
        )['c'] ?? 0);
    }

    private function purgePublicSeed(int $workspaceId): int
    {
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

        $deleted = 0;
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

    private function insertContact(int $workspaceId, int $actorUserId, array $fixture): int
    {
        Database::execute(
            "INSERT INTO contacts
                (workspace_id, demo_visibility, demo_session_id, uuid, first_name, last_name, email, phone, company, lead_source, stage, assigned_to, created_by, metadata_json)
             VALUES (?, 'public_seed', NULL, ?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?)",
            [
                $workspaceId,
                $this->uuid(),
                $fixture['first_name'],
                $fixture['last_name'],
                $fixture['email'],
                $fixture['phone'],
                $fixture['company'],
                $fixture['lead_source'],
                $fixture['stage'],
                $actorUserId > 0 ? $actorUserId : null,
                json_encode(['source' => 'protected_demo_public_seed'], JSON_UNESCAPED_SLASHES),
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function insertThread(int $workspaceId, int $contactId, array $fixture, string $threadKey): void
    {
        Database::execute(
            "INSERT INTO conversation_threads
                (workspace_id, demo_visibility, demo_session_id, contact_id, channel, thread_key, last_message_at, status, priority, last_channel, last_inbound_at, unresolved_item_count, metadata_json)
             VALUES (?, 'public_seed', NULL, ?, ?, ?, DATE_SUB(NOW(), INTERVAL 15 MINUTE), 'open', ?, ?, DATE_SUB(NOW(), INTERVAL 15 MINUTE), 1, ?)",
            [
                $workspaceId,
                $contactId,
                $fixture['channel'],
                $threadKey,
                $fixture['priority'],
                $fixture['channel'],
                json_encode(['source' => 'protected_demo_public_seed'], JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    private function insertCommunication(int $workspaceId, int $contactId, array $fixture, string $threadKey, int $index): void
    {
        Database::execute(
            "INSERT INTO communications
                (workspace_id, demo_visibility, demo_session_id, uuid, contact_id, thread_key, channel, direction, subject, body, metadata, status, read_at,
                 triage_priority, triage_score, triage_confidence, triage_status, triage_reason_codes, triage_decided_at, from_email, to_email, created_at)
             VALUES (?, 'public_seed', NULL, ?, ?, ?, ?, 'inbound', ?, ?, ?, 'delivered', NULL, ?, 86.00, 91.00, 'suggested', ?, NOW(), ?, 'workspace-demo@demo.local.invalid', DATE_SUB(NOW(), INTERVAL ? MINUTE))",
            [
                $workspaceId,
                $this->uuid(),
                $contactId,
                $threadKey,
                $fixture['channel'],
                $fixture['subject'],
                $fixture['body'],
                json_encode(['source' => 'protected_demo_public_seed'], JSON_UNESCAPED_SLASHES),
                $fixture['priority'],
                json_encode([$fixture['reason'], 'public_seed_story'], JSON_UNESCAPED_SLASHES),
                $fixture['email'],
                15 + ($index * 9),
            ]
        );
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
