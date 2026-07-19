<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceWalletService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceWalletServiceTest extends DatabaseTestCase
{
    public function testCreditReserveAndSettleAdjustWalletBalances(): void
    {
        $workspaceId = (int) ((new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Wallet Workspace',
            'workspace_slug' => 'wallet-workspace',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ])['workspace_id'] ?? 0);

        $service = new WorkspaceWalletService();
        $service->creditTokens($workspaceId, 1000, 'manual_adjustment', 'credit-1');
        $reservation = $service->reserveTokens($workspaceId, 300, 'ai_request', 'request-1');
        $settlement = $service->settleReservation($workspaceId, 'ai_request', 'request-1', 180);
        $wallet = $service->getSummary($workspaceId);
        $ledgerCount = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_wallet_ledger WHERE workspace_id = ?",
            [$workspaceId]
        );

        $this->assertTrue($reservation['ok']);
        $this->assertSame(180, (int) ($settlement['debited_tokens'] ?? 0));
        $this->assertSame(50820, (int) ($wallet['credit_balance'] ?? $wallet['token_balance'] ?? 0));
        $this->assertSame(0, (int) ($wallet['reserved_tokens'] ?? 0));
        $this->assertGreaterThanOrEqual(3, (int) ($ledgerCount['c'] ?? 0));
    }

    public function testDebitTokensFailsWhenRequestedAmountExceedsAvailableBalance(): void
    {
        $workspaceId = (int) ((new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Wallet Debit Workspace',
            'workspace_slug' => 'wallet-debit-workspace',
            'first_name' => 'Ada',
            'last_name' => 'Byron',
            'email' => 'ada.wallet@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ])['workspace_id'] ?? 0);

        $service = new WorkspaceWalletService();
        $service->creditTokens($workspaceId, 120, 'manual_adjustment', 'credit-2');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not enough available AI Credits');

        $service->debitTokens($workspaceId, 60000, 'manual_adjustment', 'debit-too-much');
    }

    public function testDebitTokensCreatesSingleLedgerEntryAndReducesBalance(): void
    {
        $workspaceId = (int) ((new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Wallet Debit Success Workspace',
            'workspace_slug' => 'wallet-debit-success-workspace',
            'first_name' => 'Katherine',
            'last_name' => 'Johnson',
            'email' => 'katherine.wallet@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ])['workspace_id'] ?? 0);

        $service = new WorkspaceWalletService();
        $service->creditTokens($workspaceId, 500, 'manual_adjustment', 'credit-3');
        $result = $service->debitTokens($workspaceId, 150, 'manual_adjustment', 'debit-1', null, ['source' => 'test'], 'Manual correction');
        $wallet = $service->getSummary($workspaceId);
        $ledger = Database::queryOne(
            "SELECT entry_type, token_delta, description
             FROM workspace_wallet_ledger
             WHERE id = ?",
            [(int) ($result['ledger_entry_id'] ?? 0)]
        );

        $this->assertSame(50350, (int) ($wallet['credit_balance'] ?? $wallet['token_balance'] ?? 0));
        $this->assertSame(50350, (int) ($result['credit_balance'] ?? $result['token_balance'] ?? 0));
        $this->assertSame('debit', (string) ($ledger['entry_type'] ?? ''));
        $this->assertSame(-150, (int) ($ledger['token_delta'] ?? 0));
        $this->assertSame('Manual correction', (string) ($ledger['description'] ?? ''));
    }
}
