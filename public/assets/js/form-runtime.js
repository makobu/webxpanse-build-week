(function () {
    'use strict';

    function controls(field) {
        return Array.prototype.slice.call(field.querySelectorAll('input, select, textarea, button'));
    }

    function valueFor(field) {
        if (!field) { return ''; }
        var radio = field.querySelector('input[type="radio"]:checked');
        if (radio) { return radio.value; }
        var checkbox = field.querySelector('input[type="checkbox"]');
        if (checkbox) { return checkbox.checked ? checkbox.value : ''; }
        var input = field.querySelector('input, select, textarea');
        return input ? String(input.value || '').trim() : '';
    }

    function matches(actual, operator, expected) {
        actual = String(actual == null ? '' : actual).trim();
        expected = String(expected == null ? '' : expected);
        if (operator === 'not_equals') { return actual !== expected; }
        if (operator === 'contains') { return expected !== '' && actual.toLowerCase().indexOf(expected.toLowerCase()) >= 0; }
        if (operator === 'not_empty') { return actual !== ''; }
        if (operator === 'empty') { return actual === ''; }
        if (operator === 'greater_than') { return actual !== '' && !isNaN(actual) && !isNaN(expected) && Number(actual) > Number(expected); }
        if (operator === 'less_than') { return actual !== '' && !isNaN(actual) && !isNaN(expected) && Number(actual) < Number(expected); }
        return actual === expected;
    }

    function setup(root) {
        if (root.dataset.formMode !== 'public') { return; }
        var form = root.querySelector('.form-runtime__form');
        if (!form) { return; }
        var steps = Array.prototype.slice.call(form.querySelectorAll('[data-form-step]'));
        var indicators = Array.prototype.slice.call(root.querySelectorAll('[data-step-indicator]'));
        var fields = Array.prototype.slice.call(form.querySelectorAll('[data-field-id]'));
        var activeStepInput = form.querySelector('[data-form-active-step-input]');
        var byId = {};
        var current = 0;

        fields.forEach(function (field) { byId[field.dataset.fieldId] = field; });

        function updateLogic() {
            fields.forEach(function (field) {
                var logic = {};
                try { logic = JSON.parse(field.dataset.fieldLogic || '{}'); } catch (error) { logic = {}; }
                var visible = true;
                if (logic.enabled && Array.isArray(logic.conditions) && logic.conditions.length) {
                    var results = logic.conditions.map(function (condition) {
                        return matches(valueFor(byId[condition.field_id]), condition.operator, condition.value);
                    });
                    var matched = logic.match === 'any' ? results.indexOf(true) >= 0 : results.indexOf(false) < 0;
                    visible = logic.action === 'hide' ? !matched : matched;
                }
                field.hidden = !visible;
                controls(field).forEach(function (control) {
                    if (control.type !== 'button') { control.disabled = !visible; }
                });
            });
            notifyHeight();
        }

        function showStep(index, focusHeading) {
            current = Math.max(0, Math.min(steps.length - 1, index));
            steps.forEach(function (step, stepIndex) { step.hidden = stepIndex !== current; });
            indicators.forEach(function (indicator, stepIndex) {
                indicator.classList.toggle('is-active', stepIndex === current);
                indicator.classList.toggle('is-complete', stepIndex < current);
            });
            if (activeStepInput) { activeStepInput.value = steps[current].dataset.formStep || ''; }
            updateLogic();
            if (focusHeading) {
                var target = steps[current].querySelector('input:not([type="hidden"]), select, textarea, button');
                if (target) { target.focus({ preventScroll: true }); }
                root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
            notifyHeight();
        }

        function validateStep(step) {
            updateLogic();
            var candidates = Array.prototype.slice.call(step.querySelectorAll('input, select, textarea')).filter(function (control) {
                return !control.disabled && !control.closest('[hidden]');
            });
            for (var index = 0; index < candidates.length; index += 1) {
                if (!candidates[index].checkValidity()) {
                    candidates[index].reportValidity();
                    return false;
                }
            }
            return true;
        }

        form.addEventListener('input', updateLogic);
        form.addEventListener('change', updateLogic);
        form.addEventListener('click', function (event) {
            var next = event.target.closest('[data-form-next]');
            if (next && validateStep(steps[current])) { showStep(current + 1, true); }
            var previous = event.target.closest('[data-form-previous]');
            if (previous) { showStep(current - 1, true); }
        });
        form.addEventListener('invalid', function (event) {
            var step = event.target.closest('[data-form-step]');
            var index = steps.indexOf(step);
            if (index >= 0 && index !== current) { showStep(index, false); }
        }, true);

        var requestedStep = steps.findIndex(function (step) { return step.dataset.formStep === root.dataset.formActiveStep; });
        showStep(requestedStep >= 0 ? requestedStep : 0, false);
    }

    function notifyHeight() {
        if (window.parent === window) { return; }
        var height = Math.max(document.documentElement.scrollHeight, document.body ? document.body.scrollHeight : 0);
        window.parent.postMessage({ type: 'crm-form-height', height: height }, '*');
    }

    document.addEventListener('DOMContentLoaded', function () {
        Array.prototype.forEach.call(document.querySelectorAll('.form-runtime[data-form-schema]'), setup);
        notifyHeight();
        if ('ResizeObserver' in window) {
            new ResizeObserver(notifyHeight).observe(document.documentElement);
        } else {
            window.addEventListener('resize', notifyHeight);
        }
    });
}());
