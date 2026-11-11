{{-- A newly opened ticket in the activity stream. data-type stays "ticket" so the
     Tickets filter chip covers it alongside ticket notes. Expects client and contact
     eager-loaded by ActivityStreamService. --}}
@php $ticket = $item->model; @endphp
<a href="{{ $item->url }}" class="activity-item" data-timestamp="{{ $item->timestamp->toIso8601String() }}" data-type="ticket">
    <div class="activity-icon activity-icon-navy">
        <i class="bi bi-ticket-perforated"></i>
    </div>
    <div class="activity-content">
        <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
            @if($ticket->contact)
                <span class="fw-semibold small">{{ $ticket->contact->full_name }}</span>
            @endif
            <span class="badge bg-primary" style="font-size: 0.65rem;">Ticket opened</span>
            @if($ticket->priority)
                <span class="badge {{ $ticket->priority->badgeClass() }}" style="font-size: 0.65rem;">{{ $ticket->priority->value }}</span>
            @endif
            <x-ticket-badge :ticket="$ticket" :link="false" :popover="false" />
            @if(($showClient ?? true) && $ticket->client)
                <x-client-badge :client="$ticket->client" :link="false" :popover="false" :size="16" />
            @endif
        </div>
        @if($ticket->subject)
            <div class="text-muted small text-clamp-2">{{ Str::limit($ticket->subject, 120) }}</div>
        @endif
    </div>
    <div class="activity-time" title="{{ $item->timestamp->toAppTz()->format('M j, Y g:i A') }}">
        {{ $item->timestamp->diffForHumans(short: true) }}
    </div>
</a>
