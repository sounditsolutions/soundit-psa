{{-- Sources tab (design/mockups/assets/design-r3.md §§1-2).
     Rail + one generic panel per source, rendered from STORED columns and the
     asset_source_syncs audit only: no vendor call is made to render this tab.
     Panel order is fixed: header, health strip, needs attention, recent activity,
     device details (collapsed), footer strip. --}}
<div class="row g-3" id="sources-root" data-default-source="{{ $sourcesDefault }}">
    <div class="col-md-3">
        <div class="text-uppercase small fw-semibold text-muted mb-2">Data sources</div>
        <div class="list-group" role="tablist" aria-label="Data sources">
            @foreach($sourcesRail as $r)
                <a href="#tab-sources/{{ $r['source'] }}"
                   class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ $r['source'] === $sourcesDefault ? 'active' : '' }}"
                   role="tab" data-source-rail="{{ $r['source'] }}" data-state="{{ $r['state'] }}"
                   aria-selected="{{ $r['source'] === $sourcesDefault ? 'true' : 'false' }}">
                    <span>{{ $r['label'] }}</span>
                    <span class="d-flex align-items-center gap-1">
                        @if($r['attention'] > 0)
                            <span class="badge rounded-pill bg-danger" data-attention-marker="{{ $r['attention'] }}"
                                  title="{{ $r['attention'] }} stored item(s) need a look{{ in_array($r['source'], \App\Services\AssetSources\AssetSources::SHARED_COLUMN_SOURCES, true) ? '; '.\App\Services\AssetSources\AssetSources::INFERRED_WORDING : '' }}">{{ $r['attention'] }}</span>
                        @endif
                        @include('assets.partials.source-state-badge', ['state' => $r['state']])
                    </span>
                </a>
            @endforeach
        </div>
        <p class="text-muted small mt-3 mb-0">A number beside a source counts stored items that need a look. It is computed from stored data; opening this tab calls no vendor.</p>
    </div>
    <div class="col-md-9">
        @forelse($sourcesPanels as $p)
            <div class="card mb-0 {{ $p['source'] === $sourcesDefault ? '' : 'd-none' }}" data-source-panel="{{ $p['source'] }}" data-state="{{ $p['state'] }}">
                <div class="card-header d-flex justify-content-between align-items-center" data-panel-section="header">
                    <span>{{ $p['label'] }}</span>
                    <span class="d-flex align-items-center gap-2">
                        @if($p['open_in'])
                            <a href="{{ $p['open_in']['url'] }}" target="_blank" rel="noopener noreferrer" class="link-light small" data-open-in>
                                {{ $p['open_in']['label'] }} <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        @endif
                        @include('assets.partials.source-state-badge', ['state' => $p['state']])
                    </span>
                </div>
                <div class="card-body">
                    <div class="d-flex flex-wrap gap-4 pb-3 border-bottom" data-panel-section="health">
                        @if($p['state'] === 'off')
                            <div data-chip-kind="state">
                                <div class="text-uppercase small fw-semibold text-muted">Integration</div>
                                <div><span class="badge bg-secondary">OFF</span></div>
                                <div class="small text-muted">switched off in Settings</div>
                            </div>
                        @endif
                        @foreach($p['chips'] as $chip)
                            <div data-chip-kind="{{ $chip['kind'] }}">
                                <div class="text-uppercase small fw-semibold text-muted">{{ $chip['label'] }}</div>
                                <div class="fw-semibold">{{ $chip['value'] }}</div>
                                <div class="small text-muted" data-chip-note>{{ $chip['note'] }}</div>
                            </div>
                        @endforeach
                    </div>

                    <div class="pt-3" data-panel-section="attention">
                        <div class="text-uppercase small fw-semibold mb-2">Needs attention</div>
                        @if($p['checks_live'])
                            {{-- The failing-check list leads the Tactical panel. It is the existing
                                 device-data read (TacticalPanelData::checks: stdout redacted and
                                 clipped server-side), fired when this panel is opened, never on
                                 page render. It is not Sync now and is not counted by its limiter. --}}
                            <div class="border rounded p-2 mb-2" data-tactical-checks
                                 data-checks-url="{{ route('assets.deviceData', ['asset' => $asset, 'section' => 'checks', 'source' => 'tactical']) }}">
                                <div class="fw-semibold small mb-1">Failing checks <span class="text-muted fw-normal">(read from Tactical when this panel is opened)</span></div>
                                <div class="small text-muted" data-tactical-checks-body>Not read yet.</div>
                            </div>
                        @endif
                        @if($p['state'] === 'off')
                            <p class="text-muted small mb-0">{{ $p['label'] }} is switched off for this PSA, so nothing is read from it and no attention count is shown.{{ $p['linked'] ? ' The values shown are the last known ones.' : '' }}</p>
                        @elseif(count($p['attention']) === 0)
                            @if($p['state'] === 'not_configured' && $p['linked'])
                                <p class="text-muted small mb-0">This source isn't configured, so these details are the last data stored.</p>
                            @else
                                <p class="text-muted small mb-0">Nothing in the stored data for this source needs a look.</p>
                            @endif
                        @else
                            @foreach($p['attention'] as $item)
                                <div class="border rounded p-2 mb-2" data-attention-item="{{ $item['key'] }}">
                                    @if($p['source'] === 'autoelevate')
                                        @include('assets.partials.autoelevate', ['asset' => $asset, 'item' => $item])
                                    @else
                                    <strong>{{ $item['title'] }}</strong>
                                    <span class="text-muted small ms-2">{{ $item['evidence'] }}</span>
                                    @endif
                                    @if($p['source'] === 'tactical' && $item['key'] === 'updates')
                                        <a href="#tab-patches" class="small ms-2" data-show-tab="#tab-patches">View in Patches</a>
                                    @endif
                                </div>
                            @endforeach
                            @if($p['all_alerts'])
                                <a href="#tab-alerts" class="small" data-show-tab="#tab-alerts" data-all-alerts>All {{ $p['all_alerts'] }} alerts</a>
                            @endif
                        @endif
                    </div>

                    <div class="pt-3" data-panel-section="activity">
                        <div class="text-uppercase small fw-semibold mb-2">Recent activity</div>
                        @if($p['activity'] !== null)
                            @include('assets.partials.sources-tactical-activity', ['activity' => $p['activity']])
                        @elseif($p['dns_activity'])
                            @include('assets.partials.sources-controld-activity', ['asset' => $asset])
                        @elseif($p['sc_events'] !== null && $p['sc_events']->isNotEmpty())
                            @include('assets.partials.sources-screenconnect-activity', ['events' => $p['sc_events']])
                        @else
                        <p class="text-muted small mb-0">
                            @if($p['state'] === 'off') None while the integration is off. @else No recent activity is shown for this source here. @endif
                        </p>
                        @endif
                    </div>

                    @if(count($p['details']))
                    <details class="border rounded p-2 mt-3" data-panel-section="details">
                        <summary class="fw-semibold">Device details</summary>
                        <table class="table table-sm table-borderless mb-0 mt-2">
                            <tbody>
                            @foreach($p['details'] as $row)
                                <tr data-detail-column="{{ $row['column'] }}">
                                    <th class="text-muted fw-normal" style="width: 45%;">
                                        {{ $row['label'] }} <code class="text-muted small">{{ $row['column'] }}</code>
                                        @if($row['shared'])<span class="small text-muted">(shared; {{ \App\Services\AssetSources\AssetSources::INFERRED_WORDING }})</span>@endif
                                    </th>
                                    <td>{{ $row['value'] }}@if($row['note'] ?? null) <span class="text-warning-emphasis small" title="Differs from the Device Identity OS. Verify which source is current before trusting either value.">{{ $row['note'] }}</span>@endif</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </details>
                    @else
                    <div class="mt-3" data-panel-section="details">
                        <p class="text-muted small mb-0">No device details: this asset is not linked to {{ $p['label'] }}.</p>
                    </div>
                    @endif

                    <div class="d-flex justify-content-between align-items-center border-top mt-3 pt-2 small text-muted" data-panel-section="footer">
                        <span>
                            <span data-footer-text>{{ $p['footer']['text'] }}</span>
                            @if($p['footer']['outcome'])
                                · <span class="badge bg-secondary" data-footer-outcome>{{ $p['footer']['outcome']['badge'] }}</span>
                                {{ $p['footer']['outcome']['sentence'] }}
                            @endif
                            @if($p['history']->isNotEmpty())
                                <details class="d-inline" data-footer-history>
                                    <summary class="d-inline text-decoration-underline">History</summary>
                                    <ul class="list-unstyled mb-0 mt-1">
                                        @foreach($p['history'] as $h)
                                            <li data-history-row>{{ $h->started_at->toAppTz()->format('M j, Y g:i A T') }} · {{ $h->user?->name ? 'Sync now by '.$h->user->name : 'Sync now' }} · {{ strtoupper($h->outcome) }} · {{ \App\Services\AssetSources\SourceSyncPipeline::sentence($h->reason) }}</li>
                                        @endforeach
                                    </ul>
                                </details>
                            @endif
                        </span>
                        @if($p['sync_now'])
                            <span class="d-flex align-items-center gap-2">
                                <span class="small" data-sync-now-result aria-live="polite"></span>
                                <button type="button" class="btn btn-outline-primary btn-sm" data-sync-now="{{ $p['source'] }}"
                                        data-url="{{ route('assets.sources.sync', [$asset, $p['source']]) }}"
                                        title="{{ $p['sync_now_description'] }}" aria-description="{{ $p['sync_now_description'] }}">Sync now</button>
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <p class="text-muted mb-0">No integration is configured or linked for this asset.</p>
        @endforelse
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var root = document.getElementById('sources-root');
    if (!root) return;
    var sourcesPane = document.getElementById('tab-sources');
    function paneShown() { return sourcesPane && sourcesPane.classList.contains('active'); }
    // The Tactical failing-check list: the existing device-data read, fired when the
    // Tactical panel is OPENED (Sources tab shown and Tactical selected), never on page
    // render, and at most once per page view. Rendered by the existing Tactical checks
    // renderer, which escapes the (server-redacted, clipped) stdout.
    function loadChecks(panel) {
        var box = panel && panel.querySelector('[data-tactical-checks]');
        if (!box || box.dataset.loaded) return;
        box.dataset.loaded = '1';
        var body = box.querySelector('[data-tactical-checks-body]');
        body.textContent = 'Reading checks from Tactical…';
        fetch(box.dataset.checksUrl, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function (d) {
                if (d.error) { body.className = 'small text-warning-emphasis'; body.textContent = d.error + ' The chips above show the last sync.'; return; }
                body.className = 'small';
                body.innerHTML = window.renderTacticalSection ? window.renderTacticalSection('checks', d) : '';
            })
            .catch(function () {
                body.className = 'small text-warning-emphasis';
                body.textContent = 'Could not read checks from Tactical. The chips above show the last sync.';
                delete box.dataset.loaded;
            });
    }
    function opened() {
        var panel = root.querySelector('[data-source-panel]:not(.d-none)');
        if (panel && paneShown()) loadChecks(panel);
    }
    function select(source) {
        var panel = root.querySelector('[data-source-panel="' + source + '"]');
        if (!panel) return false;
        root.querySelectorAll('[data-source-panel]').forEach(function (p) { p.classList.toggle('d-none', p !== panel); });
        root.querySelectorAll('[data-source-rail]').forEach(function (a) {
            var on = a.dataset.sourceRail === source;
            a.classList.toggle('active', on);
            a.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        opened();
        return true;
    }
    var sourcesTab = document.querySelector('a[href="#tab-sources"][data-bs-toggle="tab"]');
    if (sourcesTab) sourcesTab.addEventListener('shown.bs.tab', opened);
    function showTab(href) {
        var nav = document.querySelector('a[href="' + href + '"][data-bs-toggle="tab"]');
        if (nav && window.bootstrap) { window.bootstrap.Tab.getOrCreateInstance(nav).show(); }
    }
    // Links from a panel to a topic tab, and from the Overview Tactical card to this panel.
    document.querySelectorAll('[data-show-tab]').forEach(function (a) {
        a.addEventListener('click', function (e) { e.preventDefault(); showTab(a.dataset.showTab); });
    });
    document.querySelectorAll('[data-open-sources]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            showTab('#tab-sources');
            select(a.dataset.openSources);
            history.replaceState(null, '', '#tab-sources/' + a.dataset.openSources);
        });
    });
    // Sync now: the shared pipeline route. The reply's fixed sentence is shown as text.
    root.querySelectorAll('[data-sync-now]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var out = btn.parentNode.querySelector('[data-sync-now-result]');
            btn.disabled = true;
            fetch(btn.dataset.url, { method: 'POST', headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            } })
                .then(function (r) { return r.json().catch(function () { return { outcome: 'refused', message: 'Too many requests; try again in a minute.' }; }); })
                .then(function (d) {
                    if (out) out.textContent = (d.outcome === 'ok' ? 'Synced just now. ' : '') + (d.outcome === 'ok' ? 'Reload to see the new values.' : (d.message || ''));
                    var wait = d.retry_after_s || 0;
                    setTimeout(function () { btn.disabled = false; }, Math.max(1, wait) * 1000);
                })
                .catch(function () { if (out) out.textContent = 'Request failed.'; btn.disabled = false; });
        });
    });
    root.querySelectorAll('[data-source-rail]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            e.preventDefault();
            select(a.dataset.sourceRail);
            history.replaceState(null, '', '#tab-sources/' + a.dataset.sourceRail);
        });
    });
    // A #tab-sources/<source> deep link always wins over the default selection.
    var m = /^#tab-sources(?:\/([a-z0-9]+))?$/.exec(window.location.hash);
    if (m) {
        var tab = document.querySelector('a[href="#tab-sources"][data-bs-toggle="tab"]');
        if (tab && window.bootstrap) { window.bootstrap.Tab.getOrCreateInstance(tab).show(); }
        if (m[1]) { select(m[1]); }
    }
});
</script>
