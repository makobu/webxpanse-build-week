<?php

namespace CRM\Services;

class ClarityDeterministicFallbackService
{
    /** @param array<string,mixed> $questionIntent */
    public function build(string $message, array $questionIntent): string
    {
        if ((string) ($questionIntent['intent'] ?? 'general') !== 'explanation') {
            return '';
        }

        $title = $this->capture($message, '/Why is\s+(?:"|\x{201C})(.+?)(?:"|\x{201D})\s+the highest-priority constraint/iu');
        $signal = $this->capture($message, '/Current signal:\s*(.+?)(?:\.\s*Why now:|\s+Why now:)/iu');
        $whyNow = $this->capture($message, '/Why now:\s*(.+?)(?:\.\s*Evidence:|\s+Evidence:)/iu');
        $evidence = $this->capture($message, '/Evidence:\s*(.+?)(?:\.\s*Explain|\s+Explain|$)/iu');

        if ($title === '' || $signal === '' || $evidence === '') {
            return '';
        }

        $urgency = $whyNow !== ''
            ? '; the urgency signal is ' . lcfirst(rtrim($whyNow, '.'))
            : '';

        return '**Why this is the priority** ' . $title . ' is the strongest current constraint because '
            . lcfirst(rtrim($signal, '.')) . '. '
            . '**Evidence** The measured evidence is ' . rtrim($evidence, '.') . $urgency
            . ', showing that active pipeline has not yet become paid proof. '
            . '**Founder judgment and next measurable action** Choose the open opportunity with the clearest buyer intent, confirm the offer and price, then contact the open opportunities and record a reply, booked demo, proposal decision, or payment.';
    }

    private function capture(string $message, string $pattern): string
    {
        if (preg_match($pattern, $message, $matches) !== 1) {
            return '';
        }

        $value = trim(preg_replace('/\s+/u', ' ', strip_tags((string) ($matches[1] ?? ''))) ?? '');
        return mb_substr($value, 0, 320);
    }
}
