@extends('layouts.app')

@section('title', 'Alerts')

@section('content')
<div class="row mb-3">
    <div class="col d-flex align-items-center justify-content-between">
        <h4 class="section-title mb-0">
            Alerts
            <span class="text-muted fw-normal" style="font-size: 0.85rem;">({{ $alerts->total() }})</span>
        </h4>
    </div>
</div>

{{-- Severity summary bar --}}
@php
    $severityOrder = ['critical', 'error', 'warning', 'info'];
@endphp
<div class="mb-3 d-flex flex-wrap align-items-center gap-2">
    @foreach($severityOrder as $sev)
        @php
            $sevEnum = \App\Enums\AlertSeverity::tryFrom($sev);
            $sevCount = $counts[$sev] ?? 0;
        @endphp
        @if($sevEnum)
            <a href="{{ route('alerts.index', array_merge(request()->except('severity', 'page'), $sevCount > 0 && ($filters['severity'] ?? '') !== $sev ? ['severity' => $sev] : [])) }}"
               class="badge text-decoration-none {{ $sevEnum->badgeClass() }} {{ ($filters['severity'] ?? '') === $sev ? 'border border-2 border-dark' : '' }}"
               style="font-size: 0.85rem; padding: 0.4em 0.75em;">
                {{ $sevEnum->label() }}
                <span class="ms-1">{{ $sevCount }}</span>
            </a>
        @endif
    @endforeach

    @php
        $hasFilters = !empty($filters['status']) || !empty($filters['severity']) || !empty($filters['source']) || !empty($filters['client_id']);
    @endphp
    @if($hasFilters)
        <a href="{{ route('alerts.index') }}" class="btn btn-sm btn-outline-secondary ms-1">
            <i class="bi bi-x-lg me-1"></i>Clear filters
        </a>
    @endif
</div>

{{-- Filter row --}}
<div class="card shadow-sm card-static mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('alerts.index') }}" class="row g-2 align-items-end">
            <div class="col-lg-2 col-md-3">
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Open Alerts</option>
                    @foreach($statuses as $s)
                        <option value="{{ $s->value }}" {{ ($filters['status'] ?? '') === $s->value ? 'selected' : '' }}>
                            {{ $s->label() }}
                        </option>
                    @endforeach
                    <option value="all" {{ ($filters['status'] ?? '') === 'all' ? 'selected' : '' }}>All Statuses</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-3">
                <select name="severity" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Severities</option>
                    @foreach($severities as $sev)
                        <option value="{{ $sev->value }}" {{ ($filters['severity'] ?? '') === $sev->value ? 'selected' : '' }}>
                            {{ $sev->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-2 col-md-3">
                <select name="source" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Sources</option>
                    @foreach($sources as $src)
                        <option value="{{ $src->value }}" {{ ($filters['source'] ?? '') === $src->value ? 'selected' : '' }}>
                            {{ $src->label() }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-3 col-md-4">
                <select name="client_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Clients</option>
                    @foreach($clients as $c)
                        <option value="{{ $c->id }}" {{ ($filters['client_id'] ?? '') == $c->id ? 'selected' : '' }}>
                            {{ $c->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <a href="{{ route('alerts.index') }}" class="btn btn-outline-secondary btn-sm" title="Clear all filters">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>
</div>

    <div class="card shadow-sm card-static">
        @include('alerts._list', ['alerts' => $alerts, 'showBulkActions' => true, 'showPagination' => true])
    </div>

    {{-- Bulk Action Bar --}}
    <div class="bulk-action-bar" id="bulkBar">
        <span id="bulkCount" class="fw-semibold me-2">0 selected</span>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="submitBulkAction('acknowledge')">
            <i class="bi bi-check-lg me-1"></i>Acknowledge
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="submitBulkAction('create-tickets')">
            <i class="bi bi-ticket-perforated me-1"></i>Create Tickets
        </button>
        <button type="button" class="btn btn-sm btn-outline-success" onclick="submitBulkAction('resolve')">
            <i class="bi bi-check-circle me-1"></i>Resolve
        </button>
        <a href="#" class="text-light ms-auto small" onclick="deselectAll(); return false;">Deselect All</a>
    </div>

    {{-- Hidden bulk forms --}}
    <form method="POST" action="{{ route('alerts.bulk-acknowledge') }}" id="bulkAcknowledgeForm">
        @csrf
        <div id="bulkAcknowledgeInputs"></div>
    </form>
    <form method="POST" action="{{ route('alerts.bulk-create-tickets') }}" id="bulkCreateTicketsForm">
        @csrf
        <div id="bulkCreateTicketsInputs"></div>
    </form>
    <form method="POST" action="{{ route('alerts.bulk-resolve') }}" id="bulkResolveForm">
        @csrf
        <div id="bulkResolveInputs"></div>
    </form>

@push('styles')
<style>
.bulk-action-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: var(--primary-dark, #0f2440);
    color: #fff;
    padding: 0.6rem 1.5rem;
    display: none;
    align-items: center;
    gap: 0.5rem;
    z-index: 1050;
    border-top: 2px solid var(--accent, #fed136);
    box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
}
.bulk-action-bar.active { display: flex; }
</style>
@endpush

@push('scripts')
<script>
(function() {
    var selectedIds = new Set();
    var bar = document.getElementById('bulkBar');
    var countEl = document.getElementById('bulkCount');
    var selectAllEl = document.getElementById('selectAll');
    if (!bar || !selectAllEl) return;

    function updateBar() {
        countEl.textContent = selectedIds.size + ' selected';
        bar.classList.toggle('active', selectedIds.size > 0);
        selectAllEl.indeterminate = selectedIds.size > 0 && selectedIds.size < document.querySelectorAll('.alert-checkbox').length;
        selectAllEl.checked = selectedIds.size > 0 && selectedIds.size === document.querySelectorAll('.alert-checkbox').length;
    }

    selectAllEl.addEventListener('change', function() {
        document.querySelectorAll('.alert-checkbox').forEach(function(cb) {
            cb.checked = selectAllEl.checked;
            if (selectAllEl.checked) selectedIds.add(cb.value);
            else selectedIds.delete(cb.value);
        });
        updateBar();
    });

    document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('alert-checkbox')) return;
        if (e.target.checked) selectedIds.add(e.target.value);
        else selectedIds.delete(e.target.value);
        updateBar();
    });

    window.deselectAll = function() {
        selectedIds.clear();
        document.querySelectorAll('.alert-checkbox').forEach(function(cb) { cb.checked = false; });
        selectAllEl.checked = false;
        selectAllEl.indeterminate = false;
        updateBar();
    };

    window.submitBulkAction = function(action) {
        if (selectedIds.size === 0) return;

        var formMap = {
            'acknowledge': { form: 'bulkAcknowledgeForm', inputs: 'bulkAcknowledgeInputs' },
            'create-tickets': { form: 'bulkCreateTicketsForm', inputs: 'bulkCreateTicketsInputs' },
            'resolve': { form: 'bulkResolveForm', inputs: 'bulkResolveInputs' },
        };

        var target = formMap[action];
        if (!target) return;

        if (action === 'resolve' && !confirm('Resolve ' + selectedIds.size + ' alert(s)?')) return;

        var container = document.getElementById(target.inputs);
        container.innerHTML = '';
        selectedIds.forEach(function(id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'alert_ids[]';
            input.value = id;
            container.appendChild(input);
        });

        document.getElementById(target.form).submit();
    };
})();
</script>
@endpush
@endsection
