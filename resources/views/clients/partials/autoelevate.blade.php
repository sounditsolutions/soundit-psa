{{-- AutoElevate computers panel (stage 2, read-only). Exactly one of five explicit states;
     the empty states each say WHY, so a blank table never reads as "no machines" (C-56).
     Timestamps arrive as epoch milliseconds and are rendered in the app display timezone
     (C-14 toAppTz; Pacific in production) with the zone abbreviation shown. --}}
<div id="autoelevate-panel" data-state="{{ $state }}" @if($reason) data-reason="{{ $reason }}" @endif>
@if($state === 'switched_off')
    <div class="alert alert-secondary small mb-0" role="status">
        <i class="bi bi-toggle-off me-1"></i>{{ \App\Services\AssetSources\SourceSyncPipeline::sentence('switched_off') }}
    </div>
@elseif($state === 'not_mapped')
    <div class="alert alert-secondary small mb-0" role="status">
        <i class="bi bi-link-45deg me-1"></i>
        <strong>Not mapped.</strong> This client is not linked to an AutoElevate company, so no computers were requested.
        @if(auth()->user()?->isAdmin())
            <a href="{{ route('settings.autoelevate-companies.index') }}" class="ms-1">Map companies</a>
        @endif
    </div>
@elseif($state === 'failed')
    <div class="alert alert-danger small mb-0" role="alert">
        <i class="bi bi-exclamation-triangle me-1"></i>
        <strong>AutoElevate read failed</strong> ({{ $reason }}). The computer list could not be verified — this is not evidence that the client has no machines.
        @php($hint = \App\Services\AutoElevate\AutoElevateReadException::hintFor($reason))
        @if($hint)
            <div class="mt-1">{{ $hint }}</div>
        @endif
    </div>
@elseif($state === 'empty')
    <div class="alert alert-info small mb-0" role="status">
        <i class="bi bi-info-circle me-1"></i>
        <strong>No computers returned.</strong> AutoElevate answered for the mapped company and reported zero computers.
    </div>
@else
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-brand">
                <tr>
                    <th>Machine</th>
                    <th>Operating system</th>
                    <th>Elevation mode</th>
                    <th>Last check-in</th>
                    <th>Linked asset</th>
                </tr>
            </thead>
            <tbody>
            @foreach($computers as $computer)
                <tr>
                    <td class="font-monospace">{{ $computer['machine_name'] ?? '—' }}</td>
                    <td>
                        @if($computer['os_name'] !== null)
                            {{ $computer['os_name'] }}
                            @if($computer['os_version'] !== null)
                                <span class="text-muted small">{{ $computer['os_version'] }}</span>
                            @endif
                        @else
                            <span class="text-muted">Not reported</span>
                        @endif
                    </td>
                    <td>
                        @if($computer['elevation_mode'] === null)
                            <span class="badge bg-secondary">No mode reported</span>
                        @elseif(! $computer['elevation_mode_known'])
                            <span class="badge bg-warning text-dark">Unrecognised: {{ $computer['elevation_mode'] }}</span>
                        @elseif($computer['elevation_mode'] === 'live')
                            <span class="badge bg-success">live</span>
                        @elseif($computer['elevation_mode'] === 'audit')
                            <span class="badge bg-info text-dark">audit</span>
                        @else
                            <span class="badge bg-primary">{{ $computer['elevation_mode'] }}</span>
                        @endif
                    </td>
                    <td>
                        @if($computer['last_checked_in_at'] !== null)
                            {{ $computer['last_checked_in_at']->toAppTz()->format('M j, Y g:i A T') }}
                        @else
                            <span class="text-muted">Never checked in</span>
                        @endif
                    </td>
                    <td>
                        @php($linked = ($linkedAssets ?? [])[strtolower((string) $computer['id'])] ?? null)
                        @if($linked)
                            <a href="{{ route('assets.show', $linked) }}" data-linked-asset="{{ $linked->id }}">{{ $linked->hostname ?: $linked->name }}</a>
                        @else
                            <span class="text-muted" data-linked-asset="none">Not linked</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="text-muted small mt-2">{{ count($computers) }} computer{{ count($computers) === 1 ? '' : 's' }} · read-only</div>
@endif
</div>
