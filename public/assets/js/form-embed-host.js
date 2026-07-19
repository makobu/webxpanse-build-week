(function () {
    'use strict';

    window.addEventListener('message', function (event) {
        var data = event.data || {};
        if (data.type !== 'crm-form-height') { return; }
        var frames = document.querySelectorAll('iframe.ds-form-embed');
        Array.prototype.forEach.call(frames, function (frame) {
            if (frame.contentWindow !== event.source) { return; }
            var height = Math.max(260, Math.min(1800, Number(data.height) || 0));
            if (height) { frame.style.height = height + 'px'; }
        });
    });
}());
