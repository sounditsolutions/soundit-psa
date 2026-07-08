@extends('portal.layouts.app')

@section('title', 'Invoice ' . ($invoice->invoice_number ?: '#' . $invoice->id) . ' - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<div class="mb-3">
    <a href="{{ route('portal.invoices.index') }}" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Invoices</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h5 class="mb-1">Invoice {{ $invoice->invoice_number ?: '#' . $invoice->id }}</h5>
                <div class="text-muted small">
                    Date: {{ $invoice->invoice_date?->format('M j, Y') ?? '—' }}
                    @if($invoice->due_date)
                        &middot; Due: {{ $invoice->due_date->format('M j, Y') }}
                    @endif
                </div>
            </div>
            <div class="text-end">
                <span class="badge {{ $invoice->status->portalBadgeClass() }}">{{ $invoice->status->portalLabel() }}</span>
                @if($invoice->stripe_invoice_url && $invoice->status->isUnpaidForPortal())
                    <div class="mt-2">
                        <a href="{{ $invoice->stripe_invoice_url }}" target="_blank" class="btn btn-sm btn-accent">
                            <i class="bi bi-credit-card me-1"></i>Pay Online
                        </a>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Line items --}}
<div class="card">
    <div class="card-header">
        <h6 class="mb-0">Line Items</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Description</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Amount</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoice->lines as $line)
                        <tr>
                            <td>{{ $line->description }}</td>
                            <td class="text-end">{{ $line->quantity }}</td>
                            <td class="text-end">${{ number_format($line->unit_price, 2) }}</td>
                            <td class="text-end">${{ number_format($line->amount, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="table-light">
                    <tr>
                        <td colspan="3" class="text-end fw-semibold">Subtotal</td>
                        <td class="text-end">${{ number_format($invoice->subtotal, 2) }}</td>
                    </tr>
                    @if($invoice->tax > 0)
                        <tr>
                            <td colspan="3" class="text-end fw-semibold">Tax</td>
                            <td class="text-end">${{ number_format($invoice->tax, 2) }}</td>
                        </tr>
                    @endif
                    <tr>
                        <td colspan="3" class="text-end fw-bold">Total</td>
                        <td class="text-end fw-bold">${{ number_format($invoice->total, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>
@endsection
