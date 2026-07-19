(function () {
    'use strict';

    var configNode = document.getElementById('signature-builder-config');
    var form = document.getElementById('signature-form');
    if (!configNode || !form) return;

    var config = {};
    try {
        config = JSON.parse(configNode.textContent || '{}');
    } catch (error) {
        return;
    }

    var nameInput = document.getElementById('signature_name');
    var contentInput = document.getElementById('content_html');
    var templateInput = document.getElementById('template_style');
    var accentInput = document.getElementById('accent_color');
    var textInput = document.getElementById('text_color');
    var fontInput = document.getElementById('font_family');
    var fontSizeInput = document.getElementById('font_size_preset');
    var defaultInput = document.getElementById('is_default_input');
    var defaultSwitch = document.querySelector('[data-default-switch]');
    var previewFrame = document.querySelector('[data-live-frame]');
    var previewOutput = document.getElementById('signature-live-output');
    var statusNode = document.querySelector('[data-builder-status]');
    var toast = document.querySelector('[data-signature-toast]');
    var draftBanner = document.querySelector('[data-draft-banner]');
    var templateButtons = Array.prototype.slice.call(document.querySelectorAll('[data-builder-template]'));
    var quill = null;
    var fallbackEditor = null;
    var draftTimer = null;
    var toastTimer = null;
    var selectedTemplate = String(config.selectedTemplate || 'professional');
    var draftKey = 'crm-email-signature-builder-' + String(config.mode || 'create') + '-' + String(config.signatureId || 'new');
    var templates = config.templates || {};
    var fontOptions = Array.isArray(config.fontOptions) ? config.fontOptions : [];
    var fontSizeOptions = Array.isArray(config.fontSizeOptions) ? config.fontSizeOptions : [];
    var actionbar = document.querySelector('.signature-builder-actionbar');

    // The app shell animates its page container with a transform, which would make
    // fixed descendants position against the page instead of the viewport.
    if (actionbar && actionbar.parentElement !== document.body) {
        document.body.appendChild(actionbar);
    }

    var initialSnapshot = {
        name: String(config.initialName || ''),
        content: String(config.initialContent || ''),
        accent: String(config.accentColor || '#2f6fed'),
        text: String(config.textColor || '#111827'),
        font: String(config.fontFamily || 'arial'),
        fontSize: String(config.fontSizePreset || 'standard'),
        template: selectedTemplate,
        isDefault: Boolean(config.isDefault)
    };

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 2300);
    }

    function safeColor(value, fallback) {
        return /^#[0-9a-f]{6}$/i.test(String(value || '')) ? value : fallback;
    }

    function optionByValue(options, value) {
        return options.find(function (item) { return String(item.value) === String(value); }) || options[0] || {};
    }

    function editorHtml() {
        if (quill) return quill.root.innerHTML;
        return fallbackEditor ? fallbackEditor.value : String(contentInput.value || '');
    }

    function setEditorHtml(html) {
        if (quill) {
            quill.setContents([]);
            quill.clipboard.dangerouslyPasteHTML(0, String(html || ''), 'silent');
            return;
        }
        if (fallbackEditor) fallbackEditor.value = html;
    }

    function meaningfulContent(html) {
        var node = document.createElement('div');
        node.innerHTML = String(html || '').replace(/<br\s*\/?>/gi, ' ');
        return String(node.textContent || '').replace(/\u00a0/g, ' ').trim() !== '';
    }

    function updateColorOutput(input) {
        if (!input) return;
        var output = document.querySelector('[data-color-output="' + input.id + '"]');
        if (output) output.textContent = String(input.value || '').toUpperCase();
    }

    function updateTemplateState() {
        templateButtons.forEach(function (button) {
            var active = button.getAttribute('data-builder-template') === selectedTemplate;
            button.classList.toggle('is-selected', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        if (templateInput) templateInput.value = selectedTemplate;
    }

    function currentLogoHtml() {
        var logoPath = document.getElementById('logo_path');
        var logoImage = document.getElementById('logo-img');
        if (!logoPath || !logoPath.value || !logoImage || !logoImage.src) return '';

        var wrapper = document.createElement('div');
        wrapper.style.marginBottom = '10px';
        var image = document.createElement('img');
        image.src = logoImage.src;
        image.alt = 'Company logo';
        image.style.maxHeight = '52px';
        image.style.maxWidth = '160px';
        wrapper.appendChild(image);
        return wrapper.outerHTML;
    }

    function renderSignatureHtml() {
        var accent = safeColor(accentInput ? accentInput.value : '', '#2f6fed');
        var textColor = safeColor(textInput ? textInput.value : '', '#111827');
        var content = document.createElement('div');
        content.innerHTML = editorHtml();
        Array.prototype.forEach.call(content.querySelectorAll('a'), function (link) {
            link.style.color = accent;
            link.style.textDecoration = 'none';
        });

        var wrapper = document.createElement('div');
        wrapper.style.color = textColor;
        var font = optionByValue(fontOptions, fontInput ? fontInput.value : 'arial');
        var size = optionByValue(fontSizeOptions, fontSizeInput ? fontSizeInput.value : 'standard');
        wrapper.style.fontFamily = String(font.css || 'Arial, Helvetica, sans-serif');
        wrapper.style.fontSize = Number(size.pixels || 14) + 'px';
        wrapper.style.lineHeight = String(size.line_height || 1.45);
        if (selectedTemplate === 'professional') {
            wrapper.style.borderLeft = '3px solid ' + accent;
            wrapper.style.paddingLeft = '12px';
        } else if (selectedTemplate === 'sales') {
            wrapper.style.borderTop = '2px solid ' + accent;
            wrapper.style.paddingTop = '10px';
        }
        wrapper.innerHTML = content.innerHTML;

        return currentLogoHtml() + wrapper.outerHTML;
    }

    function updatePreview() {
        if (!previewOutput) return;
        previewOutput.innerHTML = renderSignatureHtml();
        updateColorOutput(accentInput);
        updateColorOutput(textInput);
    }

    function snapshot() {
        return {
            name: nameInput ? nameInput.value : '',
            content: editorHtml(),
            accent: accentInput ? accentInput.value : '#2f6fed',
            text: textInput ? textInput.value : '#111827',
            font: fontInput ? fontInput.value : 'arial',
            fontSize: fontSizeInput ? fontSizeInput.value : 'standard',
            template: selectedTemplate,
            isDefault: defaultInput ? defaultInput.value === '1' : false,
            savedAt: Date.now()
        };
    }

    function saveDraft() {
        if (!window.localStorage) return;
        try {
            window.localStorage.setItem(draftKey, JSON.stringify(snapshot()));
            if (statusNode) statusNode.textContent = 'Draft saved locally';
        } catch (error) {
            if (statusNode) statusNode.textContent = 'Changes not saved';
        }
    }

    function markChanged() {
        updatePreview();
        if (statusNode) statusNode.textContent = 'Unsaved changes';
        window.clearTimeout(draftTimer);
        draftTimer = window.setTimeout(saveDraft, 450);
    }

    function applySnapshot(data) {
        if (!data || typeof data !== 'object') return;
        if (nameInput && typeof data.name === 'string') nameInput.value = data.name;
        if (typeof data.content === 'string') setEditorHtml(data.content);
        if (accentInput && typeof data.accent === 'string') accentInput.value = safeColor(data.accent, '#2f6fed');
        if (textInput && typeof data.text === 'string') textInput.value = safeColor(data.text, '#111827');
        if (fontInput && optionByValue(fontOptions, data.font).value) fontInput.value = String(optionByValue(fontOptions, data.font).value);
        if (fontSizeInput && optionByValue(fontSizeOptions, data.fontSize).value) fontSizeInput.value = String(optionByValue(fontSizeOptions, data.fontSize).value);
        selectedTemplate = templates[data.template] ? data.template : 'professional';
        if (defaultInput) defaultInput.value = data.isDefault ? '1' : '0';
        if (defaultSwitch) defaultSwitch.setAttribute('aria-checked', data.isDefault ? 'true' : 'false');
        updateTemplateState();
        updatePreview();
    }

    function setupDraftRecovery() {
        if (!draftBanner || config.hadPostError || !window.localStorage) return;
        var saved = null;
        try {
            saved = JSON.parse(window.localStorage.getItem(draftKey) || 'null');
        } catch (error) {
            saved = null;
        }
        if (!saved || !saved.savedAt) return;

        draftBanner.hidden = false;
        var restore = draftBanner.querySelector('[data-draft-restore]');
        var discard = draftBanner.querySelector('[data-draft-discard]');

        if (restore) restore.addEventListener('click', function () {
            applySnapshot(saved);
            draftBanner.hidden = true;
            if (statusNode) statusNode.textContent = 'Recovered local draft';
            showToast('Draft restored');
        });

        if (discard) discard.addEventListener('click', function () {
            window.localStorage.removeItem(draftKey);
            draftBanner.hidden = true;
            showToast('Draft discarded');
        });
    }

    function setupQuill() {
        var editor = document.getElementById('signature-editor');
        if (!editor || !contentInput) return;

        if (typeof window.Quill === 'undefined') {
            contentInput.style.display = 'block';
            contentInput.classList.add('signature-editor-fallback');
            fallbackEditor = contentInput;
            fallbackEditor.addEventListener('input', markChanged);
            showToast('Rich editor unavailable. Plain HTML editing is active.');
            return;
        }

        var toolbar = [
            [{ header: [2, 3, false] }],
            ['bold', 'italic', 'underline'],
            [{ list: 'ordered' }, { list: 'bullet' }],
            [{ color: [] }],
            ['link'],
            ['clean']
        ];
        if (Number(config.signatureId || 0) > 0) toolbar.splice(toolbar.length - 1, 0, ['image']);

        quill = new window.Quill(editor, {
            theme: 'snow',
            placeholder: 'Add your name, role, company, phone, email, and links…',
            modules: { toolbar: toolbar }
        });
        quill.clipboard.dangerouslyPasteHTML(0, String(config.initialContent || ''), 'silent');
        quill.on('text-change', markChanged);

        if (Number(config.signatureId || 0) > 0) {
            var toolbarModule = quill.getModule('toolbar');
            toolbarModule.addHandler('image', chooseInlineImage);
        }
    }

    function uploadAsset(file, type, onSuccess) {
        if (!file || !config.assetUploadUrl || !config.signatureId) return;
        var body = new FormData();
        body.append('file', file);
        body.append('signature_id', String(config.signatureId));
        body.append('type', type);
        body.append('csrf_token', String(config.csrfToken || ''));

        showToast('Uploading image…');
        fetch(config.assetUploadUrl, { method: 'POST', body: body })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data.success) throw new Error(data.error || 'Upload failed');
                onSuccess(data);
                markChanged();
                showToast('Image uploaded');
            })
            .catch(function (error) {
                showToast(error.message || 'Upload failed');
            });
    }

    function chooseInlineImage() {
        var input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/jpeg,image/png,image/gif,image/webp';
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file || !quill) return;
            uploadAsset(file, 'image', function (data) {
                var range = quill.getSelection(true) || { index: quill.getLength() };
                quill.insertEmbed(range.index, 'image', data.url, 'user');
            });
        });
        input.click();
    }

    function setupLogoUpload() {
        var zone = document.getElementById('logo-upload-zone');
        var input = document.getElementById('logo-file');
        var pathInput = document.getElementById('logo_path');
        var image = document.getElementById('logo-img');
        var preview = document.getElementById('logo-preview');
        var placeholder = document.getElementById('logo-placeholder');
        var remove = document.getElementById('logo-remove');
        if (!zone || !input || !pathInput || !image || !preview || !placeholder || !remove) return;

        zone.addEventListener('click', function (event) {
            if (event.target.closest('#logo-remove')) return;
            if (!pathInput.value) input.click();
        });
        zone.addEventListener('keydown', function (event) {
            if ((event.key === 'Enter' || event.key === ' ') && !pathInput.value) {
                event.preventDefault();
                input.click();
            }
        });
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            uploadAsset(file, 'logo', function (data) {
                pathInput.value = data.path || '';
                image.src = data.url || '';
                preview.hidden = false;
                placeholder.hidden = true;
            });
        });
        remove.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            pathInput.value = '';
            image.removeAttribute('src');
            preview.hidden = true;
            placeholder.hidden = false;
            input.value = '';
            markChanged();
        });
    }

    templateButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            var key = button.getAttribute('data-builder-template');
            var template = templates[key];
            if (!template) return;

            var knownNames = Object.keys(templates).map(function (templateKey) {
                return templates[templateKey].name + ' Signature';
            });
            if (nameInput && (nameInput.value.trim() === '' || knownNames.indexOf(nameInput.value) !== -1)) {
                nameInput.value = template.name + ' Signature';
            }
            selectedTemplate = key;
            setEditorHtml(String(template.content_html || ''));
            if (accentInput) accentInput.value = safeColor(template.accent_color, '#2f6fed');
            if (textInput) textInput.value = safeColor(template.text_color, '#111827');
            updateTemplateState();
            markChanged();
        });
    });

    if (nameInput) nameInput.addEventListener('input', markChanged);
    if (accentInput) accentInput.addEventListener('input', markChanged);
    if (textInput) textInput.addEventListener('input', markChanged);
    if (fontInput) fontInput.addEventListener('change', markChanged);
    if (fontSizeInput) fontSizeInput.addEventListener('change', markChanged);

    if (defaultSwitch && defaultInput) {
        defaultSwitch.addEventListener('click', function () {
            var active = defaultSwitch.getAttribute('aria-checked') !== 'true';
            defaultSwitch.setAttribute('aria-checked', active ? 'true' : 'false');
            defaultInput.value = active ? '1' : '0';
            markChanged();
        });
    }

    Array.prototype.forEach.call(document.querySelectorAll('[data-preview-device]'), function (button) {
        button.addEventListener('click', function () {
            var mobile = button.getAttribute('data-preview-device') === 'mobile';
            if (previewFrame) previewFrame.classList.toggle('is-mobile', mobile);
            Array.prototype.forEach.call(document.querySelectorAll('[data-preview-device]'), function (item) {
                var active = item === button;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-preview-theme]'), function (button) {
        button.addEventListener('click', function () {
            var dark = button.getAttribute('data-preview-theme') === 'dark';
            if (previewFrame) previewFrame.classList.toggle('is-dark', dark);
            Array.prototype.forEach.call(document.querySelectorAll('[data-preview-theme]'), function (item) {
                var active = item === button;
                item.classList.toggle('is-active', active);
                item.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
        });
    });

    var resetButton = document.querySelector('[data-builder-reset]');
    if (resetButton) {
        resetButton.addEventListener('click', function () {
            if (!window.confirm('Reset this signature to its original content and design?')) return;
            applySnapshot(initialSnapshot);
            markChanged();
            showToast('Signature reset');
        });
    }

    form.addEventListener('submit', function (event) {
        var html = editorHtml();
        if (!meaningfulContent(html)) {
            event.preventDefault();
            showToast('Add some signature content before saving');
            if (quill) quill.focus();
            return;
        }

        contentInput.value = html;
        if (templateInput) templateInput.value = selectedTemplate;
        try {
            window.localStorage.removeItem(draftKey);
        } catch (error) {
            // Storage is optional.
        }
        var submit = document.querySelector('[type="submit"][form="signature-form"]');
        if (submit) {
            submit.disabled = true;
            submit.textContent = config.mode === 'edit' ? 'Saving…' : 'Creating…';
        }
    });

    setupQuill();
    setupLogoUpload();
    updateTemplateState();
    updatePreview();
    setupDraftRecovery();
}());
