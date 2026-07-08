@extends('layouts.app')

@section('title', 'New Profile')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('contracts.show', $contract) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to {{ $contract->name }}
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title">New Recurring Profile</h4>
        <p class="text-muted mb-0">{{ $contract->name }} &mdash; {{ $contract->client->name }}</p>
    </div>
</div>

<div class="row">
    <div class="col-lg-10">
        <form method="POST" action="{{ route('profiles.store', $contract) }}" id="profileForm">
            @csrf

            <div class="card shadow-sm mb-4">
                <div class="card-header"><i class="bi bi-gear me-2"></i>Profile Settings</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label">Profile Name</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="name" name="name" value="{{ old('name') }}"
                                   placeholder="e.g., Monthly Managed Services" required>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-md-12">
                            <label for="notes" class="form-label">Notes</label>
                            <textarea class="form-control @error('notes') is-invalid @enderror"
                                      id="notes" name="notes" rows="2"
                                      placeholder="Special billing instructions, service details...">{{ old('notes') }}</textarea>
                            @error('notes')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <label for="next_run_date" class="form-label">Next Run Date</label>
                            <input type="date" class="form-control @error('next_run_date') is-invalid @enderror"
                                   id="next_run_date" name="next_run_date"
                                   value="{{ old('next_run_date', $defaultNextRun) }}" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label d-block">&nbsp;</label>
                            <div class="form-check mt-2">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active"
                                       value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label for="skip_zero_invoices" class="form-label">Skip Empty Invoices</label>
                            <select class="form-select @error('skip_zero_invoices') is-invalid @enderror"
                                    id="skip_zero_invoices" name="skip_zero_invoices">
                                <option value="" {{ old('skip_zero_invoices') === null || old('skip_zero_invoices') === '' ? 'selected' : '' }}>Use global default</option>
                                <option value="1" {{ old('skip_zero_invoices') === '1' ? 'selected' : '' }}>Always skip</option>
                                <option value="0" {{ old('skip_zero_invoices') === '0' ? 'selected' : '' }}>Never skip</option>
                            </select>
                            <div class="form-text" id="skip-zero-help">
                                @php $globalSkip = \App\Models\Setting::getValue('billing_skip_zero_invoices'); @endphp
                                <span data-skip-msg="default">Global default: empty invoices will {{ $globalSkip ? 'be skipped' : 'still generate' }}.</span>
                                <span data-skip-msg="1" style="display:none">Empty invoices will <strong>always be skipped</strong> for this profile.</span>
                                <span data-skip-msg="0" style="display:none">Empty invoices will <strong>always generate</strong> for this profile.</span>
                            </div>
                            <script>
                                document.getElementById('skip_zero_invoices').addEventListener('change', function() {
                                    document.querySelectorAll('#skip-zero-help [data-skip-msg]').forEach(el => el.style.display = 'none');
                                    const key = this.value === '' ? 'default' : this.value;
                                    document.querySelector('#skip-zero-help [data-skip-msg="' + key + '"]').style.display = '';
                                });
                                document.getElementById('skip_zero_invoices').dispatchEvent(new Event('change'));
                            </script>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <label for="billing_period" class="form-label">Billing Period</label>
                            <select class="form-select @error('billing_period') is-invalid @enderror"
                                    id="billing_period" name="billing_period" required>
                                @foreach($billingPeriods as $bp)
                                    <option value="{{ $bp->value }}"
                                        {{ old('billing_period', $defaultBillingPeriod) === $bp->value ? 'selected' : '' }}>
                                        {{ $bp->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="billing_day" class="form-label">Billing Day</label>
                            <input type="number" class="form-control @error('billing_day') is-invalid @enderror"
                                   id="billing_day" name="billing_day"
                                   value="{{ old('billing_day', $defaultBillingDay) }}"
                                   min="1" max="28" required>
                            <div class="form-text">Day of month when invoices generate.</div>
                        </div>
                        <div class="col-md-4">
                            <label for="payment_terms_days" class="form-label">Payment Terms (days)</label>
                            <input type="number" class="form-control @error('payment_terms_days') is-invalid @enderror"
                                   id="payment_terms_days" name="payment_terms_days"
                                   value="{{ old('payment_terms_days', $defaultPaymentTermsDays) }}"
                                   min="0" max="365" required>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-list-ol me-2"></i>Line Items</div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="addLine()">
                        <i class="bi bi-plus-lg me-1"></i>Add Line
                    </button>
                </div>
                <div class="card-body">
                    <div id="linesContainer">
                        <div class="line-item border rounded p-3 mb-3" data-index="0">
                            <div class="row g-2">
                                <div class="col-md-3">
                                    <label class="form-label small">SKU</label>
                                    <select class="form-select form-select-sm sku-select"
                                            name="lines[0][sku_id]" onchange="onSkuSelected(this)">
                                        <option value="">-- Manual --</option>
                                        @foreach($skus as $s)
                                            <option value="{{ $s->id }}"
                                                data-price="{{ $s->unit_price }}"
                                                data-cost="{{ $s->unit_cost }}"
                                                data-taxable="{{ $s->is_taxable ? '1' : '0' }}"
                                                data-description="{{ $s->name }}"
                                                data-included-per-unit="{{ $s->included_per_unit }}"
                                                data-default-quantity-type="{{ $s->default_quantity_type?->value }}"
                                                data-default-license-type-id="{{ $s->default_license_type_id }}">
                                                {{ $s->sku_code }} — {{ $s->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small">Description</label>
                                    <input type="text" class="form-control form-control-sm desc-input"
                                           name="lines[0][description]" required>
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small">Price</label>
                                    <input type="number" class="form-control form-control-sm price-input"
                                           name="lines[0][unit_price]" step="0.01" min="0" required>
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small">Cost</label>
                                    <input type="number" class="form-control form-control-sm"
                                           name="lines[0][unit_cost_override]" step="0.01" min="0"
                                           placeholder="SKU" title="Cost override (blank = use SKU cost)">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small">Prepay</label>
                                    <input type="number" class="form-control form-control-sm"
                                           name="lines[0][prepaid_time_override]" step="1" min="0"
                                           placeholder="SKU" title="Prepaid time override in minutes (blank = use SKU default)">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small">Quantity Type</label>
                                    <select class="form-select form-select-sm qty-type-select"
                                            name="lines[0][quantity_type]" onchange="toggleConditionalFields(this)">
                                        @foreach($quantityTypes as $qt)
                                            <option value="{{ $qt->value }}">{{ $qt->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-1 fixed-qty-col">
                                    <label class="form-label small">Qty</label>
                                    <input type="number" class="form-control form-control-sm"
                                           name="lines[0][fixed_quantity]" value="1" step="0.01" min="0">
                                </div>
                                <div class="col-md-2 license-type-col" style="display:none">
                                    <label class="form-label small">License Type</label>
                                    <select class="form-select form-select-sm" name="lines[0][license_type_id]">
                                        <option value="">Select...</option>
                                        @foreach($licenseTypes as $lt)
                                            <option value="{{ $lt->id }}">{{ $lt->name }} ({{ $lt->vendor }})</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-1 d-flex align-items-end gap-2">
                                    <div class="form-check mb-2">
                                        <input type="hidden" name="lines[0][is_taxable]" value="0">
                                        <input type="checkbox" class="form-check-input taxable-check"
                                               name="lines[0][is_taxable]" value="1" checked>
                                        <label class="form-check-label small">Tax</label>
                                    </div>
                                </div>
                            </div>
                            <div class="overage-config-panel mt-2 p-2 border rounded bg-light" style="display:none">
                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <label class="form-label small">Usage License Type</label>
                                        <select class="form-select form-select-sm" name="lines[0][usage_license_type_id]">
                                            <option value="">Select...</option>
                                            @foreach($licenseTypes as $lt)
                                                <option value="{{ $lt->id }}">{{ $lt->name }} ({{ $lt->vendor }})</option>
                                            @endforeach
                                        </select>
                                        <div class="form-text">What to measure</div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">Base License Type</label>
                                        <select class="form-select form-select-sm" name="lines[0][base_license_type_id]">
                                            <option value="">(none — use 1)</option>
                                            @foreach($licenseTypes as $lt)
                                                <option value="{{ $lt->id }}">{{ $lt->name }} ({{ $lt->vendor }})</option>
                                            @endforeach
                                        </select>
                                        <div class="form-text">What provides included amount</div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">Included per Base</label>
                                        <input type="number" class="form-control form-control-sm included-per-base-input"
                                               name="lines[0][included_per_base_unit]" min="0" step="1" placeholder="e.g. 1024">
                                        <div class="form-text">Units included per base unit</div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">Overage Divisor</label>
                                        <input type="number" class="form-control form-control-sm"
                                               name="lines[0][overage_divisor]" min="1" step="1" value="1">
                                        <div class="form-text">Convert raw overage to billing units</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">Create Profile</button>
                <a href="{{ route('contracts.show', $contract) }}" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
let lineIndex = 1;

function addLine() {
    const container = document.getElementById('linesContainer');
    const quantityOptions = `@foreach($quantityTypes as $qt)<option value="{{ $qt->value }}">{{ $qt->label() }}</option>@endforeach`;
    const skuOptions = `<option value="">-- Manual --</option>@foreach($skus as $s)<option value="{{ $s->id }}" data-price="{{ $s->unit_price }}" data-cost="{{ $s->unit_cost }}" data-taxable="{{ $s->is_taxable ? '1' : '0' }}" data-description="{{ $s->name }}" data-included-per-unit="{{ $s->included_per_unit }}" data-default-quantity-type="{{ $s->default_quantity_type?->value }}" data-default-license-type-id="{{ $s->default_license_type_id }}">{{ $s->sku_code }} — {{ $s->name }}</option>@endforeach`;
    const licenseTypeOptions = `<option value="">Select...</option>@foreach($licenseTypes as $lt)<option value="{{ $lt->id }}">{{ $lt->name }} ({{ $lt->vendor }})</option>@endforeach`;
    const licenseTypeOptionsWithNone = `<option value="">(none — use 1)</option>@foreach($licenseTypes as $lt)<option value="{{ $lt->id }}">{{ $lt->name }} ({{ $lt->vendor }})</option>@endforeach`;

    const html = `
        <div class="line-item border rounded p-3 mb-3" data-index="${lineIndex}">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small">SKU</label>
                    <select class="form-select form-select-sm sku-select"
                            name="lines[${lineIndex}][sku_id]" onchange="onSkuSelected(this)">
                        ${skuOptions}
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Description</label>
                    <input type="text" class="form-control form-control-sm desc-input"
                           name="lines[${lineIndex}][description]" required>
                </div>
                <div class="col-md-1">
                    <label class="form-label small">Price</label>
                    <input type="number" class="form-control form-control-sm price-input"
                           name="lines[${lineIndex}][unit_price]" step="0.01" min="0" required>
                </div>
                <div class="col-md-1">
                    <label class="form-label small">Cost</label>
                    <input type="number" class="form-control form-control-sm"
                           name="lines[${lineIndex}][unit_cost_override]" step="0.01" min="0"
                           placeholder="SKU" title="Cost override (blank = use SKU cost)">
                </div>
                <div class="col-md-1">
                    <label class="form-label small">Prepay</label>
                    <input type="number" class="form-control form-control-sm"
                           name="lines[${lineIndex}][prepaid_time_override]" step="1" min="0"
                           placeholder="SKU" title="Prepaid time override in minutes (blank = use SKU default)">
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Quantity Type</label>
                    <select class="form-select form-select-sm qty-type-select"
                            name="lines[${lineIndex}][quantity_type]" onchange="toggleConditionalFields(this)">
                        ${quantityOptions}
                    </select>
                </div>
                <div class="col-md-1 fixed-qty-col">
                    <label class="form-label small">Qty</label>
                    <input type="number" class="form-control form-control-sm"
                           name="lines[${lineIndex}][fixed_quantity]" value="1" step="0.01" min="0">
                </div>
                <div class="col-md-2 license-type-col" style="display:none">
                    <label class="form-label small">License Type</label>
                    <select class="form-select form-select-sm" name="lines[${lineIndex}][license_type_id]">
                        ${licenseTypeOptions}
                    </select>
                </div>
                <div class="col-md-1 d-flex align-items-end gap-2">
                    <div class="form-check mb-2">
                        <input type="hidden" name="lines[${lineIndex}][is_taxable]" value="0">
                        <input type="checkbox" class="form-check-input taxable-check"
                               name="lines[${lineIndex}][is_taxable]" value="1" checked>
                        <label class="form-check-label small">Tax</label>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm mb-2" onclick="removeLine(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            <div class="overage-config-panel mt-2 p-2 border rounded bg-light" style="display:none">
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small">Usage License Type</label>
                        <select class="form-select form-select-sm" name="lines[${lineIndex}][usage_license_type_id]">
                            ${licenseTypeOptions}
                        </select>
                        <div class="form-text">What to measure</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Base License Type</label>
                        <select class="form-select form-select-sm" name="lines[${lineIndex}][base_license_type_id]">
                            ${licenseTypeOptionsWithNone}
                        </select>
                        <div class="form-text">What provides included amount</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Included per Base</label>
                        <input type="number" class="form-control form-control-sm included-per-base-input"
                               name="lines[${lineIndex}][included_per_base_unit]" min="0" step="1" placeholder="e.g. 1024">
                        <div class="form-text">Units included per base unit</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Overage Divisor</label>
                        <input type="number" class="form-control form-control-sm"
                               name="lines[${lineIndex}][overage_divisor]" min="1" step="1" value="1">
                        <div class="form-text">Convert raw overage to billing units</div>
                    </div>
                </div>
            </div>
        </div>`;

    container.insertAdjacentHTML('beforeend', html);
    lineIndex++;
}

function removeLine(btn) {
    const lines = document.querySelectorAll('.line-item');
    if (lines.length <= 1) return;
    btn.closest('.line-item').remove();
}

function onSkuSelected(select) {
    const opt = select.options[select.selectedIndex];
    if (!opt.value) return;

    const lineItem = select.closest('.line-item');
    const row = select.closest('.row');
    const desc = row.querySelector('.desc-input');
    const price = row.querySelector('.price-input');
    const taxable = row.querySelector('.taxable-check');

    if (desc && opt.dataset.description) desc.value = opt.dataset.description;
    if (price && opt.dataset.price) price.value = parseFloat(opt.dataset.price).toFixed(2);
    if (taxable) taxable.checked = opt.dataset.taxable === '1';

    // Auto-fill included_per_base_unit from SKU's included_per_unit
    const includedInput = lineItem.querySelector('.included-per-base-input');
    if (includedInput && opt.dataset.includedPerUnit) {
        includedInput.value = opt.dataset.includedPerUnit;
    }

    // Auto-fill quantity type from SKU default
    const qtyTypeSelect = lineItem.querySelector('.qty-type-select');
    if (qtyTypeSelect && opt.dataset.defaultQuantityType) {
        qtyTypeSelect.value = opt.dataset.defaultQuantityType;
        toggleConditionalFields(qtyTypeSelect);
    }

    // Auto-fill license type from SKU default
    if (opt.dataset.defaultLicenseTypeId) {
        const qtyType = opt.dataset.defaultQuantityType;
        if (qtyType === 'per_license_type' || qtyType === 'per_reseller_license_type') {
            const ltSelect = lineItem.querySelector('[name$="[license_type_id]"]');
            if (ltSelect) ltSelect.value = opt.dataset.defaultLicenseTypeId;
        } else if (qtyType === 'overage') {
            const usageLtSelect = lineItem.querySelector('[name$="[usage_license_type_id]"]');
            if (usageLtSelect) usageLtSelect.value = opt.dataset.defaultLicenseTypeId;
        }
    }
}

function toggleConditionalFields(select) {
    const lineItem = select.closest('.line-item');
    const row = select.closest('.row');
    const fixedCol = row.querySelector('.fixed-qty-col');
    const licenseCol = row.querySelector('.license-type-col');
    const overagePanel = lineItem.querySelector('.overage-config-panel');

    if (fixedCol) fixedCol.style.display = select.value === 'fixed' ? '' : 'none';
    if (licenseCol) licenseCol.style.display = (select.value === 'per_license_type' || select.value === 'per_reseller_license_type') ? '' : 'none';
    if (overagePanel) overagePanel.style.display = select.value === 'overage' ? '' : 'none';
}

// Initialize visibility on page load
document.querySelectorAll('.qty-type-select').forEach(toggleConditionalFields);
</script>
@endpush
