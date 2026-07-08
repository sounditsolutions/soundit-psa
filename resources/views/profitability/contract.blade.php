@extends('layouts.app')

@section('title', $contract->name . ' Profitability')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('profitability.client', $contract->client) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to {{ $contract->client->name }}
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title mb-1">{{ $contract->name }}</h4>
        <span class="text-muted">{{ $contract->client->name }} — Profitability</span>
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
    <div class="card-header"><i class="bi bi-box me-2"></i>By SKU / Line Item</div>
    @if(empty($data['bySku']))
        <div class="card-body text-muted text-center">No line item data for this contract.</div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th>Item</th>
                        <th class="d-none d-md-table-cell">Code</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end d-none d-md-table-cell">Cost</th>
                        <th class="text-end">Margin</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['bySku'] as $row)
                        <tr>
                            <td>{{ $row['sku_name'] }}</td>
                            <td class="d-none d-md-table-cell small text-muted">{{ $row['sku_code'] ?? '-' }}</td>
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

@if(!empty($trend))
<div class="card shadow-sm card-static mt-4">
    <div class="card-header"><i class="bi bi-graph-up me-2"></i>Monthly Trend (Last 12 Months)</div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead class="thead-brand">
                <tr>
                    <th>Month</th>
                    <th class="text-end">Revenue</th>
                    <th class="text-end d-none d-md-table-cell">Cost</th>
                    <th class="text-end">Margin</th>
                </tr>
            </thead>
            <tbody>
                @foreach($trend as $row)
                    <tr>
                        <td>{{ $row['month'] }}</td>
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
</div>
@endif
@endsection
