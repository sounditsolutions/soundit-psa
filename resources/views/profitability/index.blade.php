@extends('layouts.app')

@section('title', 'Profitability')

@section('content')
<div class="row mb-3">
    <div class="col">
        <h4 class="section-title mb-0">Profitability</h4>
    </div>
</div>

@include('profitability._date_filter')

{{-- Summary Cards --}}
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
                <div class="fs-4 fw-bold {{ ($data['marginPct'] ?? 0) >= 50 ? 'text-success' : (($data['marginPct'] ?? 0) >= 20 ? '' : 'text-danger') }}">
                    {{ $data['marginPct'] !== null ? $data['marginPct'] . '%' : '-' }}
                </div>
            </div>
        </div>
    </div>
</div>

{{-- Client Breakdown --}}
<div class="card shadow-sm card-static">
    <div class="card-header"><i class="bi bi-building me-2"></i>By Client</div>
    @if(empty($data['byClient']))
        <div class="card-body text-muted text-center">No invoice data found for this period.</div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th>Client</th>
                        <th class="text-end">Revenue</th>
                        <th class="text-end d-none d-md-table-cell">Cost</th>
                        <th class="text-end">Margin</th>
                        <th class="text-end d-none d-md-table-cell">Margin %</th>
                        <th class="text-end d-none d-md-table-cell">Invoices</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data['byClient'] as $row)
                        <tr class="cursor-pointer" onclick="window.location='{{ route('profitability.client', $row['client_id']) }}{{ $from ? '?from='.$from.'&to='.$to : '' }}'">
                            <td>
                                <a href="{{ route('profitability.client', $row['client_id']) }}" class="text-decoration-none fw-semibold">
                                    {{ $row['client_name'] }}
                                </a>
                            </td>
                            <td class="text-end">${{ number_format($row['revenue'], 2) }}</td>
                            <td class="text-end d-none d-md-table-cell">${{ number_format($row['cost'], 2) }}</td>
                            <td class="text-end fw-semibold {{ $row['margin'] >= 0 ? 'text-success' : 'text-danger' }}">
                                ${{ number_format($row['margin'], 2) }}
                            </td>
                            <td class="text-end d-none d-md-table-cell">
                                {{ $row['margin_pct'] !== null ? $row['margin_pct'] . '%' : '-' }}
                            </td>
                            <td class="text-end d-none d-md-table-cell">{{ $row['invoice_count'] }}</td>
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
