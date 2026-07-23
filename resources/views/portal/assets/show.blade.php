@extends('portal.layouts.app')

@section('title', ($asset->hostname ?? $asset->name) . ' - Devices - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<div class="mb-3">
    <a href="{{ route('portal.assets.index') }}" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Devices</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="mb-3">{{ $asset->hostname ?? $asset->name }}</h5>

        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr>
                        <td class="text-muted" style="width:140px">Name</td>
                        <td>{{ $asset->name ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Hostname</td>
                        <td>{{ $asset->hostname ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Type</td>
                        <td>{{ $asset->asset_type ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Serial Number</td>
                        <td>{{ $asset->serial_number ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Operating System</td>
                        <td>{{ $asset->os ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Status</td>
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
                </table>
            </div>
        </div>
    </div>
</div>

@if($asset->contracts->isNotEmpty())
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0">Service Agreements</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Agreement</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($asset->contracts as $contract)
                            <tr class="cursor-pointer" onclick="window.location='{{ route('portal.contracts.show', $contract) }}'">
                                <td>{{ $contract->name }}</td>
                                <td>
                                    @if($contract->status === App\Enums\ContractStatus::Active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Inactive</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
@endsection
