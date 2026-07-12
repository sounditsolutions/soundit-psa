@extends('layouts.app')

@section('title', $invoice->invoice_number . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('invoices.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Invoices
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <h4 class="section-title mb-1">{{ $invoice->invoice_number }}</h4>
            <span class="badge {{ $invoice->displayStatusBadgeClass() }}">{{ $invoice->displayStatusLabel() }}</span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            @if($invoice->is_editable)
                <a href="{{ route('invoices.edit', $invoice) }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-pencil me-1"></i>Edit
                </a>
            @endif
                @if(in_array($invoice->status, [\App\Enums\InvoiceStatus::Draft, \App\Enums\InvoiceStatus::PendingSync]))
                    @if(\App\Support\StripeConfig::isConfigured() && $invoice->client->stripe_customer_id && !$invoice->stripe_invoice_id)
                        <form method="POST" action="{{ route('invoices.push-stripe', $invoice) }}" id="push-stripe-form">
                            @csrf
                            <input type="hidden" name="send_email" id="send-email-input" value="0">
                            <div class="btn-group">
                                <button type="submit" class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-cloud-upload me-1"></i>Push to Stripe
                                </button>
                                <button type="button" class="btn btn-outline-success btn-sm dropdown-toggle dropdown-toggle-split"
                                        data-bs-toggle="dropdown">
                                    <span class="visually-hidden">More options</span>
                                </button>
                                <ul class="dropdown-menu">
                                    <li>
                                        <button type="submit" class="dropdown-item"
                                                onclick="document.getElementById('send-email-input').value='1'">
                                            <i class="bi bi-envelope me-1"></i>Push & Email Client
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </form>
                    @elseif($invoice->client->qbo_customer_id && !$invoice->qbo_invoice_id)
                        <form method="POST" action="{{ route('invoices.push-qbo', $invoice) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-cloud-upload me-1"></i>Push to QBO
                            </button>
                        </form>
                    @endif
                @endif
                @if($invoice->stripe_invoice_id)
                    @if($invoice->status === \App\Enums\InvoiceStatus::Synced)
                        <form method="POST" action="{{ route('invoices.send-stripe', $invoice) }}"
                              onsubmit="return confirm('Send this invoice to the client via Stripe email?')">
                            @csrf
                            <button type="submit" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-envelope me-1"></i>Email to Client
                            </button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('invoices.sync-stripe', $invoice) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Refresh from Stripe
                        </button>
                    </form>
                @elseif($invoice->qbo_invoice_id)
                    <form method="POST" action="{{ route('invoices.sync-qbo', $invoice) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-info btn-sm">
                            <i class="bi bi-arrow-repeat me-1"></i>Refresh from QBO
                        </button>
                    </form>
                @endif
                @if($invoice->status !== \App\Enums\InvoiceStatus::Void)
                    <form method="POST" action="{{ route('invoices.void', $invoice) }}"
                          onsubmit="return confirm('Are you sure you want to void this invoice?')">
                        @csrf
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-x-circle me-1"></i>Void
                        </button>
                    </form>
                @endif
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('warning'))
    <div class="alert alert-warning alert-dismissible fade show">
        {{ session('warning') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($invoice->isVoidWithSnapshot())
    <div class="alert alert-danger d-flex align-items-start" role="alert">
        <i class="bi bi-x-octagon-fill me-2 mt-1"></i>
        <div>
            <strong>This invoice is void.</strong>
            The amounts below are the original pre-void values, shown intentionally for reference —
            this invoice's reportable value is $0.00 and it is excluded from revenue and profitability totals.
            @if($invoice->qbo_invoice_id)
                QuickBooks shows this invoice as $0.00.
            @elseif($invoice->stripe_invoice_id)
                Stripe shows this invoice as voided.
            @endif
        </div>
    </div>
@endif

<div class="row g-4">
    {{-- Left column: Client info + Line items --}}
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>Invoice Details</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-borderless table-sm mb-0">
                        <tr>
                            <th class="text-muted" style="width: 140px;">Client</th>
                            <td><x-client-badge :client="$invoice->client" :size="24" /></td>
                        </tr>
                        @if($invoice->contract)
                            <tr>
                                <th class="text-muted">Contract</th>
                                <td><x-contract-badge :contract="$invoice->contract" /></td>
                            </tr>
                        @endif
                        <tr>
                            <th class="text-muted">Invoice Date</th>
                            <td>{{ $invoice->invoice_date->format('M j, Y') }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Due Date</th>
                            <td>{{ $invoice->due_date->format('M j, Y') }}</td>
                        </tr>
                        @if($invoice->notes)
                            <tr>
                                <th class="text-muted">Notes</th>
                                <td>{{ $invoice->notes }}</td>
                            </tr>
                        @endif
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><i class="bi bi-list-ol me-2"></i>Line Items</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Description</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit Price</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end d-none d-lg-table-cell">Cost</th>
                            <th class="text-end d-none d-lg-table-cell">Margin</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoice->lines as $line)
                            <tr>
                                <td>
                                    {{ $line->description }}
                                    @if($line->prepaid_time_minutes)
                                        <br><small class="text-info"><i class="bi bi-clock me-1"></i>{{ round($line->prepaid_time_minutes / 60, 2) }}h prepaid</small>
                                    @endif
                                    @if($line->quantity_source)
                                        <br><small class="text-muted {{ Str::contains($line->quantity_source, 'STALE') ? 'text-danger fw-semibold' : '' }}">{{ $line->quantity_source }}</small>
                                    @endif
                                </td>
                                <td class="text-end">{{ rtrim(rtrim(number_format($line->quantity, 2), '0'), '.') }}</td>
                                <td class="text-end">${{ number_format($line->unit_price, 2) }}</td>
                                <td class="text-end fw-semibold">${{ number_format($line->display_amount, 2) }}</td>
                                <td class="text-end d-none d-lg-table-cell text-muted">
                                    {{ $line->display_cost_amount !== null ? '$' . number_format($line->display_cost_amount, 2) : '-' }}
                                </td>
                                <td class="text-end d-none d-lg-table-cell {{ ($line->display_amount - ($line->display_cost_amount ?? 0)) >= 0 ? 'text-success' : 'text-danger' }}">
                                    @if($line->display_cost_amount !== null)
                                        ${{ number_format($line->display_amount - $line->display_cost_amount, 2) }}
                                    @else
                                        -
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end text-muted">Subtotal</td>
                            <td class="text-end">${{ number_format($invoice->display_subtotal, 2) }}</td>
                            <td class="d-none d-lg-table-cell"></td>
                            <td class="d-none d-lg-table-cell"></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end text-muted">Tax</td>
                            <td class="text-end">${{ number_format($invoice->display_tax, 2) }}</td>
                            <td class="d-none d-lg-table-cell"></td>
                            <td class="d-none d-lg-table-cell"></td>
                        </tr>
                        <tr class="fw-bold">
                            <td colspan="3" class="text-end">Total</td>
                            <td class="text-end">${{ number_format($invoice->display_total, 2) }}</td>
                            <td class="text-end d-none d-lg-table-cell text-muted">
                                {{ $invoice->display_total_cost !== null ? '$' . number_format($invoice->display_total_cost, 2) : '' }}
                            </td>
                            <td class="text-end d-none d-lg-table-cell {{ ($invoice->display_margin ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $invoice->display_margin !== null ? '$' . number_format($invoice->display_margin, 2) : '' }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    {{-- Right column: Totals + Payment + QBO info --}}
    <div class="col-lg-4">
        @php
            // No payment link for voided invoices — there is nothing to pay.
            $paymentUrl = null;
            $paymentProvider = null;
            if ($invoice->status !== \App\Enums\InvoiceStatus::Void) {
                if ($invoice->stripe_invoice_url) {
                    $paymentUrl = $invoice->stripe_invoice_url;
                    $paymentProvider = 'Stripe';
                } elseif ($invoice->qbo_invoice_id && \App\Support\PortalConfig::billingUrl()) {
                    $paymentUrl = \App\Support\PortalConfig::billingUrl() . '/portal/pay/?invoiceNumber='
                        . urlencode($invoice->invoice_number)
                        . '&transactionAmount=' . number_format($invoice->total, 2, '.', '');
                    $paymentProvider = \App\Support\PortalConfig::billingLabel();
                }
            }
        @endphp

        @if($paymentUrl)
        <div class="card shadow-sm mb-4 border-success">
            <div class="card-header bg-success bg-opacity-10">
                <i class="bi bi-credit-card me-2"></i>Payment Link
                <span class="badge bg-light text-dark ms-1">{{ $paymentProvider }}</span>
            </div>
            <div class="card-body">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control font-monospace small" value="{{ $paymentUrl }}" id="paymentLinkInput" readonly>
                    <button class="btn btn-outline-primary" type="button" onclick="copyPaymentLink()" id="copyBtn" title="Copy to clipboard">
                        <i class="bi bi-clipboard" id="copyIcon"></i>
                    </button>
                </div>
                <div class="mt-2">
                    <a href="{{ $paymentUrl }}" target="_blank" class="btn btn-success btn-sm w-100">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open Payment Page
                    </a>
                </div>
            </div>
        </div>
        @endif

        <div class="card shadow-sm mb-4">
            <div class="card-header">
                <i class="bi bi-calculator me-2"></i>Totals
                @if($invoice->isVoidWithSnapshot())
                    <span class="badge bg-danger ms-1">Pre-void</span>
                @endif
            </div>
            <div class="card-body">
                <table class="table table-borderless table-sm mb-0">
                    <tr>
                        <th class="text-muted">Subtotal</th>
                        <td class="text-end">${{ number_format($invoice->display_subtotal, 2) }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Tax</th>
                        <td class="text-end">${{ number_format($invoice->display_tax, 2) }}</td>
                    </tr>
                    <tr class="fw-bold border-top">
                        <th>Total</th>
                        <td class="text-end">${{ number_format($invoice->display_total, 2) }}</td>
                    </tr>
                    @if($invoice->display_total_cost !== null)
                        <tr>
                            <th class="text-muted">Cost</th>
                            <td class="text-end">${{ number_format($invoice->display_total_cost, 2) }}</td>
                        </tr>
                        <tr class="{{ ($invoice->display_margin ?? 0) >= 0 ? 'text-success' : 'text-danger' }}">
                            <th>Margin</th>
                            <td class="text-end fw-bold">${{ number_format($invoice->display_margin, 2) }}</td>
                        </tr>
                    @endif
                </table>
                @if($invoice->isVoidWithSnapshot())
                    <small class="text-muted d-block mt-2">
                        Original pre-void amounts — reportable value is $0.00.
                    </small>
                @endif
                @if($invoice->status === \App\Enums\InvoiceStatus::Draft && (float) $invoice->tax === 0.0)
                    <small class="text-muted d-block mt-2">Tax calculated on push to billing provider</small>
                @endif
                @php $totalPrepaidMinutes = $invoice->lines->sum('prepaid_time_minutes'); @endphp
                @if($totalPrepaidMinutes > 0)
                    <div class="alert alert-info py-2 mt-3 mb-0">
                        <i class="bi bi-clock-fill me-1"></i>
                        This invoice includes <strong>{{ round($totalPrepaidMinutes / 60, 2) }} hours</strong> of prepaid time
                    </div>
                @endif
            </div>
        </div>

        @if($invoice->stripe_invoice_id || \App\Support\StripeConfig::isConfigured())
        <div class="card shadow-sm mb-4">
            <div class="card-header"><i class="bi bi-stripe me-2"></i>Stripe</div>
            <div class="card-body">
                @if($invoice->stripe_invoice_id)
                    @if(!$invoice->contract_id)
                        <p class="text-muted small mb-2"><i class="bi bi-cloud-download me-1"></i>Imported from Stripe</p>
                    @endif
                    <table class="table table-borderless table-sm mb-0">
                        <tr>
                            <th class="text-muted">Stripe ID</th>
                            <td class="small font-monospace">{{ $invoice->stripe_invoice_id }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Last Synced</th>
                            <td>{{ $invoice->stripe_synced_at?->diffForHumans() ?? '-' }}</td>
                        </tr>
                        @if($invoice->stripe_invoice_url)
                            <tr>
                                <th class="text-muted">Payment Page</th>
                                <td>
                                    <a href="{{ $invoice->stripe_invoice_url }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Payment Page
                                    </a>
                                </td>
                            </tr>
                        @endif
                        @if($stripeDashboardUrl)
                            <tr>
                                <th class="text-muted">Dashboard</th>
                                <td>
                                    <a href="{{ $stripeDashboardUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>View in Dashboard
                                    </a>
                                </td>
                            </tr>
                        @endif
                    </table>
                @else
                    <p class="text-muted small mb-0">Not yet synced to Stripe.</p>
                @endif

                @if($invoice->stripe_sync_error)
                    <div class="alert alert-danger mt-3 mb-0 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        {{ $invoice->stripe_sync_error }}
                    </div>
                @endif
            </div>
        </div>
        @endif

        <div class="card shadow-sm">
            <div class="card-header"><i class="bi bi-cloud me-2"></i>QuickBooks</div>
            <div class="card-body">
                @if($invoice->qbo_invoice_id)
                    <table class="table table-borderless table-sm mb-0">
                        <tr>
                            <th class="text-muted">QBO Invoice ID</th>
                            <td>{{ $invoice->qbo_invoice_id }}</td>
                        </tr>
                        @if($invoice->qbo_doc_number)
                            <tr>
                                <th class="text-muted">Doc Number</th>
                                <td>{{ $invoice->qbo_doc_number }}</td>
                            </tr>
                        @endif
                        <tr>
                            <th class="text-muted">Last Synced</th>
                            <td>{{ $invoice->qbo_synced_at?->diffForHumans() ?? '-' }}</td>
                        </tr>
                        @if($qboViewUrl)
                            <tr>
                                <th class="text-muted">View</th>
                                <td>
                                    <a href="{{ $qboViewUrl }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>View in QuickBooks
                                    </a>
                                </td>
                            </tr>
                        @endif
                    </table>
                @else
                    <p class="text-muted small mb-0">Not yet synced to QuickBooks.</p>
                @endif

                @if($invoice->qbo_sync_error)
                    <div class="alert alert-danger mt-3 mb-0 small">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        {{ $invoice->qbo_sync_error }}
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function copyPaymentLink() {
    const input = document.getElementById('paymentLinkInput');
    navigator.clipboard.writeText(input.value).then(() => {
        const icon = document.getElementById('copyIcon');
        icon.className = 'bi bi-check-lg text-success';
        setTimeout(() => { icon.className = 'bi bi-clipboard'; }, 2000);
    });
}
</script>
@endpush
