<?php
$workSurfaceGuidance = is_array($workSurfaceGuidance ?? null) ? $workSurfaceGuidance : [];
if (!empty($workSurfaceGuidance['show_guidance'])):
    $primaryAction = is_array($workSurfaceGuidance['primary_action'] ?? null) ? $workSurfaceGuidance['primary_action'] : [];
    $primaryHref = trim((string) ($primaryAction['href'] ?? ''));
    $primaryLabel = trim((string) ($primaryAction['label'] ?? ''));
    $guidanceCsrfToken = isset($workSurfaceGuidanceCsrfToken)
        ? (string) $workSurfaceGuidanceCsrfToken
        : (isset($csrfToken) ? (string) $csrfToken : \CRM\Security::getCsrfToken());
    $metadata = [
        'mode' => (string) ($workSurfaceGuidance['mode'] ?? ''),
        'surface' => (string) ($workSurfaceGuidance['surface'] ?? ''),
        'source' => (string) ($workSurfaceGuidance['source'] ?? ''),
        'action' => (string) ($workSurfaceGuidance['action'] ?? ''),
        'gap' => (string) ($workSurfaceGuidance['gap'] ?? ''),
        'primary_action_key' => (string) ($primaryAction['key'] ?? ''),
        'cta_present' => $primaryHref !== '' && $primaryLabel !== '',
        'source_type' => (string) ($workSurfaceGuidance['source_type'] ?? 'deterministic'),
    ];
?>
<section
    class="beginner-work-guidance"
    data-work-surface-guidance
    data-work-surface-metadata="<?php echo htmlspecialchars(json_encode($metadata, JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8'); ?>"
>
    <div class="beginner-work-guidance-main">
        <p class="beginner-work-guidance-eyebrow">Next work step</p>
        <h2><?php echo htmlspecialchars((string) ($workSurfaceGuidance['goal'] ?? 'Next step')); ?></h2>
        <p><?php echo htmlspecialchars((string) ($workSurfaceGuidance['reason'] ?? '')); ?></p>
        <?php if (!empty($workSurfaceGuidance['secondary_hint'])): ?>
            <span class="beginner-work-guidance-hint"><?php echo htmlspecialchars((string) $workSurfaceGuidance['secondary_hint']); ?></span>
        <?php endif; ?>
    </div>
    <div class="beginner-work-guidance-actions">
        <?php if ($primaryHref !== '' && $primaryLabel !== ''): ?>
            <a
                class="btn-premium-primary beginner-work-guidance-cta"
                href="<?php echo htmlspecialchars($primaryHref); ?>"
                data-work-surface-guidance-cta
            >
                <?php echo htmlspecialchars($primaryLabel); ?>
            </a>
        <?php endif; ?>
        <?php if (!empty($workSurfaceGuidance['status_label'])): ?>
            <span class="beginner-work-guidance-status"><?php echo htmlspecialchars((string) $workSurfaceGuidance['status_label']); ?></span>
        <?php endif; ?>
    </div>
</section>
<?php
if (empty($GLOBALS['beginner_work_surface_guidance_tracking_loaded'])):
    $GLOBALS['beginner_work_surface_guidance_tracking_loaded'] = true;
?>
<script>
(function () {
    var endpoint = '../api/outcomes/events.php';
    var csrfToken = <?php echo json_encode($guidanceCsrfToken); ?>;

    function metadataFor(section) {
        try {
            return JSON.parse(section.getAttribute('data-work-surface-metadata') || '{}') || {};
        } catch (error) {
            return {};
        }
    }

    function track(eventKey, metadata) {
        if (!csrfToken || !eventKey) {
            return;
        }

        try {
            fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    event_key: eventKey,
                    metadata: metadata || {}
                })
            }).catch(function () {});
        } catch (error) {}
    }

    function runWhenIdle(callback) {
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(callback, { timeout: 1500 });
            return;
        }
        window.setTimeout(callback, 700);
    }

    runWhenIdle(function () {
        document.querySelectorAll('[data-work-surface-guidance]').forEach(function (section) {
            track('work_surface.guidance.viewed', metadataFor(section));
        });
    });

    document.addEventListener('click', function (event) {
        var target = event.target && event.target.closest
            ? event.target.closest('[data-work-surface-guidance-cta]')
            : null;
        if (!target) {
            return;
        }
        var section = target.closest('[data-work-surface-guidance]');
        var metadata = section ? metadataFor(section) : {};
        metadata.href = target.getAttribute('href') || '';
        track('work_surface.guidance.clicked', metadata);
    });
})();
</script>
<?php endif; ?>
<?php endif; ?>
