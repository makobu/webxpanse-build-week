<?php
/**
 * Assistant Email Renderer
 *
 * Produces safe HTML and plain-text for Email Assistant reply and digest emails.
 * Uses inline CSS only for reliable rendering across Gmail, Outlook, and Apple Mail.
 */

namespace CRM\Services;

class AssistantEmailRenderer
{
    private const CONTAINER_STYLE = 'font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 0 16px;';
    private const HEADING_STYLE = 'color: #1e293b; font-size: 18px; font-weight: 600; margin: 0 0 12px 0;';
    private const SECTION_STYLE = 'margin: 16px 0;';
    private const PARAGRAPH_STYLE = 'margin: 0 0 12px 0;';
    private const FOOTER_STYLE = 'color: #94a3b8; font-size: 12px; margin-top: 24px;';

    /**
     * Render assistant reply body as HTML and plain text.
     *
     * @param string $plainBody Plain-text response content
     * @return array{html: string, plain: string}
     */
    public function renderReply(string $plainBody): array
    {
        return $this->renderInternalReply($plainBody);
    }

    public function renderInternalReply(string $plainBody): array
    {
        $plain = trim($plainBody);
        $escaped = htmlspecialchars($plain, ENT_QUOTES, 'UTF-8');
        $htmlContent = nl2br($escaped);

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="' . self::CONTAINER_STYLE . '">';
        $html .= '<div style="' . self::SECTION_STYLE . '">';
        $html .= '<p style="' . self::PARAGRAPH_STYLE . '">' . $htmlContent . '</p>';
        $html .= '</div>';
        $html .= '<p style="' . self::FOOTER_STYLE . '">Email Assistant</p>';
        $html .= '</body></html>';

        return ['html' => $html, 'plain' => $plain];
    }

    public function renderCustomerCommercialReply(string $plainBody, array $options = []): array
    {
        $plain = trim($plainBody);
        $escaped = nl2br(htmlspecialchars($plain, ENT_QUOTES, 'UTF-8'));
        $heading = htmlspecialchars((string) ($options['heading'] ?? 'Commercial Update'), ENT_QUOTES, 'UTF-8');
        $docRef = trim((string) ($options['document_reference'] ?? ''));
        $cta = trim((string) ($options['cta'] ?? 'Reply to this email if you want anything adjusted.'));

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body style="' . self::CONTAINER_STYLE . '">';
        $html .= '<div style="padding:16px 0 8px;border-bottom:1px solid #e2e8f0;margin-bottom:16px;">';
        $html .= '<h2 style="' . self::HEADING_STYLE . '">' . $heading . '</h2>';
        if ($docRef !== '') {
            $html .= '<p style="margin:0;color:#64748b;font-size:13px;">Reference: ' . htmlspecialchars($docRef, ENT_QUOTES, 'UTF-8') . '</p>';
        }
        $html .= '</div>';
        $html .= '<div style="' . self::SECTION_STYLE . '"><p style="' . self::PARAGRAPH_STYLE . '">' . $escaped . '</p></div>';
        $html .= '<div style="margin:18px 0;padding:12px 14px;border:1px solid #dbeafe;background:#f8fbff;border-radius:8px;color:#334155;font-size:14px;">' . htmlspecialchars($cta, ENT_QUOTES, 'UTF-8') . '</div>';
        $html .= '<p style="' . self::FOOTER_STYLE . '">Sent by your CRM assistant.</p>';
        $html .= '</body></html>';

        return ['html' => $html, 'plain' => $plain];
    }

    public function renderApprovalDigest(string $plainBody): array
    {
        return $this->renderInternalReply($plainBody);
    }

    /**
     * Render daily digest as HTML and plain text.
     *
     * @param array $tasks Array of task records
     * @param array $recs AI recommendations (priorities, quick_wins, why_this_matters)
     * @param bool $isTest Whether this is a test digest
     * @return array{html: string, plain: string}
     */
    public function renderDigest(array $tasks, array $recs, bool $isTest = false, ?\DateTimeInterface $runAt = null, array $options = []): array
    {
        $runAt = $runAt ?? new \DateTimeImmutable('now');
        $title = $isTest ? 'Your CRM Daily Digest (Test)' : 'Your CRM Daily Digest';
        $dateStr = $runAt->format('l, F j, Y');
        $workspaceName = trim((string) ($options['workspace_name'] ?? ''));
        $tasksUrl = $this->safeDigestUrl((string) ($options['tasks_url'] ?? ''));
        $taskGroups = $this->groupDigestTasks($tasks, $runAt);
        $recommendations = $this->normalizeDigestRecommendations($recs);
        $summary = $this->buildDigestSummary($taskGroups, $recommendations);
        $preheader = $this->buildDigestPreheader($summary, $workspaceName);

        return [
            'html' => $this->renderDigestHtml($title, $dateStr, $workspaceName, $taskGroups, $recommendations, $summary, $preheader, $tasksUrl, $isTest, $runAt),
            'plain' => $this->renderDigestPlain($title, $dateStr, $workspaceName, $taskGroups, $recommendations, $summary, $tasksUrl, $runAt),
        ];
    }

    private function renderDigestHtml(
        string $title,
        string $dateStr,
        string $workspaceName,
        array $taskGroups,
        array $recommendations,
        array $summary,
        string $preheader,
        string $tasksUrl,
        bool $isTest,
        \DateTimeInterface $runAt
    ): string {
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $this->e($title) . '</title></head>';
        $html .= '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;-webkit-text-size-adjust:100%;text-size-adjust:100%;">';
        $html .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;line-height:1px;font-size:1px;">' . $this->e($preheader) . '</div>';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;background:#f1f5f9;border-collapse:collapse;"><tr><td align="center" style="padding:28px 12px;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:640px;background:#ffffff;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;border-collapse:separate;">';
        $html .= $this->renderDigestHeaderHtml($title, $dateStr, $workspaceName, $isTest);
        $html .= $this->renderSummaryCardsHtml($summary);
        $html .= $this->renderTaskGroupsHtml($taskGroups, $tasksUrl, $runAt);
        $html .= $this->renderRecommendationsHtml($recommendations);
        $html .= $this->renderDigestFooterHtml();
        $html .= '</table></td></tr></table></body></html>';

        return $html;
    }

    private function renderDigestHeaderHtml(string $title, string $dateStr, string $workspaceName, bool $isTest): string
    {
        $context = $workspaceName !== '' ? $workspaceName . ' - ' . $dateStr : $dateStr;
        $badge = $isTest
            ? '<span style="display:inline-block;background:#dbeafe;color:#1d4ed8;border:1px solid #93c5fd;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">Test Send</span>'
            : '';

        $html = '<tr><td style="background:#0f172a;padding:26px 28px 24px 28px;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;"><tr>';
        $html .= '<td style="vertical-align:top;">';
        $html .= '<div style="margin:0 0 10px 0;color:#bfdbfe;font-size:13px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Email Assistant</div>';
        $html .= '<h1 style="margin:0;color:#ffffff;font-size:28px;line-height:1.2;font-weight:700;">' . $this->e($title) . '</h1>';
        $html .= '<p style="margin:10px 0 0 0;color:#cbd5e1;font-size:14px;line-height:1.5;">' . $this->e($context) . '</p>';
        $html .= '</td>';
        $html .= '<td align="right" style="vertical-align:top;width:110px;">' . $badge . '</td>';
        $html .= '</tr></table></td></tr>';

        return $html;
    }

    private function renderSummaryCardsHtml(array $summary): string
    {
        $cards = [
            ['label' => 'Overdue', 'value' => (int) $summary['overdue'], 'color' => '#dc2626', 'background' => '#fef2f2'],
            ['label' => 'Due Today', 'value' => (int) $summary['due_today'], 'color' => '#2563eb', 'background' => '#eff6ff'],
            ['label' => 'Unscheduled', 'value' => (int) $summary['unscheduled'], 'color' => '#475569', 'background' => '#f8fafc'],
            ['label' => 'AI Recommendations', 'value' => (int) $summary['recommendations'], 'color' => '#059669', 'background' => '#ecfdf5'],
        ];

        $html = '<tr><td style="padding:22px 24px 8px 24px;background:#ffffff;">';
        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:separate;border-spacing:6px;">';
        $html .= '<tr>';
        foreach ($cards as $card) {
            $html .= '<td width="25%" style="background:' . $card['background'] . ';border:1px solid #e2e8f0;border-radius:12px;padding:13px 10px;text-align:center;vertical-align:top;">';
            $html .= '<div style="color:' . $card['color'] . ';font-size:24px;line-height:1;font-weight:800;">' . (int) $card['value'] . '</div>';
            $html .= '<div style="margin-top:6px;color:#475569;font-size:11px;line-height:1.25;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">' . $this->e($card['label']) . '</div>';
            $html .= '</td>';
        }
        $html .= '</tr></table></td></tr>';

        return $html;
    }

    private function renderTaskGroupsHtml(array $taskGroups, string $tasksUrl, \DateTimeInterface $runAt): string
    {
        $taskCount = array_sum(array_map('count', $taskGroups));
        $html = '<tr><td style="padding:12px 28px 4px 28px;background:#ffffff;">';
        $html .= '<h2 style="margin:0;color:#0f172a;font-size:18px;line-height:1.3;font-weight:800;">Tasks needing attention</h2>';
        $html .= '<p style="margin:6px 0 0 0;color:#64748b;font-size:13px;line-height:1.5;">Overdue, due today, and unscheduled work from your CRM.</p>';
        $html .= '</td></tr>';

        if ($taskCount === 0) {
            $html .= '<tr><td style="padding:12px 28px 18px 28px;background:#ffffff;">';
            $html .= '<div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:18px;color:#334155;font-size:14px;line-height:1.6;">You are clear for today. No overdue, due today, or unscheduled tasks were found.</div>';
            $html .= '</td></tr>';
            return $html;
        }

        foreach ($this->digestSectionDefinitions() as $bucket => $section) {
            $sectionTasks = (array) ($taskGroups[$bucket] ?? []);
            if ($sectionTasks === []) {
                continue;
            }

            $html .= '<tr><td style="padding:16px 28px 0 28px;background:#ffffff;">';
            $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border-collapse:collapse;"><tr>';
            $html .= '<td style="color:' . $section['color'] . ';font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;">' . $this->e($section['label']) . '</td>';
            $html .= '<td align="right" style="color:#94a3b8;font-size:12px;font-weight:700;">' . count($sectionTasks) . ' task' . (count($sectionTasks) === 1 ? '' : 's') . '</td>';
            $html .= '</tr></table>';
            foreach ($sectionTasks as $task) {
                $html .= $this->renderDigestTaskHtml((array) $task, $runAt);
            }
            $html .= '</td></tr>';
        }

        if ($tasksUrl !== '') {
            $html .= '<tr><td style="padding:18px 28px 20px 28px;background:#ffffff;">';
            $html .= '<a href="' . $this->e($tasksUrl) . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:10px;padding:11px 16px;font-size:14px;font-weight:700;">Open task list</a>';
            $html .= '</td></tr>';
        }

        return $html;
    }

    private function renderDigestTaskHtml(array $task, \DateTimeInterface $runAt): string
    {
        $title = trim((string) ($task['title'] ?? 'Untitled task')) ?: 'Untitled task';
        $taskUrl = $this->safeDigestUrl((string) ($task['task_url'] ?? ''));
        $titleHtml = $taskUrl !== ''
            ? '<a href="' . $this->e($taskUrl) . '" style="color:#0f172a;text-decoration:none;">' . $this->e($title) . '</a>'
            : $this->e($title);
        $priority = $this->digestPriority((string) ($task['priority'] ?? 'medium'));
        $metaParts = [$this->e($this->digestDueLabel($task, $runAt))];

        $contactName = trim((string) ($task['contact_name'] ?? ''));
        $contactUrl = $this->safeDigestUrl((string) ($task['contact_url'] ?? ''));
        if ($contactName !== '') {
            $contact = $contactUrl !== ''
                ? '<a href="' . $this->e($contactUrl) . '" style="color:#2563eb;text-decoration:none;font-weight:700;">' . $this->e($contactName) . '</a>'
                : $this->e($contactName);
            $metaParts[] = 'Contact: ' . $contact;
        }

        $contactCompany = $this->digestContactCompany($task, $contactName);
        if ($contactCompany !== '') {
            $metaParts[] = 'Account: ' . $this->e($contactCompany);
        }

        $ownerEmail = trim((string) ($task['owner_email'] ?? ''));
        if ($ownerEmail !== '') {
            $metaParts[] = 'Owner: ' . $this->e($ownerEmail);
        }

        $html = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:9px;border-collapse:separate;background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;">';
        $html .= '<tr>';
        $html .= '<td style="padding:14px 14px 12px 14px;vertical-align:top;">';
        $html .= '<div style="font-size:15px;line-height:1.35;font-weight:800;color:#0f172a;">' . $titleHtml . '</div>';
        $html .= '<div style="margin-top:7px;color:#64748b;font-size:12px;line-height:1.5;">' . implode(' - ', $metaParts) . '</div>';
        $html .= '</td>';
        $html .= '<td align="right" style="padding:14px 14px 12px 8px;vertical-align:top;width:96px;">';
        $html .= '<span style="display:inline-block;background:' . $priority['background'] . ';color:' . $priority['color'] . ';border:1px solid ' . $priority['border'] . ';border-radius:999px;padding:5px 9px;font-size:11px;line-height:1;font-weight:800;text-transform:uppercase;letter-spacing:.03em;">' . $this->e($priority['label']) . '</span>';
        $html .= '</td>';
        $html .= '</tr></table>';

        return $html;
    }

    private function renderRecommendationsHtml(array $recommendations): string
    {
        if (!$this->hasDigestRecommendations($recommendations)) {
            return '';
        }

        $html = '<tr><td style="padding:10px 28px 24px 28px;background:#ffffff;">';
        $html .= '<div style="height:1px;background:#e2e8f0;margin:0 0 20px 0;"></div>';
        $html .= '<h2 style="margin:0;color:#0f172a;font-size:18px;line-height:1.3;font-weight:800;">AI recommendations</h2>';

        if ($recommendations['why_this_matters'] !== '') {
            $html .= '<div style="margin-top:12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:14px;padding:15px;color:#1e3a8a;font-size:14px;line-height:1.6;">';
            $html .= '<div style="margin:0 0 5px 0;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:#1d4ed8;">Focus Today</div>';
            $html .= $this->e($recommendations['why_this_matters']);
            $html .= '</div>';
        }

        if ($recommendations['priorities'] !== []) {
            $html .= $this->renderRecommendationListHtml('Priorities', $recommendations['priorities']);
        }

        if ($recommendations['quick_wins'] !== []) {
            $html .= $this->renderRecommendationListHtml('Quick Wins', $recommendations['quick_wins']);
        }

        $html .= '</td></tr>';

        return $html;
    }

    private function renderRecommendationListHtml(string $label, array $items): string
    {
        $html = '<div style="margin-top:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:15px;">';
        $html .= '<div style="margin:0 0 8px 0;color:#334155;font-size:13px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;">' . $this->e($label) . '</div>';
        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $html .= '<div style="margin:8px 0 0 0;color:#0f172a;font-size:14px;line-height:1.5;">';
            $html .= '<span style="display:inline-block;width:6px;height:6px;background:#2563eb;border-radius:999px;margin:0 8px 2px 0;"></span>' . $this->e($title);
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function renderDigestFooterHtml(): string
    {
        $html = '<tr><td style="padding:18px 28px 24px 28px;background:#f8fafc;border-top:1px solid #e2e8f0;">';
        $html .= '<p style="margin:0;color:#64748b;font-size:12px;line-height:1.6;">Sent by your Email Assistant. This internal digest includes CRM tasks and assistant recommendations for your workspace.</p>';
        $html .= '</td></tr>';

        return $html;
    }

    private function renderDigestPlain(
        string $title,
        string $dateStr,
        string $workspaceName,
        array $taskGroups,
        array $recommendations,
        array $summary,
        string $tasksUrl,
        \DateTimeInterface $runAt
    ): string {
        $plain = $title . "\n";
        $plain .= ($workspaceName !== '' ? $workspaceName . ' - ' : '') . $dateStr . "\n\n";
        $plain .= "SUMMARY\n";
        $plain .= "Overdue: " . (int) $summary['overdue'] . "\n";
        $plain .= "Due Today: " . (int) $summary['due_today'] . "\n";
        $plain .= "Unscheduled: " . (int) $summary['unscheduled'] . "\n";
        $plain .= "AI Recommendations: " . (int) $summary['recommendations'] . "\n";

        $taskCount = array_sum(array_map('count', $taskGroups));
        $plain .= "\nTASKS\n";
        if ($taskCount === 0) {
            $plain .= "You are clear for today. No overdue, due today, or unscheduled tasks were found.\n";
        } else {
            foreach ($this->digestSectionDefinitions() as $bucket => $section) {
                $sectionTasks = (array) ($taskGroups[$bucket] ?? []);
                if ($sectionTasks === []) {
                    continue;
                }
                $plain .= "\n" . strtoupper($section['label']) . " (" . count($sectionTasks) . ")\n";
                foreach ($sectionTasks as $task) {
                    $plain .= $this->renderDigestTaskPlain((array) $task, $runAt);
                }
            }
            if ($tasksUrl !== '') {
                $plain .= "\nOpen task list: " . $tasksUrl . "\n";
            }
        }

        if ($this->hasDigestRecommendations($recommendations)) {
            $plain .= "\nAI RECOMMENDATIONS\n";
            if ($recommendations['why_this_matters'] !== '') {
                $plain .= "Focus Today: " . $recommendations['why_this_matters'] . "\n";
            }
            if ($recommendations['priorities'] !== []) {
                $plain .= "Priorities:\n";
                foreach ($recommendations['priorities'] as $priority) {
                    $plain .= "- " . (string) ($priority['title'] ?? '') . "\n";
                }
            }
            if ($recommendations['quick_wins'] !== []) {
                $plain .= "Quick Wins:\n";
                foreach ($recommendations['quick_wins'] as $quickWin) {
                    $plain .= "- " . (string) ($quickWin['title'] ?? '') . "\n";
                }
            }
        }

        return $plain;
    }

    private function renderDigestTaskPlain(array $task, \DateTimeInterface $runAt): string
    {
        $title = trim((string) ($task['title'] ?? 'Untitled task')) ?: 'Untitled task';
        $priority = $this->digestPriority((string) ($task['priority'] ?? 'medium'));
        $parts = [
            $this->digestDueLabel($task, $runAt),
            $priority['label'] . ' priority',
        ];

        $contactName = trim((string) ($task['contact_name'] ?? ''));
        if ($contactName !== '') {
            $parts[] = 'Contact: ' . $contactName;
        }

        $contactCompany = $this->digestContactCompany($task, $contactName);
        if ($contactCompany !== '') {
            $parts[] = 'Account: ' . $contactCompany;
        }

        $ownerEmail = trim((string) ($task['owner_email'] ?? ''));
        if ($ownerEmail !== '') {
            $parts[] = 'Owner: ' . $ownerEmail;
        }

        $line = '- ' . $title . ' (' . implode(', ', $parts) . ')';
        $taskUrl = $this->safeDigestUrl((string) ($task['task_url'] ?? ''));
        if ($taskUrl !== '') {
            $line .= ' - ' . $taskUrl;
        }

        return $line . "\n";
    }

    private function buildDigestSummary(array $taskGroups, array $recommendations): array
    {
        $recommendationCount = count($recommendations['priorities'])
            + count($recommendations['quick_wins'])
            + ($recommendations['why_this_matters'] !== '' ? 1 : 0);

        return [
            'overdue' => count((array) ($taskGroups['overdue'] ?? [])),
            'due_today' => count((array) ($taskGroups['due_today'] ?? [])),
            'unscheduled' => count((array) ($taskGroups['unscheduled'] ?? [])),
            'recommendations' => $recommendationCount,
        ];
    }

    private function buildDigestPreheader(array $summary, string $workspaceName): string
    {
        $prefix = $workspaceName !== '' ? $workspaceName . ': ' : '';
        return $prefix
            . (int) $summary['overdue'] . ' overdue, '
            . (int) $summary['due_today'] . ' due today, '
            . (int) $summary['unscheduled'] . ' unscheduled, '
            . (int) $summary['recommendations'] . ' assistant recommendations.';
    }

    private function normalizeDigestRecommendations(array $recs): array
    {
        return [
            'why_this_matters' => trim((string) ($recs['why_this_matters'] ?? '')),
            'priorities' => $this->normalizeRecommendationItems((array) ($recs['priorities'] ?? [])),
            'quick_wins' => $this->normalizeRecommendationItems((array) ($recs['quick_wins'] ?? [])),
        ];
    }

    private function normalizeRecommendationItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $item) {
            $item = (array) $item;
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $normalized[] = ['title' => $title];
            if (count($normalized) >= 3) {
                break;
            }
        }

        return $normalized;
    }

    private function hasDigestRecommendations(array $recommendations): bool
    {
        return $recommendations['why_this_matters'] !== ''
            || $recommendations['priorities'] !== []
            || $recommendations['quick_wins'] !== [];
    }

    private function digestDueLabel(array $task, \DateTimeInterface $runAt): string
    {
        $dueAt = $this->parseDigestDate((string) ($task['due_date'] ?? ''), $runAt);
        if ($dueAt === null) {
            return 'No due date';
        }

        $today = $runAt->format('Y-m-d');
        $dueDay = $dueAt->format('Y-m-d');
        if ($dueDay < $today) {
            return 'Overdue since ' . $dueAt->format('M j');
        }

        if ($dueDay === $today) {
            return 'Due today';
        }

        return 'Due ' . $dueAt->format('M j');
    }

    private function digestPriority(string $priority): array
    {
        $key = strtolower(trim($priority)) ?: 'medium';
        $map = [
            'urgent' => ['label' => 'Urgent', 'background' => '#fee2e2', 'color' => '#b91c1c', 'border' => '#fecaca'],
            'high' => ['label' => 'High', 'background' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'],
            'medium' => ['label' => 'Medium', 'background' => '#dbeafe', 'color' => '#1d4ed8', 'border' => '#bfdbfe'],
            'low' => ['label' => 'Low', 'background' => '#f1f5f9', 'color' => '#475569', 'border' => '#cbd5e1'],
        ];

        return $map[$key] ?? $map['medium'];
    }

    private function digestContactCompany(array $task, string $contactName): string
    {
        $company = trim((string) ($task['contact_company'] ?? ''));
        if ($company === '') {
            return '';
        }

        if ($contactName !== '' && strcasecmp($company, $contactName) === 0) {
            return '';
        }

        return $company;
    }

    private function groupDigestTasks(array $tasks, \DateTimeInterface $runAt): array
    {
        $groups = [
            'overdue' => [],
            'due_today' => [],
            'unscheduled' => [],
        ];

        foreach ($tasks as $task) {
            $task = (array) $task;
            $bucket = (string) ($task['digest_bucket'] ?? $this->inferDigestBucket($task, $runAt));
            if (!array_key_exists($bucket, $groups)) {
                continue;
            }
            $groups[$bucket][] = $task;
        }

        return $groups;
    }

    private function inferDigestBucket(array $task, \DateTimeInterface $runAt): string
    {
        $dueAt = $this->parseDigestDate((string) ($task['due_date'] ?? ''), $runAt);
        if ($dueAt === null) {
            return 'unscheduled';
        }

        $today = $runAt->format('Y-m-d');
        $dueDay = $dueAt->format('Y-m-d');
        if ($dueDay < $today) {
            return 'overdue';
        }

        if ($dueDay === $today) {
            return 'due_today';
        }

        return 'future';
    }

    private function parseDigestDate(string $value, \DateTimeInterface $runAt): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            $timezone = $runAt->getTimezone();
            return (new \DateTimeImmutable($value, $timezone))->setTimezone($timezone);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function digestSectionDefinitions(): array
    {
        return [
            'overdue' => ['label' => 'Overdue', 'color' => '#dc2626'],
            'due_today' => ['label' => 'Due Today', 'color' => '#2563eb'],
            'unscheduled' => ['label' => 'Unscheduled', 'color' => '#475569'],
        ];
    }

    private function safeDigestUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return '';
        }

        return $url;
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
