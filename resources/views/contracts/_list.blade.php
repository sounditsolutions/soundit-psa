{{-- Reusable contract list partial.
     Expects: $contracts, $filters, $clients, $statuses, $types, $prepaySkus
     Optional: $listRoute (string, default 'contracts.index-all'), $prefilter (array, default [])
--}}
@php
    $listRoute = $listRoute ?? 'contracts.index-all';
    $prefilter = $prefilter ?? [];
@endphp

{{-- Filters --}}
<div class="card shadow-sm card-static mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route($listRoute, $prefilter) }}">
            <div class="row g-2">
                @unless(isset($prefilter['client_id']))
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
                @endunless
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        @foreach($statuses as $s)
                            <option value="{{ $s->value }}" {{ ($filters['status'] ?? '') === $s->value ? 'selected' : '' }}>
                                {{ $s->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        @foreach($types as $t)
                            <option value="{{ $t->value }}" {{ ($filters['type'] ?? '') === $t->value ? 'selected' : '' }}>
                                {{ $t->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
                    <a href="{{ route($listRoute, $prefilter) }}" class="btn btn-outline-secondary btn-sm" title="Clear">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

@if($contracts->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="bi bi-file-earmark-text" style="font-size: 3rem;"></i>
        <p class="mt-3">No contracts found.</p>
    </div>
@else
    <div class="card shadow-sm card-static">
        <div class="text-center py-2 bg-light border-bottom small" id="selectAllBanner" style="display:none;">
            <span id="selectAllBannerText"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" class="form-check-input" id="selectAll"></th>
                        @unless(isset($prefilter['client_id']))
                        <th>Client</th>
                        @endunless
                        <th>Contract</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Billing</th>
                        <th>Start</th>
                        <th>End</th>
                        <th>Assignments</th>
                        <th class="text-center">Profiles</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($contracts as $contract)
                        <tr class="cursor-pointer" onclick="window.location='{{ route('contracts.show', $contract) }}'">
                            <td onclick="event.stopPropagation()">
                                <input type="checkbox" class="form-check-input contract-checkbox"
                                       value="{{ $contract->id }}">
                            </td>
                            @unless(isset($prefilter['client_id']))
                            <td class="small">
                                <a href="{{ route('clients.show', $contract->client) }}" class="text-decoration-none text-muted"
                                   onclick="event.stopPropagation()">
                                    {{ $contract->client?->name ?? '—' }}
                                </a>
                            </td>
                            @endunless
                            <td>
                                <a href="{{ route('contracts.show', $contract) }}" class="text-decoration-none fw-semibold">
                                    {{ $contract->name }}
                                </a>
                                @if($contract->documents_count > 0)
                                    <i class="bi bi-file-earmark-text text-muted ms-1" title="{{ $contract->documents_count }} document(s)"></i>
                                @endif
                            </td>
                            <td class="small">{{ $contract->type->label() }}</td>
                            <td><span class="badge {{ $contract->status->badgeClass() }}">{{ $contract->status->label() }}</span></td>
                            <td class="small">{{ $contract->billing_period->label() }}</td>
                            <td class="small">{{ $contract->start_date->format('M j, Y') }}</td>
                            <td class="small">{{ $contract->end_date?->format('M j, Y') ?? '—' }}</td>
                            <td class="small">
                                @if($contract->people_count + $contract->assets_count + $contract->licenses_count > 0)
                                    <span title="People">{{ $contract->people_count }}<i class="bi bi-people ms-1 me-2 text-muted"></i></span>
                                    <span title="Assets">{{ $contract->assets_count }}<i class="bi bi-display ms-1 me-2 text-muted"></i></span>
                                    <span title="Licenses">{{ $contract->licenses_count }}<i class="bi bi-key ms-1 text-muted"></i></span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            <td class="text-center small">{{ $contract->profiles_count }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $contracts->links() }}
    </div>

    {{-- Bulk Action Bar --}}
    <div class="bulk-action-bar" id="bulkBar">
        <span id="bulkCount" class="fw-semibold me-2">0 selected</span>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('status')">
            <i class="bi bi-toggle-on me-1"></i>Status
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('type')">
            <i class="bi bi-tag me-1"></i>Type
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('edit')">
            <i class="bi bi-pencil me-1"></i>Edit
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('sla')">
            <i class="bi bi-speedometer2 me-1"></i>SLA
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="openBulkModal('delete')">
            <i class="bi bi-trash me-1"></i>Delete
        </button>
        <a href="#" class="text-light ms-auto small" onclick="deselectAll(); return false;">Deselect All</a>
    </div>

    {{-- Bulk Action Modal --}}
    <div class="modal fade" id="bulkActionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('contracts.bulk-action') }}" id="bulkForm">
                    @csrf
                    <input type="hidden" name="action" id="bulkActionField">
                    <div id="bulkContractInputs"></div>
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

    var totalFilterCount = {{ $contracts->total() }};
    var pageContractCount = {{ $contracts->count() }};
    @php
        $jsFilters = array_filter([
            'client_id' => $filters['client_id'] ?? null,
            'status' => $filters['status'] ?? null,
            'type' => $filters['type'] ?? null,
        ], fn($v) => $v !== null);
    @endphp
    var currentFilters = @json($jsFilters);

    var statusOptions = @json(collect($statuses)->map(fn($s) => ['value' => $s->value, 'label' => $s->label()]));
    var typeOptions = @json(collect($types)->map(fn($t) => ['value' => $t->value, 'label' => $t->label()]));
    var prepaySkuOptions = @json($prepaySkus->map(fn($s) => ['id' => $s->id, 'name' => $s->name]));

    function updateBar() {
        var displayCount = selectAllFilter ? totalFilterCount : selectedIds.size;
        countEl.textContent = displayCount + ' selected';
        bar.classList.toggle('active', displayCount > 0);

        // Only count non-disabled checkboxes for "all on page" logic
        var enabledCheckboxes = document.querySelectorAll('.contract-checkbox:not(:disabled)');
        var enabledCount = enabledCheckboxes.length;
        var checkedEnabled = 0;
        enabledCheckboxes.forEach(function(cb) { if (cb.checked) checkedEnabled++; });

        var allOnPage = checkedEnabled === enabledCount && enabledCount > 0;
        selectAllEl.indeterminate = checkedEnabled > 0 && checkedEnabled < enabledCount;
        selectAllEl.checked = allOnPage;

        if (banner) {
            if (selectAllFilter) {
                banner.style.display = '';
                bannerText.innerHTML = 'All <strong>' + totalFilterCount + '</strong> contracts in this filter are selected. <a href="#" onclick="window.clearFilterSelection(); return false;">Clear selection</a>';
            } else if (allOnPage && totalFilterCount > pageContractCount) {
                banner.style.display = '';
                bannerText.innerHTML = 'All <strong>' + checkedEnabled + '</strong> selectable contracts on this page are selected. <a href="#" onclick="window.selectAllInFilter(); return false;">Select all <strong>' + totalFilterCount + '</strong> contracts matching this filter</a>';
            } else {
                banner.style.display = 'none';
            }
        }
    }

    selectAllEl.addEventListener('change', function() {
        selectAllFilter = false;
        document.querySelectorAll('.contract-checkbox:not(:disabled)').forEach(function(cb) {
            cb.checked = selectAllEl.checked;
            if (selectAllEl.checked) selectedIds.add(cb.value);
            else selectedIds.delete(cb.value);
        });
        updateBar();
    });

    document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('contract-checkbox')) return;
        if (selectAllFilter) selectAllFilter = false;
        if (e.target.checked) selectedIds.add(e.target.value);
        else selectedIds.delete(e.target.value);
        updateBar();
    });

    window.selectAllInFilter = function() {
        selectAllFilter = true;
        document.querySelectorAll('.contract-checkbox:not(:disabled)').forEach(function(cb) {
            cb.checked = true;
            selectedIds.add(cb.value);
        });
        updateBar();
    };

    window.clearFilterSelection = function() {
        selectAllFilter = false;
        selectedIds.clear();
        document.querySelectorAll('.contract-checkbox').forEach(function(cb) { cb.checked = false; });
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
        var container = document.getElementById('bulkContractInputs');
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
                input.name = 'contract_ids[]';
                input.value = id;
                container.appendChild(input);
            });
        }

        var title = document.getElementById('bulkModalTitle');
        var body = document.getElementById('bulkModalBody');
        var btn = document.getElementById('bulkSubmitBtn');
        body.innerHTML = '';

        if (action === 'status') {
            title.textContent = 'Change Status';
            body.innerHTML = '<p>Change status on <strong>' + count + '</strong> contract(s) to:</p>'
                + '<select name="status" class="form-select form-select-sm" required>'
                + '<option value="">Select status...</option>'
                + statusOptions.map(function(s) {
                    return '<option value="' + s.value + '">' + s.label + '</option>';
                }).join('')
                + '</select>';
            btn.textContent = 'Update ' + count + ' contract(s)';
            btn.className = 'btn btn-primary btn-sm';
        } else if (action === 'type') {
            title.textContent = 'Change Type';
            body.innerHTML = '<p>Change type on <strong>' + count + '</strong> contract(s) to:</p>'
                + '<select name="type" class="form-select form-select-sm" required>'
                + '<option value="">Select type...</option>'
                + typeOptions.map(function(t) {
                    return '<option value="' + t.value + '">' + t.label + '</option>';
                }).join('')
                + '</select>';
            btn.textContent = 'Update ' + count + ' contract(s)';
            btn.className = 'btn btn-primary btn-sm';
        } else if (action === 'edit') {
            title.textContent = 'Edit Attributes';
            var skuOpts = '<option value="">No change</option>' + prepaySkuOptions.map(function(s) {
                return '<option value="' + s.id + '">' + s.name + '</option>';
            }).join('');

            body.innerHTML = '<p>Edit attributes on <strong>' + count + '</strong> contract(s). Leave blank to keep current value.</p>'
                + '<div class="mb-3">'
                + '<label class="form-label small fw-semibold">Term Length (months)</label>'
                + '<input type="number" name="term_length_months" class="form-control form-control-sm" min="1" max="120" placeholder="No change">'
                + '</div>'
                + '<div class="mb-3">'
                + '<label class="form-label small fw-semibold">Auto Renew</label>'
                + '<select name="auto_renew" class="form-select form-select-sm">'
                + '<option value="">No change</option>'
                + '<option value="1">Yes</option>'
                + '<option value="0">No</option>'
                + '</select>'
                + '</div>'
                + '<div class="mb-3">'
                + '<label class="form-label small fw-semibold">Payment Terms (days)</label>'
                + '<input type="number" name="payment_terms_days" class="form-control form-control-sm" min="0" max="365" placeholder="No change">'
                + '</div>'
                + '<div class="mb-3">'
                + '<label class="form-label small fw-semibold">Portal Prepay SKU</label>'
                + '<select name="portal_prepay_sku_id" class="form-select form-select-sm">' + skuOpts + '</select>'
                + '</div>';
            btn.textContent = 'Update ' + count + ' contract(s)';
            btn.className = 'btn btn-primary btn-sm';
        } else if (action === 'sla') {
            title.textContent = 'Set SLA Terms';
            var priorities = ['p1', 'p2', 'p3', 'p4'];
            var responseInputs = priorities.map(function(p) {
                return '<div class="col-3"><label class="form-label small mb-1">' + p.toUpperCase() + '</label>'
                    + '<input type="number" step="any" min="0.25" class="form-control form-control-sm" name="response_' + p + '" placeholder="—"></div>';
            }).join('');
            var resolutionInputs = priorities.map(function(p) {
                return '<div class="col-3"><label class="form-label small mb-1">' + p.toUpperCase() + '</label>'
                    + '<input type="number" step="any" min="0.25" class="form-control form-control-sm" name="resolution_' + p + '" placeholder="—"></div>';
            }).join('');

            body.innerHTML = '<p>Set SLA terms on <strong>' + count + '</strong> contract(s).</p>'
                + '<div class="form-check form-switch mb-3">'
                + '<input type="hidden" name="sla_enabled" value="0">'
                + '<input class="form-check-input" type="checkbox" role="switch" id="bulkSlaEnabled" name="sla_enabled" value="1" checked '
                + 'onchange="document.getElementById(\'bulkSlaFields\').classList.toggle(\'d-none\', !this.checked)">'
                + '<label class="form-check-label" for="bulkSlaEnabled">SLA enabled</label>'
                + '</div>'
                + '<div id="bulkSlaFields">'
                + '<div class="small text-muted mb-2">Response time (hours)</div>'
                + '<div class="row g-2 mb-3">' + responseInputs + '</div>'
                + '<div class="small text-muted mb-2">Resolution time (hours)</div>'
                + '<div class="row g-2 mb-3">' + resolutionInputs + '</div>'
                + '</div>';
            btn.textContent = 'Update ' + count + ' contract(s)';
            btn.className = 'btn btn-primary btn-sm';
        } else if (action === 'delete') {
            title.textContent = 'Delete Contracts';
            body.innerHTML = '<p>Delete <strong>' + count + '</strong> contract(s)?</p>'
                + '<p class="text-danger small"><i class="bi bi-exclamation-triangle me-1"></i>All billing profiles on these contracts will be deactivated. This action can be reversed by restoring the contracts.</p>';
            btn.textContent = 'Delete ' + count + ' contract(s)';
            btn.className = 'btn btn-danger btn-sm';
        }

        new bootstrap.Modal(document.getElementById('bulkActionModal')).show();
    };
})();
</script>
@endpush
