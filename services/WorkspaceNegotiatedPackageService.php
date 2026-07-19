<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;

class WorkspaceNegotiatedPackageService
{
    private const OPEN_STATUSES = ['offered', 'accepted', 'active'];
    private const EDITABLE_STATUSES = ['draft', 'offered', 'accepted'];

    private OperatorAuditService $operatorAudit;
    private WorkspacePlanEntitlementService $entitlements;

    public function __construct(
        ?OperatorAuditService $operatorAudit = null,
        ?WorkspacePlanEntitlementService $entitlements = null
    ) {
        $this->operatorAudit = $operatorAudit ?? new OperatorAuditService();
        $this->entitlements = $entitlements ?? new WorkspacePlanEntitlementService();
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function createOffer(array $input, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSchema();
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);

        $workspace = $this->loadWorkspace((int) ($input['workspace_id'] ?? 0));
        $basePrice = $this->loadBasePrice((int) ($input['base_billing_plan_price_id'] ?? 0));
        $status = $this->normalizeStatus((string) ($input['status'] ?? 'draft'));
        if (in_array($status, self::OPEN_STATUSES, true)) {
            $this->assertNoOpenOffer((int) $workspace['id']);
        }

        $normalized = $this->normalizeCommercialInput($input, $basePrice, $workspace, $status);

        Database::beginTransaction();
        try {
            $metadata = $this->buildPriceMetadata($workspace, $basePrice, $normalized, $status);
            $priceCode = $this->uniquePriceCode('negotiated-ws-' . (int) $workspace['id'] . '-' . date('Ymd'));
            Database::execute(
                "INSERT INTO billing_plan_prices
                    (workspace_id, plan_id, price_code, currency, interval_unit, interval_count, amount, included_tokens,
                     metadata_json, provider, provider_plan_code, provider_plan_id, provider_plan_status,
                     provider_plan_synced_at, is_default, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'paystack', NULL, NULL, NULL, NULL, 0, ?)",
                [
                    (int) $workspace['id'],
                    (int) ($basePrice['plan_id'] ?? 0),
                    $priceCode,
                    $normalized['currency'],
                    $normalized['interval_unit'],
                    $normalized['interval_count'],
                    $normalized['amount'],
                    $normalized['included_credits'],
                    json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    $this->statusAllowsCheckout($status) ? 1 : 0,
                ]
            );
            $priceId = (int) Database::lastInsertId();
            $this->savePriceFeatureValues($priceId, (array) $normalized['features']);
            $paymentModes = $this->normalizePaymentModes($normalized['payment_modes'], (string) $normalized['currency'], '');
            $this->savePricePaymentMethods($priceId, $paymentModes);

            Database::execute(
                "INSERT INTO workspace_negotiated_package_offers
                    (workspace_id, base_billing_plan_price_id, negotiated_billing_plan_price_id, status,
                     agreement_reference, starts_at, expires_at, notes, metadata_json, created_by, updated_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    (int) $workspace['id'],
                    (int) $basePrice['id'],
                    $priceId,
                    $status,
                    $normalized['agreement_reference'] !== '' ? $normalized['agreement_reference'] : null,
                    $normalized['starts_at'],
                    $normalized['expires_at'],
                    $normalized['notes'] !== '' ? $normalized['notes'] : null,
                    json_encode($this->offerMetadata($normalized, $paymentModes), JSON_UNESCAPED_SLASHES),
                    $actorUserId,
                    $actorUserId,
                ]
            );
            $offerId = (int) Database::lastInsertId();
            $this->stampPriceOfferMetadata($priceId, $offerId, $status);
            $offer = $this->loadOffer($offerId);

            $this->operatorAudit->log(
                'workspace_negotiated_offer_created',
                $actorUserId,
                (int) $workspace['id'],
                $reason,
                [
                    'offer_id' => $offerId,
                    'workspace_slug' => (string) ($workspace['slug'] ?? ''),
                    'base_billing_plan_price_id' => (int) $basePrice['id'],
                    'negotiated_billing_plan_price_id' => $priceId,
                    'status' => $status,
                    'amount' => (float) $normalized['amount'],
                    'currency' => (string) $normalized['currency'],
                    'payment_modes' => $paymentModes,
                ]
            );

            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $offer;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function updateOffer(int $offerId, array $input, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSchema();
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $before = $this->loadOffer($offerId);
        $currentStatus = (string) ($before['status'] ?? '');
        if (!in_array($currentStatus, self::EDITABLE_STATUSES, true)) {
            throw new \RuntimeException('Only draft or offered negotiated packages can be edited.');
        }

        $workspace = $this->loadWorkspace((int) $before['workspace_id']);
        $basePrice = $this->loadBasePrice((int) ($input['base_billing_plan_price_id'] ?? $before['base_billing_plan_price_id'] ?? 0));
        $status = $this->normalizeStatus((string) ($input['status'] ?? $currentStatus));
        if (in_array($status, self::OPEN_STATUSES, true)) {
            $this->assertNoOpenOffer((int) $workspace['id'], $offerId);
        }

        $mergedInput = array_merge($before, $input, [
            'workspace_id' => (int) $workspace['id'],
            'base_billing_plan_price_id' => (int) $basePrice['id'],
        ]);
        $normalized = $this->normalizeCommercialInput($mergedInput, $basePrice, $workspace, $status);
        $priceId = (int) $before['negotiated_billing_plan_price_id'];
        $paymentModes = $this->normalizePaymentModes($normalized['payment_modes'], (string) $normalized['currency'], (string) ($before['provider_plan_code'] ?? ''));

        Database::beginTransaction();
        try {
            $metadata = $this->buildPriceMetadata($workspace, $basePrice, $normalized, $status, (int) $offerId);
            Database::execute(
                "UPDATE billing_plan_prices
                 SET workspace_id = ?,
                     plan_id = ?,
                     currency = ?,
                     interval_unit = ?,
                     interval_count = ?,
                     amount = ?,
                     included_tokens = ?,
                     metadata_json = ?,
                     is_active = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    (int) $workspace['id'],
                    (int) ($basePrice['plan_id'] ?? 0),
                    $normalized['currency'],
                    $normalized['interval_unit'],
                    $normalized['interval_count'],
                    $normalized['amount'],
                    $normalized['included_credits'],
                    json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    $this->statusAllowsCheckout($status) ? 1 : 0,
                    $priceId,
                ]
            );
            $this->savePriceFeatureValues($priceId, (array) $normalized['features']);
            $this->savePricePaymentMethods($priceId, $paymentModes);
            Database::execute(
                "UPDATE workspace_negotiated_package_offers
                 SET base_billing_plan_price_id = ?,
                     status = ?,
                     agreement_reference = ?,
                     starts_at = ?,
                     expires_at = ?,
                     notes = ?,
                     metadata_json = ?,
                     updated_by = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    (int) $basePrice['id'],
                    $status,
                    $normalized['agreement_reference'] !== '' ? $normalized['agreement_reference'] : null,
                    $normalized['starts_at'],
                    $normalized['expires_at'],
                    $normalized['notes'] !== '' ? $normalized['notes'] : null,
                    json_encode($this->offerMetadata($normalized, $paymentModes), JSON_UNESCAPED_SLASHES),
                    $actorUserId,
                    $offerId,
                ]
            );
            $after = $this->loadOffer($offerId);
            $this->operatorAudit->log(
                'workspace_negotiated_offer_updated',
                $actorUserId,
                (int) $workspace['id'],
                $reason,
                ['before' => $this->auditOffer($before), 'after' => $this->auditOffer($after)]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $after;
    }

    /**
     * @return array<string,mixed>
     */
    public function publishOffer(int $offerId, int $actorUserId, ?string $reason = null): array
    {
        return $this->setOfferStatus($offerId, 'offered', $actorUserId, $reason, true);
    }

    /**
     * @return array<string,mixed>
     */
    public function archiveOffer(int $offerId, int $actorUserId, ?string $reason = null): array
    {
        return $this->setOfferStatus($offerId, 'archived', $actorUserId, $reason, false);
    }

    /**
     * @return array<string,mixed>
     */
    public function activateOffer(int $offerId, int $actorUserId, ?string $reason = null): array
    {
        $this->requireSchema();
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $offer = $this->loadOffer($offerId);
        $status = (string) ($offer['status'] ?? '');
        if (!in_array($status, ['offered', 'accepted', 'active'], true)) {
            throw new \RuntimeException('Publish the negotiated offer before activating it.');
        }

        $workspaceId = (int) ($offer['workspace_id'] ?? 0);
        $priceId = (int) ($offer['negotiated_billing_plan_price_id'] ?? 0);
        if ($workspaceId <= 0 || $priceId <= 0) {
            throw new \RuntimeException('Negotiated offer is missing workspace or price details.');
        }

        $activation = (new PlatformWorkspaceOperationsService())->activateWorkspaceSubscriptionPlan(
            $workspaceId,
            $priceId,
            $actorUserId,
            $reason
        );

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_negotiated_package_offers
                 SET status = 'active',
                     accepted_at = COALESCE(accepted_at, NOW()),
                     activated_at = COALESCE(activated_at, NOW()),
                     updated_by = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$actorUserId, $offerId]
            );
            $this->stampPriceOfferMetadata($priceId, $offerId, 'active');
            $after = $this->loadOffer($offerId);
            $this->operatorAudit->log(
                'workspace_negotiated_offer_activated',
                $actorUserId,
                $workspaceId,
                $reason,
                [
                    'offer_id' => $offerId,
                    'billing_plan_price_id' => $priceId,
                    'activation_changed' => !empty($activation['changed']),
                    'credit_result' => (array) ($activation['credit_result'] ?? []),
                ]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        $subscription = (array) ($activation['subscription'] ?? []);
        $billingInvoice = (new WorkspacePackageBillingInvoiceService())->issueForOperatorActivation(
            $workspaceId,
            (int) ($subscription['id'] ?? 0),
            $priceId,
            $actorUserId,
            $offerId,
            $reason
        );
        if ($billingInvoice) {
            $activation['billing_invoice'] = (array) ($billingInvoice['summary'] ?? []);
            $activation['billing_invoice_id'] = (int) ($billingInvoice['id'] ?? 0);
        }

        $after['activation'] = $activation;
        return $after;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listWorkspaceOffers(?int $workspaceId = null, int $limit = 50, array $filters = []): array
    {
        $this->requireSchema();
        $limit = max(1, min(500, $limit));
        $params = [];
        $where = '';
        if ($workspaceId !== null && $workspaceId > 0) {
            $where = 'WHERE wno.workspace_id = ?';
            $params[] = $workspaceId;
        }

        $rows = Database::query(
            "SELECT wno.*, w.name AS workspace_name, w.slug AS workspace_slug,
                    bpp.price_code, bpp.currency, bpp.amount, bpp.interval_unit, bpp.interval_count,
                    bpp.included_tokens, bpp.metadata_json AS price_metadata_json,
                    bpp.provider_plan_code, bpp.provider_plan_status, bpp.is_active AS price_is_active,
                    bp.id AS plan_id, bp.code AS plan_code, bp.name AS plan_name,
                    base.price_code AS base_price_code, base_plan.code AS base_plan_code, base_plan.name AS base_plan_name
             FROM workspace_negotiated_package_offers wno
             JOIN workspaces w ON w.id = wno.workspace_id
             JOIN billing_plan_prices bpp ON bpp.id = wno.negotiated_billing_plan_price_id
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             LEFT JOIN billing_plan_prices base ON base.id = wno.base_billing_plan_price_id
             LEFT JOIN billing_plans base_plan ON base_plan.id = base.plan_id
             {$where}
             ORDER BY FIELD(wno.status, 'offered', 'accepted', 'active', 'draft', 'expired', 'archived'),
                      wno.updated_at DESC, wno.id DESC
             LIMIT {$limit}",
            $params
        );

        $offers = array_map([$this, 'hydrateOffer'], $rows);
        $offers = $this->filterOffers($offers, $filters);

        return array_slice($offers, 0, $limit);
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function listWorkspaceOfferSummaries(array $filters = []): array
    {
        $this->requireSchema();
        $offers = $this->listWorkspaceOffers(null, 500);
        $byWorkspace = [];
        foreach ($offers as $offer) {
            $workspaceId = (int) ($offer['workspace_id'] ?? 0);
            if ($workspaceId <= 0) {
                continue;
            }

            if (!isset($byWorkspace[$workspaceId])) {
                $byWorkspace[$workspaceId] = [
                    'workspace_id' => $workspaceId,
                    'workspace_name' => (string) ($offer['workspace_name'] ?? 'Workspace'),
                    'workspace_slug' => (string) ($offer['workspace_slug'] ?? ''),
                    'offers' => [],
                    'status_counts' => [
                        'draft' => 0,
                        'offered' => 0,
                        'accepted' => 0,
                        'active' => 0,
                        'expired' => 0,
                        'archived' => 0,
                    ],
                    'total_offers' => 0,
                    'last_updated_at' => '',
                ];
            }

            $status = (string) ($offer['status'] ?? 'draft');
            if (!isset($byWorkspace[$workspaceId]['status_counts'][$status])) {
                $byWorkspace[$workspaceId]['status_counts'][$status] = 0;
            }
            $byWorkspace[$workspaceId]['status_counts'][$status]++;
            $byWorkspace[$workspaceId]['total_offers']++;
            $byWorkspace[$workspaceId]['offers'][] = $offer;
            $updatedAt = (string) ($offer['updated_at'] ?? $offer['created_at'] ?? '');
            if ($updatedAt !== '' && strcmp($updatedAt, (string) $byWorkspace[$workspaceId]['last_updated_at']) > 0) {
                $byWorkspace[$workspaceId]['last_updated_at'] = $updatedAt;
            }
        }

        $summaries = [];
        foreach ($byWorkspace as $summary) {
            $workspaceOffers = (array) ($summary['offers'] ?? []);
            usort($workspaceOffers, fn(array $a, array $b): int => $this->compareOffersForWorkspaceSummary($a, $b));
            $current = (array) ($workspaceOffers[0] ?? []);
            $summary['current_offer'] = $current;
            $summary['current_offer_id'] = (int) ($current['id'] ?? 0);
            $summary['current_status'] = (string) ($current['status'] ?? '');
            $summary['current_package_name'] = (string) (($current['entitlements']['public_display_name'] ?? '') ?: ($current['plan_name'] ?? 'Negotiated Package'));
            $summary['amount'] = (float) ($current['amount'] ?? 0);
            $summary['currency'] = (string) ($current['currency'] ?? 'KES');
            $summary['interval_unit'] = (string) ($current['interval_unit'] ?? 'monthly');
            $summary['interval_count'] = (int) ($current['interval_count'] ?? 1);
            $summary['payment_modes'] = array_values((array) ($current['payment_modes'] ?? []));
            $summary['starts_at'] = (string) ($current['starts_at'] ?? '');
            $summary['expires_at'] = (string) ($current['expires_at'] ?? '');
            $summary['is_expiring_soon'] = $this->isOfferExpiringSoon($current);
            $summary['card_sync_blocked'] = $this->isCardSyncBlocked($current);
            unset($summary['offers']);
            $summaries[] = $summary;
        }

        $summaries = $this->filterWorkspaceSummaries($summaries, $filters);
        usort($summaries, fn(array $a, array $b): int => $this->compareWorkspaceSummaries($a, $b));

        return array_values($summaries);
    }

    /**
     * @return array<string,int>
     */
    public function negotiatedOfferMetrics(): array
    {
        $this->requireSchema();
        $offers = $this->listWorkspaceOffers(null, 500);
        $summaries = $this->listWorkspaceOfferSummaries();
        $activeWorkspaceIds = [];
        $metrics = [
            'total_offers' => count($offers),
            'open_offers' => 0,
            'active_workspaces' => 0,
            'expiring_soon' => 0,
            'card_sync_blocked' => 0,
            'negotiated_workspaces' => count($summaries),
        ];

        foreach ($offers as $offer) {
            $status = (string) ($offer['status'] ?? '');
            if (in_array($status, self::OPEN_STATUSES, true)) {
                $metrics['open_offers']++;
            }
            if ($status === 'active') {
                $activeWorkspaceIds[(int) ($offer['workspace_id'] ?? 0)] = true;
            }
            if ($this->isOfferExpiringSoon($offer)) {
                $metrics['expiring_soon']++;
            }
            if ($this->isCardSyncBlocked($offer)) {
                $metrics['card_sync_blocked']++;
            }
        }

        unset($activeWorkspaceIds[0]);
        $metrics['active_workspaces'] = count($activeWorkspaceIds);

        return $metrics;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function currentOfferForWorkspace(int $workspaceId): ?array
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_negotiated_package_offers')) {
            return null;
        }

        $rows = $this->listWorkspaceOffers($workspaceId, 10);
        $now = time();
        foreach ($rows as $offer) {
            $status = (string) ($offer['status'] ?? '');
            if (!in_array($status, self::OPEN_STATUSES, true)) {
                continue;
            }
            $startsAt = trim((string) ($offer['starts_at'] ?? ''));
            $expiresAt = trim((string) ($offer['expires_at'] ?? ''));
            if ($startsAt !== '' && ($startTs = strtotime($startsAt)) !== false && $startTs > $now) {
                continue;
            }
            if ($expiresAt !== '' && ($expiryTs = strtotime($expiresAt)) !== false && $expiryTs < $now) {
                continue;
            }

            return $offer;
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function setOfferStatus(int $offerId, string $status, int $actorUserId, ?string $reason, bool $priceActive): array
    {
        $this->requireSchema();
        $this->requireSuperAdmin($actorUserId);
        $reason = $this->requireReason($reason);
        $status = $this->normalizeStatus($status);
        $before = $this->loadOffer($offerId);
        $workspaceId = (int) ($before['workspace_id'] ?? 0);
        $priceId = (int) ($before['negotiated_billing_plan_price_id'] ?? 0);
        if (in_array($status, self::OPEN_STATUSES, true)) {
            $this->assertNoOpenOffer($workspaceId, $offerId);
        }

        Database::beginTransaction();
        try {
            $extraSet = $status === 'archived'
                ? ', archived_at = COALESCE(archived_at, NOW())'
                : '';
            Database::execute(
                "UPDATE workspace_negotiated_package_offers
                 SET status = ?,
                     updated_by = ?,
                     updated_at = NOW()
                     {$extraSet}
                 WHERE id = ?",
                [$status, $actorUserId, $offerId]
            );
            Database::execute(
                "UPDATE billing_plan_prices
                 SET is_active = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$priceActive ? 1 : 0, $priceId]
            );
            $this->stampPriceOfferMetadata($priceId, $offerId, $status);
            $after = $this->loadOffer($offerId);
            $this->operatorAudit->log(
                'workspace_negotiated_offer_' . $status,
                $actorUserId,
                $workspaceId,
                $reason,
                ['before' => $this->auditOffer($before), 'after' => $this->auditOffer($after)]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $after;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadOffer(int $offerId): array
    {
        if ($offerId <= 0) {
            throw new \RuntimeException('Negotiated offer is required.');
        }

        $row = Database::queryOne(
            "SELECT wno.*, w.name AS workspace_name, w.slug AS workspace_slug,
                    bpp.price_code, bpp.currency, bpp.amount, bpp.interval_unit, bpp.interval_count,
                    bpp.included_tokens, bpp.metadata_json AS price_metadata_json,
                    bpp.provider_plan_code, bpp.provider_plan_status, bpp.is_active AS price_is_active,
                    bp.id AS plan_id, bp.code AS plan_code, bp.name AS plan_name,
                    base.price_code AS base_price_code, base_plan.code AS base_plan_code, base_plan.name AS base_plan_name
             FROM workspace_negotiated_package_offers wno
             JOIN workspaces w ON w.id = wno.workspace_id
             JOIN billing_plan_prices bpp ON bpp.id = wno.negotiated_billing_plan_price_id
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             LEFT JOIN billing_plan_prices base ON base.id = wno.base_billing_plan_price_id
             LEFT JOIN billing_plans base_plan ON base_plan.id = base.plan_id
             WHERE wno.id = ?
             LIMIT 1",
            [$offerId]
        );

        if (!$row) {
            throw new \RuntimeException('Negotiated offer not found.');
        }

        return $this->hydrateOffer($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateOffer(array $row): array
    {
        $offerMetadata = $this->decodeJson($row['metadata_json'] ?? null);
        $priceMetadata = $this->decodeJson($row['price_metadata_json'] ?? null);
        $priceRow = [
            'id' => (int) ($row['negotiated_billing_plan_price_id'] ?? 0),
            'billing_plan_price_id' => (int) ($row['negotiated_billing_plan_price_id'] ?? 0),
            'plan_id' => (int) ($row['plan_id'] ?? $priceMetadata['base_plan_id'] ?? 0),
            'plan_code' => (string) ($row['plan_code'] ?? ''),
            'plan_name' => (string) ($row['plan_name'] ?? ''),
            'currency' => (string) ($row['currency'] ?? 'KES'),
            'amount' => (float) ($row['amount'] ?? 0),
            'interval_unit' => (string) ($row['interval_unit'] ?? 'monthly'),
            'interval_count' => (int) ($row['interval_count'] ?? 1),
            'included_tokens' => (int) ($row['included_tokens'] ?? 0),
            'metadata_json' => (string) ($row['price_metadata_json'] ?? ''),
        ];
        $row['metadata'] = $offerMetadata;
        $row['price_metadata'] = $priceMetadata;
        $row['payment_modes'] = (array) ($offerMetadata['payment_modes'] ?? []);
        $row['entitlements'] = $this->entitlements->entitlementsForPriceRow($priceRow);
        $row['is_checkout_live'] = $this->statusAllowsCheckout((string) ($row['status'] ?? '')) && !empty($row['price_is_active']);

        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadWorkspace(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required for a negotiated package.');
        }

        $workspace = Database::queryOne(
            "SELECT id, uuid, name, slug, status, plan_status, created_at, updated_at
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        );
        if (!$workspace) {
            throw new \RuntimeException('Workspace not found.');
        }

        return $workspace;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadBasePrice(int $billingPlanPriceId): array
    {
        if ($billingPlanPriceId <= 0) {
            throw new \RuntimeException('Choose a base package price for the negotiated offer.');
        }

        $privateClause = Database::columnExists('billing_plan_prices', 'workspace_id')
            ? 'AND bpp.workspace_id IS NULL'
            : '';
        $price = Database::queryOne(
            "SELECT bpp.*, bp.code AS plan_code, bp.name AS plan_name, bp.description, bp.billing_type
             FROM billing_plan_prices bpp
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE bpp.id = ?
               AND bp.billing_type = 'subscription'
               AND bpp.is_active = 1
               AND bp.is_active = 1
               {$privateClause}
             LIMIT 1",
            [$billingPlanPriceId]
        );
        if (!$price) {
            throw new \RuntimeException('Base package price was not found or is not active.');
        }

        return $price;
    }

    private function requireSuperAdmin(int $actorUserId): void
    {
        $actor = $actorUserId > 0
            ? Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$actorUserId])
            : null;
        if (!Authorization::isSuperAdmin($actor ?: null)) {
            throw new \RuntimeException('Only Super Admin can manage negotiated workspace packages.');
        }
    }

    private function requireReason(?string $reason): string
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new \RuntimeException('A reason is required for negotiated package changes.');
        }

        return $reason;
    }

    private function requireSchema(): void
    {
        if (!Database::columnExists('billing_plan_prices', 'workspace_id')
            || !Database::tableExists('workspace_negotiated_package_offers')
            || !Database::tableExists('billing_plan_price_feature_values')) {
            throw new \RuntimeException('Negotiated package schema is missing. Run database migrations.');
        }
    }

    private function assertNoOpenOffer(int $workspaceId, int $exceptOfferId = 0): void
    {
        $params = [$workspaceId];
        $except = '';
        if ($exceptOfferId > 0) {
            $except = 'AND id <> ?';
            $params[] = $exceptOfferId;
        }
        $row = Database::queryOne(
            "SELECT id
             FROM workspace_negotiated_package_offers
             WHERE workspace_id = ?
               AND status IN ('offered', 'accepted', 'active')
               {$except}
             LIMIT 1",
            $params
        );
        if ($row) {
            throw new \RuntimeException('This workspace already has an open negotiated package offer. Archive it before publishing another.');
        }
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $basePrice
     * @param array<string,mixed> $workspace
     * @return array<string,mixed>
     */
    private function normalizeCommercialInput(array $input, array $basePrice, array $workspace, string $status): array
    {
        $baseEntitlements = $this->entitlements->entitlementsForPriceRow($basePrice);
        $currency = $this->normalizeCurrency((string) ($input['currency'] ?? $basePrice['currency'] ?? 'KES'));
        $amount = round((float) ($input['amount'] ?? $basePrice['amount'] ?? 0), 2);
        if ($amount < 0) {
            throw new \RuntimeException('Negotiated package amount cannot be negative.');
        }

        $intervalUnit = $this->normalizeIntervalUnit((string) ($input['interval_unit'] ?? $basePrice['interval_unit'] ?? 'monthly'));
        $intervalCount = max(1, (int) ($input['interval_count'] ?? $basePrice['interval_count'] ?? 1));
        $includedCredits = max(0, (int) ($input['included_credits'] ?? $input['included_tokens'] ?? $basePrice['included_tokens'] ?? $baseEntitlements['included_credits'] ?? 0));
        $displayName = trim((string) ($input['display_name'] ?? 'Negotiated Workspace Package'));
        $displayCopy = trim((string) ($input['display_copy'] ?? $input['public_summary'] ?? 'Private package terms configured for ' . (string) ($workspace['name'] ?? 'this workspace') . '.'));
        $features = [
            'seat_limit' => max(0, (int) ($input['seat_limit'] ?? $baseEntitlements['seat_limit'] ?? 0)),
            'included_credits' => $includedCredits,
            'can_top_up' => $this->boolInput($input, 'can_top_up', !empty($baseEntitlements['can_top_up'])),
            'business_intelligence' => $this->boolInput($input, 'business_intelligence', !empty($baseEntitlements['business_intelligence_enabled'])),
            'personal_api_key' => $this->boolInput($input, 'personal_api_key', !empty($baseEntitlements['personal_api_key_enabled'])),
            'credit_expiry_days' => max(1, (int) ($input['credit_expiry_days'] ?? $baseEntitlements['credit_expiry_days'] ?? 180)),
        ];
        foreach ((array) ($input['features'] ?? $input['feature_values'] ?? []) as $key => $value) {
            $key = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string) $key);
            if ($key !== '') {
                $features[$key] = $value;
            }
        }

        $startsAt = $this->normalizeDateTime($input['starts_at'] ?? null);
        $expiresAt = $this->normalizeDateTime($input['expires_at'] ?? null);
        if ($startsAt !== null && $expiresAt !== null && strtotime($expiresAt) <= strtotime($startsAt)) {
            throw new \RuntimeException('Negotiated offer expiry must be after the start date.');
        }

        $paymentModes = $input['payment_modes'] ?? null;
        if ($paymentModes === null && isset($input['payment_scope']) && (string) $input['payment_scope'] !== 'custom') {
            $paymentModes = null;
        }

        return [
            'status' => $status,
            'currency' => $currency,
            'amount' => $amount,
            'interval_unit' => $intervalUnit,
            'interval_count' => $intervalCount,
            'included_credits' => $includedCredits,
            'display_name' => $displayName,
            'display_copy' => $displayCopy,
            'agreement_reference' => trim((string) ($input['agreement_reference'] ?? '')),
            'starts_at' => $startsAt,
            'expires_at' => $expiresAt,
            'notes' => trim((string) ($input['notes'] ?? '')),
            'features' => $features,
            'payment_modes' => $paymentModes,
        ];
    }

    /**
     * @param array<string,mixed>|null $modes
     * @return list<string>
     */
    private function normalizePaymentModes($modes, string $currency, string $providerPlanCode): array
    {
        $paymentModeService = new WorkspaceBillingPaymentModeService();
        $posted = is_array($modes) ? array_values($modes) : [];
        if ($posted === []) {
            $posted = strtoupper($currency) === 'KES'
                ? [WorkspaceBillingPaymentModeService::MODE_MPESA]
                : (strtoupper($currency) === 'NGN' ? [WorkspaceBillingPaymentModeService::MODE_BANK_TRANSFER] : []);
        }

        $normalized = [];
        foreach ($posted as $mode) {
            $mode = $paymentModeService->normalize((string) $mode);
            if ($mode === WorkspaceBillingPaymentModeService::MODE_CARD && trim($providerPlanCode) === '') {
                throw new \RuntimeException('Card autopay for negotiated packages requires a synced private provider plan first.');
            }
            if (!in_array($mode, $normalized, true)) {
                $normalized[] = $mode;
            }
        }

        if ($normalized === []) {
            throw new \RuntimeException('Choose at least one supported negotiated package payment method.');
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $features
     */
    private function savePriceFeatureValues(int $billingPlanPriceId, array $features): void
    {
        if ($billingPlanPriceId <= 0 || !Database::tableExists('billing_package_features') || !Database::tableExists('billing_plan_price_feature_values')) {
            return;
        }

        Database::execute("DELETE FROM billing_plan_price_feature_values WHERE billing_plan_price_id = ?", [$billingPlanPriceId]);
        foreach (Database::query("SELECT * FROM billing_package_features WHERE is_active = 1") as $feature) {
            $key = (string) ($feature['feature_key'] ?? '');
            if ($key === '' || !array_key_exists($key, $features)) {
                continue;
            }

            $valueType = (string) ($feature['value_type'] ?? 'boolean');
            $value = $this->normalizeFeatureValue($valueType, $features[$key]);
            $isEnabled = $valueType === 'boolean' ? !empty($value) : true;
            Database::execute(
                "INSERT INTO billing_plan_price_feature_values (billing_plan_price_id, feature_id, value_json, is_enabled)
                 VALUES (?, ?, ?, ?)",
                [$billingPlanPriceId, (int) $feature['id'], json_encode(['value' => $value], JSON_UNESCAPED_SLASHES), $isEnabled ? 1 : 0]
            );
        }
    }

    /**
     * @param list<string> $modes
     */
    private function savePricePaymentMethods(int $billingPlanPriceId, array $modes): void
    {
        if ($billingPlanPriceId <= 0 || !Database::tableExists('billing_plan_price_payment_methods')) {
            return;
        }

        Database::execute("DELETE FROM billing_plan_price_payment_methods WHERE billing_plan_price_id = ?", [$billingPlanPriceId]);
        foreach ($modes as $mode) {
            Database::execute(
                "INSERT INTO billing_plan_price_payment_methods (billing_plan_price_id, payment_mode, is_enabled)
                 VALUES (?, ?, 1)",
                [$billingPlanPriceId, $mode]
            );
        }
    }

    /**
     * @param array<string,mixed> $workspace
     * @param array<string,mixed> $basePrice
     * @param array<string,mixed> $normalized
     * @return array<string,mixed>
     */
    private function buildPriceMetadata(array $workspace, array $basePrice, array $normalized, string $status, int $offerId = 0): array
    {
        $features = (array) $normalized['features'];
        return [
            'workspace_negotiated' => true,
            'workspace_private' => true,
            'workspace_id' => (int) $workspace['id'],
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'offer_id' => $offerId > 0 ? $offerId : null,
            'base_billing_plan_price_id' => (int) ($basePrice['id'] ?? 0),
            'base_plan_id' => (int) ($basePrice['plan_id'] ?? 0),
            'base_plan_code' => (string) ($basePrice['plan_code'] ?? ''),
            'base_price_code' => (string) ($basePrice['price_code'] ?? ''),
            'checkout_available' => $this->statusAllowsCheckout($status),
            'requires_provider_plan' => true,
            'card_autopay_status' => 'sync_required',
            'display_name' => (string) $normalized['display_name'],
            'display_copy' => (string) $normalized['display_copy'],
            'public_display_name' => (string) $normalized['display_name'],
            'public_display_copy' => (string) $normalized['display_copy'],
            'public_summary' => (string) $normalized['display_copy'],
            'agreement_reference' => (string) $normalized['agreement_reference'],
            'negotiated_status' => $status,
            'entitlements' => [
                'seat_limit' => max(0, (int) ($features['seat_limit'] ?? 0)),
                'included_credits' => max(0, (int) ($normalized['included_credits'] ?? 0)),
                'can_top_up' => !empty($features['can_top_up']),
                'business_intelligence_enabled' => !empty($features['business_intelligence']),
                'personal_api_key_enabled' => !empty($features['personal_api_key']),
                'credit_expiry_days' => max(1, (int) ($features['credit_expiry_days'] ?? 180)),
                'is_custom' => true,
                'maturity_tier' => 'scale',
                'display_name' => (string) $normalized['display_name'],
                'public_summary' => (string) $normalized['display_copy'],
                'public_display_name' => (string) $normalized['display_name'],
                'public_display_copy' => (string) $normalized['display_copy'],
            ],
        ];
    }

    /**
     * @param array<string,mixed> $normalized
     * @param list<string> $paymentModes
     * @return array<string,mixed>
     */
    private function offerMetadata(array $normalized, array $paymentModes): array
    {
        return [
            'payment_modes' => $paymentModes,
            'features' => (array) ($normalized['features'] ?? []),
            'display_name' => (string) ($normalized['display_name'] ?? ''),
            'display_copy' => (string) ($normalized['display_copy'] ?? ''),
        ];
    }

    private function stampPriceOfferMetadata(int $priceId, int $offerId, string $status): void
    {
        $row = Database::queryOne("SELECT metadata_json FROM billing_plan_prices WHERE id = ? LIMIT 1", [$priceId]);
        if (!$row) {
            return;
        }

        $metadata = $this->decodeJson($row['metadata_json'] ?? null);
        $metadata['offer_id'] = $offerId;
        $metadata['negotiated_status'] = $status;
        $metadata['checkout_available'] = $this->statusAllowsCheckout($status);
        Database::execute(
            "UPDATE billing_plan_prices SET metadata_json = ?, updated_at = NOW() WHERE id = ?",
            [json_encode($metadata, JSON_UNESCAPED_SLASHES), $priceId]
        );
    }

    private function statusAllowsCheckout(string $status): bool
    {
        return in_array($status, self::OPEN_STATUSES, true);
    }

    /**
     * @param list<array<string,mixed>> $offers
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function filterOffers(array $offers, array $filters): array
    {
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $paymentMode = strtolower(trim((string) ($filters['payment_mode'] ?? '')));
        $query = strtolower(trim((string) ($filters['q'] ?? $filters['workspace_query'] ?? '')));

        return array_values(array_filter($offers, static function (array $offer) use ($status, $paymentMode, $query): bool {
            if ($status !== '' && $status !== 'all' && strtolower((string) ($offer['status'] ?? '')) !== $status) {
                return false;
            }
            if ($paymentMode !== '' && $paymentMode !== 'all') {
                $modes = array_map('strtolower', array_map('strval', (array) ($offer['payment_modes'] ?? [])));
                if (!in_array($paymentMode, $modes, true)) {
                    return false;
                }
            }
            if ($query !== '') {
                $haystack = strtolower(implode(' ', [
                    (string) ($offer['workspace_name'] ?? ''),
                    (string) ($offer['workspace_slug'] ?? ''),
                    (string) ($offer['workspace_id'] ?? ''),
                    (string) ($offer['agreement_reference'] ?? ''),
                    (string) ($offer['entitlements']['public_display_name'] ?? ''),
                ]));
                if (!str_contains($haystack, $query)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * @param list<array<string,mixed>> $summaries
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    private function filterWorkspaceSummaries(array $summaries, array $filters): array
    {
        $status = strtolower(trim((string) ($filters['status'] ?? '')));
        $paymentMode = strtolower(trim((string) ($filters['payment_mode'] ?? '')));
        $query = strtolower(trim((string) ($filters['q'] ?? $filters['workspace_query'] ?? '')));

        return array_values(array_filter($summaries, static function (array $summary) use ($status, $paymentMode, $query): bool {
            if ($status !== '' && $status !== 'all' && strtolower((string) ($summary['current_status'] ?? '')) !== $status) {
                return false;
            }
            if ($paymentMode !== '' && $paymentMode !== 'all') {
                $modes = array_map('strtolower', array_map('strval', (array) ($summary['payment_modes'] ?? [])));
                if (!in_array($paymentMode, $modes, true)) {
                    return false;
                }
            }
            if ($query !== '') {
                $haystack = strtolower(implode(' ', [
                    (string) ($summary['workspace_name'] ?? ''),
                    (string) ($summary['workspace_slug'] ?? ''),
                    (string) ($summary['workspace_id'] ?? ''),
                    (string) ($summary['current_package_name'] ?? ''),
                ]));
                if (!str_contains($haystack, $query)) {
                    return false;
                }
            }

            return true;
        }));
    }

    private function compareOffersForWorkspaceSummary(array $a, array $b): int
    {
        $rankA = $this->offerSortRank($a);
        $rankB = $this->offerSortRank($b);
        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }

        return strcmp((string) ($b['updated_at'] ?? $b['created_at'] ?? ''), (string) ($a['updated_at'] ?? $a['created_at'] ?? ''));
    }

    private function compareWorkspaceSummaries(array $a, array $b): int
    {
        $rankA = $this->summarySortRank($a);
        $rankB = $this->summarySortRank($b);
        if ($rankA !== $rankB) {
            return $rankA <=> $rankB;
        }

        return strcmp((string) ($b['last_updated_at'] ?? ''), (string) ($a['last_updated_at'] ?? ''));
    }

    /**
     * @param array<string,mixed> $offer
     */
    private function offerSortRank(array $offer): int
    {
        $status = (string) ($offer['status'] ?? '');
        if ($status === 'active') {
            return 10;
        }
        if (in_array($status, ['offered', 'accepted'], true)) {
            return 20;
        }
        if ($this->isOfferExpiringSoon($offer)) {
            return 30;
        }
        if ($status === 'draft') {
            return 50;
        }
        if ($status === 'expired') {
            return 80;
        }
        if ($status === 'archived') {
            return 90;
        }

        return 60;
    }

    /**
     * @param array<string,mixed> $summary
     */
    private function summarySortRank(array $summary): int
    {
        $status = (string) ($summary['current_status'] ?? '');
        if ($status === 'active') {
            return 10;
        }
        if (in_array($status, ['offered', 'accepted'], true)) {
            return 20;
        }
        if (!empty($summary['is_expiring_soon'])) {
            return 30;
        }
        if ($status === 'draft') {
            return 50;
        }
        if ($status === 'expired') {
            return 80;
        }
        if ($status === 'archived') {
            return 90;
        }

        return 60;
    }

    /**
     * @param array<string,mixed> $offer
     */
    private function isOfferExpiringSoon(array $offer): bool
    {
        $status = (string) ($offer['status'] ?? '');
        if (!in_array($status, self::OPEN_STATUSES, true)) {
            return false;
        }
        $expiresAt = trim((string) ($offer['expires_at'] ?? ''));
        if ($expiresAt === '') {
            return false;
        }
        $expiryTs = strtotime($expiresAt);
        if ($expiryTs === false) {
            return false;
        }
        $now = time();

        return $expiryTs >= $now && $expiryTs <= ($now + 30 * 86400);
    }

    /**
     * @param array<string,mixed> $offer
     */
    private function isCardSyncBlocked(array $offer): bool
    {
        $status = (string) ($offer['status'] ?? '');
        if (!in_array($status, self::OPEN_STATUSES, true)) {
            return false;
        }

        return trim((string) ($offer['provider_plan_code'] ?? '')) === '';
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, ['draft', 'offered', 'accepted', 'active', 'expired', 'archived'], true)) {
            throw new \RuntimeException('Choose a valid negotiated offer status.');
        }

        return $status;
    }

    private function normalizeCurrency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (!preg_match('/^[A-Z]{3,10}$/', $currency)) {
            throw new \RuntimeException('Currency must be a valid uppercase currency code.');
        }

        return $currency;
    }

    private function normalizeIntervalUnit(string $intervalUnit): string
    {
        $intervalUnit = strtolower(trim($intervalUnit));
        if (!in_array($intervalUnit, ['weekly', 'monthly', 'quarterly', 'yearly'], true)) {
            throw new \RuntimeException('Choose a valid negotiated package billing interval.');
        }

        return $intervalUnit;
    }

    private function normalizeDateTime($value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            throw new \RuntimeException('Use a valid date/time for negotiated package dates.');
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function normalizeFeatureValue(string $valueType, $value)
    {
        return match ($valueType) {
            'boolean' => !empty($value),
            'integer' => max(0, (int) $value),
            'decimal' => max(0, (float) $value),
            default => trim((string) $value),
        };
    }

    /**
     * @param array<string,mixed> $input
     */
    private function boolInput(array $input, string $key, bool $default): bool
    {
        if (array_key_exists($key, $input)) {
            return !empty($input[$key]);
        }
        if (array_key_exists($key . '_enabled', $input)) {
            return !empty($input[$key . '_enabled']);
        }

        return $default;
    }

    private function uniquePriceCode(string $baseCode): string
    {
        $baseCode = preg_replace('/[^a-z0-9-]+/', '-', strtolower($baseCode)) ?: 'negotiated-package';
        $baseCode = trim($baseCode, '-');
        $candidate = substr($baseCode, 0, 52);
        $suffix = '-' . bin2hex(random_bytes(3));
        $candidate = substr($candidate, 0, 64 - strlen($suffix)) . $suffix;
        while (Database::queryOne("SELECT id FROM billing_plan_prices WHERE price_code = ? LIMIT 1", [$candidate])) {
            $suffix = '-' . bin2hex(random_bytes(3));
            $candidate = substr($baseCode, 0, 64 - strlen($suffix)) . $suffix;
        }

        return $candidate;
    }

    /**
     * @param mixed $json
     * @return array<string,mixed>
     */
    private function decodeJson($json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $offer
     * @return array<string,mixed>
     */
    private function auditOffer(array $offer): array
    {
        return [
            'id' => (int) ($offer['id'] ?? 0),
            'workspace_id' => (int) ($offer['workspace_id'] ?? 0),
            'status' => (string) ($offer['status'] ?? ''),
            'base_billing_plan_price_id' => (int) ($offer['base_billing_plan_price_id'] ?? 0),
            'negotiated_billing_plan_price_id' => (int) ($offer['negotiated_billing_plan_price_id'] ?? 0),
            'amount' => (float) ($offer['amount'] ?? 0),
            'currency' => (string) ($offer['currency'] ?? ''),
            'interval_unit' => (string) ($offer['interval_unit'] ?? ''),
            'interval_count' => (int) ($offer['interval_count'] ?? 0),
            'payment_modes' => (array) ($offer['payment_modes'] ?? []),
        ];
    }
}
