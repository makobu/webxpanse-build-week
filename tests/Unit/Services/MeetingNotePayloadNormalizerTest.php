<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\MeetingNotePayloadNormalizer;
use CRM\Tests\TestCase;

class MeetingNotePayloadNormalizerTest extends TestCase
{
    public function testNormalizesGenericPayload(): void
    {
        $result = (new MeetingNotePayloadNormalizer())->normalize([
            'provider' => 'generic',
            'title' => 'Discovery Call',
            'transcript' => 'Customer wants a proposal next week.',
            'attendees' => [
                ['name' => 'Jane Doe', 'email' => 'jane@example.com'],
                'owner@example.com',
            ],
            'organizer_email' => 'owner@example.com',
        ]);

        $this->assertSame('generic', $result['provider']);
        $this->assertCount(2, $result['attendees']);
        $this->assertSame('jane@example.com', $result['attendees'][0]['email']);
        $this->assertSame('owner@example.com', $result['organizer']['email']);
    }

    public function testNormalizesZoomPayload(): void
    {
        $result = (new MeetingNotePayloadNormalizer())->normalize([
            'provider' => 'zoom',
            'meeting' => [
                'id' => 'zoom-123',
                'topic' => 'Zoom Demo',
                'host_email' => 'host@example.com',
                'participants' => [
                    ['display_name' => 'Pat Buyer', 'email' => 'buyer@example.com'],
                ],
            ],
            'transcript_text' => 'Buyer asked for pricing.',
        ]);

        $this->assertSame('zoom', $result['provider']);
        $this->assertSame('zoom-123', $result['external_meeting_id']);
        $this->assertSame('Zoom Demo', $result['title']);
        $this->assertSame('buyer@example.com', $result['attendees'][0]['email']);
    }
}
