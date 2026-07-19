<?php
/**
 * Repair persisted contact intelligence risk flags that were generated before
 * relationship risk became evidence-based.
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/constants.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

use CRM\Database;
use CRM\Services\ContactIntelligenceService;

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from command line.\n";
    exit(1);
}

Database::init(require __DIR__ . '/../config/database.php');

$options = getopt('', ['limit::', 'dry-run']);
$limit = max(1, min(5000, (int) ($options['limit'] ?? 1000)));
$dryRun = array_key_exists('dry-run', $options);

if (!Database::tableExists('contacts') || !Database::columnExists('contacts', 'metadata_json')) {
    echo json_encode([
        'success' => false,
        'error' => 'contacts.metadata_json is missing. Run migrations before repairing contact intelligence.',
    ], JSON_PRETTY_PRINT) . PHP_EOL;
    exit(1);
}

$rows = Database::query(
    "SELECT id, first_name, last_name, email, metadata_json
     FROM contacts
     WHERE metadata_json IS NOT NULL
       AND metadata_json LIKE '%contact_intelligence%'
       AND (
            metadata_json LIKE '%No response in 21 days%'
            OR metadata_json LIKE '%Contact is stale%'
            OR metadata_json LIKE '%\"is_stale\":true%'
            OR metadata_json LIKE '%\"is_stale\": true%'
       )
     ORDER BY id ASC
     LIMIT {$limit}"
);

$service = new ContactIntelligenceService();
$summary = [
    'success' => true,
    'dry_run' => $dryRun,
    'checked' => count($rows),
    'candidates' => 0,
    'repaired' => 0,
    'unchanged' => 0,
    'skipped_with_engagement_evidence' => 0,
    'failed' => 0,
    'results' => [],
];

foreach ($rows as $row) {
    $contactId = (int) ($row['id'] ?? 0);
    if ($contactId <= 0) {
        continue;
    }

    $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
    if (!is_array($metadata) || !hasPersistedRiskFalsePositiveCandidate($metadata)) {
        continue;
    }

    $summary['candidates']++;
    $beforeFlags = persistedContactRiskFlags($metadata);
    if (contactHasEngagementEvidence($contactId)) {
        $summary['skipped_with_engagement_evidence']++;
        continue;
    }

    if ($dryRun) {
        $summary['results'][] = [
            'contact_id' => $contactId,
            'email' => (string) ($row['email'] ?? ''),
            'status' => 'would_repair',
            'before_flags' => $beforeFlags,
        ];
        continue;
    }

    try {
        $after = $service->computeAndPersist($contactId) ?? [];
        $afterFlags = array_values((array) ($after['relationship_health']['risk_flags'] ?? []));
        if ($afterFlags === $beforeFlags) {
            $summary['unchanged']++;
        } else {
            $summary['repaired']++;
        }

        $summary['results'][] = [
            'contact_id' => $contactId,
            'email' => (string) ($row['email'] ?? ''),
            'status' => $afterFlags === $beforeFlags ? 'unchanged' : 'repaired',
            'before_flags' => $beforeFlags,
            'after_flags' => $afterFlags,
        ];
    } catch (Throwable $e) {
        $summary['failed']++;
        $summary['results'][] = [
            'contact_id' => $contactId,
            'email' => (string) ($row['email'] ?? ''),
            'status' => 'failed',
            'error' => $e->getMessage(),
        ];
    }
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($summary['failed'] > 0 ? 1 : 0);

function hasPersistedRiskFalsePositiveCandidate(array $metadata): bool
{
    $intelligence = $metadata['contact_intelligence'] ?? null;
    if (!is_array($intelligence)) {
        return false;
    }

    $flags = persistedContactRiskFlags($metadata);
    if (in_array('No response in 21 days', $flags, true) || in_array('Contact is stale', $flags, true)) {
        return true;
    }

    $quality = $intelligence['data_quality'] ?? [];
    return is_array($quality) && !empty($quality['is_stale']);
}

function persistedContactRiskFlags(array $metadata): array
{
    $intelligence = $metadata['contact_intelligence'] ?? [];
    if (!is_array($intelligence)) {
        return [];
    }

    $flags = [];
    foreach ([
        $intelligence['risk_flags'] ?? [],
        $intelligence['relationship_health']['risk_flags'] ?? [],
    ] as $sourceFlags) {
        if (!is_array($sourceFlags)) {
            continue;
        }
        foreach ($sourceFlags as $flag) {
            if (is_string($flag) && $flag !== '') {
                $flags[] = $flag;
            }
        }
    }

    return array_values(array_unique($flags));
}

function contactHasEngagementEvidence(int $contactId): bool
{
    $checks = [
        ['communications', 'contact_id = ?', [$contactId]],
        ['notes', "entity_type = 'contact' AND entity_id = ? AND is_private = 0", [$contactId]],
        ['tasks', 'contact_id = ?', [$contactId]],
        ['deals', 'contact_id = ?', [$contactId]],
        ['invoices', 'contact_id = ?', [$contactId]],
        ['events', 'contact_id = ?', [$contactId]],
    ];

    foreach ($checks as [$table, $where, $params]) {
        if (!Database::tableExists($table)) {
            continue;
        }

        $row = Database::queryOne("SELECT 1 AS has_evidence FROM {$table} WHERE {$where} LIMIT 1", $params);
        if ($row) {
            return true;
        }
    }

    return false;
}
