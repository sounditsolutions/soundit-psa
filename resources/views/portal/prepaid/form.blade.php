@extends('portal.layouts.app')

@section('title', 'Purchase Prepaid Time - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<div class="mb-3">
    <a href="{{ route('portal.dashboard') }}" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Dashboard</a>
</div>

<h4 class="mb-4">Purchase Prepaid Time</h4>

@if(session('error'))
    <div class="alert alert-warning">
        <i class="bi bi-exclamation-triangle me-1"></i>{{ session('error') }}
    </div>
@endif

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">{{ $contract->name }}</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-3">
                    <tr>
                        <td class="text-muted" style="width: 140px;">Product</td>
                        <td>{{ $sku->name }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Price per unit</td>
                        <td>${{ number_format($sku->unit_price, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Hours per unit</td>
                        <td>{{ $hoursPerUnit }}h</td>
                    </tr>
                    @if($contract->prepay_balance !== null)
                        <tr>
                            <td class="text-muted">Current balance</td>
                            <td><strong>{{ number_format($contract->prepay_balance, 1) }}h</strong></td>
                        </tr>
                    @endif
                </table>

                <form method="POST" action="{{ route('portal.prepaid.store', $contract) }}" id="purchaseForm">
                    @csrf
                    <input type="hidden" name="expected_unit_price" value="{{ $sku->unit_price }}">

                    <div class="mb-3">
                        <label for="quantity" class="form-label">Quantity</label>
                        <input type="number" class="form-control" id="quantity" name="quantity"
                               value="{{ old('quantity', 1) }}" min="1" step="1" required
                               style="max-width: 120px;">
                        @error('quantity')
                            <div class="invalid-feedback d-block">{{ $message }}</div>
                        @enderror
                    </div>

                    <button type="submit" class="btn btn-accent" id="submitBtn">
                        <i class="bi bi-cart-check me-1"></i>Purchase Prepaid Time
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">
                <h6 class="mb-0">Order Summary</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted">Quantity</td>
                        <td class="text-end" id="summaryQty">1</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Price per unit</td>
                        <td class="text-end">${{ number_format($sku->unit_price, 2) }}</td>
                    </tr>
                    <tr class="border-top">
                        <td><strong>Total</strong></td>
                        <td class="text-end"><strong id="summaryTotal">${{ number_format($sku->unit_price, 2) }}</strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Hours added</td>
                        <td class="text-end" id="summaryHours">{{ $hoursPerUnit }}h</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const qtyInput = document.getElementById('quantity');
    const summaryQty = document.getElementById('summaryQty');
    const summaryTotal = document.getElementById('summaryTotal');
    const summaryHours = document.getElementById('summaryHours');
    const unitPrice = {{ (float) $sku->unit_price }};
    const hoursPerUnit = {{ $hoursPerUnit }};

    function updateSummary() {
        const qty = Math.max(1, parseInt(qtyInput.value) || 1);
        summaryQty.textContent = qty;
        summaryTotal.textContent = '$' + (qty * unitPrice).toFixed(2);
        summaryHours.textContent = (qty * hoursPerUnit).toFixed(1) + 'h';
    }

    qtyInput.addEventListener('input', updateSummary);
    updateSummary();

    // Prevent double-submit
    document.getElementById('purchaseForm').addEventListener('submit', function() {
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('submitBtn').innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Processing...';
    });
});
</script>
@endsection
