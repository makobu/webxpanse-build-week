<?php
/** @var string $builderMode */
/** @var string $builderTitle */
/** @var string $builderSubtitle */
/** @var string $submitLabel */
/** @var array<string, array<string, string>> $signatureTemplates */
/** @var array<string, mixed> $builderConfig */
$templateIcons = [
    'professional' => 'fa-regular fa-address-card',
    'sales' => 'fa-solid fa-briefcase',
    'minimal' => 'fa-solid fa-align-left',
];
?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/email-signatures.css') . '?v=' . APP_VERSION . '-studio-v4'); ?>">

<main class="signature-builder-page" aria-labelledby="signature-builder-title">
    <header class="signature-builder-heading">
        <div>
            <a class="signature-back-link" href="<?php echo htmlspecialchars($basePath); ?>/email_signatures.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Signatures</a>
            <h1 class="signature-builder-title" id="signature-builder-title"><?php echo htmlspecialchars($builderTitle); ?></h1>
            <p class="signature-builder-subtitle"><?php echo htmlspecialchars($builderSubtitle); ?></p>
        </div>
    </header>

    <?php if (!empty($error)): ?>
        <div class="signature-alert signature-alert-error" role="alert">
            <i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($builderSuccess)): ?>
        <div class="signature-alert signature-alert-success" role="status">
            <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
            <span><?php echo htmlspecialchars($builderSuccess); ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" id="signature-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo \CRM\Security::getCsrfToken(); ?>">
        <input type="hidden" name="template_style" id="template_style" value="<?php echo htmlspecialchars($selectedTemplateKey); ?>">
        <input type="hidden" name="is_default" id="is_default_input" value="<?php echo $initialIsDefault ? '1' : '0'; ?>">
        <?php if ($builderMode === 'edit'): ?>
            <input type="hidden" name="logo_path" id="logo_path" value="<?php echo htmlspecialchars((string) ($sigSettings['logo_path'] ?? '')); ?>">
        <?php endif; ?>

        <div class="signature-builder-grid">
            <aside class="signature-builder-panel signature-builder-config" aria-label="Signature settings">
                <div class="signature-config-section signature-field">
                    <label for="signature_name">Signature name</label>
                    <input type="text" id="signature_name" name="name" required maxlength="255" autocomplete="off" value="<?php echo htmlspecialchars($initialName); ?>">
                    <p class="signature-field-hint">A clear internal name such as “Sales” or “Client replies”.</p>
                </div>

                <div class="signature-config-section">
                    <div class="signature-config-title">Choose a starting style</div>
                    <div class="signature-template-options">
                        <?php foreach ($signatureTemplates as $template): ?>
                            <button type="button" class="signature-template-option<?php echo $selectedTemplateKey === $template['key'] ? ' is-selected' : ''; ?>" data-builder-template="<?php echo htmlspecialchars($template['key']); ?>" aria-pressed="<?php echo $selectedTemplateKey === $template['key'] ? 'true' : 'false'; ?>">
                                <i class="<?php echo htmlspecialchars($templateIcons[$template['key']]); ?>" aria-hidden="true"></i>
                                <?php echo htmlspecialchars($template['name']); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="signature-config-section">
                    <div class="signature-config-title">Brand colours</div>
                    <div class="signature-colors">
                        <label>
                            <span class="signature-field-hint">Accent colour</span>
                            <span class="signature-color-field"><input type="color" id="accent_color" name="accent_color" value="<?php echo htmlspecialchars($initialAccent); ?>"><output data-color-output="accent_color"><?php echo htmlspecialchars(strtoupper($initialAccent)); ?></output></span>
                        </label>
                        <label>
                            <span class="signature-field-hint">Text colour</span>
                            <span class="signature-color-field"><input type="color" id="text_color" name="text_color" value="<?php echo htmlspecialchars($initialText); ?>"><output data-color-output="text_color"><?php echo htmlspecialchars(strtoupper($initialText)); ?></output></span>
                        </label>
                    </div>
                </div>

                <div class="signature-config-section">
                    <div class="signature-config-title">Email-safe typography</div>
                    <div class="signature-typography-fields">
                        <label class="signature-field">
                            <span>Font family</span>
                            <select id="font_family" name="font_family">
                                <?php foreach ((array) ($builderConfig['fontOptions'] ?? []) as $option): ?>
                                    <option value="<?php echo htmlspecialchars((string) $option['value']); ?>"<?php echo $initialFont === (string) $option['value'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $option['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="signature-field">
                            <span>Text size</span>
                            <select id="font_size_preset" name="font_size_preset">
                                <?php foreach ((array) ($builderConfig['fontSizeOptions'] ?? []) as $option): ?>
                                    <option value="<?php echo htmlspecialchars((string) $option['value']); ?>"<?php echo $initialFontSize === (string) $option['value'] ? ' selected' : ''; ?>><?php echo htmlspecialchars((string) $option['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <p class="signature-field-hint">These choices use dependable fonts supported by major email clients.</p>
                </div>

                <div class="signature-config-section">
                    <div class="signature-default-row">
                        <div><strong>Default signature</strong><span>Automatically include this signature in new emails.</span></div>
                        <button type="button" class="signature-switch" data-default-switch role="switch" aria-label="Set as default signature" aria-checked="<?php echo $initialIsDefault ? 'true' : 'false'; ?>"></button>
                    </div>

                    <?php if ($builderMode === 'edit'): ?>
                        <div class="signature-field-heading" style="margin-top: 1rem;"><span>Company logo</span></div>
                        <div class="signature-logo-zone" id="logo-upload-zone" role="button" tabindex="0" aria-label="Upload company logo">
                            <div class="signature-logo-preview" id="logo-preview" <?php echo empty($logoUrl) ? 'hidden' : ''; ?>>
                                <img id="logo-img" src="<?php echo htmlspecialchars((string) ($logoUrl ?? '')); ?>" alt="Company logo">
                                <button type="button" id="logo-remove" class="signature-button signature-button-danger signature-button-small">Remove logo</button>
                            </div>
                            <div id="logo-placeholder" <?php echo !empty($logoUrl) ? 'hidden' : ''; ?>>
                                <i class="fa-regular fa-image" aria-hidden="true"></i>
                                <p>Upload PNG, JPG, GIF, or WebP · max 2MB</p>
                            </div>
                            <input type="file" id="logo-file" accept="image/jpeg,image/png,image/gif,image/webp" hidden>
                        </div>
                    <?php else: ?>
                        <div class="signature-info-note"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>You can add your company logo and inline images after saving the signature for the first time.</span></div>
                    <?php endif; ?>
                </div>
            </aside>

            <section class="signature-builder-panel signature-builder-editor" aria-labelledby="signature-content-heading">
                <div class="signature-draft-banner" data-draft-banner hidden>
                    <span>A newer local draft is available.</span>
                    <span class="signature-draft-actions"><button type="button" data-draft-restore>Restore</button><button type="button" data-draft-discard>Discard</button></span>
                </div>
                <div class="signature-panel-header"><h2 class="signature-panel-title" id="signature-content-heading">Signature content</h2></div>
                <div class="signature-editor-shell"><div id="signature-editor" aria-label="Signature content editor"></div></div>
                <textarea id="content_html" name="content_html" hidden><?php echo htmlspecialchars($initialContent); ?></textarea>
                <div class="signature-editor-status" data-builder-status>Ready to edit</div>
            </section>

            <aside class="signature-builder-panel signature-builder-preview" aria-labelledby="signature-live-preview-heading">
                <div class="signature-panel-header"><h2 class="signature-panel-title" id="signature-live-preview-heading">Live preview</h2></div>
                <div class="signature-preview-switches">
                    <div class="signature-segmented" aria-label="Preview width">
                        <button type="button" class="is-active" data-preview-device="desktop" aria-pressed="true"><i class="fa-solid fa-desktop" aria-hidden="true"></i> Desktop</button>
                        <button type="button" data-preview-device="mobile" aria-pressed="false"><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i> Mobile</button>
                    </div>
                    <div class="signature-segmented" aria-label="Inbox appearance">
                        <button type="button" class="is-active" data-preview-theme="light" aria-label="Light inbox" aria-pressed="true"><i class="fa-regular fa-sun" aria-hidden="true"></i></button>
                        <button type="button" data-preview-theme="dark" aria-label="Dark inbox" aria-pressed="false"><i class="fa-regular fa-moon" aria-hidden="true"></i></button>
                    </div>
                </div>
                <div class="signature-live-frame" data-live-frame>
                    <div class="signature-live-header"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i><div class="signature-live-icons"><i class="fa-regular fa-envelope" aria-hidden="true"></i><i class="fa-regular fa-clock" aria-hidden="true"></i><i class="fa-solid fa-ellipsis-vertical" aria-hidden="true"></i></div></div>
                    <div class="signature-live-meta"><span>From</span><strong>Alex Morgan &lt;alex@example.com&gt;</strong><span>To</span><strong>Jane Doe &lt;jane@example.com&gt;</strong><span>Subject</span><strong>Meeting follow-up</strong></div>
                    <div class="signature-live-body"><div class="signature-live-message">Hi Jane,<br><br>Thanks for taking the time to connect. Please let me know if you have any questions.<br><br>Best regards,</div><div class="signature-live-output" id="signature-live-output"></div></div>
                </div>
            </aside>
        </div>

        <div class="signature-builder-actionbar">
            <div class="signature-builder-actionbar-inner">
                <button type="button" class="signature-button signature-button-secondary" data-builder-reset><i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i> Reset</button>
                <div class="signature-builder-actions">
                    <a href="<?php echo htmlspecialchars($basePath); ?>/email_signatures.php" class="signature-button signature-button-secondary">Cancel</a>
                    <button type="submit" form="signature-form" class="signature-button signature-button-primary"><?php echo htmlspecialchars($submitLabel); ?></button>
                </div>
            </div>
        </div>
    </form>
</main>

<div class="signature-toast" data-signature-toast role="status" aria-live="polite"></div>
<script type="application/json" id="signature-builder-config"><?php echo json_encode($builderConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?></script>
