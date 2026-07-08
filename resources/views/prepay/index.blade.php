@extends('layouts.app')

@section('title', 'Prepay Dashboard')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="section-title mb-0">Prepay Dashboard</h4>
    <span class="text-muted small">{{ $contracts->count() }} prepay contracts</span>
</div>

@if($contracts->isEmpty())
    <div class="card shadow-sm">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-wallet2 display-4 d-block mb-3"></i>
            <p class="mb-1">No prepay data available.</p>
            <p class="small">Prepay balances will appear here once contracts with prepay are configured.</p>
        </div>
    </div>
@else
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Contract</th>
                        <th class="text-end">Balance</th>
                        <th class="text-end">Burn Rate</th>
                        <th class="text-end">Days Left</th>
                        <th>Last Top-Up</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($contracts as $contract)
                        @php
                            $balance = (float) $contract->prepay_balance;
                            $isAmount = $contract->prepay_as_amount;
                            $lowThreshold = $isAmount ? 500 : 4;
                            $balanceClass = $balance <= 0 ? 'text-danger fw-semibold' : ($balance < $lowThreshold ? 'text-warning fw-semibold' : 'text-success');

                            $lastTopUp = $contract->prepayTransactions
                                ->where($isAmount ? 'amount' : 'hours', '>', 0)
                                ->sortByDesc('date')
                                ->first();
                        @endphp
                        <tr>
                            <td>
                                <x-client-badge :client="$contract->client" />
                            </td>
                            <td>
                                <x-contract-badge :contract="$contract" />
                            </td>
                            <td class="text-end {{ $balanceClass }}">
                                {{ $contract->prepay_balance_formatted }}
                            </td>
                            <td class="text-end small">
                                {{ $contract->burn_rate_formatted }}
                            </td>
                            <td class="text-end small">
                                @if($contract->days_until_depleted)
                                    <span class="{{ $contract->days_until_depleted < 14 ? 'text-danger fw-semibold' : '' }}">
                                        ~{{ $contract->days_until_depleted }}
                                    </span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="small">
                                {{ $lastTopUp?->date?->format('M j, Y') ?? '-' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif
@endsection
