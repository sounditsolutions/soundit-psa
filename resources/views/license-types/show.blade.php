@extends('layouts.app')

@section('title', $licenseType->name . ' - License Type')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('license-types.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to License Types
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col d-flex justify-content-between align-items-center">
        <h4 class="section-title mb-0">{{ $licenseType->name }}</h4>
        <span class="badge bg-light text-dark">{{ $licenseType->vendor }}</span>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Edit Form --}}
<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm card-static">
            <div class="card-header"><i class="bi bi-pencil me-2"></i>Details</div>
            <div class="card-body">
                <form method="POST" action="{{ route('license-types.update', $licenseType) }}">
                    @csrf
                    @method('PATCH')
                    @include('license-types._form', ['licenseType' => $licenseType])
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="{{ route('license-types.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Licenses by Client --}}
@if($licenses->isNotEmpty())
<div class="row mt-4">
    <div class="col">
        <div class="card shadow-sm card-static">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-key me-2"></i>Licenses by Client ({{ $licenses->count() }})</span>
                <a href="{{ route('licenses.index', ['license_type_id' => $licenseType->id]) }}" class="btn btn-outline-primary btn-sm">
                    View in Licenses
                </a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Client</th>
                            <th class="text-end">Quantity</th>
                            <th class="text-end d-none d-md-table-cell">Assigned</th>
                            <th class="text-center" style="width: 80px;">Status</th>
                            <th class="d-none d-md-table-cell">Synced</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($licenses as $license)
                        <tr>
                            <td>
                                @if($license->client)
                                    <a href="{{ route('clients.show', $license->client) }}" class="text-decoration-none">
                                        {{ $license->client->name }}
                                    </a>
                                @else
                                    <span class="text-muted">No client</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">{{ number_format($license->quantity) }}</td>
                            <td class="text-end d-none d-md-table-cell">
                                @if($license->assigned_quantity !== null)
                                    {{ number_format($license->assigned_quantity) }}
                                    @if($license->quantity > 0)
                                        <small class="text-muted">({{ round($license->assigned_quantity / $license->quantity * 100) }}%)</small>
                                    @endif
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($license->status === 'active')
                                    <span class="badge bg-success">Active</span>
                                @else
                                    <span class="badge bg-secondary">{{ ucfirst($license->status ?? 'unknown') }}</span>
                                @endif
                            </td>
                            <td class="d-none d-md-table-cell small text-muted">
                                {{ $license->synced_at?->diffForHumans() ?? '-' }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="table-light fw-semibold">
                            <td>Total</td>
                            <td class="text-end">{{ number_format($licenses->sum('quantity')) }}</td>
                            <td class="text-end d-none d-md-table-cell">
                                @php $totalAssigned = $licenses->whereNotNull('assigned_quantity')->sum('assigned_quantity'); @endphp
                                {{ $totalAssigned > 0 ? number_format($totalAssigned) : '-' }}
                            </td>
                            <td></td>
                            <td class="d-none d-md-table-cell"></td>
                        </tr>
                        @php $totalQty = $licenses->sum('quantity'); $estCost = $licenseType->estimateCost($totalQty); @endphp
                        @if($estCost !== null)
                        <tr class="table-light">
                            <td class="text-muted">Est. Vendor Cost</td>
                            <td class="text-end fw-semibold" colspan="4">
                                ${{ number_format($estCost, 2) }}
                                <small class="text-muted fw-normal">
                                    @if($licenseType->minimum_quantity && $totalQty < $licenseType->minimum_quantity)
                                        (min {{ $licenseType->minimum_quantity }} × ${{ number_format($licenseType->default_unit_cost, 2) }}{{ $licenseType->cost_divisor > 1 ? ' ÷ ' . $licenseType->cost_divisor : '' }})
                                    @elseif($licenseType->effective_unit_cost)
                                        ({{ number_format($totalQty) }} × ${{ number_format($licenseType->default_unit_cost, 2) }}{{ $licenseType->cost_divisor > 1 ? ' ÷ ' . $licenseType->cost_divisor : '' }})
                                    @endif
                                    @if($licenseType->minimum_cost && $estCost == $licenseType->minimum_cost)
                                        — min cost floor
                                    @endif
                                </small>
                            </td>
                        </tr>
                        @endif
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@else
<div class="row mt-4">
    <div class="col">
        <div class="alert alert-info">No licenses found for this type.</div>
    </div>
</div>
@endif
@endsection
