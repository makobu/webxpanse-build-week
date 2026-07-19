<?php
$pluginQuickStart = is_array($pluginQuickStart ?? null) ? $pluginQuickStart : [];
$quickStartSteps = array_values(array_filter(
    (array) ($pluginQuickStart['steps'] ?? []),
    static fn($step): bool => is_array($step) && trim((string) ($step['label'] ?? '')) !== ''
));

if ($pluginQuickStart !== [] && $quickStartSteps !== []):
    $quickStartKey = preg_replace('/[^a-z0-9_-]+/i', '-', (string) ($pluginQuickStart['key'] ?? 'marketing-product')) ?: 'marketing-product';
    $quickStartId = 'plugin-quick-start-' . strtolower(trim($quickStartKey, '-'));
    $quickStartCompleted = count(array_filter($quickStartSteps, static fn(array $step): bool => !empty($step['complete'])));
    $quickStartCurrentIndex = null;
    foreach ($quickStartSteps as $stepIndex => $quickStartStep) {
        if (empty($quickStartStep['complete'])) {
            $quickStartCurrentIndex = $stepIndex;
            break;
        }
    }
    $quickStartAllComplete = $quickStartCurrentIndex === null;
    if ($quickStartAllComplete) {
        $quickStartCurrentIndex = max(0, count($quickStartSteps) - 1);
    }
    $quickStartCurrent = (array) ($quickStartSteps[$quickStartCurrentIndex] ?? []);
    $quickStartAction = $quickStartAllComplete
        ? (array) ($pluginQuickStart['completion_action'] ?? $quickStartCurrent)
        : $quickStartCurrent;
    $quickStartActionHref = trim((string) ($quickStartAction['href'] ?? '#'));
    $quickStartActionLabel = trim((string) ($quickStartAction['action_label'] ?? $quickStartAction['label'] ?? 'Continue'));
    $quickStartProgressLabel = $quickStartCompleted . ' of ' . count($quickStartSteps) . ' complete';
?>
<div class="plugin-quick-start-wrap" data-workspace-guide data-guide-key="<?php echo htmlspecialchars($quickStartKey, ENT_QUOTES, 'UTF-8'); ?>">
    <section
        class="plugin-quick-start <?php echo $quickStartAllComplete ? 'is-complete' : ''; ?>"
        id="<?php echo htmlspecialchars($quickStartId, ENT_QUOTES, 'UTF-8'); ?>"
        aria-labelledby="<?php echo htmlspecialchars($quickStartId, ENT_QUOTES, 'UTF-8'); ?>-title"
        data-workspace-guide-content
    >
        <div class="plugin-quick-start-intro">
            <div class="plugin-quick-start-meta">
                <span>Your first win</span>
                <strong
                    role="progressbar"
                    aria-label="<?php echo $quickStartCompleted; ?> of <?php echo count($quickStartSteps); ?> quick-start steps complete"
                    aria-valuemin="0"
                    aria-valuemax="<?php echo count($quickStartSteps); ?>"
                    aria-valuenow="<?php echo $quickStartCompleted; ?>"
                ><?php echo htmlspecialchars($quickStartProgressLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
            </div>
            <h2 id="<?php echo htmlspecialchars($quickStartId, ENT_QUOTES, 'UTF-8'); ?>-title"><?php echo htmlspecialchars((string) ($pluginQuickStart['outcome'] ?? 'Complete your first outcome'), ENT_QUOTES, 'UTF-8'); ?></h2>
        </div>

        <ol class="plugin-quick-start-steps">
            <?php foreach ($quickStartSteps as $stepIndex => $quickStartStep): ?>
                <?php
                    $quickStartStepComplete = !empty($quickStartStep['complete']);
                    $quickStartStepCurrent = !$quickStartAllComplete && $stepIndex === $quickStartCurrentIndex;
                    $quickStartStepState = $quickStartStepComplete ? 'is-complete' : ($quickStartStepCurrent ? 'is-current' : 'is-upcoming');
                ?>
                <li class="<?php echo $quickStartStepState; ?>"<?php echo $quickStartStepCurrent ? ' aria-current="step"' : ''; ?>>
                    <span class="plugin-quick-start-number" aria-hidden="true">
                        <?php if ($quickStartStepComplete): ?><i class="fas fa-check"></i><?php else: ?><?php echo $stepIndex + 1; ?><?php endif; ?>
                    </span>
                    <span><?php echo htmlspecialchars((string) $quickStartStep['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                </li>
            <?php endforeach; ?>
        </ol>

        <div class="plugin-quick-start-actions">
            <a class="btn-premium-primary plugin-quick-start-cta" href="<?php echo htmlspecialchars($quickStartActionHref !== '' ? $quickStartActionHref : '#', ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($quickStartActionLabel !== '' ? $quickStartActionLabel : 'Continue', ENT_QUOTES, 'UTF-8'); ?>
            </a>
            <button
                class="plugin-quick-start-toggle"
                type="button"
                aria-controls="<?php echo htmlspecialchars($quickStartId, ENT_QUOTES, 'UTF-8'); ?>"
                aria-expanded="true"
                data-workspace-guide-hide
            >Hide guide</button>
        </div>
    </section>
    <button
        class="plugin-quick-start-restore"
        type="button"
        aria-controls="<?php echo htmlspecialchars($quickStartId, ENT_QUOTES, 'UTF-8'); ?>"
        aria-expanded="false"
        data-workspace-guide-show
        hidden
    ><i class="fas fa-route" aria-hidden="true"></i> Show quick start</button>
</div>
<?php endif; ?>
