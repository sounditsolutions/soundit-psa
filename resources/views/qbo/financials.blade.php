@extends('layouts.app')

@section('title', 'Bank & Expenses')

@section('content')
<div class="row mb-3 align-items-center">
    <div class="col">
        <h4 class="section-title mb-0">Bank &amp; Expenses</h4>
        <div class="text-muted small">
            Balances and expenses synced from QuickBooks Online
            @if($lastSyncedAt)
                &middot; last synced {{ $lastSyncedAt->toAppTz()->format('M j, Y g:i A') }}
            @endif
        </div>
    </div>
    <div class="col-auto">
        <form method="POST" action="{{ route('qbo.financials.sync') }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-primary btn-sm" @unless($connected) disabled @endunless>
                <i class="bi bi-arrow-repeat me-1"></i>Sync now
            </button>
        </form>
    </div>
</div>

@unless($connected)
    <div class="alert alert-warning" role="alert">
        Not connected to QuickBooks Online. Connect in
        <a href="{{ route('settings.integrations') }}">Settings &rarr; Integrations</a> to sync bank balances and expenses.
    </div>
@endunless

{{-- Bank balances --}}
<div class="row g-3 mb-2">
    <div class="col-md-3">
        <div class="card shadow-sm text-center h-100">
            <div class="card-body">
                <div class="text-muted small">Total Cash</div>
                <div class="fs-4 fw-bold">${{ number_format($totalCash, 2) }}</div>
                <div class="text-muted small">{{ $bankAccounts->where('active', true)->count() }} active account(s)</div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Bank Accounts</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>Account</th>
                    <th>Type</th>
                    <th class="text-end">Current Balance</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($bankAccounts as $account)
                    <tr class="{{ $account->active ? '' : 'text-muted' }}">
                        <td>{{ $account->name }}</td>
                        <td>{{ $account->account_sub_type ?? '—' }}</td>
                        <td class="text-end">
                            {{ $account->currency && $account->currency !== 'USD' ? $account->currency.' ' : '$' }}{{ number_format((float) $account->current_balance, 2) }}
                        </td>
                        <td class="text-end">
                            @unless($account->active)
                                <span class="badge bg-secondary">Inactive</span>
                            @endunless
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center text-muted py-3">No bank accounts synced yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Recent expenses --}}
<div class="card shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        Recent Expenses
        <span class="text-muted small fw-normal">(latest {{ $expenses->count() }})</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Payee</th>
                    <th>Paid From</th>
                    <th>Method</th>
                    <th>Memo</th>
                    <th class="text-end">Amount</th>
                </tr>
            </thead>
            <tbody>
                @forelse($expenses as $expense)
                    <tr>
                        <td class="text-nowrap">{{ $expense->txn_date?->format('M j, Y') ?? '—' }}</td>
                        <td>{{ $expense->payee_name ?? '—' }}</td>
                        <td>{{ $expense->account_name ?? '—' }}</td>
                        <td>{{ $expense->payment_type ?? '—' }}</td>
                        <td class="text-truncate" style="max-width: 20rem;">{{ $expense->memo }}</td>
                        <td class="text-end text-nowrap">
                            {{ $expense->currency && $expense->currency !== 'USD' ? $expense->currency.' ' : '$' }}{{ number_format((float) $expense->total_amount, 2) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-3">No expenses synced yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
