<?php

namespace CRM\Services;

class WhatsAppAssistantFormatter
{
    private int $maxChars;
    private int $maxChunks;

    public function __construct(?int $maxChars = null, ?int $maxChunks = null)
    {
        $this->maxChars = max(180, (int) ($maxChars ?? ($_ENV['WHATSAPP_ASSISTANT_MAX_MESSAGE_CHARS'] ?? 550)));
        $this->maxChunks = max(2, (int) ($maxChunks ?? ($_ENV['WHATSAPP_ASSISTANT_MAX_MESSAGE_CHUNKS'] ?? 6)));
    }

    public function formatReply(string $body): array
    {
        $normalized = $this->normalizeText($body);
        if ($normalized === '') {
            return ['I could not generate a WhatsApp-ready response.'];
        }

        if ($this->textLength($normalized) <= $this->maxChars) {
            return [$normalized];
        }

        $summary = $this->buildSummary($normalized);
        $chunks = $this->chunkText($normalized);
        if (count($chunks) <= 1) {
            return [$summary];
        }

        $messages = [$summary];
        $total = min(count($chunks), $this->maxChunks - 1);
        for ($i = 0; $i < $total; $i++) {
            $messages[] = 'Part ' . ($i + 1) . '/' . $total . "\n" . $chunks[$i];
        }

        if (count($chunks) > $total) {
            $messages[] = 'More detail is available in CRM.';
        }

        return $messages;
    }

    public function formatDigest(array $tasks, array $recommendations, bool $isTest = false, array $context = []): array
    {
        $runAt = $context['run_at'] instanceof \DateTimeInterface
            ? $context['run_at']
            : new \DateTimeImmutable('now');
        $workspaceName = $this->sanitizeMarkdownText((string) ($context['workspace_name'] ?? ''));
        $recipientName = $this->sanitizeMarkdownText((string) ($context['recipient_name'] ?? ''));
        $tasksUrl = $this->sanitizeUrl((string) ($context['tasks_url'] ?? ''));

        $baseTitle = $isTest ? '*CRM Daily Digest (Test)*' : '*CRM Daily Digest*';
        $counts = $this->countDigestBuckets($tasks, $runAt);

        $meta = [$runAt->format('D, M j')];
        if ($workspaceName !== '') {
            $meta[] = $workspaceName;
        }
        if ($recipientName !== '') {
            $meta[] = 'For ' . $recipientName;
        }

        $sections = [];
        $sections[] = implode("\n", [
            $baseTitle,
            '> ' . implode(' | ', $meta),
            '> "' . $this->buildDigestLeadQuote($counts) . '"',
        ]);
        $sections[] = implode("\n", [
            '*At a glance*',
            '`OVERDUE` ' . $counts['overdue'] . ' | `TODAY` ' . $counts['due_today'] . ' | `OPEN` ' . count($tasks),
        ]);

        $prioritySection = $this->buildPrioritySection($recommendations, $tasks, $runAt);
        if ($prioritySection !== '') {
            $sections[] = $prioritySection;
        }

        $visibleTasks = array_slice($tasks, 0, 5);
        $groupedTasks = [
            'overdue' => [],
            'due_today' => [],
            'unscheduled' => [],
            'future' => [],
        ];
        foreach ($visibleTasks as $task) {
            $bucket = (string) ($task['digest_bucket'] ?? $this->inferDigestBucket($task, $runAt));
            if (!array_key_exists($bucket, $groupedTasks)) {
                $bucket = 'unscheduled';
            }
            $groupedTasks[$bucket][] = $task;
        }

        $taskSectionAdded = false;
        foreach ([
            'overdue' => 'Overdue',
            'due_today' => 'Due today',
            'unscheduled' => 'No date',
            'future' => 'Coming up',
        ] as $bucket => $label) {
            if ($groupedTasks[$bucket] === []) {
                continue;
            }
            $taskLines = ['*' . $label . '*'];
            foreach ($groupedTasks[$bucket] as $task) {
                $taskLines[] = $this->formatTaskBlock((array) $task, $runAt);
            }
            $sections[] = implode("\n", $taskLines);
            $taskSectionAdded = true;
        }

        if (!$taskSectionAdded) {
            $sections[] = implode("\n", [
                '*Tasks*',
                '> No overdue or due tasks.',
            ]);
        } else {
            $hiddenTaskCount = max(0, count($tasks) - count($visibleTasks));
            if ($hiddenTaskCount > 0) {
                $sections[] = implode("\n", [
                    '*More in CRM*',
                    '> ' . $hiddenTaskCount . ' more task' . ($hiddenTaskCount === 1 ? '' : 's') . ' beyond this WhatsApp preview.',
                ]);
            }
        }

        $quickWinLines = [];
        foreach (array_slice((array) ($recommendations['quick_wins'] ?? []), 0, 2) as $item) {
            $title = $this->sanitizeMarkdownText((string) ($item['title'] ?? ''));
            if ($title !== '') {
                $quickWinLines[] = '> ' . $this->truncateLine($title, 120);
            }
        }
        if ($quickWinLines !== []) {
            array_unshift($quickWinLines, '*Quick wins*');
            $sections[] = implode("\n", $quickWinLines);
        }

        $sections[] = implode("\n", [
            '*Open CRM*',
            $tasksUrl !== '' ? $tasksUrl : 'Open CRM for full details.',
        ]);

        return $this->formatDigestChunks($sections, $baseTitle);
    }

    private function buildPrioritySection(array $recommendations, array $tasks, \DateTimeInterface $runAt): string
    {
        $priorityLines = [];
        $seen = [];
        foreach (array_slice((array) ($recommendations['priorities'] ?? []), 0, 3) as $item) {
            $title = $this->sanitizeMarkdownText((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $key = strtolower($title);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $priorityLines[] = $this->formatPriorityLine(count($priorityLines) + 1, $title, []);
        }

        if ($priorityLines === []) {
            foreach (array_slice($tasks, 0, 3) as $task) {
                $title = $this->sanitizeMarkdownText((string) ($task['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $priorityLines[] = $this->formatPriorityLine(count($priorityLines) + 1, $title, (array) $task, $runAt);
            }
        }

        if ($priorityLines === []) {
            return '';
        }

        return "*Top priorities*\n" . implode("\n", $priorityLines);
    }

    private function formatPriorityLine(int $index, string $title, array $task = [], ?\DateTimeInterface $runAt = null): string
    {
        $lines = [$index . '. *' . $this->truncateLine($title, 120) . '*'];
        $details = $task !== [] && $runAt !== null ? $this->taskDetailParts($task, $runAt) : [];
        if ($details !== []) {
            $lines[] = '   ' . implode(' | ', $details);
        }

        return implode("\n", $lines);
    }

    private function formatTaskBlock(array $task, \DateTimeInterface $runAt): string
    {
        $title = $this->sanitizeMarkdownText((string) ($task['title'] ?? 'Task'), 'Task');
        if ($title === '') {
            $title = 'Task';
        }

        $lines = ['*' . $this->truncateLine($title, 140) . '*'];
        $parts = $this->taskDetailParts($task, $runAt);
        if ($parts !== []) {
            $lines[] = implode(' | ', $parts);
        }

        return implode("\n", $lines);
    }

    private function taskDetailParts(array $task, \DateTimeInterface $runAt): array
    {
        $parts = [];
        $priority = strtolower(trim((string) ($task['priority'] ?? '')));
        if (in_array($priority, ['urgent', 'high', 'medium', 'low'], true)) {
            $parts[] = '`' . strtoupper($priority) . '`';
        }

        $dueLabel = $this->formatDueDate((string) ($task['due_date'] ?? ''), $runAt);
        if ($dueLabel !== '') {
            $parts[] = 'due ' . $dueLabel;
        }

        $contactName = $this->sanitizeMarkdownText((string) ($task['contact_name'] ?? ''));
        $company = $this->sanitizeMarkdownText((string) ($task['contact_company'] ?? $task['company'] ?? ''));
        if ($contactName !== '' && $company !== '') {
            $parts[] = $contactName . ' / ' . $company;
        } elseif ($contactName !== '') {
            $parts[] = $contactName;
        } elseif ($company !== '') {
            $parts[] = $company;
        }

        return $parts;
    }

    private function inferDigestBucket(array $task, \DateTimeInterface $runAt): string
    {
        $due = trim((string) ($task['due_date'] ?? ''));
        if ($due === '') {
            return 'unscheduled';
        }

        $timestamp = strtotime($due);
        if ($timestamp === false) {
            return 'unscheduled';
        }

        $dueDay = date('Y-m-d', $timestamp);
        $today = $runAt->format('Y-m-d');
        if ($dueDay < $today) {
            return 'overdue';
        }
        if ($dueDay === $today) {
            return 'due_today';
        }

        return 'future';
    }

    private function formatDueDate(string $dueDate, \DateTimeInterface $runAt): string
    {
        $dueDate = trim($dueDate);
        if ($dueDate === '') {
            return '';
        }

        $timestamp = strtotime($dueDate);
        if ($timestamp === false) {
            return '';
        }

        if (date('Y-m-d', $timestamp) === $runAt->format('Y-m-d')) {
            return 'today';
        }

        return date('M j', $timestamp);
    }

    private function truncateLine(string $line, int $limit): string
    {
        $line = trim($line);
        if ($this->textLength($line) <= $limit) {
            return $line;
        }

        return rtrim($this->safeSubstr($line, 0, max(1, $limit - 3))) . '...';
    }

    private function buildDigestLeadQuote(array $counts): string
    {
        if ((int) ($counts['overdue'] ?? 0) > 0 && (int) ($counts['due_today'] ?? 0) > 0) {
            return 'Today: protect overdue commitments, then clear what is due now.';
        }
        if ((int) ($counts['overdue'] ?? 0) > 0) {
            return 'Today: recover overdue commitments before they age further.';
        }
        if ((int) ($counts['due_today'] ?? 0) > 0) {
            return 'Today: clear due work while the window is still fresh.';
        }
        if ((int) ($counts['open'] ?? 0) === 0) {
            return 'Today: no urgent task pressure; use the room to strengthen follow-up.';
        }

        return 'Today: keep momentum moving through the visible CRM queue.';
    }

    private function countDigestBuckets(array $tasks, \DateTimeInterface $runAt): array
    {
        $counts = [
            'overdue' => 0,
            'due_today' => 0,
            'unscheduled' => 0,
            'future' => 0,
            'open' => count($tasks),
        ];

        foreach ($tasks as $task) {
            $bucket = (string) (((array) $task)['digest_bucket'] ?? $this->inferDigestBucket((array) $task, $runAt));
            if (!array_key_exists($bucket, $counts)) {
                $bucket = 'unscheduled';
            }
            $counts[$bucket]++;
        }

        return $counts;
    }

    private function formatDigestChunks(array $sections, string $baseTitle): array
    {
        $full = implode("\n\n", $sections);
        if ($this->textLength($full) <= $this->maxChars) {
            return [$full];
        }

        $continuationPlaceholder = $baseTitle . ' (99/99)';
        $continuationLimit = max(80, $this->maxChars - $this->textLength($continuationPlaceholder) - 2);
        $chunks = [];
        $current = (string) array_shift($sections);
        $overflow = false;

        foreach ($sections as $section) {
            foreach ($this->splitDigestSection((string) $section, $continuationLimit) as $piece) {
                $candidate = $current === '' ? $piece : $current . "\n\n" . $piece;
                if ($this->textLength($candidate) <= $this->maxChars) {
                    $current = $candidate;
                    continue;
                }

                if (count($chunks) >= $this->maxChunks - 1) {
                    $overflow = true;
                    break 2;
                }

                if ($current !== '') {
                    $chunks[] = $current;
                }
                $current = $continuationPlaceholder . "\n\n" . $piece;
            }
        }

        if ($overflow) {
            $current = $this->appendDigestOverflowNotice($current);
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        $total = count($chunks);
        if ($total <= 1) {
            return $chunks;
        }

        foreach ($chunks as $index => $chunk) {
            if ($index === 0) {
                continue;
            }
            $chunks[$index] = preg_replace(
                '/^\*CRM Daily Digest(?: \(Test\))?\* \(99\/99\)/',
                $baseTitle . ' (' . ($index + 1) . '/' . $total . ')',
                $chunk,
                1
            ) ?? $chunk;
        }

        return $chunks;
    }

    private function splitDigestSection(string $section, int $limit): array
    {
        if ($this->textLength($section) <= $limit) {
            return [$section];
        }

        $lines = explode("\n", $section);
        $heading = (string) array_shift($lines);
        $pieces = [];
        $current = $heading;

        foreach ($lines as $line) {
            $candidate = $current . "\n" . $line;
            if ($this->textLength($candidate) <= $limit) {
                $current = $candidate;
                continue;
            }

            if ($current !== $heading) {
                $pieces[] = $current;
                $current = $heading;
            }

            $lineLimit = max(20, $limit - $this->textLength($heading) - 1);
            $truncatedLine = $this->truncateLine($line, $lineLimit);
            $candidate = $heading . "\n" . $truncatedLine;
            if ($this->textLength($candidate) <= $limit) {
                $current = $candidate;
                continue;
            }

            $pieces[] = $this->truncateLine($section, $limit);
            $current = '';
        }

        if ($current !== '') {
            $pieces[] = $current;
        }

        return array_values(array_filter($pieces, static fn(string $piece): bool => trim($piece) !== ''));
    }

    private function appendDigestOverflowNotice(string $current): string
    {
        $notice = "*More in CRM*\n> More detail is available in CRM.";
        $candidate = $current . "\n\n" . $notice;
        if ($this->textLength($candidate) <= $this->maxChars) {
            return $candidate;
        }

        $shortNotice = '> More detail is available in CRM.';
        $candidate = $current . "\n" . $shortNotice;
        if ($this->textLength($candidate) <= $this->maxChars) {
            return $candidate;
        }

        return $this->truncateLine($current, max(1, $this->maxChars - $this->textLength($shortNotice) - 1)) . "\n" . $shortNotice;
    }

    private function sanitizeMarkdownText(string $text, string $fallback = ''): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
        $text = str_replace(['*', '_', '`', '~'], '', $text);
        $text = str_replace('|', '/', $text);
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);
        $text = ltrim($text, '> ');

        return $text !== '' ? $text : $fallback;
    }

    private function sanitizeUrl(string $url): string
    {
        $url = html_entity_decode(strip_tags($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = preg_replace('/\s+/', '', trim($url)) ?? '';
        return $url;
    }

    private function buildSummary(string $text): string
    {
        $firstParagraph = preg_split('/\n\s*\n/', $text, 2)[0] ?? $text;
        $firstParagraph = trim($firstParagraph);
        $summaryLimit = min(220, $this->maxChars - 40);
        if ($this->textLength($firstParagraph) > $summaryLimit) {
            $firstParagraph = $this->safeSubstr($firstParagraph, 0, $summaryLimit - 3) . '...';
        }

        return 'Summary' . "\n" . $firstParagraph;
    }

    private function chunkText(string $text): array
    {
        $chunks = [];
        $remaining = $text;
        while ($remaining !== '') {
            if ($this->textLength($remaining) <= $this->maxChars) {
                $chunks[] = $remaining;
                break;
            }

            $slice = $this->safeSubstr($remaining, 0, $this->maxChars);
            $breakAt = max(
                (int) strrpos($slice, "\n"),
                (int) strrpos($slice, '. '),
                (int) strrpos($slice, '; '),
                (int) strrpos($slice, ', ')
            );
            if ($breakAt < (int) floor($this->maxChars * 0.55)) {
                $breakAt = $this->maxChars;
            }

            $chunk = trim($this->safeSubstr($remaining, 0, $breakAt));
            $chunks[] = $chunk;
            $remaining = trim($this->safeSubstr($remaining, $breakAt));
        }

        return $chunks;
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim(strip_tags($text)));
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function textLength(string $text): int
    {
        return function_exists('mb_strlen') ? (int) mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private function safeSubstr(string $text, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return $length === null
                ? (string) mb_substr($text, $start, null, 'UTF-8')
                : (string) mb_substr($text, $start, $length, 'UTF-8');
        }

        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }
}
