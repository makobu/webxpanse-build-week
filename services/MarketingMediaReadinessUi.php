<?php

namespace CRM\Services;

class MarketingMediaReadinessUi
{
    public static function render(array $readiness): string
    {
        if ($readiness === []) {
            return '';
        }

        $escape = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
        $status = preg_replace('/[^a-z0-9_-]/i', '', (string) ($readiness['status'] ?? 'needs_attention')) ?: 'needs_attention';
        $counts = (array) ($readiness['counts'] ?? []);
        $actions = array_slice((array) ($readiness['actions'] ?? []), 0, 6);
        $queues = (array) ($readiness['queues'] ?? []);

        $countCards = '';
        foreach ([
            'total_media' => 'Media',
            'needs_accessibility' => 'Accessibility',
            'blocked_or_restricted' => 'Blocked',
            'content_without_media' => 'Content Gaps',
            'landing_media_issues' => 'Landing Gaps',
            'distribution_without_media' => 'Distribution Gaps',
            'channel_kits_blocked' => 'Kit Gaps',
        ] as $key => $label) {
            $countCards .= '<div class="marketing-media-readiness-stat">'
                . '<span>' . $escape($label) . '</span>'
                . '<strong>' . (int) ($counts[$key] ?? 0) . '</strong>'
                . '</div>';
        }

        $actionCards = '';
        foreach ($actions as $action) {
            $priority = preg_replace('/[^a-z0-9_-]/i', '', (string) ($action['priority'] ?? 'normal')) ?: 'normal';
            $actionCards .= '<a class="marketing-media-readiness-action ' . $escape($priority) . '" href="' . $escape($action['href'] ?? 'marketing_assets.php') . '">'
                . '<strong>' . $escape($action['label'] ?? 'Review media readiness') . '</strong>'
                . '<span>' . $escape($action['reason'] ?? 'Review visual readiness before launch.') . '</span>'
                . '</a>';
        }

        if ($actionCards === '') {
            $actionCards = '<div class="empty-state"><p>No media readiness actions are open right now.</p></div>';
        }

        $queueCards = '';
        foreach ([
            'accessibility' => 'Accessibility Queue',
            'governance' => 'Governance Queue',
            'content' => 'Content Without Media',
            'landing_pages' => 'Landing Visual Gaps',
            'distribution' => 'Distribution Without Media',
            'channel_kits' => 'Channel Kit Gaps',
        ] as $key => $label) {
            $items = array_slice((array) ($queues[$key] ?? []), 0, 3);
            $queueCards .= '<div class="marketing-media-readiness-queue"><h3>' . $escape($label) . '</h3>';
            if ($items === []) {
                $queueCards .= '<p>No open items.</p>';
            } else {
                foreach ($items as $item) {
                    $title = (string) ($item['title'] ?? $item['content_title'] ?? $item['media_title'] ?? 'Marketing item');
                    $href = (string) ($item['href'] ?? 'marketing_assets.php');
                    $meta = trim((string) ($item['status'] ?? $item['channel'] ?? $item['media_type'] ?? ''));
                    $queueCards .= '<a href="' . $escape($href) . '">'
                        . '<strong>' . $escape($title) . '</strong>'
                        . ($meta !== '' ? '<span>' . $escape($labelize($meta)) . '</span>' : '')
                        . '</a>';
                }
            }
            $queueCards .= '</div>';
        }

        return '<div class="content-card marketing-media-readiness-panel">'
            . '<div class="marketing-media-readiness-head">'
            . '<div><h2>' . $escape($readiness['title'] ?? 'Media Operational Readiness') . '</h2>'
            . '<p>' . $escape($readiness['subtitle'] ?? 'Review media launch gates before manual publishing or sending.') . '</p></div>'
            . '<div class="marketing-media-readiness-score ' . $escape($status) . '"><strong>' . (int) ($readiness['score'] ?? 0) . '%</strong><span>' . $escape($labelize($status)) . '</span></div>'
            . '</div>'
            . '<div class="marketing-media-readiness-stats">' . $countCards . '</div>'
            . '<div class="marketing-media-readiness-body">'
            . '<div><h3>Recommended Actions</h3><div class="marketing-media-readiness-actions">' . $actionCards . '</div></div>'
            . '<div><h3>Launch Queues</h3><div class="marketing-media-readiness-queues">' . $queueCards . '</div></div>'
            . '</div>'
            . '<div class="marketing-media-readiness-footnote">'
            . '<span>' . $escape($readiness['recommended_product_decision'] ?? 'Treat media readiness as a launch gate across Marketing.') . '</span>'
            . '<span>No external publish/send, social API, email API, ad API, SEO API, or media-generation API is triggered from this panel.</span>'
            . '</div>'
            . '</div>';
    }
}
