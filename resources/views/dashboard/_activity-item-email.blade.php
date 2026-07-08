@php $email = $item->model; @endphp
<a href="{{ $item->url }}" class="activity-item" data-timestamp="{{ $item->timestamp->toIso8601String() }}" data-type="email">
    <div class="activity-icon activity-icon-purple">
        <i class="bi bi-envelope"></i>
    </div>
    <div class="activity-content">
        <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
            <span class="fw-semibold small">{{ $email->senderDisplay() }}</span>
            @if($email->direction)
                <span class="badge {{ $email->direction->badgeClass() }}" style="font-size: 0.65rem;">{{ $email->direction->label() }}</span>
            @endif
            @if(($showClient ?? true) && $email->client)
                <x-client-badge :client="$email->client" :link="false" :popover="false" :size="16" />
            @endif
            @if($email->ticket)
                <x-ticket-badge :ticket="$email->ticket" :link="false" :popover="false" />
            @endif
        </div>
        @if($email->subject)
            <div class="text-muted small text-clamp-2">{{ Str::limit($email->subject, 120) }}</div>
        @endif
    </div>
    <div class="activity-time" title="{{ $item->timestamp->toAppTz()->format('M j, Y g:i A') }}">
        {{ $item->timestamp->diffForHumans(short: true) }}
    </div>
</a>
