<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\CompanyProfile;
use CRM\Modules\UserStrategyProfile;
use CRM\Services\CustomerReplyAssistantService;
use CRM\Tests\DatabaseTestCase;

class CustomerReplyAssistantServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $otherUserId;
    private int $contactId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at) VALUES (?, ?, ?, 'admin', 'Reply', 'Owner', NOW())",
            [uuid_v4(), 'customer-reply@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at) VALUES (?, ?, ?, 'admin', 'Other', 'Sender', NOW())",
            [uuid_v4(), 'other-reply@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->otherUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (uuid, first_name, last_name, email, created_at) VALUES (?, ?, ?, ?, NOW())",
            [uuid_v4(), 'Reply', 'Target', 'reply-target@example.com']
        );
        $this->contactId = (int) Database::lastInsertId();
    }

    public function testGenerateDraftFromCommunicationReturnsCanonicalReplyShape(): void
    {
        Database::execute(
            "INSERT INTO communications (uuid, contact_id, channel, direction, subject, body, status, created_at)
             VALUES (?, ?, 'email', 'inbound', ?, ?, 'received', NOW())",
            [uuid_v4(), $this->contactId, 'Need the latest pricing', 'Can you send pricing details?']
        );
        $communicationId = (int) Database::lastInsertId();

        $result = (new CustomerReplyAssistantService())->generateDraftFromCommunication($communicationId, $this->userId, [
            'surface' => 'conversation',
            'goal' => 'draft',
        ]);

        $this->assertTrue($result['success']);
        $this->assertSame('email', $result['channel']);
        $this->assertSame('customer_thread', $result['mode']);
        $this->assertSame($communicationId, $result['communication_id']);
        $this->assertSame($this->contactId, $result['contact_id']);
        $this->assertArrayHasKey('policy', $result);
        $this->assertArrayHasKey('draft', $result);
        $this->assertArrayHasKey('compat', $result);
        $this->assertArrayHasKey('summary_text', $result);
        $this->assertNotSame('', trim((string) $result['compat']['body']));
        $this->assertSame($result['compat']['body'], $result['compat']['plain_body']);
    }

    public function testGenerateDraftFromContactFallsBackSafelyWithoutCommunication(): void
    {
        $result = (new CustomerReplyAssistantService())->generateDraftFromContact($this->contactId, 'email', $this->userId, [
            'surface' => 'email_view',
            'goal' => 'draft',
        ]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['fallback']);
        $this->assertSame('fallback', $result['source']);
        $this->assertSame('email_view', $result['mode']);
        $this->assertArrayHasKey('draft', $result);
        $this->assertNotSame('', trim((string) $result['draft']['plain_body']));
        $this->assertNotSame('', trim((string) $result['summary_text']));
    }

    public function testResolveDraftStyleContextPrefersUserStrategyOverChannelDefaults(): void
    {
        (new UserStrategyProfile())->save($this->userId, [
            'outreach_posture' => 'Consultative and warm',
            'draft_tone_preset' => 'consultative',
            'draft_voice_notes' => 'Keep replies calm and practical.',
            'draft_cta_style' => 'soft',
            'draft_formality_level' => 'formal',
            'draft_reading_level' => 'professional',
        ]);
        (new AIAutoResponderConfig())->save([
            'channels' => [
                'email' => [
                    'enabled' => true,
                    'confidence_threshold' => 0.88,
                    'max_chars' => 4000,
                    'draft_length_band' => 'detailed',
                    'draft_fullness' => 'fuller',
                    'draft_include_clear_cta' => true,
                ],
            ],
        ]);

        $style = (new CustomerReplyAssistantService())->resolveDraftStyleContext($this->userId, 'email');

        $this->assertSame('consultative', $style['tone']);
        $this->assertSame('soft', $style['cta_style']);
        $this->assertSame('formal', $style['formality_level']);
        $this->assertSame('level_2', $style['reading_level']);
        $this->assertSame('detailed', $style['length_band']);
        $this->assertSame('fuller', $style['fullness']);
        $this->assertTrue($style['include_clear_cta']);
        $this->assertStringContainsString('Keep replies calm and practical.', $style['instruction_text']);
    }

    public function testResolveDraftStyleContextKeepsExistingFallbackDefaultsWhenNothingConfigured(): void
    {
        $service = new CustomerReplyAssistantService();

        $emailStyle = $service->resolveDraftStyleContext($this->userId, 'email');
        $whatsAppStyle = $service->resolveDraftStyleContext($this->userId, 'whatsapp');

        $this->assertSame('professional', $emailStyle['tone']);
        $this->assertSame('casual', $whatsAppStyle['tone']);
        $this->assertSame('level_2', $emailStyle['reading_level']);
        $this->assertSame('level_2', $whatsAppStyle['reading_level']);
    }

    public function testFormatDraftForChannelAddsEmailSignoffAndSimplifiesReadingLevel(): void
    {
        $service = new CustomerReplyAssistantService();
        $style = $service->resolveDraftStyleContext($this->userId, 'email');
        $style['reading_level'] = 'level_1';

        $draft = $service->formatDraftForChannel([
            'subject' => 'Re: Pricing',
            'plain_body' => 'Thank you for your message. I have reviewed your request and will follow up shortly regarding additional details.',
            'html_body' => '',
        ], $style, 'email', []);

        $this->assertStringContainsString('Best regards,', $draft['plain_body']);
        $this->assertStringContainsString('about more details', strtolower($draft['plain_body']));
    }

    public function testResolveDraftStyleContextUsesUsersDefaultSignatureOnly(): void
    {
        Database::execute(
            "INSERT INTO email_signatures (user_id, name, content_html, content_text, is_default, created_at, updated_at)
             VALUES (?, 'Primary', '<p>Thanks,<br>Reply Owner</p>', 'Thanks,\nReply Owner', 1, NOW(), NOW())",
            [$this->userId]
        );
        $userSignatureId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO email_signatures (user_id, name, content_html, content_text, is_default, created_at, updated_at)
             VALUES (?, 'Other', '<p>Regards,<br>Other Sender</p>', 'Regards,\nOther Sender', 1, NOW(), NOW())",
            [$this->otherUserId]
        );

        $style = (new CustomerReplyAssistantService())->resolveDraftStyleContext($this->userId, 'email');

        $this->assertSame($userSignatureId, (int) ($style['default_signature_id'] ?? 0));
        $this->assertSame($userSignatureId, (int) (($style['default_signature']['id'] ?? 0)));
        $this->assertStringContainsString('Reply Owner', (string) ($style['default_signature']['rendered_text'] ?? ''));
        $this->assertStringNotContainsString('Other Sender', (string) ($style['default_signature']['rendered_text'] ?? ''));
    }

    public function testFormatDraftForChannelAppendsSavedSignatureWithoutDuplicatingGenericClosing(): void
    {
        Database::execute(
            "INSERT INTO email_signatures (user_id, name, content_html, content_text, is_default, created_at, updated_at)
             VALUES (?, 'Primary', '<div><strong>Reply Owner</strong><br>Customer Success</div>', 'Reply Owner\nCustomer Success', 1, NOW(), NOW())",
            [$this->userId]
        );

        $service = new CustomerReplyAssistantService();
        $style = $service->resolveDraftStyleContext($this->userId, 'email');

        $draft = $service->formatDraftForChannel([
            'subject' => 'Re: Pricing',
            'plain_body' => "Thanks for the note.\n\nBest regards,\nReply Owner",
            'html_body' => '',
        ], $style, 'email', []);

        $this->assertStringNotContainsString('Best regards,', $draft['plain_body']);
        $this->assertStringContainsString('Customer Success', $draft['plain_body']);
        $this->assertSame((int) ($style['default_signature_id'] ?? 0), (int) ($draft['signature_id'] ?? 0));
        $this->assertStringContainsString('<strong>Reply Owner</strong>', (string) ($draft['signature_html'] ?? ''));
    }

    public function testFormatDraftForChannelSuppressesSameDayWhatsappGreeting(): void
    {
        $service = new CustomerReplyAssistantService();
        $style = $service->resolveDraftStyleContext($this->userId, 'whatsapp', [], [
            'thread_context' => [
                'messages' => [
                    ['created_at' => date('Y-m-d H:i:s'), 'direction' => 'inbound', 'body' => 'Ping'],
                ],
            ],
        ]);

        $draft = $service->formatDraftForChannel([
            'plain_body' => "Hi Reply,\nThanks for the message. I can help with that today.",
            'html_body' => '',
        ], $style, 'whatsapp', []);

        $this->assertStringStartsNotWith('Hi', $draft['plain_body']);
        $this->assertStringContainsString('Thanks for the message.', $draft['plain_body']);
    }

    public function testResolveDraftStyleContextMarksNextDayWhatsappAsFreshConversation(): void
    {
        $service = new CustomerReplyAssistantService();
        $style = $service->resolveDraftStyleContext($this->userId, 'whatsapp', [], [
            'thread_context' => [
                'messages' => [
                    ['created_at' => date('Y-m-d H:i:s', strtotime('-1 day')), 'direction' => 'inbound', 'body' => 'Yesterday'],
                ],
            ],
        ]);

        $this->assertFalse((bool) ($style['continuity']['same_day_continuation'] ?? true));
    }

    public function testFormatDraftForChannelDoesNotWelcomeContactToTheirOwnCompany(): void
    {
        $companyProfile = new CompanyProfile();
        $companyProfile->update([
            'company_name' => 'webXpanse',
            'company_description' => 'AI operating system for founder-led businesses.',
        ]);

        $service = new CustomerReplyAssistantService();
        $style = $service->resolveDraftStyleContext($this->userId, 'email');

        $draft = $service->formatDraftForChannel([
            'subject' => 'Welcome to Pick and Go, Reply!',
            'plain_body' => 'Welcome to Pick and Go. We are excited to support your journey with Pick and Go.',
            'html_body' => '',
        ], $style, 'email', [
            'purpose' => 'welcome',
            'contact' => [
                'company' => 'Pick and Go',
            ],
        ]);

        $this->assertStringContainsString('webXpanse', $draft['subject']);
        $this->assertStringContainsString('webXpanse', $draft['plain_body']);
        $this->assertStringNotContainsString('Welcome to Pick and Go', $draft['subject']);
        $this->assertStringNotContainsString('Welcome to Pick and Go', $draft['plain_body']);
    }
}
