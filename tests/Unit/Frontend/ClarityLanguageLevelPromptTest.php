<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class ClarityLanguageLevelPromptTest extends TestCase
{
    public function testClarityLegacyPromptsIncludeWorkspaceLanguageInstruction(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../api/chat/ask.php');

        $this->assertNotFalse($source);
        $this->assertStringContainsString('function clarityLanguageInstruction', (string) $source);
        $this->assertStringContainsString('$languageInstruction = clarityLanguageInstruction($operatingContext);', (string) $source);
        $this->assertStringContainsString('\'response_style_contract\' => $responseStyleContract', (string) $source);
        $this->assertStringContainsString('\'language_level\' => (string) ($responseStyleContract[\'label\'] ?? \'\')', (string) $source);
    }

    public function testMobileClarityChatPassesOperatingContextLanguageLevel(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../api/mobile/ai/chat.php');

        $this->assertNotFalse($source);
        $this->assertStringContainsString('AIOperatingContextService', (string) $source);
        $this->assertStringContainsString('response_style_contract', (string) $source);
        $this->assertStringContainsString('operating_context', (string) $source);
        $this->assertStringContainsString('language_level', (string) $source);
    }
}
