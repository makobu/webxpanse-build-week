<?php

namespace CRM\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

class MobileVoiceApiContractTest extends TestCase
{
    public function testMobileVoiceBootstrapUsesBearerWorkspaceAndModuleGates(): void
    {
        $source = $this->readVoiceSource('_bootstrap.php');

        $this->assertStringContainsString('mobileRequireAuth()', $source);
        $this->assertStringContainsString('mobileCurrentUser', $source);
        $this->assertStringContainsString('mobileWorkspaceId', $source);
        $this->assertStringContainsString('WorkspaceSkillInstallService', $source);
        $this->assertStringContainsString('isGloballyDeactivated', $source);
        $this->assertStringContainsString('WorkspaceVoiceEntitlementService', $source);
        $this->assertStringNotContainsString('voiceApiRequireCsrf', $source);
        $this->assertStringNotContainsString('Auth::check', $source);
    }

    public function testMobileVoiceStatusPreservesServerOwnedScopeAndCapabilities(): void
    {
        $source = $this->readVoiceSource('status.php');

        $this->assertStringContainsString("mobileVoiceRequire('voice.calls.use')", $source);
        $this->assertStringContainsString("mobileVoiceCan('voice.calls.view_all')", $source);
        $this->assertStringContainsString('since_event_id', $source);
        $this->assertStringContainsString('VoiceCallService', $source);
        $this->assertStringContainsString('VoiceQueueService', $source);
        $this->assertStringContainsString('WorkspaceVoiceConfigService', $source);
        $this->assertStringContainsString('provider_capabilities', $source);
        $this->assertStringContainsString("'permissions'", $source);
    }

    public function testMobileVoiceMutationsReuseHardenedDomainServices(): void
    {
        $contracts = [
            'initiate.php' => ['VoiceCallService', 'requestOutbound', 'manual_destination_confirmed'],
            'presence.php' => ['VoiceAgentService', 'setPresence'],
            'control.php' => ['VoiceCallControlService', 'transferToFallback', 'disposition'],
            'contact.php' => ['VoiceContactLinkService', 'createAndLink', 'linkExisting'],
            'follow_up.php' => ['Tasks', 'automation_dedupe_key', 'voice_call_follow_up'],
            'review.php' => ['VoiceEvidenceAccessService', 'reviewInsight', 'voice.insights.review'],
        ];

        foreach ($contracts as $file => $needles) {
            $source = $this->readVoiceSource($file);
            $this->assertStringContainsString("mobileVoiceRequireMethod('POST')", $source, $file);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $source, $file);
            }
            $this->assertStringNotContainsString('voiceApiRequireCsrf', $source, $file);
        }
    }

    public function testCallFollowUpIsCallScopedAndRequiresTaskPermission(): void
    {
        $source = $this->readVoiceSource('follow_up.php');

        $this->assertStringContainsString("mobileVoiceRequire('tasks.write')", $source);
        $this->assertStringContainsString('agent_user_id = ? OR created_by_user_id = ?', $source);
        $this->assertStringContainsString('contact_id', $source);
        $this->assertStringContainsString('mobile_call_center', $source);
        $this->assertStringContainsString("'result_route' => '/tasks/'", $source);
    }

    public function testProtectedEvidenceEndpointsStayPermissionAndWorkspaceScoped(): void
    {
        $transcript = $this->readVoiceSource('transcript.php');
        $recording = $this->readVoiceSource('recording.php');

        $this->assertStringContainsString("mobileVoiceRequire('voice.transcripts.view')", $transcript);
        $this->assertStringContainsString('VoiceEvidenceAccessService', $transcript);
        $this->assertStringContainsString("mobileVoiceCan('voice.calls.view_all')", $transcript);
        $this->assertStringContainsString("mobileVoiceRequire('voice.recordings.listen')", $recording);
        $this->assertStringContainsString('$mobileVoiceEntitlements[\'recording\']', $recording);
        $this->assertStringContainsString('Content-Disposition', $recording);
        $this->assertStringContainsString('Cache-Control', $recording);
        $this->assertStringContainsString('finally', $recording);
        $this->assertStringContainsString('@unlink', $recording);
        $this->assertStringNotContainsString('encrypted_provider_url', $recording);
    }

    public function testVoicePushRoutesAndNotificationsTargetCallDetails(): void
    {
        $push = (string) file_get_contents(__DIR__ . '/../../../services/MobilePushNotificationService.php');
        $notifications = (string) file_get_contents(__DIR__ . '/../../../services/VoiceMobileNotificationService.php');

        $this->assertStringContainsString("'voice_call' => '/calls/'", $push);
        $this->assertStringContainsString('call_center\\.php', $push);
        $this->assertStringContainsString("'voice_call'", $notifications);
        $this->assertStringContainsString('MobilePushNotificationService', $notifications);
        $this->assertStringContainsString('SELECT id FROM notifications', $notifications);
        $this->assertStringContainsString('notifyAssigned', $notifications);
        $this->assertStringContainsString('notifyMissed', $notifications);
        $this->assertStringContainsString('notifyIntelligenceReady', $notifications);
        $this->assertStringContainsString('catch (\\Throwable $e)', $notifications);
    }

    private function readVoiceSource(string $file): string
    {
        $path = __DIR__ . '/../../../api/mobile/voice/' . $file;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
