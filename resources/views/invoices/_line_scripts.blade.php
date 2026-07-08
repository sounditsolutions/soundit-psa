{{-- Shared line item JavaScript for invoice create/edit views --}}
{{-- Required variable: $lineIndex (int) - starting index for new lines --}}
{{-- Required variable: $skus (Collection) - available SKUs for dropdown --}}
<script>
let lineIndex = {{ $lineIndex }};

function addLine() {
    const container = document.getElementById('linesContainer');
    const i = lineIndex++;
    const html = `
        <div class="line-item border rounded p-3 mb-3" data-index="${i}">
            <div class="row g-2">
                <div class="col-lg-2 col-md-6">
                    <label class="form-label small">SKU</label>
                    <select class="form-select form-select-sm sku-select"
                            name="lines[${i}][sku_id]"
                            onchange="onSkuSelected(this)">
                        <option value="">-- Manual --</option>
                        @foreach($skus as $s)
                        <option value="{{ $s->id }}"
                            data-price="{{ $s->unit_price }}"
                            data-cost="{{ $s->unit_cost }}"
                            data-taxable="{{ $s->is_taxable ? '1' : '0' }}"
                            data-description="{{ e($s->name) }}">{{ $s->sku_code }} &mdash; {{ $s->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-lg-2 col-md-6">
                    <label class="form-label small">Description</label>
                    <input type="text" class="form-control form-control-sm desc-input"
                           name="lines[${i}][description]" required>
                </div>
                <div class="col-lg-1 col-md-3">
                    <label class="form-label small">Qty</label>
                    <input type="number" class="form-control form-control-sm qty-input"
                           name="lines[${i}][quantity]" step="0.01" min="0"
                           value="1" required oninput="updateSubtotal()">
                </div>
                <div class="col-lg-2 col-md-3">
                    <label class="form-label small">Unit Price</label>
                    <input type="number" class="form-control form-control-sm price-input"
                           name="lines[${i}][unit_price]" step="0.01" min="0"
                           required oninput="updateSubtotal()">
                </div>
                <div class="col-lg-2 col-md-3">
                    <label class="form-label small">Unit Cost</label>
                    <input type="number" class="form-control form-control-sm cost-input"
                           name="lines[${i}][unit_cost]" step="0.01" min="0"
                           placeholder="0.00">
                </div>
                <div class="col-lg-1 col-md-3">
                    <label class="form-label small">Prepaid Min</label>
                    <input type="number" class="form-control form-control-sm"
                           name="lines[${i}][prepaid_time_minutes]" step="1" min="0"
                           placeholder="0">
                </div>
                <div class="col-lg-1 col-md-3 d-flex align-items-end gap-2">
                    <div class="form-check mb-2">
                        <input type="hidden" name="lines[${i}][is_taxable]" value="0">
                        <input type="checkbox" class="form-check-input taxable-check"
                               name="lines[${i}][is_taxable]" value="1" checked>
                        <label class="form-check-label small">Tax</label>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm mb-2" onclick="removeLine(this)"
                            title="Remove line">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>`;
    container.insertAdjacentHTML('beforeend', html);
    const newItem = container.lastElementChild;
    const descInput = newItem.querySelector('.desc-input');
    if (descInput) descInput.focus();
}

function removeLine(btn) {
    const item = btn.closest('.line-item');
    const idInput = item.querySelector('input[name$="[id]"]');

    if (idInput && idInput.value) {
        // Existing line — hide and mark for deletion
        item.style.display = 'none';
        item.querySelectorAll('[required]').forEach(el => el.removeAttribute('required'));
        const name = idInput.name.replace('[id]', '[_delete]');
        const deleteInput = document.createElement('input');
        deleteInput.type = 'hidden';
        deleteInput.name = name;
        deleteInput.value = '1';
        item.appendChild(deleteInput);
    } else {
        // New line — remove from DOM
        item.remove();
    }
    updateSubtotal();
}

function onSkuSelected(select) {
    const opt = select.options[select.selectedIndex];
    if (!opt.value) return;

    const row = select.closest('.row');
    const desc = row.querySelector('.desc-input');
    const price = row.querySelector('.price-input');
    const cost = row.querySelector('.cost-input');
    const taxable = row.querySelector('.taxable-check');

    if (desc && opt.dataset.description) desc.value = opt.dataset.description;
    if (price && opt.dataset.price) price.value = parseFloat(opt.dataset.price).toFixed(2);
    if (cost && opt.dataset.cost) cost.value = parseFloat(opt.dataset.cost).toFixed(2);
    if (taxable) taxable.checked = opt.dataset.taxable === '1';
    updateSubtotal();
}

function updateSubtotal() {
    let subtotal = 0;
    document.querySelectorAll('.line-item').forEach(item => {
        if (item.style.display === 'none') return;
        const qty = parseFloat(item.querySelector('.qty-input')?.value) || 0;
        const price = parseFloat(item.querySelector('.price-input')?.value) || 0;
        subtotal += qty * price;
    });
    const el = document.getElementById('runningSubtotal');
    if (el) el.textContent = '$' + subtotal.toFixed(2);
}
</script>
