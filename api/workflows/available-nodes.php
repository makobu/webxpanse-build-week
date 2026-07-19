<?php
/**
 * Get Available Workflow Nodes API
 * Returns all available triggers and actions for the visual builder
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\AutomationEngine;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

// Require authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $automationEngine = new AutomationEngine();
    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    $user = Auth::user();
    
    // Get available triggers and actions using reflection
    $reflection = new \ReflectionClass($automationEngine);
    $triggersProperty = $reflection->getProperty('triggers');
    $triggersProperty->setAccessible(true);
    $triggers = $triggersProperty->getValue($automationEngine);
    
    $actionsProperty = $reflection->getProperty('actions');
    $actionsProperty->setAccessible(true);
    $actions = $actionsProperty->getValue($automationEngine);
    
    // Get additional data for dropdowns
    $stages = Database::query(
        "SELECT DISTINCT stage FROM contacts WHERE workspace_id = ? AND stage IS NOT NULL AND stage != '' ORDER BY stage ASC",
        [$workspaceId]
    );
    $tags = Database::query("SELECT id, name FROM tags ORDER BY name ASC");
    $users = Database::query(
        "SELECT u.id, u.email
         FROM users u
         JOIN workspace_memberships wm ON wm.user_id = u.id
         WHERE wm.workspace_id = ? AND wm.membership_status = 'active'
         ORDER BY u.email ASC",
        [$workspaceId]
    );
    $emailTemplates = Database::query(
        "SELECT id, name, subject, body_text, body_html, category, purpose, tags, template_key, match_metadata_json
         FROM email_templates
         WHERE is_active = 1
           AND workspace_id = ?
           AND is_library = 0
           AND (created_by = ? OR is_ai_generated = 1)
         ORDER BY is_ai_generated DESC, name ASC",
        [$workspaceId, (int) ($user['id'] ?? 0)]
    );
    $emailTemplateFacets = [
        'purposes' => [],
        'tones' => [],
        'lifecycle_stages' => [],
        'audiences' => [],
    ];
    foreach ($emailTemplates as &$emailTemplate) {
        $metadata = json_decode((string) ($emailTemplate['match_metadata_json'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $emailTemplate['match_metadata'] = $metadata;
        foreach (['purposes', 'tones', 'lifecycle_stages', 'audiences'] as $facetKey) {
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
    
    // Get custom fields
    $customFields = Database::query(
        "SELECT id, field_name AS name, field_type FROM custom_fields ORDER BY field_name ASC"
    );
    
    echo json_encode([
        'success' => true,
        'triggers' => $triggers,
        'actions' => $actions,
        'options' => [
            'stages' => array_column($stages, 'stage'),
            'tags' => $tags,
            'users' => $users,
            'email_templates' => $emailTemplates,
            'email_template_facets' => $emailTemplateFacets,
            'custom_fields' => $customFields,
            'message_sentiments' => ['positive', 'negative', 'neutral'],
            'message_intents' => ['purchase', 'support', 'information', 'complaint', 'inquiry', 'booking', 'cancellation', 'general', 'unknown']
        ]
    ]);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
