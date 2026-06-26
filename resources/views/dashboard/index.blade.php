@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="row mb-4">
    <div class="col">
        <h4 class="section-title">Dashboard</h4>
        <p class="text-muted mb-0">Welcome back, {{ auth()->user()->name }}.</p>
    </div>
</div>

{{-- Stat Cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <a href="{{ route('tickets.index', ['status' => 'needs_action']) }}" class="text-decoration-none">
            <div class="card shadow-sm stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="activity-icon activity-icon-navy">
                        <i class="bi bi-ticket-detailed"></i>
                    </div>
                    <div>
                        <div class="fs-3 fw-bold" id="statNeedsResponse">{{ $stats['needs_response'] }}</div>
                        <div class="text-muted small">Needs Action</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="{{ route('calls.index', ['status' => 'missed']) }}" class="text-decoration-none">
            <div class="card shadow-sm stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="activity-icon" style="background-color: #dc3545;">
                        <i class="bi bi-telephone-x"></i>
                    </div>
                    <div>
                        <div class="fs-3 fw-bold" id="statMissedCalls">{{ $stats['missed_calls'] }}</div>
                        <div class="text-muted small">Missed Calls</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4">
        <a href="{{ route('invoices.index', ['status' => 'outstanding']) }}" class="text-decoration-none">
            <div class="card shadow-sm stat-card h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="activity-icon activity-icon-orange">
                        <i class="bi bi-receipt"></i>
                    </div>
                    <div>
                        <div class="fs-3 fw-bold" id="statOutstanding">${{ number_format($stats['outstanding_invoices'], 2) }}</div>
                        <div class="text-muted small">Outstanding</div>
                    </div>
                </div>
            </div>
        </a>
    </div>
</div>

{{-- Managed Services Profitability --}}
@if(isset($profitability))
<div class="d-flex justify-content-end mb-1">
    <form method="POST" action="{{ route('dashboard.refresh-profitability') }}" class="d-inline">
        @csrf
        <button type="submit" class="btn btn-link btn-sm text-muted p-0" title="Refresh profitability estimates">
            <i class="bi bi-arrow-clockwise me-1"></i>Refresh
        </button>
    </form>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card shadow-sm stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="activity-icon" style="background-color: #198754;">
                    <i class="bi bi-arrow-up-circle"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold">${{ number_format($profitability['mrr'], 0) }}</div>
                    <div class="text-muted small">Est. Monthly Revenue</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="activity-icon" style="background-color: #dc3545;">
                    <i class="bi bi-arrow-down-circle"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold">${{ number_format($profitability['license_cost'], 0) }}</div>
                    <div class="text-muted small">Est. License Costs</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow-sm stat-card h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="activity-icon" style="background-color: {{ $profitability['profit'] >= 0 ? '#198754' : '#dc3545' }};">
                    <i class="bi bi-graph-up"></i>
                </div>
                <div>
                    <div class="fs-3 fw-bold {{ $profitability['profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                        {{ round($profitability['profit']) < 0 ? '-$' : '$' }}{{ number_format(abs($profitability['profit']), 0) }}
                    </div>
                    <div class="text-muted small">
                        Est. Monthly Profit
                        @if($profitability['mrr'] > 0)
                            <span class="{{ $profitability['profit'] >= 0 ? 'text-success' : 'text-danger' }}">
                                ({{ round($profitability['profit'] / $profitability['mrr'] * 100) }}%)
                            </span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Main content: Alerts + Tickets + Activity --}}
<div class="row g-4">
    <div class="col-md-8">
        {{-- Alerts Card --}}
        @include('dashboard._alerts-card')

        <div class="d-flex justify-content-between align-items-center mb-3">
            <span><i class="bi bi-ticket-detailed me-2"></i><strong>Open Tickets</strong> <span class="badge bg-secondary ms-1">{{ $tickets->total() }}</span></span>
            <a href="{{ route('tickets.index') }}" class="btn btn-outline-primary btn-sm">View All</a>
        </div>
        @include('tickets._list', [
            'listRoute' => 'tickets.index',
            'prefilter' => [],
            'tickets' => $tickets,
            'filters' => $ticketFilters,
            'clients' => $ticketClients,
            'users' => $ticketUsers,
            'statuses' => $ticketStatuses,
            'priorities' => $ticketPriorities,
            'types' => $ticketTypes,
            'sources' => $ticketSources,
            'unassignedCount' => $unassignedCount,
            'showFilters' => false,
            'showBulkActions' => false,
            'columns' => ['id', 'subject', 'client', 'priority', 'status', 'assignee', 'updated_at'],
        ])
    </div>

    {{-- Activity Stream --}}
    <div class="col-md-4">
        <div class="card shadow-sm card-static">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-activity me-2"></i>Activity</span>
            </div>

            {{-- New items banner --}}
            <div class="activity-new-banner" id="newItemsBanner" onclick="showNewItems()">
                <i class="bi bi-arrow-up me-1"></i><span id="newItemsCount">0</span> new items — click to show
            </div>

            {{-- Filter chips --}}
            <div class="stream-filters">
                <button class="stream-filter active" data-filter="all" onclick="toggleFilter(this)">All</button>
                <button class="stream-filter" data-filter="ticket" onclick="toggleFilter(this)">Tickets</button>
                <button class="stream-filter" data-filter="call" onclick="toggleFilter(this)">Calls</button>
                <button class="stream-filter" data-filter="email" onclick="toggleFilter(this)">Emails</button>
                <button class="stream-filter" data-filter="contract" onclick="toggleFilter(this)">Contracts</button>
                <button class="stream-filter" data-filter="triage" onclick="toggleFilter(this)">Triage</button>
                <button class="stream-filter" data-filter="invoice" onclick="toggleFilter(this)">Billing</button>
            </div>

            {{-- Stream --}}
            <div id="activityStream">
                @include('dashboard._activity-stream', ['stream' => $stream])
            </div>

            {{-- Load More --}}
            @if($stream->count() >= 30)
                <div class="card-footer text-center" id="loadMoreContainer">
                    <button class="btn btn-outline-primary btn-sm" id="loadMoreBtn" onclick="loadMore()">
                        <i class="bi bi-arrow-down me-1"></i>Load More
                    </button>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection

@push('styles')
<link href="{{ asset('css/activity-stream.css') }}" rel="stylesheet">
@endpush

@push('scripts')
<script>
(function() {
    const POLL_INTERVAL = 60000;
    let activeFilters = new Set();
    let pendingHtml = '';
    let pendingCount = 0;

    // ── Filter persistence ──
    function saveFilters() {
        localStorage.setItem('psa-activity-filters', JSON.stringify([...activeFilters]));
    }
    function loadFilters() {
        try {
            const saved = JSON.parse(localStorage.getItem('psa-activity-filters'));
            if (saved && Array.isArray(saved) && saved.length > 0) {
                activeFilters = new Set(saved);
                // Update chip UI
                document.querySelectorAll('.stream-filter').forEach(chip => {
                    const f = chip.dataset.filter;
                    chip.classList.toggle('active', activeFilters.has(f));
                });
                applyFilters();
                return;
            }
        } catch (e) {}
        // Default: "All" active
        activeFilters.add('all');
    }

    // ── Filter logic ──
    window.toggleFilter = function(chip) {
        const filter = chip.dataset.filter;

        if (filter === 'all') {
            activeFilters.clear();
            activeFilters.add('all');
        } else {
            activeFilters.delete('all');
            if (activeFilters.has(filter)) {
                activeFilters.delete(filter);
            } else {
                activeFilters.add(filter);
            }
            if (activeFilters.size === 0) {
                activeFilters.add('all');
            }
        }

        // Update chip UI
        document.querySelectorAll('.stream-filter').forEach(c => {
            c.classList.toggle('active', activeFilters.has(c.dataset.filter));
        });

        applyFilters();
        saveFilters();
    };

    function applyFilters() {
        const showAll = activeFilters.has('all');
        document.querySelectorAll('#activityStream .activity-item').forEach(el => {
            const type = el.dataset.type;
            el.style.display = (showAll || activeFilters.has(type)) ? '' : 'none';
        });
    }

    // ── Load More ──
    function getFilterParam() {
        if (activeFilters.has('all')) return '';
        return [...activeFilters].join(',');
    }

    window.loadMore = function() {
        const items = document.querySelectorAll('#activityStream .activity-item');
        if (items.length === 0) return;

        const lastItem = items[items.length - 1];
        const before = lastItem.dataset.timestamp;
        const btn = document.getElementById('loadMoreBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading...';

        let url = `{{ route('dashboard.activity') }}?before=${encodeURIComponent(before)}`;
        const types = getFilterParam();
        if (types) url += `&types=${encodeURIComponent(types)}`;

        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.text())
        .then(html => {
            html = html.trim();
            if (!html || html.includes('No recent activity')) {
                document.getElementById('loadMoreContainer').innerHTML =
                    '<span class="text-muted small">No more activity.</span>';
                return;
            }
            document.getElementById('activityStream').insertAdjacentHTML('beforeend', html);
            applyFilters();
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-down me-1"></i>Load More';
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-down me-1"></i>Load More';
        });
    };

    // ── Polling ──
    function getNewestTimestamp() {
        const first = document.querySelector('#activityStream .activity-item');
        return first ? first.dataset.timestamp : null;
    }

    function poll() {
        const since = getNewestTimestamp();
        if (!since) return;

        fetch(`{{ route('dashboard.activity') }}?since=${encodeURIComponent(since)}`, {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            }
        })
        .then(r => r.json())
        .then(data => {
            if (data.count > 0) {
                pendingHtml = data.html;
                pendingCount = data.count;
                const banner = document.getElementById('newItemsBanner');
                document.getElementById('newItemsCount').textContent = data.count;
                banner.style.display = 'block';
            }
            // Update stat cards
            if (data.stats) {
                document.getElementById('statNeedsResponse').textContent = data.stats.needs_response;
                document.getElementById('statMissedCalls').textContent = data.stats.missed_calls;
                document.getElementById('statOutstanding').textContent = '$' + Number(data.stats.outstanding_invoices).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
            }
        })
        .catch(() => {});
    }

    window.showNewItems = function() {
        if (pendingHtml) {
            document.getElementById('activityStream').insertAdjacentHTML('afterbegin', pendingHtml);
            applyFilters();
            pendingHtml = '';
            pendingCount = 0;
        }
        document.getElementById('newItemsBanner').style.display = 'none';
    };

    // ── Init ──
    loadFilters();
    setInterval(poll, POLL_INTERVAL);
})();
</script>
@endpush
