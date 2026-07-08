(function () {
    'use strict';

    var SEARCH_URL = '/search/quick';
    var MIN_QUERY_LEN = 2;
    var DEBOUNCE_MS = 300;

    var backdrop = document.getElementById('cmd-palette-backdrop');
    var input = document.getElementById('cmd-palette-input');
    var resultsList = document.getElementById('cmd-palette-results');

    if (!backdrop || !input || !resultsList) return;

    var cachedData = null; // { nav, recent }
    var activeIndex = -1;
    var visibleItems = [];
    var debounceTimer = null;

    // ── Open / Close ──

    function open() {
        backdrop.classList.add('open');
        input.value = '';
        input.focus();
        activeIndex = -1;

        if (!cachedData) {
            fetchResults('').then(function (data) {
                cachedData = { nav: data.nav || [], recent: data.recent || [] };
                render(cachedData.nav, cachedData.recent, []);
            });
        } else {
            render(cachedData.nav, cachedData.recent, []);
        }
    }

    function close() {
        backdrop.classList.remove('open');
        input.value = '';
        activeIndex = -1;
        visibleItems = [];
    }

    function isOpen() {
        return backdrop.classList.contains('open');
    }

    // ── Fetch ──

    function fetchResults(query) {
        var url = SEARCH_URL;
        if (query) url += '?q=' + encodeURIComponent(query);

        return fetch(url, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
            },
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json(); })
        .catch(function () { return { nav: [], recent: [], results: [] }; });
    }

    // ── Render ──

    function render(navItems, recentItems, dbResults) {
        var query = input.value.trim().toLowerCase();
        var html = '';
        visibleItems = [];

        // Filter nav items client-side
        var filteredNav = query
            ? navItems.filter(function (item) {
                var haystack = (item.label + ' ' + (item.keywords || '')).toLowerCase();
                return haystack.indexOf(query) !== -1;
            })
            : navItems;

        // Filter recent items client-side
        var filteredRecent = query
            ? recentItems.filter(function (item) {
                return item.label.toLowerCase().indexOf(query) !== -1;
            })
            : recentItems;

        // Sort nav: starts-with first, then contains
        if (query) {
            filteredNav.sort(function (a, b) {
                var aStarts = a.label.toLowerCase().indexOf(query) === 0 ? 0 : 1;
                var bStarts = b.label.toLowerCase().indexOf(query) === 0 ? 0 : 1;
                return aStarts - bStarts;
            });
        }

        // DB results section (from server)
        if (dbResults.length > 0) {
            html += '<div class="cmd-palette-section-label">Search Results</div>';
            dbResults.forEach(function (item) {
                var idx = visibleItems.length;
                visibleItems.push(item);
                html += renderItem(item, idx);
            });
        }

        // Recent section
        if (filteredRecent.length > 0) {
            html += '<div class="cmd-palette-section-label">Recent</div>';
            filteredRecent.forEach(function (item) {
                var idx = visibleItems.length;
                visibleItems.push(item);
                html += renderItem(item, idx);
            });
        }

        // Nav section
        if (filteredNav.length > 0) {
            html += '<div class="cmd-palette-section-label">Navigation</div>';
            filteredNav.forEach(function (item) {
                var idx = visibleItems.length;
                visibleItems.push(item);
                html += renderItem(item, idx);
            });
        }

        if (visibleItems.length === 0) {
            html = '<div class="cmd-palette-empty">No results found</div>';
        }

        resultsList.innerHTML = html;
        activeIndex = visibleItems.length > 0 ? 0 : -1;
        updateActive();
    }

    function renderItem(item, idx) {
        var typeLabel = item.type ? '<span class="cmd-palette-item-type">' + escapeHtml(item.type) + '</span>' : '';
        return '<a href="' + escapeHtml(item.url) + '" ' +
            'class="cmd-palette-item" ' +
            'role="option" ' +
            'id="cmd-item-' + idx + '" ' +
            'data-index="' + idx + '">' +
            '<i class="bi ' + escapeHtml(item.icon || 'bi-link-45deg') + '"></i>' +
            '<span class="cmd-palette-item-label">' + escapeHtml(item.label) + '</span>' +
            typeLabel +
            '</a>';
    }

    function updateActive() {
        var items = resultsList.querySelectorAll('.cmd-palette-item');
        items.forEach(function (el, i) {
            el.classList.toggle('active', i === activeIndex);
        });

        // Update aria-activedescendant
        if (activeIndex >= 0) {
            input.setAttribute('aria-activedescendant', 'cmd-item-' + activeIndex);
            // Scroll into view
            var activeEl = document.getElementById('cmd-item-' + activeIndex);
            if (activeEl) activeEl.scrollIntoView({ block: 'nearest' });
        } else {
            input.removeAttribute('aria-activedescendant');
        }
    }

    function navigate() {
        if (activeIndex >= 0 && activeIndex < visibleItems.length) {
            window.location.href = visibleItems[activeIndex].url;
            close();
        }
    }

    function escapeHtml(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // ── Input handling ──

    input.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        var query = input.value.trim();

        if (query.length >= MIN_QUERY_LEN) {
            debounceTimer = setTimeout(function () {
                fetchResults(query).then(function (data) {
                    // Update cached nav/recent if returned
                    if (data.nav) cachedData = { nav: data.nav, recent: data.recent || cachedData.recent };
                    render(cachedData.nav, cachedData.recent, data.results || []);
                });
            }, DEBOUNCE_MS);
        } else {
            // Just filter client-side on cached data
            if (cachedData) {
                render(cachedData.nav, cachedData.recent, []);
            }
        }
    });

    // ── Keyboard navigation ──

    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (activeIndex < visibleItems.length - 1) {
                activeIndex++;
                updateActive();
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (activeIndex > 0) {
                activeIndex--;
                updateActive();
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            navigate();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            close();
        }
    });

    // ── Click on result ──
    resultsList.addEventListener('click', function (e) {
        var item = e.target.closest('.cmd-palette-item');
        if (item) {
            // Let the <a> tag navigate naturally
            close();
        }
    });

    // ── Backdrop click closes ──
    backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) close();
    });

    // ── Global Ctrl+K / Cmd+K ──
    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
            e.preventDefault();
            if (isOpen()) {
                close();
            } else {
                open();
            }
        }
        // ESC from anywhere
        if (e.key === 'Escape' && isOpen()) {
            close();
        }
    });

    // ── Trigger button(s) ──
    document.querySelectorAll('[data-cmd-palette]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            open();
        });
    });
})();
