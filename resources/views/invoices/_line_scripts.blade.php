{{-- Shared line item JavaScript for invoice create/edit views --}}
{{-- Required variable: $lineIndex (int) - starting index for new lines --}}
{{-- Required variable: $skus (Collection) - available SKUs for dropdown --}}

@php
    // Option list for the client-side "Add Line" builder. A SKU name is
    // vendor-sync-reachable (QBO/Stripe item names land in it unattended, past
    // the staff forms' validation), so it is shipped as data and rendered with
    // textContent — see partials/_select_options_js for why (psa-951q).
    //
    // The four data keys are the dataset property names onSkuSelected() reads
    // back below (opt.dataset.price / .cost / .taxable / .description); each is a
    // single lowercase word, so the dataset<->attribute mapping is an identity
    // and they land as data-price / data-cost / data-taxable / data-description.
    // Renaming one here silently mis-prices a line.
    $skuOptionData = $skus->map(fn ($s) => [
        'value' => (string) $s->id,
        'label' => $s->sku_code.' — '.$s->name,
        'data' => [
            'price' => (string) $s->unit_price,
            'cost' => (string) $s->unit_cost,
            'taxable' => $s->is_taxable ? '1' : '0',
            'description' => (string) $s->name,
        ],
    ])->values();
@endphp

@include('partials._select_options_js')

<script>
let lineIndex = {{ $lineIndex }};

// psa-951q: this option list is inert DATA, never JavaScript source. SKU names
// are vendor-sync-reachable, not merely operator-entered, and the backtick
// template literal below is a JavaScript string context that Blade's HTML
// escaping does not cover — a name containing a backtick closed the literal,
// and one containing ${...} was evaluated in the staff browser. See
// partials/_select_options_js for the full story and the exact write paths.
//
// The json directive below is given a BARE VARIABLE, shaped in the PHP block
// above. It must never be given an inline expression containing a comma: the
// directive explode()s its argument on ',' (CompilesJson::compileJson), so a
// re-inlined two-element ['value' => .., 'label' => ..] map — one comma —
// compiles to json_encode($x, 512) instead of json_encode($x, 15, 512). That is
// VALID PHP which renders fine and passes tests, with JSON_HEX_TAG silently
// dropped and < / > left raw. Only 3+ commas fail loudly, so re-inlining this
// would NOT announce itself. See partials/_select_options_js and psa-28hr.
const SKU_OPTIONS = @json($skuOptionData);

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

    // The markup above is static, developer-authored HTML. The options are the
    // untrusted, vendor-sync-reachable part, so they are built from data instead
    // — never spliced into a string that JavaScript then has to parse.
    //
    // The '-- Manual --' placeholder is ALSO in the static markup above. It is a
    // developer constant with no XSS exposure, so it costs nothing to ship it
    // server-side, and it means a JS failure here degrades the row to a LABELLED
    // empty select rather than a blank one. fillSelectOptions replaceChildren()s
    // it away and re-adds it, so the success path is unchanged. Keep the two
    // spellings identical. Only the untrusted SKU labels must stay in data.
    fillSelectOptions(newItem.querySelector('.sku-select'), SKU_OPTIONS, '-- Manual --');

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
