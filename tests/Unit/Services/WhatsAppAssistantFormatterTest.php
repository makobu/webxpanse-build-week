<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\WhatsAppAssistantFormatter;
use CRM\Tests\TestCase;

class WhatsAppAssistantFormatterTest extends TestCase
{
    public function testEmptyDigestKeepsWorkspaceRecipientAndFallbackLine(): void
    {
        $messages = (new WhatsAppAssistantFormatter())->formatDigest([], [], false, [
            'workspace_name' => 'Acme Workspace',
            'recipient_name' => 'Ada Lovelace',
            'tasks_url' => 'https://crm.example.test/tasks.php',
            'run_at' => new \DateTimeImmutable('2026-06-10 08:00:00'),
        ]);

        $body = implode("\n---\n", $messages);

        $this->assertSame(1, count($messages));
        $this->assertStringContainsString('*CRM Daily Digest*', $body);
        $this->assertStringContainsString('> Wed, Jun 10 | Acme Workspace | For Ada Lovelace', $body);
        $this->assertStringContainsString('> "Today: no urgent task pressure; use the room to strengthen follow-up."', $body);
        $this->assertStringContainsString('*At a glance*', $body);
        $this->assertStringContainsString('`OVERDUE` 0 | `TODAY` 0 | `OPEN` 0', $body);
        $this->assertStringContainsString("*Tasks*\n> No overdue or due tasks.", $body);
        $this->assertStringContainsString("*Open CRM*\nhttps://crm.example.test/tasks.php", $body);
    }

    public function testDigestGroupsOverdueTodayAndNoDateTasksWithContext(): void
    {
        $messages = (new WhatsAppAssistantFormatter())->formatDigest([
            [
                'title' => 'Call overdue lead',
                'priority' => 'urgent',
                'due_date' => '2026-06-09 09:00:00',
                'contact_name' => 'Grace Hopper',
                'contact_company' => 'Compiler Co',
            ],
            [
                'title' => 'Send proposal today',
                'priority' => 'high',
                'due_date' => '2026-06-10 12:00:00',
            ],
            [
                'title' => 'Triage unplanned customer note',
                'priority' => 'medium',
                'due_date' => null,
                'company' => 'No Date Ltd',
            ],
        ], [
            'priorities' => [
                ['title' => 'Protect overdue customer follow-up'],
            ],
        ], true, [
            'workspace_name' => 'Operator Desk',
            'recipient_name' => 'Digest Owner',
            'run_at' => new \DateTimeImmutable('2026-06-10 08:00:00'),
        ]);

        $body = implode("\n---\n", $messages);

        $this->assertStringContainsString('*CRM Daily Digest (Test)*', $body);
        $this->assertStringContainsString('> Wed, Jun 10 | Operator Desk | For Digest Owner', $body);
        $this->assertStringContainsString('> "Today: protect overdue commitments, then clear what is due now."', $body);
        $this->assertStringContainsString('*Top priorities*', $body);
        $this->assertStringContainsString('1. *Protect overdue customer follow-up*', $body);
        $this->assertStringContainsString('*Overdue*', $body);
        $this->assertStringContainsString("*Call overdue lead*\n`URGENT` | due Jun 9 | Grace Hopper / Compiler Co", $body);
        $this->assertStringContainsString('*Due today*', $body);
        $this->assertStringContainsString("*Send proposal today*\n`HIGH` | due today", $body);
        $this->assertStringContainsString('*No date*', $body);
        $this->assertStringContainsString("*Triage unplanned customer note*\n`MEDIUM` | No Date Ltd", $body);
        $this->assertLessThan(
            strpos($body, '*Due today*'),
            strpos($body, '*Overdue*')
        );
    }

    public function testDigestTruncatesAndChunksLongContentWithContinuationHeaders(): void
    {
        $longTitle = str_repeat('Very long customer follow up ', 8);
        $tasks = [];
        for ($i = 1; $i <= 8; $i++) {
            $tasks[] = [
                'title' => $longTitle . $i,
                'priority' => 'high',
                'due_date' => '2026-06-10 09:00:00',
            ];
        }

        $messages = (new WhatsAppAssistantFormatter(220, 4))->formatDigest($tasks, [
            'quick_wins' => [
                ['title' => str_repeat('Review ', 40)],
            ],
        ], false, [
            'workspace_name' => 'Long Ops',
            'recipient_name' => 'Long Recipient',
            'run_at' => new \DateTimeImmutable('2026-06-10 08:00:00'),
        ]);

        $body = implode("\n", $messages);

        $this->assertGreaterThan(1, count($messages));
        foreach ($messages as $message) {
            $this->assertLessThanOrEqual(220, strlen($message));
        }
        $this->assertStringStartsWith('*CRM Daily Digest*', $messages[0]);
        $this->assertStringStartsWith('*CRM Daily Digest* (2/', $messages[1]);
        $this->assertStringNotContainsString('Summary', $body);
        $this->assertStringContainsString('...', $body);
    }

    public function testInvalidDatesDoNotRenderEpochDueDates(): void
    {
        $messages = (new WhatsAppAssistantFormatter())->formatDigest([
            [
                'title' => 'Fix malformed task date',
                'priority' => 'medium',
                'due_date' => 'not-a-date',
            ],
        ], [], false, [
            'run_at' => new \DateTimeImmutable('2026-06-10 08:00:00'),
        ]);

        $body = implode("\n", $messages);

        $this->assertStringContainsString('*No date*', $body);
        $this->assertStringContainsString("*Fix malformed task date*\n`MEDIUM`", $body);
        $this->assertStringNotContainsString('Jan 1', $body);
        $this->assertStringNotContainsString('1970', $body);
    }

    public function testDigestShowsVisibleCountLimit(): void
    {
        $tasks = [];
        for ($i = 1; $i <= 7; $i++) {
            $tasks[] = [
                'title' => 'Visible task ' . $i,
                'priority' => 'low',
                'due_date' => '2026-06-10 09:00:00',
            ];
        }

        $messages = (new WhatsAppAssistantFormatter())->formatDigest($tasks, [], false, [
            'run_at' => new \DateTimeImmutable('2026-06-10 08:00:00'),
        ]);

        $body = implode("\n", $messages);

        $this->assertStringContainsString('Visible task 1', $body);
        $this->assertStringContainsString('Visible task 5', $body);
        $this->assertStringNotContainsString('Visible task 6', $body);
        $this->assertStringContainsString("*More in CRM*\n> 2 more tasks beyond this WhatsApp preview.", $body);
    }

    public function testDigestSanitizesUserTextBeforeApplyingWhatsappMarkdown(): void
    {
        $messages = (new WhatsAppAssistantFormatter())->formatDigest([
            [
                'title' => "Call *VIP* _lead_ `now` <b>today</b>\nline | pipe > quote",
                'priority' => 'urgent',
                'due_date' => '2026-06-10 09:00:00',
                'contact_name' => 'Grace_*',
                'contact_company' => '`Compiler` | Co',
            ],
        ], [
            'priorities' => [
                ['title' => 'Protect *raw* _priority_ `marker`'],
            ],
            'quick_wins' => [
                ['title' => "> Review <i>one</i> `quick` _win_"],
            ],
        ], false, [
            'workspace_name' => 'Ops_* `desk`',
            'recipient_name' => 'Ada_Boss',
            'run_at' => new \DateTimeImmutable('2026-06-10 08:00:00'),
        ]);

        $body = implode("\n", $messages);

        $this->assertStringContainsString('> Wed, Jun 10 | Ops desk | For AdaBoss', $body);
        $this->assertStringContainsString('1. *Protect raw priority marker*', $body);
        $this->assertStringContainsString('*Call VIP lead now today line / pipe > quote*', $body);
        $this->assertStringContainsString('`URGENT` | due today | Grace / Compiler / Co', $body);
        $this->assertStringContainsString('> Review one quick win', $body);
        $this->assertStringNotContainsString('*VIP*', $body);
        $this->assertStringNotContainsString('_lead_', $body);
        $this->assertStringNotContainsString('`now`', $body);
        $this->assertStringNotContainsString('<b>', $body);
    }
}
