@extends('layouts.app')

@section('title', 'Assets - ' . $client->name . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('clients.show', $client) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to {{ $client->name }}
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col d-flex justify-content-between align-items-center">
        <h4 class="section-title mb-0">Assets &mdash; {{ $client->name }}</h4>
        <a href="{{ route('assets.create', ['client_id' => $client->id]) }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Asset
        </a>
    </div>
</div>

<div class="card shadow-sm">
    @if($assets->isEmpty())
        <div class="card-body text-muted text-center py-3">
            No assets synced for this client.
        </div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Device</th>
                        <th class="d-none d-md-table-cell">Type</th>
                        <th class="d-none d-md-table-cell">OS</th>
                        <th class="d-none d-md-table-cell">Last Seen</th>
                        <th class="text-center" style="width: 90px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($assets as $asset)
                        <tr style="cursor:pointer;" onclick="window.location='{{ route('assets.show', $asset) }}'">
                            <td>
                                <strong>{{ $asset->hostname ?: $asset->name }}</strong>
                                @if($asset->hostname && $asset->hostname !== $asset->name)
                                    <br><small class="text-muted">{{ $asset->name }}</small>
                                @endif
                            </td>
                            <td class="d-none d-md-table-cell">{{ $asset->asset_type ?: '-' }}</td>
                            <td class="d-none d-md-table-cell">{{ $asset->os ?: '-' }}</td>
                            <td class="d-none d-md-table-cell">
                                @if($asset->last_seen_at)
                                    <span title="{{ $asset->last_seen_at->toAppTz()->format('Y-m-d H:i T') }}">
                                        {{ $asset->last_seen_at->diffForHumans() }}
                                    </span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @php $status = $asset->statusBadge; @endphp
                                @if($status === 'Online')
                                    <span class="badge bg-success" title="Online per RMM">Online</span>
                                @elseif($status === 'Offline')
                                    <span class="badge bg-danger" title="Offline per RMM">Offline</span>
                                @elseif($status === 'Stale')
                                    <span class="badge bg-warning text-dark" title="RMM data is stale — last seen beyond the staleness window; status may be out of date">Stale</span>
                                @else
                                    <span class="badge bg-secondary" title="No RMM status available">Unknown</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($assets->hasPages())
        <div class="card-footer">
            {{ $assets->links() }}
        </div>
        @endif
    @endif
</div>
@endsection
