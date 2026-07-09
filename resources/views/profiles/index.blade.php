@extends('layouts.app')

@section('title', 'Recurring Profiles')

@section('content')
<div class="row mb-3">
    <div class="col d-flex align-items-center justify-content-between">
        <h4 class="section-title mb-0">Recurring Invoice Profiles</h4>
    </div>
</div>

{{-- Filters --}}
<div class="card shadow-sm card-static mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route('profiles.index') }}">
            <div class="row g-2">
                <div class="col-md-3">
                    <select name="client_id" class="form-select form-select-sm">
                        <option value="">All Clients</option>
                        @foreach($clients as $c)
                            <option value="{{ $c->id }}" {{ ($filters['client_id'] ?? '') == $c->id ? 'selected' : '' }}>
                                {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="active" class="form-select form-select-sm">
                        <option value="">Active &amp; Inactive</option>
                        <option value="1" {{ ($filters['active'] ?? '') === '1' ? 'selected' : '' }}>Active only</option>
                        <option value="0" {{ ($filters['active'] ?? '') === '0' ? 'selected' : '' }}>Inactive only</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="sku_id" class="form-select form-select-sm">
                        <option value="">All SKUs</option>
                        @foreach($skus as $s)
                            <option value="{{ $s->id }}" {{ ($filters['sku_id'] ?? '') == $s->id ? 'selected' : '' }}>
                                {{ $s->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-auto">
                    <select name="taxable" class="form-select form-select-sm">
                        <option value="">Tax: Any</option>
                        <option value="0" {{ ($filters['taxable'] ?? '') === '0' ? 'selected' : '' }}>Has non-taxable lines</option>
                        <option value="1" {{ ($filters['taxable'] ?? '') === '1' ? 'selected' : '' }}>Has taxable lines</option>
                    </select>
                </div>
                <div class="col-md-auto d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
                    <a href="{{ route('profiles.index') }}" class="btn btn-outline-secondary btn-sm" title="Clear">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

@if($profiles->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="bi bi-arrow-repeat" style="font-size: 3rem;"></i>
        <p class="mt-3">No recurring profiles found.</p>
    </div>
@else
    <div class="card shadow-sm card-static">
        <div class="text-center py-2 bg-light border-bottom small" id="selectAllBanner" style="display:none;">
            <span id="selectAllBannerText"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    @php
                        $sortUrl = function($col) use ($sort, $dir, $filters) {
                            $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
                            return route('profiles.index', array_filter($filters) + ['sort' => $col, 'dir' => $newDir]);
                        };
                        $sortIcon = function($col) use ($sort, $dir) {
                            if ($sort !== $col) return '<i class="bi bi-chevron-expand text-muted opacity-50 ms-1"></i>';
                            return $dir === 'asc'
                                ? '<i class="bi bi-sort-up ms-1"></i>'
                                : '<i class="bi bi-sort-down ms-1"></i>';
                        };
                    @endphp
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" class="form-check-input" id="selectAll"></th>
                        <th style="width: 30px;"></th>
                        <th><a href="{{ $sortUrl('client') }}" class="text-decoration-none" style="color: inherit;">Client{!! $sortIcon('client') !!}</a></th>
                        <th><a href="{{ $sortUrl('contract') }}" class="text-decoration-none" style="color: inherit;">Contract{!! $sortIcon('contract') !!}</a></th>
                        <th><a href="{{ $sortUrl('name') }}" class="text-decoration-none" style="color: inherit;">Profile{!! $sortIcon('name') !!}</a></th>
                        <th><a href="{{ $sortUrl('billing_period') }}" class="text-decoration-none" style="color: inherit;">Billing{!! $sortIcon('billing_period') !!}</a></th>
                        <th><a href="{{ $sortUrl('payment_terms_days') }}" class="text-decoration-none" style="color: inherit;">Terms{!! $sortIcon('payment_terms_days') !!}</a></th>
                        <th><a href="{{ $sortUrl('is_active') }}" class="text-decoration-none" style="color: inherit;">Status{!! $sortIcon('is_active') !!}</a></th>
                        <th><a href="{{ $sortUrl('next_run_date') }}" class="text-decoration-none" style="color: inherit;">Next Run{!! $sortIcon('next_run_date') !!}</a></th>
                        <th><a href="{{ $sortUrl('last_run_date') }}" class="text-decoration-none" style="color: inherit;">Last Run{!! $sortIcon('last_run_date') !!}</a></th>
                        <th>Source</th>
                        <th class="text-center">Auto-push</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($profiles as $profile)
                        <tr class="cursor-pointer" onclick="window.location='{{ route('profiles.show', $profile) }}'">
                            <td onclick="event.stopPropagation()">
                                <input type="checkbox" class="form-check-input profile-checkbox"
                                       value="{{ $profile->id }}">
                            </td>
                            <td onclick="event.stopPropagation()">
                                @if($profile->lines->isNotEmpty())
                                    <a href="#" class="text-muted" data-bs-toggle="collapse" data-bs-target="#profileLines{{ $profile->id }}" onclick="event.preventDefault(); this.querySelector('i').classList.toggle('bi-chevron-right'); this.querySelector('i').classList.toggle('bi-chevron-down');">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                @endif
                            </td>
                            <td class="small">
                                <span onclick="event.stopPropagation()">
                                    <x-client-badge :client="$profile->contract->client" />
                                </span>
                            </td>
                            <td class="small">
                                <span onclick="event.stopPropagation()">
                                    <x-contract-badge :contract="$profile->contract" />
                                </span>
                            </td>
                            <td>
                                <a href="{{ route('profiles.show', $profile) }}" class="text-decoration-none fw-semibold">
                                    {{ $profile->name }}
                                </a>
                                @if($profile->notes)
                                    <i class="bi bi-chat-left-text text-muted ms-1" title="{{ $profile->notes }}"></i>
                                @endif
                            </td>
                            <td class="small">{{ $profile->billing_period->label() }}</td>
                            <td class="small">{{ $profile->payment_terms_days !== null ? $profile->payment_terms_days . 'd' : '—' }}</td>
                            <td>
                                @if($profile->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">Inactive</span>
                                @endif
                            </td>
                            <td class="small">
                                @if($profile->next_run_date)
                                    <span class="{{ $profile->is_active && $profile->next_run_date->isPast() ? 'text-danger fw-semibold' : '' }}">
                                        {{ $profile->next_run_date->format('M j, Y') }}
                                    </span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small">{{ $profile->last_run_date?->format('M j, Y') ?? '—' }}</td>
                            <td class="small">
                                <i class="bi bi-pencil text-muted"></i> Native
                            </td>
                            <td class="small text-center">
                                @if($profile->auto_push_mode)
                                    <span class="badge bg-info" title="{{ $profile->auto_push_mode->label() }}">
                                        <i class="bi bi-arrow-up-circle me-1"></i>{{ $profile->auto_push_mode === \App\Enums\AutoPushMode::PushAndSend ? 'Push+Send' : 'Push' }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                        @if($profile->lines->isNotEmpty())
                            <tr class="collapse" id="profileLines{{ $profile->id }}">
                                <td colspan="13" class="p-0">
                                    <table class="table table-sm mb-0 ms-4" style="background: #f8f9fa;">
                                        <thead>
                                            <tr class="text-muted small">
                                                <th>SKU</th>
                                                <th>Description</th>
                                                <th>Qty Type</th>
                                                <th class="text-end">Unit Price</th>
                                                <th class="text-center">Taxable</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($profile->lines as $line)
                                                <tr class="small">
                                                    <td>{{ $line->sku?->name ?? '—' }}</td>
                                                    <td>{{ $line->description ?: '—' }}</td>
                                                    <td>
                                                        {{ $line->quantity_type->label() }}
                                                        @if($line->quantity_type === \App\Enums\QuantityType::Fixed && $line->fixed_quantity)
                                                            <span class="text-muted">({{ rtrim(rtrim(number_format($line->fixed_quantity, 2), '0'), '.') }})</span>
                                                        @endif
                                                    </td>
                                                    <td class="text-end">${{ number_format($line->unit_price, 2) }}</td>
                                                    <td class="text-center">
                                                        @if($line->is_taxable)
                                                            <i class="bi bi-check text-success"></i>
                                                        @else
                                                            <i class="bi bi-dash text-muted"></i>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $profiles->links() }}
    </div>

    {{-- Bulk Action Bar --}}
    <div class="bulk-action-bar" id="bulkBar">
        <span id="bulkCount" class="fw-semibold me-2">0 selected</span>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('edit')">
            <i class="bi bi-pencil me-1"></i>Edit
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('activate')">
            <i class="bi bi-toggle-on me-1"></i>Activate
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('deactivate')">
            <i class="bi bi-toggle-off me-1"></i>Deactivate
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('set_quantity_type')">
            <i class="bi bi-sliders me-1"></i>Set Qty Type
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('enable_auto_push')">
            <i class="bi bi-arrow-up-circle me-1"></i>Auto-push
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('enable_auto_push_send')">
            <i class="bi bi-send me-1"></i>Auto-push+Send
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('disable_auto_push')">
            <i class="bi bi-x-circle me-1"></i>No auto-push
        </button>
        <a href="#" class="text-light ms-auto small" onclick="deselectAll(); return false;">Deselect All</a>
    </div>

    {{-- Bulk Action Modal --}}
    <div class="modal fade" id="bulkActionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('profiles.bulk-action') }}" id="bulkForm">
                    @csrf
                    <input type="hidden" name="action" id="bulkActionField">
                    <div id="bulkProfileInputs"></div>
                    <div class="modal-header">
                        <h5 class="modal-title" id="bulkModalTitle">Confirm</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="bulkModalBody"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm" id="bulkSubmitBtn">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection

@push('styles')
<style>
.cursor-pointer { cursor: pointer; }
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
    var selectAllFilter = false;
    var bar = document.getElementById('bulkBar');
    var countEl = document.getElementById('bulkCount');
    var selectAllEl = document.getElementById('selectAll');
    var banner = document.getElementById('selectAllBanner');
    var bannerText = document.getElementById('selectAllBannerText');
    if (!bar || !selectAllEl) return;

    var totalFilterCount = {{ $profiles->total() }};
    var pageProfileCount = {{ $profiles->count() }};
    var currentFilters = @json(array_filter($filters ?? []));
    var skuOptions = @json($skus->map(fn($s) => ['id' => $s->id, 'name' => $s->name]));
    {{-- Custom types are excluded from bulk assignment: each needs a specific type chosen per line. --}}
    var quantityTypeOptions = @json(collect($quantityTypes)->reject(fn($qt) => $qt === \App\Enums\QuantityType::Custom)->map(fn($qt) => ['value' => $qt->value, 'label' => $qt->label()])->values());

    function updateBar() {
        var displayCount = selectAllFilter ? totalFilterCount : selectedIds.size;
        countEl.textContent = displayCount + ' selected';
        bar.classList.toggle('active', displayCount > 0);

        var enabledCheckboxes = document.querySelectorAll('.profile-checkbox:not(:disabled)');
        var enabledCount = enabledCheckboxes.length;
        var checkedEnabled = 0;
        enabledCheckboxes.forEach(function(cb) { if (cb.checked) checkedEnabled++; });

        var allOnPage = checkedEnabled === enabledCount && enabledCount > 0;
        selectAllEl.indeterminate = checkedEnabled > 0 && checkedEnabled < enabledCount;
        selectAllEl.checked = allOnPage;

        if (banner) {
            if (selectAllFilter) {
                banner.style.display = '';
                bannerText.innerHTML = 'All <strong>' + totalFilterCount + '</strong> profiles in this filter are selected. <a href="#" onclick="window.clearFilterSelection(); return false;">Clear selection</a>';
            } else if (allOnPage && totalFilterCount > pageProfileCount) {
                banner.style.display = '';
                bannerText.innerHTML = 'All <strong>' + checkedEnabled + '</strong> selectable profiles on this page are selected. <a href="#" onclick="window.selectAllInFilter(); return false;">Select all <strong>' + totalFilterCount + '</strong> profiles matching this filter</a>';
            } else {
                banner.style.display = 'none';
            }
        }
    }

    selectAllEl.addEventListener('change', function() {
        selectAllFilter = false;
        document.querySelectorAll('.profile-checkbox:not(:disabled)').forEach(function(cb) {
            cb.checked = selectAllEl.checked;
            if (selectAllEl.checked) selectedIds.add(cb.value);
            else selectedIds.delete(cb.value);
        });
        updateBar();
    });

    document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('profile-checkbox')) return;
        if (selectAllFilter) selectAllFilter = false;
        if (e.target.checked) selectedIds.add(e.target.value);
        else selectedIds.delete(e.target.value);
        updateBar();
    });

    window.selectAllInFilter = function() {
        selectAllFilter = true;
        document.querySelectorAll('.profile-checkbox:not(:disabled)').forEach(function(cb) {
            cb.checked = true;
            selectedIds.add(cb.value);
        });
        updateBar();
    };

    window.clearFilterSelection = function() {
        selectAllFilter = false;
        selectedIds.clear();
        document.querySelectorAll('.profile-checkbox').forEach(function(cb) { cb.checked = false; });
        selectAllEl.checked = false;
        selectAllEl.indeterminate = false;
        updateBar();
    };

    window.deselectAll = window.clearFilterSelection;

    window.openBulkModal = function(action) {
        var count = selectAllFilter ? totalFilterCount : selectedIds.size;
        if (count === 0) return;

        document.getElementById('bulkActionField').value = action;

        // Populate hidden inputs
        var container = document.getElementById('bulkProfileInputs');
        container.innerHTML = '';

        if (selectAllFilter) {
            var saf = document.createElement('input');
            saf.type = 'hidden'; saf.name = 'select_all_filter'; saf.value = '1';
            container.appendChild(saf);
            for (var key in currentFilters) {
                var fi = document.createElement('input');
                fi.type = 'hidden';
                fi.name = 'filter_' + key;
                fi.value = currentFilters[key];
                container.appendChild(fi);
            }
        } else {
            selectedIds.forEach(function(id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'profile_ids[]';
                input.value = id;
                container.appendChild(input);
            });
        }

        var title = document.getElementById('bulkModalTitle');
        var body = document.getElementById('bulkModalBody');
        var btn = document.getElementById('bulkSubmitBtn');
        body.innerHTML = '';

        if (action === 'edit') {
            title.textContent = 'Bulk Edit ' + count + ' Profile(s)';
            body.innerHTML = '<p class="small text-muted mb-3">Leave fields blank to keep current values.</p>' +
                '<div class="mb-3">' +
                    '<label class="form-label">Payment Terms (days)</label>' +
                    '<input type="number" class="form-control" name="payment_terms_days" min="0" max="365" placeholder="e.g. 10">' +
                '</div>' +
                '<div class="mb-3">' +
                    '<label class="form-label">Billing Day</label>' +
                    '<input type="number" class="form-control" name="billing_day" min="1" max="28" placeholder="e.g. 1">' +
                '</div>';
            btn.textContent = 'Update';
            btn.className = 'btn btn-primary btn-sm';
        } else if (action === 'activate') {
            title.textContent = 'Activate ' + count + ' Profile(s)';
            body.innerHTML = '<p>Set <strong>' + count + '</strong> profile(s) to Active?</p>' +
                '<p class="text-muted small">Inactive profiles will be activated. Already-active profiles are unaffected.</p>';
            btn.textContent = 'Activate';
            btn.className = 'btn btn-success btn-sm';
        } else if (action === 'deactivate') {
            title.textContent = 'Deactivate ' + count + ' Profile(s)';
            body.innerHTML = '<p>Set <strong>' + count + '</strong> profile(s) to Inactive?</p>' +
                '<p class="text-muted small">Active profiles will be deactivated. Already-inactive profiles are unaffected.</p>';
            btn.textContent = 'Deactivate';
            btn.className = 'btn btn-warning btn-sm';
        } else if (action === 'set_quantity_type') {
            title.textContent = 'Set Quantity Type on ' + count + ' Profile(s)';
            var skuOpts = '<option value="">Select SKU...</option>';
            skuOptions.forEach(function(s) {
                skuOpts += '<option value="' + s.id + '">' + s.name + '</option>';
            });
            var qtOpts = '';
            quantityTypeOptions.forEach(function(qt) {
                qtOpts += '<option value="' + qt.value + '">' + qt.label + '</option>';
            });
            body.innerHTML = '<p class="small text-muted mb-3">Update quantity type on all profile lines matching a specific SKU across selected profiles.</p>' +
                '<div class="mb-3">' +
                    '<label class="form-label">For lines using SKU</label>' +
                    '<select class="form-select" name="target_sku_id" required>' + skuOpts + '</select>' +
                '</div>' +
                '<div class="mb-3">' +
                    '<label class="form-label">Set quantity type to</label>' +
                    '<select class="form-select" name="new_quantity_type" required>' + qtOpts + '</select>' +
                '</div>';
            btn.textContent = 'Update Lines';
            btn.className = 'btn btn-primary btn-sm';
        } else if (action === 'enable_auto_push') {
            title.textContent = 'Enable Auto-Push on ' + count + ' Profile(s)';
            body.innerHTML = '<p>Enable <strong>push on generation</strong> for <strong>' + count + '</strong> profile(s)?</p>' +
                '<p class="text-muted small">Invoices will be automatically pushed to QBO or Stripe when generated. Clients must be mapped to a billing backend.</p>';
            btn.textContent = 'Enable';
            btn.className = 'btn btn-primary btn-sm';
        } else if (action === 'enable_auto_push_send') {
            title.textContent = 'Enable Auto-Push+Send on ' + count + ' Profile(s)';
            body.innerHTML = '<p>Enable <strong>push and send on generation</strong> for <strong>' + count + '</strong> profile(s)?</p>' +
                '<p class="text-muted small">Invoices will be automatically pushed and emailed to the customer via Stripe when generated. For QBO clients, this behaves the same as push-only.</p>';
            btn.textContent = 'Enable';
            btn.className = 'btn btn-primary btn-sm';
        } else if (action === 'disable_auto_push') {
            title.textContent = 'Disable Auto-Push on ' + count + ' Profile(s)';
            body.innerHTML = '<p>Disable auto-push for <strong>' + count + '</strong> profile(s)?</p>' +
                '<p class="text-muted small">Invoices will remain as Draft after generation until manually pushed.</p>';
            btn.textContent = 'Disable';
            btn.className = 'btn btn-warning btn-sm';
        }

        new bootstrap.Modal(document.getElementById('bulkActionModal')).show();
    };
})();
</script>
@endpush
