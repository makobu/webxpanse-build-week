(function () {
    'use strict';

    var items = document.querySelectorAll('.docs-accordion-item');
    if (!items.length) return;

    items.forEach(function (item) {
        var header = item.querySelector('.docs-accordion-header');
        var body = item.querySelector('.docs-accordion-body');
        if (!header || !body) return;

        header.addEventListener('click', function () {
            var isExpanded = header.getAttribute('aria-expanded') === 'true';
            header.setAttribute('aria-expanded', !isExpanded);
            body.hidden = isExpanded;
        });
    });

    // Open accordion from URL hash (e.g. #contacts)
    var hash = window.location.hash;
    if (hash) {
        var slug = hash.replace(/^#/, '');
        var targetItem = document.querySelector('.docs-accordion-item[data-feature="' + slug + '"]');
        if (targetItem) {
            var h = targetItem.querySelector('.docs-accordion-header');
            var b = targetItem.querySelector('.docs-accordion-body');
            if (h && b) {
                h.setAttribute('aria-expanded', 'true');
                b.hidden = false;
                targetItem.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }
})();
