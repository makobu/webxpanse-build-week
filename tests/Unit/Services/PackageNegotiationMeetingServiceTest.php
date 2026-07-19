<?php

declare(strict_types=1);

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Services\GuidedDemoPackageIntentService;
use CRM\Services\PackageNegotiationMeetingService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class PackageNegotiationMeetingServiceTest extends DatabaseTestCase
{
    private int $supportUserId;
    private PackageNegotiationMeetingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supportUserId = (int) Auth::createUser(
            'package-negotiation-support@example.test',
            'P@ssword123!',
            'admin',
            'Package',
            'Support'
        );
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $this->supportUserId, 'superadmin', true, $this->supportUserId);
        $this->service = new PackageNegotiationMeetingService();
    }

    public function testManualModeCreatesPendingDefaultWorkspaceBookingAndSignalsSupport(): void
    {
        $customer = $this->provisionCustomer('package-negotiation-manual@example.test', 'Package Negotiation Manual');
        $state = $this->service->setAutoApproval(false, $this->supportUserId);
        $this->removeNoticeWindow((int) (($state['profile']['id'] ?? 0)));

        $result = $this->service->bookPackageMeeting(
            (int) $customer['workspace_id'],
            (int) $customer['user_id'],
            null,
            GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM,
            $this->nextMondayAt('10:00:00'),
            'UTC',
            ['selected_package' => ['display_name' => 'Scale Custom']]
        );

        $booking = Database::queryOne("SELECT * FROM meeting_booking_requests WHERE id = ?", [(int) $result['booking_id']]) ?: [];
        $bookingMetadata = json_decode((string) ($booking['metadata_json'] ?? '{}'), true) ?: [];
        $intent = Database::queryOne("SELECT metadata_json FROM guided_demo_package_intents WHERE id = ?", [(int) $result['intent_id']]) ?: [];
        $intentMetadata = json_decode((string) ($intent['metadata_json'] ?? '{}'), true) ?: [];
        $opsEvent = Database::queryOne(
            "SELECT signal_type, owner_workspace_id, owner_user_id, metadata_json
             FROM default_workspace_ops_events
             WHERE id = ?",
            [(int) $result['ops_event_id']]
        ) ?: [];

        $this->assertSame(1, (int) ($booking['workspace_id'] ?? 0));
        $this->assertSame('pending', (string) ($booking['status'] ?? ''));
        $this->assertEmpty($booking['event_id'] ?? null);
        $this->assertSame('package_negotiation', (string) ($bookingMetadata['source'] ?? ''));
        $this->assertSame((int) $customer['workspace_id'], (int) ($bookingMetadata['owner_workspace_id'] ?? 0));
        $this->assertSame((int) $result['booking_id'], (int) ($intentMetadata['meeting_booking_id'] ?? 0));
        $this->assertSame(PackageNegotiationMeetingService::SIGNAL_TYPE, (string) ($opsEvent['signal_type'] ?? ''));
        $this->assertSame((int) $customer['workspace_id'], (int) ($opsEvent['owner_workspace_id'] ?? 0));
        $this->assertSame((int) $customer['user_id'], (int) ($opsEvent['owner_user_id'] ?? 0));
        $this->assertSame(1, $this->notificationCount(1, $this->supportUserId));
        $this->assertSame(1, $this->notificationCount((int) $customer['workspace_id'], (int) $customer['user_id']));
    }

    public function testAutoApprovalModeConfirmsBookingAndCreatesCalendarEvent(): void
    {
        $customer = $this->provisionCustomer('package-negotiation-auto@example.test', 'Package Negotiation Auto');
        $state = $this->service->setAutoApproval(true, $this->supportUserId);
        $this->removeNoticeWindow((int) (($state['profile']['id'] ?? 0)));

        $result = $this->service->bookPackageMeeting(
            (int) $customer['workspace_id'],
            (int) $customer['user_id'],
            null,
            GuidedDemoPackageIntentService::PACKAGE_SCALE_CUSTOM,
            $this->nextMondayAt('11:00:00'),
            'UTC',
            ['selected_package' => ['display_name' => 'Scale Custom']]
        );

        $booking = Database::queryOne("SELECT * FROM meeting_booking_requests WHERE id = ?", [(int) $result['booking_id']]) ?: [];
        $event = Database::queryOne("SELECT * FROM events WHERE id = ? AND workspace_id = 1", [(int) ($booking['event_id'] ?? 0)]) ?: [];
        $intent = Database::queryOne("SELECT metadata_json FROM guided_demo_package_intents WHERE id = ?", [(int) $result['intent_id']]) ?: [];
        $intentMetadata = json_decode((string) ($intent['metadata_json'] ?? '{}'), true) ?: [];

        $this->assertSame('confirmed', (string) ($booking['status'] ?? ''));
        $this->assertGreaterThan(0, (int) ($booking['event_id'] ?? 0));
        $this->assertSame('meeting', (string) ($event['event_type'] ?? ''));
        $this->assertSame('scheduled', (string) ($event['status'] ?? ''));
        $this->assertSame((int) ($booking['event_id'] ?? 0), (int) ($intentMetadata['meeting_event_id'] ?? 0));
    }

    /**
     * @return array<string,mixed>
     */
    private function provisionCustomer(string $email, string $workspaceName): array
    {
        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => $workspaceName,
            'workspace_slug' => strtolower(str_replace(' ', '-', $workspaceName)) . '-' . substr(hash('sha256', $email), 0, 6),
            'first_name' => 'Package',
            'last_name' => 'Buyer',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }

    private function removeNoticeWindow(int $profileId): void
    {
        Database::execute(
            "UPDATE meeting_booking_profiles
             SET timezone = 'UTC',
                 min_notice_hours = 0,
                 max_advance_days = 90
             WHERE id = ?",
            [$profileId]
        );
    }

    private function nextMondayAt(string $time): string
    {
        $start = new \DateTimeImmutable('next monday ' . $time, new \DateTimeZone('UTC'));
        if ($start <= new \DateTimeImmutable('+2 days', new \DateTimeZone('UTC'))) {
            $start = $start->modify('+1 week');
        }

        return $start->format('Y-m-d H:i:s');
    }

    private function notificationCount(int $workspaceId, int $userId): int
    {
        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM notifications
             WHERE workspace_id = ?
               AND user_id = ?
               AND type = 'package_negotiation_meeting'",
            [$workspaceId, $userId]
        )['count'] ?? 0);
    }
}
