{{-- Expects: invoice model instance with optional eager-loaded client --}}
@props(['invoice' => null, 'link' => true, 'popover' => true, 'fallback' => '—'])

@if($invoice)
    @php
        $dotColor = match($invoice->status) {
            \App\Enums\InvoiceStatus::Draft => '#6c757d',
            \App\Enums\InvoiceStatus::PendingSync => '#ffc107',
            \App\Enums\InvoiceStatus::Synced,
            \App\Enums\InvoiceStatus::Posted => '#0dcaf0',
            \App\Enums\InvoiceStatus::Paid => '#198754',
            \App\Enums\InvoiceStatus::Void => '#dc3545',
        };
        $popoverHtml = $popover ? '<strong>' . e($invoice->invoice_number) . '</strong>'
            . '<br><span class="badge ' . $invoice->status->badgeClass() . '" style="font-size:.7rem">' . e($invoice->status->label()) . '</span>'
            . '<br><small class="text-muted">Total:</small> $' . e(number_format($invoice->total, 2))
            . ($invoice->invoice_date ? '<br><small class="text-muted">Date:</small> ' . e($invoice->invoice_date->format('M j, Y')) : '')
            . ($invoice->due_date ? '<br><small class="text-muted">Due:</small> ' . e($invoice->due_date->format('M j, Y')) : '')
            . ($invoice->relationLoaded('client') && $invoice->client ? '<br><small class="text-muted">Client:</small> ' . e($invoice->client->name) : '')
            : '';
    @endphp
    <div class="d-inline-flex align-items-center gap-1"
        @if($popover && $popoverHtml)
            data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-html="true"
            data-bs-placement="auto" data-bs-delay='{"show":300,"hide":200}'
            data-bs-content="{{ $popoverHtml }}"
        @endif
    >
        <span class="d-inline-block rounded-circle flex-shrink-0" style="width:8px;height:8px;background:{{ $dotColor }};"></span>
        @if($link)
            <a href="{{ route('invoices.show', $invoice) }}" class="text-decoration-none text-truncate" style="max-width: 200px" {{ $attributes }}>{{ $invoice->invoice_number }}</a>
        @else
            <span class="text-truncate" style="max-width: 200px" {{ $attributes }}>{{ $invoice->invoice_number }}</span>
        @endif
    </div>
@else
    <span class="text-muted">{{ $fallback }}</span>
@endif
