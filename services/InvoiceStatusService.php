<?php

namespace CRM\Services;

class InvoiceStatusService
{
    public const ALLOWED = [
        'draft' => ['sent', 'cancelled', 'revised', 'finalized'],
        'sent' => ['viewed', 'accepted', 'revised', 'cancelled', 'overdue', 'finalized'],
        'viewed' => ['accepted', 'revised', 'cancelled', 'overdue', 'finalized'],
        'accepted' => ['finalized', 'revised', 'cancelled'],
        'revised' => ['sent', 'accepted', 'cancelled', 'finalized'],
        'finalized' => ['partially_paid', 'paid', 'overdue', 'cancelled'],
        'partially_paid' => ['paid', 'overdue'],
        'paid' => [],
        'cancelled' => [],
        'overdue' => ['partially_paid', 'paid', 'cancelled'],
    ];

    public function canTransition(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }
}
