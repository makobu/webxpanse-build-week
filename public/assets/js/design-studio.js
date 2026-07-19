(function () {
    'use strict';

    var root = document.querySelector('[data-design-studio]');
    var initialNode = document.getElementById('designStudioInitial');
    if (!root || !initialNode) { return; }

    var initial;
    try { initial = JSON.parse(initialNode.textContent || '{}'); } catch (error) { return; }

    var state = {
        pageId: Number(initial.pageId || 0),
        page: clone(initial.page || {}),
        document: clone(initial.document || { schema: 'crm.design/v1', theme: {}, blocks: [] }),
        revision: Number(initial.revision || 0),
        validation: clone(initial.validation || { valid: false, errors: [], warnings: [] }),
        manifest: Array.isArray(initial.manifest) ? initial.manifest : [],
        templates: Array.isArray(initial.templates) ? initial.templates : [],
        options: initial.options || {},
        versions: Array.isArray(initial.versions) ? initial.versions : [],
        csrfToken: String(initial.csrfToken || ''),
        apiUrl: String(initial.apiUrl || ''),
        mediaUploadUrl: String(initial.mediaUploadUrl || ''),
        mediaLibraryUrl: String(initial.mediaLibraryUrl || 'marketing_assets.php#media-tools'),
        previewUrl: String(initial.previewUrl || ''),
        publicUrl: String(initial.publicUrl || ''),
        canManage: Boolean(initial.canManage),
        isPublished: Boolean(initial.isPublished),
        selectedId: null,
        inspectorTab: 'content',
        viewport: window.innerWidth <= 760 ? 'mobile' : (window.innerWidth <= 1080 ? 'tablet' : 'desktop'),
        zoom: 0.9,
        undo: [],
        redo: [],
        changeSerial: 0,
        savedSerial: 0,
        saving: false,
        saveTimer: null,
        fieldHistoryOpen: false,
        fieldHistoryTimer: null,
        toastTimer: null
    };

    var canvas = document.getElementById('designCanvas');
    var viewport = document.getElementById('designCanvasViewport');
    var inspectorBody = document.getElementById('designInspectorBody');
    var inspectorTitle = document.getElementById('designInspectorTitle');
    var inspectorFooter = document.getElementById('designInspectorFooter');
    var saveStatus = document.getElementById('designSaveStatus');
    var toolbarStatus = root.querySelector('.design-toolbar__status');
    var revisionStatus = toolbarStatus ? toolbarStatus.querySelector('small') : null;
    var validity = document.getElementById('designValidity');
    var toast = document.getElementById('designToast');
    var templateDialog = document.getElementById('designTemplateDialog');
    var settingsDialog = document.getElementById('designSettingsDialog');
    var validationDialog = document.getElementById('designValidationDialog');
    var historyDialog = document.getElementById('designHistoryDialog');
    var mediaUploadInput = document.getElementById('designMediaUpload');
    var pendingMediaTarget = null;
    var draggedBlockId = '';
    var draggedLibraryType = '';
    var dropTargetId = '';
    var typography = initial.typography || {};
    var webFonts = Array.isArray(typography.web_fonts) ? typography.web_fonts : [];
    var webScales = Array.isArray(typography.web_scales) ? typography.web_scales : [];

    viewport.className = 'design-canvas-viewport is-' + state.viewport;
    root.querySelectorAll('[data-viewport]').forEach(function (button) { button.classList.toggle('is-active', button.dataset.viewport === state.viewport); });

    function clone(value) { return JSON.parse(JSON.stringify(value)); }
    function e(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }
    function safeColor(value, fallback) { return /^#[0-9a-f]{6}$/i.test(String(value || '')) ? String(value).toLowerCase() : fallback; }
    function isDarkColor(value) {
        var color = safeColor(value, '');
        if (!color) { return false; }
        var red = parseInt(color.slice(1, 3), 16), green = parseInt(color.slice(3, 5), 16), blue = parseInt(color.slice(5, 7), 16);
        return ((red * 299) + (green * 587) + (blue * 114)) / 1000 < 145;
    }
    function safeUrl(value) {
        var url = String(value || '').trim();
        if (!url) { return ''; }
        if (url.charAt(0) === '#' || url.charAt(0) === '/' || /^[a-z0-9_.-]+\.php(?:\?|$)/i.test(url) || /^https?:\/\//i.test(url)) { return url; }
        return '';
    }
    function lines(value) { return String(value || '').split(/\r?\n/).map(function (line) { return line.trim(); }).filter(Boolean); }
    function pairs(value) {
        return lines(value).map(function (line) {
            var at = line.indexOf('|');
            return at < 0 ? [line, ''] : [line.slice(0, at).trim(), line.slice(at + 1).trim()];
        }).filter(function (pair) { return pair[0]; });
    }
    function ids(value) {
        return String(value || '').split(/[^0-9]+/).map(Number).filter(function (id, index, all) { return id > 0 && all.indexOf(id) === index; });
    }
    function typographyOption(options, value) {
        return options.find(function (item) { return String(item.value) === String(value); }) || options[0] || {};
    }
    function typographySelectOptions(options, selected, inheritLabel) {
        var html = inheritLabel ? '<option value="inherit"' + (selected === 'inherit' ? ' selected' : '') + '>' + e(inheritLabel) + '</option>' : '';
        return html + options.map(function (item) {
            return '<option value="' + e(item.value) + '"' + (String(selected) === String(item.value) ? ' selected' : '') + '>' + e(item.label) + '</option>';
        }).join('');
    }
    function fontCss(value) { return String(typographyOption(webFonts, value).css || 'system-ui, sans-serif'); }
    function scaleValues(value) {
        var option = typographyOption(webScales, value);
        return { heading: Number(option.heading || 1), body: Number(option.body || 1) };
    }
    function manifestFor(type) { return state.manifest.find(function (item) { return item.type === type; }) || null; }
    function selectedBlock() { return state.document.blocks.find(function (block) { return block.id === state.selectedId; }) || null; }
    function optionById(group, id) { return (state.options[group] || []).find(function (item) { return Number(item.id) === Number(id); }) || null; }
    function blockLabel(block) { var manifest = manifestFor(block.type); return manifest ? manifest.label : block.type; }
    function newId(type) {
        var suffix = Math.random().toString(16).slice(2, 10) + Date.now().toString(16).slice(-4);
        return type + '_' + suffix;
    }
    function defaultBlock(type) {
        var manifest = manifestFor(type);
        if (!manifest) { return null; }
        return {
            id: newId(type),
            type: type,
            props: clone(manifest.default_props || {}),
            style: { alignment: ['hero', 'cta', 'logo_strip'].indexOf(type) >= 0 ? 'center' : 'left', padding: 'normal', background: 'transparent', max_width: 1180, heading_font: 'inherit', body_font: 'inherit', type_scale: 'inherit' },
            visibility: { hide_desktop: false, hide_tablet: false, hide_mobile: false }
        };
    }
    function snapshot() { return { page: clone(state.page), document: clone(state.document), selectedId: state.selectedId }; }
    function restoreSnapshot(entry) {
        state.page = clone(entry.page);
        state.document = clone(entry.document);
        state.selectedId = entry.selectedId && state.document.blocks.some(function (block) { return block.id === entry.selectedId; }) ? entry.selectedId : null;
        state.changeSerial += 1;
        renderAll();
        scheduleSave();
    }
    function pushUndo() {
        state.undo.push(snapshot());
        if (state.undo.length > 50) { state.undo.shift(); }
        state.redo = [];
        updateHistoryButtons();
    }
    function mutate(callback, message) {
        pushUndo();
        callback();
        state.changeSerial += 1;
        renderAll();
        scheduleSave();
        if (message) { showToast(message); }
    }
    function beginFieldHistory() {
        if (!state.fieldHistoryOpen) {
            pushUndo();
            state.fieldHistoryOpen = true;
        }
        window.clearTimeout(state.fieldHistoryTimer);
        state.fieldHistoryTimer = window.setTimeout(function () { state.fieldHistoryOpen = false; }, 700);
    }

    function mediaHtml(id, alt, className) {
        var media = optionById('media_files', id);
        var url = media ? String(media.display_url || '') : '';
        if (!media || !url) { return '<div class="' + e(className) + ' ds-empty-media"><span>' + (id ? 'Media unavailable' : 'Approved media') + '</span></div>'; }
        var label = String(alt || media.alt_text || media.title || '');
        if (String(media.media_type || '') === 'video') { return '<figure class="' + e(className) + '"><video controls preload="metadata" src="' + e(url) + '"></video></figure>'; }
        return '<figure class="' + e(className) + '"><img src="' + e(url) + '" alt="' + e(label) + '" loading="lazy"></figure>';
    }
    function buttonHtml(label, href, primary) {
        label = String(label || '').trim(); href = safeUrl(href);
        if (!label || !href) { return ''; }
        return '<a class="ds-button ' + (primary ? 'ds-button--primary' : 'ds-button--secondary') + '" href="' + e(href) + '">' + e(label) + '</a>';
    }
    function inlineElement(block, tag, key, value, className, singleLine) {
        var attributes = className ? ' class="' + e(className) + '"' : '';
        if (state.selectedId === block.id) {
            attributes += ' contenteditable="plaintext-only" draggable="false" data-inline-prop="' + e(key) + '"' + (singleLine ? ' data-inline-single="1"' : '') + ' spellcheck="true"';
        }
        return '<' + tag + attributes + '>' + e(value).replace(/\n/g, '<br>') + '</' + tag + '>';
    }
    function blockFrame(block, body) {
        var style = block.style || {};
        var visibility = block.visibility || {};
        var classes = ['ds-block', 'ds-block--' + block.type, 'ds-align--' + (style.alignment || 'left'), 'ds-space--' + (style.padding || 'normal')];
        if (style.background && style.background !== 'transparent') {
            classes.push('ds-block--custom-background');
            if (isDarkColor(style.background)) { classes.push('ds-block--dark-background'); }
        }
        ['desktop', 'tablet', 'mobile'].forEach(function (name) { if (visibility['hide_' + name]) { classes.push('ds-hide-' + name); } });
        if (state.selectedId === block.id) { classes.push('is-selected'); }
        if (draggedBlockId === block.id) { classes.push('is-dragging'); }
        if (dropTargetId === block.id) { classes.push('is-drop-target'); }
        var background = style.background === 'transparent' ? 'transparent' : safeColor(style.background, 'transparent');
        var maxWidth = Math.max(640, Math.min(1600, Number(style.max_width || 1180)));
        var variables = ['--ds-block-bg:' + background, '--ds-max:' + maxWidth + 'px'];
        if (style.heading_font && style.heading_font !== 'inherit') variables.push('--ds-heading-font:' + fontCss(style.heading_font));
        if (style.body_font && style.body_font !== 'inherit') variables.push('--ds-body-font:' + fontCss(style.body_font));
        if (style.type_scale && style.type_scale !== 'inherit') {
            var blockScale = scaleValues(style.type_scale);
            variables.push('--ds-heading-scale:' + blockScale.heading, '--ds-body-scale:' + blockScale.body);
        }
        return '<section id="' + e(block.id) + '" class="' + classes.join(' ') + '" data-design-block="' + e(block.type) + '" data-block-id="' + e(block.id) + '" data-block-label="' + e(blockLabel(block)) + '" draggable="true" tabindex="0" role="button" aria-label="Edit ' + e(blockLabel(block)) + ' block" style="' + e(variables.join(';')) + '"><div class="ds-block__inner">' + body + '</div></section>';
    }
    function renderBlock(block) {
        var p = block.props || {};
        var eyebrow = p.eyebrow ? inlineElement(block, 'p', 'eyebrow', p.eyebrow, 'ds-eyebrow', true) : '';
        var body = p.body ? inlineElement(block, 'p', 'body', p.body, 'ds-copy', false) : '';
        var content = '';
        if (block.type === 'hero') {
            content = '<div class="ds-hero__copy">' + eyebrow + inlineElement(block, 'h1', 'heading', p.heading, '', true) + body + '<div class="ds-actions">' + buttonHtml(p.primary_label, p.primary_href, true) + buttonHtml(p.secondary_label, p.secondary_href, false) + '</div></div>' + mediaHtml(p.media_file_id, p.image_alt, 'ds-hero__media');
        } else if (block.type === 'text_image') {
            var copy = '<div class="ds-split__copy">' + eyebrow + inlineElement(block, 'h2', 'heading', p.heading, '', true) + body + '</div>';
            var media = mediaHtml(p.media_file_id, p.image_alt, 'ds-split__media');
            content = '<div class="ds-split ds-split--' + e(p.image_side || 'right') + '">' + (p.image_side === 'left' ? media + copy : copy + media) + '</div>';
        } else if (block.type === 'benefits') {
            content = inlineElement(block, 'h2', 'heading', p.heading, '', true) + body + '<ul class="ds-benefits">' + lines(p.items).map(function (item) { return '<li><span>✓</span>' + e(item) + '</li>'; }).join('') + '</ul>';
        } else if (block.type === 'logo_strip') {
            content = inlineElement(block, 'h2', 'heading', p.heading, '', true) + '<ul class="ds-logos">' + lines(p.items).map(function (item) { return '<li>' + e(item) + '</li>'; }).join('') + '</ul>';
        } else if (block.type === 'testimonials') {
            content = inlineElement(block, 'h2', 'heading', p.heading, '', true) + '<div class="ds-quote">' + mediaHtml(p.media_file_id, p.image_alt, 'ds-quote__media') + inlineElement(block, 'blockquote', 'quote', p.quote, '', false) + '<p>' + inlineElement(block, 'strong', 'name', p.name, '', true) + inlineElement(block, 'span', 'role', p.role, '', true) + '</p></div>';
        } else if (block.type === 'pricing') {
            content = inlineElement(block, 'h2', 'heading', p.heading, '', true) + body + '<article class="ds-price">' + inlineElement(block, 'p', 'plan_name', p.plan_name, 'ds-price__name', true) + '<p class="ds-price__value">' + inlineElement(block, 'strong', 'price', p.price, '', true) + inlineElement(block, 'span', 'period', p.period, '', true) + '</p><ul>' + lines(p.features).map(function (item) { return '<li>✓ ' + e(item) + '</li>'; }).join('') + '</ul>' + buttonHtml(p.button_label, p.button_href, true) + '</article>';
        } else if (block.type === 'faq') {
            content = inlineElement(block, 'h2', 'heading', p.heading, '', true) + '<div class="ds-faq">' + pairs(p.items).map(function (pair) { return '<details><summary>' + e(pair[0]) + '</summary><p>' + e(pair[1]) + '</p></details>'; }).join('') + '</div>';
        } else if (block.type === 'gallery') {
            var gallery = ids(p.media_ids).map(function (id) { return mediaHtml(id, p.image_alt, 'ds-gallery__item'); }).join('');
            content = inlineElement(block, 'h2', 'heading', p.heading, '', true) + '<div class="ds-gallery">' + (gallery || '<div class="ds-empty-media">Add approved media</div>') + '</div>';
        } else if (block.type === 'crm_form') {
            var form = optionById('forms', p.form_id || state.page.form_id);
            var formUrl = form && form.uuid ? 'form.php?uuid=' + encodeURIComponent(form.uuid) + '&embed=1' + (form.preview_token ? '&preview_token=' + encodeURIComponent(form.preview_token) : '') : '';
            content = '<div id="contact">' + eyebrow + inlineElement(block, 'h2', 'heading', p.heading, '', true) + body + (formUrl ? '<iframe class="ds-embed ds-form-embed" title="' + e(p.heading) + '" src="' + e(formUrl) + '" loading="lazy" style="height:' + Math.max(240, Math.min(1400, Number(p.height || 620))) + 'px"></iframe>' : '<div class="ds-connection-warning">Connect an active CRM form before publishing.</div>') + '</div>';
        } else if (block.type === 'booking') {
            var bookingUrl = safeUrl(p.booking_url);
            var booking = p.display === 'embed' && bookingUrl ? '<iframe class="ds-embed ds-booking-embed" title="' + e(p.heading) + '" src="' + e(bookingUrl) + '" loading="lazy" style="height:' + Math.max(240, Math.min(1400, Number(p.height || 760))) + 'px"></iframe>' : buttonHtml(p.button_label, bookingUrl, true);
            content = '<div id="booking">' + eyebrow + inlineElement(block, 'h2', 'heading', p.heading, '', true) + body + booking + '</div>';
        } else if (block.type === 'whatsapp') {
            var phone = String(p.phone || '').replace(/\D+/g, '');
            var whatsappUrl = phone ? 'https://wa.me/' + phone + '?text=' + encodeURIComponent(String(p.message || '')) : '';
            content = '<div id="whatsapp">' + eyebrow + inlineElement(block, 'h2', 'heading', p.heading, '', true) + body + buttonHtml(p.button_label, whatsappUrl, true) + '</div>';
        } else {
            content = inlineElement(block, 'h2', 'heading', p.heading, '', true) + body + '<div class="ds-actions">' + buttonHtml(p.button_label, p.button_href, true) + '</div>';
        }
        return blockFrame(block, content);
    }
    function renderCanvas() {
        var theme = state.document.theme || {};
        var pageScale = scaleValues(theme.type_scale || 'balanced');
        var vars = [
            '--ds-primary:' + safeColor(theme.primary_color, '#0f67ea'), '--ds-secondary:' + safeColor(theme.secondary_color, '#0f9f76'),
            '--ds-page:' + safeColor(theme.page_color, '#f6f8fb'), '--ds-surface:' + safeColor(theme.surface_color, '#ffffff'),
            '--ds-text:' + safeColor(theme.text_color, '#132238'), '--ds-muted:' + safeColor(theme.muted_color, '#5b6b7f'),
            '--ds-radius:' + Math.max(0, Math.min(32, Number(theme.radius || 16))) + 'px',
            '--ds-section-space:' + Math.max(32, Math.min(160, Number(theme.section_spacing || 80))) + 'px',
            '--ds-heading-font:' + fontCss(theme.heading_font || 'system'),
            '--ds-body-font:' + fontCss(theme.body_font || 'system'),
            '--ds-heading-scale:' + pageScale.heading,
            '--ds-body-scale:' + pageScale.body
        ].join(';');
        canvas.innerHTML = '<div class="ds-page" data-design-schema="crm.design/v1" style="' + vars + '">' + state.document.blocks.map(renderBlock).join('') + '</div>';
        canvas.style.transform = 'scale(' + state.zoom + ')';
        canvas.style.marginBottom = ((state.zoom - 1) * canvas.offsetHeight) + 'px';
        bindCanvasDragTargets();
    }

    function mediaControlHtml(field, value, multiple) {
        var selectedIds = multiple ? ids(value) : (Number(value) > 0 ? [Number(value)] : []);
        var selectedMedia = selectedIds.map(function (id) { return optionById('media_files', id); }).filter(Boolean);
        var current = selectedMedia[0] || null;
        var preview = current && current.display_url && current.media_type === 'image'
            ? '<img src="' + e(current.display_url) + '" alt="">'
            : '<i class="fas ' + (current && current.media_type === 'video' ? 'fa-circle-play' : 'fa-image') + '" aria-hidden="true"></i>';
        var title = multiple
            ? (selectedMedia.length ? selectedMedia.length + ' item' + (selectedMedia.length === 1 ? '' : 's') + ' selected' : 'No media selected')
            : (current ? current.title : 'No media selected');
        var actionLabel = current && !multiple ? 'Replace upload' : 'Upload new';
        return '<div class="design-media-control">'
            + '<div class="design-media-current">' + preview + '<div><strong>' + e(title) + '</strong><span>' + (current ? e(String(current.media_type || 'media').replace(/_/g, ' ')) : 'Choose existing media or upload here') + '</span></div></div>'
            + '<div class="design-media-actions"><button type="button" class="design-button design-button--secondary" data-upload-media data-media-prop="' + e(field.key) + '"' + (multiple ? ' data-media-multi="1"' : '') + '><i class="fas fa-cloud-arrow-up"></i> ' + e(actionLabel) + '</button>'
            + '<a class="design-button design-button--secondary" href="' + e(state.mediaLibraryUrl) + '" target="_blank" rel="noopener"><i class="fas fa-images"></i> Library</a></div>'
            + '<small>Images and video up to 50 MB. The upload is saved to your reusable media library.</small></div>';
    }
    function fieldHtml(field, value) {
        var id = 'designField_' + field.key;
        var required = field.required ? ' <small>Required</small>' : '';
        var html = '<label class="design-field"><span>' + e(field.label) + required + '</span>';
        if (field.type === 'textarea' || field.type === 'lines' || field.type === 'pairs') {
            html += '<textarea id="' + id + '" data-prop="' + e(field.key) + '">' + e(value) + '</textarea>';
        } else if (field.type === 'select') {
            html += '<select id="' + id + '" data-prop="' + e(field.key) + '">' + (field.options || []).map(function (option) { return '<option value="' + e(option) + '"' + (String(value) === String(option) ? ' selected' : '') + '>' + e(String(option).replace(/_/g, ' ')) + '</option>'; }).join('') + '</select>';
        } else if (field.type === 'media') {
            html += '<select id="' + id + '" data-prop="' + e(field.key) + '" data-value-type="number" data-media-select><option value="0">No media</option>' + (state.options.media_files || []).map(function (item) { return '<option value="' + Number(item.id) + '"' + (Number(value) === Number(item.id) ? ' selected' : '') + '>' + e(item.title + ' · ' + item.media_type) + '</option>'; }).join('') + '</select>';
            html += '</label>' + mediaControlHtml(field, value, false);
            return html;
        } else if (field.type === 'media_multi') {
            var selectedIds = ids(value);
            html += '<select id="' + id + '" data-prop="' + e(field.key) + '" data-value-type="media-multi" data-media-select multiple size="6">' + (state.options.media_files || []).map(function (item) { return '<option value="' + Number(item.id) + '"' + (selectedIds.indexOf(Number(item.id)) >= 0 ? ' selected' : '') + '>' + e(item.title + ' · ' + item.media_type) + '</option>'; }).join('') + '</select>';
            html += '</label>' + mediaControlHtml(field, value, true);
            return html;
        } else if (field.type === 'form') {
            html += '<select id="' + id + '" data-prop="' + e(field.key) + '" data-value-type="number"><option value="0">Choose a CRM form</option>' + (state.options.forms || []).map(function (item) { return '<option value="' + Number(item.id) + '"' + (Number(value) === Number(item.id) ? ' selected' : '') + '>' + e(item.name) + '</option>'; }).join('') + '</select>';
        } else if (field.type === 'booking_url' && (state.options.booking_profiles || []).length) {
            var knownBooking = (state.options.booking_profiles || []).some(function (item) { return String(item.booking_url) === String(value); });
            html += '<select id="' + id + '" data-prop="' + e(field.key) + '">' + (!knownBooking && value ? '<option value="' + e(value) + '" selected>Current booking link</option>' : '') + '<option value="">Choose a public booking profile</option>' + (state.options.booking_profiles || []).map(function (item) { return '<option value="' + e(item.booking_url) + '"' + (String(value) === String(item.booking_url) ? ' selected' : '') + '>' + e(item.title) + '</option>'; }).join('') + '</select>';
        } else {
            var inputType = field.type === 'number' ? 'number' : (field.type === 'phone' ? 'tel' : 'text');
            html += '<input id="' + id + '" type="' + inputType + '" data-prop="' + e(field.key) + '"' + (field.type === 'number' ? ' data-value-type="number" min="240" max="1400"' : '') + ' value="' + e(value) + '">';
        }
        if (field.type === 'pairs') { html += '<small class="design-inspector-help">Use one line per item: Question | Answer</small>'; }
        if (field.type === 'booking_url') { html += '<small class="design-inspector-help">Only the core meeting_schedule.php flow is accepted.</small>'; }
        return html + '</label>';
    }
    function pageField(label, key, type, options) {
        var value = state.page[key] == null ? '' : state.page[key];
        var html = '<label class="design-field"><span>' + e(label) + '</span>';
        if (type === 'textarea') { html += '<textarea data-page-field="' + e(key) + '">' + e(value) + '</textarea>'; }
        else if (type === 'select') { html += '<select data-page-field="' + e(key) + '" data-value-type="number"><option value="0">None</option>' + (options || []).map(function (item) { return '<option value="' + Number(item.id) + '"' + (Number(value) === Number(item.id) ? ' selected' : '') + '>' + e(item.name || item.title) + '</option>'; }).join('') + '</select>'; }
        else { html += '<input type="text" data-page-field="' + e(key) + '" value="' + e(value) + '">'; }
        return html + '</label>';
    }
    function backgroundControlHtml(style) {
        var custom = style.background && style.background !== 'transparent';
        var fallback = safeColor((state.document.theme || {}).surface_color, '#ffffff');
        var color = custom ? safeColor(style.background, fallback) : fallback;
        var swatches = [
            ['#ffffff', 'White'],
            [safeColor((state.document.theme || {}).surface_color, '#ffffff'), 'Surface'],
            [safeColor((state.document.theme || {}).page_color, '#f6f8fb'), 'Page'],
            [safeColor((state.document.theme || {}).primary_color, '#0f67ea'), 'Primary'],
            [safeColor((state.document.theme || {}).secondary_color, '#0f9f76'), 'Accent'],
            ['#132238', 'Dark']
        ];
        return '<label class="design-field-toggle"><span>Custom background</span><input type="checkbox" data-style-custom-background' + (custom ? ' checked' : '') + '></label>'
            + '<div class="design-color-control"' + (custom ? '' : ' hidden') + '>'
            + '<div class="design-color-inputs"><label><span class="sr-only">Choose background colour</span><input type="color" data-style-color-picker value="' + e(color) + '" aria-label="Choose background colour"></label><label><span class="sr-only">Background hex colour</span><input type="text" data-style-color-hex value="' + e(color) + '" maxlength="7" inputmode="text" spellcheck="false" aria-label="Background hex colour"></label></div>'
            + '<div class="design-color-swatches" role="group" aria-label="Background colour suggestions">' + swatches.map(function (swatch) { return '<button type="button" data-style-color-swatch="' + e(swatch[0]) + '" title="' + e(swatch[1]) + '" aria-label="Use ' + e(swatch[1]) + ' background" style="--design-swatch:' + e(swatch[0]) + '"></button>'; }).join('') + '</div>'
            + '<small>Applied instantly to this block and saved automatically.</small></div>';
    }
    function renderInspector() {
        var block = selectedBlock();
        inspectorTitle.textContent = block ? blockLabel(block) : 'Page';
        inspectorFooter.classList.toggle('is-page', !block);
        root.classList.toggle('has-inspector', Boolean(block) && window.innerWidth <= 1080);
        var html = '';
        if (!block) {
            if (state.inspectorTab === 'style') { html = themeInspectorHtml(); }
            else {
                html = '<section class="design-inspector-section"><h3>Page identity</h3>' + pageField('Page name', 'title', 'text') + pageField('URL slug', 'slug', 'text') + pageField('SEO title', 'seo_title', 'text') + pageField('Meta description', 'meta_description', 'textarea') + '</section>'
                    + '<section class="design-inspector-section"><h3>CRM connections</h3>' + pageField('Default CRM form', 'form_id', 'select', state.options.forms) + pageField('Campaign', 'campaign_id', 'select', state.options.campaigns) + pageField('Audience', 'audience_segment_id', 'select', state.options.audience_segments) + '</section>'
                    + '<section class="design-inspector-section"><button type="button" class="design-button design-button--secondary" data-action="history"><i class="fas fa-clock-rotate-left"></i> Version history</button></section>';
            }
        } else if (state.inspectorTab === 'content') {
            var manifest = manifestFor(block.type);
            html = '<section class="design-inspector-section"><h3>' + e(blockLabel(block)) + ' content</h3>' + (manifest ? manifest.fields.map(function (field) { return fieldHtml(field, block.props[field.key]); }).join('') : '') + '</section>';
        } else if (state.inspectorTab === 'style') {
            var style = block.style || {};
            html = '<section class="design-inspector-section"><h3>Block layout</h3>'
                + '<label class="design-field"><span>Alignment</span><select data-style-field="alignment"><option value="left"' + (style.alignment === 'left' ? ' selected' : '') + '>Left</option><option value="center"' + (style.alignment === 'center' ? ' selected' : '') + '>Center</option></select></label>'
                + '<label class="design-field"><span>Spacing</span><select data-style-field="padding"><option value="compact"' + (style.padding === 'compact' ? ' selected' : '') + '>Compact</option><option value="normal"' + (style.padding === 'normal' ? ' selected' : '') + '>Normal</option><option value="spacious"' + (style.padding === 'spacious' ? ' selected' : '') + '>Spacious</option></select></label>'
                + backgroundControlHtml(style)
                + '<label class="design-field"><span>Content width</span><input type="number" min="640" max="1600" step="20" data-style-field="max_width" data-value-type="number" value="' + Number(style.max_width || 1180) + '"></label></section>'
                + '<section class="design-inspector-section"><h3>Block typography</h3>'
                + '<label class="design-field"><span>Heading font</span><select data-style-field="heading_font">' + typographySelectOptions(webFonts, style.heading_font || 'inherit', 'Use page heading font') + '</select></label>'
                + '<label class="design-field"><span>Body font</span><select data-style-field="body_font">' + typographySelectOptions(webFonts, style.body_font || 'inherit', 'Use page body font') + '</select></label>'
                + '<label class="design-field"><span>Type size</span><select data-style-field="type_scale">' + typographySelectOptions(webScales, style.type_scale || 'inherit', 'Use page size') + '</select></label>'
                + '<p class="design-inspector-help">Overrides apply only to this block. Choose the page option to keep global brand typography.</p></section>';
        } else {
            var visibilityState = block.visibility || {};
            html = '<section class="design-inspector-section"><h3>Responsive visibility</h3>' + ['desktop', 'tablet', 'mobile'].map(function (name) { return '<label class="design-field-toggle"><span>Hide on ' + e(name) + '</span><input type="checkbox" data-visibility-field="hide_' + e(name) + '"' + (visibilityState['hide_' + name] ? ' checked' : '') + '></label>'; }).join('') + '<p class="design-inspector-help">Hidden blocks remain in the draft and can be restored for any viewport.</p></section>';
        }
        inspectorBody.innerHTML = html;
        bindInspectorFields();
    }
    function themeInspectorHtml() {
        var theme = state.document.theme || {};
        var colors = [['primary_color', 'Primary'], ['secondary_color', 'Accent'], ['page_color', 'Page'], ['surface_color', 'Surface'], ['text_color', 'Text'], ['muted_color', 'Muted text']];
        return '<section class="design-inspector-section"><h3>Brand colors</h3>' + colors.map(function (entry) { return '<label class="design-field"><span>' + e(entry[1]) + '</span><input type="color" data-theme-field="' + e(entry[0]) + '" value="' + e(safeColor(theme[entry[0]], '#ffffff')) + '"></label>'; }).join('') + '</section><section class="design-inspector-section"><h3>Brand typography</h3><label class="design-field"><span>Heading font</span><select data-theme-field="heading_font">' + typographySelectOptions(webFonts, theme.heading_font || 'system') + '</select></label><label class="design-field"><span>Body font</span><select data-theme-field="body_font">' + typographySelectOptions(webFonts, theme.body_font || 'system') + '</select></label><label class="design-field"><span>Type size</span><select data-theme-field="type_scale">' + typographySelectOptions(webScales, theme.type_scale || 'balanced') + '</select></label></section><section class="design-inspector-section"><h3>Shape and rhythm</h3><label class="design-field"><span>Corner radius</span><input type="number" min="0" max="32" data-theme-field="radius" data-value-type="number" value="' + Number(theme.radius || 16) + '"></label><label class="design-field"><span>Section spacing</span><input type="number" min="32" max="160" data-theme-field="section_spacing" data-value-type="number" value="' + Number(theme.section_spacing || 80) + '"></label></section>';
    }
    function bindInspectorFields() {
        inspectorBody.querySelectorAll('[data-prop]').forEach(function (input) {
            input.addEventListener('input', function () {
                var block = selectedBlock(); if (!block) { return; }
                beginFieldHistory();
                var value = input.value;
                if (input.dataset.valueType === 'number') { value = Number(value || 0); }
                if (input.dataset.valueType === 'media-multi') { value = Array.from(input.selectedOptions).map(function (option) { return option.value; }).join(','); }
                block.props[input.dataset.prop] = value;
                if (input.hasAttribute('data-media-select') && input.dataset.valueType === 'number' && Object.prototype.hasOwnProperty.call(block.props, 'image_alt') && !String(block.props.image_alt || '').trim()) {
                    var selectedMedia = optionById('media_files', Number(value));
                    if (selectedMedia) { block.props.image_alt = String(selectedMedia.alt_text || selectedMedia.title || ''); }
                }
                state.changeSerial += 1; renderCanvas(); scheduleSave();
                if (input.hasAttribute('data-media-select')) { renderInspector(); }
            });
        });
        inspectorBody.querySelectorAll('[data-page-field]').forEach(function (input) {
            input.addEventListener('input', function () {
                beginFieldHistory();
                state.page[input.dataset.pageField] = input.dataset.valueType === 'number' ? Number(input.value || 0) : input.value;
                if (input.dataset.pageField === 'title') { document.getElementById('designPageTitle').textContent = input.value || 'Untitled page'; }
                state.changeSerial += 1; scheduleSave();
            });
        });
        inspectorBody.querySelectorAll('[data-style-field]').forEach(function (input) {
            input.addEventListener('input', function () {
                var block = selectedBlock(); if (!block) { return; }
                beginFieldHistory(); block.style[input.dataset.styleField] = input.dataset.valueType === 'number' ? Number(input.value || 0) : input.value;
                state.changeSerial += 1; renderCanvas(); scheduleSave();
            });
        });
        var customBackground = inspectorBody.querySelector('[data-style-custom-background]');
        if (customBackground) {
            customBackground.addEventListener('change', function () {
                var block = selectedBlock(); if (!block) { return; }
                var fallback = safeColor((state.document.theme || {}).surface_color, '#ffffff');
                beginFieldHistory(); block.style.background = customBackground.checked ? fallback : 'transparent';
                state.changeSerial += 1; renderInspector(); renderCanvas(); scheduleSave();
            });
        }
        var colorPicker = inspectorBody.querySelector('[data-style-color-picker]');
        var colorHex = inspectorBody.querySelector('[data-style-color-hex]');
        var applyBackground = function (value) {
            var block = selectedBlock(); if (!block) { return false; }
            var color = safeColor(value, '');
            if (!color) { return false; }
            beginFieldHistory(); block.style.background = color; state.changeSerial += 1;
            if (colorPicker && colorPicker.value !== color) { colorPicker.value = color; }
            if (colorHex && colorHex.value !== color) { colorHex.value = color; }
            if (colorHex) { colorHex.removeAttribute('aria-invalid'); }
            renderCanvas(); scheduleSave(); return true;
        };
        if (colorPicker) { colorPicker.addEventListener('input', function () { applyBackground(colorPicker.value); }); }
        if (colorHex) {
            colorHex.addEventListener('input', function () { if (!applyBackground(colorHex.value)) { colorHex.setAttribute('aria-invalid', 'true'); } });
            colorHex.addEventListener('blur', function () { var block = selectedBlock(); colorHex.value = block ? safeColor(block.style.background, '#ffffff') : '#ffffff'; colorHex.removeAttribute('aria-invalid'); });
        }
        inspectorBody.querySelectorAll('[data-style-color-swatch]').forEach(function (button) { button.addEventListener('click', function () { if (applyBackground(button.dataset.styleColorSwatch)) { renderInspector(); } }); });
        inspectorBody.querySelectorAll('[data-visibility-field]').forEach(function (input) {
            input.addEventListener('change', function () {
                var block = selectedBlock(); if (!block) { return; }
                beginFieldHistory(); block.visibility[input.dataset.visibilityField] = input.checked;
                state.changeSerial += 1; renderCanvas(); scheduleSave();
            });
        });
        inspectorBody.querySelectorAll('[data-theme-field]').forEach(function (input) {
            input.addEventListener('input', function () {
                beginFieldHistory(); state.document.theme[input.dataset.themeField] = input.dataset.valueType === 'number' ? Number(input.value || 0) : input.value;
                state.changeSerial += 1; renderCanvas(); scheduleSave();
            });
        });
    }

    function renderAll() {
        renderCanvas(); renderInspector(); renderValidation(); renderTemplates(); renderVersions(); updateHistoryButtons();
        var publishLabel = root.querySelector('[data-action="publish"] span');
        if (publishLabel) { publishLabel.textContent = state.isPublished ? 'Publish update' : 'Publish'; }
    }
    function updateHistoryButtons() {
        var undo = root.querySelector('[data-action="undo"]'); var redo = root.querySelector('[data-action="redo"]');
        if (undo) { undo.disabled = state.undo.length === 0; }
        if (redo) { redo.disabled = state.redo.length === 0; }
    }
    function renderValidation() {
        var errors = Array.isArray(state.validation.errors) ? state.validation.errors : [];
        var warnings = Array.isArray(state.validation.warnings) ? state.validation.warnings : [];
        validity.className = 'design-validity ' + (state.validation.valid ? 'is-valid' : 'has-issues');
        validity.innerHTML = '<i class="fas ' + (state.validation.valid ? 'fa-circle-check' : 'fa-triangle-exclamation') + '"></i><span>' + (state.validation.valid ? 'Page valid' : errors.length + ' issue(s)') + '</span>';
        var items = errors.map(function (message) { return '<li class="design-validation-item is-error"><i class="fas fa-circle-xmark"></i><div><strong>Publishing blocker</strong><span>' + e(message) + '</span></div></li>'; })
            .concat(warnings.map(function (message) { return '<li class="design-validation-item is-warning"><i class="fas fa-triangle-exclamation"></i><div><strong>Quality warning</strong><span>' + e(message) + '</span></div></li>'; }));
        document.getElementById('designValidationDetails').innerHTML = items.length ? '<ul class="design-validation-list">' + items.join('') + '</ul>' : '<div class="design-validation-item"><i class="fas fa-circle-check"></i><div><strong>Ready to publish</strong><span>The document passed structure, conversion, destination, and accessibility checks.</span></div></div>';
    }
    function renderTemplates() {
        document.getElementById('designTemplateList').innerHTML = state.templates.map(function (template) {
            return '<article class="design-template-card"><span class="design-template-card__preview design-template-card__preview--' + e(template.key) + '"><i></i><i></i><i></i><i></i></span><span class="design-template-card__body"><strong>' + e(template.name) + '</strong><span>' + e(template.description) + '</span><small>' + e(String(template.use_case).replace(/_/g, ' ')) + ' · ' + e(template.industry) + ' · v' + e(template.version) + '</small><button type="button" class="design-button design-button--primary" data-apply-template="' + e(template.key) + '">Use this template</button></span></article>';
        }).join('');
    }
    function renderVersions() {
        var list = state.versions.map(function (version) {
            return '<li class="design-version-item"><i class="fas ' + (version.type === 'published' ? 'fa-rocket' : 'fa-pen-ruler') + '"></i><div><strong>' + e(version.type === 'published' ? 'Published version ' + version.number : 'Draft checkpoint ' + version.number) + '</strong><span>' + e(version.created_at || 'Recently') + (version.created_by_email ? ' · ' + e(version.created_by_email) : '') + '</span></div><button type="button" class="design-button design-button--secondary" data-restore-version="' + Number(version.id) + '">Restore</button></li>';
        });
        document.getElementById('designVersionList').innerHTML = list.length ? '<ul class="design-version-list">' + list.join('') + '</ul>' : '<div class="design-validation-item"><i class="fas fa-clock"></i><div><strong>No checkpoints yet</strong><span>Your first autosave creates one.</span></div></div>';
    }

    function setSaveState(mode, message) {
        toolbarStatus.classList.toggle('is-saving', mode === 'saving');
        toolbarStatus.classList.toggle('has-error', mode === 'error');
        saveStatus.textContent = message;
    }
    function renderRevision() {
        if (revisionStatus) { revisionStatus.textContent = 'Revision ' + state.revision; }
    }
    function scheduleSave() {
        setSaveState('saving', 'Unsaved changes');
        window.clearTimeout(state.saveTimer);
        state.saveTimer = window.setTimeout(function () { saveNow(); }, 1100);
    }
    async function api(action, payload) {
        var response = await fetch(state.apiUrl, {
            method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrfToken },
            body: JSON.stringify(Object.assign({ action: action, page_id: state.pageId, csrf_token: state.csrfToken }, payload || {}))
        });
        var result = {};
        try { result = await response.json(); } catch (error) { result = { success: false, error: 'The server returned an unreadable response.' }; }
        if (!response.ok || !result.success) { var failure = new Error(result.error || 'Design Studio request failed.'); failure.status = response.status; throw failure; }
        return result;
    }
    async function uploadMedia(file, target) {
        if (!file || !target || !state.mediaUploadUrl) { return; }
        if (Number(file.size || 0) > 52428800) { showToast('Choose a file smaller than 50 MB.', true); return; }
        var data = new FormData();
        data.append('page_id', String(state.pageId)); data.append('csrf_token', state.csrfToken); data.append('media_file', file);
        setSaveState('saving', 'Uploading media…');
        try {
            var response = await fetch(state.mediaUploadUrl, { method: 'POST', credentials: 'same-origin', body: data });
            var result = await response.json().catch(function () { return {}; });
            if (!response.ok || !result.success || !result.media) { throw new Error(result.error || 'Media upload failed.'); }
            var media = result.media;
            state.options.media_files = (state.options.media_files || []).filter(function (item) { return Number(item.id) !== Number(media.id); });
            state.options.media_files.unshift(media);
            var block = state.document.blocks.find(function (item) { return item.id === target.blockId; });
            if (!block) { throw new Error('Select the block again before adding media.'); }
            mutate(function () {
                if (target.multiple) {
                    var selected = ids(block.props[target.prop]);
                    if (selected.indexOf(Number(media.id)) < 0) { selected.push(Number(media.id)); }
                    block.props[target.prop] = selected.join(',');
                } else {
                    block.props[target.prop] = Number(media.id);
                }
                if (Object.prototype.hasOwnProperty.call(block.props, 'image_alt') && !String(block.props.image_alt || '').trim()) {
                    block.props.image_alt = String(media.alt_text || media.title || '');
                }
            }, target.multiple ? 'Media added to gallery' : 'Media uploaded and selected');
        } catch (error) {
            setSaveState('error', 'Upload failed'); showToast(error.message || 'Media upload failed.', true);
        }
    }
    async function saveNow() {
        window.clearTimeout(state.saveTimer);
        if (state.saving || state.savedSerial === state.changeSerial) { return true; }
        state.saving = true;
        var serial = state.changeSerial;
        var documentPayload = clone(state.document);
        var pagePayload = clone(state.page);
        setSaveState('saving', 'Saving changes…');
        try {
            var result = await api('save', { revision: state.revision, document: documentPayload, page: pagePayload });
            state.revision = Number(result.editor.revision || state.revision + 1);
            renderRevision();
            state.validation = clone(result.editor.validation || state.validation);
            state.versions = clone(result.editor.versions || state.versions);
            if (state.changeSerial === serial) {
                state.document = clone(result.editor.document || state.document);
                state.page = Object.assign(state.page, clone(result.editor.page || {}));
                state.savedSerial = serial;
                setSaveState('saved', 'All changes saved');
                renderAll();
            } else {
                setSaveState('saving', 'Saving newer changes…');
                scheduleSave();
            }
            return true;
        } catch (error) {
            setSaveState('error', error.status === 409 ? 'Save conflict' : 'Save failed');
            showToast(error.message, true);
            return false;
        } finally { state.saving = false; }
    }

    function showToast(message, error) {
        window.clearTimeout(state.toastTimer); toast.hidden = false; toast.textContent = message; toast.classList.toggle('is-error', Boolean(error));
        state.toastTimer = window.setTimeout(function () { toast.hidden = true; }, 3600);
    }
    function openDialog(dialog) { if (dialog && typeof dialog.showModal === 'function') { dialog.showModal(); } }
    function closePanels() { root.classList.remove('has-library', 'has-inspector'); }

    function addBlock(type, index) {
        var block = defaultBlock(type); if (!block) { return; }
        mutate(function () {
            var at = Number.isInteger(index) ? Math.max(0, Math.min(state.document.blocks.length, index)) : state.document.blocks.length;
            state.document.blocks.splice(at, 0, block); state.selectedId = block.id; root.classList.add('has-inspector');
        }, blockLabel(block) + ' added');
    }
    function moveSelected(direction) {
        var index = state.document.blocks.findIndex(function (block) { return block.id === state.selectedId; });
        var next = index + direction; if (index < 0 || next < 0 || next >= state.document.blocks.length) { return; }
        mutate(function () { var block = state.document.blocks.splice(index, 1)[0]; state.document.blocks.splice(next, 0, block); }, 'Block moved');
    }
    function duplicateSelected() {
        var index = state.document.blocks.findIndex(function (block) { return block.id === state.selectedId; }); if (index < 0) { return; }
        mutate(function () { var copy = clone(state.document.blocks[index]); copy.id = newId(copy.type); state.document.blocks.splice(index + 1, 0, copy); state.selectedId = copy.id; }, 'Block duplicated');
    }
    function deleteSelected() {
        var index = state.document.blocks.findIndex(function (block) { return block.id === state.selectedId; }); if (index < 0) { return; }
        mutate(function () { state.document.blocks.splice(index, 1); state.selectedId = state.document.blocks[index] ? state.document.blocks[index].id : (state.document.blocks[index - 1] ? state.document.blocks[index - 1].id : null); }, 'Block deleted');
    }
    function undo() { if (!state.undo.length) { return; } state.redo.push(snapshot()); restoreSnapshot(state.undo.pop()); updateHistoryButtons(); }
    function redo() { if (!state.redo.length) { return; } state.undo.push(snapshot()); restoreSnapshot(state.redo.pop()); updateHistoryButtons(); }

    function bindCanvasDragTargets() {
        canvas.querySelectorAll('[data-block-id]').forEach(function (element) {
            element.addEventListener('dragstart', function (event) { draggedBlockId = element.dataset.blockId; event.dataTransfer.effectAllowed = 'move'; event.dataTransfer.setData('text/plain', 'block:' + draggedBlockId); element.classList.add('is-dragging'); });
            element.addEventListener('dragend', function () { draggedBlockId = ''; dropTargetId = ''; canvas.querySelectorAll('.is-dragging,.is-drop-target').forEach(function (item) { item.classList.remove('is-dragging', 'is-drop-target'); }); });
            element.addEventListener('dragover', function (event) { event.preventDefault(); dropTargetId = element.dataset.blockId; element.classList.add('is-drop-target'); });
            element.addEventListener('dragleave', function () { element.classList.remove('is-drop-target'); });
            element.addEventListener('drop', function (event) {
                event.preventDefault(); event.stopPropagation();
                var targetIndex = state.document.blocks.findIndex(function (block) { return block.id === element.dataset.blockId; });
                var payload = event.dataTransfer.getData('text/plain');
                if (payload.indexOf('add:') === 0) { addBlock(payload.slice(4), targetIndex); }
                else if (payload.indexOf('block:') === 0) {
                    var sourceId = payload.slice(6); var sourceIndex = state.document.blocks.findIndex(function (block) { return block.id === sourceId; });
                    if (sourceIndex >= 0 && targetIndex >= 0 && sourceIndex !== targetIndex) { mutate(function () { var block = state.document.blocks.splice(sourceIndex, 1)[0]; if (sourceIndex < targetIndex) { targetIndex -= 1; } state.document.blocks.splice(targetIndex, 0, block); state.selectedId = block.id; }, 'Block reordered'); }
                }
                draggedBlockId = ''; dropTargetId = '';
            });
        });
    }

    canvas.addEventListener('click', function (event) {
        var block = event.target.closest('[data-block-id]'); if (!block) { return; }
        if (event.target.closest('[data-inline-prop]') && state.selectedId === block.dataset.blockId) { return; }
        state.selectedId = block.dataset.blockId; state.inspectorTab = 'content'; root.classList.add('has-inspector'); renderAll();
        var selectedElement = canvas.querySelector('[data-block-id="' + window.CSS.escape(state.selectedId) + '"]');
        if (selectedElement) { selectedElement.focus({ preventScroll: true }); }
    });
    canvas.addEventListener('input', function (event) {
        var inline = event.target.closest('[data-inline-prop]');
        var block = selectedBlock();
        if (!inline || !block) { return; }
        beginFieldHistory();
        block.props[inline.dataset.inlineProp] = inline.innerText.replace(/\r/g, '').trim();
        state.changeSerial += 1;
        scheduleSave();
    });
    canvas.addEventListener('focusout', function (event) {
        if (event.target.closest('[data-inline-prop]')) { renderInspector(); }
    });
    canvas.addEventListener('keydown', function (event) {
        if (event.target.closest('[data-inline-single]') && event.key === 'Enter') { event.preventDefault(); event.target.blur(); }
    });
    canvas.addEventListener('dragover', function (event) { if (draggedLibraryType && !event.target.closest('[data-block-id]')) { event.preventDefault(); } });
    canvas.addEventListener('drop', function (event) {
        if (event.target.closest('[data-block-id]')) { return; }
        event.preventDefault(); var payload = event.dataTransfer.getData('text/plain'); if (payload.indexOf('add:') === 0) { addBlock(payload.slice(4)); }
        draggedLibraryType = '';
    });

    root.addEventListener('click', async function (event) {
        var uploadTrigger = event.target.closest('[data-upload-media]');
        if (uploadTrigger) {
            pendingMediaTarget = { blockId: state.selectedId, prop: uploadTrigger.dataset.mediaProp, multiple: uploadTrigger.dataset.mediaMulti === '1' };
            if (mediaUploadInput) { mediaUploadInput.click(); }
            return;
        }
        var add = event.target.closest('[data-add-block]'); if (add) { addBlock(add.dataset.addBlock); if (window.innerWidth <= 760) { root.classList.remove('has-library'); } return; }
        var action = event.target.closest('[data-action]');
        if (action) {
            var name = action.dataset.action;
            if (name === 'undo') { undo(); }
            else if (name === 'redo') { redo(); }
            else if (name === 'duplicate') { duplicateSelected(); }
            else if (name === 'delete') { deleteSelected(); }
            else if (name === 'templates') { renderTemplates(); openDialog(templateDialog); }
            else if (name === 'more') { state.selectedId = null; renderInspector(); document.getElementById('designPageSettings').innerHTML = inspectorBody.innerHTML; bindSettingsMirror(); openDialog(settingsDialog); }
            else if (name === 'validation') { renderValidation(); openDialog(validationDialog); }
            else if (name === 'history') { renderVersions(); openDialog(historyDialog); }
            else if (name === 'close-library') { root.classList.remove('has-library'); }
            else if (name === 'close-inspector') { root.classList.remove('has-inspector'); state.selectedId = null; renderAll(); }
            else if (name === 'publish') {
                if (!(await saveNow())) { return; }
                try {
                    setSaveState('saving', 'Publishing snapshot…');
                    var publishResult = await api('publish', {});
                    state.isPublished = true; state.publicUrl = String(publishResult.publication.public_url || state.publicUrl); state.versions = clone(publishResult.editor.versions || state.versions); state.validation = clone(publishResult.editor.validation || state.validation);
                    setSaveState('saved', 'Published'); showToast('Published snapshot is live.'); renderAll();
                } catch (error) { setSaveState('error', 'Publish blocked'); showToast(error.message, true); renderValidation(); openDialog(validationDialog); }
            }
        }
        var tab = event.target.closest('[data-inspector-tab]'); if (tab) { state.inspectorTab = tab.dataset.inspectorTab; root.querySelectorAll('[data-inspector-tab]').forEach(function (button) { button.classList.toggle('is-active', button === tab); }); renderInspector(); return; }
        var viewportButton = event.target.closest('[data-viewport]'); if (viewportButton) { state.viewport = viewportButton.dataset.viewport; root.querySelectorAll('[data-viewport]').forEach(function (button) { button.classList.toggle('is-active', button === viewportButton); }); viewport.className = 'design-canvas-viewport is-' + state.viewport; return; }
        var zoom = event.target.closest('[data-zoom]'); if (zoom) { state.zoom = Math.max(.55, Math.min(1.1, state.zoom + (zoom.dataset.zoom === 'in' ? .05 : -.05))); document.getElementById('designZoomValue').textContent = Math.round(state.zoom * 100) + '%'; renderCanvas(); return; }
        var apply = event.target.closest('[data-apply-template]'); if (apply) {
            var template = state.templates.find(function (item) { return item.key === apply.dataset.applyTemplate; });
            if (template && window.confirm('Replace the current blocks with the ' + template.name + ' template? You can undo this change.')) { mutate(function () { state.document = clone(template.document); state.selectedId = state.document.blocks[0] ? state.document.blocks[0].id : null; }, template.name + ' applied'); templateDialog.close(); }
            return;
        }
        var restore = event.target.closest('[data-restore-version]'); if (restore) {
            if (!window.confirm('Restore this checkpoint as the newest draft? Your current saved revision remains in history.')) { return; }
            try { var restored = await api('restore', { revision: state.revision, version_id: Number(restore.dataset.restoreVersion) }); state.document = clone(restored.editor.document); state.page = clone(restored.editor.page); state.revision = Number(restored.editor.revision); renderRevision(); state.validation = clone(restored.editor.validation); state.versions = clone(restored.editor.versions); state.changeSerial += 1; state.savedSerial = state.changeSerial; state.undo = []; state.redo = []; historyDialog.close(); renderAll(); showToast('Version restored as a new draft.'); } catch (error) { showToast(error.message, true); }
            return;
        }
        var mobile = event.target.closest('[data-mobile-panel]'); if (mobile) {
            root.querySelectorAll('[data-mobile-panel]').forEach(function (button) { button.classList.toggle('is-active', button === mobile); });
            closePanels();
            if (mobile.dataset.mobilePanel === 'blocks' || mobile.dataset.mobilePanel === 'layers') { root.classList.add('has-library'); }
            else if (mobile.dataset.mobilePanel === 'page') { state.selectedId = null; renderInspector(); root.classList.add('has-inspector'); }
            else { state.selectedId = selectedBlock() ? state.selectedId : null; state.inspectorTab = 'style'; renderInspector(); root.classList.add('has-inspector'); }
        }
    });

    function bindSettingsMirror() {
        var holder = document.getElementById('designPageSettings');
        holder.querySelectorAll('[data-page-field]').forEach(function (input) { input.addEventListener('input', function () { beginFieldHistory(); state.page[input.dataset.pageField] = input.dataset.valueType === 'number' ? Number(input.value || 0) : input.value; state.changeSerial += 1; scheduleSave(); }); });
        holder.querySelectorAll('[data-theme-field]').forEach(function (input) { input.addEventListener('input', function () { beginFieldHistory(); state.document.theme[input.dataset.themeField] = input.dataset.valueType === 'number' ? Number(input.value || 0) : input.value; state.changeSerial += 1; renderCanvas(); scheduleSave(); }); });
        var history = holder.querySelector('[data-action="history"]'); if (history) { history.addEventListener('click', function () { settingsDialog.close(); renderVersions(); openDialog(historyDialog); }); }
    }

    root.querySelectorAll('[data-add-block]').forEach(function (tile) {
        tile.addEventListener('dragstart', function (event) { draggedLibraryType = tile.dataset.addBlock; event.dataTransfer.effectAllowed = 'copy'; event.dataTransfer.setData('text/plain', 'add:' + draggedLibraryType); });
        tile.addEventListener('dragend', function () { draggedLibraryType = ''; });
    });
    var search = document.getElementById('designBlockSearch');
    function filterBlocks() {
        var query = String(search.value || '').trim().toLowerCase();
        var active = root.querySelector('.design-category-tabs .is-active'); var category = active ? active.dataset.category : 'all';
        root.querySelectorAll('[data-add-block]').forEach(function (tile) { var text = tile.textContent.toLowerCase(); var matchesCategory = category === 'all' || tile.dataset.category === category; tile.hidden = !matchesCategory || (query && text.indexOf(query) < 0); });
    }
    search.addEventListener('input', filterBlocks);
    root.querySelectorAll('[data-category]').forEach(function (button) { if (button.closest('.design-category-tabs')) { button.addEventListener('click', function () { root.querySelectorAll('.design-category-tabs [data-category]').forEach(function (item) { item.classList.toggle('is-active', item === button); }); filterBlocks(); }); } });
    if (mediaUploadInput) { mediaUploadInput.addEventListener('change', function () { var file = mediaUploadInput.files && mediaUploadInput.files[0]; var target = pendingMediaTarget; pendingMediaTarget = null; mediaUploadInput.value = ''; uploadMedia(file, target); }); }

    document.addEventListener('keydown', function (event) {
        var tag = document.activeElement && document.activeElement.tagName ? document.activeElement.tagName.toLowerCase() : '';
        var editing = ['input', 'textarea', 'select'].indexOf(tag) >= 0 || Boolean(document.activeElement && document.activeElement.isContentEditable);
        if ((event.ctrlKey || event.metaKey) && !editing && event.key.toLowerCase() === 'z') { event.preventDefault(); event.shiftKey ? redo() : undo(); }
        else if ((event.ctrlKey || event.metaKey) && !editing && event.key.toLowerCase() === 'y') { event.preventDefault(); redo(); }
        else if (event.altKey && !editing && event.key === 'ArrowUp') { event.preventDefault(); moveSelected(-1); }
        else if (event.altKey && !editing && event.key === 'ArrowDown') { event.preventDefault(); moveSelected(1); }
        else if (!editing && (event.key === 'Delete' || event.key === 'Backspace') && state.selectedId) { event.preventDefault(); deleteSelected(); }
        else if (event.key === 'Escape') { closePanels(); }
    });

    window.addEventListener('beforeunload', function (event) { if (state.savedSerial !== state.changeSerial || state.saving) { event.preventDefault(); event.returnValue = ''; } });

    state.savedSerial = state.changeSerial;
    renderAll();
    var resetInitialStagePosition = function () {
        var stage = root.querySelector('.design-stage');
        if (stage) { stage.scrollTop = 0; }
    };
    window.requestAnimationFrame(resetInitialStagePosition);
    window.setTimeout(resetInitialStagePosition, 250);
}());
