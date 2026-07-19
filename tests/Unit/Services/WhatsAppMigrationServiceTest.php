<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\WhatsAppMigrationService;
use PHPUnit\Framework\TestCase;

class WhatsAppMigrationServiceTest extends TestCase
{
    public function testRegistrationPayloadUsesOfficialBackupShape(): void
    {
        $payload = WhatsAppMigrationService::buildRegistrationPayload(
            '123456',
            'backup-password',
            'encoded-metadata',
            'ke'
        );

        $this->assertSame([
            'messaging_product' => 'whatsapp',
            'pin' => '123456',
            'backup' => [
                'password' => 'backup-password',
                'data' => 'encoded-metadata',
            ],
            'data_localization_region' => 'KE',
        ], $payload);
    }

    public function testRegistrationPayloadRequiresBackupPasswordAndMetadataTogether(): void
    {
        $this->expectException(\RuntimeException::class);

        WhatsAppMigrationService::buildRegistrationPayload('123456', 'backup-password', '');
    }
}
