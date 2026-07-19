<?php
/**
 * Create Workflow Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\AutomationEngine;
use CRM\Modules\WorkflowTemplates;
use CRM\Security;
use CRM\Services\WorkflowMigrationService;
use CRM\Services\WorkspaceScopeService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::can('workflows.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$automationEngine = new AutomationEngine();
$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
$error = null;
$success = null;
$workflowId = isset($_GET['id']) ? (int) ($_GET['id'] ?? 0) : 0;
$editingWorkflow = null;

if ($workflowId > 0) {
    $editingWorkflow = $automationEngine->getWorkflow($workflowId);
    if (!$editingWorkflow) {
        header('Location: workflows.php');
        exit;
    }
    try {
        (new WorkflowMigrationService())->migrateWorkflow($workflowId);
        $editingWorkflow = $automationEngine->getWorkflow($workflowId);
    } catch (\Exception $e) {
        error_log('Workflow migration on edit failed: ' . $e->getMessage());
    }
}

$templateId = isset($_GET['template_id']) ? (int) $_GET['template_id'] : null;
$template = null;
if ($templateId) {
    try {
        $templatesModule = new WorkflowTemplates();
        $template = $templatesModule->getTemplate($templateId, (int) ($user['id'] ?? 0));
    } catch (\Exception $e) {
        $template = null;
        error_log('Error loading template: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $name = $_POST['name'] ?? '';
            $triggerType = $_POST['trigger_type'] ?? '';
            $isActive = isset($_POST['is_active']) ? 1 : 0;

            if (empty($name) || empty($triggerType)) {
                $error = 'Workflow name and trigger are required.';
            } else {
                $trigger = ['type' => $triggerType];
                if ($triggerType === 'no_activity_for_days') {
                    $trigger['days'] = (int) ($_POST['trigger_days'] ?? 7);
                } elseif ($triggerType === 'stage_changed') {
                    $trigger['from_stage'] = $_POST['from_stage'] ?? '';
                    $trigger['to_stage'] = $_POST['to_stage'] ?? '';
                }

                $actions = [];
                if (isset($_POST['actions']) && is_array($_POST['actions'])) {
                    foreach ($_POST['actions'] as $actionData) {
                        if (empty($actionData['type'])) {
                            continue;
                        }

                        $action = ['type' => $actionData['type']];
                        switch ($actionData['type']) {
                            case 'send_email':
                                $action['template_id'] = (int) ($actionData['template_id'] ?? 0);
                                $action['subject'] = $actionData['subject'] ?? '';
                                $action['body'] = $actionData['body'] ?? '';
                                break;
                            case 'send_whatsapp':
                                $action['message'] = $actionData['message'] ?? '';
                                break;
                            case 'change_stage':
                                $action['stage'] = $actionData['stage'] ?? '';
                                break;
                            case 'assign_to_user':
                                $action['user_id'] = (int) ($actionData['user_id'] ?? 0);
                                break;
                            case 'wait_for_days':
                                $action['days'] = (int) ($actionData['days'] ?? 1);
                                break;
                        }

                        $actions[] = $action;
                    }
                }

                if (empty($actions)) {
                    $error = 'At least one action is required.';
                } else {
                    $conditions = [];
                    if (isset($_POST['conditions']) && is_array($_POST['conditions'])) {
                        $conditionList = [];
                        foreach ($_POST['conditions'] as $conditionData) {
                            if (!empty($conditionData['field']) && !empty($conditionData['operator'])) {
                                $conditionList[] = [
                                    'field' => $conditionData['field'],
                                    'operator' => $conditionData['operator'],
                                    'value' => $conditionData['value'] ?? null,
                                ];
                            }
                        }
                        if (!empty($conditionList)) {
                            if (count($conditionList) > 1) {
                                $conditions = [
                                    'operator' => 'AND',
                                    'conditions' => $conditionList,
                                ];
                            } else {
                                $conditions = $conditionList[0];
                            }
                        }
                    }

                    if ($templateId && $template) {
                        $trigger = $template['trigger_config'];
                        $conditions = $template['conditions'];
                        $actions = $template['actions'];
                    }

                    $workflowId = $automationEngine->createWorkflow(
                        $name,
                        $trigger,
                        $conditions,
                        $actions
                    );

                    if (!$isActive) {
                        Database::execute(
                            'UPDATE workflows SET is_active = 0 WHERE workspace_id = ? AND id = ?',
                            [$workspaceId, $workflowId]
                        );
                        $triggerService = new \CRM\Services\WorkflowTriggerService();
                        $triggerService->unsubscribeWorkflow($workflowId);
                    }

                    header('Location: workflows.php?success=created');
                    exit;
                }
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$emailTemplates = Database::query(
    "SELECT et.id, et.name, et.slug, et.purpose, et.category, et.match_metadata_json
     FROM email_templates et
     LEFT JOIN smart_template_sets sts ON sts.id = et.smart_template_set_id
     WHERE et.workspace_id = ?
       AND COALESCE(et.is_library, 0) = 0
       AND et.is_active = 1
       AND (et.created_by = ? OR COALESCE(et.is_ai_generated, 0) = 1)
       AND (
            COALESCE(et.is_ai_generated, 0) = 0
            OR et.smart_template_set_id IS NULL
            OR sts.status = 'active'
       )
     ORDER BY COALESCE(et.is_ai_generated, 0) DESC, et.name ASC",
    [$workspaceId, (int) ($user['id'] ?? 0)]
);
$emailTemplateFacets = ['purposes' => [], 'tones' => [], 'lifecycle_stages' => [], 'audiences' => [], 'workflow_intents' => []];
foreach ($emailTemplates as &$emailTemplate) {
    $metadata = json_decode((string) ($emailTemplate['match_metadata_json'] ?? ''), true);
    $metadata = is_array($metadata) ? $metadata : [];
    $emailTemplate['match_metadata'] = $metadata;
    foreach (['purposes', 'tones', 'lifecycle_stages', 'audiences', 'workflow_intents'] as $facetKey) {
        foreach ((array) ($metadata[$facetKey] ?? []) as $facetValue) {
            $facetValue = trim((string) $facetValue);
            if ($facetValue !== '') {
                $emailTemplateFacets[$facetKey][] = $facetValue;
            }
        }
    }
    if (!empty($emailTemplate['purpose'])) {
        $emailTemplateFacets['purposes'][] = (string) $emailTemplate['purpose'];
    }
}
unset($emailTemplate);
foreach ($emailTemplateFacets as $facetKey => $values) {
    $emailTemplateFacets[$facetKey] = array_values(array_unique($values));
    sort($emailTemplateFacets[$facetKey]);
}

$users = Database::query(
    "SELECT u.id, u.email
     FROM users u
     JOIN workspace_memberships wm ON wm.user_id = u.id
     WHERE wm.workspace_id = ?
       AND wm.membership_status = 'active'
     ORDER BY u.email ASC",
    [$workspaceId]
);

$isEditing = $editingWorkflow !== null;
$initialWorkflowName = (string) ($_POST['name'] ?? ($editingWorkflow['name'] ?? ($template['name'] ?? '')));
$initialIsActive = !isset($_POST['is_active']) ? (bool) ($editingWorkflow['is_active'] ?? 1) : !empty($_POST['is_active']);
$initialWorkflowMode = (string) ($editingWorkflow['workflow_mode'] ?? 'mixed');
$savedBanner = isset($_GET['saved']) && $_GET['saved'] === '1';
$templateDescription = (string) ($template['description'] ?? '');
$initialLegacyState = [
    'name' => (string) ($_POST['name'] ?? ''),
    'trigger_type' => (string) ($_POST['trigger_type'] ?? ''),
    'trigger_days' => (int) ($_POST['trigger_days'] ?? 7),
    'from_stage' => (string) ($_POST['from_stage'] ?? ''),
    'to_stage' => (string) ($_POST['to_stage'] ?? ''),
    'is_active' => isset($_POST['is_active']) ? !empty($_POST['is_active']) : $initialIsActive,
    'actions' => array_values($_POST['actions'] ?? []),
    'conditions' => array_values($_POST['conditions'] ?? []),
];
$templateVisualState = $template ? [
    'name' => (string) ($template['name'] ?? ''),
    'trigger' => $template['trigger_config'] ?? [],
    'conditions' => $template['conditions'] ?? [],
    'actions' => $template['actions'] ?? [],
    'description' => $templateDescription,
] : null;
$pageTitle = ($isEditing ? 'Edit Workflow' : 'Create Workflow') . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/workflow-builder.css?v=workflow-editor-20260716d">
<style>
  .workflow-create-page {
    background: #f5f5f5;
  }

  body:has(.workflow-create-page) .page-content {
    padding-top: 0.75rem;
  }

  body:has(.workflow-create-page) .page-content > .container {
    max-width: none;
    width: 100%;
    padding-left: 0;
    padding-right: 0;
  }

  .page-premium.workflow-create-page {
    padding: 0.75rem clamp(0.5rem, 0.75vw, 0.9rem);
  }

  .page-premium.workflow-create-page > .container {
    max-width: none;
    width: 100%;
  }

  .workflow-create-header {
    align-items: flex-start;
    padding: 0.95rem 1.1rem;
    border-radius: 20px;
    border: 1px solid rgba(226, 232, 240, 0.95);
    box-shadow: 0 16px 36px rgba(15, 23, 42, 0.06);
    background:
      radial-gradient(circle at top left, rgba(59, 130, 246, 0.1), transparent 36%),
      radial-gradient(circle at 88% 18%, rgba(14, 165, 233, 0.1), transparent 30%),
      linear-gradient(135deg, #ffffff, #f8fbff 55%, #f8fafc);
  }

  .workflow-create-header h1 {
    font-size: clamp(1.45rem, 2.4vw, 2rem);
    letter-spacing: -0.05em;
    margin-bottom: 0.2rem;
  }

  .workflow-create-header p {
    max-width: 42ch;
    font-size: 0.8rem;
    line-height: 1.35;
    color: #64748b;
  }

  .workflow-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.32rem 0.64rem;
    border-radius: 999px;
    background: rgba(37, 99, 235, 0.08);
    color: #1d4ed8;
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }

  .workflow-card {
    background: rgba(255, 255, 255, 0.96);
    border-radius: 20px;
    border: 1px solid rgba(226, 232, 240, 0.95);
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.06);
  }

  .workflow-inline-banner {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.7rem;
    align-items: center;
    padding: 0.6rem 0.85rem;
    margin-bottom: 0.75rem;
    border-radius: 16px;
  }

  .workflow-inline-banner[hidden] {
    display: none !important;
  }

  .workflow-inline-banner-icon {
    width: 1.7rem;
    height: 1.7rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 0.85rem;
    font-weight: 700;
  }

  .workflow-inline-banner-content h3 {
    margin: 0 0 0.12rem;
    font-size: 0.86rem;
    color: inherit;
  }

  .workflow-inline-banner-content p {
    margin: 0;
    font-size: 0.8rem;
    line-height: 1.35;
    color: inherit;
  }

  .workflow-inline-banner-actions {
    margin-top: 0.35rem;
    display: flex;
    gap: 0.65rem;
    flex-wrap: wrap;
  }

  .workflow-inline-banner.is-success {
    background: #ecfdf3;
    border: 1px solid #86efac;
    color: #166534;
  }

  .workflow-inline-banner.is-success .workflow-inline-banner-icon {
    background: rgba(22, 101, 52, 0.12);
  }

  .workflow-inline-banner.is-warning {
    background: #fff7ed;
    border: 1px solid #fdba74;
    color: #9a3412;
  }

  .workflow-inline-banner.is-warning .workflow-inline-banner-icon {
    background: rgba(154, 52, 18, 0.12);
  }

  .workflow-inline-banner.is-danger {
    background: #fef2f2;
    border: 1px solid #fca5a5;
    color: #991b1b;
  }

  .workflow-inline-banner.is-danger .workflow-inline-banner-icon {
    background: rgba(153, 27, 27, 0.12);
  }

  .workflow-inline-banner.is-info {
    background: #eff6ff;
    border: 1px solid #93c5fd;
    color: #1d4ed8;
  }

  .workflow-inline-banner.is-info .workflow-inline-banner-icon {
    background: rgba(29, 78, 216, 0.12);
  }

  .workflow-template-banner,
  .workflow-recommend-strip,
  .workflow-setup-card,
  .workflow-palette-rail,
  .workflow-builder-card,
  .workflow-save-rail,
  .workflow-advanced-panel {
    margin-bottom: 0.95rem;
  }

  .workflow-template-banner {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.9rem;
    padding: 0.78rem 0.95rem;
    background:
      linear-gradient(135deg, rgba(37, 99, 235, 0.05), rgba(56, 189, 248, 0.03)),
      rgba(255, 255, 255, 0.98);
  }

  .workflow-template-banner h3,
  .workflow-recommend-strip h2,
  .workflow-builder-card h2,
  .workflow-save-rail h2,
  .workflow-advanced-panel h2 {
    margin: 0;
    color: #0f172a;
    letter-spacing: -0.03em;
  }

  .workflow-template-banner p,
  .workflow-recommend-strip p,
  .workflow-save-rail p,
  .workflow-advanced-panel p,
  .workflow-setup-subtitle {
    margin: 0;
    color: #475569;
    line-height: 1.5;
  }

  .workflow-template-banner-actions,
  .workflow-recommend-strip-actions,
  .workflow-builder-toolbar,
  .workflow-advanced-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
    align-items: center;
  }

  .workflow-recommend-strip {
    padding: 0.8rem 0.9rem;
    background:
      linear-gradient(180deg, rgba(37, 99, 235, 0.04), rgba(255, 255, 255, 0.97) 34%),
      linear-gradient(135deg, #ffffff, #f9fbff);
  }

  .workflow-recommend-strip[hidden] {
    display: none !important;
  }

  .workflow-recommend-strip-header {
    display: flex;
    justify-content: space-between;
    gap: 0.6rem;
    align-items: center;
    margin-bottom: 0.45rem;
  }

  .workflow-recommend-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
  }

  .workflow-recommend-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.52rem 0.72rem;
    border-radius: 999px;
    text-decoration: none;
    border: 1px solid rgba(203, 213, 225, 0.9);
    background: rgba(255, 255, 255, 0.98);
    box-shadow: 0 6px 18px rgba(15, 23, 42, 0.04);
    transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
    color: #334155;
    font-size: 0.78rem;
    font-weight: 600;
  }

  .workflow-recommend-chip:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 24px rgba(59, 130, 246, 0.1);
    border-color: rgba(96, 165, 250, 0.45);
    background: #ffffff;
    text-decoration: none;
  }

  .workflow-recommend-chip i {
    color: #2563eb;
  }

  .workflow-setup-card {
    padding: 0.85rem 0.95rem;
    border-radius: 18px;
    background:
      linear-gradient(180deg, rgba(248, 250, 252, 0.92), rgba(255, 255, 255, 0.98));
  }

  .workflow-setup-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(220px, auto);
    gap: 0.85rem;
    align-items: center;
  }

  .workflow-setup-advanced {
    margin-top: 0.72rem;
    border-top: 1px solid rgba(226, 232, 240, 0.92);
    padding-top: 0.65rem;
  }

  .workflow-setup-advanced summary {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    color: #334155;
    cursor: pointer;
    font-size: 0.78rem;
    font-weight: 700;
    list-style: none;
  }

  .workflow-setup-advanced summary::-webkit-details-marker {
    display: none;
  }

  .workflow-setup-advanced summary i {
    color: #64748b;
    transition: transform 0.2s ease;
  }

  .workflow-setup-advanced[open] summary i {
    transform: rotate(90deg);
  }

  .workflow-setup-advanced-body {
    max-width: 420px;
    padding-top: 0.65rem;
  }

  .workflow-field-help {
    margin: 0.14rem 0 0;
    color: #64748b;
    font-size: 0.74rem;
    line-height: 1.45;
  }

  .workflow-field {
    display: flex;
    flex-direction: column;
    gap: 0.28rem;
  }

  .workflow-field label {
    color: #0f172a;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
  }

  .workflow-field input,
  .workflow-field select,
  .workflow-field textarea {
    width: 100%;
    border: 1px solid rgba(203, 213, 225, 0.95);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.98);
    color: #0f172a;
    padding: 0.7rem 0.86rem;
    font-size: 0.88rem;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
  }

  .workflow-field textarea {
    min-height: 120px;
    resize: vertical;
  }

  .workflow-field input:focus,
  .workflow-field select:focus,
  .workflow-field textarea:focus {
    outline: none;
    border-color: rgba(96, 165, 250, 0.85);
    box-shadow: 0 0 0 4px rgba(96, 165, 250, 0.12);
  }

  .workflow-checkbox-row {
    display: inline-flex;
    align-items: center;
    gap: 0.7rem;
    justify-content: center;
    min-height: 44px;
    padding: 0.65rem 0.85rem;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.98);
    border: 1px solid rgba(203, 213, 225, 0.95);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.75);
    color: #334155;
    font-weight: 600;
    white-space: nowrap;
  }

  .workflow-checkbox-row input {
    width: 16px;
    height: 16px;
  }

  .workflow-meta-chip {
    padding: 0.68rem 0.78rem;
    border-radius: 16px;
    border: 1px solid rgba(226, 232, 240, 0.95);
    background: rgba(255, 255, 255, 0.94);
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    min-width: 0;
  }

  .workflow-meta-chip-label {
    display: block;
    margin-bottom: 0.15rem;
    color: #64748b;
    font-size: 0.64rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
  }

  .workflow-meta-chip strong {
    color: #0f172a;
    font-size: 0.84rem;
    letter-spacing: -0.02em;
  }

  .workflow-meta-chip p {
    margin: 0.18rem 0 0;
    color: #64748b;
    font-size: 0.72rem;
    line-height: 1.45;
  }

  .workflow-workbench-grid {
    display: grid;
    grid-template-columns: minmax(190px, 230px) minmax(0, 1fr) minmax(230px, 270px);
    gap: 0.65rem;
    align-items: start;
  }

  .workflow-palette-rail {
    position: sticky;
    top: 1rem;
    max-height: calc(100vh - 2rem);
    overflow: hidden;
    padding: 0.62rem;
    min-width: 0;
  }

  .workflow-palette-rail-header {
    padding: 0.1rem 0.2rem 0.62rem;
    border-bottom: 1px solid rgba(226, 232, 240, 0.92);
  }

  .workflow-palette-rail-header strong {
    display: block;
    color: #0f172a;
    font-size: 0.78rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  .workflow-palette-rail-header p {
    margin: 0.24rem 0 0;
    color: #64748b;
    font-size: 0.72rem;
    line-height: 1.45;
  }

  .workflow-palette-host {
    min-height: 0;
  }

  .workflow-palette-rail #workflow-palette-container {
    scrollbar-width: thin;
  }

  .workflow-palette-rail #workflow-palette {
    min-width: 0;
  }

  .workflow-builder-card {
    padding: 0.62rem;
    min-width: 0;
  }

  .workflow-builder-card-header {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    align-items: flex-start;
    margin-bottom: 0.7rem;
  }

  .workflow-builder-card-header strong {
    color: #0f172a;
    font-size: 0.78rem;
    letter-spacing: 0.02em;
    text-transform: uppercase;
  }

  .workflow-save-rail h2 {
    font-size: 0.98rem;
    letter-spacing: -0.03em;
  }

  .workflow-builder-toolbar {
    gap: 0.5rem;
  }

  .workflow-builder-toolbar-main,
  .workflow-builder-toolbar-extra {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    align-items: center;
  }

  .workflow-toolbar-menu {
    position: relative;
  }

  .workflow-toolbar-menu summary {
    list-style: none;
  }

  .workflow-toolbar-menu summary::-webkit-details-marker {
    display: none;
  }

  .workflow-toolbar-menu[open] .workflow-toolbar-menu-panel {
    display: grid;
  }

  .workflow-toolbar-menu-panel {
    display: none;
    position: absolute;
    top: calc(100% + 0.45rem);
    right: 0;
    min-width: 220px;
    padding: 0.55rem;
    background: rgba(255, 255, 255, 0.98);
    border: 1px solid rgba(226, 232, 240, 0.98);
    border-radius: 16px;
    box-shadow: 0 18px 38px rgba(15, 23, 42, 0.12);
    backdrop-filter: blur(8px);
    gap: 0.45rem;
    z-index: 40;
  }

  .workflow-toolbar-menu-panel .btn-premium-secondary {
    justify-content: flex-start;
    width: 100%;
  }

  .workflow-builder-shell {
    border-radius: 16px;
    border: 1px solid rgba(226, 232, 240, 0.95);
    background:
      radial-gradient(circle at top left, rgba(37, 99, 235, 0.04), transparent 32%),
      linear-gradient(180deg, rgba(248, 250, 252, 0.9), rgba(255, 255, 255, 0.98) 18%);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.8);
    padding: 0.42rem;
  }

  .workflow-builder-canvas {
    min-height: 820px;
    border-radius: 16px;
    overflow: hidden;
    background: #fff;
  }

  .workflow-validation-summary {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.9rem;
    align-items: start;
    margin: 0 0 0.75rem;
    padding: 0.85rem 0.95rem;
    border-radius: 16px;
    border: 1px solid transparent;
    background: #f8fafc;
  }

  .workflow-validation-summary[hidden] {
    display: none !important;
  }

  .workflow-validation-summary-icon {
    width: 2.2rem;
    height: 2.2rem;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
  }

  .workflow-validation-summary h3 {
    margin: 0 0 0.3rem;
    color: inherit;
    font-size: 0.98rem;
  }

  .workflow-validation-summary p,
  .workflow-validation-summary ul {
    margin: 0;
    color: inherit;
    line-height: 1.6;
  }

  .workflow-validation-summary ul {
    padding-left: 1rem;
    margin-top: 0.4rem;
  }

  .workflow-validation-summary.is-success {
    background: #ecfdf3;
    border-color: #86efac;
    color: #166534;
  }

  .workflow-validation-summary.is-success .workflow-validation-summary-icon {
    background: rgba(22, 101, 52, 0.12);
  }

  .workflow-validation-summary.is-warning {
    background: #fff7ed;
    border-color: #fdba74;
    color: #9a3412;
  }

  .workflow-validation-summary.is-warning .workflow-validation-summary-icon {
    background: rgba(154, 52, 18, 0.12);
  }

  .workflow-validation-summary.is-danger {
    background: #fef2f2;
    border-color: #fca5a5;
    color: #991b1b;
  }

  .workflow-validation-summary.is-danger .workflow-validation-summary-icon {
    background: rgba(153, 27, 27, 0.12);
  }

  .workflow-clear-confirm {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    padding: 0.88rem 0.98rem;
    margin-bottom: 0.75rem;
    border-radius: 16px;
    background: #fff7ed;
    border: 1px solid #fdba74;
    color: #9a3412;
  }

  .workflow-clear-confirm[hidden] {
    display: none !important;
  }

  .workflow-clear-confirm p {
    margin: 0;
    line-height: 1.55;
  }

  .workflow-save-rail {
    position: sticky;
    top: 1.5rem;
    padding: 0.72rem;
    min-width: 0;
    background:
      linear-gradient(180deg, rgba(37, 99, 235, 0.04), rgba(255, 255, 255, 0.98) 24%),
      rgba(255, 255, 255, 0.98);
  }

  .workflow-save-rail-content {
    display: grid;
    gap: 0.7rem;
  }

  .workflow-save-status {
    padding: 0.76rem 0.84rem;
    border-radius: 16px;
    border: 1px solid rgba(191, 219, 254, 0.95);
    background: linear-gradient(180deg, rgba(239, 246, 255, 0.95), rgba(248, 250, 252, 0.95));
  }

  .workflow-save-status-label {
    display: block;
    margin-bottom: 0.4rem;
    color: #64748b;
    font-size: 0.66rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
  }

  .workflow-save-status strong {
    display: block;
    color: #0f172a;
    font-size: 0.82rem;
  }

  .workflow-save-status p {
    margin-top: 0.25rem;
    font-size: 0.72rem;
    color: #64748b;
    line-height: 1.45;
  }

  .workflow-save-status.is-success {
    background: #ecfdf3;
    border-color: #86efac;
  }

  .workflow-save-status.is-warning {
    background: #fff7ed;
    border-color: #fdba74;
  }

  .workflow-save-status.is-info {
    background: #eff6ff;
    border-color: #93c5fd;
  }

  .workflow-save-status.is-danger {
    background: #fef2f2;
    border-color: #fca5a5;
  }

  .workflow-rail-note {
    padding: 0.72rem 0.8rem;
    border-radius: 16px;
    border: 1px solid rgba(226, 232, 240, 0.98);
    background: rgba(248, 250, 252, 0.92);
  }

  .workflow-save-meta {
    display: grid;
    gap: 0.6rem;
  }

  .workflow-save-meta-item {
    padding: 0.72rem 0.8rem;
    border-radius: 16px;
    background: rgba(248, 250, 252, 0.92);
    border: 1px solid rgba(226, 232, 240, 0.98);
  }

  .workflow-save-meta-item span {
    display: block;
    margin-bottom: 0.25rem;
    color: #64748b;
    font-size: 0.64rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
  }

  .workflow-save-meta-item strong {
    color: #0f172a;
    font-size: 0.84rem;
  }

  .workflow-save-meta-item p {
    margin-top: 0.2rem;
    font-size: 0.72rem;
    color: #64748b;
    line-height: 1.45;
  }

  .workflow-rail-note strong {
    display: block;
    color: #0f172a;
    margin-bottom: 0.4rem;
  }

  .workflow-advanced-panel {
    overflow: hidden;
  }

  .workflow-advanced-panel summary {
    list-style: none;
    cursor: pointer;
    padding: 1.05rem 1.2rem;
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: center;
    color: #0f172a;
    font-weight: 700;
  }

  .workflow-advanced-panel summary::-webkit-details-marker {
    display: none;
  }

  .workflow-advanced-panel[open] summary {
    border-bottom: 1px solid rgba(148, 163, 184, 0.14);
  }

  .workflow-advanced-panel-body {
    padding: 1.1rem 1.2rem 1.25rem;
  }

  .workflow-advanced-panel-head {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    align-items: flex-start;
    margin-bottom: 1rem;
  }

  .workflow-compat-form {
    display: grid;
    gap: 1.2rem;
  }

  .workflow-compat-card {
    padding: 0.95rem;
    border-radius: 16px;
    background: rgba(248, 250, 252, 0.92);
    border: 1px solid rgba(226, 232, 240, 0.95);
  }

  .workflow-compat-card-header {
    display: flex;
    justify-content: space-between;
    gap: 0.75rem;
    align-items: center;
    margin-bottom: 0.9rem;
  }

  .workflow-compat-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
  }

  .workflow-conditions-list,
  .workflow-actions-list {
    display: grid;
    gap: 0.9rem;
  }

  .workflow-condition-row {
    display: grid;
    grid-template-columns: 1.2fr 1fr 1fr auto;
    gap: 0.75rem;
    align-items: start;
  }

  .workflow-help-text {
    color: #64748b;
    font-size: 0.8rem;
    line-height: 1.5;
  }

  .workflow-text-link {
    color: #2563eb;
    font-weight: 600;
    text-decoration: none;
  }

  .workflow-text-link:hover {
    text-decoration: underline;
  }

  .workflow-stack-sm {
    display: grid;
    gap: 0.75rem;
  }

  .workflow-btn-group {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
  }

  @media (max-width: 1200px) {
    .workflow-workbench-grid {
      grid-template-columns: 1fr;
    }

    .workflow-palette-rail {
      position: static;
      max-height: none;
      overflow: visible;
    }

    .workflow-save-rail {
      position: static;
    }
  }

  @media (max-width: 960px) {
    .workflow-template-banner,
    .workflow-recommend-strip-header,
    .workflow-builder-card-header,
    .workflow-advanced-panel-head,
    .workflow-clear-confirm,
    .page-header {
      grid-template-columns: 1fr;
      display: grid;
    }

    .workflow-setup-grid,
    .workflow-compat-grid,
    .workflow-condition-row {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 1280px) {
    .workflow-setup-grid {
      grid-template-columns: minmax(0, 1fr) minmax(220px, auto);
    }
  }

  @media (max-width: 760px) {
    .workflow-setup-grid {
      grid-template-columns: 1fr;
    }

    .workflow-checkbox-row {
      justify-content: flex-start;
      width: 100%;
    }
  }

  @media (max-width: 640px) {
    .page-premium.workflow-create-page {
      padding: 0.75rem;
    }

    .page-header {
      padding: 1.35rem;
    }

    .workflow-card,
    .workflow-save-rail,
    .workflow-builder-card,
    .workflow-setup-card,
    .workflow-advanced-panel-body {
      border-radius: 18px;
    }

    .workflow-builder-canvas {
      min-height: 560px;
    }
  }

  /* Editor-first workflow builder */
  :root {
    --workflow-blue: #2563eb;
    --workflow-blue-dark: #1d4ed8;
    --workflow-ink: #0f172a;
    --workflow-muted: #64748b;
    --workflow-border: #dbe3ee;
    --workflow-surface: #ffffff;
    --workflow-subtle: #f8fafc;
    --workflow-grid: rgba(148, 163, 184, 0.22);
    --workflow-radius: 10px;
  }

  .sr-only {
    position: absolute !important;
    width: 1px !important;
    height: 1px !important;
    padding: 0 !important;
    margin: -1px !important;
    overflow: hidden !important;
    clip: rect(0, 0, 0, 0) !important;
    white-space: nowrap !important;
    border: 0 !important;
  }

  .workflow-create-page {
    background: #fff;
    color: var(--workflow-ink);
    overflow-x: hidden;
  }

  .page-premium.workflow-create-page {
    padding: 0.6rem clamp(0.55rem, 1vw, 1rem) 1.25rem;
  }

  .workflow-editor-commandbar {
    position: sticky;
    top: 0;
    z-index: 45;
    display: grid;
    grid-template-columns: minmax(250px, auto) minmax(280px, 1fr) auto;
    gap: 1.25rem;
    align-items: center;
    min-height: 68px;
    margin-bottom: 0.55rem;
    padding: 0.65rem 0.8rem;
    border: 1px solid var(--workflow-border);
    border-radius: var(--workflow-radius);
    background: rgba(255, 255, 255, 0.97);
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
    backdrop-filter: blur(12px);
  }

  .workflow-editor-title {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
  }

  .workflow-editor-nav,
  .workflow-command-actions,
  .workflow-command-name,
  .workflow-active-toggle {
    display: flex;
    align-items: center;
  }

  .workflow-editor-nav {
    gap: 0.9rem;
    min-width: 0;
  }

  .workflow-command-link {
    display: inline-flex;
    align-items: center;
    gap: 0.48rem;
    color: var(--workflow-blue);
    font-size: 0.82rem;
    font-weight: 600;
    text-decoration: none;
    white-space: nowrap;
  }

  .workflow-command-link:hover {
    color: var(--workflow-blue-dark);
    text-decoration: none;
  }

  .workflow-command-divider {
    width: 1px;
    height: 24px;
    background: var(--workflow-border);
  }

  .workflow-command-name {
    gap: 0.7rem;
    min-width: 0;
  }

  .workflow-command-name label,
  .workflow-utility-field > span {
    color: #475569;
    font-size: 0.76rem;
    font-weight: 600;
    white-space: nowrap;
  }

  .workflow-command-name input,
  .workflow-utility-field select {
    min-width: 0;
    width: 100%;
    height: 40px;
    border: 1px solid #cbd5e1;
    border-radius: 7px;
    background: #fff;
    color: var(--workflow-ink);
    padding: 0 0.75rem;
    font: inherit;
    font-size: 0.82rem;
    box-shadow: none;
  }

  .workflow-command-name input:focus,
  .workflow-utility-field select:focus {
    outline: none;
    border-color: var(--workflow-blue);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
  }

  .workflow-command-actions {
    justify-content: flex-end;
    gap: 0.55rem;
  }

  .workflow-editor-commandbar .btn-premium-primary,
  .workflow-editor-commandbar .btn-premium-secondary {
    min-height: 40px;
    border-radius: 7px;
    padding: 0.58rem 0.78rem;
    font-size: 0.78rem;
    box-shadow: none;
    white-space: nowrap;
  }

  .workflow-editor-commandbar .btn-premium-primary {
    background: var(--workflow-blue);
    border-color: var(--workflow-blue);
  }

  .workflow-active-toggle {
    position: relative;
    gap: 0.52rem;
    color: #334155;
    font-size: 0.78rem;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
  }

  .workflow-active-toggle input {
    position: absolute;
    opacity: 0;
    pointer-events: none;
  }

  .workflow-active-toggle-track {
    position: relative;
    width: 40px;
    height: 22px;
    border-radius: 999px;
    background: #cbd5e1;
    transition: background 0.18s ease, box-shadow 0.18s ease;
  }

  .workflow-active-toggle-track::after {
    content: '';
    position: absolute;
    top: 3px;
    left: 3px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 4px rgba(15, 23, 42, 0.22);
    transition: transform 0.18s ease;
  }

  .workflow-active-toggle input:checked + .workflow-active-toggle-track {
    background: var(--workflow-blue);
  }

  .workflow-active-toggle input:checked + .workflow-active-toggle-track::after {
    transform: translateX(18px);
  }

  .workflow-active-toggle input:focus-visible + .workflow-active-toggle-track {
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
  }

  .workflow-command-utilities .workflow-toolbar-menu-panel {
    top: calc(100% + 0.5rem);
    min-width: 235px;
    border-radius: 9px;
  }

  .workflow-utility-field {
    display: grid;
    gap: 0.35rem;
    padding: 0.25rem;
  }

  .workflow-inline-banner {
    max-width: 780px;
    margin: 0 0 0.55rem auto;
    border-radius: 9px;
    box-shadow: none;
  }

  .workflow-template-banner {
    margin-bottom: 0.55rem;
    border-radius: 9px;
    box-shadow: none;
  }

  .workflow-workbench-grid {
    display: grid;
    grid-template-columns: clamp(280px, 22vw, 340px) minmax(0, 1fr);
    gap: 0;
    align-items: stretch;
    min-height: 650px;
    height: calc(100vh - 270px);
    max-height: 940px;
    overflow: hidden;
    border: 1px solid var(--workflow-border);
    border-radius: var(--workflow-radius);
    background: var(--workflow-surface);
  }

  .workflow-palette-rail {
    position: static;
    display: flex;
    flex-direction: column;
    min-width: 0;
    max-height: none;
    margin: 0;
    padding: 0;
    overflow: hidden;
    border: 0;
    border-right: 1px solid var(--workflow-border);
    border-radius: 0;
    background: #fbfcfe;
    box-shadow: none;
  }

  .workflow-palette-rail-header {
    flex: 0 0 auto;
    padding: 0.82rem 0.85rem 0.7rem;
    border-bottom: 0;
  }

  .workflow-palette-rail-header strong {
    font-size: 0.92rem;
    letter-spacing: -0.02em;
    text-transform: none;
  }

  .workflow-palette-rail-header p {
    margin-top: 0.18rem;
    font-size: 0.7rem;
  }

  .workflow-palette-host {
    flex: 1 1 auto;
    min-height: 0;
    overflow: hidden;
  }

  .workflow-palette-rail #workflow-palette-container {
    display: flex !important;
    flex-direction: column;
    height: 100%;
    max-height: none !important;
    margin: 0 !important;
    overflow: hidden !important;
    border: 0 !important;
    border-radius: 0 !important;
    background: transparent !important;
    box-shadow: none !important;
  }

  .workflow-quick-starts {
    flex: 0 0 auto;
    margin: 0;
    border-top: 1px solid #eef2f7;
    border-bottom: 1px solid #eef2f7;
    background: #fff;
  }

  .workflow-quick-starts[hidden] {
    display: none !important;
  }

  .workflow-quick-starts summary {
    display: flex;
    justify-content: space-between;
    align-items: center;
    min-height: 38px;
    padding: 0 0.8rem;
    color: #334155;
    cursor: pointer;
    font-size: 0.72rem;
    font-weight: 700;
    list-style: none;
  }

  .workflow-quick-starts summary::-webkit-details-marker {
    display: none;
  }

  .workflow-quick-starts[open] summary i {
    transform: rotate(180deg);
  }

  .workflow-recommend-list {
    display: grid;
    gap: 0.28rem;
    padding: 0 0.65rem 0.55rem;
  }

  .workflow-recommend-chip {
    min-width: 0;
    padding: 0.42rem 0.5rem;
    border-radius: 6px;
    background: #fff;
    box-shadow: none;
    font-size: 0.68rem;
  }

  .workflow-quick-starts-link {
    display: block;
    padding: 0 0.75rem 0.65rem;
    color: var(--workflow-blue);
    font-size: 0.68rem;
    font-weight: 600;
    text-decoration: none;
  }

  .workflow-builder-card {
    position: relative;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: #fff;
    box-shadow: none;
  }

  .workflow-builder-shell {
    position: relative;
    height: 100%;
    margin: 0;
    padding: 0;
    overflow: hidden;
    border: 0;
    border-radius: 0;
    background: #fff;
    box-shadow: none;
  }

  .workflow-builder-shell::after {
    content: '';
    position: absolute;
    z-index: 3;
    right: 0;
    bottom: 0;
    left: 0;
    height: 58px;
    border-top: 1px solid var(--workflow-border);
    background: rgba(255, 255, 255, 0.97);
    pointer-events: none;
  }

  .workflow-builder-canvas,
  #workflow-builder {
    height: 100%;
    min-height: 0;
    overflow: hidden;
    border-radius: 0;
    background: #fff;
  }

  #workflow-canvas {
    height: 100% !important;
    min-height: 0 !important;
    border: 0 !important;
    border-radius: 0 !important;
    background-color: #fff !important;
    background-image: radial-gradient(circle, var(--workflow-grid) 1px, transparent 1px) !important;
    background-size: 24px 24px !important;
    box-shadow: none !important;
  }

  #workflow-canvas.is-drop-target {
    background-color: #f5f9ff !important;
    box-shadow: inset 0 0 0 2px var(--workflow-blue) !important;
  }

  #workflow-controls,
  #workflow-zoom-controls {
    top: auto !important;
    bottom: 10px !important;
    z-index: 12 !important;
    gap: 6px !important;
  }

  #workflow-controls {
    left: 14px !important;
  }

  #workflow-zoom-controls {
    right: auto !important;
    left: 215px !important;
    justify-content: flex-start !important;
  }

  #workflow-controls button,
  #workflow-zoom-controls button {
    min-width: 36px !important;
    height: 36px !important;
    padding: 0 10px !important;
    border-color: #dbe3ee !important;
    border-radius: 6px !important;
    background: #fff !important;
    color: #334155 !important;
    font-size: 0.72rem !important;
    font-weight: 600 !important;
    box-shadow: none !important;
    backdrop-filter: none !important;
  }

  .workflow-canvas-status {
    position: absolute;
    right: 100px;
    bottom: 0;
    z-index: 12;
    display: flex;
    align-items: center;
    gap: 0.48rem;
    min-height: 58px;
    color: #64748b;
    font-size: 0.7rem;
    pointer-events: none;
  }

  .workflow-canvas-status > strong {
    color: #334155;
    font-size: inherit;
    font-weight: 600;
  }

  .workflow-save-status {
    display: inline-flex;
    align-items: center;
    gap: 0.38rem;
    margin: 0;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
  }

  .workflow-save-status > strong {
    color: #475569;
    font-size: 0.7rem;
    font-weight: 500;
  }

  .workflow-status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #22c55e;
  }

  .workflow-save-status.is-warning .workflow-status-dot {
    background: #f59e0b;
  }

  .workflow-save-status.is-danger .workflow-status-dot {
    background: #ef4444;
  }

  .workflow-save-status.is-info .workflow-status-dot {
    background: #3b82f6;
  }

  .workflow-status-copy {
    display: none;
  }

  .workflow-clear-confirm,
  .workflow-validation-summary {
    position: absolute;
    z-index: 35;
    top: 0.75rem;
    right: 0.75rem;
    left: 0.75rem;
    margin: 0;
    border-radius: 8px;
    box-shadow: 0 12px 30px rgba(15, 23, 42, 0.1);
  }

  .workflow-advanced-panel {
    margin-top: 0.75rem;
    border-radius: 10px;
    box-shadow: none;
  }

  #node-config-panel {
    top: 88px;
    right: 18px;
    width: min(360px, calc(100vw - 36px));
    max-height: calc(100vh - 112px);
    transform: none;
    border-color: var(--workflow-border);
    border-radius: 10px;
    box-shadow: 0 18px 48px rgba(15, 23, 42, 0.16);
  }

  @media (max-width: 1180px) {
    .workflow-editor-commandbar {
      grid-template-columns: minmax(220px, auto) minmax(260px, 1fr);
    }

    .workflow-command-actions {
      grid-column: 1 / -1;
      justify-content: flex-end;
      margin-top: -0.2rem;
    }

    .workflow-workbench-grid {
      height: calc(100vh - 324px);
      grid-template-columns: 238px minmax(0, 1fr);
    }
  }

  @media (max-width: 820px) {
    .workflow-editor-commandbar {
      position: static;
      grid-template-columns: 1fr;
      gap: 0.65rem;
    }

    .workflow-command-actions {
      grid-column: auto;
      justify-content: flex-start;
      flex-wrap: wrap;
      margin-top: 0;
    }

    .workflow-workbench-grid {
      grid-template-columns: 1fr;
      height: auto;
      max-height: none;
      overflow: visible;
    }

    .workflow-palette-rail {
      max-height: 360px;
      border-right: 0;
      border-bottom: 1px solid var(--workflow-border);
    }

    .workflow-builder-card,
    .workflow-builder-shell,
    .workflow-builder-canvas,
    #workflow-builder {
      height: 620px;
    }
  }

  @media (max-width: 620px) {
    .workflow-editor-nav {
      flex-wrap: wrap;
    }

    .workflow-command-name {
      align-items: stretch;
      flex-direction: column;
      gap: 0.3rem;
    }

    .workflow-primary-save {
      flex: 1 1 auto;
    }

    .workflow-builder-card,
    .workflow-builder-shell,
    .workflow-builder-canvas,
    #workflow-builder {
      height: 560px;
    }

    #workflow-zoom-controls {
      left: auto !important;
      right: 10px !important;
    }

    #workflow-zoom-controls button:nth-child(3),
    #workflow-zoom-controls button:nth-child(5),
    .workflow-canvas-status > span,
    .workflow-canvas-status > strong:first-child {
      display: none !important;
    }

    .workflow-canvas-status {
      right: 78px;
      bottom: 50px;
      min-height: 32px;
      padding: 0 0.55rem;
      border: 1px solid var(--workflow-border);
      border-radius: 999px;
      background: #fff;
    }
  }
</style>

<div class="page-premium workflow-create-page">
  <div class="container">
    <section class="workflow-editor-commandbar" aria-label="Workflow editor commands">
      <div class="workflow-editor-nav">
        <h1 class="workflow-editor-title"><?php echo $isEditing ? 'Edit workflow' : 'Create workflow'; ?></h1>
        <a href="workflows.php" class="workflow-command-link">
          <i class="fas fa-arrow-left" aria-hidden="true"></i>
          <span>Back to workflows</span>
        </a>
        <span class="workflow-command-divider" aria-hidden="true"></span>
        <a href="workflow_templates.php" class="workflow-command-link">
          <i class="fas fa-layer-group" aria-hidden="true"></i>
          <span>Templates</span>
        </a>
      </div>

      <div class="workflow-command-name">
        <label for="visual-workflow-name">Workflow name</label>
        <input
          type="text"
          id="visual-workflow-name"
          placeholder="e.g. Welcome new contacts"
          value="<?php echo htmlspecialchars($initialWorkflowName); ?>"
          required
        >
      </div>

      <div class="workflow-command-actions">
        <label class="workflow-active-toggle">
          <span>Active</span>
          <input type="checkbox" id="visual-is-active" <?php echo $initialIsActive ? 'checked' : ''; ?>>
          <span class="workflow-active-toggle-track" aria-hidden="true"></span>
        </label>
        <button type="button" class="btn-premium-secondary" onclick="validateWorkflow()">
          <i class="fas fa-check-circle" aria-hidden="true"></i>
          Validate
        </button>
        <details class="workflow-toolbar-menu workflow-command-utilities">
          <summary class="btn-premium-secondary">
            <i class="fas fa-sliders" aria-hidden="true"></i>
            Utilities
          </summary>
          <div class="workflow-toolbar-menu-panel">
            <label class="workflow-utility-field" for="workflow-mode">
              <span>Workflow type</span>
              <select id="workflow-mode">
                <option value="mixed">Mixed platform</option>
                <option value="crm">CRM ops</option>
                <option value="journey">Journey automation</option>
              </select>
            </label>
            <button type="button" class="btn-premium-secondary" onclick="importWorkflowJSON()">
              <i class="fas fa-file-import" aria-hidden="true"></i>
              Import JSON
            </button>
            <button type="button" class="btn-premium-secondary" onclick="exportWorkflowJSON()">
              <i class="fas fa-file-export" aria-hidden="true"></i>
              Export JSON
            </button>
            <button type="button" class="btn-premium-secondary" onclick="openAdvancedPanel(true)">
              <i class="fas fa-list" aria-hidden="true"></i>
              Compatibility form
            </button>
            <button type="button" class="btn-premium-secondary" onclick="requestClearVisualBuilder()">
              <i class="fas fa-trash" aria-hidden="true"></i>
              Clear builder
            </button>
          </div>
        </details>
        <button type="button" class="btn-premium-primary workflow-primary-save" onclick="saveWorkflowVisual()">
          <i class="fas fa-save" aria-hidden="true"></i>
          <?php echo $isEditing ? 'Save changes' : 'Create workflow'; ?>
        </button>
      </div>
    </section>

    <section
      id="workflow-page-feedback"
      class="workflow-card workflow-inline-banner <?php echo $error ? 'is-danger' : ($savedBanner ? 'is-success' : 'is-info'); ?>"
      <?php echo ($error || $savedBanner) ? '' : 'hidden'; ?>
    >
      <div class="workflow-inline-banner-icon">
        <i class="fas <?php echo $error ? 'fa-exclamation-triangle' : ($savedBanner ? 'fa-check' : 'fa-circle-info'); ?>"></i>
      </div>
      <div class="workflow-inline-banner-content">
        <h3 id="workflow-page-feedback-title">
          <?php
          if ($error) {
              echo 'Compatibility form needs attention';
          } elseif ($savedBanner) {
              echo 'Workflow saved';
          } else {
              echo 'Workflow builder ready';
          }
          ?>
        </h3>
        <p id="workflow-page-feedback-message">
          <?php
          if ($error) {
              echo htmlspecialchars($error);
          } elseif ($savedBanner) {
              echo 'The latest workflow version was saved successfully. You can continue refining the builder or open the advanced panel if you need the fallback form.';
          } else {
              echo 'Use the builder as the primary authoring path. Inline validation, import/export, and save states will appear here.';
          }
          ?>
        </p>
        <div id="workflow-page-feedback-actions" class="workflow-inline-banner-actions"></div>
      </div>
    </section>

    <?php if ($template): ?>
      <section class="workflow-card workflow-template-banner">
        <div class="workflow-stack-sm">
          <h3>Starting from template: <?php echo htmlspecialchars((string) ($template['name'] ?? 'Workflow template')); ?></h3>
          <p><?php echo htmlspecialchars($templateDescription !== '' ? $templateDescription : 'This template has been loaded into the compatibility payload so the visual builder can start from the same workflow source.'); ?></p>
        </div>
        <div class="workflow-template-banner-actions">
          <a href="workflow_templates.php" class="btn-premium-secondary">
            <i class="fas fa-layer-group"></i>
            View Library
          </a>
        </div>
      </section>
    <?php endif; ?>

    <div class="workflow-workbench-grid">
      <aside class="workflow-palette-rail" aria-label="Workflow node library">
        <div class="workflow-palette-rail-header">
          <strong>Nodes</strong>
          <p>Drag a node or use Add.</p>
        </div>
        <details id="workflow-create-recommendations" class="workflow-quick-starts" hidden>
          <summary>
            <span>Quick starts</span>
            <i class="fas fa-chevron-down" aria-hidden="true"></i>
          </summary>
          <div id="workflow-create-recommendations-list" class="workflow-recommend-list"></div>
          <a href="workflow_templates.php" class="workflow-quick-starts-link">Browse all templates</a>
        </details>
        <div id="workflow-palette-host" class="workflow-palette-host"></div>
      </aside>

      <section class="workflow-builder-card">
        <section id="workflow-clear-confirm" class="workflow-clear-confirm" hidden>
          <p><strong>Clear this builder?</strong> This removes the current nodes and connections from the canvas. It does not change saved workflows until you save again.</p>
          <div class="workflow-btn-group">
            <button type="button" class="btn-premium-danger" onclick="clearVisualBuilderConfirmed()">
              <i class="fas fa-trash"></i>
              Confirm Clear
            </button>
            <button type="button" class="btn-premium-secondary" onclick="cancelClearVisualBuilder()">
              <i class="fas fa-xmark"></i>
              Keep Current Graph
            </button>
          </div>
        </section>

        <section id="workflow-validation-summary" class="workflow-validation-summary" hidden>
          <div class="workflow-validation-summary-icon">
            <i class="fas fa-check"></i>
          </div>
          <div>
            <h3>Validation ready</h3>
            <p>Validation results will appear here.</p>
          </div>
        </section>

        <div id="visual-builder-container" class="workflow-builder-shell">
          <div id="workflow-builder" class="workflow-builder-canvas"></div>
          <div class="workflow-canvas-status" aria-live="polite">
            <strong id="builder-node-count">0 nodes</strong>
            <span aria-hidden="true">•</span>
            <span id="builder-connection-count">0 connections</span>
            <span aria-hidden="true">•</span>
            <div id="workflow-save-status" class="workflow-save-status">
              <span class="workflow-status-dot" aria-hidden="true"></span>
              <strong id="workflow-save-status-title">Ready to build</strong>
              <span id="workflow-save-status-copy" class="workflow-status-copy">Add a trigger to start your workflow.</span>
            </div>
            <span id="workflow-meta-status" class="sr-only">Builder ready</span>
            <span id="workflow-meta-status-note" class="sr-only">Start with a trigger in the node library.</span>
          </div>
        </div>
      </section>
    </div>

    <details id="workflow-advanced-panel" class="workflow-card workflow-advanced-panel" <?php echo $error ? 'open' : ''; ?>>
      <summary>
        <span><i class="fas fa-list"></i> Advanced / compatibility form</span>
        <i class="fas fa-chevron-down"></i>
      </summary>
      <div class="workflow-advanced-panel-body">
        <div class="workflow-advanced-panel-head">
          <div class="workflow-stack-sm">
            <h2>Legacy fallback authoring</h2>
            <p>Use this panel when you need the existing compatibility form payload. It stays on the same page and continues posting the same legacy workflow fields.</p>
          </div>
          <div class="workflow-advanced-actions">
            <button type="button" class="btn-premium-secondary" onclick="populateCompatibilityFormFromBuilder(true)">
              <i class="fas fa-arrow-down"></i>
              Pull Current Builder State
            </button>
          </div>
        </div>

        <form method="POST" action="" id="workflowForm" class="workflow-compat-form">
          <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

          <div class="workflow-compat-grid">
            <div class="workflow-field">
              <label for="name">Workflow name *</label>
              <input
                type="text"
                id="name"
                name="name"
                required
                value="<?php echo htmlspecialchars((string) ($_POST['name'] ?? '')); ?>"
                placeholder="e.g., Welcome New Contacts"
              >
            </div>
            <div class="workflow-field">
              <label for="trigger_type">Trigger *</label>
              <select id="trigger_type" name="trigger_type" required onchange="updateTriggerFields()">
                <option value="">Select a trigger...</option>
                <option value="contact_created" <?php echo (isset($_POST['trigger_type']) && $_POST['trigger_type'] === 'contact_created') ? 'selected' : ''; ?>>Contact Created</option>
                <option value="email_opened" <?php echo (isset($_POST['trigger_type']) && $_POST['trigger_type'] === 'email_opened') ? 'selected' : ''; ?>>Email Opened</option>
                <option value="whatsapp_message_received" <?php echo (isset($_POST['trigger_type']) && $_POST['trigger_type'] === 'whatsapp_message_received') ? 'selected' : ''; ?>>WhatsApp Message Received</option>
                <option value="form_submitted" <?php echo (isset($_POST['trigger_type']) && $_POST['trigger_type'] === 'form_submitted') ? 'selected' : ''; ?>>Form Submitted</option>
                <option value="stage_changed" <?php echo (isset($_POST['trigger_type']) && $_POST['trigger_type'] === 'stage_changed') ? 'selected' : ''; ?>>Stage Changed</option>
                <option value="no_activity_for_days" <?php echo (isset($_POST['trigger_type']) && $_POST['trigger_type'] === 'no_activity_for_days') ? 'selected' : ''; ?>>No Activity for Days</option>
              </select>
            </div>
          </div>

          <div id="trigger_fields" class="workflow-compat-card" hidden></div>

          <div class="workflow-compat-card">
            <div class="workflow-compat-card-header">
              <div class="workflow-stack-sm">
                <strong>Conditions</strong>
                <p class="workflow-help-text">Conditions narrow when the workflow should run. Leave empty to allow the trigger to run for all matching records.</p>
              </div>
              <button type="button" class="btn-premium-secondary" onclick="addCondition()">
                <i class="fas fa-plus"></i>
                Add Condition
              </button>
            </div>
            <div id="conditions_list" class="workflow-conditions-list"></div>
          </div>

          <div class="workflow-compat-card">
            <div class="workflow-compat-card-header">
              <div class="workflow-stack-sm">
                <strong>Actions *</strong>
                <p class="workflow-help-text">These action cards continue posting the existing legacy action payload structure when the compatibility form is used.</p>
              </div>
              <button type="button" class="btn-premium-secondary" onclick="addAction()">
                <i class="fas fa-plus"></i>
                Add Action
              </button>
            </div>
            <div id="actions_container" class="workflow-actions-list"></div>
          </div>

          <label class="workflow-checkbox-row">
            <input
              type="checkbox"
              id="is_active"
              name="is_active"
              <?php echo $initialIsActive ? 'checked' : ''; ?>
            >
            <span>Activate workflow immediately</span>
          </label>

          <div class="workflow-btn-group">
            <button type="submit" class="btn-premium-primary">
              <i class="fas fa-save"></i>
              <?php echo $isEditing ? 'Save via Compatibility Form' : 'Create via Compatibility Form'; ?>
            </button>
            <button type="button" class="btn-premium-secondary" onclick="openAdvancedPanel(true)">
              <i class="fas fa-rotate"></i>
              Refresh From Builder
            </button>
          </div>
        </form>
      </div>
    </details>
  </div>
</div>

<script>
let actionCount = 0;
let conditionCount = 0;
let pageWorkflowBuilder = null;

const emailTemplates = <?php echo json_encode($emailTemplates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const emailTemplateFacets = <?php echo json_encode($emailTemplateFacets, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const users = <?php echo json_encode($users, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const stages = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];
const stageLabels = ['New', 'Contacted', 'Qualified', 'Proposal', 'Negotiation', 'Won', 'Lost'];
const availableFields = [
    { value: 'stage', label: 'Stage' },
    { value: 'company', label: 'Company' },
    { value: 'email', label: 'Email' },
    { value: 'phone', label: 'Phone' },
    { value: 'lead_score', label: 'Composite Lead Score' },
    { value: 'assigned_to', label: 'Assigned To' }
];
const availableOperators = [
    { value: 'equals', label: 'Equals' },
    { value: 'not_equals', label: 'Not Equals' },
    { value: 'contains', label: 'Contains' },
    { value: 'not_contains', label: 'Not Contains' },
    { value: 'greater_than', label: 'Greater Than' },
    { value: 'less_than', label: 'Less Than' },
    { value: 'is_empty', label: 'Is Empty' },
    { value: 'is_not_empty', label: 'Is Not Empty' }
];
const currentWorkflowId = <?php echo $workflowId > 0 ? $workflowId : 'null'; ?>;
window.currentWorkflowId = currentWorkflowId;
const workflowModeDefault = <?php echo json_encode($initialWorkflowMode, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const hasTemplate = <?php echo $template ? 'true' : 'false'; ?>;
const isEditingWorkflow = <?php echo $isEditing ? 'true' : 'false'; ?>;
const initialLegacyState = <?php echo json_encode($initialLegacyState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
const templateData = <?php echo json_encode($templateVisualState, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value == null ? '' : String(value);
    return div.innerHTML;
}

function setBuilderRailStatus(title, copy, type = 'neutral') {
    const box = document.getElementById('workflow-save-status');
    const titleEl = document.getElementById('workflow-save-status-title');
    const copyEl = document.getElementById('workflow-save-status-copy');
    if (!box || !titleEl || !copyEl) {
        return;
    }

    box.className = 'workflow-save-status';
    if (type && type !== 'neutral') {
        box.classList.add(`is-${type}`);
    }
    titleEl.textContent = title;
    copyEl.textContent = copy;
}

function setMetaStatus(title, note) {
    const statusEl = document.getElementById('workflow-meta-status');
    const noteEl = document.getElementById('workflow-meta-status-note');
    if (statusEl) {
        statusEl.textContent = title;
    }
    if (noteEl) {
        noteEl.textContent = note;
    }
}

function hideWorkflowFeedback() {
    const banner = document.getElementById('workflow-page-feedback');
    const actions = document.getElementById('workflow-page-feedback-actions');
    if (!banner) {
        return;
    }
    banner.hidden = true;
    banner.className = 'workflow-card workflow-inline-banner';
    if (actions) {
        actions.innerHTML = '';
    }
}

function setWorkflowFeedback(type, title, message, actionsHtml = '') {
    const banner = document.getElementById('workflow-page-feedback');
    const titleEl = document.getElementById('workflow-page-feedback-title');
    const messageEl = document.getElementById('workflow-page-feedback-message');
    const iconEl = banner ? banner.querySelector('.workflow-inline-banner-icon i') : null;
    const actionsEl = document.getElementById('workflow-page-feedback-actions');
    if (!banner || !titleEl || !messageEl) {
        return;
    }

    const normalizedType = ['success', 'warning', 'danger', 'info'].includes(type) ? type : 'info';
    const iconMap = {
        success: 'fa-check',
        warning: 'fa-triangle-exclamation',
        danger: 'fa-circle-xmark',
        info: 'fa-circle-info'
    };

    banner.hidden = false;
    banner.className = `workflow-card workflow-inline-banner is-${normalizedType}`;
    titleEl.textContent = title;
    messageEl.textContent = message;
    if (iconEl) {
        iconEl.className = `fas ${iconMap[normalizedType]}`;
    }
    if (actionsEl) {
        actionsEl.innerHTML = actionsHtml || '';
    }
}

window.WorkflowBuilderFeedback = {
    notify(type, message, title = 'Workflow builder') {
        const safeType = type === 'error' ? 'danger' : (type === 'warning' ? 'warning' : (type === 'success' ? 'success' : 'info'));
        setWorkflowFeedback(safeType, title, message);
        setBuilderRailStatus(title, message, safeType === 'danger' ? 'danger' : safeType);
        if (safeType === 'success') {
            setMetaStatus('Builder healthy', 'The latest builder action completed successfully.');
        } else if (safeType === 'warning') {
            setMetaStatus('Needs review', 'There is feedback to review before the workflow is saved.');
        } else if (safeType === 'danger') {
            setMetaStatus('Action blocked', 'Resolve the highlighted issue and try again.');
        }
    }
};

function syncWorkflowMeta() {
    const visualNameEl = document.getElementById('visual-workflow-name');
    const formNameEl = document.getElementById('name');
    const visualActiveEl = document.getElementById('visual-is-active');
    const formActiveEl = document.getElementById('is_active');

    if (visualNameEl && formNameEl) {
        formNameEl.value = visualNameEl.value;
    }
    if (visualActiveEl && formActiveEl) {
        formActiveEl.checked = visualActiveEl.checked;
    }
}

function updateBuilderStats() {
    const nodeEl = document.getElementById('builder-node-count');
    const connectionEl = document.getElementById('builder-connection-count');
    const nodeCount = pageWorkflowBuilder && Array.isArray(pageWorkflowBuilder.nodes) ? pageWorkflowBuilder.nodes.length : 0;
    const connectionCount = pageWorkflowBuilder && pageWorkflowBuilder.connectionManager && Array.isArray(pageWorkflowBuilder.connectionManager.connections)
        ? pageWorkflowBuilder.connectionManager.connections.length
        : 0;

    if (nodeEl) {
        nodeEl.textContent = `${nodeCount} ${nodeCount === 1 ? 'node' : 'nodes'}`;
    }
    if (connectionEl) {
        connectionEl.textContent = `${connectionCount} ${connectionCount === 1 ? 'connection' : 'connections'}`;
    }
}

window.addEventListener('workflow:stats-changed', updateBuilderStats);

function getConditionList(conditions) {
    if (!conditions) {
        return [];
    }
    if (Array.isArray(conditions)) {
        return conditions;
    }
    if (Array.isArray(conditions.conditions)) {
        return conditions.conditions;
    }
    if (conditions.field || conditions.operator) {
        return [conditions];
    }
    return [];
}

function clearValidationSummary() {
    const summary = document.getElementById('workflow-validation-summary');
    if (!summary) {
        return;
    }
    summary.hidden = true;
    summary.className = 'workflow-validation-summary';
}

function renderValidationSummary(results) {
    const summary = document.getElementById('workflow-validation-summary');
    if (!summary || !results) {
        return;
    }

    const isValid = !!results.valid;
    const type = isValid ? 'success' : 'danger';
    const issues = Array.isArray(results.issues) ? results.issues : [];
    const icon = isValid ? 'fa-check' : 'fa-triangle-exclamation';
    const title = isValid ? 'Validation passed' : 'Validation issues found';
    const body = isValid
        ? '<p>The graph structure looks ready to save using the existing workflow payload and graph contract.</p>'
        : `<p>Resolve the issues below before saving.</p><ul>${issues.map(issue => `<li>${escapeHtml(issue.message || issue)}</li>`).join('')}</ul>`;

    summary.hidden = false;
    summary.className = `workflow-validation-summary is-${type}`;
    summary.innerHTML = `
        <div class="workflow-validation-summary-icon">
            <i class="fas ${icon}"></i>
        </div>
        <div>
            <h3>${title}</h3>
            ${body}
        </div>
    `;

    if (isValid) {
        setWorkflowFeedback('success', 'Validation passed', 'The workflow graph is structurally valid and ready for save.');
        setBuilderRailStatus('Validation passed', 'The current graph passed validation and can be saved.', 'success');
        setMetaStatus('Validation passed', 'Graph structure is ready for save.');
    } else {
        setWorkflowFeedback('warning', 'Validation needs attention', 'There are issues to address before this workflow can be saved.');
        setBuilderRailStatus('Fix validation issues', 'Review the issues list in the builder and update the graph before saving.', 'warning');
        setMetaStatus('Validation issues', 'Resolve the builder issues highlighted below.');
    }
}

function renderInlineSummary(type, title, message) {
    const summary = document.getElementById('workflow-validation-summary');
    if (!summary) {
        return;
    }

    const safeType = ['success', 'warning', 'danger'].includes(type) ? type : 'info';
    const iconMap = {
        success: 'fa-check',
        warning: 'fa-triangle-exclamation',
        danger: 'fa-circle-xmark',
        info: 'fa-circle-info'
    };

    summary.hidden = false;
    summary.className = `workflow-validation-summary is-${safeType === 'info' ? 'warning' : safeType}`;
    summary.innerHTML = `
        <div class="workflow-validation-summary-icon">
            <i class="fas ${iconMap[safeType]}"></i>
        </div>
        <div>
            <h3>${escapeHtml(title)}</h3>
            <p>${escapeHtml(message)}</p>
        </div>
    `;
}

function updateTriggerFields(triggerConfig = null) {
    const triggerTypeEl = document.getElementById('trigger_type');
    const container = document.getElementById('trigger_fields');
    if (!triggerTypeEl || !container) {
        return;
    }

    const triggerType = triggerTypeEl.value;
    container.innerHTML = '';
    container.hidden = true;

    if (triggerType === 'no_activity_for_days') {
        container.hidden = false;
        container.innerHTML = `
            <div class="workflow-field">
                <label for="trigger_days">Days without activity *</label>
                <input type="number" id="trigger_days" name="trigger_days" min="1" value="7">
            </div>
        `;
        const daysEl = document.getElementById('trigger_days');
        if (daysEl) {
            daysEl.value = triggerConfig && triggerConfig.days != null ? triggerConfig.days : 7;
        }
    } else if (triggerType === 'stage_changed') {
        container.hidden = false;
        container.innerHTML = `
            <div class="workflow-compat-grid">
                <div class="workflow-field">
                    <label for="from_stage">From stage</label>
                    <select id="from_stage" name="from_stage">
                        <option value="">Any</option>
                        ${stages.map((stage, index) => `<option value="${stage}">${stageLabels[index]}</option>`).join('')}
                    </select>
                </div>
                <div class="workflow-field">
                    <label for="to_stage">To stage</label>
                    <select id="to_stage" name="to_stage">
                        <option value="">Any</option>
                        ${stages.map((stage, index) => `<option value="${stage}">${stageLabels[index]}</option>`).join('')}
                    </select>
                </div>
            </div>
        `;

        const fromStageEl = document.getElementById('from_stage');
        const toStageEl = document.getElementById('to_stage');
        if (fromStageEl) {
            fromStageEl.value = triggerConfig && triggerConfig.from_stage != null ? triggerConfig.from_stage : '';
        }
        if (toStageEl) {
            toStageEl.value = triggerConfig && triggerConfig.to_stage != null ? triggerConfig.to_stage : '';
        }
    }
}

function getActionFieldsHtml(actionId, actionData = {}) {
    const type = actionData.type || '';
    if (!type) {
        return '';
    }

    if (type === 'send_email') {
        const facetOptions = function(values, selected) {
            return (values || []).map(value => `<option value="${escapeHtml(value)}" ${String(selected || '') === String(value) ? 'selected' : ''}>${escapeHtml(String(value).replace(/_/g, ' '))}</option>`).join('');
        };
        return `
            <div class="workflow-stack-sm">
                <div class="workflow-field">
                    <label>Template selection</label>
                    <select name="actions[${actionId}][template_strategy]">
                        <option value="fixed" ${(actionData.template_strategy || 'fixed') === 'fixed' ? 'selected' : ''}>Pick a specific template</option>
                        <option value="auto" ${(actionData.template_strategy || '') === 'auto' ? 'selected' : ''}>Auto-pick best template</option>
                    </select>
                </div>
                <div class="workflow-field">
                    <label>Email template</label>
                    <select name="actions[${actionId}][template_id]">
                        <option value="">Select template...</option>
                        ${emailTemplates.map(template => `<option value="${template.id}" ${Number(actionData.template_id || 0) === Number(template.id) ? 'selected' : ''}>${escapeHtml(template.name)}</option>`).join('')}
                    </select>
                </div>
                <div class="workflow-field">
                    <label>Workflow intent</label>
                    <select name="actions[${actionId}][template_intent_key]">
                        <option value="">Auto intent...</option>
                        ${facetOptions(emailTemplateFacets.workflow_intents, actionData.template_intent_key)}
                    </select>
                </div>
                <div class="workflow-field">
                    <label>Purpose</label>
                    <select name="actions[${actionId}][template_purpose]">
                        <option value="">Auto purpose...</option>
                        ${facetOptions(emailTemplateFacets.purposes, actionData.template_purpose)}
                    </select>
                </div>
                <div class="workflow-field">
                    <label>Tone</label>
                    <select name="actions[${actionId}][template_tone]">
                        <option value="">Auto tone...</option>
                        ${facetOptions(emailTemplateFacets.tones, actionData.template_tone)}
                    </select>
                </div>
                <div class="workflow-field">
                    <label>Lifecycle stage</label>
                    <select name="actions[${actionId}][template_lifecycle_stage]">
                        <option value="">Auto stage...</option>
                        ${facetOptions(emailTemplateFacets.lifecycle_stages, actionData.template_lifecycle_stage)}
                    </select>
                </div>
                <div class="workflow-field">
                    <label>Audience</label>
                    <select name="actions[${actionId}][template_audience]">
                        <option value="">Auto audience...</option>
                        ${facetOptions(emailTemplateFacets.audiences, actionData.template_audience)}
                    </select>
                </div>
                <div class="workflow-field">
                    <label>Subject</label>
                    <input type="text" name="actions[${actionId}][subject]" value="${escapeHtml(actionData.subject || '')}" placeholder="Email subject">
                </div>
                <div class="workflow-field">
                    <label>Body</label>
                    <textarea name="actions[${actionId}][body]" rows="4" placeholder="Email body (use {first_name}, {last_name}, etc.)">${escapeHtml(actionData.body || '')}</textarea>
                </div>
            </div>
        `;
    }

    if (type === 'send_whatsapp') {
        return `
            <div class="workflow-field">
                <label>Message</label>
                <textarea name="actions[${actionId}][message]" rows="4" placeholder="WhatsApp message (use {first_name}, etc.)">${escapeHtml(actionData.message || '')}</textarea>
            </div>
        `;
    }

    if (type === 'change_stage') {
        return `
            <div class="workflow-field">
                <label>New stage *</label>
                <select name="actions[${actionId}][stage]" required>
                    <option value="">Select stage...</option>
                    ${stages.map((stage, index) => `<option value="${stage}" ${(actionData.stage || '') === stage ? 'selected' : ''}>${stageLabels[index]}</option>`).join('')}
                </select>
            </div>
        `;
    }

    if (type === 'assign_to_user') {
        return `
            <div class="workflow-field">
                <label>User *</label>
                <select name="actions[${actionId}][user_id]" required>
                    <option value="">Select user...</option>
                    ${users.map(user => `<option value="${user.id}" ${Number(actionData.user_id || 0) === Number(user.id) ? 'selected' : ''}>${escapeHtml(user.email)}</option>`).join('')}
                </select>
            </div>
        `;
    }

    if (type === 'wait_for_days') {
        return `
            <div class="workflow-field">
                <label>Days *</label>
                <input type="number" name="actions[${actionId}][days]" min="1" value="${escapeHtml(actionData.days || 1)}" required>
            </div>
        `;
    }

    return `<p class="workflow-help-text">This action type is preserved for compatibility: <strong>${escapeHtml(type)}</strong>.</p>`;
}

function addAction(actionData = null) {
    actionCount += 1;
    const action = actionData || {};
    const actionType = action.type || '';
    const knownTypes = ['send_email', 'send_whatsapp', 'change_stage', 'assign_to_user', 'wait_for_days'];
    const extraOption = actionType && !knownTypes.includes(actionType)
        ? `<option value="${escapeHtml(actionType)}" selected>${escapeHtml(actionType)}</option>`
        : '';

    const container = document.getElementById('actions_container');
    if (!container) {
        return;
    }

    const actionDiv = document.createElement('div');
    actionDiv.id = `action_${actionCount}`;
    actionDiv.className = 'workflow-compat-card';
    actionDiv.innerHTML = `
        <div class="workflow-compat-card-header">
            <strong>Action ${actionCount}</strong>
            <button type="button" class="btn-premium-secondary" onclick="removeAction(${actionCount})">
                <i class="fas fa-trash"></i>
                Remove
            </button>
        </div>
        <div class="workflow-stack-sm">
            <div class="workflow-field">
                <label>Action type *</label>
                <select name="actions[${actionCount}][type]" onchange="updateActionFields(${actionCount})" required>
                    <option value="">Select action...</option>
                    <option value="send_email" ${actionType === 'send_email' ? 'selected' : ''}>Send Email</option>
                    <option value="send_whatsapp" ${actionType === 'send_whatsapp' ? 'selected' : ''}>Send WhatsApp</option>
                    <option value="change_stage" ${actionType === 'change_stage' ? 'selected' : ''}>Change Stage</option>
                    <option value="assign_to_user" ${actionType === 'assign_to_user' ? 'selected' : ''}>Assign to User</option>
                    <option value="wait_for_days" ${actionType === 'wait_for_days' ? 'selected' : ''}>Wait for Days</option>
                    ${extraOption}
                </select>
            </div>
            <div id="action_fields_${actionCount}">${getActionFieldsHtml(actionCount, action)}</div>
        </div>
    `;
    container.appendChild(actionDiv);
}

function updateActionFields(actionId) {
    const wrapper = document.getElementById(`action_${actionId}`);
    const fieldsContainer = document.getElementById(`action_fields_${actionId}`);
    if (!wrapper || !fieldsContainer) {
        return;
    }

    const select = wrapper.querySelector(`select[name="actions[${actionId}][type]"]`);
    const type = select ? select.value : '';
    fieldsContainer.innerHTML = getActionFieldsHtml(actionId, { type });
}

function removeAction(actionId) {
    const actionEl = document.getElementById(`action_${actionId}`);
    if (actionEl) {
        actionEl.remove();
    }
}

function buildConditionValueField(conditionId, field = '', operator = '', value = '') {
    const noValueOperators = ['is_empty', 'is_not_empty'];
    if (noValueOperators.includes(operator)) {
        return '<p class="workflow-help-text">No value needed for this operator.</p>';
    }

    if (field === 'lead_score' || field === 'assigned_to') {
        return `<input type="number" name="conditions[${conditionId}][value]" value="${escapeHtml(value)}" placeholder="Value">`;
    }

    if (field === 'stage') {
        return `
            <select name="conditions[${conditionId}][value]">
                <option value="">Select stage...</option>
                ${stages.map((stage, index) => `<option value="${stage}" ${value === stage ? 'selected' : ''}>${stageLabels[index]}</option>`).join('')}
            </select>
        `;
    }

    return `<input type="text" name="conditions[${conditionId}][value]" value="${escapeHtml(value)}" placeholder="Value">`;
}

function addCondition(conditionData = null) {
    conditionCount += 1;
    const condition = conditionData || {};
    const container = document.getElementById('conditions_list');
    if (!container) {
        setWorkflowFeedback('danger', 'Compatibility form unavailable', 'The conditions list could not be found. Refresh the page and try again.');
        return;
    }

    const conditionDiv = document.createElement('div');
    conditionDiv.id = `condition_${conditionCount}`;
    conditionDiv.className = 'workflow-compat-card';
    conditionDiv.innerHTML = `
        <div class="workflow-condition-row">
            <div class="workflow-field">
                <label>Field</label>
                <select name="conditions[${conditionCount}][field]" onchange="updateConditionValueField(${conditionCount})">
                    <option value="">Select field...</option>
                    ${availableFields.map(field => `<option value="${field.value}" ${condition.field === field.value ? 'selected' : ''}>${field.label}</option>`).join('')}
                </select>
            </div>
            <div class="workflow-field">
                <label>Operator</label>
                <select name="conditions[${conditionCount}][operator]" onchange="updateConditionValueField(${conditionCount})">
                    <option value="">Select operator...</option>
                    ${availableOperators.map(operator => `<option value="${operator.value}" ${condition.operator === operator.value ? 'selected' : ''}>${operator.label}</option>`).join('')}
                </select>
            </div>
            <div class="workflow-field">
                <label>Value</label>
                <div id="condition_value_${conditionCount}">
                    ${buildConditionValueField(conditionCount, condition.field || '', condition.operator || '', condition.value || '')}
                </div>
            </div>
            <div class="workflow-btn-group">
                <button type="button" class="btn-premium-secondary" onclick="removeCondition(${conditionCount})">
                    <i class="fas fa-trash"></i>
                    Remove
                </button>
            </div>
        </div>
    `;
    container.appendChild(conditionDiv);
}

function updateConditionValueField(conditionId) {
    const wrapper = document.getElementById(`condition_${conditionId}`);
    const valueContainer = document.getElementById(`condition_value_${conditionId}`);
    if (!wrapper || !valueContainer) {
        return;
    }

    const fieldSelect = wrapper.querySelector(`select[name="conditions[${conditionId}][field]"]`);
    const operatorSelect = wrapper.querySelector(`select[name="conditions[${conditionId}][operator]"]`);
    const currentValue = wrapper.querySelector(`[name="conditions[${conditionId}][value]"]`);
    const field = fieldSelect ? fieldSelect.value : '';
    const operator = operatorSelect ? operatorSelect.value : '';
    const value = currentValue ? currentValue.value : '';
    valueContainer.innerHTML = buildConditionValueField(conditionId, field, operator, value);
}

function removeCondition(conditionId) {
    const conditionEl = document.getElementById(`condition_${conditionId}`);
    if (conditionEl) {
        conditionEl.remove();
    }
}

function hydrateLegacyForm(data) {
    const formNameEl = document.getElementById('name');
    const activeEl = document.getElementById('is_active');
    const triggerTypeEl = document.getElementById('trigger_type');
    const actionsContainer = document.getElementById('actions_container');
    const conditionsContainer = document.getElementById('conditions_list');

    if (formNameEl && data && typeof data.name === 'string') {
        formNameEl.value = data.name;
    }
    if (activeEl && data && typeof data.is_active === 'boolean') {
        activeEl.checked = data.is_active;
    }
    if (triggerTypeEl) {
        triggerTypeEl.value = data && data.trigger_type ? data.trigger_type : '';
    }

    updateTriggerFields({
        days: data && data.trigger_days != null ? data.trigger_days : 7,
        from_stage: data && data.from_stage ? data.from_stage : '',
        to_stage: data && data.to_stage ? data.to_stage : ''
    });

    if (actionsContainer) {
        actionsContainer.innerHTML = '';
        actionCount = 0;
    }
    if (conditionsContainer) {
        conditionsContainer.innerHTML = '';
        conditionCount = 0;
    }

    const actions = data && Array.isArray(data.actions) ? data.actions : [];
    const conditions = data && Array.isArray(data.conditions) ? data.conditions : [];

    actions.forEach(action => addAction(action));
    conditions.forEach(condition => addCondition(condition));

    syncWorkflowMeta();
}

function populateCompatibilityForm(workflowData, options = {}) {
    if (!workflowData) {
        return false;
    }

    const formNameEl = document.getElementById('name');
    const visualNameEl = document.getElementById('visual-workflow-name');
    const triggerTypeEl = document.getElementById('trigger_type');
    const actionsContainer = document.getElementById('actions_container');
    const conditionsContainer = document.getElementById('conditions_list');
    const activeEl = document.getElementById('is_active');

    const workflowName = options.name || (visualNameEl ? visualNameEl.value.trim() : '') || 'Workflow from Visual Builder';
    const trigger = options.trigger || workflowData.trigger || {};
    const conditions = getConditionList(options.conditions !== undefined ? options.conditions : workflowData.conditions);
    const actions = Array.isArray(workflowData.actions) ? workflowData.actions : [];

    if (formNameEl) {
        formNameEl.value = workflowName;
    }
    if (triggerTypeEl) {
        triggerTypeEl.value = trigger.type || '';
    }
    if (activeEl) {
        activeEl.checked = document.getElementById('visual-is-active') ? document.getElementById('visual-is-active').checked : activeEl.checked;
    }

    updateTriggerFields(trigger);

    if (actionsContainer) {
        actionsContainer.innerHTML = '';
        actionCount = 0;
    }
    actions.forEach(action => addAction(action));

    if (conditionsContainer) {
        conditionsContainer.innerHTML = '';
        conditionCount = 0;
    }
    conditions.forEach(condition => addCondition(condition));

    syncWorkflowMeta();
    return true;
}

function getBuilderTriggerConfig(exportedData = null) {
    const fallback = exportedData && exportedData.trigger ? exportedData.trigger : {};
    if (!pageWorkflowBuilder || !Array.isArray(pageWorkflowBuilder.nodes)) {
        return fallback;
    }

    const triggerNode = pageWorkflowBuilder.nodes.find(node => node.type === 'trigger');
    if (!triggerNode) {
        return fallback;
    }

    return Object.assign({}, fallback, triggerNode.data || {}, {
        type: (triggerNode.data && triggerNode.data.type) || triggerNode.key || fallback.type || ''
    });
}

function openAdvancedPanel(syncFromVisual = false) {
    const panel = document.getElementById('workflow-advanced-panel');
    if (!panel) {
        return;
    }

    if (syncFromVisual) {
        populateCompatibilityFormFromBuilder(false);
    }

    panel.open = true;
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function populateCompatibilityFormFromBuilder(showFeedback = true) {
    if (!pageWorkflowBuilder) {
        if (showFeedback) {
            setWorkflowFeedback('warning', 'Builder not ready', 'The builder has not finished initializing yet. Try again in a moment.');
        }
        return false;
    }

    const workflowData = pageWorkflowBuilder.exportToForm();
    if (!workflowData) {
        if (showFeedback) {
            setWorkflowFeedback('warning', 'No builder graph to export', 'Add a trigger and at least one action in the builder before pulling the compatibility form state.');
            setBuilderRailStatus('Builder export unavailable', 'Create a valid graph in the builder before using the advanced panel.', 'warning');
        }
        return false;
    }

    const populated = populateCompatibilityForm(workflowData, {
        name: document.getElementById('visual-workflow-name') ? document.getElementById('visual-workflow-name').value.trim() : '',
        trigger: getBuilderTriggerConfig(workflowData),
        conditions: workflowData.conditions
    });

    if (populated && showFeedback) {
        setWorkflowFeedback('info', 'Compatibility form refreshed', 'The advanced panel now mirrors the current builder state using the same legacy field names.');
        setBuilderRailStatus('Form refreshed from builder', 'The fallback form now contains the latest builder graph details.', 'info');
    }

    return populated;
}

function requestClearVisualBuilder() {
    const panel = document.getElementById('workflow-clear-confirm');
    if (panel) {
        panel.hidden = false;
    }
}

function cancelClearVisualBuilder() {
    const panel = document.getElementById('workflow-clear-confirm');
    if (panel) {
        panel.hidden = true;
    }
}

function clearVisualBuilderConfirmed() {
    cancelClearVisualBuilder();
    if (!pageWorkflowBuilder || !pageWorkflowBuilder.initialized) {
        setWorkflowFeedback('warning', 'Builder not ready', 'The builder is not initialized yet, so there is nothing to clear.');
        return;
    }

    pageWorkflowBuilder.clear();
    updateBuilderStats();
    clearValidationSummary();
    setWorkflowFeedback('info', 'Builder cleared', 'The current canvas was cleared. Saved workflows remain unchanged until you save again.');
    setBuilderRailStatus('Canvas cleared', 'The builder is empty. Load a template, import JSON, or start building again.', 'info');
    setMetaStatus('Builder cleared', 'The canvas is empty and ready for a new flow.');
}

function validateWorkflow() {
    if (!pageWorkflowBuilder || !pageWorkflowBuilder.validator) {
        setWorkflowFeedback('warning', 'Builder not ready', 'Validation is only available after the visual builder finishes loading.');
        return;
    }

    const results = pageWorkflowBuilder.validator.showValidationResults();
    renderValidationSummary(results);
}

function exportWorkflowJSON() {
    if (!pageWorkflowBuilder) {
        setWorkflowFeedback('warning', 'Builder not ready', 'The builder needs to finish loading before it can export JSON.');
        return;
    }

    const exported = pageWorkflowBuilder.exportJSON();
    if (exported !== null) {
        setWorkflowFeedback('success', 'Workflow JSON exported', 'A JSON export was generated from the current graph.');
        setBuilderRailStatus('JSON exported', 'The current graph was exported using the existing workflow builder serialization.', 'success');
    }
}

function importWorkflowJSON() {
    if (!pageWorkflowBuilder) {
        setWorkflowFeedback('warning', 'Builder not ready', 'The builder needs to finish loading before importing JSON.');
        return;
    }

    const input = document.createElement('input');
    input.type = 'file';
    input.accept = '.json,application/json';
    input.addEventListener('change', event => {
        const file = event.target.files && event.target.files[0];
        if (!file) {
            return;
        }

        const reader = new FileReader();
        reader.onload = loadEvent => {
            try {
                const imported = pageWorkflowBuilder.importJSON(loadEvent.target.result);
                if (imported) {
                    updateBuilderStats();
                    syncWorkflowMeta();
                    setMetaStatus('Imported graph', 'The canvas was populated from the imported JSON payload.');
                    setBuilderRailStatus('JSON imported', 'Review the imported graph and run validation before saving.', 'info');
                    setWorkflowFeedback('success', 'Workflow JSON imported', 'The builder imported the JSON payload successfully.');
                }
            } catch (error) {
                setWorkflowFeedback('danger', 'Import error', error.message || 'The selected file could not be imported.');
                setBuilderRailStatus('Import failed', 'Review the file contents and try again with a valid workflow JSON export.', 'danger');
            }
        };
        reader.onerror = () => {
            setWorkflowFeedback('danger', 'Import error', 'The selected file could not be read.');
            setBuilderRailStatus('Import failed', 'The file could not be read from disk.', 'danger');
        };
        reader.readAsText(file);
    });
    input.click();
}

async function initializeVisualBuilder() {
    if (pageWorkflowBuilder && pageWorkflowBuilder.initialized) {
        updateBuilderStats();
        return pageWorkflowBuilder;
    }

    if (!pageWorkflowBuilder) {
        pageWorkflowBuilder = new WorkflowBuilder('workflow-builder');
        window.workflowBuilder = pageWorkflowBuilder;
    }

    await new Promise(resolve => {
        window.setTimeout(() => {
            if (pageWorkflowBuilder && !pageWorkflowBuilder.initialized) {
                pageWorkflowBuilder.init();
            }
            if (pageWorkflowBuilder && pageWorkflowBuilder.initialized && pageWorkflowBuilder.connectionManager) {
                pageWorkflowBuilder.connectionManager.redrawAll();
            }
            updateBuilderStats();
            resolve();
        }, 220);
    });

    return pageWorkflowBuilder;
}

function getSuccessRedirectUrl(workflowId) {
    return `workflow_create.php?id=${workflowId}&saved=1`;
}

async function saveWorkflowVisual() {
    const visualNameEl = document.getElementById('visual-workflow-name');
    if (!visualNameEl || !visualNameEl.value.trim()) {
        setWorkflowFeedback('warning', 'Workflow name required', 'Enter a workflow name in the setup bar before saving.');
        setBuilderRailStatus('Name required', 'The workflow name must be set before save.', 'warning');
        if (visualNameEl) {
            visualNameEl.focus();
        }
        return;
    }

    syncWorkflowMeta();

    if (!pageWorkflowBuilder) {
        setWorkflowFeedback('warning', 'Builder not ready', 'Wait for the builder to finish loading before saving.');
        return;
    }

    setBuilderRailStatus('Saving workflow', 'The builder is serializing the current graph and sending it to the existing workflow save endpoint.', 'info');
    const saveResult = await pageWorkflowBuilder.saveToBackend(currentWorkflowId);
    if (!saveResult) {
        return;
    }

    const nextWorkflowId = saveResult.workflow_id || currentWorkflowId;
    const status = saveResult.status || 'saved';

    if ((status === 'saved' || status === 'applied') && nextWorkflowId) {
        window.location.href = getSuccessRedirectUrl(nextWorkflowId);
        return;
    }

    if (status === 'saved_with_warning') {
        renderInlineSummary('warning', 'Saved with warning', saveResult.message || 'Workflow saved, but trigger sync needs attention.');
        setWorkflowFeedback(
            'warning',
            'Saved with warning',
            saveResult.warning || saveResult.message || 'Workflow saved, but trigger sync needs attention.',
            nextWorkflowId ? `<a class="btn-premium-secondary" href="${getSuccessRedirectUrl(nextWorkflowId)}"><i class="fas fa-sync"></i> Reload latest</a>` : ''
        );
        setBuilderRailStatus('Saved with warning', 'The workflow row was saved; review trigger sync before relying on live automation.', 'warning');
        return;
    }

    if (status === 'pending_approval') {
        renderInlineSummary('info', 'Pending approval', 'This AI-assisted workflow proposal was saved for approval rather than being applied immediately.');
        setWorkflowFeedback(
            'info',
            'Pending approval',
            saveResult.message || 'The AI-assisted workflow proposal was saved and is waiting for approval.',
            '<a class="btn-premium-secondary" href="workflow_approvals.php"><i class="fas fa-clipboard-check"></i> Open Workflow Approvals</a>'
        );
        setBuilderRailStatus('Pending approval', 'The proposal is waiting in the approvals queue. No backend contract changes were made.', 'info');
        setMetaStatus('Awaiting approval', 'This save produced a proposal rather than a direct workflow update.');
        return;
    }

    if (status === 'suggested') {
        renderInlineSummary('warning', 'Suggestion only', 'Suggest-only mode created a proposal but did not save workflow changes.');
        setWorkflowFeedback('warning', 'Suggestion only', saveResult.message || 'Suggest-only mode is active, so the workflow proposal was not saved.');
        setBuilderRailStatus('Suggestion generated', 'Suggest-only mode prevented the workflow from being saved.', 'warning');
        setMetaStatus('Suggest-only mode', 'A workflow suggestion was created but not applied.');
        return;
    }

    if (status === 'blocked') {
        setWorkflowFeedback('danger', 'Workflow blocked', saveResult.message || 'The workflow could not be completed.');
        setBuilderRailStatus('Workflow blocked', 'The current save was blocked. Review the feedback and retry after resolving the issue.', 'danger');
        setMetaStatus('Save blocked', 'The builder could not complete the workflow save.');
    }
}

function renderRecommendations(recommendations) {
    const container = document.getElementById('workflow-create-recommendations');
    const list = document.getElementById('workflow-create-recommendations-list');
    if (!container || !list || !Array.isArray(recommendations) || recommendations.length === 0) {
        return;
    }

    list.innerHTML = recommendations.slice(0, 6).map(recommendation => {
        const template = recommendation.template || {};
        const templateId = template.id || '';
        const name = escapeHtml(template.name || 'Workflow template');
        return `
            <a class="workflow-recommend-chip" href="workflow_create.php?template_id=${templateId}" title="${escapeHtml(recommendation.reason || name)}">
                <i class="fas fa-sparkles"></i>
                <span>${name}</span>
            </a>
        `;
    }).join('');
    container.hidden = false;
}

async function loadRecommendations() {
    if (hasTemplate || isEditingWorkflow) {
        return;
    }

    try {
        const response = await fetch('../api/workflows/recommendations.php', { credentials: 'same-origin' });
        const data = await response.json();
        renderRecommendations(data.recommended_templates || []);
    } catch (error) {
        console.warn('Workflow recommendations unavailable', error);
    }
}

async function hydrateBuilderForEdit() {
    if (!currentWorkflowId || !pageWorkflowBuilder) {
        return;
    }

    const loaded = await pageWorkflowBuilder.loadFromBackend(currentWorkflowId);
    if (loaded) {
        syncWorkflowMeta();
        updateBuilderStats();
        setMetaStatus('Existing workflow loaded', 'The current workflow graph was loaded into the builder.');
        setBuilderRailStatus('Editing existing workflow', 'Review the current graph, validate changes, and save when ready.', 'info');
    }
}

function bindMetaEvents() {
    const visualNameEl = document.getElementById('visual-workflow-name');
    const formNameEl = document.getElementById('name');
    const visualActiveEl = document.getElementById('visual-is-active');
    const formActiveEl = document.getElementById('is_active');
    const modeEl = document.getElementById('workflow-mode');
    const advancedPanel = document.getElementById('workflow-advanced-panel');

    if (visualNameEl) {
        visualNameEl.addEventListener('input', syncWorkflowMeta);
    }
    if (formNameEl) {
        formNameEl.addEventListener('input', () => {
            if (visualNameEl && advancedPanel && advancedPanel.open) {
                visualNameEl.value = formNameEl.value;
            }
        });
    }
    if (visualActiveEl) {
        visualActiveEl.addEventListener('change', syncWorkflowMeta);
    }
    if (formActiveEl) {
        formActiveEl.addEventListener('change', () => {
            if (visualActiveEl && advancedPanel && advancedPanel.open) {
                visualActiveEl.checked = formActiveEl.checked;
            }
        });
    }
    if (modeEl) {
        modeEl.addEventListener('change', () => {
            setMetaStatus('Setup updated', `Workflow mode is set to ${modeEl.options[modeEl.selectedIndex].text}.`);
        });
    }
}

async function initWorkflowCreatePage() {
    const modeEl = document.getElementById('workflow-mode');
    if (modeEl) {
        modeEl.value = workflowModeDefault;
    }

    bindMetaEvents();

    if (templateData) {
        hydrateLegacyForm({
            name: templateData.name || '',
            trigger_type: templateData.trigger && templateData.trigger.type ? templateData.trigger.type : '',
            trigger_days: templateData.trigger && templateData.trigger.days != null ? templateData.trigger.days : 7,
            from_stage: templateData.trigger && templateData.trigger.from_stage ? templateData.trigger.from_stage : '',
            to_stage: templateData.trigger && templateData.trigger.to_stage ? templateData.trigger.to_stage : '',
            is_active: <?php echo $initialIsActive ? 'true' : 'false'; ?>,
            actions: Array.isArray(templateData.actions) ? templateData.actions : [],
            conditions: getConditionList(templateData.conditions)
        });
        if (document.getElementById('visual-workflow-name')) {
            document.getElementById('visual-workflow-name').value = templateData.name || document.getElementById('visual-workflow-name').value;
        }
        syncWorkflowMeta();
        setMetaStatus('Template loaded', 'The builder will initialize from the selected template payload.');
        setBuilderRailStatus('Template ready', 'Review the template graph, validate it, and save when ready.', 'info');
    } else if (initialLegacyState && (initialLegacyState.trigger_type || (initialLegacyState.actions && initialLegacyState.actions.length))) {
        hydrateLegacyForm(initialLegacyState);
        if (document.getElementById('visual-workflow-name') && initialLegacyState.name) {
            document.getElementById('visual-workflow-name').value = initialLegacyState.name;
        }
        syncWorkflowMeta();
    } else {
        updateTriggerFields();
        syncWorkflowMeta();
    }

    await initializeVisualBuilder();

    if (isEditingWorkflow) {
        await hydrateBuilderForEdit();
    } else if ((templateData && templateData.trigger && Array.isArray(templateData.actions) && templateData.actions.length > 0)
        || (initialLegacyState && initialLegacyState.trigger_type && Array.isArray(initialLegacyState.actions) && initialLegacyState.actions.length > 0)) {
        if (pageWorkflowBuilder && typeof pageWorkflowBuilder.loadFromForm === 'function') {
            pageWorkflowBuilder.loadFromForm();
            updateBuilderStats();
        }
    } else {
        setMetaStatus('Builder ready', 'Start with a trigger in the palette, import JSON, or use a recommendation below.');
        setBuilderRailStatus('Ready to build', 'Add a trigger to start your workflow, then validate before saving.', 'info');
    }

    window.setInterval(updateBuilderStats, 1200);
    loadRecommendations();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initWorkflowCreatePage);
} else {
    initWorkflowCreatePage();
}
</script>

<script src="assets/js/workflow-nodes.js?v=workflow-webhook-trigger-polish-20260704"></script>
<script src="assets/js/workflow-history.js"></script>
<script src="assets/js/workflow-shortcuts.js?v=workflow-editor-20260716c"></script>
<script src="assets/js/workflow-node-config.js?v=workflow-webhook-trigger-polish-20260704"></script>
<script src="assets/js/workflow-minimap.js"></script>
<script src="assets/js/workflow-validator.js"></script>
<script src="assets/js/workflow-connections.js?v=workflow-conditional-label-fix-20260513"></script>
<script src="assets/js/workflow-builder.js?v=workflow-editor-20260716c"></script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
