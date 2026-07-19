<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Notifications;
use CRM\Modules\WorkspaceBillingSettings;

class WorkspaceBillingService
{
    private WorkspaceBillingSettings $settingsModule;
    private BillingHubClientInterface $billingHubClient;
    private ?array $settingsCache = null;

    public function __construct(?BillingHubClientInterface $billingHubClient = null)
    {
        $this->settingsModule = new WorkspaceBillingSettings();
        $this->billingHubClient = $billingHubClient ?? new BillingHubClient();
    }

    public function getSettings(): array
    {
        if ($this->settingsCache === null) {
            $this->settingsCache = $this->settingsModule->get();
        }

        return $this->settingsCache;
    }

    public function shouldExposeCompatibilityState(): bool
    {
        if (!$this->tablesAvailable()) {
            return false;
        }

        return $this->isCentralHubMode() || $this->isLegacyLocalProviderMode();
    }

    public function isLegacyLocalProviderMode(): bool
    {
        $settings = $this->getSettings();

        return (string) ($settings['billing_mode'] ?? '') === 'local_provider';
    }

    public function saveSettings(array $data, ?int $updatedBy = null): void
    {
        $this->settingsModule->save($data, $updatedBy);
        $this->settingsCache = null;
    }

    public function saveContactsFromTextarea(string $rawEmails): void
    {
        if (!$this->tablesAvailable()) {
            return;
        }

        $items = preg_split('/[\r\n,;]+/', $rawEmails) ?: [];
        $seen = [];
        Database::execute("UPDATE workspace_billing_contacts SET is_active = 0");
        foreach ($items as $email) {
            $normalized = strtolower(trim($email));
            if ($normalized === '' || isset($seen[$normalized]) || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $seen[$normalized] = true;
            $existing = Database::queryOne("SELECT id FROM workspace_billing_contacts WHERE email = ?", [$normalized]);
            if ($existing) {
                Database::execute(
                    "UPDATE workspace_billing_contacts SET is_active = 1, is_primary = ?, updated_at = NOW() WHERE id = ?",
                    [count($seen) === 1 ? 1 : 0, (int) $existing['id']]
                );
            } else {
                Database::execute(
                    "INSERT INTO workspace_billing_contacts (email, is_primary, is_active) VALUES (?, ?, 1)",
                    [$normalized, count($seen) === 1 ? 1 : 0]
                );
            }
        }
    }

    public function listContacts(): array
    {
        if (!$this->tablesAvailable()) {
            return [];
        }

        return Database::query(
            "SELECT * FROM workspace_billing_contacts WHERE is_active = 1 ORDER BY is_primary DESC, email ASC"
        );
    }

    public function getContactsTextarea(): string
    {
        return implode("\n", array_map(
            static fn (array $contact): string => (string) ($contact['email'] ?? ''),
            $this->listContacts()
        ));
    }

    public function saveDefaultPlan(array $data, ?int $updatedBy = null): void
    {
        if (!$this->tablesAvailable()) {
            return;
        }

        $intervalUnit = in_array((string) ($data['interval_unit'] ?? 'monthly'), ['weekly', 'monthly', 'quarterly', 'yearly'], true)
            ? (string) $data['interval_unit']
            : 'monthly';
        $record = [
            'name' => trim((string) ($data['name'] ?? 'Default Workspace Plan')),
            'description' => trim((string) ($data['description'] ?? '')),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'interval_unit' => $intervalUnit,
            'interval_count' => max(1, (int) ($data['interval_count'] ?? 1)),
            'amount' => max(0, (float) ($data['amount'] ?? 0)),
            'currency' => trim((string) ($data['currency'] ?? ($this->getSettings()['default_currency'] ?? 'KES'))),
            'grace_days' => max(0, (int) ($data['grace_days'] ?? ($this->getSettings()['grace_days'] ?? 3))),
            'next_charge_date' => $this->normalizeDate($data['next_charge_date'] ?? date('Y-m-d')),
        ];

        $existing = Database::queryOne("SELECT id FROM workspace_billing_plans WHERE id = 1");
        if ($existing) {
            Database::execute(
                "UPDATE workspace_billing_plans SET
                    name = ?, description = ?, is_active = ?, interval_unit = ?, interval_count = ?,
                    amount = ?, currency = ?, grace_days = ?, next_charge_date = ?, updated_by = ?, updated_at = NOW()
                 WHERE id = 1",
                [
                    $record['name'],
                    $record['description'],
                    $record['is_active'],
                    $record['interval_unit'],
                    $record['interval_count'],
                    $record['amount'],
                    $record['currency'],
                    $record['grace_days'],
                    $record['next_charge_date'],
                    $updatedBy,
                ]
            );
            return;
        }

        Database::execute(
            "INSERT INTO workspace_billing_plans
                (id, name, description, is_active, interval_unit, interval_count, amount, currency, grace_days, next_charge_date, created_by, updated_by)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $record['name'],
                $record['description'],
                $record['is_active'],
                $record['interval_unit'],
                $record['interval_count'],
                $record['amount'],
                $record['currency'],
                $record['grace_days'],
                $record['next_charge_date'],
                $updatedBy,
                $updatedBy,
            ]
        );
    }

    public function getDefaultPlan(): array
    {
        if (!$this->tablesAvailable()) {
            return [];
        }

        return Database::queryOne("SELECT * FROM workspace_billing_plans WHERE id = 1") ?? [];
    }

    public function createOneTimeCharge(array $data, ?int $userId = null): int
    {
        if (!$this->tablesAvailable()) {
            throw new \RuntimeException('Workspace billing is not available.');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new \RuntimeException('Charge title is required.');
        }

        $amount = round(max(0, (float) ($data['amount'] ?? 0)), 2);
        if ($amount <= 0) {
            throw new \RuntimeException('Charge amount must be greater than zero.');
        }

        $dueDate = $this->normalizeDate($data['due_date'] ?? date('Y-m-d'));
        $currency = trim((string) ($data['currency'] ?? ($this->getSettings()['default_currency'] ?? 'KES')));
        $chargeType = (string) ($data['charge_type'] ?? 'one_time');
        if (!in_array($chargeType, ['one_time', 'adjustment', 'recurring'], true)) {
            $chargeType = 'one_time';
        }

        Database::execute(
            "INSERT INTO workspace_billing_charges
                (plan_id, cycle_id, charge_type, status, title, description, amount, currency, quantity, due_date, billing_period_start, billing_period_end, created_by, updated_by)
             VALUES (?, ?, ?, 'open', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                !empty($data['plan_id']) ? (int) $data['plan_id'] : null,
                !empty($data['cycle_id']) ? (int) $data['cycle_id'] : null,
                $chargeType,
                $title,
                trim((string) ($data['description'] ?? '')),
                $amount,
                $currency,
                max(1, (float) ($data['quantity'] ?? 1)),
                $dueDate,
                $this->normalizeNullableDate($data['billing_period_start'] ?? null),
                $this->normalizeNullableDate($data['billing_period_end'] ?? null),
                $userId,
                $userId,
            ]
        );

        $chargeId = (int) Database::lastInsertId();
        $this->syncWorkspaceStatus('manual_charge_created', ['charge_id' => $chargeId]);
        return $chargeId;
    }

    public function updateChargeStatus(int $chargeId, string $status, ?int $userId = null): void
    {
        if (!$this->tablesAvailable() || $chargeId <= 0) {
            return;
        }

        $status = in_array($status, ['open', 'waived', 'cancelled', 'paid'], true) ? $status : 'open';
        $fields = ['status = ?', 'updated_by = ?', 'updated_at = NOW()'];
        $params = [$status, $userId];
        if ($status === 'waived') {
            $fields[] = 'waived_at = NOW()';
        } elseif ($status === 'cancelled') {
            $fields[] = 'cancelled_at = NOW()';
        } elseif ($status === 'paid') {
            $fields[] = 'settled_at = NOW()';
        }
        $params[] = $chargeId;
        Database::execute("UPDATE workspace_billing_charges SET " . implode(', ', $fields) . " WHERE id = ?", $params);
        $this->syncWorkspaceStatus('charge_status_updated', ['charge_id' => $chargeId, 'status' => $status], $userId);
    }

    public function listCharges(array $filters = []): array
    {
        if (!$this->tablesAvailable()) {
            return [];
        }

        $where = [];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'c.status = ?';
            $params[] = $filters['status'];
        }
        $sql = "SELECT c.*, p.name AS plan_name
                FROM workspace_billing_charges c
                LEFT JOIN workspace_billing_plans p ON p.id = c.plan_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY c.due_date ASC, c.id DESC';
        return Database::query($sql, $params);
    }

    public function ensureRecurringChargesUpToDate(?int $userId = null): int
    {
        if (!$this->tablesAvailable()) {
            return 0;
        }

        $plan = $this->getDefaultPlan();
        if (empty($plan) || empty($plan['is_active']) || (float) ($plan['amount'] ?? 0) <= 0) {
            return 0;
        }

        $today = date('Y-m-d');
        $nextChargeDate = $this->normalizeDate($plan['next_charge_date'] ?? $today);
        $created = 0;

        while ($nextChargeDate <= $today) {
            $periodStart = $nextChargeDate;
            $periodEnd = $this->calculatePeriodEnd($periodStart, (string) ($plan['interval_unit'] ?? 'monthly'), (int) ($plan['interval_count'] ?? 1));
            $cycleKey = 'plan-' . (int) $plan['id'] . '-' . $periodStart;
            $existingCycle = Database::queryOne("SELECT id FROM workspace_billing_cycles WHERE cycle_key = ?", [$cycleKey]);
            if (!$existingCycle) {
                Database::execute(
                    "INSERT INTO workspace_billing_cycles (plan_id, cycle_key, period_start, period_end, due_date, status)
                     VALUES (?, ?, ?, ?, ?, 'open')",
                    [(int) $plan['id'], $cycleKey, $periodStart, $periodEnd, $periodStart]
                );
                $cycleId = (int) Database::lastInsertId();
                $this->createOneTimeCharge([
                    'plan_id' => (int) $plan['id'],
                    'cycle_id' => $cycleId,
                    'charge_type' => 'recurring',
                    'title' => trim((string) ($plan['name'] ?? 'Workspace Subscription')),
                    'description' => trim((string) ($plan['description'] ?? '')),
                    'amount' => (float) ($plan['amount'] ?? 0),
                    'currency' => (string) ($plan['currency'] ?? 'KES'),
                    'due_date' => $periodStart,
                    'billing_period_start' => $periodStart,
                    'billing_period_end' => $periodEnd,
                ], $userId);
                $created++;
            }

            $nextChargeDate = $this->calculateNextChargeDate($nextChargeDate, (string) ($plan['interval_unit'] ?? 'monthly'), (int) ($plan['interval_count'] ?? 1));
        }

        Database::execute(
            "UPDATE workspace_billing_plans SET next_charge_date = ?, updated_by = ?, updated_at = NOW() WHERE id = 1",
            [$nextChargeDate, $userId]
        );

        return $created;
    }

    public function getBillingStateForUser(?array $user = null): array
    {
        $user = $user ?? Auth::user();
        $summary = $this->getWorkspaceSummary();
        $settings = $summary['settings'] ?? $this->getSettings();
        $isBypass = Authorization::isSuperAdmin($user);
        $isLocked = !$isBypass && ($summary['status'] ?? 'current') === 'locked';
        $promptsEnabled = !empty($settings['enabled']) && !empty($settings['in_app_prompts_enabled']);

        $state = array_merge($summary, [
            'is_admin_bypass' => $isBypass,
            'restricted' => $isLocked,
            'show_prompt' => !$isBypass && $promptsEnabled && in_array((string) ($summary['status'] ?? 'current'), ['payment_due', 'grace'], true),
        ]);

        return $state;
    }

    public function getWorkspaceSummary(): array
    {
        if (!$this->tablesAvailable()) {
            return $this->defaultSummary();
        }

        if ($this->isCentralHubMode()) {
            return $this->buildCentralHubSummary();
        }

        $this->ensureRecurringChargesUpToDate();
        return $this->syncWorkspaceStatus();
    }

    public function syncWorkspaceStatus(?string $reason = null, array $metadata = [], ?int $userId = null): array
    {
        if (!$this->tablesAvailable()) {
            return $this->defaultSummary();
        }

        $settings = $this->getSettings();

        if (empty($settings['enabled'])) {
            $currentStatus = (string) ($settings['workspace_status'] ?? 'current');
            if ($currentStatus !== 'current') {
                $this->saveSettings([
                    'workspace_status' => 'current',
                    'last_status_changed_at' => date('Y-m-d H:i:s'),
                ], $userId);
                Database::execute(
                    "INSERT INTO workspace_billing_status_history (previous_status, new_status, reason, metadata_json, created_by)
                     VALUES (?, 'current', ?, ?, ?)",
                    [$currentStatus, $reason ?: 'billing_disabled', json_encode($metadata), $userId]
                );
            }

            $summary = $this->defaultSummary();
            $summary['settings'] = $this->getSettings();
            return $summary;
        }

        $openCharges = $this->getOutstandingCharges();
        $outstandingAmount = 0.0;
        $currency = (string) ($settings['default_currency'] ?? 'KES');
        $earliestDueDate = null;
        foreach ($openCharges as $charge) {
            $outstandingAmount += (float) ($charge['amount'] ?? 0);
            if ($earliestDueDate === null || strcmp((string) $charge['due_date'], $earliestDueDate) < 0) {
                $earliestDueDate = (string) $charge['due_date'];
            }
            if (!empty($charge['currency'])) {
                $currency = (string) $charge['currency'];
            }
        }

        $newStatus = 'current';
        $graceExpiresAt = null;
        $today = date('Y-m-d');
        if ($outstandingAmount > 0 && $earliestDueDate !== null) {
            if ($earliestDueDate > $today) {
                $newStatus = 'payment_due';
            } else {
                $graceDays = max(0, (int) ($settings['grace_days'] ?? 0));
                $graceExpiresAt = date('Y-m-d H:i:s', strtotime($earliestDueDate . ' +' . $graceDays . ' days 23:59:59'));
                if (!empty($settings['auto_lock_enabled']) && strtotime($graceExpiresAt) < time()) {
                    $newStatus = 'locked';
                } else {
                    $newStatus = 'grace';
                }
            }
        }

        $previousStatus = (string) ($settings['workspace_status'] ?? 'current');
        $statusChanged = $previousStatus !== $newStatus;
        $updateData = [
            'workspace_status' => $newStatus,
            'last_status_changed_at' => $statusChanged ? date('Y-m-d H:i:s') : ($settings['last_status_changed_at'] ?? null),
            'last_locked_at' => $newStatus === 'locked' ? date('Y-m-d H:i:s') : ($settings['last_locked_at'] ?? null),
        ];
        if ($newStatus === 'current' && $outstandingAmount <= 0) {
            $updateData['last_paid_at'] = date('Y-m-d H:i:s');
        }

        $this->saveSettings($updateData, $userId);
        if ($statusChanged) {
            Database::execute(
                "INSERT INTO workspace_billing_status_history (previous_status, new_status, reason, metadata_json, created_by)
                 VALUES (?, ?, ?, ?, ?)",
                [$previousStatus, $newStatus, $reason, json_encode($metadata), $userId]
            );
        }

        return [
            'status' => $newStatus,
            'amount_due' => round($outstandingAmount, 2),
            'currency' => $currency,
            'due_date' => $earliestDueDate,
            'grace_expires_at' => $graceExpiresAt,
            'charge_count' => count($openCharges),
            'charges' => $openCharges,
            'payment_url' => publicUrl('billing_start_payment.php'),
            'settings' => $this->getSettings(),
            'is_trial_active' => false,
            'trial_starts_at' => null,
            'trial_ends_at' => null,
            'trial_notes' => null,
        ];
    }

    public function createHostedCheckout(?int $userId = null, string $channel = 'web', ?string $returnUrl = null): array
    {
        if (!$this->tablesAvailable()) {
            throw new \RuntimeException('Workspace billing is not available.');
        }

        if ($this->isCentralHubMode()) {
            return $this->createCentralHubCheckout($userId, $channel, $returnUrl);
        }

        $summary = $this->getWorkspaceSummary();
        if ((float) ($summary['amount_due'] ?? 0) <= 0) {
            throw new \RuntimeException('There is no outstanding workspace balance to pay.');
        }

        $settings = $summary['settings'] ?? $this->getSettings();
        if (empty($settings['enabled'])) {
            throw new \RuntimeException('Workspace billing is currently disabled.');
        }
        $secretKey = trim((string) ($settings['paystack_secret_key'] ?? ''));
        if ($secretKey === '') {
            throw new \RuntimeException('Paystack secret key is not configured.');
        }

        $charges = $summary['charges'] ?? [];
        $contact = $this->listContacts()[0] ?? null;
        $customerEmail = (string) ($contact['email'] ?? $this->fallbackBillingEmail());
        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('A valid billing contact email is required before starting payment.');
        }

        $reference = 'wb_' . date('YmdHis') . '_' . bin2hex(random_bytes(6));
        $callbackUrl = $returnUrl ?: trim((string) ($settings['paystack_callback_url'] ?? ''));
        if ($callbackUrl === '') {
            $callbackUrl = WorkspaceBillingSettings::generatedPaystackCallbackUrl();
        }
        $gateway = new PaystackGateway($secretKey);
        $payload = [
            'email' => $customerEmail,
            'amount' => (int) round(((float) $summary['amount_due']) * 100),
            'currency' => (string) ($summary['currency'] ?? 'KES'),
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'source' => 'workspace_billing',
                'channel' => $channel,
                'charge_ids' => array_map(static fn (array $charge): int => (int) ($charge['id'] ?? 0), $charges),
            ],
        ];
        $response = $gateway->initializeTransaction($payload);
        $data = (array) ($response['data'] ?? []);

        Database::beginTransaction();
        try {
            Database::execute(
                "INSERT INTO workspace_billing_payment_intents
                    (provider, reference, status, amount, currency, customer_email, authorization_url, access_code, provider_transaction_id, channel, callback_url, provider_response_json, expires_at, created_by)
                 VALUES ('paystack', ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $reference,
                    (float) $summary['amount_due'],
                    (string) ($summary['currency'] ?? 'KES'),
                    $customerEmail,
                    (string) ($data['authorization_url'] ?? ''),
                    (string) ($data['access_code'] ?? ''),
                    (string) ($data['reference'] ?? ''),
                    $channel,
                    $callbackUrl,
                    json_encode($response),
                    date('Y-m-d H:i:s', time() + 3600),
                    $userId,
                ]
            );
            $intentId = (int) Database::lastInsertId();
            foreach ($charges as $charge) {
                Database::execute(
                    "INSERT INTO workspace_billing_payment_intent_items (payment_intent_id, charge_id, amount) VALUES (?, ?, ?)",
                    [$intentId, (int) $charge['id'], (float) $charge['amount']]
                );
                Database::execute(
                    "UPDATE workspace_billing_charges SET status = 'pending', updated_by = ?, updated_at = NOW() WHERE id = ?",
                    [$userId, (int) $charge['id']]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'reference' => $reference,
            'authorization_url' => (string) ($data['authorization_url'] ?? ''),
            'access_code' => (string) ($data['access_code'] ?? ''),
            'amount' => (float) $summary['amount_due'],
            'currency' => (string) ($summary['currency'] ?? 'KES'),
            'status' => 'pending',
        ];
    }

    public function hasPaymentIntentReference(string $reference): bool
    {
        $reference = trim($reference);
        if ($reference === '' || !$this->tablesAvailable()) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT id
             FROM workspace_billing_payment_intents
             WHERE reference = ?
             LIMIT 1",
            [$reference]
        );

        return !empty($row['id']);
    }

    public function verifyAndApplyPayment(string $reference): array
    {
        if (!$this->tablesAvailable()) {
            throw new \RuntimeException('Workspace billing is not available.');
        }

        if ($this->isCentralHubMode()) {
            $summary = $this->refreshFromBillingHub();
            return [
                'success' => (string) ($summary['status'] ?? 'current') === 'current',
                'reference' => $reference,
                'summary' => $summary,
                'provider' => ['mode' => 'central_hub'],
            ];
        }

        $intent = Database::queryOne(
            "SELECT * FROM workspace_billing_payment_intents WHERE reference = ? LIMIT 1",
            [$reference]
        );
        if (!$intent) {
            throw new \RuntimeException('Payment intent not found for this reference.');
        }

        $settings = $this->getSettings();
        $gateway = new PaystackGateway((string) ($settings['paystack_secret_key'] ?? ''));
        $response = $gateway->verifyTransaction($reference);
        $data = (array) ($response['data'] ?? []);
        $paid = (($data['status'] ?? '') === 'success');

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_billing_payment_intents
                 SET status = ?, provider_transaction_id = ?, provider_response_json = ?, paid_at = ?, updated_at = NOW()
                 WHERE id = ?",
                [
                    $paid ? 'paid' : 'failed',
                    (string) ($data['id'] ?? ''),
                    json_encode($response),
                    $paid ? date('Y-m-d H:i:s') : null,
                    (int) $intent['id'],
                ]
            );

            $chargeItems = Database::query(
                "SELECT charge_id FROM workspace_billing_payment_intent_items WHERE payment_intent_id = ?",
                [(int) $intent['id']]
            );
            foreach ($chargeItems as $item) {
                Database::execute(
                    "UPDATE workspace_billing_charges
                     SET status = ?, settled_at = ?, updated_at = NOW()
                     WHERE id = ?",
                    [$paid ? 'paid' : 'open', $paid ? date('Y-m-d H:i:s') : null, (int) $item['charge_id']]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        $summary = $this->syncWorkspaceStatus($paid ? 'payment_verified' : 'payment_failed', ['reference' => $reference]);
        if ($paid) {
            $this->notifyAdminsOfPayment($reference, (float) ($intent['amount'] ?? 0), (string) ($intent['currency'] ?? 'KES'));
        }

        return [
            'success' => $paid,
            'reference' => $reference,
            'summary' => $summary,
            'provider' => $response,
        ];
    }

    public function processWebhook(string $rawPayload, ?string $signature): array
    {
        if (!$this->tablesAvailable()) {
            return ['accepted' => false, 'message' => 'Workspace billing is not available.'];
        }

        if ($this->isCentralHubMode()) {
            return ['accepted' => false, 'message' => 'Direct provider webhooks are disabled while billing hub mode is active.'];
        }

        $settings = $this->getSettings();
        $gateway = new PaystackGateway((string) ($settings['paystack_secret_key'] ?? ''));
        $decoded = json_decode($rawPayload, true);
        if (!is_array($decoded)) {
            $this->logWebhook('ignored', 'invalid_json', $signature, null, 'Invalid JSON payload');
            return ['accepted' => false, 'message' => 'Invalid JSON payload.'];
        }

        if (!$gateway->verifyWebhookSignature($rawPayload, $signature)) {
            $this->logWebhook('rejected', (string) ($decoded['event'] ?? ''), $signature, $decoded, 'Invalid signature');
            return ['accepted' => false, 'message' => 'Invalid webhook signature.'];
        }

        $event = (string) ($decoded['event'] ?? '');
        $reference = (string) (($decoded['data']['reference'] ?? '') ?: '');
        $message = 'Webhook accepted';
        if ($event === 'charge.success' && $reference !== '') {
            $this->verifyAndApplyPayment($reference);
            $message = 'Payment applied';
        }

        $this->logWebhook('accepted', $event, $signature, $decoded, $message);
        return ['accepted' => true, 'message' => $message];
    }

    public function processHubStatusSync(string $rawPayload, ?string $signature): array
    {
        if (!$this->tablesAvailable()) {
            return ['accepted' => false, 'message' => 'Workspace billing is not available.'];
        }

        if (!$this->isCentralHubMode()) {
            return ['accepted' => false, 'message' => 'Billing hub sync is not enabled for this installation.'];
        }

        $decoded = json_decode($rawPayload, true);
        if (!is_array($decoded)) {
            return ['accepted' => false, 'message' => 'Invalid JSON payload.'];
        }

        if (!$this->verifyHubSyncSignature($rawPayload, $signature)) {
            return ['accepted' => false, 'message' => 'Invalid billing hub signature.'];
        }

        $payload = $this->normalizeHubSyncPayload($decoded);
        $expectedWorkspaceKey = trim((string) ($this->getSettings()['billing_workspace_key'] ?? ''));
        if ($expectedWorkspaceKey === '' || $payload['workspace_key'] !== $expectedWorkspaceKey) {
            return ['accepted' => false, 'message' => 'Workspace key does not match this installation.'];
        }

        $current = $this->getSettings();
        $lastSyncedAt = $this->normalizeNullableDateTime($current['hub_last_synced_at'] ?? null);
        if (
            $lastSyncedAt !== null
            && $payload['updated_at'] !== null
            && strtotime($payload['updated_at']) <= strtotime($lastSyncedAt)
        ) {
            return ['accepted' => false, 'message' => 'Stale billing hub update ignored.'];
        }

        $summary = $this->applyHubSyncPayload($payload, 'hub_push');
        return [
            'accepted' => true,
            'message' => 'Workspace billing status updated.',
            'summary' => $summary,
        ];
    }

    public function refreshFromBillingHub(): array
    {
        if (!$this->isCentralHubMode()) {
            return $this->getWorkspaceSummary();
        }

        $settings = $this->getSettings();
        $this->assertCentralHubConfigured($settings);
        $payload = $this->normalizeHubSyncPayload($this->billingHubClient->fetchStatus($settings));

        return $this->applyHubSyncPayload($payload, 'hub_pull');
    }

    public function triggerReminders(): int
    {
        if (!$this->tablesAvailable()) {
            return 0;
        }

        $summary = $this->getWorkspaceSummary();
        $status = (string) ($summary['status'] ?? 'current');
        if (!in_array($status, ['payment_due', 'grace', 'locked'], true)) {
            return 0;
        }

        $settings = $summary['settings'] ?? $this->getSettings();
        if (empty($settings['enabled']) || empty($settings['email_reminders_enabled'])) {
            return 0;
        }

        $contacts = $this->listContacts();
        if (empty($contacts)) {
            return 0;
        }

        $subject = 'Workspace billing reminder';
        $message = sprintf(
            "Your workspace has an outstanding balance of %s %s due by %s.",
            (string) ($summary['currency'] ?? 'KES'),
            number_format((float) ($summary['amount_due'] ?? 0), 2),
            (string) ($summary['due_date'] ?? 'today')
        );

        $emailService = new EmailService();
        foreach ($contacts as $contact) {
            try {
                $emailService->sendImmediate(
                    0,
                    (string) $contact['email'],
                    $subject,
                    strip_tags($message),
                    ['body_html' => nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'))]
                );
            } catch (\Throwable $e) {
                error_log('WorkspaceBillingService::triggerReminders email failed: ' . $e->getMessage());
            }
        }

        (new Notifications())->createForAdmins(
            'warning',
            'Workspace payment reminder sent',
            $message,
            ['link' => 'billing_payment_required.php', 'severity' => $status === 'locked' ? 'high' : 'medium']
        );

        return count($contacts);
    }

    public function runMaintenance(?int $userId = null): array
    {
        if ($this->isCentralHubMode()) {
            $summary = $this->refreshFromBillingHub();
            $reminders = $this->triggerReminders();

            return [
                'generated_charges' => 0,
                'reminders_sent' => $reminders,
                'summary' => $summary,
            ];
        }

        $generatedCharges = $this->ensureRecurringChargesUpToDate($userId);
        $summary = $this->syncWorkspaceStatus('maintenance_run', ['generated_charges' => $generatedCharges], $userId);
        $reminders = $this->triggerReminders();

        return [
            'generated_charges' => $generatedCharges,
            'reminders_sent' => $reminders,
            'summary' => $summary,
        ];
    }

    private function getOutstandingCharges(): array
    {
        return Database::query(
            "SELECT *
             FROM workspace_billing_charges
             WHERE status IN ('open', 'pending')
             ORDER BY due_date ASC, id ASC"
        );
    }

    private function notifyAdminsOfPayment(string $reference, float $amount, string $currency): void
    {
        (new Notifications())->createForAdmins(
            'success',
            'Workspace payment received',
            sprintf('Workspace billing payment %s for %s %s was confirmed.', $reference, $currency, number_format($amount, 2)),
            ['link' => 'billing_payment_required.php', 'severity' => 'low']
        );
    }

    private function logWebhook(string $verificationStatus, string $eventName, ?string $signature, ?array $payload, string $message): void
    {
        Database::execute(
            "INSERT INTO workspace_billing_webhook_logs (provider, event_name, signature, payload_json, verification_status, message)
             VALUES ('paystack', ?, ?, ?, ?, ?)",
            [$eventName, $signature, $payload ? json_encode($payload) : null, $verificationStatus, $message]
        );
    }

    private function createCentralHubCheckout(?int $userId = null, string $channel = 'web', ?string $returnUrl = null): array
    {
        $summary = $this->refreshFromBillingHub();
        if ((float) ($summary['amount_due'] ?? 0) <= 0) {
            throw new \RuntimeException('There is no outstanding workspace balance to pay.');
        }

        $settings = $summary['settings'] ?? $this->getSettings();
        if (empty($settings['enabled'])) {
            throw new \RuntimeException('Workspace billing is currently disabled.');
        }

        $this->assertCentralHubConfigured($settings);

        $contact = $this->listContacts()[0] ?? null;
        $customerEmail = (string) ($contact['email'] ?? $this->fallbackBillingEmail());
        $localReference = 'hub_' . date('YmdHis') . '_' . bin2hex(random_bytes(6));
        $response = $this->billingHubClient->createCheckout($settings, [
            'workspace_key' => (string) ($settings['billing_workspace_key'] ?? ''),
            'reference' => $localReference,
            'amount_due' => round((float) ($summary['amount_due'] ?? 0), 2),
            'currency' => (string) ($summary['currency'] ?? 'KES'),
            'due_at' => $this->formatNullableDateTimeValue($summary['due_date'] ?? null),
            'grace_expires_at' => $this->formatNullableDateTimeValue($summary['grace_expires_at'] ?? null),
            'channel' => $channel,
            'customer_email' => $customerEmail,
            'return_url' => $returnUrl ?: ($settings['mobile_return_url'] ?? publicUrl('billing_callback.php')),
            'crm_status_sync_url' => apiUrl('workspace_billing/status_sync.php'),
            'crm_callback_url' => publicUrl('billing_callback.php'),
        ]);

        $authorizationUrl = (string) ($response['authorization_url'] ?? $response['checkout_url'] ?? '');
        if ($authorizationUrl === '') {
            throw new \RuntimeException('Billing hub did not return a checkout URL.');
        }

        $providerReference = (string) ($response['reference'] ?? $localReference);
        $charges = $this->listCharges(['status' => 'open']);

        Database::beginTransaction();
        try {
            Database::execute(
                "INSERT INTO workspace_billing_payment_intents
                    (provider, reference, status, amount, currency, customer_email, authorization_url, provider_transaction_id, channel, callback_url, provider_response_json, expires_at, created_by)
                 VALUES ('billing_hub', ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $providerReference,
                    (float) ($summary['amount_due'] ?? 0),
                    (string) ($summary['currency'] ?? 'KES'),
                    $customerEmail,
                    $authorizationUrl,
                    $localReference,
                    $channel,
                    $returnUrl ?: ($settings['mobile_return_url'] ?? publicUrl('billing_callback.php')),
                    json_encode($response),
                    $this->normalizeNullableDateTime($response['expires_at'] ?? date('Y-m-d H:i:s', time() + 3600)),
                    $userId,
                ]
            );
            $intentId = (int) Database::lastInsertId();
            foreach ($charges as $charge) {
                if ((string) ($charge['status'] ?? 'open') !== 'open') {
                    continue;
                }
                Database::execute(
                    "INSERT INTO workspace_billing_payment_intent_items (payment_intent_id, charge_id, amount) VALUES (?, ?, ?)",
                    [$intentId, (int) $charge['id'], (float) $charge['amount']]
                );
                Database::execute(
                    "UPDATE workspace_billing_charges SET status = 'pending', updated_by = ?, updated_at = NOW() WHERE id = ?",
                    [$userId, (int) $charge['id']]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return [
            'reference' => $providerReference,
            'authorization_url' => $authorizationUrl,
            'amount' => (float) ($summary['amount_due'] ?? 0),
            'currency' => (string) ($summary['currency'] ?? 'KES'),
            'status' => 'pending',
            'provider' => 'billing_hub',
        ];
    }

    private function buildCentralHubSummary(): array
    {
        $settings = $this->getSettings();
        $status = $this->normalizeStatus((string) ($settings['workspace_status'] ?? 'current'));
        $charges = $this->getOutstandingCharges();
        $currency = (string) ($settings['hub_synced_currency'] ?? $settings['default_currency'] ?? 'KES');
        if ($currency === '') {
            $currency = 'KES';
        }

        return [
            'status' => $status,
            'amount_due' => round((float) ($settings['hub_synced_amount_due'] ?? 0), 2),
            'currency' => $currency,
            'due_date' => $this->normalizeNullableDateTime($settings['hub_synced_due_at'] ?? null),
            'grace_expires_at' => $this->normalizeNullableDateTime($settings['hub_synced_grace_expires_at'] ?? null),
            'charge_count' => count($charges),
            'charges' => $charges,
            'payment_url' => publicUrl('billing_start_payment.php'),
            'settings' => $settings,
            'is_trial_active' => false,
            'trial_starts_at' => null,
            'trial_ends_at' => null,
            'trial_notes' => null,
        ];
    }

    private function applyHubSyncPayload(array $payload, string $source): array
    {
        $settings = $this->getSettings();
        $status = $payload['status'];
        $previousStatus = $this->normalizeStatus((string) ($settings['workspace_status'] ?? 'current'));
        $statusChanged = $previousStatus !== $status;
        $saveData = [
            'workspace_status' => $status,
            'hub_synced_amount_due' => round((float) ($payload['amount_due'] ?? 0), 2),
            'hub_synced_currency' => (string) ($payload['currency'] ?? ($settings['default_currency'] ?? 'KES')),
            'hub_synced_due_at' => $payload['due_at'],
            'hub_synced_grace_expires_at' => $payload['grace_expires_at'],
            'hub_last_synced_at' => $payload['updated_at'] ?? date('Y-m-d H:i:s'),
            'hub_last_payment_reference' => $payload['payment_reference'] ?? ($settings['hub_last_payment_reference'] ?? null),
            'last_status_changed_at' => $statusChanged ? date('Y-m-d H:i:s') : ($settings['last_status_changed_at'] ?? null),
            'last_locked_at' => $status === 'locked' ? date('Y-m-d H:i:s') : ($settings['last_locked_at'] ?? null),
            'last_paid_at' => $status === 'current' ? date('Y-m-d H:i:s') : ($settings['last_paid_at'] ?? null),
        ];
        $this->saveSettings($saveData);

        if ($statusChanged) {
            Database::execute(
                "INSERT INTO workspace_billing_status_history (previous_status, new_status, reason, metadata_json, created_by)
                 VALUES (?, ?, ?, ?, ?)",
                [$previousStatus, $status, $source, json_encode($payload), null]
            );
        }

        if ($status === 'current') {
            $this->settleIntentCharges((string) ($payload['payment_reference'] ?? ''));
        }

        return $this->buildCentralHubSummary();
    }

    private function settleIntentCharges(string $reference): void
    {
        if ($reference === '') {
            return;
        }

        $intent = Database::queryOne(
            "SELECT * FROM workspace_billing_payment_intents WHERE reference = ? LIMIT 1",
            [$reference]
        );
        if (!$intent) {
            return;
        }

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_billing_payment_intents
                 SET status = 'paid', paid_at = NOW(), updated_at = NOW()
                 WHERE id = ?",
                [(int) $intent['id']]
            );

            $items = Database::query(
                "SELECT charge_id FROM workspace_billing_payment_intent_items WHERE payment_intent_id = ?",
                [(int) $intent['id']]
            );
            foreach ($items as $item) {
                Database::execute(
                    "UPDATE workspace_billing_charges
                     SET status = 'paid', settled_at = NOW(), updated_at = NOW()
                     WHERE id = ?",
                    [(int) $item['charge_id']]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function assertCentralHubConfigured(array $settings): void
    {
        if (
            trim((string) ($settings['billing_hub_base_url'] ?? '')) === ''
            || trim((string) ($settings['billing_workspace_key'] ?? '')) === ''
            || trim((string) ($settings['billing_hub_signing_secret'] ?? '')) === ''
        ) {
            throw new \RuntimeException('Billing hub base URL, workspace key, and signing secret must be configured.');
        }
    }

    private function normalizeHubSyncPayload(array $payload): array
    {
        return [
            'workspace_key' => trim((string) ($payload['workspace_key'] ?? '')),
            'status' => $this->normalizeStatus((string) ($payload['status'] ?? 'current')),
            'amount_due' => round(max(0, (float) ($payload['amount_due'] ?? 0)), 2),
            'currency' => trim((string) ($payload['currency'] ?? 'KES')),
            'due_at' => $this->normalizeNullableDateTime($payload['due_at'] ?? null),
            'grace_expires_at' => $this->normalizeNullableDateTime($payload['grace_expires_at'] ?? null),
            'payment_reference' => trim((string) ($payload['payment_reference'] ?? '')),
            'updated_at' => $this->normalizeNullableDateTime($payload['updated_at'] ?? null) ?? date('Y-m-d H:i:s'),
        ];
    }

    private function verifyHubSyncSignature(string $rawPayload, ?string $signature): bool
    {
        $secret = trim((string) ($this->getSettings()['billing_hub_signing_secret'] ?? ''));
        if ($secret === '' || !is_string($signature) || trim($signature) === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawPayload, $secret), trim($signature));
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['current', 'payment_due', 'grace', 'locked'], true)
            ? $status
            : 'current';
    }

    private function isCentralHubMode(): bool
    {
        $settings = $this->getSettings();
        return (string) ($settings['billing_mode'] ?? 'central_hub') === 'central_hub'
            && trim((string) ($settings['billing_hub_base_url'] ?? '')) !== ''
            && trim((string) ($settings['billing_workspace_key'] ?? '')) !== ''
            && trim((string) ($settings['billing_hub_signing_secret'] ?? '')) !== '';
    }

    private function formatNullableDateTimeValue(mixed $value): ?string
    {
        $dateTime = $this->normalizeNullableDateTime($value);
        return $dateTime !== null ? date(DATE_ATOM, strtotime($dateTime)) : null;
    }

    private function normalizeDate(mixed $value): string
    {
        $string = trim((string) $value);
        if ($string === '') {
            return date('Y-m-d');
        }
        $timestamp = strtotime($string);
        return $timestamp ? date('Y-m-d', $timestamp) : date('Y-m-d');
    }

    private function normalizeNullableDate(mixed $value): ?string
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }
        $timestamp = strtotime($string);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    private function normalizeNullableDateTime(mixed $value): ?string
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        $timestamp = strtotime($string);
        return $timestamp ? date('Y-m-d H:i:s', $timestamp) : null;
    }

    private function calculatePeriodEnd(string $periodStart, string $intervalUnit, int $intervalCount): string
    {
        $next = $this->calculateNextChargeDate($periodStart, $intervalUnit, $intervalCount);
        return date('Y-m-d', strtotime($next . ' -1 day'));
    }

    private function calculateNextChargeDate(string $date, string $intervalUnit, int $intervalCount): string
    {
        $intervalCount = max(1, $intervalCount);
        return match ($intervalUnit) {
            'weekly' => date('Y-m-d', strtotime($date . ' +' . $intervalCount . ' week')),
            'quarterly' => date('Y-m-d', strtotime($date . ' +' . ($intervalCount * 3) . ' month')),
            'yearly' => date('Y-m-d', strtotime($date . ' +' . $intervalCount . ' year')),
            default => date('Y-m-d', strtotime($date . ' +' . $intervalCount . ' month')),
        };
    }

    private function defaultSummary(): array
    {
        return [
            'status' => 'current',
            'amount_due' => 0.0,
            'currency' => 'KES',
            'due_date' => null,
            'grace_expires_at' => null,
            'charge_count' => 0,
            'charges' => [],
            'payment_url' => publicUrl('billing_start_payment.php'),
            'settings' => $this->getSettings(),
            'is_trial_active' => false,
            'trial_starts_at' => null,
            'trial_ends_at' => null,
            'trial_notes' => null,
        ];
    }

    private function fallbackBillingEmail(): string
    {
        $billingEmail = trim((string) ($_ENV['BILLING_EMAIL'] ?? ''));
        if ($billingEmail !== '') {
            return $billingEmail;
        }

        try {
            return (new EmailIntegrationService())->getPreferredMainFromEmail('');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function tablesAvailable(): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'workspace_billing_settings'"
            );
            return ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
