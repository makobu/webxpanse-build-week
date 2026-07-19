<?php

namespace CRM\Services;

class CustomerThreadAutonomyService
{
    public function assess(array $threadContext, ?array $contact = null, ?array $deal = null, ?array $invoice = null): array
    {
        $messages = (array) ($threadContext['messages'] ?? []);
        $latestMessage = $messages !== [] ? (array) end($messages) : [];
        $hasContact = !empty($contact['id']) || !empty($threadContext['contact']['id']);
        $hasRecipient = trim((string) ($contact['email'] ?? $threadContext['contact']['email'] ?? '')) !== '';
        $hasInbound = false;
        foreach ($messages as $message) {
            if ((string) ($message['direction'] ?? '') === 'inbound') {
                $hasInbound = true;
                break;
            }
        }

        $lastTimestamp = (string) ($latestMessage['created_at'] ?? ($threadContext['latest_inbound_message']['created_at'] ?? ''));
        $stalenessHours = 0;
        if ($lastTimestamp !== '') {
            $ts = strtotime($lastTimestamp);
            $stalenessHours = $ts ? max(0, (int) floor((time() - $ts) / 3600)) : 0;
        }

        $threadCompleteness = 0.45;
        if ($hasContact) {
            $threadCompleteness += 0.15;
        }
        if ($hasRecipient) {
            $threadCompleteness += 0.15;
        }
        if ($hasInbound) {
            $threadCompleteness += 0.15;
        }
        if (!empty($deal['id']) || !empty($invoice['id'])) {
            $threadCompleteness += 0.1;
        }

        return [
            'has_contact_identity' => $hasContact,
            'has_recipient' => $hasRecipient,
            'has_inbound_message' => $hasInbound,
            'stale_thread_state' => $stalenessHours >= 168,
            'thread_staleness_hours' => $stalenessHours,
            'thread_completeness' => max(0.0, min(1.0, round($threadCompleteness, 4))),
            'thread_response_latency_hours' => $stalenessHours,
            'send_caution_level' => $stalenessHours >= 72 ? 'high' : ($stalenessHours >= 24 ? 'medium' : 'low'),
        ];
    }

    public function augmentPolicyContext(array $baseContext, array $assessment): array
    {
        return array_merge($baseContext, [
            'thread_completeness' => (float) ($assessment['thread_completeness'] ?? 0.0),
            'thread_response_latency_hours' => (int) ($assessment['thread_response_latency_hours'] ?? 0),
            'stale_thread_state' => !empty($assessment['stale_thread_state']),
            'thread_identity_present' => !empty($assessment['has_contact_identity']),
            'recipient' => !empty($baseContext['recipient']) ? (string) $baseContext['recipient'] : '',
        ]);
    }

    public function explanation(array $assessment): array
    {
        $reasons = [];
        if (empty($assessment['has_contact_identity'])) {
            $reasons[] = 'missing_thread_contact_identity';
        }
        if (empty($assessment['has_recipient'])) {
            $reasons[] = 'missing_thread_recipient';
        }
        if (empty($assessment['has_inbound_message'])) {
            $reasons[] = 'insufficient_thread_context';
        }
        if (!empty($assessment['stale_thread_state'])) {
            $reasons[] = 'stale_thread_state';
        }
        return $reasons;
    }
}
