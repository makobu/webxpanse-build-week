<?php

namespace CRM\Services;

class WorkspaceBillingPaymentModeService
{
    public const MODE_CARD = 'card';
    public const MODE_MPESA = 'mpesa';
    public const MODE_BANK_TRANSFER = 'bank_transfer';

    /**
     * @return list<string>
     */
    public function supportedModes(): array
    {
        return [
            self::MODE_CARD,
            self::MODE_MPESA,
            self::MODE_BANK_TRANSFER,
        ];
    }

    public function normalize(?string $mode): string
    {
        $mode = strtolower(trim((string) $mode));
        return in_array($mode, $this->supportedModes(), true) ? $mode : self::MODE_CARD;
    }

    /**
     * @param array<string,mixed>|null $availability
     * @return array<string,bool>
     */
    public function normalizeAvailability(?array $availability): array
    {
        $normalized = [
            self::MODE_CARD => true,
            self::MODE_MPESA => true,
            self::MODE_BANK_TRANSFER => true,
        ];

        if ($availability === null) {
            return $normalized;
        }

        foreach ($normalized as $mode => $default) {
            if (array_key_exists($mode, $availability)) {
                $normalized[$mode] = !empty($availability[$mode]);
            }
        }

        return $normalized;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listAvailableModes(
        string $currency,
        bool $paystackReady = true,
        ?bool $mpesaReady = null,
        ?array $availability = null,
        ?array $availabilityReasons = null
    ): array
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            $currency = 'KES';
        }
        $mpesaReady = $mpesaReady ?? $paystackReady;
        $availability = $this->normalizeAvailability($availability);
        $cardEnabled = $availability[self::MODE_CARD];
        $mpesaEnabled = $availability[self::MODE_MPESA];
        $bankTransferEnabled = $availability[self::MODE_BANK_TRANSFER];

        $modes = [
            [
                'key' => self::MODE_CARD,
                'label' => 'Card',
                'flow_type' => 'redirect',
                'requires_phone' => false,
                'available' => $cardEnabled && $paystackReady,
                'reason' => !$cardEnabled
                    ? (string) ($availabilityReasons[self::MODE_CARD] ?? 'Card payments are temporarily unavailable.')
                    : ($paystackReady ? null : 'Paystack billing is not ready.'),
            ],
            [
                'key' => self::MODE_MPESA,
                'label' => 'M-Pesa',
                'flow_type' => 'offline_charge',
                'requires_phone' => true,
                'available' => $mpesaEnabled && $mpesaReady && $currency === 'KES',
                'reason' => !$mpesaEnabled
                    ? (string) ($availabilityReasons[self::MODE_MPESA] ?? 'M-Pesa payments are temporarily unavailable.')
                    : ($mpesaReady
                        ? ($currency === 'KES' ? null : 'M-Pesa is only enabled for KES workspaces in this slice.')
                        : 'M-Pesa billing is not ready.'),
            ],
            [
                'key' => self::MODE_BANK_TRANSFER,
                'label' => 'Bank Transfer',
                'flow_type' => 'offline_charge',
                'requires_phone' => false,
                'available' => $bankTransferEnabled && $paystackReady && $currency === 'NGN',
                'reason' => !$bankTransferEnabled
                    ? (string) ($availabilityReasons[self::MODE_BANK_TRANSFER] ?? 'Bank transfer payments are temporarily unavailable.')
                    : ($paystackReady
                        ? ($currency === 'NGN' ? null : 'Bank transfer is only enabled for NGN-supported workspaces in this slice.')
                        : 'Paystack billing is not ready.'),
            ],
        ];

        return $modes;
    }

    /**
     * @return array<string,mixed>
     */
    public function resolveMode(
        string $mode,
        string $currency,
        bool $paystackReady = true,
        ?bool $mpesaReady = null,
        ?array $availability = null,
        ?array $availabilityReasons = null
    ): array
    {
        $mode = $this->normalize($mode);
        foreach ($this->listAvailableModes($currency, $paystackReady, $mpesaReady, $availability, $availabilityReasons) as $candidate) {
            if (($candidate['key'] ?? '') === $mode) {
                return $candidate;
            }
        }

        return [
            'key' => self::MODE_CARD,
            'label' => 'Card',
            'flow_type' => 'redirect',
            'requires_phone' => false,
            'available' => false,
            'reason' => 'Payment mode is not available.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function assertAvailable(
        string $mode,
        string $currency,
        bool $paystackReady = true,
        ?bool $mpesaReady = null,
        ?array $availability = null,
        ?array $availabilityReasons = null
    ): array
    {
        $resolved = $this->resolveMode($mode, $currency, $paystackReady, $mpesaReady, $availability, $availabilityReasons);
        if (!empty($resolved['available'])) {
            return $resolved;
        }

        throw new \RuntimeException((string) ($resolved['reason'] ?? 'This payment mode is not available for the active workspace.'));
    }
}
