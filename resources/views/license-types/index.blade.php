@extends('layouts.app')

@section('title', 'License Types')

@section('content')
<div class="row mb-3">
    <div class="col d-flex justify-content-between align-items-center">
        <h4 class="section-title mb-0">License Types</h4>
        <a href="{{ route('license-types.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New License Type
        </a>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<form method="GET" action="{{ route('license-types.index') }}" class="mb-3">
    <div class="row g-2">
        <div class="col-auto" style="min-width: 150px;">
            <select name="vendor" class="form-select" onchange="this.form.submit()">
                <option value="">All vendors</option>
                @foreach($vendors as $v)
                    <option value="{{ $v }}" {{ $vendor === $v ? 'selected' : '' }}>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto" style="min-width: 130px;">
            <select name="active" class="form-select" onchange="this.form.submit()">
                <option value="1" {{ $active === '1' ? 'selected' : '' }}>Active only</option>
                <option value="all" {{ $active === 'all' ? 'selected' : '' }}>All</option>
            </select>
        </div>
    </div>
</form>

@if($licenseTypes->isEmpty())
    <div class="alert alert-info">No license types found.</div>
@else
    <div class="card shadow-sm card-static">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th>Name</th>
                        <th>Vendor</th>
                        <th class="text-end">Total Qty</th>
                        <th class="text-end d-none d-md-table-cell">Clients</th>
                        <th class="text-end d-none d-md-table-cell">Unit Cost</th>
                        <th class="text-end d-none d-md-table-cell" title="Based on default unit cost">Est. Cost</th>
                        <th class="d-none d-lg-table-cell">Linked SKU</th>
                        <th class="text-center" style="width: 80px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($licenseTypes as $lt)
                        @php
                            $totalQty = (int) ($lt->total_quantity ?? 0);
                            $estCost = $lt->estimateCost($totalQty);
                        @endphp
                        <tr class="cursor-pointer" onclick="window.location='{{ route('license-types.show', $lt) }}'">
                            <td>
                                <a href="{{ route('license-types.show', $lt) }}" class="text-decoration-none fw-semibold">
                                    {{ $lt->name }}
                                </a>
                                @if($lt->vendor_sku_id)
                                    <br><small class="text-muted">{{ $lt->vendor_sku_id }}</small>
                                @endif
                            </td>
                            <td><span class="badge bg-light text-dark">{{ $lt->vendor }}</span></td>
                            <td class="text-end fw-semibold">{{ $totalQty > 0 ? number_format($totalQty) : '-' }}</td>
                            <td class="text-end d-none d-md-table-cell">{{ ($lt->client_count ?? 0) > 0 ? $lt->client_count : '-' }}</td>
                            <td class="text-end d-none d-md-table-cell">
                                @if($lt->default_unit_cost)
                                    ${{ number_format($lt->default_unit_cost, 2) }}
                                    @if($lt->cost_divisor > 1)
                                        <small class="text-muted">÷{{ $lt->cost_divisor }}</small>
                                    @endif
                                @else
                                    -
                                @endif
                            </td>
                            <td class="text-end d-none d-md-table-cell">
                                {{ $estCost !== null ? '$' . number_format($estCost, 2) : '-' }}
                            </td>
                            <td class="d-none d-lg-table-cell small">{{ $lt->sku?->name ?? '-' }}</td>
                            <td class="text-center">
                                @if($lt->is_active)
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
    <div class="mt-3">{{ $licenseTypes->links() }}</div>
@endif
@endsection

@push('styles')
<style>.cursor-pointer { cursor: pointer; }</style>
@endpush
