(function () {
    'use strict';

    var STORAGE_KEY = 'psa-sidebar-collapsed';
    var sidebar = document.getElementById('psa-sidebar');
    var backdrop = document.getElementById('sidebar-backdrop');
    var collapseBtn = document.getElementById('sidebar-collapse-btn');
    var mobileToggle = document.getElementById('sidebar-mobile-toggle');
    var billingToggle = document.getElementById('billing-toggle');

    if (!sidebar) return;

    // ── Desktop: restore collapsed state from localStorage ──
    var isDesktop = window.matchMedia('(min-width: 992px)');
    if (isDesktop.matches && localStorage.getItem(STORAGE_KEY) === '1') {
        document.body.classList.add('sidebar-collapsed');
    }

    // ── Desktop collapse toggle ──
    if (collapseBtn) {
        collapseBtn.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-collapsed');
            var collapsed = document.body.classList.contains('sidebar-collapsed');
            localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
            collapseBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            // Re-init tooltips when toggling
            initTooltips();
        });
    }

    // ── Mobile hamburger toggle ──
    if (mobileToggle) {
        mobileToggle.addEventListener('click', function () {
            document.body.classList.toggle('sidebar-mobile-open');
        });
    }

    // ── Backdrop click closes mobile sidebar ──
    if (backdrop) {
        backdrop.addEventListener('click', function () {
            document.body.classList.remove('sidebar-mobile-open');
        });
    }

    // ── ESC key closes mobile sidebar ──
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && document.body.classList.contains('sidebar-mobile-open')) {
            document.body.classList.remove('sidebar-mobile-open');
        }
    });

    // ── Handle resize: clean up mobile state ──
    window.addEventListener('resize', function () {
        if (window.innerWidth >= 768) {
            document.body.classList.remove('sidebar-mobile-open');
        }
    });

    // ── Billing: click-to-expand in collapsed mode ──
    // When sidebar is collapsed and user clicks the Billing toggle,
    // temporarily expand the sidebar so the submenu is visible.
    if (billingToggle) {
        billingToggle.addEventListener('click', function (e) {
            var isCollapsed = document.body.classList.contains('sidebar-collapsed') ||
                (!isDesktop.matches && window.innerWidth >= 768);

            if (isCollapsed) {
                e.preventDefault();
                e.stopPropagation();

                // Expand sidebar temporarily
                document.body.classList.remove('sidebar-collapsed');

                // Show the billing submenu
                var submenu = document.getElementById('sidebarBilling');
                if (submenu) {
                    var bsCollapse = bootstrap.Collapse.getOrCreateInstance(submenu, { toggle: false });
                    bsCollapse.show();
                }

                initTooltips();
            }
        });
    }

    // ── Cross-tab sync ──
    window.addEventListener('storage', function (e) {
        if (e.key === STORAGE_KEY && isDesktop.matches) {
            document.body.classList.toggle('sidebar-collapsed', e.newValue === '1');
            initTooltips();
        }
    });

    // ── Tooltips: only active in collapsed mode ──
    var activeTooltips = [];

    function initTooltips() {
        // Dispose existing tooltips
        activeTooltips.forEach(function (t) { t.dispose(); });
        activeTooltips = [];

        var isCollapsedOrTablet = document.body.classList.contains('sidebar-collapsed') ||
            window.innerWidth < 992;

        if (!isCollapsedOrTablet) return;

        // Don't show tooltips on mobile overlay (full labels visible)
        if (window.innerWidth < 768) return;

        var links = sidebar.querySelectorAll('.sidebar-link[data-bs-title]');
        links.forEach(function (link) {
            // Temporarily remove data-bs-toggle="collapse" interference for tooltip
            var existingToggle = link.getAttribute('data-bs-toggle');
            if (existingToggle === 'collapse') return; // Skip billing toggle (has its own label)

            var tooltip = new bootstrap.Tooltip(link, {
                trigger: 'hover',
                placement: 'right',
                container: 'body'
            });
            activeTooltips.push(tooltip);
        });
    }

    // Initialize tooltips on load
    initTooltips();
})();
