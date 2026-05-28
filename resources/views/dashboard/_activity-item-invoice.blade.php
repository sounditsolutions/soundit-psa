@php
    $invoice = $item->model;
    $isOverdue = $invoice->status === \App\Enums\InvoiceStatus::Posted && $invoice->due_date?->isPast();
@endphp
<a href="{{ $item->url }}" class="activity-item {{ $isOverdue ? 'border-start border-danger border-3' : '' }}" data-timestamp="{{ $item->timestamp->toIso8601String() }}" data-type="invoice">
    <div class="activity-icon activity-icon-orange">
        <i class="bi bi-receipt"></i>
    </div>
    <div class="activity-content">
        <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
            <span class="fw-semibold small">{{ $invoice->invoice_number }}</span>
            <span class="badge {{ $invoice->status->badgeClass() }}" style="font-size: 0.65rem;">{{ $invoice->status->label() }}</span>
            <span class="fw-semibold small">${{ number_format($invoice->total, 2) }}</span>
            @if(($showClient ?? true) && $invoice->client)
                <x-client-badge :client="$invoice->client" :link="false" :popover="false" :size="16" />
            @endif
        </div>
        <div class="text-muted small">
            @if($invoice->contract)
                <x-contract-badge :contract="$invoice->contract" :link="false" :popover="false" />
                <span class="mx-1">&middot;</span>
            @endif
            {{ $invoice->invoice_date?->format('M j, Y') }}
            @if($isOverdue)
                <span class="text-danger fw-semibold ms-1">Overdue</span>
            @endif
        </div>
    </div>
    <div class="activity-time" title="{{ $item->timestamp->toAppTz()->format('M j, Y g:i A') }}">
        {{ $item->timestamp->diffForHumans(short: true) }}
    </div>
</a>
