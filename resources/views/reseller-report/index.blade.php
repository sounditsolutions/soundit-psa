@extends('layouts.app')

@section('title', 'Reseller Report')

@section('content')
<div class="row mb-3">
    <div class="col">
        <h4 class="section-title mb-0">Reseller License Report</h4>
    </div>
</div>

<form method="GET" action="{{ route('reseller-report.index') }}" class="mb-3">
    <div class="row g-2">
        <div class="col-auto" style="min-width: 250px;">
            <select name="reseller_id" class="form-select" onchange="this.form.submit()">
                <option value="">-- Select a reseller --</option>
                @foreach($resellers as $r)
                    <option value="{{ $r->id }}" {{ $selectedResellerId == $r->id ? 'selected' : '' }}>{{ $r->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto" style="min-width: 250px;">
            <select name="license_type_id" class="form-select" onchange="this.form.submit()">
                <option value="">All license types</option>
                @foreach($licenseTypes as $lt)
                    <option value="{{ $lt->id }}" {{ $selectedLicenseTypeId == $lt->id ? 'selected' : '' }}>{{ $lt->name }}</option>
                @endforeach
            </select>
        </div>
        @if($selectedResellerId || $selectedLicenseTypeId)
            <div class="col-auto">
                <a href="{{ route('reseller-report.index') }}" class="btn btn-outline-secondary" title="Clear filters">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        @endif
    </div>
</form>

@if(!$data)
    <div class="alert alert-info">
        Select a reseller above to view aggregated license counts across their child clients.
    </div>
@else
    {{-- Summary cards --}}
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm card-static">
                <div class="card-body text-center">
                    <div class="text-muted small">Child Clients</div>
                    <div class="fs-3 fw-bold">{{ $data['childClients']->count() }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm card-static">
                <div class="card-body text-center">
                    <div class="text-muted small">License Types</div>
                    <div class="fs-3 fw-bold">{{ $data['typeTotals']->count() }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm card-static">
                <div class="card-body text-center">
                    <div class="text-muted small">Total Licenses</div>
                    <div class="fs-3 fw-bold">{{ number_format($data['grandTotal']) }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm card-static">
                <div class="card-body text-center">
                    <div class="text-muted small">Reseller</div>
                    <div class="fs-5 fw-bold">
                        <a href="{{ route('clients.show', $data['reseller']) }}" class="text-decoration-none">
                            {{ $data['reseller']->name }}
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Per license type breakdown --}}
    @forelse($data['typeTotals'] as $typeTotal)
        <div class="card shadow-sm card-static mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <strong>{{ $typeTotal->license_type_name }}</strong>
                    @if($typeTotal->vendor)
                        <span class="badge bg-light text-dark ms-1">{{ $typeTotal->vendor }}</span>
                    @endif
                </span>
                <span class="badge bg-primary">Total: {{ number_format($typeTotal->total_quantity) }}</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="thead-brand">
                        <tr>
                            <th>Client</th>
                            <th class="text-end" style="width: 120px;">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data['clientBreakdown']->get($typeTotal->license_type_id, collect()) as $row)
                            <tr>
                                <td>
                                    <a href="{{ route('clients.show', $row->client_id) }}" class="text-decoration-none">
                                        {{ $row->client_name }}
                                    </a>
                                </td>
                                <td class="text-end fw-semibold">{{ number_format($row->quantity) }}</td>
                            </tr>
                        @endforeach
                        <tr class="table-light">
                            <td class="fw-bold">Total ({{ $typeTotal->client_count }} clients)</td>
                            <td class="text-end fw-bold">{{ number_format($typeTotal->total_quantity) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="alert alert-info">
            No active licenses found across this reseller's child clients.
        </div>
    @endforelse
@endif
@endsection
