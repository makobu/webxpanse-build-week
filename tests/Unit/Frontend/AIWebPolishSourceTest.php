<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class AIWebPolishSourceTest extends TestCase
{
    public function testTargetedAiFlowsAvoidNativeBlockingDialogsAndExposeAccessibleStatus(): void
    {
        $compose = (string) file_get_contents(__DIR__ . '/../../../public/email_compose.php');
        $chat = (string) file_get_contents(__DIR__ . '/../../../public/assets/js/chat-bubble.js');
        $coach = (string) file_get_contents(__DIR__ . '/../../../public/assets/js/ai-coach-modal.js');
        $layout = (string) file_get_contents(__DIR__ . '/../../../views/layouts/base.php');
        $conversation = (string) file_get_contents(__DIR__ . '/../../../public/conversation.php');
        $deal = (string) file_get_contents(__DIR__ . '/../../../public/deal_view.php');
        $chatCss = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/chat-bubble.css');

        $this->assertStringNotContainsString('alert(', $compose);
        $this->assertStringNotContainsString('window.prompt', $chat);
        $this->assertStringNotContainsString('alert(', $coach);
        $this->assertStringContainsString('role="status" aria-live="polite"', $compose);
        $this->assertStringContainsString('aria-controls="advanced-options"', $compose);
        $this->assertStringContainsString('aria-modal="true" aria-hidden="true"', $layout);
        $this->assertStringContainsString('handlePanelKeyboard', $chat);
        $this->assertStringContainsString('chat-bubble-task-confirm', $chat);
        $this->assertStringContainsString("renderExecutionStatus(diagnostics.ai_status)", $chat);
        $this->assertStringContainsString('diagnostics.operator_controls_enabled === true', $chat);
        $this->assertStringContainsString("layer.setAttribute('aria-modal', 'true')", $coach);
        $this->assertStringContainsString('document.removeEventListener(\'keydown\', onKey)', $coach);
        $this->assertStringContainsString('id="thread-summary-status" role="status" aria-live="polite"', $conversation);
        $this->assertStringContainsString('renderExecutionStatus(data.ai_status)', $conversation);
        $this->assertStringContainsString('id="deal-ai-status" role="status" aria-live="polite"', $deal);
        $this->assertStringContainsString('renderExecutionStatus(statuses[0])', $deal);
        $this->assertStringContainsString('body:has(#dashboard-setup-popup.is-visible) .chat-bubble-container', $chatCss);
    }

    public function testClarityOperatorWritesAreProtectedAtEveryEndpoint(): void
    {
        foreach ([
            'api/ai/feedback.php',
            'api/ai/link_task_to_guidance.php',
            'api/tasks/create.php',
        ] as $relativePath) {
            $source = (string) file_get_contents(__DIR__ . '/../../../' . $relativePath);
            $this->assertStringContainsString('ClarityOperatorControlsService', $source, $relativePath);
            $this->assertStringContainsString('BLOCKED_REASON', $source, $relativePath);
        }
    }

    public function testComposeRestoresSafeAiDraftMetadataAcrossReauthentication(): void
    {
        $compose = (string) file_get_contents(__DIR__ . '/../../../public/email_compose.php');
        $sessionUx = (string) file_get_contents(__DIR__ . '/../../../public/assets/js/session-ux.js');

        foreach (['draft_source', 'draft_mode', 'draft_intention_hidden', 'draft_learning_sample_id'] as $fieldId) {
            $this->assertMatchesRegularExpression(
                '/id="' . preg_quote($fieldId, '/') . '"[^>]*data-session-draft="true"/',
                $compose
            );
        }
        $this->assertStringContainsString("field.getAttribute('data-session-draft') !== 'true'", $sessionUx);
    }

    public function testDefaultWorkspaceKeepsOperatorShellWithoutTenantPaymentControls(): void
    {
        $layout = (string) file_get_contents(__DIR__ . '/../../../views/layouts/base.php');

        $this->assertStringContainsString("data-workspace-kind=\"<?php echo \$layoutIsDefaultWorkspace ? 'default' : 'tenant'; ?>\"", $layout);
        $this->assertStringContainsString("\$layoutBodyClasses[] = \$layoutIsDefaultWorkspace ? 'workspace-mode-default' : 'workspace-mode-tenant';", $layout);
        $this->assertStringContainsString("Auth::check() && !\$isProtectedDemoSession && !\$layoutIsDefaultWorkspace", $layout);
        $this->assertStringContainsString("!\$isPresentationWorkspaceSession && !\$layoutIsDefaultWorkspace", $layout);
    }

    public function testAiEndpointsExposeAdditiveStatusMetadata(): void
    {
        foreach ([
            'api/chat/ask.php',
            'api/generate_draft.php',
            'api/ai-coach/recommendations.php',
            'api/inbox/summarize.php',
            'api/deals/intelligence.php',
            'api/mobile/ai/chat.php',
            'api/mobile/ai/recommendations.php',
        ] as $relativePath) {
            $source = (string) file_get_contents(__DIR__ . '/../../../' . $relativePath);
            $this->assertStringContainsString('ai_status', $source, $relativePath);
            $this->assertStringContainsString('AIExecutionStatusService', $source, $relativePath);
        }
    }
}
