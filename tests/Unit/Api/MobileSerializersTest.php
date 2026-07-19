<?php

namespace CRM\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/mobile/_serializers.php';

class MobileSerializersTest extends TestCase
{
    public function testTargetSerializerReturnsCompactMobileRoute(): void
    {
        $payload = \mobileTargetSummary([
            'id' => 42,
            'title' => 'Q3 pipeline',
            'status' => 'active',
            'target_value' => '100000',
            'current_value' => '48000',
            'progress_percentage' => '48.5',
            'is_on_track' => '1',
            'days_remaining' => '9',
        ]);

        $this->assertSame(42, $payload['id']);
        $this->assertSame('Q3 pipeline', $payload['title']);
        $this->assertSame(100000.0, $payload['target_value']);
        $this->assertSame(48.5, $payload['progress_percentage']);
        $this->assertTrue($payload['is_on_track']);
        $this->assertSame('/targets/42', $payload['route']);
    }

    public function testNotificationSerializerMapsNewMobileRoutes(): void
    {
        $target = \mobileNotificationSummary([
            'entity_type' => 'target',
            'entity_id' => 17,
        ]);
        $booking = \mobileNotificationSummary([
            'entity_type' => 'meeting_booking',
            'entity_id' => 9,
        ]);
        $organization = \mobileNotificationSummary([
            'link' => '/public/hr_analytics.php',
        ]);

        $this->assertSame('/targets/17', $target['route']);
        $this->assertSame('/bookings', $booking['route']);
        $this->assertSame('/organization', $organization['route']);
    }

    public function testCalendarShareSerializerKeepsShareActionFields(): void
    {
        $payload = \mobileCalendarShareSummary([
            'id' => 5,
            'status' => 'sent',
            'booking_url' => 'https://example.test/book/abc',
            'contact_id' => 12,
            'contact_first_name' => 'Ada',
            'contact_last_name' => 'Lovelace',
            'duration_minutes' => 30,
            'suggested_slots' => [['start' => '2026-07-11T09:00:00Z']],
        ]);

        $this->assertSame(5, $payload['id']);
        $this->assertSame('Ada Lovelace', $payload['contact_name']);
        $this->assertSame('https://example.test/book/abc', $payload['booking_url']);
        $this->assertCount(1, $payload['suggested_slots']);
        $this->assertSame('/calendar/share', $payload['route']);
    }

    public function testFormSubmissionSerializerDecodesSubmittedFields(): void
    {
        $payload = \mobileFormSubmissionSummary([
            'id' => 88,
            'contact_id' => 33,
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'data' => '{"email":"grace@example.test","need":"demo"}',
            'created_at' => '2026-07-10 12:00:00',
        ]);

        $this->assertSame(88, $payload['id']);
        $this->assertSame(33, $payload['contact_id']);
        $this->assertSame('Grace Hopper', $payload['contact_name']);
        $this->assertSame('demo', $payload['fields']['need']);
        $this->assertSame('/contacts/33', $payload['contact_route']);
    }

    public function testCommunicationSerializersTrimMobilePreviews(): void
    {
        $longMessage = str_repeat('A', 220);

        $whatsapp = \mobileWhatsAppMessageSummary([
            'id' => 91,
            'direction' => 'outbound',
            'status' => 'sent',
            'message_body' => $longMessage,
            'to_number' => '+15550000000',
        ]);
        $campaign = \mobileCampaignReviewSummary([
            'id' => 92,
            'channel' => 'email',
            'status' => 'sent',
            'message' => $longMessage,
        ]);

        $this->assertSame(180, strlen($whatsapp['preview']));
        $this->assertSame('+15550000000', $whatsapp['phone']);
        $this->assertSame(180, strlen($campaign['preview']));
    }

    public function testOrganizationSerializerReturnsStableMobileRecords(): void
    {
        $payload = \mobileOrganizationIntelligencePayload([
            'founder_brief' => [
                'headline' => 'Leadership attention required',
                'top_priorities' => ['Coach the sales lead'],
            ],
            'organization_health' => ['health_score' => 62],
            'leadership_attention' => [
                ['headline' => 'Pipeline coverage', 'description' => 'Add an owner.', 'priority' => 'high'],
            ],
            'people_risks' => [
                ['risk' => 'Overdue work', 'user_id' => 8, 'name' => 'Ada', 'risk_level' => 'high'],
            ],
            'employees' => [
                ['user_id' => 8, 'name' => 'Ada', 'overloaded' => true, 'workload_score' => 91, 'open_tasks' => 12],
            ],
        ], [
            'task_candidates' => [[
                'key' => 'coach-ada',
                'title' => 'Coach Ada',
                'description' => 'Review workload.',
                'priority' => 'high',
                'due_date_offset' => 5,
                'source_scope' => 'Sales',
                'source_risk_type' => 'overload',
            ]],
        ]);

        $this->assertSame('Coach the sales lead', $payload['executive_brief']['top_priorities'][0]['title']);
        $this->assertSame('Pipeline coverage', $payload['leadership_attention'][0]['title']);
        $this->assertSame(8, $payload['people_risks'][0]['user_id']);
        $this->assertSame(91.0, $payload['overloaded_users'][0]['workload_score']);
        $this->assertSame('coach-ada', $payload['action_plan_candidates'][0]['candidate_key']);
        $this->assertSame(5, $payload['action_plan_candidates'][0]['due_date_offset']);
    }
}
