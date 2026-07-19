<?php
/**
 * Card Component Library
 */

/**
 * Render a card component
 */
function renderCard($title, $content, $actions = null, $class = '') {
    ?>
    <div class="card <?php echo $class; ?>">
        <?php if ($title): ?>
            <div class="card-header"><?php echo htmlspecialchars($title); ?></div>
        <?php endif; ?>
        <div class="card-body">
            <?php echo $content; ?>
        </div>
        <?php if ($actions): ?>
            <div style="margin-top: var(--spacing-sm);">
                <?php echo $actions; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render a stats card
 */
function renderStatsCard($number, $label, $class = '') {
    ?>
    <div class="stats-card card <?php echo $class; ?>">
        <div class="stats-number"><?php echo htmlspecialchars($number); ?></div>
        <div class="stats-label"><?php echo htmlspecialchars($label); ?></div>
    </div>
    <?php
}

/**
 * Render a contact card
 */
function renderContactCard($contact, $actions = null) {
    $initials = strtoupper(substr($contact['first_name'], 0, 1) . substr($contact['last_name'] ?? '', 0, 1));
    ?>
    <div class="contact-card card">
        <div class="contact-avatar"><?php echo $initials; ?></div>
        <div class="contact-info">
            <div class="contact-name">
                <?php echo htmlspecialchars($contact['first_name'] . ' ' . ($contact['last_name'] ?? '')); ?>
            </div>
            <div class="contact-email"><?php echo htmlspecialchars($contact['email'] ?? ''); ?></div>
            <?php if (!empty($contact['company'])): ?>
                <div style="color: var(--charcoal-grey); font-size: 0.9rem; margin-top: 4px;">
                    <?php echo htmlspecialchars($contact['company']); ?>
                </div>
            <?php endif; ?>
        </div>
        <?php if ($actions): ?>
            <div class="contact-actions">
                <?php echo $actions; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
}
