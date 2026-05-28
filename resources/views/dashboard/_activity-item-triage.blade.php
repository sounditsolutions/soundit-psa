@php
    $run = $item->model;
    $classification = $run->classification();
    $aiUser = \App\Support\TriageConfig::systemUserId() ? \App\Models\User::find(\App\Support\TriageConfig::systemUserId()) : null;
@endphp
<a href="{{ $item->url }}" class="activity-item {{ $run->hasFailed() ? 'border-start border-danger border-3' : '' }}" data-timestamp="{{ $item->timestamp->toIso8601String() }}" data-type="triage">
    @if($aiUser)
        <div class="activity-icon-avatar">
            <x-avatar :user="$aiUser" :size="32" />
        </div>
    @else
        <div class="activity-icon activity-icon-purple">
            <i class="bi bi-robot"></i>
        </div>
    @endif
    <div class="activity-content">
        <div class="d-flex flex-wrap align-items-center gap-1 mb-1">
            <span class="fw-semibold small">{{ $aiUser?->name ?? 'AI Triage' }}</span>
            <span class="badge bg-secondary" style="font-size: 0.65rem;">{{ ucfirst($run->mode) }}</span>
            @if($run->hasFailed())
                <span class="badge bg-danger" style="font-size: 0.65rem;">Failed</span>
            @else
                <span class="badge bg-success" style="font-size: 0.65rem;">Completed</span>
            @endif
            @if($run->ticket)
                <x-ticket-badge :ticket="$run->ticket" :link="false" :popover="false" />
            @endif
            @if(($showClient ?? true) && $run->ticket?->client)
                <x-client-badge :client="$run->ticket->client" :link="false" :popover="false" :size="16" />
            @endif
        </div>
        @if($classification)
            <div class="text-muted small text-clamp-2">{{ $classification['classification'] ?? '' }} — {{ $classification['reasoning'] ?? '' }}</div>
        @elseif($run->hasFailed() && $run->errors)
            <div class="text-muted small text-clamp-2">{{ Str::limit(implode('; ', array_map(fn ($e) => is_array($e) ? ($e['message'] ?? json_encode($e)) : $e, $run->errors)), 120) }}</div>
        @endif
    </div>
    <div class="activity-time" title="{{ $item->timestamp->toAppTz()->format('M j, Y g:i A') }}">
        {{ $item->timestamp->diffForHumans(short: true) }}
    </div>
</a>
