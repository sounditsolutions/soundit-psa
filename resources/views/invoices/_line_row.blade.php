<div class="line-item border rounded p-3 mb-3" data-index="{{ $i }}">
    <input type="hidden" name="lines[{{ $i }}][id]" value="{{ $line->id }}">
    <div class="row g-2">
        <div class="col-lg-2 col-md-6">
            <label class="form-label small">SKU</label>
            <select class="form-select form-select-sm sku-select"
                    name="lines[{{ $i }}][sku_id]"
                    onchange="onSkuSelected(this)">
                <option value="">-- Manual --</option>
                @foreach($skus as $s)
                    <option value="{{ $s->id }}"
                        data-price="{{ $s->unit_price }}"
                        data-cost="{{ $s->unit_cost }}"
                        data-taxable="{{ $s->is_taxable ? '1' : '0' }}"
                        data-description="{{ e($s->name) }}"
                        {{ old("lines.{$i}.sku_id", $line->sku_id) == $s->id ? 'selected' : '' }}>{{ $s->sku_code }} &mdash; {{ $s->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-lg-2 col-md-6">
            <label class="form-label small">Description</label>
            <input type="text" class="form-control form-control-sm desc-input"
                   name="lines[{{ $i }}][description]"
                   value="{{ old("lines.{$i}.description", $line->description) }}" required>
        </div>
        <div class="col-lg-1 col-md-3">
            <label class="form-label small">Qty</label>
            <input type="number" class="form-control form-control-sm qty-input"
                   name="lines[{{ $i }}][quantity]" step="0.01" min="0"
                   value="{{ old("lines.{$i}.quantity", rtrim(rtrim(number_format($line->quantity, 2), '0'), '.')) }}"
                   required oninput="updateSubtotal()">
        </div>
        <div class="col-lg-2 col-md-3">
            <label class="form-label small">Unit Price</label>
            <input type="number" class="form-control form-control-sm price-input"
                   name="lines[{{ $i }}][unit_price]" step="0.01" min="0"
                   value="{{ old("lines.{$i}.unit_price", $line->unit_price) }}"
                   required oninput="updateSubtotal()">
        </div>
        <div class="col-lg-2 col-md-3">
            <label class="form-label small">Unit Cost</label>
            <input type="number" class="form-control form-control-sm cost-input"
                   name="lines[{{ $i }}][unit_cost]" step="0.01" min="0"
                   value="{{ old("lines.{$i}.unit_cost", $line->unit_cost) }}"
                   placeholder="0.00">
        </div>
        <div class="col-lg-1 col-md-3">
            <label class="form-label small">Prepaid Min</label>
            <input type="number" class="form-control form-control-sm"
                   name="lines[{{ $i }}][prepaid_time_minutes]" step="1" min="0"
                   value="{{ old("lines.{$i}.prepaid_time_minutes", $line->prepaid_time_minutes) }}"
                   placeholder="0">
        </div>
        <div class="col-lg-1 col-md-3 d-flex align-items-end gap-2">
            <div class="form-check mb-2">
                <input type="hidden" name="lines[{{ $i }}][is_taxable]" value="0">
                <input type="checkbox" class="form-check-input taxable-check"
                       name="lines[{{ $i }}][is_taxable]" value="1"
                       {{ old("lines.{$i}.is_taxable", $line->is_taxable) ? 'checked' : '' }}>
                <label class="form-check-label small">Tax</label>
            </div>
            <button type="button" class="btn btn-outline-danger btn-sm mb-2" onclick="removeLine(this)"
                    title="Remove line">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
    @if($line->quantity_source)
        <div class="mt-1">
            <small class="text-muted">{{ $line->quantity_source }}</small>
        </div>
    @endif
</div>
