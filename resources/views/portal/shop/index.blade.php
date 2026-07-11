@extends('portal.layouts.app')

@section('title', 'Shop - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<div class="mb-3">
    <a href="{{ route('portal.dashboard') }}" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>
</div>

<h4 class="mb-1">Shop</h4>
<p class="text-muted">Browse products and services and place an order. We'll follow up to confirm the details.</p>

@if(session('error'))
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>{{ session('error') }}
    </div>
@endif

@if(! $hasProducts)
    <div class="card">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-bag" style="font-size: 2.5rem;"></i>
            <p class="mt-3 mb-0">There are no products available to order right now.</p>
            <p class="mb-0">Please contact us if you'd like to place an order.</p>
        </div>
    </div>
@else
<form method="POST" action="{{ route('portal.shop.store') }}" id="shopForm">
    @csrf
    <div class="row g-4">
        <div class="col-lg-8">
            @foreach($groupedSkus as $category => $skus)
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">{{ $category }}</h6>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Product</th>
                                        <th class="text-end" style="width: 120px;">Price</th>
                                        <th class="text-center" style="width: 110px;">Qty</th>
                                        <th class="text-end" style="width: 120px;">Subtotal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($skus as $sku)
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">{{ $sku->name }}</div>
                                                @if($sku->portal_description)
                                                    <div class="text-muted small">{{ $sku->portal_description }}</div>
                                                @endif
                                            </td>
                                            <td class="text-end">${{ number_format($sku->unit_price, 2) }}</td>
                                            <td class="text-center">
                                                <input type="hidden" name="expected_prices[{{ $sku->id }}]" value="{{ $sku->unit_price }}">
                                                <input type="number"
                                                       class="form-control form-control-sm qty-input text-center mx-auto"
                                                       name="quantities[{{ $sku->id }}]"
                                                       value="{{ old('quantities.'.$sku->id, 0) }}"
                                                       min="0" max="999" step="1"
                                                       data-price="{{ (float) $sku->unit_price }}"
                                                       style="max-width: 90px;">
                                            </td>
                                            <td class="text-end line-subtotal" data-price="{{ (float) $sku->unit_price }}">$0.00</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="col-lg-4">
            <div class="card" style="position: sticky; top: 1rem;">
                <div class="card-header">
                    <h6 class="mb-0">Order Summary</h6>
                </div>
                <div class="card-body">
                    <table class="table table-sm table-borderless mb-3">
                        <tr>
                            <td class="text-muted">Items</td>
                            <td class="text-end" id="summaryItems">0</td>
                        </tr>
                        <tr class="border-top">
                            <td><strong>Total</strong></td>
                            <td class="text-end"><strong id="summaryTotal">$0.00</strong></td>
                        </tr>
                    </table>
                    <p class="text-muted small">Taxes are calculated at checkout. You'll receive an invoice and payment link.</p>
                    <button type="submit" class="btn btn-accent w-100" id="submitBtn" disabled>
                        <i class="bi bi-bag-check me-1"></i>Place Order
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('shopForm');
    const qtyInputs = Array.from(document.querySelectorAll('.qty-input'));
    const summaryItems = document.getElementById('summaryItems');
    const summaryTotal = document.getElementById('summaryTotal');
    const submitBtn = document.getElementById('submitBtn');

    function money(n) { return '$' + n.toFixed(2); }

    function recalc() {
        let totalItems = 0;
        let total = 0;

        qtyInputs.forEach(function(input) {
            let qty = parseInt(input.value) || 0;
            if (qty < 0) { qty = 0; input.value = 0; }
            const price = parseFloat(input.dataset.price) || 0;
            const lineTotal = qty * price;

            const row = input.closest('tr');
            const sub = row.querySelector('.line-subtotal');
            if (sub) { sub.textContent = money(lineTotal); }

            totalItems += qty;
            total += lineTotal;
        });

        summaryItems.textContent = totalItems;
        summaryTotal.textContent = money(total);
        submitBtn.disabled = totalItems < 1;
    }

    qtyInputs.forEach(function(input) {
        input.addEventListener('input', recalc);
    });
    recalc();

    // Prevent double-submit
    form.addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';
    });
});
</script>
@endif
@endsection
