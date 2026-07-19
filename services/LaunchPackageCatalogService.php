<?php

declare(strict_types=1);

namespace CRM\Services;

class LaunchPackageCatalogService
{
    /**
     * @return array<string,array<string,mixed>>
     */
    public function packagesByCode(): array
    {
        return [
            'compass-free' => [
                'code' => 'compass-free',
                'plan_code' => 'compass-free',
                'name' => 'Compass Free',
                'display_name' => 'Compass Free',
                'positioning' => 'Recommended plan',
                'ideal_customer' => 'Solo founder testing the system',
                'scope_label' => 'Solo founder testing the system',
                'best_for' => 'Solo founder testing the system.',
                'duration' => 'Always-on free access',
                'deliverable' => 'A low-friction workspace to test the system.',
                'summary' => 'Removes adoption friction and captures top-of-funnel founder demand.',
                'rationale' => 'Removes adoption friction and captures top-of-funnel founder demand.',
                'inherits_from' => null,
                'tier_intro' => 'Start with the core workspace, no checkout required.',
                'upgrade_reason' => 'Upgrade when the founder workflow is proven and you want more structure or collaborators.',
                'feature_highlights' => [
                    'Start without checkout',
                    '50,000 onboarding AI Credits',
                    'No top-ups on free plan',
                    'Keep the workspace active',
                ],
                'feature_groups' => [
                    [
                        'title' => 'Workspace foundation',
                        'items' => [
                            'Core workspace access for testing the CRM flow',
                            'Contacts, deals, and tasks basics',
                            '50,000 one-time onboarding AI Credits',
                            'No AI Credit top-ups, Business Intelligence, or personal API key access',
                            'Upgrade-ready workspace history',
                        ],
                    ],
                ],
                'note_placeholder' => 'Anything you want us to know about your free workspace setup.',
                'cta' => 'Use Free',
                'status_chip' => 'Auto-activated',
                'checkout_available' => false,
                'is_free' => true,
            ],
            'solo-launch' => [
                'code' => 'solo-launch',
                'plan_code' => 'solo-launch',
                'name' => 'Solo Launch',
                'display_name' => 'Solo Launch',
                'positioning' => 'One-person founder',
                'ideal_customer' => 'One-person founder who wants structure and AI help',
                'scope_label' => 'One-person founder who wants structure and AI help',
                'best_for' => 'One founder who wants structure before hiring or delegating.',
                'duration' => 'Monthly or annual',
                'deliverable' => 'Affordable operating structure with AI help.',
                'summary' => 'Deliberately affordable versus imported tools; priced as a business operating system, not just a contact list.',
                'rationale' => 'Deliberately affordable versus imported tools; priced as a business operating system, not just a contact list.',
                'inherits_from' => 'compass-free',
                'tier_intro' => 'Everything in Compass Free, plus...',
                'upgrade_reason' => 'Move from testing to a repeatable solo operating rhythm.',
                'feature_highlights' => [
                    'Founder operating rhythm',
                    '1,000,000 AI Credits per cycle',
                    'AI Credit top-ups available',
                    'Monthly or annual card autopay',
                ],
                'feature_groups' => [
                    [
                        'title' => 'Solo operating system',
                        'items' => [
                            'One-person CRM workflow for contacts, deals, tasks, and follow-up',
                            'Founder operating loop for turning daily work into a repeatable routine',
                            '1,000,000 included AI Credits per billing cycle',
                            'AI Credit top-ups available when usage grows',
                            'Monthly or annual Paystack card autopay',
                        ],
                    ],
                ],
                'note_placeholder' => 'Share the structure and AI help you want first.',
                'cta' => 'Start Solo Launch',
                'status_chip' => 'Card autopay',
            ],
            'founder-plus' => [
                'code' => 'founder-plus',
                'plan_code' => 'founder-plus',
                'name' => 'Founder Plus',
                'display_name' => 'Founder Plus',
                'positioning' => 'Founder plus collaborators',
                'ideal_customer' => 'Founder plus one to two collaborators',
                'scope_label' => 'Founder plus one to two collaborators',
                'best_for' => 'Founder plus one or two collaborators.',
                'duration' => 'Monthly or annual',
                'deliverable' => 'Core workspace package for serious users.',
                'summary' => 'Best good-better tier for serious users; built to be the core revenue driver.',
                'rationale' => 'Best good-better tier for serious users; built to be the core revenue driver.',
                'inherits_from' => 'solo-launch',
                'tier_intro' => 'Everything in Solo Launch, plus...',
                'upgrade_reason' => 'Add a collaborator-ready rhythm for handoffs, proposals, invoices, and review.',
                'feature_highlights' => [
                    '3 seats',
                    '3,500,000 AI Credits per cycle',
                    'Business Intelligence plugin',
                ],
                'feature_groups' => [
                    [
                        'title' => 'Collaborator workflow',
                        'items' => [
                            'Shared contacts, deals, and tasks handoff for a founder plus one or two helpers',
                            '3,500,000 included AI Credits per billing cycle',
                            'Business Intelligence plugin access',
                            'Proposal and invoice follow-up workflow visibility',
                            'Stronger weekly review cadence for serious users',
                        ],
                    ],
                ],
                'note_placeholder' => 'Tell us who will collaborate with you.',
                'cta' => 'Start Founder Plus',
                'status_chip' => 'Core tier',
            ],
            'growth-studio' => [
                'code' => 'growth-studio',
                'plan_code' => 'growth-studio',
                'name' => 'Growth Studio',
                'display_name' => 'Growth Studio',
                'positioning' => 'Small team',
                'ideal_customer' => 'Small team expanding beyond the founder',
                'scope_label' => 'Small team expanding beyond the founder',
                'best_for' => 'Small team expanding beyond the founder.',
                'duration' => 'Monthly or annual',
                'deliverable' => 'More workflow depth, reporting, and automation.',
                'summary' => 'Adds workflow depth, reporting, and stronger automation without enterprise complexity.',
                'rationale' => 'Adds workflow depth, reporting, and stronger automation without enterprise complexity.',
                'inherits_from' => 'founder-plus',
                'tier_intro' => 'Everything in Founder Plus, plus...',
                'upgrade_reason' => 'Give a small team more workflow depth, reporting visibility, and automation readiness.',
                'feature_highlights' => [
                    '15 seats',
                    '6,000,000 AI Credits per cycle',
                    'Personal API key access',
                ],
                'feature_groups' => [
                    [
                        'title' => 'Growth workflow',
                        'items' => [
                            'Team follow-up and review routines across the workspace',
                            '6,000,000 included AI Credits per billing cycle',
                            'Business Intelligence and personal API key plugin access',
                            'Automation readiness cues for stronger handoffs',
                            'Workflow depth without enterprise complexity',
                        ],
                    ],
                ],
                'note_placeholder' => 'Share your team size and workflow needs.',
                'cta' => 'Start Growth Studio',
                'status_chip' => 'Workflow depth',
            ],
            'scale-custom' => [
                'code' => 'scale-custom',
                'plan_code' => 'scale-custom',
                'name' => 'Scale Custom',
                'display_name' => 'Scale Custom',
                'positioning' => 'Multi-user custom',
                'ideal_customer' => 'Multi-user teams, agencies, accelerators, partner channels',
                'scope_label' => 'Multi-user teams, agencies, accelerators, partner channels',
                'best_for' => 'Multi-user teams and partner channels.',
                'duration' => 'Custom annual contract',
                'deliverable' => 'Sales-led annual agreement.',
                'summary' => 'Keeps enterprise/custom available without distorting the launch motion.',
                'rationale' => 'Keeps enterprise/custom available without distorting the launch motion.',
                'inherits_from' => 'growth-studio',
                'tier_intro' => 'Everything in Growth Studio, plus...',
                'upgrade_reason' => 'Use a sales-led annual agreement when rollout, procurement, or channel structure needs a custom path.',
                'feature_highlights' => [
                    'Unlimited seats',
                    '20,000,000 AI Credits per cycle',
                    'All plugin gates enabled',
                ],
                'feature_groups' => [
                    [
                        'title' => 'Custom rollout',
                        'items' => [
                            'Custom annual contract discussion',
                            '20,000,000 included AI Credits per billing cycle',
                            'Unlimited seats by agreement',
                            'Business Intelligence and personal API key plugin access',
                            'Agency, accelerator, and partner-channel setup',
                            'Sales-led onboarding for multi-user teams',
                        ],
                    ],
                ],
                'note_placeholder' => 'Tell us team size, channel, and procurement needs.',
                'cta' => 'Request Custom Plan',
                'status_chip' => 'Sales handoff',
                'checkout_available' => false,
                'is_custom' => true,
                'price' => 'From KES 39,000',
                'price_short' => 'From KES 39,000',
                'billing_cadence' => 'custom',
                'currency_code' => 'KES',
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function packageForCode(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $packages = $this->packagesByCode();
        return $packages[$code] ?? null;
    }

    /**
     * @param list<array<string,mixed>> $prices
     * @return list<array<string,mixed>>
     */
    public function cardsFromSubscriptionPrices(array $prices, bool $includeCustom = false): array
    {
        $pricesByPlan = [];
        foreach ($prices as $price) {
            $planCode = (string) ($price['plan_code'] ?? '');
            $metadata = is_array($price['metadata'] ?? null) ? (array) $price['metadata'] : [];
            if (!empty($metadata['workspace_negotiated']) || !empty($price['workspace_negotiated'])) {
                $planCode = 'workspace-negotiated-' . (int) ($price['id'] ?? $price['billing_plan_price_id'] ?? 0);
                $price['plan_code'] = $planCode;
            }
            if ($planCode === '') {
                continue;
            }
            $pricesByPlan[$planCode][] = $price;
        }

        $cards = [];
        foreach ($this->packagesByCode() as $planCode => $package) {
            $planPrices = $pricesByPlan[$planCode] ?? [];
            if ($planPrices === [] && (empty($package['is_custom']) || !$includeCustom)) {
                continue;
            }
            $cards[] = $this->buildCardFromPrices($package, $planPrices);
            unset($pricesByPlan[$planCode]);
        }

        foreach ($pricesByPlan as $planCode => $planPrices) {
            $primary = $planPrices[0] ?? [];
            $entitlements = is_array($primary['entitlements'] ?? null) ? (array) $primary['entitlements'] : [];
            $isWorkspaceNegotiated = !empty($primary['workspace_negotiated']) || !empty($primary['metadata']['workspace_negotiated']);
            $summary = trim((string) ($entitlements['public_summary'] ?? $primary['description'] ?? 'Custom workspace package.'));
            $displayName = trim((string) ($entitlements['public_display_name'] ?? $entitlements['display_name'] ?? $primary['plan_name'] ?? 'Custom Package'));
            $featureHighlights = [];
            foreach ((array) ($entitlements['feature_details'] ?? []) as $feature) {
                $label = trim((string) ($feature['label'] ?? $feature['feature_key'] ?? ''));
                if ($label === '' || empty($feature['value'])) {
                    continue;
                }
                $valueType = (string) ($feature['value_type'] ?? 'boolean');
                $featureHighlights[] = $valueType === 'boolean'
                    ? $label
                    : $label . ': ' . (string) $feature['value'];
            }
            $cards[] = $this->buildCardFromPrices([
                'code' => $planCode,
                'plan_code' => $planCode,
                'name' => $displayName,
                'display_name' => $displayName,
                'positioning' => 'Custom package',
                'ideal_customer' => 'Configured by superadmin',
                'scope_label' => $summary,
                'best_for' => $summary,
                'duration' => 'Configured cadence',
                'deliverable' => $summary,
                'summary' => $summary,
                'rationale' => $summary,
                'inherits_from' => null,
                'tier_intro' => 'Configured package features',
                'upgrade_reason' => 'Choose this package when its configured limits match the workspace.',
                'feature_highlights' => $featureHighlights !== [] ? array_slice($featureHighlights, 0, 6) : ['Configured by superadmin'],
                'feature_groups' => [[
                    'title' => 'Configured package',
                    'items' => $featureHighlights !== [] ? array_slice($featureHighlights, 0, 8) : [$summary],
                ]],
                'note_placeholder' => 'Share anything the team should know about this package.',
                'cta' => 'Choose Package',
                'status_chip' => $isWorkspaceNegotiated ? 'Private offer' : 'Custom',
                'is_custom' => !empty($entitlements['is_custom']),
                'checkout_available' => $isWorkspaceNegotiated,
            ], $planPrices);
        }

        return $cards;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function decorateBillingPlanRow(array $row): array
    {
        $details = $this->packageForCode((string) ($row['plan_code'] ?? ''));
        if ($details === null) {
            $entitlements = is_array($row['entitlements'] ?? null) ? (array) $row['entitlements'] : [];
            $featureHighlights = [];
            foreach ((array) ($entitlements['feature_details'] ?? []) as $feature) {
                $label = trim((string) ($feature['label'] ?? $feature['feature_key'] ?? ''));
                if ($label !== '' && !empty($feature['value'])) {
                    $featureHighlights[] = (string) $label;
                }
            }
            $row['feature_highlights'] = $featureHighlights;
            $row['feature_groups'] = [[
                'title' => 'Configured package',
                'items' => $featureHighlights !== [] ? $featureHighlights : [(string) ($row['description'] ?? 'Custom package')],
            ]];
            return $row;
        }

        foreach ([
            'inherits_from',
            'tier_intro',
            'feature_highlights',
            'feature_groups',
            'best_for',
            'upgrade_reason',
            'rationale',
            'ideal_customer',
        ] as $key) {
            $row[$key] = $details[$key] ?? null;
        }
        $row['launch_package_details'] = $details;

        return $row;
    }

    /**
     * @param array<string,mixed> $package
     * @param list<array<string,mixed>> $prices
     * @return array<string,mixed>
     */
    private function buildCardFromPrices(array $package, array $prices): array
    {
        $monthly = null;
        $annual = null;
        foreach ($prices as $price) {
            $interval = (string) ($price['interval_unit'] ?? '');
            if ($interval === 'monthly' && $monthly === null) {
                $monthly = $price;
            } elseif (in_array($interval, ['yearly', 'annual'], true) && $annual === null) {
                $annual = $price;
            }
        }

        $primary = $monthly ?? $annual ?? ($prices[0] ?? null);
        $entitlements = is_array($primary['entitlements'] ?? null) ? (array) $primary['entitlements'] : [];
        $publicDisplayName = trim((string) ($entitlements['public_display_name'] ?? $entitlements['display_name'] ?? ''));
        $publicSummary = trim((string) ($entitlements['public_summary'] ?? $entitlements['public_display_copy'] ?? ''));
        if ($publicDisplayName !== '') {
            $package['display_name'] = $publicDisplayName;
            $package['name'] = $publicDisplayName;
        }
        if ($publicSummary !== '') {
            $package['description'] = $publicSummary;
            $package['scope_label'] = $publicSummary;
            $package['summary'] = $publicSummary;
        }
        $options = [];
        $includedOptionPriceIds = [];
        foreach ([['monthly', 'Monthly', $monthly], ['annual', 'Annual', $annual]] as [$cadence, $label, $price]) {
            if (!is_array($price)) {
                continue;
            }
            $includedOptionPriceIds[(int) ($price['id'] ?? 0)] = true;
            $options[] = [
                'cadence' => $cadence,
                'label' => $label,
                'billing_plan_price_id' => (int) ($price['id'] ?? 0),
                'price_code' => (string) ($price['price_code'] ?? ''),
                'amount' => (float) ($price['amount'] ?? 0),
                'currency' => (string) ($price['currency'] ?? 'KES'),
                'provider_plan_configured' => !empty($price['provider_plan_configured']),
                'checkout_available' => !empty($price['checkout_available']) || (float) ($price['amount'] ?? 0) <= 0,
                'is_default' => !empty($price['is_default']),
                'payment_modes' => (array) ($price['payment_modes'] ?? []),
                'interval_unit' => (string) ($price['interval_unit'] ?? ''),
                'interval_count' => (int) ($price['interval_count'] ?? 1),
            ];
        }
        foreach ($prices as $price) {
            $priceId = (int) ($price['id'] ?? 0);
            if ($priceId <= 0 || isset($includedOptionPriceIds[$priceId])) {
                continue;
            }
            $interval = (string) ($price['interval_unit'] ?? 'monthly');
            $count = max(1, (int) ($price['interval_count'] ?? 1));
            $label = $this->formatCadenceLabel($interval, $count);
            $options[] = [
                'cadence' => $interval,
                'label' => $label,
                'billing_plan_price_id' => $priceId,
                'price_code' => (string) ($price['price_code'] ?? ''),
                'amount' => (float) ($price['amount'] ?? 0),
                'currency' => (string) ($price['currency'] ?? 'KES'),
                'provider_plan_configured' => !empty($price['provider_plan_configured']),
                'checkout_available' => !empty($price['checkout_available']) || (float) ($price['amount'] ?? 0) <= 0,
                'is_default' => !empty($price['is_default']),
                'payment_modes' => (array) ($price['payment_modes'] ?? []),
                'interval_unit' => $interval,
                'interval_count' => $count,
            ];
        }

        $isFree = !empty($package['is_free']) || ($primary !== null && (float) ($primary['amount'] ?? 0) <= 0);
        $hasAvailableCheckoutOption = false;
        $checkoutBlockedReason = '';
        foreach ($options as $option) {
            if (!empty($option['checkout_available'])) {
                $hasAvailableCheckoutOption = true;
                break;
            }

            foreach ((array) ($option['payment_modes'] ?? []) as $paymentMode) {
                $message = trim((string) ($paymentMode['help'] ?? $paymentMode['reason'] ?? ''));
                if (empty($paymentMode['available']) && $message !== '') {
                    $checkoutBlockedReason = $message;
                    break 2;
                }
            }
        }
        $package['bullets'] = $package['feature_highlights'] ?? ($package['bullets'] ?? []);
        $package['checkout_options'] = $options;
        $package['monthly_price'] = $monthly;
        $package['annual_price'] = $annual;
        $package['billing_plan_price_id'] = $primary['id'] ?? null;
        $package['billing_price'] = $primary;
        $package['entitlements'] = $entitlements;
        $package['plan_code'] = (string) ($package['plan_code'] ?? $package['code'] ?? '');
        $package['workspace_negotiated'] = !empty($primary['workspace_negotiated']) || !empty($primary['metadata']['workspace_negotiated']);
        $package['is_workspace_private'] = !empty($primary['is_workspace_private']) || !empty($primary['metadata']['workspace_private']);
        $package['private_workspace_id'] = (int) ($primary['workspace_id'] ?? $primary['metadata']['workspace_id'] ?? 0);
        $package['plan_name'] = (string) ($primary['plan_name'] ?? $package['display_name'] ?? $package['name'] ?? 'Package');
        $package['description'] = (string) ($primary['description'] ?? $package['summary'] ?? '');
        $package['currency_code'] = (string) ($primary['currency'] ?? $package['currency_code'] ?? 'KES');
        $package['is_default'] = $this->hasDefaultPrice($options);
        $package['is_free'] = $isFree;
        $package['checkout_available'] = (bool) ($package['checkout_available'] ?? empty($package['is_custom']))
            && $options !== []
            && $hasAvailableCheckoutOption
            && !$isFree;
        if (!$package['checkout_available'] && $checkoutBlockedReason !== '') {
            $package['locked_reason'] = $checkoutBlockedReason;
        }
        $package['price_amounts'] = [
            'monthly' => $monthly['amount'] ?? null,
            'annual' => $annual['amount'] ?? null,
        ];
        $package['seat_summary'] = $this->formatSeatSummary((int) ($entitlements['seat_limit'] ?? 0));
        $package['credit_summary'] = $this->formatCreditSummary(
            (int) ($entitlements['included_credits'] ?? $primary['included_tokens'] ?? 0),
            (string) ($package['plan_code'] ?? ''),
            $isFree
        );
        $package['plugin_summary'] = $this->formatPluginSummary($entitlements);
        $package['capability_summary'] = [
            $package['seat_summary'],
            $package['credit_summary'],
            !empty($entitlements['can_top_up']) ? 'AI Credit top-ups available' : 'No AI Credit top-ups',
            !empty($entitlements['business_intelligence_enabled']) ? 'Business Intelligence included' : 'Business Intelligence locked',
            !empty($entitlements['personal_api_key_enabled']) ? 'Personal API key included' : 'Personal API key locked',
            'Credits expire after ' . number_format((int) ($entitlements['credit_expiry_days'] ?? 180)) . ' days',
        ];

        if (!isset($package['price'])) {
            $package['price'] = $this->formatGroupedPrice($monthly, $annual);
            $package['price_short'] = $package['price'];
            $package['billing_cadence'] = $isFree ? 'free' : 'recurring';
        }

        return $package;
    }

    private function formatSeatSummary(int $seatLimit): string
    {
        return $seatLimit > 0 ? number_format($seatLimit) . ' seat' . ($seatLimit === 1 ? '' : 's') : 'Unlimited seats';
    }

    private function formatCreditSummary(int $credits, string $planCode, bool $isFree): string
    {
        if ($credits <= 0) {
            return 'No included AI Credits';
        }

        $suffix = $isFree || $planCode === 'compass-free'
            ? ' one-time onboarding AI Credits'
            : ' AI Credits per billing cycle';

        return number_format($credits) . $suffix;
    }

    /**
     * @param array<string,mixed> $entitlements
     */
    private function formatPluginSummary(array $entitlements): string
    {
        $enabled = [];
        if (!empty($entitlements['business_intelligence_enabled'])) {
            $enabled[] = 'Business Intelligence';
        }
        if (!empty($entitlements['personal_api_key_enabled'])) {
            $enabled[] = 'personal API key';
        }

        return $enabled === [] ? 'Core plugins only' : implode(' + ', $enabled);
    }

    /**
     * @param list<array<string,mixed>> $options
     */
    private function hasDefaultPrice(array $options): bool
    {
        foreach ($options as $option) {
            if (!empty($option['is_default'])) {
                return true;
            }
        }

        return false;
    }

    private function formatGroupedPrice(?array $monthly, ?array $annual): string
    {
        $monthlyAmount = (float) ($monthly['amount'] ?? 0);
        $annualAmount = (float) ($annual['amount'] ?? 0);
        $currency = strtoupper((string) ($monthly['currency'] ?? $annual['currency'] ?? 'KES'));
        if ($monthlyAmount <= 0 && $annualAmount <= 0) {
            return $currency . ' 0';
        }

        $parts = [];
        if ($monthly !== null) {
            $parts[] = $currency . ' ' . number_format($monthlyAmount, 0) . '/mo';
        }
        if ($annual !== null) {
            $parts[] = $currency . ' ' . number_format($annualAmount, 0) . '/yr';
        }

        return implode(' or ', $parts);
    }

    private function formatCadenceLabel(string $interval, int $count): string
    {
        $interval = strtolower(trim($interval));
        $unit = match ($interval) {
            'weekly' => 'week',
            'quarterly' => 'quarter',
            'yearly', 'annual' => 'year',
            default => 'month',
        };

        if ($count <= 1) {
            return ucfirst($unit === 'year' ? 'Annual' : $unit . 'ly');
        }

        return 'Every ' . number_format($count) . ' ' . $unit . 's';
    }
}
