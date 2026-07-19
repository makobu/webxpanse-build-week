<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\ReadinessCaptureSettings;
use CRM\Tests\DatabaseTestCase;

class ReadinessCaptureSettingsTest extends DatabaseTestCase
{
    private ReadinessCaptureSettings $settings;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settings = new ReadinessCaptureSettings();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'owner', NOW())",
            [uniqid('readiness-settings-', true), 'settings-owner@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testGetOrCreateInitializesConnectorWithSecret(): void
    {
        $settings = $this->settings->getOrCreate($this->userId);

        $this->assertSame(ReadinessCaptureSettings::CONNECTOR_KEY, (string) $settings['connector_key']);
        $this->assertFalse((bool) $settings['is_enabled']);
        $this->assertNotSame('', (string) $settings['capture_secret']);
        $this->assertSame(ReadinessCaptureSettings::DEFAULT_SOURCE, (string) $settings['source_label']);
    }

    public function testRotateSecretChangesStoredSecret(): void
    {
        $before = $this->settings->getOrCreate($this->userId);
        $after = $this->settings->rotateSecret($this->userId);

        $this->assertNotSame((string) $before['capture_secret'], (string) $after['capture_secret']);
        $this->assertSame((int) $this->userId, (int) ($after['updated_by_user_id'] ?? 0));
    }

    public function testAuthenticateReturnsSettingsOnlyForEnabledConnector(): void
    {
        $settings = $this->settings->getOrCreate($this->userId);
        $this->assertNull($this->settings->authenticate((string) $settings['capture_secret']));

        $updated = $this->settings->update([
            'is_enabled' => true,
            'source_label' => 'web_assessment',
            'origin_notes' => 'webxpanse.com landing page',
        ], $this->userId);

        $auth = $this->settings->authenticate((string) $updated['capture_secret']);
        $this->assertNotNull($auth);
        $this->assertSame('web_assessment', (string) ($auth['source_label'] ?? ''));
        $this->assertNull($this->settings->authenticate('wrong_secret'));
    }
}
