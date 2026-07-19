<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\WorkspaceLanguageLevelService;
use PHPUnit\Framework\TestCase;

class WorkspaceLanguageLevelServiceTest extends TestCase
{
    public function testNormalizesCanonicalAndLegacyValues(): void
    {
        $service = new WorkspaceLanguageLevelService();

        $this->assertSame('level_1', $service->normalize('level_1'));
        $this->assertSame('level_1', $service->normalize('junior_high'));
        $this->assertSame('level_2', $service->normalize('college'));
        $this->assertSame('level_2', $service->normalize('professional'));
        $this->assertSame('level_3', $service->normalize('level_3'));
        $this->assertSame('level_3', $service->normalize('executive'));
        $this->assertSame('level_2', $service->normalize('unexpected'));
    }

    public function testContextAndPromptUseNeutralLabels(): void
    {
        $service = new WorkspaceLanguageLevelService();
        $context = $service->context('level_1');

        $this->assertSame('Level 1', $context['label']);
        $this->assertSame('Plain, direct, low-jargon', $context['description']);

        $instruction = $service->promptInstruction('level_3');
        $this->assertStringContainsString('LANGUAGE LEVEL: Level 3', $instruction);
        $this->assertStringContainsString('executive operating language', $instruction);
        $this->assertStringContainsString('do not reduce nuance', $instruction);
    }

    public function testResponseStyleContractIsStableAndPreservesRigor(): void
    {
        $service = new WorkspaceLanguageLevelService();
        $contract = $service->responseStyleContract('junior_high');

        $this->assertSame('level_1', $contract['level']);
        $this->assertSame('Level 1', $contract['label']);
        $this->assertSame('Plain, direct, low-jargon', $contract['description']);
        $this->assertStringContainsString('LANGUAGE LEVEL: Level 1', $contract['prompt_instruction']);
        $this->assertStringContainsString('plain terms', $contract['prompt_instruction']);
        $this->assertStringContainsString('do not reduce nuance', $contract['preserve_rigor']);
        $this->assertStringContainsString('compliance boundaries', $contract['prompt_instruction']);
        $this->assertContains('jargon_density', $contract['applies_to']);
        $this->assertContains('evidence', $contract['does_not_change']);
        $this->assertContains('business_logic', $contract['does_not_change']);
    }
}
