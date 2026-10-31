{{-- Control D panel, Recent activity: the DNS Activity block moved from the Security tab
     (design-r3 §4 A). It is the existing assets.controld.activity read, fired ONLY when a
     button is clicked (M3S.1): nothing is read on page render or when the panel is opened.
     1 h by default; blocked rows are listed first. Vendor values are inserted as text. --}}
<div data-dns-activity data-dns-activity-url="{{ route('assets.controld.activity', $asset) }}">
    <div class="d-flex align-items-center gap-2 mb-2">
        <button type="button" class="btn btn-outline-primary btn-sm" data-dns-activity-hours="1">
            <i class="bi bi-activity me-1"></i>View DNS Activity
        </button>
        <div class="btn-group btn-group-sm" role="group" aria-label="DNS Activity">
            <button type="button" class="btn btn-outline-secondary" data-dns-activity-hours="1">1h</button>
            <button type="button" class="btn btn-outline-secondary" data-dns-activity-hours="4">4h</button>
            <button type="button" class="btn btn-outline-secondary" data-dns-activity-hours="24">24h</button>
        </div>
    </div>
    <div class="small text-muted" data-dns-activity-body>Not read yet.</div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var box = document.querySelector('[data-dns-activity]');
    if (!box) return;
    var body = box.querySelector('[data-dns-activity-body]');
    function cell(tag, text, cls) {
        var el = document.createElement(tag);
        if (cls) el.className = cls;
        el.textContent = text;
        return el;
    }
    function loadDnsActivity(hours) {
        box.querySelectorAll('[data-dns-activity-hours]').forEach(function (b) {
            if (b.parentNode.getAttribute('role') === 'group') b.classList.toggle('active', parseInt(b.dataset.dnsActivityHours, 10) === hours);
        });
        body.className = 'small text-muted';
        body.textContent = 'Loading DNS activity...';
        fetch(box.dataset.dnsActivityUrl + '?hours=' + hours, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (!r.ok) return r.json().then(function (d) { throw new Error(d.error || 'Request failed'); });
                return r.json();
            })
            .then(function (queries) {
                body.textContent = '';
                if (!queries.length) {
                    body.textContent = 'No DNS queries found in the last ' + hours + ' hour' + (hours > 1 ? 's' : '') + '.';
                    return;
                }
                // Blocked rows first; the vendor's order is kept within each group.
                var rows = queries.filter(function (q) { return q.action === 'blocked'; })
                    .concat(queries.filter(function (q) { return q.action !== 'blocked'; }));
                var table = document.createElement('table');
                table.className = 'table table-sm table-hover mb-0';
                var head = table.createTHead().insertRow();
                ['Time', 'Domain', 'Action', 'Trigger', 'Type'].forEach(function (h) { head.appendChild(cell('th', h)); });
                var tbody = table.createTBody();
                rows.forEach(function (q) {
                    var tr = tbody.insertRow();
                    var badge = q.action === 'allowed' ? 'success' : (q.action === 'blocked' ? 'danger' : (q.action === 'nxdomain' ? 'warning' : 'secondary'));
                    tr.appendChild(cell('td', q.timestamp ? new Date(q.timestamp * 1000).toLocaleTimeString() : '-', 'text-nowrap small'));
                    tr.appendChild(cell('td', q.domain || '-', 'small text-break'));
                    var act = document.createElement('td');
                    act.appendChild(cell('span', q.action || 'unknown', 'badge bg-' + badge));
                    tr.appendChild(act);
                    tr.appendChild(cell('td', q.trigger || '-', 'small text-muted'));
                    tr.appendChild(cell('td', q.type || '-', 'small text-muted'));
                });
                var wrap = document.createElement('div');
                wrap.className = 'table-responsive';
                wrap.appendChild(table);
                body.className = 'small';
                body.appendChild(wrap);
                if (queries.length >= 100) body.appendChild(cell('div', 'Showing first 100 queries', 'text-center py-2 text-muted small'));
            })
            .catch(function (err) {
                body.className = 'small text-danger';
                body.textContent = err.message;
            });
    }
    box.querySelectorAll('[data-dns-activity-hours]').forEach(function (b) {
        b.addEventListener('click', function () { loadDnsActivity(parseInt(b.dataset.dnsActivityHours, 10)); });
    });
});
</script>
