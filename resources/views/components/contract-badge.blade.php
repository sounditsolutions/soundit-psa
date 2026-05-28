{{-- Expects: contract model instance --}}
@props(['contract' => null, 'link' => true, 'popover' => true, 'fallback' => '—'])

@if($contract)
    @php
        $dotColor = match($contract->status) {
            \App\Enums\ContractStatus::Active => '#198754',
            \App\Enums\ContractStatus::Expired => '#6c757d',
            \App\Enums\ContractStatus::Cancelled => '#dc3545',
        };
        $popoverHtml = $popover ? '<strong>' . e($contract->name) . '</strong>'
            . '<br><span class="badge ' . $contract->status->badgeClass() . '" style="font-size:.7rem">' . e($contract->status->label()) . '</span>'
            . ' <small class="text-muted">' . e($contract->type->label()) . '</small>'
            . ($contract->billing_period ? '<br><small class="text-muted">Billing:</small> ' . e($contract->billing_period->label()) : '')
            . ($contract->has_prepay ? '<br><small class="text-muted">Prepay:</small> ' . e($contract->prepay_balance_formatted) : '')
            . ($contract->start_date ? '<br><small class="text-muted">Dates:</small> ' . e($contract->start_date->format('M j, Y')) . ($contract->end_date ? ' — ' . e($contract->end_date->format('M j, Y')) : ' — ongoing') : '')
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
            <a href="{{ route('contracts.show', $contract) }}" class="text-decoration-none text-truncate" style="max-width: 200px" {{ $attributes }}>{{ Str::limit($contract->name, 30) }}</a>
        @else
            <span class="text-truncate" style="max-width: 200px" {{ $attributes }}>{{ Str::limit($contract->name, 30) }}</span>
        @endif
    </div>
@else
    <span class="text-muted">{{ $fallback }}</span>
@endif
