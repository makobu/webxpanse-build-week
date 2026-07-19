(function () {
    'use strict';

    var searchInput = document.querySelector('[data-signature-search]');
    var cards = Array.prototype.slice.call(document.querySelectorAll('[data-signature-card]'));
    var emptyFilter = document.querySelector('[data-signature-filter-empty]');
    var toast = document.querySelector('[data-signature-toast]');
    var toastTimer = null;

    function showToast(message) {
        if (!toast) return;
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(toastTimer);
        toastTimer = window.setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 2200);
    }

    if (searchInput && cards.length) {
        searchInput.addEventListener('input', function () {
            var query = searchInput.value.trim().toLowerCase();
            var visibleCount = 0;

            cards.forEach(function (card) {
                var haystack = String(card.getAttribute('data-signature-search-text') || '').toLowerCase();
                var visible = query === '' || haystack.indexOf(query) !== -1;
                card.hidden = !visible;
                if (visible) visibleCount += 1;
            });

            if (emptyFilter) {
                emptyFilter.classList.toggle('is-visible', visibleCount === 0);
            }
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-signature-confirm]');
        if (!form) return;

        var message = form.getAttribute('data-signature-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
        }
    });

    function fallbackCopy(html) {
        var container = document.createElement('div');
        container.setAttribute('contenteditable', 'true');
        container.style.position = 'fixed';
        container.style.left = '-9999px';
        container.innerHTML = html;
        document.body.appendChild(container);

        var selection = window.getSelection();
        var range = document.createRange();
        range.selectNodeContents(container);
        selection.removeAllRanges();
        selection.addRange(range);
        var copied = document.execCommand('copy');
        selection.removeAllRanges();
        container.remove();

        return copied;
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-copy-signature]');
        if (!button) return;

        var card = button.closest('[data-signature-card]');
        var preview = card ? card.querySelector('[data-signature-html]') : null;
        if (!preview) return;

        var html = preview.innerHTML;
        var plainText = preview.innerText;
        var clipboardItemSupported = typeof window.ClipboardItem !== 'undefined'
            && navigator.clipboard
            && typeof navigator.clipboard.write === 'function';

        if (clipboardItemSupported) {
            var item = new window.ClipboardItem({
                'text/html': new Blob([html], { type: 'text/html' }),
                'text/plain': new Blob([plainText], { type: 'text/plain' })
            });
            navigator.clipboard.write([item]).then(function () {
                showToast('Signature copied with formatting');
            }).catch(function () {
                showToast(fallbackCopy(html) ? 'Signature copied' : 'Copy failed');
            });
            return;
        }

        showToast(fallbackCopy(html) ? 'Signature copied' : 'Copy failed');
    });
}());
