// Guard for stray page-events.js errors
// Safely wraps a global handleKeyDown if it exists, and suppresses the known error.
(function () {
    'use strict';

    function wrapHandleKeyDown() {
        if (typeof window.handleKeyDown !== 'function') {
            return;
        }
        if (window.handleKeyDown.__safeWrapped) {
            return;
        }

        const original = window.handleKeyDown;
        const safe = function (event) {
            try {
                return original(event);
            } catch (err) {
                return undefined;
            }
        };
        safe.__safeWrapped = true;
        window.handleKeyDown = safe;

        // Best-effort removal of original listener
        document.removeEventListener('keydown', original, true);
        document.removeEventListener('keydown', original, false);
        document.addEventListener('keydown', safe);
    }

    window.addEventListener('error', function (event) {
        if (!event) {
            return;
        }
        const filename = event.filename || '';
        const message = event.message || '';
        if (filename.indexOf('page-events.js') !== -1 || message.indexOf('handleKeyDown') !== -1) {
            event.preventDefault();
        }
    }, true);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wrapHandleKeyDown);
    } else {
        wrapHandleKeyDown();
    }

    setTimeout(wrapHandleKeyDown, 0);
    setTimeout(wrapHandleKeyDown, 1000);
})();
