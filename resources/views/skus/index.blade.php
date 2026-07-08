@extends('layouts.app')

@section('title', 'SKUs')

@section('content')
<div class="row mb-3">
    <div class="col d-flex justify-content-between align-items-center">
        <h4 class="section-title mb-0">SKUs / Products</h4>
        <div class="d-flex gap-2">
            @if(\App\Support\StripeConfig::isConfigured())
                <form method="POST" action="{{ route('skus.import-stripe') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm"
                            onclick="return confirm('Import products from Stripe?')">
                        <i class="bi bi-cloud-download me-1"></i>Import from Stripe
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('skus.import-qbo') }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm"
                            onclick="return confirm('Import service items from QuickBooks Online?')">
                        <i class="bi bi-cloud-download me-1"></i>Import from QBO
                    </button>
                </form>
            @endif
            <a href="{{ route('skus.create') }}" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i>New SKU
            </a>
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<form method="GET" action="{{ route('skus.index') }}" class="mb-3">
    <div class="row g-2">
        <div class="col">
            <div class="input-group">
                <input type="text" name="search" class="form-control" placeholder="Search by name, code, or description..."
                       value="{{ $search }}" autofocus>
                <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
                @if($search || $category || $active !== '1' || $quantity_type)
                    <a href="{{ route('skus.index') }}" class="btn btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
                @endif
            </div>
        </div>
        <div class="col-auto" style="min-width: 150px;">
            <select name="category" class="form-select" onchange="this.form.submit()">
                <option value="">All categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat }}" {{ $category === $cat ? 'selected' : '' }}>{{ $cat }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto" style="min-width: 160px;">
            <select name="quantity_type" class="form-select" onchange="this.form.submit()">
                <option value="">All qty types</option>
                @foreach($quantityTypes as $qt)
                    <option value="{{ $qt->value }}" {{ $quantity_type === $qt->value ? 'selected' : '' }}>{{ $qt->label() }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto" style="min-width: 130px;">
            <select name="active" class="form-select" onchange="this.form.submit()">
                <option value="1" {{ $active === '1' ? 'selected' : '' }}>Active only</option>
                <option value="all" {{ $active === 'all' ? 'selected' : '' }}>All</option>
                <option value="0" {{ $active === '0' ? 'selected' : '' }}>Inactive</option>
            </select>
        </div>
    </div>
</form>

@if($skus->isEmpty())
    <div class="alert alert-info">
        @if($search || $category || $quantity_type)
            No SKUs match your filters.
        @else
            No SKUs found. Create one or import from QuickBooks.
        @endif
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
                        <th>Name</th>
                        <th>Code</th>
                        <th class="d-none d-md-table-cell">Category</th>
                        <th class="d-none d-lg-table-cell">Qty Type</th>
                        <th class="text-end">Price</th>
                        <th class="text-end d-none d-md-table-cell">Cost</th>
                        <th class="text-end d-none d-lg-table-cell">Prepay</th>
                        <th class="text-end d-none d-lg-table-cell">Profiles</th>
                        <th class="text-end d-none d-lg-table-cell">Margin</th>
                        <th class="text-center d-none d-md-table-cell">Tax</th>
                        <th class="text-center d-none d-lg-table-cell">Billing</th>
                        <th class="text-center" style="width: 80px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($skus as $sku)
                        <tr class="cursor-pointer" onclick="window.location='{{ route('skus.edit', $sku) }}'">
                            <td onclick="event.stopPropagation()">
                                <input type="checkbox" class="form-check-input sku-checkbox" value="{{ $sku->id }}">
                            </td>
                            <td>
                                <a href="{{ route('skus.edit', $sku) }}" class="text-decoration-none fw-semibold">
                                    {{ $sku->name }}
                                </a>
                                @if($sku->description)
                                    <i class="bi bi-info-circle text-muted ms-1" data-bs-toggle="popover"
                                       data-bs-trigger="hover focus" data-bs-placement="auto"
                                       data-bs-delay='{"show":300,"hide":200}'
                                       data-bs-content="{{ e($sku->description) }}"></i>
                                @endif
                            </td>
                            <td class="text-muted small">{{ $sku->sku_code }}</td>
                            <td class="d-none d-md-table-cell small">{{ $sku->category ?? '-' }}</td>
                            <td class="d-none d-lg-table-cell small">{{ $sku->default_quantity_type?->label() ?? '-' }}</td>
                            <td class="text-end">${{ number_format($sku->unit_price, 2) }}</td>
                            <td class="text-end d-none d-md-table-cell">${{ number_format($sku->unit_cost, 2) }}</td>
                            <td class="text-end d-none d-lg-table-cell">
                                @if($sku->prepaid_time_minutes)
                                    <span class="badge bg-info text-dark" title="{{ $sku->prepaid_time_minutes }} min per unit">
                                        <i class="bi bi-clock me-1"></i>{{ $sku->prepaid_time_minutes }}m
                                    </span>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-end d-none d-lg-table-cell">
                                @if($sku->profile_count > 0)
                                    {{ $sku->profile_count }}
                                @else
                                    <span class="text-muted">0</span>
                                @endif
                            </td>
                            <td class="text-end d-none d-lg-table-cell">
                                @if($sku->margin !== null)
                                    <span class="{{ $sku->margin >= 50 ? 'text-success' : ($sku->margin >= 20 ? '' : 'text-danger') }}">
                                        {{ $sku->margin }}%
                                    </span>
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-center d-none d-md-table-cell">
                                @if($sku->is_taxable)
                                    <i class="bi bi-check-circle text-success"></i>
                                @else
                                    <i class="bi bi-x-circle text-muted"></i>
                                @endif
                            </td>
                            <td class="text-center d-none d-lg-table-cell">
                                @if($sku->stripe_product_id)
                                    <i class="bi bi-stripe text-primary me-1" title="Stripe: {{ $sku->stripe_product_id }}{{ $sku->stripe_price_id ? ' / ' . $sku->stripe_price_id : ' (no price)' }}"></i>
                                @endif
                                @if($sku->qbo_item_id)
                                    @if($sku->qbo_sync_error)
                                        <i class="bi bi-exclamation-triangle text-warning" title="QBO error: {{ $sku->qbo_sync_error }}"></i>
                                    @else
                                        <i class="bi bi-cloud-check text-success" title="QBO: {{ $sku->qbo_item_id }} — synced {{ $sku->qbo_synced_at?->diffForHumans() }}"></i>
                                    @endif
                                @endif
                                @if(!$sku->stripe_product_id && !$sku->qbo_item_id)
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($sku->is_active)
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">Inactive</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">
        {{ $skus->links() }}
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
        <a href="#" class="text-light ms-auto small" onclick="deselectAll(); return false;">Deselect All</a>
    </div>

    {{-- Bulk Action Modal --}}
    <div class="modal fade" id="bulkActionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('skus.bulk-action') }}" id="bulkForm">
                    @csrf
                    <input type="hidden" name="action" id="bulkActionField">
                    <div id="bulkSkuInputs"></div>
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

    var totalFilterCount = {{ $skus->total() }};
    var pageSkuCount = {{ $skus->count() }};
    var currentFilters = {
        @if($search) search: @json($search), @endif
        @if($category) category: @json($category), @endif
        @if($quantity_type) quantity_type: @json($quantity_type), @endif
        active: @json($active)
    };
    var quantityTypeOptions = @json(collect($quantityTypes)->map(fn($qt) => ['value' => $qt->value, 'label' => $qt->label()]));
    var licenseTypeOptions = @json($licenseTypes->map(fn($lt) => ['id' => $lt->id, 'name' => $lt->name]));

    function updateBar() {
        var displayCount = selectAllFilter ? totalFilterCount : selectedIds.size;
        countEl.textContent = displayCount + ' selected';
        bar.classList.toggle('active', displayCount > 0);

        var checkboxes = document.querySelectorAll('.sku-checkbox');
        var checkedCount = 0;
        checkboxes.forEach(function(cb) { if (cb.checked) checkedCount++; });

        var allOnPage = checkedCount === checkboxes.length && checkboxes.length > 0;
        selectAllEl.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        selectAllEl.checked = allOnPage;

        if (banner) {
            if (selectAllFilter) {
                banner.style.display = '';
                bannerText.innerHTML = 'All <strong>' + totalFilterCount + '</strong> SKUs in this filter are selected. <a href="#" onclick="window.clearFilterSelection(); return false;">Clear selection</a>';
            } else if (allOnPage && totalFilterCount > pageSkuCount) {
                banner.style.display = '';
                bannerText.innerHTML = 'All <strong>' + checkedCount + '</strong> SKUs on this page are selected. <a href="#" onclick="window.selectAllInFilter(); return false;">Select all <strong>' + totalFilterCount + '</strong> SKUs matching this filter</a>';
            } else {
                banner.style.display = 'none';
            }
        }
    }

    selectAllEl.addEventListener('change', function() {
        selectAllFilter = false;
        document.querySelectorAll('.sku-checkbox').forEach(function(cb) {
            cb.checked = selectAllEl.checked;
            if (selectAllEl.checked) selectedIds.add(cb.value);
            else selectedIds.delete(cb.value);
        });
        updateBar();
    });

    document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('sku-checkbox')) return;
        if (selectAllFilter) selectAllFilter = false;
        if (e.target.checked) selectedIds.add(e.target.value);
        else selectedIds.delete(e.target.value);
        updateBar();
    });

    window.selectAllInFilter = function() {
        selectAllFilter = true;
        document.querySelectorAll('.sku-checkbox').forEach(function(cb) {
            cb.checked = true;
            selectedIds.add(cb.value);
        });
        updateBar();
    };

    window.clearFilterSelection = function() {
        selectAllFilter = false;
        selectedIds.clear();
        document.querySelectorAll('.sku-checkbox').forEach(function(cb) { cb.checked = false; });
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
        var container = document.getElementById('bulkSkuInputs');
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
                input.name = 'sku_ids[]';
                input.value = id;
                container.appendChild(input);
            });
        }

        var title = document.getElementById('bulkModalTitle');
        var body = document.getElementById('bulkModalBody');
        var btn = document.getElementById('bulkSubmitBtn');
        body.innerHTML = '';

        if (action === 'edit') {
            title.textContent = 'Bulk Edit ' + count + ' SKU(s)';
            var qtOpts = '<option value="">— Keep current —</option>';
            quantityTypeOptions.forEach(function(qt) {
                qtOpts += '<option value="' + qt.value + '">' + qt.label + '</option>';
            });
            var ltOpts = '<option value="">— Keep current —</option>';
            licenseTypeOptions.forEach(function(lt) {
                ltOpts += '<option value="' + lt.id + '">' + lt.name + '</option>';
            });
            body.innerHTML = '<p class="small text-muted mb-3">Leave fields on "Keep current" to preserve existing values.</p>' +
                '<div class="mb-3">' +
                    '<label class="form-label">Category</label>' +
                    '<input type="text" class="form-control" name="category" maxlength="50" placeholder="e.g. Managed, Security">' +
                    '<div class="form-check mt-1"><input type="checkbox" class="form-check-input" name="clear_category" value="1" id="clearCatCheck">' +
                    '<label class="form-check-label small text-muted" for="clearCatCheck">Clear category (set to none)</label></div>' +
                '</div>' +
                '<div class="mb-3">' +
                    '<label class="form-label">Default Quantity Type</label>' +
                    '<select class="form-select" name="default_quantity_type">' + qtOpts + '</select>' +
                '</div>' +
                '<div class="mb-3">' +
                    '<label class="form-label">Default License Type</label>' +
                    '<select class="form-select" name="default_license_type_id">' + ltOpts + '</select>' +
                '</div>';
            btn.textContent = 'Update';
            btn.className = 'btn btn-primary btn-sm';
        } else if (action === 'activate') {
            title.textContent = 'Activate ' + count + ' SKU(s)';
            body.innerHTML = '<p>Set <strong>' + count + '</strong> SKU(s) to Active?</p>' +
                '<p class="text-muted small">Inactive SKUs will be activated. Already-active SKUs are unaffected.</p>';
            btn.textContent = 'Activate';
            btn.className = 'btn btn-success btn-sm';
        } else if (action === 'deactivate') {
            title.textContent = 'Deactivate ' + count + ' SKU(s)';
            body.innerHTML = '<p>Set <strong>' + count + '</strong> SKU(s) to Inactive?</p>' +
                '<p class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>Deactivated SKUs will no longer appear in SKU dropdowns when creating profile lines.</p>';
            btn.textContent = 'Deactivate';
            btn.className = 'btn btn-warning btn-sm';
        }

        new bootstrap.Modal(document.getElementById('bulkActionModal')).show();
    };
})();
</script>
@endpush
