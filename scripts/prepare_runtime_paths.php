<?php
declare(strict_types=1);

use CRM\Services\RuntimePathService;

$root = dirname(__DIR__);
$args = array_slice($argv ?? [], 1);
$json = in_array('--json', $args, true);
$help = in_array('--help', $args, true) || in_array('-h', $args, true);

if ($help) {
    echo "Usage: php scripts/prepare_runtime_paths.php [--json]\n\n";
    echo "Creates required ignored runtime directories and verifies writability.\n";
    exit(0);
}

require_once $root . '/vendor/autoload.php';

$service = new RuntimePathService($root);
$result = $service->ensure();
$ok = (array) ($result['missing'] ?? []) === [] && (array) ($result['not_writable'] ?? []) === [];

if ($json) {
    echo json_encode(['ok' => $ok] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($ok ? 0 : 1);
}

echo "=== CRM Runtime Path Preparation ===\n";
echo "Paths checked: " . implode(', ', (array) ($result['paths_checked'] ?? [])) . "\n";
echo "Created: " . (((array) ($result['created'] ?? [])) !== [] ? implode(', ', (array) $result['created']) : 'none') . "\n";
echo "Missing: " . (((array) ($result['missing'] ?? [])) !== [] ? implode(', ', (array) $result['missing']) : 'none') . "\n";
echo "Not writable: " . (((array) ($result['not_writable'] ?? [])) !== [] ? implode(', ', (array) $result['not_writable']) : 'none') . "\n";
echo $ok ? "\nRuntime paths are ready.\n" : "\nRuntime paths need attention before production upload.\n";

exit($ok ? 0 : 1);
