<?php

require_once __DIR__ . '/../../public/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Services\FinanceStatementService;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Services\WorkspaceContext;
use CRM\Session;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

Authorization::requirePermission('finance.view', true);

$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$user = Auth::user();
$userId = (int) (($user['id'] ?? 0));
(new WorkspaceFinanceGateService())->assertRuntimeReady($workspaceId, $user, true);

try {
    $statements = (new FinanceStatementService())->generate(
        $workspaceId,
        $userId,
        $_GET['date_from'] ?? null,
        $_GET['date_to'] ?? null
    );
    $tool = (string) ($_GET['tool'] ?? 'review_report_quality');
    $cards = (array) ($statements['ai_help_cards'] ?? []);
    $quality = (array) ($statements['report_quality'] ?? []);
    $summary = (array) ($statements['summary'] ?? []);

    $payload = [
        'tool' => $tool,
        'cards' => $cards,
        'warnings' => (array) ($quality['warnings'] ?? []),
        'suggestions' => [],
    ];

    if ($tool === 'categorize_uncategorized_expenses') {
        $payload['suggestions'][] = [
            'issue' => 'Categorize costs',
            'why' => 'Uncategorized expenses weaken P&L and cash-flow confidence.',
            'action' => 'Open Expenses and assign each blank category to operations, marketing, payroll, inventory, taxes, software, or other.',
        ];
    } elseif ($tool === 'explain_runway_risk') {
        $payload['suggestions'][] = [
            'issue' => 'Runway',
            'why' => 'Runway uses cash reserve divided by current monthly burn.',
            'action' => 'Update cash reserve and recurring costs before making a spend decision.',
            'value' => $summary['runway_months'] ?? null,
        ];
    } elseif ($tool === 'explain_pnl_variance') {
        $payload['suggestions'][] = [
            'issue' => 'P&L movement',
            'why' => 'Net income is paid revenue minus paid costs classified by category mapping.',
            'action' => 'Check COGS/delivery-cost categories first, then operating expenses.',
        ];
    } else {
        $payload['suggestions'][] = [
            'issue' => 'Report quality',
            'why' => 'AI guidance is strongest when invoices, expense categories, budgets, and recurring costs are current.',
            'action' => 'Fix warnings, then rerun the finance review.',
            'confidence' => $quality['confidence'] ?? 'unknown',
        ];
    }

    echo json_encode($payload);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load accounting help']);
}
