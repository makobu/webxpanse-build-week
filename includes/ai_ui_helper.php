<?php

function aiUiDecisionMeta(?string $decision): array
{
    $normalized = strtolower(trim((string) $decision));

    return match ($normalized) {
        'allow' => ['label' => 'Ready', 'tone' => 'success'],
        'allow_with_warning' => ['label' => 'Use with warnings', 'tone' => 'warning'],
        'suggest_only' => ['label' => 'Suggestion only', 'tone' => 'warning'],
        'approval_required', 'pending' => ['label' => 'Approval required', 'tone' => 'warning'],
        'blocked', 'reject', 'rejected', 'expired' => ['label' => 'Blocked', 'tone' => 'danger'],
        'failed' => ['label' => 'Failed', 'tone' => 'danger'],
        'retry_pending', 'waiting' => ['label' => 'Retry pending', 'tone' => 'warning'],
        'paused' => ['label' => 'Paused', 'tone' => 'danger'],
        'diagnostics_only' => ['label' => 'Diagnostics only', 'tone' => 'neutral'],
        'normal', 'approved' => ['label' => 'Ready', 'tone' => 'success'],
        default => ['label' => ucfirst(str_replace('_', ' ', $normalized !== '' ? $normalized : 'unknown')), 'tone' => 'neutral'],
    };
}

function aiUiDecisionColors(?string $decision): array
{
    $tone = aiUiDecisionMeta($decision)['tone'];

    return match ($tone) {
        'success' => ['bg' => '#dcfce7', 'text' => '#166534', 'border' => '#86efac'],
        'warning' => ['bg' => '#fef3c7', 'text' => '#92400e', 'border' => '#fcd34d'],
        'danger' => ['bg' => '#fee2e2', 'text' => '#991b1b', 'border' => '#fca5a5'],
        default => ['bg' => '#e2e8f0', 'text' => '#334155', 'border' => '#cbd5e1'],
    };
}

function aiUiQualityMeta($score): array
{
    if (!is_numeric($score)) {
        return ['label' => 'Context unknown', 'tone' => 'neutral'];
    }

    $value = (float) $score;
    if ($value >= 0.85) {
        return ['label' => 'Context high', 'tone' => 'success'];
    }
    if ($value >= 0.65) {
        return ['label' => 'Context medium', 'tone' => 'warning'];
    }

    return ['label' => 'Context low', 'tone' => 'danger'];
}

function aiUiWarningLabel(string $warning): string
{
    return match (strtolower(trim($warning))) {
        'stale_context', 'stale_context_present' => 'Stale context',
        'bundle_trimmed' => 'Prompt warning',
        'required_context_trimmed' => 'Required context trimmed',
        'prompt_warning' => 'Prompt warning',
        'prompt_overload', 'prompt_overload_detected' => 'Prompt overload',
        'low_signal_bundle', 'low_signal_context_bundle' => 'Low-signal context',
        'missing_context' => 'Missing context',
        'fallback' => 'Fallback used',
        default => ucfirst(str_replace('_', ' ', trim($warning))),
    };
}

function aiUiInlineChip(string $label, ?string $decision = null): string
{
    $colors = aiUiDecisionColors($decision);

    return sprintf(
        '<span style="display:inline-flex;align-items:center;gap:.35rem;padding:4px 8px;border-radius:999px;background:%s;color:%s;border:1px solid %s;font-size:12px;font-weight:700;">%s</span>',
        htmlspecialchars($colors['bg']),
        htmlspecialchars($colors['text']),
        htmlspecialchars($colors['border']),
        htmlspecialchars($label)
    );
}
