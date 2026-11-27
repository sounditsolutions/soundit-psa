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
                        <td><span id="orderStatusBadge" class="badge {{ $invoice->status->portalBadgeClass() }}">{{ $invoice->status->portalLabel() }}</span></td>
                    </tr>
                </table>
            </div>
        </div>

        {{-- Payment --}}
        <div id="paymentSection">
            @if($payLink)
                @include('portal.partials.pay-now')
            @elseif($awaitingSync)
                @include('portal.partials.pay-pending')
            @elseif(! $invoice->status->isClientPayable())
                {{-- Non-payable (e.g. staff voided it): truthful terminal state, no
                     promised/polled payment link (psa-bl36l R2D). --}}
                <div class="alert alert-secondary">
                    <i class="bi bi-info-circle me-1"></i>
                    This order's invoice is {{ strtolower($invoice->status->label()) }} and is not payable — no payment is due. If you have questions, please contact us.
                </div>
            @elseif($invoice->qboPartialBalanceLog())
                {{-- #1173: partially paid. PortalPayLink withholds the
                     full-total billing link, so no link is "on its way";
                     say what is owed instead. --}}
                <div class="card mb-4"><div class="card-body">
                    @include('portal.invoices.partials.qbo-balance-note')
                </div></div>
            @else
                @include('portal.partials.pay-pending')
            @endif
        </div>

        <div class="d-flex justify-content-between">
            @if($invoice->status !== \App\Enums\InvoiceStatus::Void)
            <a href="{{ route('portal.invoices.show', $invoice) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-receipt me-1"></i>View Invoice
            </a>
            @else
            <span></span>
            @endif
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

    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    // The BenjiPays source is a POST (a vendor mint); every other source is a link.
    function payButton(data) {
        const label = '<i class="bi bi-credit-card me-1"></i>Pay Now — ' + fmt(data.total);
        if (data.payment_method === 'post') {
            const token = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
            return '<form method="POST" action="' + esc(data.payment_url) + '" class="d-inline">' +
                '<input type="hidden" name="_token" value="' + esc(token) + '">' +
                '<button type="submit" class="btn btn-accent btn-lg">' + label + '</button></form>';
        }
        return '<a href="' + esc(data.payment_url) + '" target="_blank" rel="noopener" class="btn btn-accent btn-lg">' + label + '</a>';
    }

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
                        '<p class="mb-2">Ready to pay?</p>' + payButton(data) + '</div></div>';
                } else if (data.client_payable === false) {
                    // Terminal: no longer payable (e.g. staff voided it mid-poll).
                    // Stop polling and tell the truth (psa-bl36l R4/A).
                    section.innerHTML = '<div class="alert alert-secondary">' +
                        '<i class="bi bi-info-circle me-1"></i>' +
                        "This order's invoice is " + String(data.status_label || '').toLowerCase() +
                        ' and is not payable — no payment is due. If you have questions, please contact us.</div>';
                    var badge = document.getElementById('orderStatusBadge');
                    if (badge && data.status_label) {
                        badge.textContent = data.status_label;
                        badge.className = 'badge ' + (data.status_badge || '');
                    }
                } else {
                    // Out of attempts: stop and leave the "pay link is on its
                    // way" block in place; it already says what to do next.
                    attempts++;
                    if (attempts < maxAttempts) {
                        setTimeout(checkSync, 3000);
                    }
                }
            })
            .catch(() => {
                // Leave the "pay link is on its way" block in place.
            });
    }

    checkSync();
});
</script>
@endif
@endsection
