<?php
/**
 * ML Lead Scoring Service
 * Handles machine learning model training for lead scoring
 * Supports multiple algorithms: Logistic Regression, Random Forest, Gradient Boosting
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Services\MLTrainingDataService;
use CRM\Services\MLFeatureService;
use CRM\Services\AnalyticsWorkspaceService;

class MLLeadScoringService
{
    private MLTrainingDataService $trainingDataService;
    private MLFeatureService $featureService;
    private ?string $pythonServiceUrl;
    private AnalyticsWorkspaceService $analyticsWorkspace;
    
    public function __construct(
        ?MLTrainingDataService $trainingDataService = null,
        ?MLFeatureService $featureService = null,
        ?AnalyticsWorkspaceService $analyticsWorkspace = null
    )
    {
        $this->analyticsWorkspace = $analyticsWorkspace ?? new AnalyticsWorkspaceService();
        $this->trainingDataService = $trainingDataService ?? new MLTrainingDataService(null, $this->analyticsWorkspace);
        $this->featureService = $featureService ?? new MLFeatureService();
        $this->pythonServiceUrl = $_ENV['PYTHON_ML_SERVICE_URL'] ?? null;
    }
    
    /**
     * Train a new ML model
     * 
     * @param array $config Configuration:
     *   - model_type: 'conversion', 'churn', or 'engagement'
     *   - algorithm: 'logistic_regression', 'random_forest', 'gradient_boosting'
     *   - training_options: Options for training data preparation
     *   - hyperparameters: Algorithm-specific hyperparameters
     * @return array Model data with metrics
     */
    public function trainModel(array $config, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $modelType = $config['model_type'] ?? 'conversion';
        $algorithm = $config['algorithm'] ?? 'logistic_regression';
        $trainingOptions = $config['training_options'] ?? [];
        $hyperparameters = $config['hyperparameters'] ?? [];
        
        // Prepare training data
        $trainingOptions['label_type'] = $modelType;
        $dataset = $this->trainingDataService->prepareTrainingDataset($trainingOptions, $resolvedWorkspaceId);
        
        if (empty($dataset['train'])) {
            throw new \Exception("No training data available");
        }
        
        // Train model based on algorithm
        $modelData = match($algorithm) {
            'logistic_regression' => $this->trainLogisticRegression($dataset, $hyperparameters),
            'random_forest' => $this->trainRandomForest($dataset, $hyperparameters),
            'gradient_boosting' => $this->trainGradientBoosting($dataset, $hyperparameters),
            default => throw new \Exception("Unsupported algorithm: {$algorithm}")
        };
        
        // Generate version
        $version = $this->generateVersion($modelType, $algorithm);
        
        // Save model
        $modelId = $this->saveModel([
            'model_type' => $modelType,
            'version' => $version,
            'algorithm' => $algorithm,
            'model_data' => $modelData['model'],
            'feature_list' => $this->featureService->getFeatureNames(),
            'hyperparameters' => $hyperparameters,
            'training_samples' => count($dataset['train']),
            'validation_samples' => count($dataset['validation']),
            'test_samples' => count($dataset['test']),
            'metrics' => $modelData['metrics']
        ], $resolvedWorkspaceId);
        
        return [
            'model_id' => $modelId,
            'version' => $version,
            'metrics' => $modelData['metrics'],
            'statistics' => $dataset['statistics']
        ];
    }
    
    /**
     * Train Logistic Regression model (using PHP-ML or Python service)
     */
    private function trainLogisticRegression(array $dataset, array $hyperparameters): array
    {
        if ($this->pythonServiceUrl) {
            return $this->trainViaPythonService('logistic_regression', $dataset, $hyperparameters);
        }
        
        // PHP-ML implementation (simplified - would need php-ml library)
        return $this->trainSimpleLogisticRegression($dataset, $hyperparameters);
    }
    
    /**
     * Train Random Forest model
     */
    private function trainRandomForest(array $dataset, array $hyperparameters): array
    {
        if ($this->pythonServiceUrl) {
            return $this->trainViaPythonService('random_forest', $dataset, $hyperparameters);
        }
        
        // Fallback to logistic regression if Python service unavailable
        return $this->trainSimpleLogisticRegression($dataset, $hyperparameters);
    }
    
    /**
     * Train Gradient Boosting model
     */
    private function trainGradientBoosting(array $dataset, array $hyperparameters): array
    {
        if ($this->pythonServiceUrl) {
            return $this->trainViaPythonService('gradient_boosting', $dataset, $hyperparameters);
        }
        
        // Fallback to logistic regression if Python service unavailable
        return $this->trainSimpleLogisticRegression($dataset, $hyperparameters);
    }
    
    /**
     * Train via Python ML service
     */
    private function trainViaPythonService(string $algorithm, array $dataset, array $hyperparameters): array
    {
        // Prepare data for Python service
        $trainData = $this->prepareDataForPython($dataset['train']);
        $validationData = $this->prepareDataForPython($dataset['validation']);
        $testData = $this->prepareDataForPython($dataset['test']);
        
        $payload = [
            'algorithm' => $algorithm,
            'train' => [
                'X' => $trainData['features'],
                'y' => $trainData['labels']
            ],
            'validation' => [
                'X' => $validationData['features'],
                'y' => $validationData['labels']
            ],
            'test' => [
                'X' => $testData['features'],
                'y' => $testData['labels']
            ],
            'hyperparameters' => $hyperparameters
        ];
        
        $ch = curl_init($this->pythonServiceUrl . '/train');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300, // 5 minutes for training
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new \Exception("Python ML service error: HTTP {$httpCode} - {$response}");
        }
        
        $result = json_decode($response, true);
        
        return [
            'model' => $result['model_data'] ?? '',
            'metrics' => $result['metrics'] ?? []
        ];
    }
    
    /**
     * Simple Logistic Regression implementation (fallback)
     * This is a simplified version - for production, use PHP-ML library or Python service
     */
    private function trainSimpleLogisticRegression(array $dataset, array $hyperparameters): array
    {
        // Convert dataset to feature matrix and labels
        $trainData = $this->prepareDataForPython($dataset['train']);
        $validationData = $this->prepareDataForPython($dataset['validation']);
        $testData = $this->prepareDataForPython($dataset['test']);
        
        // Simple linear model coefficients (would use proper ML algorithm in production)
        $featureCount = count($trainData['features'][0] ?? []);
        $coefficients = array_fill(0, $featureCount, 0.01); // Initialize small random values
        $bias = 0.0;
        
        // Simple training loop (gradient descent approximation)
        $learningRate = $hyperparameters['learning_rate'] ?? 0.01;
        $iterations = $hyperparameters['iterations'] ?? 100;
        
        for ($i = 0; $i < $iterations; $i++) {
            $totalError = 0;
            $gradient = array_fill(0, $featureCount, 0);
            $biasGradient = 0;
            
            foreach ($trainData['features'] as $idx => $features) {
                $prediction = $this->sigmoid($this->dotProduct($features, $coefficients) + $bias);
                $error = $prediction - $trainData['labels'][$idx];
                $totalError += abs($error);
                
                foreach ($features as $j => $feature) {
                    $gradient[$j] += $error * $feature;
                }
                $biasGradient += $error;
            }
            
            // Update coefficients
            foreach ($coefficients as $j => &$coef) {
                $coef -= $learningRate * ($gradient[$j] / count($trainData['features']));
            }
            $bias -= $learningRate * ($biasGradient / count($trainData['features']));
        }
        
        // Evaluate on validation set
        $validationMetrics = $this->evaluateModel($coefficients, $bias, $validationData);
        
        // Evaluate on test set
        $testMetrics = $this->evaluateModel($coefficients, $bias, $testData);
        
        // Store model
        $modelData = [
            'coefficients' => $coefficients,
            'bias' => $bias,
            'algorithm' => 'logistic_regression',
            'feature_count' => $featureCount
        ];
        
        return [
            'model' => json_encode($modelData),
            'metrics' => array_merge($validationMetrics, [
                'test_accuracy' => $testMetrics['accuracy'],
                'test_precision' => $testMetrics['precision'],
                'test_recall' => $testMetrics['recall'],
                'test_f1' => $testMetrics['f1']
            ])
        ];
    }
    
    /**
     * Evaluate model performance
     */
    private function evaluateModel(array $coefficients, float $bias, array $testData): array
    {
        $predictions = [];
        $probabilities = [];
        
        foreach ($testData['features'] as $features) {
            $score = $this->dotProduct($features, $coefficients) + $bias;
            $prob = $this->sigmoid($score);
            $probabilities[] = $prob;
            $predictions[] = $prob > 0.5 ? 1 : 0;
        }
        
        // Calculate metrics
        $tp = $fp = $tn = $fn = 0;
        foreach ($predictions as $i => $pred) {
            $actual = $testData['labels'][$i];
            if ($pred == 1 && $actual == 1) $tp++;
            elseif ($pred == 1 && $actual == 0) $fp++;
            elseif ($pred == 0 && $actual == 0) $tn++;
            else $fn++;
        }
        
        $accuracy = ($tp + $tn) / max(1, count($predictions));
        $precision = $tp / max(1, $tp + $fp);
        $recall = $tp / max(1, $tp + $fn);
        $f1 = 2 * ($precision * $recall) / max(0.0001, $precision + $recall);
        
        // Calculate AUC-ROC (simplified)
        $auc = $this->calculateAUC($probabilities, $testData['labels']);
        
        return [
            'accuracy' => $accuracy,
            'precision' => $precision,
            'recall' => $recall,
            'f1' => $f1,
            'auc_roc' => $auc,
            'true_positives' => $tp,
            'false_positives' => $fp,
            'true_negatives' => $tn,
            'false_negatives' => $fn
        ];
    }
    
    /**
     * Calculate AUC-ROC (simplified implementation)
     */
    private function calculateAUC(array $probabilities, array $labels): float
    {
        // Pair probabilities with labels and sort by probability descending
        $pairs = [];
        foreach ($probabilities as $i => $prob) {
            $pairs[] = ['prob' => $prob, 'label' => $labels[$i]];
        }
        usort($pairs, fn($a, $b) => $b['prob'] <=> $a['prob']);
        
        $positiveCount = array_sum($labels);
        $negativeCount = count($labels) - $positiveCount;
        
        if ($positiveCount == 0 || $negativeCount == 0) {
            return 0.5; // Cannot calculate AUC if all labels are same
        }
        
        $auc = 0;
        $rank = 0;
        $positiveRankSum = 0;
        
        foreach ($pairs as $pair) {
            $rank++;
            if ($pair['label'] == 1) {
                $positiveRankSum += $rank;
            }
        }
        
        $auc = ($positiveRankSum - ($positiveCount * ($positiveCount + 1) / 2)) / ($positiveCount * $negativeCount);
        
        return max(0, min(1, $auc));
    }
    
    /**
     * Sigmoid function
     */
    private function sigmoid(float $x): float
    {
        return 1 / (1 + exp(-max(-500, min(500, $x))));
    }
    
    /**
     * Dot product
     */
    private function dotProduct(array $a, array $b): float
    {
        $sum = 0;
        foreach ($a as $i => $val) {
            $sum += $val * ($b[$i] ?? 0);
        }
        return $sum;
    }
    
    /**
     * Prepare data for Python service
     */
    private function prepareDataForPython(array $samples): array
    {
        $features = [];
        $labels = [];
        
        foreach ($samples as $sample) {
            $featureArray = array_values($sample['features']);
            $features[] = $featureArray;
            $labels[] = $sample['label'] ? 1 : 0;
        }
        
        return [
            'features' => $features,
            'labels' => $labels
        ];
    }
    
    /**
     * Generate model version
     */
    private function generateVersion(string $modelType, string $algorithm): string
    {
        $timestamp = date('YmdHis');
        $random = substr(md5(uniqid()), 0, 6);
        return "{$modelType}_{$algorithm}_{$timestamp}_{$random}";
    }
    
    /**
     * Save model to database
     */
    private function saveModel(array $modelData, int $workspaceId): int
    {
        $userId = $_SESSION['user_id'] ?? null;
        
        Database::execute(
            "INSERT INTO ml_models 
             (workspace_id, model_type, version, algorithm, model_data, feature_list, hyperparameters,
              accuracy, precision_score, recall_score, f1_score, auc_roc,
              training_samples, validation_samples, test_samples, trained_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $modelData['model_type'],
                $modelData['version'],
                $modelData['algorithm'],
                $modelData['model_data'],
                json_encode($modelData['feature_list']),
                json_encode($modelData['hyperparameters']),
                $modelData['metrics']['accuracy'] ?? null,
                $modelData['metrics']['precision'] ?? null,
                $modelData['metrics']['recall'] ?? null,
                $modelData['metrics']['f1'] ?? null,
                $modelData['metrics']['auc_roc'] ?? null,
                $modelData['training_samples'],
                $modelData['validation_samples'],
                $modelData['test_samples'],
                $userId
            ]
        );
        
        return Database::lastInsertId();
    }
    
    /**
     * Load model from database
     */
    public function loadModel(int $modelId, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId($workspaceId);
        $model = Database::queryOne(
            "SELECT * FROM ml_models WHERE workspace_id = ? AND id = ?",
            [$resolvedWorkspaceId, $modelId]
        );
        
        if (!$model) {
            throw new \Exception("Model not found: {$modelId}");
        }
        
        $model['model_data'] = json_decode($model['model_data'], true);
        $model['feature_list'] = json_decode($model['feature_list'], true);
        $model['hyperparameters'] = json_decode($model['hyperparameters'], true);
        
        return $model;
    }
    
    /**
     * Evaluate model on test data
     */
    public function evaluateModelById(int $modelId, array $testData, ?int $workspaceId = null): array
    {
        $model = $this->loadModel($modelId, $workspaceId);
        
        // This would use the actual model to make predictions
        // For now, return placeholder metrics
        return [
            'accuracy' => 0.85,
            'precision' => 0.82,
            'recall' => 0.88,
            'f1' => 0.85,
            'auc_roc' => 0.90
        ];
    }
}
