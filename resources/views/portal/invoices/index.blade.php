@extends('portal.layouts.app')

@section('title', 'Invoices - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<h4 class="mb-3">Invoices</h4>

<div class="card">
    <div class="card-body p-0">
        @if($invoices->isEmpty())
            <p class="text-muted p-3 mb-0">No invoices found.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Invoice</th>
                            <th>Date</th>
                            <th>Due Date</th>
                            <th class="text-end">Total</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($invoices as $invoice)
                            <tr>
                                <td><a href="{{ route('portal.invoices.show', $invoice) }}">{{ $invoice->invoice_number ?: '#' . $invoice->id }}</a></td>
                                <td class="text-muted">{{ $invoice->invoice_date?->format('M j, Y') ?? '—' }}</td>
                                <td class="text-muted">{{ $invoice->due_date?->format('M j, Y') ?? '—' }}</td>
                                <td class="text-end">${{ number_format($invoice->total, 2) }}</td>
                                <td><span class="badge {{ $invoice->status->portalBadgeClass() }}">{{ $invoice->status->portalLabel() }}</span></td>
                                <td class="text-end">
                                    @if($invoice->stripe_invoice_url && $invoice->status->isUnpaidForPortal())
                                        <a href="{{ $invoice->stripe_invoice_url }}" target="_blank" class="btn btn-sm btn-accent">Pay Online</a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if($invoices->hasPages())
    <div class="mt-3">{{ $invoices->links() }}</div>
@endif
@endsection
