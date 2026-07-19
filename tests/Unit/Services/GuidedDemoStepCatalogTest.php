<?php

declare(strict_types=1);

namespace CRM\Tests\Unit\Services;

use CRM\Services\GuidedDemoStepCatalog;
use PHPUnit\Framework\TestCase;

class GuidedDemoStepCatalogTest extends TestCase
{
    public function testExpandedFounderTourHasPhasesActionsAndRoutes(): void
    {
        $catalog = new GuidedDemoStepCatalog();
        $steps = $catalog->steps();

        $this->assertCount(14, $steps);
        $this->assertSame('dashboard_today', $catalog->firstKey());
        $this->assertSame('wrap_up', (string) ($steps[13]['key'] ?? ''));
        $this->assertSame('inbox.php', $catalog->pageFor('simulate_reply_received'));
        $this->assertSame('founder_operating_loop.php', $catalog->pageFor('weekly_review'));
        $this->assertSame('email_compose.php', $catalog->pageFor('preview_outreach_message'));

        $this->assertSame([
            'dashboard_today',
            'add_demo_contact',
            'contact_detail',
            'preview_outreach_message',
            'simulate_reply_received',
            'create_follow_up_task',
            'prepare_demo_quote',
            'dashboard_rollup',
            'modules_channels',
            'modules_calendar',
            'modules_finance',
            'modules_ai_coach',
            'weekly_review',
            'wrap_up',
        ], array_map(static fn(array $step): string => (string) ($step['key'] ?? ''), $steps));

        $actionKeys = array_values(array_filter(array_map(
            static fn(array $step): string => (string) (($step['action']['key'] ?? '') ?: ''),
            $steps
        )));
        $this->assertSame([
            'add_demo_contact',
            'preview_outreach_message',
            'simulate_reply_received',
            'create_follow_up_task',
            'prepare_demo_quote',
        ], $actionKeys);

        $contactStep = $catalog->step('add_demo_contact');
        $this->assertSame('Add one person', (string) ($contactStep['title'] ?? ''));
        $this->assertSame(
            'Pick a contact type. I\'ll add a demo contact.',
            (string) ($contactStep['body'] ?? '')
        );
        $this->assertSame('Continue', (string) ($contactStep['action_label'] ?? ''));
        $this->assertSame('Add contact', (string) ($contactStep['action']['label'] ?? ''));
        $this->assertSame(['Warm referral', 'Past customer', 'New lead'], array_column((array) ($contactStep['action']['choices'] ?? []), 'label'));

        $draftStep = $catalog->step('preview_outreach_message');
        $this->assertSame('Draft a message', (string) ($draftStep['title'] ?? ''));
        $this->assertSame(
            'Pick a message type. I\'ll put a draft here.',
            (string) ($draftStep['body'] ?? '')
        );
        $this->assertSame('[data-guided-demo-target="email-compose-body"]', (string) ($draftStep['target'] ?? ''));
        $this->assertSame('Place draft', (string) ($draftStep['action']['label'] ?? ''));
        $this->assertSame('Draft added', (string) ($draftStep['action']['completed_label'] ?? ''));
        $this->assertSame(['Problem', 'Quick win', 'Invite'], array_column((array) ($draftStep['action']['choices'] ?? []), 'label'));

        $replyStep = $catalog->step('simulate_reply_received');
        $this->assertSame('Add a buyer reply', (string) ($replyStep['title'] ?? ''));
        $this->assertSame(
            'Pick what the buyer asked. I\'ll add a demo reply.',
            (string) ($replyStep['body'] ?? '')
        );
        $this->assertSame('The reply shows what to do next.', (string) ($replyStep['why'] ?? ''));
        $this->assertSame('Reply added', (string) ($replyStep['action']['completed_label'] ?? ''));
        $this->assertSame('What did the buyer ask?', (string) ($replyStep['action']['prompt'] ?? ''));
        $this->assertSame('Adding the reply...', (string) ($replyStep['action']['working_label'] ?? ''));
        $this->assertSame(['Price', 'Next step', 'Short version'], array_column((array) ($replyStep['action']['choices'] ?? []), 'label'));

        $taskStep = $catalog->step('create_follow_up_task');
        $this->assertTrue((bool) ($taskStep['action']['auto_run_on_choice'] ?? false));
        $this->assertTrue((bool) ($catalog->clientStep('create_follow_up_task')['demo_action']['auto_run_on_choice'] ?? false));

        $quoteStep = $catalog->step('prepare_demo_quote');
        $this->assertSame('invoices.php', (string) ($quoteStep['page'] ?? ''));
        $this->assertSame('[data-guided-demo-target="invoice-demo-documents"]', (string) ($quoteStep['target'] ?? ''));
        $this->assertSame(['Starter quote', 'Deposit invoice', 'Simple proposal'], array_column((array) ($quoteStep['action']['choices'] ?? []), 'label'));

        $wrapStep = $catalog->step('wrap_up');
        $this->assertSame('Return to your dashboard', (string) ($wrapStep['title'] ?? ''));
        $this->assertSame('Next, I\'ll take you back to your workspace dashboard.', (string) ($wrapStep['body'] ?? ''));
        $this->assertSame('Open dashboard', (string) ($wrapStep['action_label'] ?? ''));

        foreach ($actionKeys as $actionKey) {
            $this->assertTrue($catalog->requiresAction($actionKey), 'Action should be required: ' . $actionKey);
        }

        $phases = array_column($catalog->phases(), 'key');
        $this->assertSame(['workday', 'contacts', 'messages', 'inbox', 'tasks', 'money', 'modules', 'review', 'next'], $phases);

        foreach (['modules_channels', 'modules_calendar', 'modules_finance', 'modules_ai_coach'] as $moduleStep) {
            $this->assertFalse($catalog->requiresAction($moduleStep), 'Module discovery should not require setup: ' . $moduleStep);
        }
    }

    public function testContactRouteUsesSessionPrimaryContact(): void
    {
        $catalog = new GuidedDemoStepCatalog();
        $route = $catalog->routeFor('contact_detail', [
            'metadata_json' => json_encode(['primary_contact_id' => 123]),
        ]);

        $this->assertSame('contact_view.php?guided_demo=1&id=123', $route);
    }

    public function testComposerRouteUsesSessionPrimaryContact(): void
    {
        $catalog = new GuidedDemoStepCatalog();
        $route = $catalog->routeFor('preview_outreach_message', [
            'metadata_json' => json_encode(['primary_contact_id' => 123]),
        ]);

        $this->assertSame('email_compose.php?guided_demo=1&contact_id=123', $route);
    }

    public function testModuleRoutesCarryRequestedModule(): void
    {
        $catalog = new GuidedDemoStepCatalog();

        $this->assertSame('workspace_skills.php?guided_demo=1&module=calendar_meetings', $catalog->routeFor('modules_calendar'));
        $this->assertSame('workspace_skills.php?guided_demo=1&module=finance', $catalog->routeFor('modules_finance'));
        $this->assertSame('workspace_skills.php?guided_demo=1&module=ai_coach', $catalog->routeFor('modules_ai_coach'));
    }

    public function testEveryStepCarriesPhaseLessonTargetAndRequiredActionShape(): void
    {
        $catalog = new GuidedDemoStepCatalog();
        $expectedPhases = [
            'dashboard_today' => 'workday',
            'add_demo_contact' => 'contacts',
            'contact_detail' => 'contacts',
            'preview_outreach_message' => 'messages',
            'simulate_reply_received' => 'inbox',
            'create_follow_up_task' => 'tasks',
            'prepare_demo_quote' => 'money',
            'dashboard_rollup' => 'workday',
            'modules_channels' => 'modules',
            'modules_calendar' => 'modules',
            'modules_finance' => 'modules',
            'modules_ai_coach' => 'modules',
            'weekly_review' => 'review',
            'wrap_up' => 'next',
        ];

        foreach ($catalog->steps() as $step) {
            $key = (string) ($step['key'] ?? '');
            $this->assertArrayHasKey($key, $expectedPhases);
            $this->assertSame($expectedPhases[$key], (string) ($step['phase'] ?? ''), 'Unexpected phase for ' . $key);

            foreach (['phase_label', 'title', 'body', 'why', 'page', 'target', 'fallback_target', 'action_label'] as $field) {
                $this->assertNotSame('', trim((string) ($step[$field] ?? '')), $field . ' should be present for ' . $key);
            }

            $this->assertLessThanOrEqual(80, strlen((string) ($step['title'] ?? '')), 'Title should stay focused for ' . $key);
            $this->assertLessThanOrEqual(190, strlen((string) ($step['body'] ?? '')), 'Body should stay concise for ' . $key);
            $this->assertLessThanOrEqual(140, strlen((string) ($step['why'] ?? '')), 'Lesson should stay concise for ' . $key);
            $this->assertNotSame((string) ($step['body'] ?? ''), (string) ($step['why'] ?? ''), 'Lesson should add distinct context for ' . $key);

            $clientStep = $catalog->clientStep($key);
            $this->assertSame((string) ($step['phase_label'] ?? ''), (string) ($clientStep['phase_label'] ?? ''));
            $this->assertSame((string) ($step['why'] ?? ''), (string) ($clientStep['why'] ?? ''));
            $this->assertSame($key === 'create_follow_up_task', (bool) (($clientStep['demo_action']['auto_run_on_choice'] ?? false)));

            $action = is_array($step['action'] ?? null) ? $step['action'] : null;
            if ($action === null) {
                $this->assertFalse($catalog->requiresAction($key), 'Step should not block without an action: ' . $key);
                continue;
            }

            foreach (['key', 'label', 'completed_label', 'prompt', 'working_label'] as $field) {
                $this->assertNotSame('', trim((string) ($action[$field] ?? '')), 'Action ' . $field . ' should be present for ' . $key);
            }
            $this->assertNotEmpty((array) ($action['choices'] ?? []), 'Required action choices should be present for ' . $key);
            foreach ((array) ($action['choices'] ?? []) as $choice) {
                $this->assertIsArray($choice, 'Choice should be structured for ' . $key);
                $this->assertNotSame('', trim((string) ($choice['key'] ?? '')), 'Choice key should be present for ' . $key);
                $this->assertNotSame('', trim((string) ($choice['label'] ?? '')), 'Choice label should be present for ' . $key);
            }
            $this->assertTrue((bool) ($action['required'] ?? false), 'Guided action should be required for ' . $key);
            $this->assertSame($key, (string) ($action['key'] ?? ''), 'Action key should match step key for ' . $key);
            $this->assertTrue($catalog->requiresAction($key), 'Catalog should block for required action: ' . $key);
        }
    }

    public function testVisibleGuidedDemoCopyUsesPlainSecondPersonVoice(): void
    {
        $catalog = new GuidedDemoStepCatalog();
        $forbidden = [
            'the founder',
            'the crm',
            'the system',
            'commercial outcome',
            'execution layer',
            'pick your first offer',
            'what do you want to sell',
            'business model',
            'angle',
            'signals',
            'visibility',
            'inspect',
            'execution',
        ];

        foreach ($catalog->steps() as $step) {
            $visible = [
                (string) ($step['title'] ?? ''),
                (string) ($step['body'] ?? ''),
                (string) ($step['why'] ?? ''),
                (string) ($step['action_label'] ?? ''),
            ];
            $action = is_array($step['action'] ?? null) ? $step['action'] : [];
            foreach (['label', 'completed_label', 'prompt', 'working_label'] as $field) {
                $visible[] = (string) ($action[$field] ?? '');
            }
            foreach ((array) ($action['choices'] ?? []) as $choice) {
                $visible[] = is_array($choice) ? (string) ($choice['label'] ?? '') : '';
            }

            $copy = strtolower(implode(' ', $visible));
            foreach ($forbidden as $phrase) {
                $this->assertStringNotContainsString($phrase, $copy, 'Forbidden phrase in step ' . (string) ($step['key'] ?? ''));
            }

            foreach ($visible as $text) {
                $words = preg_split('/\s+/', trim((string) $text));
                if ($words === false || $words === ['']) {
                    continue;
                }
                $this->assertLessThanOrEqual(12, count($words), 'Visible copy should stay short in step ' . (string) ($step['key'] ?? ''));
            }
        }
    }
}
