<div class="row g-3 mb-3">
    <div class="col-md-8">
        <label for="name" class="form-label">Name</label>
        <input type="text" class="form-control @error('name') is-invalid @enderror"
               id="name" name="name" value="{{ old('name', $sku?->name) }}" required>
        @error('name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-4">
        <label for="sku_code" class="form-label">SKU Code</label>
        <input type="text" class="form-control @error('sku_code') is-invalid @enderror"
               id="sku_code" name="sku_code" value="{{ old('sku_code', $sku?->sku_code) }}" required
               maxlength="50" style="text-transform: uppercase;">
        @error('sku_code')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="mb-3">
    <label for="description" class="form-label">Description</label>
    <textarea class="form-control @error('description') is-invalid @enderror"
              id="description" name="description" rows="2">{{ old('description', $sku?->description) }}</textarea>
    @error('description')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <label for="unit_price" class="form-label">Unit Price ($)</label>
        <input type="number" class="form-control @error('unit_price') is-invalid @enderror"
               id="unit_price" name="unit_price" value="{{ old('unit_price', $sku?->unit_price ?? '0.00') }}"
               step="0.01" min="0" required>
        @error('unit_price')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-3">
        <label for="unit_cost" class="form-label">Unit Cost ($)</label>
        <input type="number" class="form-control @error('unit_cost') is-invalid @enderror"
               id="unit_cost" name="unit_cost" value="{{ old('unit_cost', $sku?->unit_cost ?? '0.00') }}"
               step="0.01" min="0" required>
        @error('unit_cost')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-3">
        <label for="category" class="form-label">Category</label>
        <input type="text" class="form-control @error('category') is-invalid @enderror"
               id="category" name="category" value="{{ old('category', $sku?->category) }}"
               maxlength="50" placeholder="e.g. Managed, Security">
        @error('category')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-3 d-flex align-items-end gap-3 pb-1">
        <div class="form-check">
            <input type="hidden" name="is_taxable" value="0">
            <input type="checkbox" class="form-check-input" id="is_taxable" name="is_taxable" value="1"
                   {{ old('is_taxable', $sku?->is_taxable ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_taxable">Taxable</label>
        </div>
        <div class="form-check">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
                   {{ old('is_active', $sku?->is_active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <label for="prepaid_time_minutes" class="form-label">
            Prepaid Time (min)
            <i class="bi bi-question-circle text-muted ms-1" data-bs-toggle="tooltip" data-bs-placement="top"
               title="Per-unit prepaid time included when this SKU is invoiced. When the invoice is marked Paid, these minutes are deposited into the contract's prepay balance. Leave blank for non-prepay SKUs."></i>
        </label>
        <input type="number" class="form-control @error('prepaid_time_minutes') is-invalid @enderror"
               id="prepaid_time_minutes" name="prepaid_time_minutes"
               value="{{ old('prepaid_time_minutes', $sku?->prepaid_time_minutes) }}"
               step="1" min="0" placeholder="e.g. 30">
        @error('prepaid_time_minutes')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-3">
        <label for="default_quantity_type" class="form-label">
            Default Qty Type
            <i class="bi bi-question-circle text-muted ms-1" data-bs-toggle="tooltip" data-bs-placement="top"
               title="When this SKU is selected on a recurring profile line, the quantity type will auto-fill to this value. Controls how the line quantity is calculated: Fixed (manual), Per Workstation/Server/User (counted from contract assignments), Per License Type (counted from a specific license), or Overage (usage-based with included allowance)."></i>
        </label>
        <select class="form-select @error('default_quantity_type') is-invalid @enderror"
                id="default_quantity_type" name="default_quantity_type" onchange="toggleSkuQtyTypeFields()">
            <option value="">None</option>
            @foreach(\App\Enums\QuantityType::cases() as $qt)
                <option value="{{ $qt->value }}"
                    {{ old('default_quantity_type', $sku?->default_quantity_type?->value) === $qt->value ? 'selected' : '' }}>
                    {{ $qt->label() }}
                </option>
            @endforeach
        </select>
        @error('default_quantity_type')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-3" id="skuLicenseTypeCol"
         style="{{ in_array(old('default_quantity_type', $sku?->default_quantity_type?->value), ['per_license_type', 'per_reseller_license_type', 'overage']) ? '' : 'display:none' }}">
        <label for="default_license_type_id" class="form-label">
            Default License Type
            <i class="bi bi-question-circle text-muted ms-1" data-bs-toggle="tooltip" data-bs-placement="top"
               id="licenseTypeTooltip"
               title="{{ in_array(old('default_quantity_type', $sku?->default_quantity_type?->value), ['overage']) ? 'Auto-fills the &quot;Usage License Type&quot; field on profile lines. This is the license whose count is measured for overage calculation.' : 'Auto-fills the license type on profile lines. The billing quantity will equal the count of this license type assigned to the contract.' }}"></i>
        </label>
        <select class="form-select @error('default_license_type_id') is-invalid @enderror"
                id="default_license_type_id" name="default_license_type_id">
            <option value="">None</option>
            @foreach($licenseTypes as $lt)
                <option value="{{ $lt->id }}"
                    {{ old('default_license_type_id', $sku?->default_license_type_id) == $lt->id ? 'selected' : '' }}>
                    {{ $lt->name }}
                </option>
            @endforeach
        </select>
        @error('default_license_type_id')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-3" id="skuIncludedPerUnitCol"
         style="{{ old('default_quantity_type', $sku?->default_quantity_type?->value) === 'overage' ? '' : 'display:none' }}">
        <label for="included_per_unit" class="form-label">
            Included per Unit
            <i class="bi bi-question-circle text-muted ms-1" data-bs-toggle="tooltip" data-bs-placement="top"
               title="Auto-fills the &quot;Included per Base&quot; field on overage profile lines. This is how many units of usage are included per base unit before overage charges apply. Example: 1024 means 1024 GB of backup included per device."></i>
        </label>
        <input type="number" class="form-control @error('included_per_unit') is-invalid @enderror"
               id="included_per_unit" name="included_per_unit"
               value="{{ old('included_per_unit', $sku?->included_per_unit) }}"
               step="1" min="0" placeholder="e.g. 1024">
        @error('included_per_unit')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

@if(!empty($qboIncomeAccounts ?? []) || !empty($qboExpenseAccounts ?? []))
<div class="row g-3 mb-3">
    <div class="col-12">
        <h6 class="text-muted mb-2"><i class="bi bi-receipt me-1"></i>QuickBooks Online (per-SKU override)</h6>
        <p class="form-text mb-2">
            Optional. Overrides the default income/expense accounts configured under Settings → Integrations → QuickBooks Online.
        </p>
    </div>
    <div class="col-md-6">
        <label for="qbo_income_account_id" class="form-label">Income Account</label>
        <select class="form-select" id="qbo_income_account_id" name="qbo_income_account_id">
            <option value="">(Use default)</option>
            @foreach(($qboIncomeAccounts ?? []) as $acct)
                <option value="{{ $acct['Id'] }}" {{ old('qbo_income_account_id', $sku?->qbo_income_account_id) === $acct['Id'] ? 'selected' : '' }}>
                    {{ $acct['Name'] }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label for="qbo_expense_account_id" class="form-label">Expense Account</label>
        <select class="form-select" id="qbo_expense_account_id" name="qbo_expense_account_id">
            <option value="">(Use default)</option>
            @foreach(($qboExpenseAccounts ?? []) as $acct)
                <option value="{{ $acct['Id'] }}" {{ old('qbo_expense_account_id', $sku?->qbo_expense_account_id) === $acct['Id'] ? 'selected' : '' }}>
                    {{ $acct['Name'] }} ({{ $acct['AccountType'] }})
                </option>
            @endforeach
        </select>
    </div>
</div>
@endif

@push('scripts')
<script>
function toggleSkuQtyTypeFields() {
    var sel = document.getElementById('default_quantity_type');
    var val = sel.value;
    var licenseCol = document.getElementById('skuLicenseTypeCol');
    var includedCol = document.getElementById('skuIncludedPerUnitCol');
    var tooltipIcon = document.getElementById('licenseTypeTooltip');

    // License type: show for per_license_type, per_reseller_license_type, and overage
    licenseCol.style.display = (val === 'per_license_type' || val === 'per_reseller_license_type' || val === 'overage') ? '' : 'none';

    // Included per unit: only for overage
    includedCol.style.display = val === 'overage' ? '' : 'none';

    // Update tooltip text based on context
    if (tooltipIcon) {
        var existing = bootstrap.Tooltip.getInstance(tooltipIcon);
        if (existing) existing.dispose();
        tooltipIcon.setAttribute('title', val === 'overage'
            ? 'Auto-fills the "Usage License Type" field on profile lines. This is the license whose count is measured for overage calculation.'
            : 'Auto-fills the license type on profile lines. The billing quantity will equal the count of this license type assigned to the contract.');
        new bootstrap.Tooltip(tooltipIcon);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function(el) {
        new bootstrap.Tooltip(el);
    });
    toggleSkuQtyTypeFields();
});
</script>
@endpush
