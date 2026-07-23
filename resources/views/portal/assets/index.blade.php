@extends('portal.layouts.app')

@section('title', 'Devices - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<h4 class="mb-3">Devices</h4>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" action="{{ route('portal.assets.index') }}" class="d-flex">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by hostname, name, or serial..." value="{{ $search ?? '' }}">
            <button type="submit" class="btn btn-sm btn-outline-primary ms-2">Search</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        @if($assets->isEmpty())
            <p class="text-muted p-3 mb-0">No devices found.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Hostname</th>
                            <th>Type</th>
                            <th>OS</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($assets as $asset)
                            <tr class="cursor-pointer" onclick="window.location='{{ route('portal.assets.show', $asset) }}'">
                                <td>{{ $asset->hostname ?? $asset->name }}</td>
                                <td class="text-muted">{{ $asset->asset_type ?? '—' }}</td>
                                <td class="text-muted">{{ $asset->os ?? '—' }}</td>
                                <td>
                                    @php $assetStatus = $asset->statusBadge; @endphp
                                    @if($assetStatus === 'Online')
                                        <span class="badge bg-success">Online</span>
                                    @elseif($assetStatus === 'Stale')
                                        <span class="badge bg-warning text-dark" title="Based on monitoring data that hasn't refreshed recently — this device's status may be out of date">Stale</span>
                                    @elseif($assetStatus === 'Offline')
                                        <span class="badge bg-secondary">Offline</span>
                                    @else
                                        <span class="badge bg-secondary">Unknown</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if($assets->hasPages())
    <div class="mt-3">{{ $assets->links() }}</div>
@endif
@endsection
