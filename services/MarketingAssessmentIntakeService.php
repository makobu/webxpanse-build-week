<?php
declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;
use CRM\Security;
use CRM\Modules\Contacts;
use CRM\Modules\Tags;
use CRM\Modules\Tracking;
use InvalidArgumentException;

class MarketingAssessmentIntakeService
{
    private const ALLOWED_CONTACT_SOURCES = [
        'form',
        'whatsapp',
        'ad',
        'referral',
        'social',
        'import',
        'other',
        'mobile_app',
        'web_assessment',
    ];

    private Tracking $tracking;
    private Contacts $contacts;
    private Tags $tags;

    public function __construct()
    {
        $this->tracking = new Tracking();
        $this->contacts = new Contacts();
        $this->tags = new Tags();
    }

    public function ingest(array $payload, ?int $actorUserId = null): array
    {
        $identity = $this->normalizeIdentity((array) ($payload['lead'] ?? []));
        $summary = $this->normalizeSummary((array) ($payload['summary'] ?? []));
        $answers = $this->normalizeAnswers((array) ($payload['answers'] ?? []));
        $attribution = $this->normalizeAttribution((array) ($payload['attribution'] ?? []));
        $source = $this->normalizeSource((string) ($payload['source'] ?? 'web_assessment'));
        $submittedAt = date('Y-m-d H:i:s');

        $existingContact = Database::queryOne("SELECT id FROM contacts WHERE email = ?", [$identity['email']]);
        $updatedExisting = $existingContact !== null;
        $visitorId = $attribution['visitor_id'] !== '' ? $attribution['visitor_id'] : ('vis_' . bin2hex(random_bytes(8)));

        $submissionId = $this->tracking->trackFormSubmission([
            'visitor_id' => $visitorId,
            'form_id' => 'business_ai_readiness_check',
            'form_data' => array_filter([
                'name' => trim($identity['first_name'] . ' ' . $identity['last_name']),
                'first_name' => $identity['first_name'],
                'last_name' => $identity['last_name'],
                'email' => $identity['email'],
                'phone' => $identity['phone'],
                'company' => $identity['company'],
                'assessment_score' => $summary['score'],
                'assessment_band' => $summary['readiness_band'],
                'assessment_bottleneck' => $summary['primary_bottleneck'],
                'assessment_next_step' => $summary['recommended_next_step'],
                'assessment_answers' => json_encode($answers),
            ], static fn($value): bool => $value !== null && $value !== ''),
            'page' => $attribution['source_page'],
            'utm' => $attribution['utm'],
        ]);

        $contact = Database::queryOne("SELECT id FROM contacts WHERE email = ?", [$identity['email']]);
        if (!$contact || empty($contact['id'])) {
            throw new InvalidArgumentException('Unable to resolve contact after assessment intake.');
        }

        $contactId = (int) $contact['id'];
        $this->updateContactRecord($contactId, $identity, $source);
        $this->persistAssessmentMetadata($contactId, $summary, $answers, $attribution, $source, $submittedAt);
        $this->assignAssessmentTags($contactId, $summary, $actorUserId);

        return [
            'success' => true,
            'contact_id' => $contactId,
            'updated_existing' => $updatedExisting,
            'submission_id' => $submissionId,
        ];
    }

    private function normalizeIdentity(array $lead): array
    {
        $name = trim((string) ($lead['name'] ?? ''));
        $firstName = trim((string) ($lead['first_name'] ?? ''));
        $lastName = trim((string) ($lead['last_name'] ?? ''));
        if ($name !== '' && $firstName === '') {
            $parts = preg_split('/\s+/', $name) ?: [];
            $firstName = (string) array_shift($parts);
            $lastName = $lastName !== '' ? $lastName : trim(implode(' ', $parts));
        }

        $email = Security::sanitizeInput((string) ($lead['email'] ?? ''), 'email');
        if (!Security::validateEmail($email)) {
            throw new InvalidArgumentException('A valid lead email is required.');
        }

        return [
            'first_name' => Security::sanitizeInput($firstName, 'string'),
            'last_name' => Security::sanitizeInput($lastName, 'string'),
            'email' => $email,
            'phone' => Security::sanitizeInput((string) ($lead['phone'] ?? ''), 'string'),
            'company' => Security::sanitizeInput((string) ($lead['company'] ?? ''), 'string'),
        ];
    }

    private function normalizeSummary(array $summary): array
    {
        $score = (int) ($summary['score'] ?? 0);
        $readinessBand = Security::sanitizeInput((string) ($summary['readiness_band'] ?? ''), 'string');
        $primaryBottleneck = Security::sanitizeInput((string) ($summary['primary_bottleneck'] ?? ''), 'string');
        $recommendedNextStep = Security::sanitizeInput((string) ($summary['recommended_next_step'] ?? ''), 'string');

        if ($score < 0 || $score > 100 || $readinessBand === '' || $recommendedNextStep === '') {
            throw new InvalidArgumentException('Assessment summary is incomplete.');
        }

        $topFrictions = array_values(array_filter(array_map(
            static fn($value): string => Security::sanitizeInput((string) $value, 'string'),
            (array) ($summary['top_frictions'] ?? [])
        )));
        $quickWins = array_values(array_filter(array_map(
            static fn($value): string => Security::sanitizeInput((string) $value, 'string'),
            (array) ($summary['quick_wins'] ?? [])
        )));

        return [
            'score' => $score,
            'readiness_band' => $readinessBand,
            'primary_bottleneck' => $primaryBottleneck,
            'recommended_next_step' => $recommendedNextStep,
            'top_frictions' => array_slice($topFrictions, 0, 3),
            'quick_wins' => array_slice($quickWins, 0, 3),
        ];
    }

    private function normalizeAnswers(array $answers): array
    {
        $normalized = [];
        foreach ($answers as $key => $value) {
            $safeKey = preg_replace('/[^a-z0-9_]/i', '', (string) $key);
            if ($safeKey === '') {
                continue;
            }

            $normalized[$safeKey] = Security::sanitizeInput((string) $value, 'string');
        }

        if ($normalized === []) {
            throw new InvalidArgumentException('Assessment answers are required.');
        }

        return $normalized;
    }

    private function normalizeAttribution(array $attribution): array
    {
        $utm = [];
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
            $value = Security::sanitizeInput((string) ($attribution['utm'][$key] ?? ''), 'string');
            if ($value !== '') {
                $utm[$key] = $value;
            }
        }

        return [
            'visitor_id' => Security::sanitizeInput((string) ($attribution['visitor_id'] ?? ''), 'string'),
            'source_page' => Security::sanitizeInput((string) ($attribution['source_page'] ?? ''), 'string'),
            'referrer' => Security::sanitizeInput((string) ($attribution['referrer'] ?? ''), 'string'),
            'utm' => $utm,
        ];
    }

    private function normalizeSource(string $source): string
    {
        $source = strtolower(trim($source));
        if ($source === '' || !in_array($source, self::ALLOWED_CONTACT_SOURCES, true)) {
            return 'web_assessment';
        }
        return $source;
    }

    private function updateContactRecord(int $contactId, array $identity, string $source): void
    {
        $contact = $this->contacts->getById($contactId);
        if (!$contact) {
            return;
        }

        if (trim((string) ($contact['lead_source'] ?? '')) !== $source) {
            Database::execute("UPDATE contacts SET lead_source = ? WHERE id = ?", [$source, $contactId]);
        }

        $updates = [];
        foreach (['first_name', 'last_name', 'phone', 'company'] as $field) {
            $incoming = trim((string) ($identity[$field] ?? ''));
            if ($incoming === '') {
                continue;
            }

            if (trim((string) ($contact[$field] ?? '')) !== $incoming) {
                $updates[$field] = $incoming;
            }
        }

        if (count($updates) > 0) {
            $this->contacts->update($contactId, $updates);
        }
    }

    private function persistAssessmentMetadata(
        int $contactId,
        array $summary,
        array $answers,
        array $attribution,
        string $source,
        string $submittedAt
    ): void {
        $contact = $this->contacts->getById($contactId);
        if (!$contact) {
            return;
        }

        $metadata = [];
        if (!empty($contact['metadata_json']) && is_string($contact['metadata_json'])) {
            $decoded = json_decode((string) $contact['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $assessmentSnapshot = [
            'source' => $source,
            'submitted_at' => $submittedAt,
            'score' => $summary['score'],
            'readiness_band' => $summary['readiness_band'],
            'primary_bottleneck' => $summary['primary_bottleneck'],
            'recommended_next_step' => $summary['recommended_next_step'],
            'top_frictions' => $summary['top_frictions'],
            'quick_wins' => $summary['quick_wins'],
            'answers' => $answers,
            'attribution' => $attribution,
        ];

        $history = $metadata['marketing_assessment_history'] ?? [];
        if (!is_array($history)) {
            $history = [];
        }
        array_unshift($history, $assessmentSnapshot);

        $metadata['marketing_assessment_latest'] = $assessmentSnapshot;
        $metadata['marketing_assessment_history'] = array_slice($history, 0, 5);

        Database::execute(
            "UPDATE contacts SET metadata_json = ? WHERE id = ?",
            [json_encode($metadata), $contactId]
        );
    }

    private function assignAssessmentTags(int $contactId, array $summary, ?int $actorUserId): void
    {
        $tagNames = [
            'web-assessment-' . $this->slugify($summary['readiness_band']),
        ];

        if ($summary['primary_bottleneck'] !== '') {
            $tagNames[] = 'web-assessment-' . $this->slugify($summary['primary_bottleneck']);
        }

        foreach ($tagNames as $tagName) {
            if ($tagName === 'web-assessment-') {
                continue;
            }

            $tag = $this->tags->getByName($tagName);
            $tagId = (int) ($tag['id'] ?? 0);
            if ($tagId <= 0) {
                if (($actorUserId ?? 0) <= 0) {
                    continue;
                }
                $tagId = $this->tags->create([
                    'name' => $tagName,
                    'color' => '#0077e6',
                    'description' => 'Auto-created from the WebXpanse business AI readiness assessment.',
                    'created_by' => $actorUserId ?? 0,
                ]);
            }

            $this->tags->assign($tagId, 'contact', $contactId);
        }
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }
}
