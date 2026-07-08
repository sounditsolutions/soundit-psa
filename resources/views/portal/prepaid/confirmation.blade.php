@extends('portal.layouts.app')

@section('title', 'Order Confirmed - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<div class="text-center mb-4">
    <i class="bi bi-check-circle text-success" style="font-size: 3rem;"></i>
    <h4 class="mt-2">Order Confirmed</h4>
    <p class="text-muted">Your prepaid time purchase has been submitted.</p>
</div>

<div class="row justify-content-center">
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">Order Details</h6>
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <td class="text-muted" style="width: 140px;">Invoice</td>
                        <td>{{ $invoice->invoice_number }}</td>
                    </tr>
                    @if($invoice->contract)
                        <tr>
                            <td class="text-muted">Agreement</td>
                            <td>{{ $invoice->contract->name }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td class="text-muted">Hours</td>
                        <td>{{ $totalHours }}h</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Subtotal</td>
                        <td id="orderSubtotal">${{ number_format($invoice->subtotal, 2) }}</td>
                    </tr>
                    <tr id="taxRow" @if(!$invoice->tax) style="display: none" @endif>
                        <td class="text-muted">Tax</td>
                        <td id="orderTax">${{ number_format($invoice->tax ?? 0, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Total</td>
                        <td><strong id="orderTotal">${{ number_format($invoice->total, 2) }}</strong></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Status</td>
                        <td><span class="badge {{ $invoice->status->portalBadgeClass() }}">{{ $invoice->status->portalLabel() }}</span></td>
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
    const pollUrl = @json(route('portal.prepaid.payment-status', $invoice));
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
                section.innerHTML = '<div class="alert alert-info">' +
                    '<i class="bi bi-info-circle me-1"></i>' +
                    'Your order has been received. You\'ll receive a payment link shortly.</div>';
            });
    }

    checkSync();
});
</script>
@endif
@endsection
