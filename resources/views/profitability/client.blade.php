@extends('layouts.app')

@section('title', $client->name . ' Profitability')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('profitability.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Profitability
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title mb-1">{{ $client->name }}</h4>
        <span class="text-muted">Profitability</span>
    </div>
</div>

@include('profitability._date_filter')

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm text-center">
            <div class="card-body">
                <div class="text-muted small">Revenue</div>
                <div class="fs-4 fw-bold">${{ number_format($data['revenue'], 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center">
            <div class="card-body">
                <div class="text-muted small">Cost</div>
                <div class="fs-4 fw-bold">${{ number_format($data['cost'], 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center">
            <div class="card-body">
                <div class="text-muted small">Margin</div>
                <div class="fs-4 fw-bold {{ $data['margin'] >= 0 ? 'text-success' : 'text-danger' }}">
                    ${{ number_format($data['margin'], 2) }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm text-center">
            <div class="card-body">
                <div class="text-muted small">Margin %</div>
                <div class="fs-4 fw-bold">{{ $data['marginPct'] !== null ? $data['marginPct'] . '%' : '-' }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm card-static">
    <div class="card-header"><i class="bi bi-file-earmark-text me-2"></i>By Contract</div>
    @if(empty($data['byContract']))
        <div class="card-body text-muted text-center">No invoice data for this client.</div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th>Contract</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end d-none d-md-table-cell">Cost</th>
                        <th class="text-end">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['byContract'] as $row)
                        <tr class="cursor-pointer" onclick="window.location='{{ route('profitability.contract', $row['contract_id']) }}'">
                            <td>
                                <a href="{{ route('profitability.contract', $row['contract_id']) }}" class="text-decoration-none fw-semibold">
                                    {{ $row['contract_name'] }}
                                </a>
                            </td>
                            <td class="text-end">${{ number_format($row['revenue'], 2) }}</td>
                            <td class="text-end d-none d-md-table-cell">${{ number_format($row['cost'], 2) }}</td>
                            <td class="text-end fw-semibold {{ $row['margin'] >= 0 ? 'text-success' : 'text-danger' }}">
                                ${{ number_format($row['margin'], 2) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection

@push('styles')
<style>.cursor-pointer { cursor: pointer; }</style>
@endpush
