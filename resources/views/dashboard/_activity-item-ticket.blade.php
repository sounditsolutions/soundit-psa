@php $note = $item->model; @endphp
<a href="{{ $item->url }}" class="activity-item" data-timestamp="{{ $item->timestamp->toIso8601String() }}" data-type="ticket">
    <div class="activity-icon activity-icon-navy">
        <i class="bi {{ $note->note_type->icon() }}"></i>
    </div>
    <div class="activity-content">
        <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
            @if($note->author)
                <span class="fw-semibold small">{{ $note->author->name }}</span>
            @elseif($note->author_name)
                <span class="fw-semibold small">{{ $note->author_name }}</span>
            @endif
            <span class="badge bg-secondary" style="font-size: 0.65rem;">{{ $note->note_type->label() }}</span>
            @if($note->ticket)
                <span class="badge {{ $note->ticket->priority->badgeClass() }}" style="font-size: 0.65rem;">{{ $note->ticket->priority->value }}</span>
                <x-ticket-badge :ticket="$note->ticket" :link="false" :popover="false" />
            @endif
            @if(($showClient ?? true) && $note->ticket?->client)
                <x-client-badge :client="$note->ticket->client" :link="false" :popover="false" :size="16" />
            @endif
        </div>
        @if($note->body)
            <div class="text-muted small text-clamp-2">{{ Str::limit(strip_tags($note->body), 120) }}</div>
        @endif
    </div>
    <div class="activity-time" title="{{ $item->timestamp->toAppTz()->format('M j, Y g:i A') }}">
        {{ $item->timestamp->diffForHumans(short: true) }}
    </div>
</a>
