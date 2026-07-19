<?php
/**
 * Attribution Recompute Worker
 *
 * Usage:
 *   php cli/attribution_recompute.php [model_slug]
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\AttributionService;

Database::init(require __DIR__ . '/../config/database.php');

$modelSlug = $argv[1] ?? null;
$service = new AttributionService();
$result = $service->recomputeAllWonDeals($modelSlug, 5000);

echo "Attribution recompute complete.\n";
echo "Deals processed: " . ($result['deals'] ?? 0) . "\n";
echo "Rows written: " . ($result['rows'] ?? 0) . "\n";
