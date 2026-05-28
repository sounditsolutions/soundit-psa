@extends('layouts.app')

@section('title', $contractor->name . ' — Time Pool')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="section-title mb-0">
            <a href="{{ route('settings.staff.index') }}" class="text-decoration-none text-muted">Staff</a>
            <i class="bi bi-chevron-right small mx-1"></i>
            {{ $contractor->name }} — Time Pool
        </h4>
    </div>
    <a href="{{ route('settings.staff.edit', $contractor) }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-pencil me-1"></i>Edit Staff
    </a>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Summary Cards --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card card-static shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small mb-1">Hours Purchased</div>
                <div class="fs-4 fw-bold text-success">{{ number_format($creditTotal, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-static shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small mb-1">Adjustments</div>
                <div class="fs-4 fw-bold {{ $debitTotal > 0 ? 'text-danger' : 'text-muted' }}">
                    {{ $debitTotal > 0 ? '-' : '' }}{{ number_format($debitTotal, 2) }}
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-static shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small mb-1">Hours Consumed</div>
                <div class="fs-4 fw-bold text-primary">{{ number_format($consumed, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-lg-3">
        <div class="card card-static shadow-sm h-100">
            <div class="card-body text-center">
                <div class="text-muted small mb-1">Current Balance</div>
                <div class="fs-4 fw-bold {{ $balance < 0 ? 'text-danger' : ($balance < 4 ? 'text-warning' : 'text-success') }}">
                    {{ number_format($balance, 2) }} hrs
                    @if($balance < 0)
                        <i class="bi bi-exclamation-triangle-fill small" title="Negative balance"></i>
                    @endif
                </div>
                @if($burnRate > 0)
                    <div class="text-muted small mt-1">
                        {{ number_format($burnRate, 1) }} hrs this month
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    {{-- Left column: Time entries + Transactions --}}
    <div class="col-lg-8">
        {{-- Time Entries --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header">
                <i class="bi bi-clock me-2"></i>Time Entries
                @if($timeEntries->isNotEmpty())
                    <span class="badge bg-light text-dark ms-1">{{ $timeEntries->count() }}</span>
                @endif
            </div>
            @if($timeEntries->isEmpty())
                <div class="card-body text-muted text-center py-3">
                    No time entries recorded yet.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Ticket</th>
                                <th>Client</th>
                                <th class="text-end">Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($timeEntries as $entry)
                                <tr>
                                    <td class="small text-nowrap">
                                        {{ $entry->noted_at?->toAppTz()->format('M j, Y') ?? '-' }}
                                    </td>
                                    <td class="small">
                                        @if($entry->ticket)
                                            <a href="{{ route('tickets.show', $entry->ticket) }}">
                                                T-{{ $entry->ticket->id }}
                                            </a>
                                            <span class="text-muted ms-1">{{ \Illuminate\Support\Str::limit($entry->ticket->subject, 40) }}</span>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                    <td class="small">
                                        {{ $entry->ticket?->client?->name ?? '-' }}
                                    </td>
                                    <td class="small text-end text-danger">
                                        {{ $entry->formattedTime }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Credit/Debit Transactions --}}
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-journal-text me-2"></i>Transactions
                @if($transactions->isNotEmpty())
                    <span class="badge bg-light text-dark ms-1">{{ $transactions->count() }}</span>
                @endif
            </div>
            @if($transactions->isEmpty())
                <div class="card-body text-muted text-center py-3">
                    No transactions recorded yet. Use the form to add an initial balance or credit hours.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Description</th>
                                <th class="text-end">Hours</th>
                                <th class="d-none d-md-table-cell">Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $txn)
                                <tr>
                                    <td class="small text-nowrap">
                                        <span class="me-1 {{ $txn->hours >= 0 ? 'text-success' : 'text-danger' }}">
                                            <i class="bi {{ $txn->hours >= 0 ? 'bi-plus-circle' : 'bi-dash-circle' }}"></i>
                                        </span>
                                        {{ $txn->date->toAppTz()->format('M j, Y') }}
                                    </td>
                                    <td class="small">
                                        <span class="badge {{ $txn->source->badgeClass() }}">{{ $txn->source->label() }}</span>
                                    </td>
                                    <td class="small">{{ $txn->description }}</td>
                                    <td class="small text-end fw-semibold {{ $txn->hours >= 0 ? 'text-success' : 'text-danger' }}">
                                        {{ $txn->formattedHours() }}
                                    </td>
                                    <td class="small d-none d-md-table-cell">{{ $txn->recorder?->name ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- Right column: Manual adjustment form --}}
    <div class="col-lg-4">
        <div class="card card-static shadow-sm">
            <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Add Transaction</div>
            <div class="card-body">
                <form method="POST" action="{{ route('contractors.time-pool.store', $contractor) }}">
                    @csrf
                    <div class="mb-3">
                        <label for="source" class="form-label">Type</label>
                        <select name="source" id="source" class="form-select @error('source') is-invalid @enderror" required>
                            @foreach($sources as $source)
                                <option value="{{ $source->value }}" {{ old('source') === $source->value ? 'selected' : '' }}>
                                    {{ $source->label() }}
                                </option>
                            @endforeach
                        </select>
                        @error('source')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="hours" class="form-label">Hours</label>
                        <input type="number" name="hours" id="hours" step="0.25" min="0.01" max="9999"
                               class="form-control @error('hours') is-invalid @enderror"
                               value="{{ old('hours') }}" required>
                        @error('hours')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="description" class="form-label">Description <span class="text-danger">*</span></label>
                        <textarea name="description" id="description" rows="2"
                                  class="form-control @error('description') is-invalid @enderror"
                                  placeholder="e.g., Purchased 10 hours — Acme invoice #1234"
                                  required>{{ old('description') }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg me-1"></i>Add Transaction
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
