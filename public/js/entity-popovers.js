/**
 * Entity badge popovers — initializes Bootstrap 5 popovers on all
 * elements with data-bs-toggle="popover". Uses a guard attribute
 * so it can be safely re-called after dynamic content insertion.
 */
function initPopovers(root) {
    root.querySelectorAll('[data-bs-toggle="popover"]:not([data-popover-init])').forEach(function (el) {
        el.setAttribute('data-popover-init', '');
        new bootstrap.Popover(el, {
            container: 'body',
            trigger: 'hover focus',
            boundary: 'viewport'
        });
    });
}

document.addEventListener('DOMContentLoaded', function () {
    initPopovers(document);
});
