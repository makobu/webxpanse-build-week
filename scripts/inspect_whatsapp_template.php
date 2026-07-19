<?php
/**
 * Inspect WhatsApp template structure for debugging 132012 errors.
 * Usage: php scripts/inspect_whatsapp_template.php [template_name] [language_code]
 * Example: php scripts/inspect_whatsapp_template.php hello_world en_US
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Services\WhatsAppService;

$templateName = $argv[1] ?? null;
$languageCode = $argv[2] ?? 'en_US';

if (!$templateName) {
    echo "Usage: php inspect_whatsapp_template.php <template_name> [language_code]\n";
    echo "Example: php inspect_whatsapp_template.php hello_world en_US\n\n";
    echo "Available templates:\n";
    try {
        $service = new WhatsAppService();
        $templates = $service->getTemplates();
        foreach ($templates as $t) {
            $params = $t['parameter_count'] ?? [];
            $pc = ($params['header'] ?? 0) + ($params['body'] ?? 0) + ($params['buttons'] ?? 0);
            echo "  - {$t['name']} ({$t['language']}) - {$pc} params\n";
        }
    } catch (\Exception $e) {
        echo "  Error: " . $e->getMessage() . "\n";
    }
    exit(1);
}

try {
    $service = new WhatsAppService();
    $template = $service->getTemplateByName($templateName, $languageCode);
    
    if (!$template) {
        echo "Template '{$templateName}' with language '{$languageCode}' not found.\n";
        exit(1);
    }
    
    echo "Template: {$template['name']}\n";
    echo "Language: {$template['language']}\n";
    echo "Category: {$template['category']}\n\n";
    
    echo "Parameter schema (expected types for each placeholder):\n";
    echo json_encode($template['parameter_schema'] ?? [], JSON_PRETTY_PRINT) . "\n\n";
    
    echo "Parameter counts:\n";
    echo json_encode($template['parameter_count'] ?? [], JSON_PRETTY_PRINT) . "\n\n";
    
    echo "Raw components from API:\n";
    foreach ($template['components'] ?? [] as $comp) {
        $type = $comp['type'] ?? '?';
        $format = $comp['format'] ?? null;
        $params = $comp['parameters'] ?? [];
        echo "  - {$type}" . ($format ? " (format: {$format})" : '') . "\n";
        foreach ($params as $i => $p) {
            echo "    [{$i}] " . json_encode($p) . "\n";
        }
    }
    
    echo "\nExpected parameter format for sending:\n";
    $schema = $template['parameter_schema'] ?? [];
    if (!empty($schema['header'])) {
        echo "  header: ";
        foreach ($schema['header'] as $i => $h) {
            echo ($i > 0 ? ', ' : '') . ($h['type'] ?? 'text');
        }
        echo "\n";
    }
    if (!empty($schema['body'])) {
        echo "  body: ";
        foreach ($schema['body'] as $i => $b) {
            echo ($i > 0 ? ', ' : '') . ($b['type'] ?? 'text');
        }
        echo "\n";
    }
    if (!empty($schema['buttons'])) {
        echo "  buttons: " . count($schema['buttons']) . " URL params\n";
    }
    
    echo "\nExample template_params for this template:\n";
    $example = [];
    if (!empty($schema['header'])) {
        $example['header'] = array_map(function($h) {
            $t = $h['type'] ?? 'text';
            if ($t === 'currency') return ['100.50|USD'];
            if ($t === 'date_time') return ['February 15, 2025'];
            if ($t === 'image' || $t === 'video' || $t === 'document') return ['https://example.com/file.jpg'];
            return ['Header value'];
        }, $schema['header']);
    }
    if (!empty($schema['body'])) {
        $example['body'] = array_map(function($b) {
            $t = $b['type'] ?? 'text';
            if ($t === 'currency') return '99.99|USD';
            if ($t === 'date_time') return 'February 15, 2025';
            return 'Body value';
        }, $schema['body']);
    }
    echo json_encode($example, JSON_PRETTY_PRINT) . "\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
