@php $call = $item->model; @endphp
<a href="{{ $item->url }}" class="activity-item {{ $call->needsFollowUp() ? 'border-start border-warning border-3' : '' }}" data-timestamp="{{ $item->timestamp->toIso8601String() }}" data-type="call">
    <div class="activity-icon activity-icon-blue">
        <i class="bi {{ $call->direction?->value === 'outbound' ? 'bi-telephone-outbound' : 'bi-telephone-inbound' }}"></i>
    </div>
    <div class="activity-content">
        <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
            <span class="fw-semibold small">{{ $call->person?->name ?? \App\Support\PhoneNumber::format($call->from_number) }}</span>
            @if($call->direction)
                <span class="badge bg-secondary" style="font-size: 0.65rem;">{{ $call->direction->label() }}</span>
            @endif
            <span class="badge {{ $call->status->badgeClass() }}" style="font-size: 0.65rem;">{{ $call->status->label() }}</span>
            @if($call->duration_seconds)
                <span class="text-muted small">{{ gmdate($call->duration_seconds >= 3600 ? 'H:i:s' : 'i:s', $call->duration_seconds) }}</span>
            @endif
            @if(($showClient ?? true) && $call->client)
                <x-client-badge :client="$call->client" :link="false" :popover="false" :size="16" />
            @endif
        </div>
        @if($call->call_summary)
            <div class="text-muted small text-clamp-2">{{ Str::limit($call->call_summary, 120) }}</div>
        @elseif($call->from_number)
            <div class="text-muted small">{{ \App\Support\PhoneNumber::format($call->from_number) }}</div>
        @endif
    </div>
    <div class="activity-time" title="{{ $item->timestamp->toAppTz()->format('M j, Y g:i A') }}">
        {{ $item->timestamp->diffForHumans(short: true) }}
    </div>
</a>
