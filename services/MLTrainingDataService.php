<?php
/**
 * ML Training Data Service
 * Prepares historical data for machine learning model training
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Services\MLFeatureService;

class MLTrainingDataService
{
    private MLFeatureService $featureService;
    private AnalyticsWorkspaceService $analyticsWorkspace;
    
    public function __construct(
        ?MLFeatureService $featureService = null,
        ?AnalyticsWorkspaceService $analyticsWorkspace = null
    )
    {
        $this->featureService = $featureService ?? new MLFeatureService();
        $this->analyticsWorkspace = $analyticsWorkspace ?? new AnalyticsWorkspaceService();
    }
    
    /**
     * Prepare training dataset
     * 
     * @param array $options Options for dataset preparation:
     *   - days_back: Number of days to look back for training data (default: 180)
     *   - min_samples: Minimum number of samples required (default: 100)
     *   - balance_classes: Whether to balance classes (default: true)
     *   - label_type: 'conversion', 'churn', or 'engagement' (default: 'conversion')
     *   - test_split: Percentage for test set (default: 0.2)
     *   - validation_split: Percentage for validation set (default: 0.1)
     * @return array Training dataset with features and labels
     */
    public function prepareTrainingDataset(array $options = [], ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $daysBack = $options['days_back'] ?? 180;
        $minSamples = $options['min_samples'] ?? 100;
        $balanceClasses = $options['balance_classes'] ?? true;
        $labelType = $options['label_type'] ?? 'conversion';
        $testSplit = $options['test_split'] ?? 0.2;
        $validationSplit = $options['validation_split'] ?? 0.1;
        
        // Get contacts with sufficient history
        $cutoffDate = date('Y-m-d', strtotime("-{$daysBack} days"));
        
        $contacts = Database::query(
            "SELECT id, created_at, stage, updated_at
             FROM contacts 
             WHERE workspace_id = ?
               AND created_at <= ?
             ORDER BY created_at DESC",
            [$resolvedWorkspaceId, $cutoffDate]
        );
        
        if (count($contacts) < $minSamples) {
            throw new \Exception("Insufficient training data: " . count($contacts) . " contacts found, minimum {$minSamples} required");
        }
        
        $dataset = [];
        $featureNames = $this->featureService->getFeatureNames();
        
        foreach ($contacts as $contact) {
            try {
                // Extract features at snapshot date (use created_at + 30 days as snapshot)
                $snapshotDate = date('Y-m-d', strtotime($contact['created_at'] . ' +30 days'));
                
                // Skip if snapshot is in the future
                if (strtotime($snapshotDate) > time()) {
                    continue;
                }
                
                // Extract features
                $features = $this->featureService->extractAllFeatures((int) $contact['id'], $resolvedWorkspaceId);
                
                // Generate label
                $label = $this->generateLabel($contact['id'], $labelType, $snapshotDate, $resolvedWorkspaceId);
                
                if ($label === null) {
                    continue; // Skip if label cannot be determined
                }
                
                $dataset[] = [
                    'contact_id' => $contact['id'],
                    'workspace_id' => $resolvedWorkspaceId,
                    'features' => $features,
                    'label' => $label,
                    'snapshot_date' => $snapshotDate,
                    'outcome_date' => $this->getOutcomeDate((int) $contact['id'], $labelType, $resolvedWorkspaceId)
                ];
            } catch (\Exception $e) {
                // Skip contacts that fail feature extraction
                continue;
            }
        }
        
        // Balance dataset if requested
        if ($balanceClasses) {
            $dataset = $this->balanceDataset($dataset);
        }
        
        // Split into train/validation/test sets
        $shuffled = $this->shuffleDataset($dataset);
        $totalSamples = count($shuffled);
        
        $testSize = (int) ($totalSamples * $testSplit);
        $validationSize = (int) ($totalSamples * $validationSplit);
        $trainSize = $totalSamples - $testSize - $validationSize;
        
        $trainSet = array_slice($shuffled, 0, $trainSize);
        $validationSet = array_slice($shuffled, $trainSize, $validationSize);
        $testSet = array_slice($shuffled, $trainSize + $validationSize, $testSize);
        
        // Store training data in database
        $this->storeTrainingData($trainSet, $validationSet, $testSet, $labelType, $resolvedWorkspaceId);
        
        return [
            'train' => $trainSet,
            'validation' => $validationSet,
            'test' => $testSet,
            'statistics' => [
                'total_samples' => $totalSamples,
                'train_samples' => count($trainSet),
                'validation_samples' => count($validationSet),
                'test_samples' => count($testSet),
                'positive_samples' => count(array_filter($dataset, fn($d) => $d['label'] === true)),
                'negative_samples' => count(array_filter($dataset, fn($d) => $d['label'] === false)),
                'feature_count' => count($featureNames)
            ]
        ];
    }
    
    /**
     * Generate label for a contact
     */
    public function generateLabel(int $contactId, string $labelType, string $snapshotDate, ?int $workspaceId = null): ?bool
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        switch ($labelType) {
            case 'conversion':
                return $this->generateConversionLabel($contactId, $snapshotDate, $resolvedWorkspaceId);
            case 'churn':
                return $this->generateChurnLabel($contactId, $snapshotDate, $resolvedWorkspaceId);
            case 'engagement':
                return $this->generateEngagementLabel($contactId, $snapshotDate, $resolvedWorkspaceId);
            default:
                throw new \Exception("Unknown label type: {$labelType}");
        }
    }
    
    /**
     * Generate conversion label (converted = true, not converted = false)
     */
    private function generateConversionLabel(int $contactId, string $snapshotDate, int $workspaceId): ?bool
    {
        // Check if contact converted (stage = 'won') within 90 days of snapshot
        $outcomeWindow = date('Y-m-d', strtotime($snapshotDate . ' +90 days'));
        
        $contact = Database::queryOne(
            "SELECT stage, updated_at 
             FROM contacts 
             WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );
        
        if (!$contact) {
            return null;
        }
        
        // If already won before snapshot, exclude (data leakage)
        if ($contact['stage'] === 'won' && strtotime($contact['updated_at']) < strtotime($snapshotDate)) {
            return null;
        }
        
        // Check if converted within outcome window
        $converted = Database::queryOne(
            "SELECT updated_at 
             FROM contacts 
             WHERE workspace_id = ?
             AND id = ? 
             AND stage = 'won' 
             AND updated_at >= ? 
             AND updated_at <= ?",
            [$workspaceId, $contactId, $snapshotDate, $outcomeWindow]
        );
        
        if ($converted) {
            return true; // Converted
        }
        
        // Check if lost (definitely not converted)
        $lost = Database::queryOne(
            "SELECT updated_at 
             FROM contacts 
             WHERE workspace_id = ?
             AND id = ? 
             AND stage = 'lost' 
             AND updated_at >= ? 
             AND updated_at <= ?",
            [$workspaceId, $contactId, $snapshotDate, $outcomeWindow]
        );
        
        if ($lost) {
            return false; // Lost (not converted)
        }
        
        // If outcome window has passed and no conversion, label as false
        if (strtotime($outcomeWindow) < time()) {
            return false; // Did not convert
        }
        
        // If outcome window hasn't passed yet, exclude from training
        return null;
    }
    
    /**
     * Generate churn label
     */
    private function generateChurnLabel(int $contactId, string $snapshotDate, int $workspaceId): ?bool
    {
        // Churn = no activity for 60+ days after snapshot
        $churnWindow = date('Y-m-d', strtotime($snapshotDate . ' +60 days'));
        
        // Check if contact had activity after snapshot
        $recentActivity = Database::queryOne(
            "SELECT MAX(created_at) as last_activity 
             FROM activities 
             WHERE workspace_id = ?
             AND contact_id = ? 
             AND created_at >= ?",
            [$workspaceId, $contactId, $snapshotDate]
        );
        
        if (!$recentActivity || empty($recentActivity['last_activity'])) {
            // No activity after snapshot - check if churn window has passed
            if (strtotime($churnWindow) < time()) {
                return true; // Churned
            }
            return null; // Too early to tell
        }
        
        $lastActivity = strtotime($recentActivity['last_activity']);
        $daysSinceActivity = (time() - $lastActivity) / 86400;
        
        if ($daysSinceActivity > 60 && strtotime($churnWindow) < time()) {
            return true; // Churned
        }
        
        return false; // Did not churn
    }
    
    /**
     * Generate engagement label (high engagement = true)
     */
    private function generateEngagementLabel(int $contactId, string $snapshotDate, int $workspaceId): ?bool
    {
        // High engagement = 5+ activities in 30 days after snapshot
        $engagementWindow = date('Y-m-d', strtotime($snapshotDate . ' +30 days'));
        
        $activityCount = Database::queryOne(
            "SELECT COUNT(*) as count 
             FROM activities 
             WHERE workspace_id = ?
             AND contact_id = ? 
             AND created_at >= ? 
             AND created_at <= ?",
            [$workspaceId, $contactId, $snapshotDate, $engagementWindow]
        );
        
        $count = (int) ($activityCount['count'] ?? 0);
        
        // Only label if engagement window has passed
        if (strtotime($engagementWindow) < time()) {
            return $count >= 5; // High engagement threshold
        }
        
        return null; // Too early to tell
    }
    
    /**
     * Balance dataset to handle class imbalance
     */
    public function balanceDataset(array $dataset): array
    {
        $positiveSamples = array_filter($dataset, fn($d) => $d['label'] === true);
        $negativeSamples = array_filter($dataset, fn($d) => $d['label'] === false);
        
        $positiveCount = count($positiveSamples);
        $negativeCount = count($negativeSamples);
        
        // If classes are balanced (within 20%), return as-is
        if (abs($positiveCount - $negativeCount) / max($positiveCount, $negativeCount) < 0.2) {
            return $dataset;
        }
        
        // Undersample majority class
        if ($positiveCount > $negativeCount) {
            // Undersample positive class
            $positiveSamples = array_values($positiveSamples);
            shuffle($positiveSamples);
            $balancedPositive = array_slice($positiveSamples, 0, $negativeCount);
            return array_merge($balancedPositive, array_values($negativeSamples));
        } else {
            // Undersample negative class
            $negativeSamples = array_values($negativeSamples);
            shuffle($negativeSamples);
            $balancedNegative = array_slice($negativeSamples, 0, $positiveCount);
            return array_merge(array_values($positiveSamples), $balancedNegative);
        }
    }
    
    /**
     * Shuffle dataset randomly
     */
    private function shuffleDataset(array $dataset): array
    {
        $shuffled = $dataset;
        shuffle($shuffled);
        return $shuffled;
    }
    
    /**
     * Get outcome date for a contact
     */
    private function getOutcomeDate(int $contactId, string $labelType, int $workspaceId): ?string
    {
        switch ($labelType) {
            case 'conversion':
                $outcome = Database::queryOne(
                    "SELECT updated_at 
                     FROM contacts 
                     WHERE workspace_id = ? AND id = ? AND stage = 'won'
                     ORDER BY updated_at ASC 
                     LIMIT 1",
                    [$workspaceId, $contactId]
                );
                return $outcome ? date('Y-m-d', strtotime($outcome['updated_at'])) : null;
                
            case 'churn':
                $lastActivity = Database::queryOne(
                    "SELECT MAX(created_at) as last_activity 
                     FROM activities 
                     WHERE workspace_id = ? AND contact_id = ?",
                    [$workspaceId, $contactId]
                );
                if ($lastActivity && $lastActivity['last_activity']) {
                    $lastActivityTime = strtotime($lastActivity['last_activity']);
                    $churnDate = date('Y-m-d', $lastActivityTime + (60 * 86400)); // 60 days after last activity
                    return $churnDate;
                }
                return null;
                
            default:
                return null;
        }
    }
    
    /**
     * Store training data in database
     */
    private function storeTrainingData(
        array $trainSet,
        array $validationSet,
        array $testSet,
        string $labelType,
        int $workspaceId
    ): void
    {
        // Clear old training data
        Database::execute(
            "DELETE FROM ml_training_data WHERE workspace_id = ? AND label_type = ?",
            [$workspaceId, $labelType]
        );
        
        // Store all sets
        $allSets = array_merge($trainSet, $validationSet, $testSet);
        
        foreach ($allSets as $sample) {
            Database::execute(
                "INSERT INTO ml_training_data 
                 (workspace_id, contact_id, features, label, label_type, snapshot_date, outcome_date) 
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    $sample['contact_id'],
                    json_encode($sample['features']),
                    $sample['label'] ? 1 : 0,
                    $labelType,
                    $sample['snapshot_date'],
                    $sample['outcome_date']
                ]
            );
        }
    }
    
    /**
     * Export training data to CSV/JSON
     */
    public function exportTrainingData(string $format = 'json', ?int $workspaceId = null): string
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $trainingData = Database::query(
            "SELECT contact_id, features, label, label_type, snapshot_date 
             FROM ml_training_data 
             WHERE workspace_id = ?
             ORDER BY snapshot_date DESC",
            [$resolvedWorkspaceId]
        );
        
        if ($format === 'csv') {
            return $this->exportToCSV($trainingData);
        } else {
            return json_encode($trainingData, JSON_PRETTY_PRINT);
        }
    }
    
    /**
     * Export to CSV format
     */
    private function exportToCSV(array $data): string
    {
        if (empty($data)) {
            return '';
        }
        
        // Get feature names from first sample
        $firstSample = json_decode($data[0]['features'], true);
        $featureNames = array_keys($firstSample);
        
        // CSV header
        $csv = ['contact_id', 'label', 'label_type', 'snapshot_date', ...$featureNames];
        $lines = [implode(',', $csv)];
        
        // CSV rows
        foreach ($data as $row) {
            $features = json_decode($row['features'], true);
            $csvRow = [
                $row['contact_id'],
                $row['label'] ? '1' : '0',
                $row['label_type'],
                $row['snapshot_date']
            ];
            
            foreach ($featureNames as $featureName) {
                $csvRow[] = $features[$featureName] ?? '0';
            }
            
            $lines[] = implode(',', $csvRow);
        }
        
        return implode("\n", $lines);
    }
    
    /**
     * Get training data statistics
     */
    public function getTrainingDataStats(string $labelType = 'conversion', ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $stats = Database::queryOne(
            "SELECT 
                COUNT(*) as total_samples,
                COUNT(CASE WHEN label = 1 THEN 1 END) as positive_samples,
                COUNT(CASE WHEN label = 0 THEN 1 END) as negative_samples,
                MIN(snapshot_date) as earliest_snapshot,
                MAX(snapshot_date) as latest_snapshot
             FROM ml_training_data 
             WHERE workspace_id = ? AND label_type = ?",
            [$resolvedWorkspaceId, $labelType]
        );
        
        return [
            'total_samples' => (int) ($stats['total_samples'] ?? 0),
            'positive_samples' => (int) ($stats['positive_samples'] ?? 0),
            'negative_samples' => (int) ($stats['negative_samples'] ?? 0),
            'positive_rate' => (int) ($stats['total_samples'] ?? 0) > 0 
                ? ((int) ($stats['positive_samples'] ?? 0) / (int) ($stats['total_samples'] ?? 0)) 
                : 0,
            'earliest_snapshot' => $stats['earliest_snapshot'] ?? null,
            'latest_snapshot' => $stats['latest_snapshot'] ?? null
        ];
    }
}
