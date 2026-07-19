(function () {
    'use strict';

    var root = document.querySelector('[data-form-studio]');
    var payloadNode = document.getElementById('formStudioInitial');
    if (!root || !payloadNode) return;

    var initial = JSON.parse(payloadNode.textContent || '{}');
    var canvas = document.getElementById('formCanvas');
    var viewport = document.getElementById('formCanvasViewport');
    var inspector = document.getElementById('formInspectorBody');
    var inspectorTitle = document.getElementById('formInspectorTitle');
    var saveStatus = document.getElementById('formSaveStatus');
    var toast = document.getElementById('formToast');
    var typography = initial.typography || {};
    var webFonts = Array.isArray(typography.web_fonts) ? typography.web_fonts : [];
    var webScales = Array.isArray(typography.web_scales) ? typography.web_scales : [];
    var state = {
        document: clone(initial.document || {}),
        revision: Number(initial.revision || 0),
        validation: initial.validation || { valid: false, errors: [], warnings: [] },
        manifest: initial.manifest || [],
        templates: initial.templates || [],
        versions: initial.versions || [],
        activeStep: initial.activeStep || ((initial.document.steps || [])[0] || {}).id || 'step_1',
        selectedId: null,
        inspectorTab: 'field',
        viewport: 'desktop',
        zoom: 1,
        undo: [],
        redo: [],
        changeSerial: 0,
        savedSerial: 0,
        saving: false,
        saveTimer: 0,
        historyOpen: false,
        historyTimer: 0,
        toastTimer: 0,
        draggedFieldId: '',
        draggedLibraryType: '',
        isPublished: !!initial.isPublished
    };

    function clone(value) { return JSON.parse(JSON.stringify(value)); }
    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>'"]/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char];
        });
    }
    function typographyOption(options, value) {
        return options.find(function (item) { return String(item.value) === String(value); }) || options[0] || {};
    }
    function typographySelectOptions(options, selected) {
        return options.map(function (item) {
            return '<option value="' + esc(item.value) + '"' + (String(selected) === String(item.value) ? ' selected' : '') + '>' + esc(item.label) + '</option>';
        }).join('');
    }
    function fontCss(value) { return String(typographyOption(webFonts, value).css || 'system-ui, sans-serif'); }
    function scaleValues(value) {
        var option = typographyOption(webScales, value);
        return { heading: Number(option.heading || 1), body: Number(option.body || 1) };
    }
    function manifest(type) { return state.manifest.find(function (item) { return item.type === type; }) || null; }
    function selectedField() { return (state.document.fields || []).find(function (field) { return field.id === state.selectedId; }) || null; }
    function selectedStep() { return (state.document.steps || []).find(function (step) { return step.id === state.activeStep; }) || null; }
    function fieldLabel(type) { var item = manifest(type); return item ? item.label : String(type || 'Field'); }
    function uniqueId(prefix) {
        var seed = Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
        return String(prefix || 'item').replace(/[^a-z0-9_]/gi, '_').toLowerCase() + '_' + seed;
    }
    function uniqueName(label) {
        var base = String(label || 'field').toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'field';
        var name = base, index = 2;
        var names = (state.document.fields || []).map(function (field) { return field.name; });
        while (names.indexOf(name) >= 0) name = base + '_' + index++;
        return name;
    }
    function defaultField(type) {
        var item = manifest(type) || { label: 'Field', collects_value: true };
        var label = item.label;
        var options = [];
        if (type === 'select' || type === 'radio') options = ['Option 1', 'Option 2'];
        if (type === 'rating') label = 'How would you rate your experience?';
        if (type === 'nps') label = 'How likely are you to recommend us?';
        if (type === 'heading') label = 'Section heading';
        if (type === 'paragraph') label = 'Add supporting information here.';
        return {
            id: uniqueId(type), type: type, step_id: state.activeStep,
            name: item.collects_value ? uniqueName(label) : '', label: label,
            placeholder: '', help: '', required: false, options: options,
            default_value: '', mapping: '', width: 'full',
            validation: { min_length: null, max_length: null, min_value: null, max_value: null },
            logic: { enabled: false, action: 'show', match: 'all', conditions: [] },
            accept: type === 'file' ? 'image/*,.pdf' : '', max_size: type === 'file' ? 5 : 0
        };
    }
    function snapshot() {
        return { document: clone(state.document), selectedId: state.selectedId, activeStep: state.activeStep };
    }
    function restore(entry) {
        state.document = clone(entry.document);
        state.selectedId = entry.selectedId;
        state.activeStep = entry.activeStep || ((state.document.steps || [])[0] || {}).id || 'step_1';
        state.changeSerial++;
        renderAll();
        scheduleSave();
    }
    function pushUndo() {
        state.undo.push(snapshot());
        if (state.undo.length > 60) state.undo.shift();
        state.redo = [];
    }
    function mutate(callback, message) {
        pushUndo();
        callback();
        state.changeSerial++;
        renderAll();
        scheduleSave();
        if (message) showToast(message);
    }
    function beginFieldHistory() {
        if (!state.historyOpen) {
            pushUndo();
            state.historyOpen = true;
        }
        window.clearTimeout(state.historyTimer);
        state.historyTimer = window.setTimeout(function () { state.historyOpen = false; }, 650);
    }

    function fieldValue(field) {
        if (field.type === 'checkbox') return false;
        return field.default_value || '';
    }
    function fieldHtml(field) {
        var selected = field.id === state.selectedId ? ' is-selected' : '';
        var half = field.width === 'half' ? ' is-half' : '';
        var attrs = ' class="form-runtime__field form-runtime__field--' + esc(field.type) + half + selected + '" data-field-id="' + esc(field.id) + '" draggable="true"';
        if (field.type === 'heading') return '<div' + attrs + '><h2 contenteditable="true" data-inline-label="' + esc(field.id) + '">' + esc(field.label) + '</h2></div>';
        if (field.type === 'paragraph') return '<div' + attrs + '><p contenteditable="true" data-inline-label="' + esc(field.id) + '">' + esc(field.label) + '</p></div>';
        if (field.type === 'divider') return '<div' + attrs + '><hr></div>';
        if (field.type === 'hidden') return '<div' + attrs + '><label>Hidden value</label><input disabled value="' + esc(field.default_value || '') + '"><small>' + esc(field.name) + '</small></div>';
        var required = field.required ? ' <b>*</b>' : '';
        var html = '<div' + attrs + '>';
        if (field.type === 'checkbox') {
            html += '<label class="form-runtime__check"><input type="checkbox" disabled><span contenteditable="true" data-inline-label="' + esc(field.id) + '">' + esc(field.label) + '</span>' + required + '</label>';
        } else {
            html += '<label><span contenteditable="true" data-inline-label="' + esc(field.id) + '">' + esc(field.label) + '</span>' + required + '</label>';
            if (field.type === 'textarea') {
                html += '<textarea disabled placeholder="' + esc(field.placeholder) + '"></textarea>';
            } else if (field.type === 'select') {
                html += '<select disabled><option>' + esc(field.placeholder || 'Select an option') + '</option>' + (field.options || []).map(function (option) { return '<option>' + esc(option) + '</option>'; }).join('') + '</select>';
            } else if (field.type === 'radio') {
                html += '<div class="form-runtime__choices">' + (field.options || []).map(function (option) { return '<label><input type="radio" disabled><span>' + esc(option) + '</span></label>'; }).join('') + '</div>';
            } else if (field.type === 'rating' || field.type === 'nps') {
                var max = field.type === 'rating' ? 5 : 10, start = field.type === 'rating' ? 1 : 0, items = '';
                for (var i = start; i <= max; i++) items += '<label><input type="radio" disabled><span>' + i + '</span></label>';
                html += '<div class="form-runtime__scale">' + items + '</div>';
            } else if (field.type === 'file') {
                html += '<input type="file" disabled><small>Maximum ' + Number(field.max_size || 5) + ' MB</small>';
            } else {
                var inputType = field.type === 'phone' ? 'tel' : field.type;
                html += '<input type="' + esc(inputType) + '" disabled placeholder="' + esc(field.placeholder) + '" value="' + esc(fieldValue(field)) + '">';
            }
        }
        if (field.help) html += '<small class="form-runtime__help">' + esc(field.help) + '</small>';
        return html + '</div>';
    }
    function renderCanvas() {
        var doc = state.document;
        var theme = doc.theme || {};
        var content = doc.content || {};
        var steps = doc.steps || [];
        var typeScale = scaleValues(theme.type_scale || 'balanced');
        if (!steps.some(function (step) { return step.id === state.activeStep; })) state.activeStep = (steps[0] || {}).id || 'step_1';
        var step = selectedStep() || steps[0] || { id: 'step_1', title: 'Form', description: '' };
        var stepIndex = Math.max(0, steps.findIndex(function (item) { return item.id === step.id; }));
        var style = '--form-primary:' + esc(theme.primary_color || '#0f67ea') + ';--form-button:' + esc(theme.button_color || '#0f67ea') + ';--form-page:' + esc(theme.page_color || '#f4f7fb') + ';--form-surface:' + esc(theme.surface_color || '#fff') + ';--form-text:' + esc(theme.text_color || '#17243a') + ';--form-muted:' + esc(theme.muted_color || '#65758b') + ';--form-radius:' + Number(theme.radius || 12) + 'px;--form-gap:' + (theme.density === 'compact' ? '.78rem' : '1rem') + ';--form-heading-font:' + esc(fontCss(theme.heading_font || 'system')) + ';--form-body-font:' + esc(fontCss(theme.body_font || 'system')) + ';--form-heading-scale:' + typeScale.heading + ';--form-body-scale:' + typeScale.body;
        var logo = theme.logo_path ? '<img class="form-runtime__logo" src="' + esc(initial.assetBaseUrl + '&type=logo&v=' + encodeURIComponent(theme.logo_path)) + '" alt="">' : '';
        var header = theme.header_image_path ? '<img class="form-runtime__header" src="' + esc(initial.assetBaseUrl + '&type=header_image&v=' + encodeURIComponent(theme.header_image_path)) + '" alt="">' : '';
        var progress = '';
        if (steps.length > 1) {
            progress = '<ol class="form-runtime__steps" aria-label="Form progress">' + steps.map(function (item, index) {
                var cls = index < stepIndex ? 'is-complete' : (item.id === step.id ? 'is-active' : '');
                return '<li class="' + cls + '" data-step-indicator="' + esc(item.id) + '"><span>' + (index + 1) + '</span><strong>' + esc(item.title) + '</strong></li>';
            }).join('') + '</ol>';
        }
        var fields = (doc.fields || []).filter(function (field) { return field.step_id === step.id; }).map(fieldHtml).join('');
        var consent = stepIndex === steps.length - 1 && content.gdpr_enabled ? '<label class="form-runtime__consent"><input type="checkbox" disabled><span>' + esc(content.gdpr_label || 'I agree to the privacy policy') + ' <b>*</b></span></label>' : '';
        var actions = '<div class="form-runtime__actions">' + (stepIndex > 0 ? '<button type="button" class="form-runtime__secondary">Previous</button>' : '') + (stepIndex === steps.length - 1 ? '<button type="button" class="form-runtime__primary">' + esc(content.submit_label || 'Submit') + '</button>' : '<button type="button" class="form-runtime__primary">Next <span>→</span></button>') + '</div>';
        canvas.innerHTML = '<div class="form-runtime" data-form-schema="crm.form/v1" data-form-mode="editor" style="' + style + '"><div class="form-runtime__card">' + header + '<div class="form-runtime__inner">' + logo + '<header class="form-runtime__intro"><h1 contenteditable="true" data-inline-content="title">' + esc(content.title || 'Untitled form') + '</h1>' + (content.description ? '<p contenteditable="true" data-inline-content="description">' + esc(content.description) + '</p>' : '<p contenteditable="true" data-inline-content="description">Add a short description</p>') + '</header>' + progress + '<form class="form-runtime__form"><section class="form-runtime__step"><p class="form-runtime__step-description">' + esc(step.description || '') + '</p><div class="form-runtime__grid">' + fields + '</div>' + consent + actions + '</section></form></div></div></div>';
        canvas.style.transform = 'scale(' + state.zoom + ')';
        canvas.style.marginBottom = ((state.zoom - 1) * canvas.offsetHeight) + 'px';
        bindCanvas();
    }

    function inputField(label, prop, value, type, extra) {
        type = type || 'text'; extra = extra || '';
        return '<label class="design-field"><span>' + esc(label) + '</span><input type="' + esc(type) + '" data-field-prop="' + esc(prop) + '" value="' + esc(value == null ? '' : value) + '" ' + extra + '></label>';
    }
    function textareaField(label, prop, value) {
        return '<label class="design-field"><span>' + esc(label) + '</span><textarea data-field-prop="' + esc(prop) + '">' + esc(value || '') + '</textarea></label>';
    }
    function selectField(label, prop, value, options, attr) {
        return '<label class="design-field"><span>' + esc(label) + '</span><select ' + (attr || '') + ' data-field-prop="' + esc(prop) + '">' + options.map(function (option) { var pair = Array.isArray(option) ? option : [option, option]; return '<option value="' + esc(pair[0]) + '"' + (String(value) === String(pair[0]) ? ' selected' : '') + '>' + esc(pair[1]) + '</option>'; }).join('') + '</select></label>';
    }
    function themeHtml() {
        var t = state.document.theme || {};
        var assetControl = function (label, type, path) {
            var hasAsset = Boolean(path);
            var previewUrl = hasAsset ? initial.assetBaseUrl + '&type=' + encodeURIComponent(type) + '&v=' + encodeURIComponent(path) : '';
            var action = type === 'logo' ? 'upload-logo' : 'upload-header';
            var remove = type === 'logo' ? 'remove-logo' : 'remove-header';
            return '<div class="design-media-control"><div class="design-media-current">' + (hasAsset ? '<img src="' + esc(previewUrl) + '" alt="">' : '<i class="fas fa-image" aria-hidden="true"></i>') + '<div><strong>' + esc(label) + '</strong><span>' + (hasAsset ? 'Ready to replace or remove' : 'No image selected') + '</span></div></div><div class="design-media-actions' + (hasAsset ? '' : ' is-single') + '"><button type="button" class="design-button design-button--secondary" data-action="' + action + '"><i class="fas fa-cloud-arrow-up"></i> ' + (hasAsset ? 'Replace' : 'Choose image') + '</button>' + (hasAsset ? '<button type="button" class="design-button design-button--secondary" data-action="' + remove + '"><i class="fas fa-trash"></i> Remove</button>' : '') + '</div></div>';
        };
        return '<section class="design-inspector-section"><h3>Brand colors</h3>' +
            inputField('Primary', 'theme.primary_color', t.primary_color, 'color') + inputField('Button', 'theme.button_color', t.button_color, 'color') + inputField('Page background', 'theme.page_color', t.page_color, 'color') + inputField('Form surface', 'theme.surface_color', t.surface_color, 'color') + inputField('Text', 'theme.text_color', t.text_color, 'color') +
            '</section><section class="design-inspector-section"><h3>Brand typography</h3><label class="design-field"><span>Heading font</span><select data-field-prop="theme.heading_font">' + typographySelectOptions(webFonts, t.heading_font || 'system') + '</select></label><label class="design-field"><span>Body font</span><select data-field-prop="theme.body_font">' + typographySelectOptions(webFonts, t.body_font || 'system') + '</select></label><label class="design-field"><span>Type size</span><select data-field-prop="theme.type_scale">' + typographySelectOptions(webScales, t.type_scale || 'balanced') + '</select></label></section><section class="design-inspector-section"><h3>Brand media</h3>' + assetControl('Logo', 'logo', t.logo_path) + assetControl('Header image', 'header_image', t.header_image_path) + '</section><section class="design-inspector-section"><h3>Shape and rhythm</h3>' + inputField('Corner radius', 'theme.radius', t.radius, 'number', 'min="0" max="32"') + selectField('Density', 'theme.density', t.density, [['spacious', 'Spacious'], ['compact', 'Compact']]) + '</section>';
    }
    function renderInspector() {
        var field = selectedField(), html = '';
        inspectorTitle.textContent = field ? fieldLabel(field.type) : ((selectedStep() || {}).title || 'Form');
        root.querySelectorAll('[data-inspector-tab]').forEach(function (button) { button.classList.toggle('is-active', button.dataset.inspectorTab === state.inspectorTab); });
        if (state.inspectorTab === 'design') {
            html = themeHtml();
            if (field) html += '<section class="design-inspector-section"><h3>Field layout</h3>' + selectField('Width', 'width', field.width, [['full', 'Full width'], ['half', 'Half width']]) + '</section>';
        } else if (state.inspectorTab === 'logic') {
            if (!field || ['heading', 'paragraph', 'divider', 'hidden'].indexOf(field.type) >= 0) {
                html = '<div class="form-inspector-empty"><i class="fas fa-code-branch"></i><strong>No field logic here</strong><span>Select an input field to control when it appears.</span></div>';
            } else {
                var logic = field.logic || { enabled: false, action: 'show', match: 'all', conditions: [] };
                html = '<section class="design-inspector-section"><label class="design-field-toggle"><span>Use conditional logic</span><input type="checkbox" data-logic-prop="enabled"' + (logic.enabled ? ' checked' : '') + '></label>';
                if (logic.enabled) {
                    html += selectField('Action', 'logic.action', logic.action, [['show', 'Show this field'], ['hide', 'Hide this field']], 'data-logic-select="1"') + selectField('Match', 'logic.match', logic.match, [['all', 'All conditions'], ['any', 'Any condition']], 'data-logic-select="1"');
                    html += '<div class="form-logic-summary"><i class="fas fa-filter"></i><span>' + esc(logicSummary(field)) + '</span></div>';
                    html += (logic.conditions || []).map(function (condition, index) { return conditionHtml(condition, index, field.id); }).join('');
                    html += '<button type="button" class="form-add-option" data-action="add-condition"><i class="fas fa-plus"></i> Add condition</button>';
                }
                html += '</section>';
            }
        } else if (field) {
            html = '<section class="design-inspector-section"><h3>Field</h3>' + inputField('Label', 'label', field.label) + (field.name !== '' ? inputField('Internal name', 'name', field.name) : '') + (['heading', 'paragraph', 'divider'].indexOf(field.type) < 0 ? inputField('Placeholder', 'placeholder', field.placeholder) + textareaField('Help text', 'help', field.help) : '') + '</section>';
            if (field.name !== '') {
                html += '<section class="design-inspector-section"><label class="design-field-toggle"><span>Required</span><input type="checkbox" data-field-check="required"' + (field.required ? ' checked' : '') + '></label>' + selectField('CRM contact field', 'mapping', field.mapping, [['', 'No mapping'], ['first_name', 'First name'], ['last_name', 'Last name'], ['email', 'Email'], ['phone', 'Phone'], ['company', 'Company'], ['job_title', 'Job title'], ['lead_source', 'Lead source'], ['notes', 'Notes']]) + selectField('Step', 'step_id', field.step_id, (state.document.steps || []).map(function (step) { return [step.id, step.title]; })) + '</section>';
            }
            if (['select', 'radio'].indexOf(field.type) >= 0) html += optionsHtml(field);
            if (field.type === 'file') html += '<section class="design-inspector-section"><h3>File rules</h3>' + inputField('Accepted files', 'accept', field.accept) + inputField('Maximum size MB', 'max_size', field.max_size, 'number', 'min="1" max="20"') + '</section>';
            if (['text', 'email', 'phone', 'number', 'textarea'].indexOf(field.type) >= 0) {
                html += '<section class="design-inspector-section"><h3>Validation</h3>' + inputField('Minimum length', 'validation.min_length', field.validation.min_length, 'number', 'min="0"') + inputField('Maximum length', 'validation.max_length', field.validation.max_length, 'number', 'min="1"') + (field.type === 'number' ? inputField('Minimum value', 'validation.min_value', field.validation.min_value, 'number') + inputField('Maximum value', 'validation.max_value', field.validation.max_value, 'number') : '') + '</section>';
            }
        } else {
            var step = selectedStep();
            html = '<section class="design-inspector-section"><h3>Form content</h3><label class="design-field"><span>Form name</span><input data-content-prop="title" value="' + esc(state.document.content.title || '') + '"></label><label class="design-field"><span>Description</span><textarea data-content-prop="description">' + esc(state.document.content.description || '') + '</textarea></label></section>';
            if (step) html += '<section class="design-inspector-section"><h3>Selected step</h3><label class="design-field"><span>Step title</span><input data-step-prop="title" value="' + esc(step.title) + '"></label><label class="design-field"><span>Description</span><textarea data-step-prop="description">' + esc(step.description || '') + '</textarea></label>' + (state.document.steps.length > 1 ? '<button type="button" class="form-add-option" data-action="delete-step"><i class="fas fa-trash"></i> Delete this step</button>' : '') + '</section>';
        }
        inspector.innerHTML = html;
        bindInspector();
        document.getElementById('formInspectorFooter').hidden = !field;
    }

    function optionsHtml(field) {
        return '<section class="design-inspector-section"><h3>Options</h3><div class="form-options-editor">' + (field.options || []).map(function (option, index) { return '<div class="form-options-editor-row"><input value="' + esc(option) + '" data-option-index="' + index + '"><button type="button" data-remove-option="' + index + '" aria-label="Remove option"><i class="fas fa-trash"></i></button></div>'; }).join('') + '<button type="button" class="form-add-option" data-action="add-option"><i class="fas fa-plus"></i> Add option</button></div></section>';
    }
    function conditionHtml(condition, index, currentId) {
        var sources = (state.document.fields || []).filter(function (field) { return field.name && field.id !== currentId; });
        return '<div class="form-logic-condition"><label class="design-field"><span>Field</span><select data-condition-index="' + index + '" data-condition-prop="field_id">' + sources.map(function (source) { return '<option value="' + esc(source.id) + '"' + (source.id === condition.field_id ? ' selected' : '') + '>' + esc(source.label) + '</option>'; }).join('') + '</select></label><label class="design-field"><span>Operator</span><select data-condition-index="' + index + '" data-condition-prop="operator">' + [['equals', 'Equals'], ['not_equals', 'Does not equal'], ['contains', 'Contains'], ['not_empty', 'Is not empty'], ['empty', 'Is empty'], ['greater_than', 'Greater than'], ['less_than', 'Less than']].map(function (item) { return '<option value="' + item[0] + '"' + (item[0] === condition.operator ? ' selected' : '') + '>' + item[1] + '</option>'; }).join('') + '</select></label>' + (['not_empty', 'empty'].indexOf(condition.operator) < 0 ? '<label class="design-field"><span>Value</span><input data-condition-index="' + index + '" data-condition-prop="value" value="' + esc(condition.value || '') + '"></label>' : '') + '<button type="button" data-remove-condition="' + index + '">Remove condition</button></div>';
    }
    function logicSummary(field) {
        var logic = field.logic || {};
        var sources = {};
        (state.document.fields || []).forEach(function (item) { sources[item.id] = item.label; });
        if (!logic.conditions || !logic.conditions.length) return 'Add a condition to activate this rule.';
        var phrases = logic.conditions.map(function (condition) { return (sources[condition.field_id] || 'Field') + ' ' + condition.operator.replace(/_/g, ' ') + (condition.value ? ' “' + condition.value + '”' : ''); });
        return (logic.action === 'hide' ? 'Hide' : 'Show') + ' when ' + phrases.join(logic.match === 'any' ? ' or ' : ' and ');
    }

    function bindInspector() {
        inspector.querySelectorAll('[data-field-prop]').forEach(function (input) {
            input.addEventListener('input', function () {
                if (String(input.dataset.fieldProp || '').indexOf('theme.') === 0) {
                    beginFieldHistory(); setPath(state.document, input.dataset.fieldProp, valueFor(input)); state.changeSerial++; renderCanvas(); scheduleSave(); return;
                }
                var field = selectedField(); if (!field) return;
                beginFieldHistory(); setPath(field, input.dataset.fieldProp, valueFor(input)); state.changeSerial++; renderCanvas(); scheduleSave();
            });
            input.addEventListener('change', function () { renderInspector(); });
        });
        inspector.querySelectorAll('[data-field-check]').forEach(function (input) {
            input.addEventListener('change', function () { var field = selectedField(); if (!field) return; mutate(function () { field[input.dataset.fieldCheck] = input.checked; }); });
        });
        inspector.querySelectorAll('[data-content-prop]').forEach(function (input) {
            input.addEventListener('input', function () { beginFieldHistory(); state.document.content[input.dataset.contentProp] = input.value; state.changeSerial++; renderCanvas(); updateTitle(); scheduleSave(); });
        });
        inspector.querySelectorAll('[data-step-prop]').forEach(function (input) {
            input.addEventListener('input', function () { var step = selectedStep(); if (!step) return; beginFieldHistory(); step[input.dataset.stepProp] = input.value; state.changeSerial++; renderCanvas(); renderSteps(); scheduleSave(); });
        });
        inspector.querySelectorAll('[data-logic-prop]').forEach(function (input) {
            input.addEventListener('change', function () { var field = selectedField(); if (!field) return; mutate(function () { field.logic[input.dataset.logicProp] = input.type === 'checkbox' ? input.checked : input.value; if (input.dataset.logicProp === 'enabled' && input.checked && !field.logic.conditions.length) addConditionTo(field); }); });
        });
        inspector.querySelectorAll('[data-logic-select]').forEach(function (input) {
            input.addEventListener('change', function () { var field = selectedField(); if (!field) return; mutate(function () { setPath(field, input.dataset.fieldProp, input.value); }); });
        });
        inspector.querySelectorAll('[data-option-index]').forEach(function (input) {
            input.addEventListener('input', function () { var field = selectedField(); if (!field) return; beginFieldHistory(); field.options[Number(input.dataset.optionIndex)] = input.value; state.changeSerial++; renderCanvas(); scheduleSave(); });
        });
        inspector.querySelectorAll('[data-remove-option]').forEach(function (button) { button.addEventListener('click', function () { var field = selectedField(); if (!field) return; mutate(function () { field.options.splice(Number(button.dataset.removeOption), 1); }, 'Option removed'); }); });
        inspector.querySelectorAll('[data-condition-index]').forEach(function (input) {
            input.addEventListener('input', function () { var field = selectedField(); if (!field) return; beginFieldHistory(); field.logic.conditions[Number(input.dataset.conditionIndex)][input.dataset.conditionProp] = input.value; state.changeSerial++; scheduleSave(); });
            input.addEventListener('change', renderInspector);
        });
        inspector.querySelectorAll('[data-remove-condition]').forEach(function (button) { button.addEventListener('click', function () { var field = selectedField(); if (!field) return; mutate(function () { field.logic.conditions.splice(Number(button.dataset.removeCondition), 1); }, 'Condition removed'); }); });
    }
    function valueFor(input) {
        if (input.type === 'number') return input.value === '' ? null : Number(input.value);
        return input.value;
    }
    function setPath(object, path, value) {
        var parts = String(path).split('.'), target = object;
        if (parts[0] === 'theme') { target = state.document.theme; parts.shift(); }
        for (var i = 0; i < parts.length - 1; i++) { if (!target[parts[i]]) target[parts[i]] = {}; target = target[parts[i]]; }
        target[parts[parts.length - 1]] = value;
    }

    function bindCanvas() {
        canvas.querySelectorAll('[data-field-id]').forEach(function (element) {
            element.addEventListener('click', function (event) { if (event.target.closest('[contenteditable]')) return; state.selectedId = element.dataset.fieldId; renderAll(); root.classList.add('has-inspector'); });
            element.addEventListener('dragstart', function (event) { state.draggedFieldId = element.dataset.fieldId; event.dataTransfer.setData('text/plain', 'field:' + state.draggedFieldId); element.classList.add('is-dragging'); });
            element.addEventListener('dragend', function () { state.draggedFieldId = ''; canvas.querySelectorAll('.is-dragging,.is-drop-target').forEach(function (item) { item.classList.remove('is-dragging', 'is-drop-target'); }); });
            element.addEventListener('dragover', function (event) { event.preventDefault(); element.classList.add('is-drop-target'); });
            element.addEventListener('dragleave', function () { element.classList.remove('is-drop-target'); });
            element.addEventListener('drop', function (event) {
                event.preventDefault(); var targetId = element.dataset.fieldId;
                if (state.draggedFieldId && state.draggedFieldId !== targetId) reorderField(state.draggedFieldId, targetId);
                else if (state.draggedLibraryType) addField(state.draggedLibraryType, targetId);
            });
        });
        canvas.querySelectorAll('[data-inline-label]').forEach(function (editable) {
            editable.addEventListener('focus', function () { state.selectedId = editable.dataset.inlineLabel; renderInspector(); });
            editable.addEventListener('input', function () { var field = (state.document.fields || []).find(function (item) { return item.id === editable.dataset.inlineLabel; }); if (!field) return; beginFieldHistory(); field.label = editable.textContent.trim().slice(0, 180); state.changeSerial++; scheduleSave(); });
            editable.addEventListener('keydown', function (event) { if (event.key === 'Enter') { event.preventDefault(); editable.blur(); } });
        });
        canvas.querySelectorAll('[data-inline-content]').forEach(function (editable) {
            editable.addEventListener('input', function () { beginFieldHistory(); var key = editable.dataset.inlineContent; state.document.content[key] = editable.textContent.trim().slice(0, key === 'title' ? 255 : 1000); state.changeSerial++; updateTitle(); scheduleSave(); });
            editable.addEventListener('keydown', function (event) { if (event.key === 'Enter' && editable.dataset.inlineContent === 'title') { event.preventDefault(); editable.blur(); } });
        });
        canvas.addEventListener('dragover', function (event) { if (state.draggedLibraryType) event.preventDefault(); });
        canvas.addEventListener('drop', function (event) { if (state.draggedLibraryType && !event.target.closest('[data-field-id]')) { event.preventDefault(); addField(state.draggedLibraryType); } });
    }

    function addField(type, beforeId) {
        if (type === 'page_break') { addStep(); return; }
        mutate(function () {
            var field = defaultField(type), fields = state.document.fields || (state.document.fields = []);
            var index = beforeId ? fields.findIndex(function (item) { return item.id === beforeId; }) : -1;
            if (index >= 0) fields.splice(index, 0, field); else fields.push(field);
            state.selectedId = field.id;
        }, fieldLabel(type) + ' added');
        root.classList.add('has-inspector');
    }
    function reorderField(sourceId, targetId) {
        mutate(function () {
            var fields = state.document.fields, source = fields.findIndex(function (item) { return item.id === sourceId; }), target = fields.findIndex(function (item) { return item.id === targetId; });
            if (source < 0 || target < 0) return;
            var field = fields.splice(source, 1)[0]; if (source < target) target--;
            field.step_id = state.activeStep; fields.splice(target, 0, field); state.selectedId = field.id;
        }, 'Field reordered');
    }
    function addStep() {
        mutate(function () { var step = { id: uniqueId('step'), title: 'Step ' + ((state.document.steps || []).length + 1), description: '' }; state.document.steps.push(step); state.activeStep = step.id; state.selectedId = null; }, 'Step added');
        switchLibrary('steps');
    }
    function deleteStep() {
        if ((state.document.steps || []).length <= 1) return;
        mutate(function () {
            var index = state.document.steps.findIndex(function (step) { return step.id === state.activeStep; });
            var replacement = state.document.steps[Math.max(0, index - 1)];
            state.document.fields.forEach(function (field) { if (field.step_id === state.activeStep) field.step_id = replacement.id; });
            state.document.steps.splice(index, 1); state.activeStep = replacement.id; state.selectedId = null;
        }, 'Step removed; its fields moved to the previous step');
    }
    function moveSelected(direction) {
        var field = selectedField(); if (!field) return;
        var inStep = state.document.fields.filter(function (item) { return item.step_id === field.step_id; });
        var current = inStep.findIndex(function (item) { return item.id === field.id; }), next = current + direction;
        if (next < 0 || next >= inStep.length) return;
        reorderField(field.id, inStep[next].id);
    }
    function duplicateSelected() {
        var field = selectedField(); if (!field) return;
        mutate(function () { var copy = clone(field); copy.id = uniqueId(copy.type); if (copy.name) copy.name = uniqueName(copy.name); var index = state.document.fields.findIndex(function (item) { return item.id === field.id; }); state.document.fields.splice(index + 1, 0, copy); state.selectedId = copy.id; }, 'Field duplicated');
    }
    function deleteSelected() {
        var field = selectedField(); if (!field) return;
        mutate(function () { var index = state.document.fields.findIndex(function (item) { return item.id === field.id; }); state.document.fields.splice(index, 1); state.selectedId = null; }, 'Field removed');
    }
    function addConditionTo(field) {
        var source = (state.document.fields || []).find(function (item) { return item.id !== field.id && item.name; });
        if (!source) return;
        field.logic.conditions.push({ field_id: source.id, operator: 'not_empty', value: '' });
    }

    function renderSteps() {
        var list = document.getElementById('formStepList'); if (!list) return;
        list.innerHTML = (state.document.steps || []).map(function (step, index) {
            var count = state.document.fields.filter(function (field) { return field.step_id === step.id; }).length;
            return '<button type="button" class="form-step-row' + (step.id === state.activeStep ? ' is-active' : '') + '" data-step-id="' + esc(step.id) + '"><span>' + (index + 1) + '</span><span><strong>' + esc(step.title) + '</strong><small>' + count + ' item' + (count === 1 ? '' : 's') + '</small></span><i class="fas fa-chevron-right"></i></button>';
        }).join('');
        list.querySelectorAll('[data-step-id]').forEach(function (button) { button.addEventListener('click', function () { state.activeStep = button.dataset.stepId; state.selectedId = null; renderAll(); }); });
    }
    function renderTemplates() {
        var card = function (template, compact) {
            if (compact) return '<button type="button" class="form-template-mini" data-apply-template="' + esc(template.key) + '"><strong>' + esc(template.name) + '</strong><span>' + esc(template.description) + '</span></button>';
            return '<article class="design-template-card"><span class="design-template-card__preview" aria-hidden="true"><i></i><i></i><i></i><i></i></span><span class="design-template-card__body"><strong>' + esc(template.name) + '</strong><span>' + esc(template.description) + '</span><small>' + esc(String(template.use_case || '').replace(/_/g, ' ')) + ' · v' + esc(template.version) + '</small><button type="button" class="design-button design-button--secondary" data-apply-template="' + esc(template.key) + '">Use template</button></span></article>';
        };
        document.getElementById('formTemplateMiniList').innerHTML = state.templates.map(function (template) { return card(template, true); }).join('');
        document.getElementById('formTemplateList').innerHTML = state.templates.map(function (template) { return card(template, false); }).join('');
        root.querySelectorAll('[data-apply-template]').forEach(function (button) { button.addEventListener('click', function () { applyTemplate(button.dataset.applyTemplate); }); });
    }
    function applyTemplate(key) {
        var template = state.templates.find(function (item) { return item.key === key; }); if (!template) return;
        if (!window.confirm('Replace the current fields and steps with the ' + template.name + ' template? You can undo this change.')) return;
        mutate(function () { var title = state.document.content.title; state.document = clone(template.document); state.document.content.title = title; state.activeStep = state.document.steps[0].id; state.selectedId = state.document.fields[0] ? state.document.fields[0].id : null; }, template.name + ' applied');
        closeDialog(document.getElementById('formTemplateDialog'));
    }

    function renderSettings() {
        var body = document.getElementById('formSettingsBody'), content = state.document.content, theme = state.document.theme;
        body.innerHTML = '<div class="design-settings-grid"><section class="design-inspector-section"><h3>Submission</h3><label class="design-field"><span>Submit button</span><input data-settings-content="submit_label" value="' + esc(content.submit_label || 'Submit') + '"></label><label class="design-field"><span>Success message</span><textarea data-settings-content="success_message">' + esc(content.success_message || '') + '</textarea></label><label class="design-field"><span>Redirect URL <small>optional</small></span><input type="url" data-settings-content="redirect_url" value="' + esc(content.redirect_url || '') + '" placeholder="https://example.com/thanks"></label><label class="design-field-toggle"><span>Require privacy consent</span><input type="checkbox" data-settings-content-check="gdpr_enabled"' + (content.gdpr_enabled ? ' checked' : '') + '></label><label class="design-field"><span>Consent label</span><textarea data-settings-content="gdpr_label">' + esc(content.gdpr_label || '') + '</textarea></label></section><section class="design-inspector-section"><h3>Brand assets</h3><div class="design-inspector-help"><strong>Logo</strong><br>' + (theme.logo_path ? esc(theme.logo_path) : 'No logo selected') + '</div><div class="design-settings-actions"><button type="button" class="design-button design-button--secondary" data-action="upload-logo">Choose logo</button>' + (theme.logo_path ? '<button type="button" class="design-button design-button--secondary" data-action="remove-logo">Remove</button>' : '') + '</div><div class="design-inspector-help"><strong>Header image</strong><br>' + (theme.header_image_path ? esc(theme.header_image_path) : 'No header image selected') + '</div><div class="design-settings-actions"><button type="button" class="design-button design-button--secondary" data-action="upload-header">Choose image</button>' + (theme.header_image_path ? '<button type="button" class="design-button design-button--secondary" data-action="remove-header">Remove</button>' : '') + '</div><button type="button" class="design-button design-button--secondary" data-action="history"><i class="fas fa-clock-rotate-left"></i> Version history</button>' + (state.isPublished ? '<a class="design-button design-button--secondary" href="' + esc(initial.publicUrl) + '" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Open live form</a>' : '') + '</section></div>';
        body.querySelectorAll('[data-settings-content]').forEach(function (input) { input.addEventListener('input', function () { beginFieldHistory(); content[input.dataset.settingsContent] = input.value; state.changeSerial++; renderCanvas(); scheduleSave(); }); });
        body.querySelectorAll('[data-settings-content-check]').forEach(function (input) { input.addEventListener('change', function () { mutate(function () { content[input.dataset.settingsContentCheck] = input.checked; }); renderSettings(); }); });
    }
    function renderValidation() {
        var target = document.getElementById('formValidationDetails'), errors = state.validation.errors || [], warnings = state.validation.warnings || [];
        var items = errors.map(function (message) { return '<li class="design-validation-item is-error"><i class="fas fa-circle-xmark"></i><div><strong>Publishing blocker</strong><span>' + esc(message) + '</span></div></li>'; }).concat(warnings.map(function (message) { return '<li class="design-validation-item is-warning"><i class="fas fa-triangle-exclamation"></i><div><strong>Quality warning</strong><span>' + esc(message) + '</span></div></li>'; }));
        target.innerHTML = items.length ? '<ul class="design-validation-list">' + items.join('') + '</ul>' : '<div class="design-empty-state"><i class="fas fa-circle-check"></i><h3>Ready to publish</h3><p>All required form checks pass.</p></div>';
        var badge = document.getElementById('formValidity'); badge.className = 'design-validity ' + (state.validation.valid ? 'is-valid' : 'has-issues'); badge.innerHTML = '<i class="fas ' + (state.validation.valid ? 'fa-circle-check' : 'fa-triangle-exclamation') + '"></i><span>' + (state.validation.valid ? 'Form valid' : errors.length + ' issue(s)') + '</span>';
    }
    function renderVersions() {
        document.getElementById('formVersionList').innerHTML = state.versions.length ? '<div class="design-version-list">' + state.versions.map(function (version) { return '<article class="design-version-row"><div><strong>Revision ' + version.revision + '</strong><span>' + esc(version.type) + ' · ' + esc(version.created_at) + (version.created_by_email ? ' · ' + esc(version.created_by_email) : '') + '</span></div><button type="button" class="design-button design-button--secondary" data-restore-version="' + version.id + '">Restore</button></article>'; }).join('') + '</div>' : '<div class="design-empty-state"><p>No version checkpoints yet.</p></div>';
        document.querySelectorAll('[data-restore-version]').forEach(function (button) { button.addEventListener('click', function () { if (window.confirm('Restore this version as the newest draft?')) api('restore', { version_id: Number(button.dataset.restoreVersion) }).then(applyEditor).catch(handleError); }); });
    }

    function renderAll() { renderCanvas(); renderInspector(); renderSteps(); renderTemplates(); renderValidation(); updateHistory(); updateTitle(); }
    function updateTitle() { var title = state.document.content.title || 'Untitled form'; document.getElementById('formStudioTitle').textContent = title; document.title = 'Form Studio - ' + title; }
    function updateHistory() { var undo = root.querySelector('[data-action="undo"]'), redo = root.querySelector('[data-action="redo"]'); if (undo) undo.disabled = !state.undo.length; if (redo) redo.disabled = !state.redo.length; }
    function setSaveState(mode, message) { var box = saveStatus.closest('.design-toolbar__status'); box.classList.toggle('is-saving', mode === 'saving'); box.classList.toggle('has-error', mode === 'error'); saveStatus.textContent = message; box.querySelector('small').textContent = 'Revision ' + state.revision; }
    function scheduleSave() { window.clearTimeout(state.saveTimer); setSaveState('saving', 'Unsaved changes'); state.saveTimer = window.setTimeout(saveNow, 900); }
    async function api(action, extra) {
        var response = await fetch(initial.apiUrl, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': initial.csrfToken }, body: JSON.stringify(Object.assign({ action: action, form_id: initial.formId, revision: state.revision, document: state.document, form: { name: state.document.content.title }, csrf_token: initial.csrfToken }, extra || {})) });
        var result = await response.json().catch(function () { return {}; });
        if (!response.ok || !result.success) throw new Error(result.error || 'Form Studio request failed.');
        return result.editor;
    }
    async function saveNow() {
        if (state.saving || state.savedSerial === state.changeSerial) return;
        state.saving = true; var serial = state.changeSerial; setSaveState('saving', 'Saving…');
        try { var editor = await api('save'); applyEditor(editor, false); state.savedSerial = serial; setSaveState('saved', 'Saved just now'); if (state.savedSerial !== state.changeSerial) scheduleSave(); }
        catch (error) { handleError(error); }
        finally { state.saving = false; }
    }
    function applyEditor(editor, replaceDocument) {
        if (!editor) return;
        state.revision = Number(editor.revision || state.revision); state.validation = editor.validation || state.validation; state.versions = editor.versions || state.versions; state.isPublished = !!editor.is_published;
        if (replaceDocument !== false && editor.document) state.document = clone(editor.document);
        renderAll(); renderSettings(); renderVersions();
        return editor;
    }
    function handleError(error) { setSaveState('error', error.message || 'Save failed'); showToast(error.message || 'Form Studio request failed.', true); }
    function showToast(message, error) { window.clearTimeout(state.toastTimer); toast.textContent = message; toast.classList.toggle('has-error', !!error); toast.hidden = false; state.toastTimer = window.setTimeout(function () { toast.hidden = true; }, 3600); }
    function openDialog(dialog) { if (dialog && typeof dialog.showModal === 'function') dialog.showModal(); }
    function closeDialog(dialog) { if (dialog && typeof dialog.close === 'function' && dialog.open) dialog.close(); }
    function switchLibrary(name) { root.querySelectorAll('[data-library-tab]').forEach(function (button) { button.classList.toggle('is-active', button.dataset.libraryTab === name); }); root.querySelectorAll('[data-library-panel]').forEach(function (panel) { panel.hidden = panel.dataset.libraryPanel !== name; }); if (window.innerWidth <= 840) root.classList.add('has-library'); }

    async function uploadAsset(type, file) {
        if (!file) return; var data = new FormData(); data.append('form_id', initial.formId); data.append('form_uuid', initial.uuid); data.append('type', type); data.append('file', file); data.append('csrf_token', initial.csrfToken);
        try { var response = await fetch(initial.assetUploadUrl, { method: 'POST', body: data }); var result = await response.json(); if (!response.ok || !result.success) throw new Error(result.error || 'Upload failed.'); mutate(function () { state.document.theme[type === 'logo' ? 'logo_path' : 'header_image_path'] = result.path; }, type === 'logo' ? 'Logo updated' : 'Header image updated'); renderSettings(); }
        catch (error) { handleError(error); }
    }

    root.querySelectorAll('[data-add-field]').forEach(function (tile) {
        tile.addEventListener('click', function () { addField(tile.dataset.addField); });
        tile.addEventListener('dragstart', function (event) { state.draggedLibraryType = tile.dataset.addField; event.dataTransfer.setData('text/plain', 'library:' + state.draggedLibraryType); });
        tile.addEventListener('dragend', function () { state.draggedLibraryType = ''; });
    });
    root.addEventListener('click', async function (event) {
        var action = event.target.closest('[data-action]');
        if (action) {
            var name = action.dataset.action;
            if (name === 'undo' && state.undo.length) { state.redo.push(snapshot()); restore(state.undo.pop()); }
            else if (name === 'redo' && state.redo.length) { state.undo.push(snapshot()); restore(state.redo.pop()); }
            else if (name === 'duplicate') duplicateSelected();
            else if (name === 'delete') deleteSelected();
            else if (name === 'add-step') addStep();
            else if (name === 'delete-step') deleteStep();
            else if (name === 'add-option') { var field = selectedField(); if (field) mutate(function () { field.options.push('New option'); }, 'Option added'); }
            else if (name === 'add-condition') { var logicField = selectedField(); if (logicField) mutate(function () { addConditionTo(logicField); }, 'Condition added'); }
            else if (name === 'templates') { renderTemplates(); openDialog(document.getElementById('formTemplateDialog')); }
            else if (name === 'validation') { renderValidation(); openDialog(document.getElementById('formValidationDialog')); }
            else if (name === 'more') { renderSettings(); openDialog(document.getElementById('formSettingsDialog')); }
            else if (name === 'history') { closeDialog(document.getElementById('formSettingsDialog')); renderVersions(); openDialog(document.getElementById('formHistoryDialog')); }
            else if (name === 'publish') { await saveNow(); try { var published = await api('publish'); applyEditor(published); showToast('Published form is live.'); } catch (error) { handleError(error); renderValidation(); openDialog(document.getElementById('formValidationDialog')); } }
            else if (name === 'upload-logo') document.getElementById('formLogoUpload').click();
            else if (name === 'upload-header') document.getElementById('formHeaderUpload').click();
            else if (name === 'remove-logo') { mutate(function () { state.document.theme.logo_path = ''; }, 'Logo removed'); renderSettings(); }
            else if (name === 'remove-header') { mutate(function () { state.document.theme.header_image_path = ''; }, 'Header image removed'); renderSettings(); }
            else if (name === 'close-inspector') root.classList.remove('has-inspector');
        }
        var tab = event.target.closest('[data-inspector-tab]'); if (tab) { state.inspectorTab = tab.dataset.inspectorTab; renderInspector(); }
        var libraryTab = event.target.closest('[data-library-tab]'); if (libraryTab) switchLibrary(libraryTab.dataset.libraryTab);
        var view = event.target.closest('[data-viewport]'); if (view) { state.viewport = view.dataset.viewport; root.querySelectorAll('[data-viewport]').forEach(function (button) { button.classList.toggle('is-active', button === view); }); viewport.className = 'design-canvas-viewport is-' + state.viewport; }
        var zoom = event.target.closest('[data-zoom]'); if (zoom) { state.zoom = Math.max(.65, Math.min(1.15, state.zoom + (zoom.dataset.zoom === 'in' ? .05 : -.05))); document.getElementById('formZoomValue').textContent = Math.round(state.zoom * 100) + '%'; renderCanvas(); }
        var mobile = event.target.closest('[data-mobile-panel]'); if (mobile) { root.querySelectorAll('[data-mobile-panel]').forEach(function (button) { button.classList.toggle('is-active', button === mobile); }); if (mobile.dataset.mobilePanel === 'blocks' || mobile.dataset.mobilePanel === 'layers') { switchLibrary(mobile.dataset.mobilePanel === 'blocks' ? 'fields' : 'steps'); root.classList.add('has-library'); root.classList.remove('has-inspector'); } else { state.inspectorTab = mobile.dataset.mobilePanel === 'style' ? 'design' : 'field'; root.classList.add('has-inspector'); root.classList.remove('has-library'); renderInspector(); } }
    });
    document.getElementById('formFieldSearch').addEventListener('input', function (event) { var query = event.target.value.trim().toLowerCase(); root.querySelectorAll('[data-search-label]').forEach(function (tile) { tile.hidden = query !== '' && tile.dataset.searchLabel.indexOf(query) < 0; }); });
    document.getElementById('formLogoUpload').addEventListener('change', function () { uploadAsset('logo', this.files && this.files[0]); this.value = ''; });
    document.getElementById('formHeaderUpload').addEventListener('change', function () { uploadAsset('header_image', this.files && this.files[0]); this.value = ''; });
    document.addEventListener('keydown', function (event) {
        var tag = document.activeElement && document.activeElement.tagName ? document.activeElement.tagName.toLowerCase() : '', editing = ['input', 'textarea', 'select'].indexOf(tag) >= 0 || document.activeElement.isContentEditable;
        if ((event.ctrlKey || event.metaKey) && !editing && event.key.toLowerCase() === 'z') { event.preventDefault(); event.shiftKey ? (state.redo.length && restore(state.redo.pop())) : (state.undo.length && (state.redo.push(snapshot()), restore(state.undo.pop()))); }
        else if ((event.ctrlKey || event.metaKey) && !editing && event.key.toLowerCase() === 'y' && state.redo.length) { event.preventDefault(); state.undo.push(snapshot()); restore(state.redo.pop()); }
        else if (event.altKey && !editing && event.key === 'ArrowUp') { event.preventDefault(); moveSelected(-1); }
        else if (event.altKey && !editing && event.key === 'ArrowDown') { event.preventDefault(); moveSelected(1); }
        else if (!editing && (event.key === 'Delete' || event.key === 'Backspace') && state.selectedId) { event.preventDefault(); deleteSelected(); }
        else if (event.key === 'Escape') { root.classList.remove('has-library', 'has-inspector'); }
    });
    window.addEventListener('beforeunload', function (event) { if (state.savedSerial !== state.changeSerial || state.saving) { event.preventDefault(); event.returnValue = ''; } });

    renderAll(); renderSettings(); renderVersions(); setSaveState('saved', 'All changes saved');
    var resetInitialStagePosition = function () {
        var stage = root.querySelector('.design-stage');
        if (stage) { stage.scrollTop = 0; }
    };
    window.requestAnimationFrame(resetInitialStagePosition);
    window.setTimeout(resetInitialStagePosition, 250);
}());
