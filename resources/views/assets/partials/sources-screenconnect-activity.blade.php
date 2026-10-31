{{-- ScreenConnect panel, Recent activity: the last stored session events (written by the
     ScreenConnect webhook), newest first. Moved from the Security tab (design-r3 §4 A).
     No vendor call. --}}
<div class="small" style="max-height: 200px; overflow-y: auto;" data-sc-events>
    @foreach($events as $evt)
        <div class="d-flex justify-content-between py-1 {{ !$loop->last ? 'border-bottom' : '' }}" data-sc-event>
            <div>
                <span class="badge bg-light text-dark">{{ $evt->event_type }}</span>
                @if($evt->host)
                    <span class="text-muted">by {{ $evt->host }}</span>
                @endif
                @if($evt->data)
                    <div class="text-muted text-truncate" style="max-width: 300px;" title="{{ $evt->data }}">{{ $evt->data }}</div>
                @endif
            </div>
            <span class="text-muted text-nowrap ms-2" title="{{ $evt->event_time?->toAppTz()->format('Y-m-d H:i T') }}">
                {{ $evt->event_time?->diffForHumans() }}
            </span>
        </div>
    @endforeach
</div>
