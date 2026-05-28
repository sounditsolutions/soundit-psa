{{-- Expects: ticket with optional eager-loaded client, assignee --}}
@props(['ticket' => null, 'link' => true, 'popover' => true, 'fallback' => '—'])

@if($ticket)
    @php
        $dotColor = match($ticket->status) {
            \App\Enums\TicketStatus::New => '#0d6efd',
            \App\Enums\TicketStatus::InProgress => '#ffc107',
            \App\Enums\TicketStatus::PendingClient,
            \App\Enums\TicketStatus::PendingThirdParty => '#0dcaf0',
            \App\Enums\TicketStatus::Resolved => '#198754',
            \App\Enums\TicketStatus::Closed => '#6c757d',
            default => '#6c757d',
        };
        $popoverHtml = $popover ? '<strong>' . e($ticket->display_id) . '</strong> ' . e(Str::limit($ticket->subject, 80))
            . ($ticket->status ? '<br><span class="badge ' . $ticket->status->badgeClass() . '" style="font-size:.7rem">' . e($ticket->status->label()) . '</span>' : '')
            . ($ticket->priority ? ' <span class="badge ' . $ticket->priority->badgeClass() . '" style="font-size:.7rem">' . e($ticket->priority->label()) . '</span>' : '')
            . ($ticket->relationLoaded('client') && $ticket->client ? '<br><small class="text-muted">Client:</small> ' . e($ticket->client->name) : '')
            . ($ticket->relationLoaded('assignee') && $ticket->assignee ? '<br><small class="text-muted">Assignee:</small> ' . e($ticket->assignee->name) : '')
            . ($ticket->opened_at ? '<br><small class="text-muted">Opened:</small> ' . e($ticket->opened_at->diffForHumans()) : '')
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
            <a href="{{ route('tickets.show', $ticket) }}" class="text-decoration-none text-truncate" style="max-width: 200px" {{ $attributes }}>{{ $ticket->display_id }}</a>
        @else
            <span class="text-truncate" style="max-width: 200px" {{ $attributes }}>{{ $ticket->display_id }}</span>
        @endif
    </div>
@else
    <span class="text-muted">{{ $fallback }}</span>
@endif
