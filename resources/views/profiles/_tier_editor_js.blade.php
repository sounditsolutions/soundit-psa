{{--
    Shared graduated-tier editor behaviour for the recurring-profile line forms
    (profiles/create.blade.php and profiles/show.blade.php).

    psa-x47y: these seven functions were duplicated VERBATIM between the two views.
    That is a billing-trust hazard, not a cosmetic one — updatePricingMethodNote below
    is what tells an operator, at the moment they flip the graduated toggle, that this
    line's bands will OVERRIDE the volume rate card on its SKU. Charlie's ruling
    (authenticated, 2026-07-13) is that the override is ALLOWED but must never be
    SILENT, and the product lane approved the recut specifically because the applied
    method is visible where the decision is made. Two copies meant two places for that
    note to drift, and a drift on either one silently reintroduces the
    invisible-to-the-operator defect the product lane originally rejected.

    Guarded by TieredPricingOverrideTest::test_both_profile_forms_ship_the_same_tier_editor_behaviour,
    which asserts this surface renders on BOTH screens.

    The MARKUP is deliberately NOT shared: create/ builds its tier rows from a
    client-side JS template (the addLine() row index) while show/ renders them
    server-side from a Blade loop ({{ $i }}). Those are genuinely different rendering
    modes, so only the BEHAVIOUR is single-sourced here. The server-side counterpart of this predicate is
    App\Support\PricingModelOverride, which is already single-sourced across the
    service, views and controller.

    REQUIRES of the including page: a .line-item wrapper per line carrying
    data-index, and within it .sku-select, .qty-type-select, .tiered-toggle,
    .price-input, .pricing-method-note and a .tier-config-panel holding .tier-rows
    plus an .add-tier-btn.
--}}
<script>
function tierRowHtml(lineIdx, tierIdx) {
    return `
        <div class="row g-2 tier-row mb-1 align-items-end">
            <div class="col-md-4">
                <label class="form-label small">Up to (qty)</label>
                <input type="number" class="form-control form-control-sm tier-upto"
                       name="lines[${lineIdx}][pricing_tiers][${tierIdx}][up_to]" min="1" step="1" placeholder="unlimited">
            </div>
            <div class="col-md-4">
                <label class="form-label small">Unit price</label>
                <input type="number" class="form-control form-control-sm tier-price"
                       name="lines[${lineIdx}][pricing_tiers][${tierIdx}][unit_price]" min="0" step="0.01" oninput="syncTierBasePrice(this)">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeTier(this)">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>`;
}

function addTier(el) {
    const panel = el.closest('.tier-config-panel');
    const lineItem = panel.closest('.line-item');
    const rows = panel.querySelector('.tier-rows');
    const seq = parseInt(panel.dataset.tierSeq || '0', 10);
    panel.dataset.tierSeq = (seq + 1).toString();
    rows.insertAdjacentHTML('beforeend', tierRowHtml(lineItem.dataset.index, seq));
}

function removeTier(btn) {
    const lineItem = btn.closest('.line-item');
    btn.closest('.tier-row').remove();
    syncTierBasePriceFromLine(lineItem);
}

function toggleTiered(checkbox) {
    const lineItem = checkbox.closest('.line-item');
    const panel = lineItem.querySelector('.tier-config-panel');
    const priceInput = lineItem.querySelector('.price-input');

    if (checkbox.checked) {
        panel.style.display = '';
        if (!panel.querySelector('.tier-row')) addTier(panel.querySelector('.add-tier-btn'));
        if (priceInput) { priceInput.readOnly = true; priceInput.classList.add('bg-light'); priceInput.title = 'Set by the first tier below'; }
        syncTierBasePriceFromLine(lineItem);
    } else {
        panel.style.display = 'none';
        panel.querySelectorAll('.tier-row').forEach(r => r.remove());
        if (priceInput) { priceInput.readOnly = false; priceInput.classList.remove('bg-light'); priceInput.title = ''; }
    }
}

// The stored/flat unit price mirrors the first tier so it stays a sensible base
// and satisfies the required unit_price validation.
function syncTierBasePrice(input) {
    syncTierBasePriceFromLine(input.closest('.line-item'));
}

function syncTierBasePriceFromLine(lineItem) {
    const firstTier = lineItem.querySelector('.tier-config-panel .tier-price');
    const priceInput = lineItem.querySelector('.price-input');
    if (firstTier && priceInput && firstTier.value !== '') {
        priceInput.value = parseFloat(firstTier.value).toFixed(2);
    }
}

// The applied pricing method must be visible where it is CHOSEN, not only after
// saving: the moment this line's graduated bands would override volume storage
// tiers carried by its SKU, say so beside the toggle that does it. (On the profile
// detail screen the badges above reflect the SAVED state; this note tracks the
// UNSAVED form state.)
function updatePricingMethodNote(lineItem) {
    const note = lineItem.querySelector('.pricing-method-note');
    if (!note) return;

    const skuSelect = lineItem.querySelector('.sku-select');
    const opt = skuSelect ? skuSelect.options[skuSelect.selectedIndex] : null;
    const qtySelect = lineItem.querySelector('.qty-type-select');
    const overrides = !!(opt && opt.dataset.hasVolumeTiers === '1')
        && !!(qtySelect && qtySelect.value === 'per_backup_storage_gb')
        && !!lineItem.querySelector('.tiered-toggle')?.checked;

    if (overrides) {
        note.innerHTML = '<i class="bi bi-info-circle me-1"></i>Overrides the SKU\'s volume storage tiers — this line will bill by its graduated bands.';
    }
    note.style.display = overrides ? '' : 'none';
}
</script>
