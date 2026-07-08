@extends('portal.layouts.app')

@section('title', 'Order Confirmed - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<div class="text-center mb-4">
    <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
    <h4 class="mt-2">Order Confirmed</h4>
    <p class="text-muted">Your order has been submitted.</p>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0">Order Details</h6>
                <span class="text-muted small">Invoice {{ $invoice->invoice_number }}</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-3">
                        <thead class="table-light">
                            <tr>
                                <th>Product</th>
                                <th class="text-center" style="width: 70px;">Qty</th>
                                <th class="text-end" style="width: 110px;">Unit</th>
                                <th class="text-end" style="width: 120px;">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoice->lines as $line)
                                <tr>
                                    <td>{{ $line->description }}</td>
                                    <td class="text-center">{{ (int) $line->quantity }}</td>
                                    <td class="text-end">${{ number_format($line->unit_price, 2) }}</td>
                                    <td class="text-end">${{ number_format($line->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted" style="width: 140px;">Subtotal</td>
                        <td class="text-end" id="orderSubtotal">${{ number_format($invoice->subtotal, 2) }}</td>
                    </tr>
                    <tr id="taxRow" @if(!$invoice->tax) style="display: none" @endif>
                        <td class="text-muted">Tax</td>
                        <td class="text-end" id="orderTax">${{ number_format($invoice->tax ?? 0, 2) }}</td>
                    </tr>
                    <tr class="border-top">
                        <td><strong>Total</strong></td>
                        <td class="text-end"><strong id="orderTotal">${{ number_format($invoice->total, 2) }}</strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Status</td>
                        <td class="text-end"><span class="badge {{ $invoice->status->portalBadgeClass() }}">{{ $invoice->status->portalLabel() }}</span></td>
                    </tr>
                </table>
            </div>
        </div>

        {{-- Payment --}}
        <div id="paymentSection">
            @if($paymentUrl)
                <div class="card mb-4">
                    <div class="card-body text-center">
                        <p class="mb-2">Ready to pay?</p>
                        <a href="{{ $paymentUrl }}" target="_blank" class="btn btn-accent btn-lg">
                            <i class="bi bi-credit-card me-1"></i>Pay Now — ${{ number_format($invoice->total, 2) }}
                        </a>
                    </div>
                </div>
            @elseif($awaitingSync)
                <div class="card mb-4" id="paymentLoading">
                    <div class="card-body text-center py-3">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        <span class="text-muted">Preparing payment link...</span>
                    </div>
                </div>
            @else
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-1"></i>
                    Your order has been received. You'll receive a payment link shortly.
                </div>
            @endif
        </div>

        <div class="d-flex justify-content-between">
            <a href="{{ route('portal.invoices.show', $invoice) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-receipt me-1"></i>View Invoice
            </a>
            <a href="{{ route('portal.dashboard') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-house me-1"></i>Back to Dashboard
            </a>
        </div>
    </div>
</div>

@if($awaitingSync)
<script>
document.addEventListener('DOMContentLoaded', function() {
    const pollUrl = @json(route('portal.shop.payment-status', $invoice));
    const section = document.getElementById('paymentSection');
    let attempts = 0;
    const maxAttempts = 10;

    function fmt(n) { return '$' + n.toFixed(2); }

    function checkSync() {
        fetch(pollUrl, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(data => {
                // Update amounts with billing-backend values (includes tax)
                if (data.total) {
                    document.getElementById('orderSubtotal').textContent = fmt(data.subtotal);
                    document.getElementById('orderTotal').textContent = fmt(data.total);
                    if (data.tax > 0) {
                        document.getElementById('orderTax').textContent = fmt(data.tax);
                        document.getElementById('taxRow').style.display = '';
                    }
                }

                if (data.payment_url) {
                    section.innerHTML = '<div class="card mb-4"><div class="card-body text-center">' +
                        '<p class="mb-2">Ready to pay?</p>' +
                        '<a href="' + data.payment_url + '" target="_blank" class="btn btn-accent btn-lg">' +
                        '<i class="bi bi-credit-card me-1"></i>Pay Now — ' + fmt(data.total) + '</a></div></div>';
                } else {
                    attempts++;
                    if (attempts >= maxAttempts) {
                        section.innerHTML = '<div class="alert alert-info">' +
                            '<i class="bi bi-info-circle me-1"></i>' +
                            'Your order has been received. You\'ll receive a payment link shortly.</div>';
                    } else {
                        setTimeout(checkSync, 3000);
                    }
                }
            })
            .catch(() => {
                attempts++;
                if (attempts < maxAttempts) {
                    setTimeout(checkSync, 3000);
                }
            });
    }

    setTimeout(checkSync, 3000);
});
</script>
@endif
@endsection
