<?php
/**
 * ML Model Retraining CLI
 * Automated retraining pipeline for ML models
 * 
 * Usage:
 *   php cli/ml_model_retrain.php --model=conversion --days=90 --auto-promote
 *   php cli/ml_model_retrain.php --model=churn --days=180
 *   php cli/ml_model_retrain.php --all --days=90
 */

require_once __DIR__ . '/../config/bootstrap.php';

use CRM\Services\MLLeadScoringService;
use CRM\Services\MLModelEvaluator;
use CRM\Modules\MLModelManager;
use CRM\Services\MLOutcomeTracker;

// Parse command line arguments
$options = getopt('', [
    'model:',
    'days:',
    'auto-promote',
    'all',
    'algorithm:',
    'help'
]);

if (isset($options['help'])) {
    echo "ML Model Retraining CLI\n";
    echo "Usage:\n";
    echo "  php cli/ml_model_retrain.php [options]\n\n";
    echo "Options:\n";
    echo "  --model=TYPE        Model type (conversion, churn, engagement)\n";
    echo "  --days=N            Days of historical data to use (default: 90)\n";
    echo "  --algorithm=ALG     Algorithm (logistic_regression, random_forest, gradient_boosting)\n";
    echo "  --auto-promote      Automatically promote if new model is better\n";
    echo "  --all               Retrain all model types\n";
    echo "  --help              Show this help message\n\n";
    echo "Examples:\n";
    echo "  php cli/ml_model_retrain.php --model=conversion --days=90 --auto-promote\n";
    echo "  php cli/ml_model_retrain.php --all --days=180\n";
    exit(0);
}

$modelTypes = isset($options['all']) 
    ? ['conversion', 'churn', 'engagement']
    : [($options['model'] ?? 'conversion')];

$daysBack = (int) ($options['days'] ?? 90);
$algorithm = $options['algorithm'] ?? 'logistic_regression';
$autoPromote = isset($options['auto-promote']);

$trainingService = new MLLeadScoringService();
$evaluator = new MLModelEvaluator();
$modelManager = new MLModelManager();
$outcomeTracker = new MLOutcomeTracker();

echo "=== ML Model Retraining Pipeline ===\n\n";

foreach ($modelTypes as $modelType) {
    echo "Training {$modelType} model...\n";
    echo "  Days back: {$daysBack}\n";
    echo "  Algorithm: {$algorithm}\n";
    echo "  Auto-promote: " . ($autoPromote ? 'Yes' : 'No') . "\n\n";
    
    try {
        // Step 1: Prepare training data
        echo "Step 1: Preparing training data...\n";
        $trainingOptions = [
            'days_back' => $daysBack,
            'min_samples' => 100,
            'balance_classes' => true,
            'label_type' => $modelType
        ];
        
        // Step 2: Train model
        echo "Step 2: Training model...\n";
        $result = $trainingService->trainModel([
            'model_type' => $modelType,
            'algorithm' => $algorithm,
            'training_options' => $trainingOptions,
            'hyperparameters' => [
                'learning_rate' => 0.01,
                'iterations' => 100
            ]
        ]);
        
        $newModelId = $result['model_id'];
        echo "  Model trained successfully!\n";
        echo "  Model ID: {$newModelId}\n";
        echo "  Version: {$result['version']}\n";
        echo "  Metrics:\n";
        foreach ($result['metrics'] as $metric => $value) {
            echo "    {$metric}: " . round($value, 4) . "\n";
        }
        echo "\n";
        
        // Step 3: Compare with current model
        $currentModel = $modelManager->getActiveModel($modelType);
        
        if ($currentModel) {
            echo "Step 3: Comparing with current model...\n";
            $comparison = $modelManager->compareModels($currentModel['id'], $newModelId);
            
            echo "  Current model AUC-ROC: " . round($currentModel['auc_roc'] ?? 0, 4) . "\n";
            echo "  New model AUC-ROC: " . round($result['metrics']['auc_roc'] ?? 0, 4) . "\n";
            
            $improvement = ($comparison['improvements']['auc_roc']['percentage'] ?? 0);
            echo "  Improvement: " . round($improvement, 2) . "%\n";
            echo "  Winner: " . ($comparison['winner'] === 'model2' ? 'New Model' : 'Current Model') . "\n\n";
            
            // Step 4: Auto-promote if enabled and better
            if ($autoPromote && $comparison['winner'] === 'model2') {
                echo "Step 4: Auto-promoting new model...\n";
                $modelManager->promoteModel($newModelId);
                echo "  New model promoted to production!\n\n";
            } elseif ($autoPromote) {
                echo "Step 4: New model not better, keeping current model\n\n";
            } else {
                echo "Step 4: Manual promotion required\n";
                echo "  Run: php cli/ml_model_promote.php --model-id={$newModelId}\n\n";
            }
        } else {
            echo "Step 3: No current model found, promoting new model...\n";
            $modelManager->promoteModel($newModelId);
            echo "  New model promoted to production!\n\n";
        }
        
        // Step 5: Validate predictions
        echo "Step 5: Validating predictions...\n";
        $validation = $outcomeTracker->validatePredictions($modelType, $daysBack);
        echo "  Total validated: {$validation['total_validated']}\n";
        echo "  Accuracy: " . round($validation['accuracy'] * 100, 2) . "%\n";
        echo "  MAE: " . round($validation['mae'], 4) . "\n";
        echo "  RMSE: " . round($validation['rmse'], 4) . "\n\n";
        
        echo "✓ {$modelType} model retraining completed successfully!\n\n";
        
    } catch (\Exception $e) {
        echo "✗ Error training {$modelType} model: " . $e->getMessage() . "\n";
        echo "  Stack trace: " . $e->getTraceAsString() . "\n\n";
        continue;
    }
}

echo "=== Retraining Pipeline Complete ===\n";

// Optional: Auto-detect outcomes
echo "\nAuto-detecting outcomes...\n";
try {
    $outcomeTracker->autoDetectConversions();
    echo "  Conversions detected\n";
    
    $outcomeTracker->autoDetectChurn();
    echo "  Churn detected\n";
} catch (\Exception $e) {
    echo "  Error detecting outcomes: " . $e->getMessage() . "\n";
}

echo "\nDone!\n";
