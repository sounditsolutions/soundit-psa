@php $activity = $item->model; @endphp
<a href="{{ $item->url }}" class="activity-item" data-timestamp="{{ $item->timestamp->toIso8601String() }}" data-type="contract">
    <div class="activity-icon activity-icon-teal">
        <i class="bi bi-file-earmark-text"></i>
    </div>
    <div class="activity-content">
        <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
            @if($activity->user)
                <span class="fw-semibold small">{{ $activity->user->name }}</span>
            @endif
            <span class="badge bg-secondary" style="font-size: 0.65rem;">{{ Str::title(str_replace('_', ' ', $activity->action)) }}</span>
            @if($activity->contract)
                <x-contract-badge :contract="$activity->contract" :link="false" :popover="false" />
            @endif
            @if(($showClient ?? true) && $activity->contract?->client)
                <x-client-badge :client="$activity->contract->client" :link="false" :popover="false" :size="16" />
            @endif
        </div>
        @if($activity->changes)
            <div class="text-muted small text-clamp-2">{{ Str::limit(json_encode($activity->changes), 120) }}</div>
        @endif
    </div>
    <div class="activity-time" title="{{ $item->timestamp->toAppTz()->format('M j, Y g:i A') }}">
        {{ $item->timestamp->diffForHumans(short: true) }}
    </div>
</a>
