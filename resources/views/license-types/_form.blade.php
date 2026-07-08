<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label for="name" class="form-label">Name</label>
        <input type="text" class="form-control @error('name') is-invalid @enderror"
               id="name" name="name" value="{{ old('name', $licenseType?->name) }}" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label for="vendor" class="form-label">Vendor</label>
        <input type="text" class="form-control @error('vendor') is-invalid @enderror"
               id="vendor" name="vendor" value="{{ old('vendor', $licenseType?->vendor ?? 'manual') }}"
               required maxlength="50" placeholder="e.g. manual, mesh, cipp_m365">
        @error('vendor')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-3">
        <label for="vendor_sku_id" class="form-label">Vendor SKU ID</label>
        <input type="text" class="form-control" id="vendor_sku_id" name="vendor_sku_id"
               value="{{ old('vendor_sku_id', $licenseType?->vendor_sku_id) }}" placeholder="Optional">
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label for="sku_id" class="form-label">Linked SKU (for billing/cost)</label>
        <select class="form-select" id="sku_id" name="sku_id">
            <option value="">-- None --</option>
            @foreach($skus as $s)
                <option value="{{ $s->id }}" {{ old('sku_id', $licenseType?->sku_id) == $s->id ? 'selected' : '' }}>
                    {{ $s->sku_code }} — {{ $s->name }} (${{ number_format($s->unit_cost, 2) }} cost)
                </option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <label for="default_unit_cost" class="form-label">Unit Cost ($)</label>
        <input type="number" class="form-control" id="default_unit_cost" name="default_unit_cost"
               value="{{ old('default_unit_cost', $licenseType?->default_unit_cost) }}"
               step="0.0001" min="0" placeholder="Cost">
    </div>
    <div class="col-md-2">
        <label for="cost_divisor" class="form-label" title="Divide unit cost by this number. E.g., $6/TB with divisor 1024 when quantity is in GB.">
            Cost Divisor <i class="bi bi-question-circle text-muted" style="font-size: 0.8rem;"></i>
        </label>
        <input type="number" class="form-control" id="cost_divisor" name="cost_divisor"
               value="{{ old('cost_divisor', $licenseType?->cost_divisor) }}"
               step="1" min="1" placeholder="1">
    </div>
    <div class="col-md-2 d-flex align-items-end pb-1">
        <div class="form-check">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
                   {{ old('is_active', $licenseType?->is_active ?? true) ? 'checked' : '' }}>
            <label class="form-check-label" for="is_active">Active</label>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-3">
        <label for="minimum_quantity" class="form-label" title="Vendor bills for at least this many units. Effective qty = max(actual, minimum).">
            Min Quantity <i class="bi bi-question-circle text-muted" style="font-size: 0.8rem;"></i>
        </label>
        <input type="number" class="form-control" id="minimum_quantity" name="minimum_quantity"
               value="{{ old('minimum_quantity', $licenseType?->minimum_quantity) }}"
               step="1" min="1" placeholder="No minimum">
    </div>
    <div class="col-md-3">
        <label for="minimum_cost" class="form-label" title="Vendor charges at least this dollar amount. Effective cost = max(qty × unit cost, minimum).">
            Min Cost ($) <i class="bi bi-question-circle text-muted" style="font-size: 0.8rem;"></i>
        </label>
        <input type="number" class="form-control" id="minimum_cost" name="minimum_cost"
               value="{{ old('minimum_cost', $licenseType?->minimum_cost) }}"
               step="0.01" min="0" placeholder="No minimum">
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-12">
        <label for="notes" class="form-label">Notes</label>
        <textarea class="form-control" id="notes" name="notes" rows="3"
                  placeholder="Vendor pricing details, contract terms, etc.">{{ old('notes', $licenseType?->notes) }}</textarea>
    </div>
</div>
