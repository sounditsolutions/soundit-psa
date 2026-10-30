{{-- Tactical panel, Recent activity (design-r3 §2.2, §4 A): the newest Tactical
     actions from the local action log (redacted at write), ticket-linked. The same
     outcome vocabulary as the Overview card it moved from. No vendor call. --}}
@php
    $outcome = fn (string $status): array => match ($status) {
        'ok' => ['bg-success', 'succeeded'],
        'offline' => ['bg-warning text-dark', 'no-op (agent unreachable)'],
        'outcome_unknown' => ['bg-warning text-dark', 'outcome unknown (may have run)'],
        'error' => ['bg-danger', 'error'],
        default => ['bg-secondary', $status],
    };
@endphp
<div id="tactical-recent-actions">
@if(empty($activity['rows']))
    <p class="text-muted small mb-0">No recent Tactical actions for this device.</p>
@else
    <ul class="list-unstyled small mb-0" data-tactical-activity>
        @foreach($activity['rows'] as $row)
            @php [$badgeClass, $outcomeText] = $outcome($row['result_status']); @endphp
            <li class="d-flex justify-content-between align-items-start gap-2 py-1 border-bottom" data-activity-row>
                <span>
                    <span class="fw-semibold">{{ \Illuminate\Support\Str::of($row['action'])->after('tactical.')->replace('_', ' ')->title() }}</span>
                    <span class="text-muted">· {{ $row['actor'] }}</span>
                    @if(! empty($row['ticket_id']))
                        · under <a href="{{ route('tickets.show', $row['ticket_id']) }}" class="text-decoration-none">#{{ $row['ticket_id'] }}</a>
                    @endif
                    @if($row['when'])
                        <span class="text-muted">· <span title="{{ $row['when']->toAppTz()->format('Y-m-d H:i T') }}">{{ $row['when']->diffForHumans() }}</span></span>
                    @endif
                </span>
                <span class="badge {{ $badgeClass }} flex-shrink-0">{{ $outcomeText }}</span>
            </li>
        @endforeach
    </ul>
    @if($activity['total'] > count($activity['rows']))
        <div class="small text-muted mt-1">{{ count($activity['rows']) }} most recent of {{ $activity['total'] }}</div>
    @endif
@endif
</div>
