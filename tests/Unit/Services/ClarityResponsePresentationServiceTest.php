<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\ClarityResponsePresentationService;
use CRM\Services\WorkspaceLanguageLevelService;
use PHPUnit\Framework\TestCase;

class ClarityResponsePresentationServiceTest extends TestCase
{
    public function testExplanationKeepsStraightAnswerAndRemovesDiagnosticAppendix(): void
    {
        $answer = "Your risk score is high mainly because task completion is low and two important tasks are overdue.\n\n"
            . "Strongest factors:\n- task_completion = 35\n- overdue_open_tasks = 2\n\n"
            . "Measured facts:\n- source context was checked\n\nMissing evidence:\n- recent activity";

        $presented = (new ClarityResponsePresentationService())->present(
            $answer,
            (new WorkspaceLanguageLevelService())->responseStyleContract('level_2'),
            ['intent' => 'explanation']
        );

        $this->assertSame(
            'Your risk score is high mainly because task completion is low and two important tasks are overdue.',
            $presented['answer']
        );
        $this->assertTrue($presented['guard_applied']);
        $this->assertSame(1, $presented['removed_section_count']);
        $this->assertStringNotContainsString('task_completion', $presented['answer']);
        $this->assertStringNotContainsString('Missing evidence', $presented['answer']);
    }

    public function testInternalTokensAreRemovedWithoutDroppingNaturalConclusion(): void
    {
        $answer = "The list is empty because onboarding has not produced any qualifying contacts yet.\n"
            . "`missing_context_flags` includes `onboarding_progress_missing`.\n"
            . "No customer currently meets the nurture rules.";

        $presented = (new ClarityResponsePresentationService())->present(
            $answer,
            ['level' => 'level_1'],
            ['intent' => 'explanation']
        );

        $this->assertSame(
            'The list is empty because onboarding has not produced any qualifying contacts yet. No customer currently meets the nurture rules.',
            $presented['answer']
        );
        $this->assertStringNotContainsString('missing_context_flags', $presented['answer']);
    }

    public function testLanguageLevelCapsExplanationLength(): void
    {
        $answer = 'First sentence. Second sentence. Third sentence. Fourth sentence.';
        $service = new ClarityResponsePresentationService();

        $levelOne = $service->present($answer, ['level' => 'level_1'], ['intent' => 'explanation']);
        $levelThree = $service->present($answer, ['level' => 'level_3'], ['intent' => 'explanation']);

        $this->assertSame('First sentence. Second sentence.', $levelOne['answer']);
        $this->assertSame($answer, $levelThree['answer']);
    }

    public function testGeneralHowToAnswerKeepsUsefulSteps(): void
    {
        $answer = "1. Open Contacts.\n2. Choose the customer.\n3. Select Add note.";

        $presented = (new ClarityResponsePresentationService())->present(
            $answer,
            ['level' => 'level_1'],
            ['intent' => 'general']
        );

        $this->assertSame($answer, $presented['answer']);
        $this->assertFalse($presented['guard_applied']);
    }

    public function testOnlyInternalSectionsProduceSafeFallback(): void
    {
        $presented = (new ClarityResponsePresentationService())->present(
            "Processing logic:\nmissing_context_flags = true",
            ['level' => 'level_1'],
            ['intent' => 'explanation']
        );

        $this->assertSame(
            'I can see the result, but the available information does not show a reliable reason yet.',
            $presented['answer']
        );
        $this->assertTrue($presented['guard_applied']);
    }
}
