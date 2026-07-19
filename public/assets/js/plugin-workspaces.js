(function () {
    'use strict';

    var storagePrefix = 'crm.plugin-workspace-guide.';

    function storedState(key) {
        try {
            return window.localStorage.getItem(storagePrefix + key) || '';
        } catch (error) {
            return '';
        }
    }

    function saveState(key, value) {
        try {
            window.localStorage.setItem(storagePrefix + key, value);
        } catch (error) {
            // The guide still works for this page when storage is unavailable.
        }
    }

    function setHidden(root, hidden, moveFocus) {
        var content = root.querySelector('[data-workspace-guide-content]');
        var hideButton = root.querySelector('[data-workspace-guide-hide]');
        var showButton = root.querySelector('[data-workspace-guide-show]');
        if (!content || !hideButton || !showButton) {
            return;
        }

        content.hidden = hidden;
        showButton.hidden = !hidden;
        hideButton.setAttribute('aria-expanded', hidden ? 'false' : 'true');
        showButton.setAttribute('aria-expanded', hidden ? 'false' : 'true');

        if (moveFocus) {
            (hidden ? showButton : hideButton).focus();
        }
    }

    document.querySelectorAll('[data-workspace-guide]').forEach(function (root) {
        var key = root.getAttribute('data-guide-key') || 'marketing-product';
        var hideButton = root.querySelector('[data-workspace-guide-hide]');
        var showButton = root.querySelector('[data-workspace-guide-show]');
        setHidden(root, storedState(key) === 'hidden', false);

        if (hideButton) {
            hideButton.addEventListener('click', function () {
                saveState(key, 'hidden');
                setHidden(root, true, true);
            });
        }

        if (showButton) {
            showButton.addEventListener('click', function () {
                saveState(key, 'visible');
                setHidden(root, false, true);
            });
        }
    });
}());
