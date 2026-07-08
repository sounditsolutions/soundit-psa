@extends('layouts.app')

@section('title', 'Technician Time Report')

@section('content')
<div class="row mb-3">
    <div class="col">
        <h4 class="section-title mb-0">Technician Time Report</h4>
    </div>
</div>

<form method="GET" action="{{ route('reports.time') }}" class="mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-auto">
            <label class="form-label small text-muted mb-1">From</label>
            <input type="date" name="from" class="form-control" value="{{ $filters['from'] }}">
        </div>
        <div class="col-auto">
            <label class="form-label small text-muted mb-1">To</label>
            <input type="date" name="to" class="form-control" value="{{ $filters['to'] }}">
        </div>
        <div class="col-auto" style="min-width: 200px;">
            <label class="form-label small text-muted mb-1">Technician</label>
            <select name="user_id" class="form-select">
                <option value="">All technicians</option>
                @foreach($users as $u)
                    <option value="{{ $u->id }}" {{ $filters['user_id'] == $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto" style="min-width: 200px;">
            <label class="form-label small text-muted mb-1">Client</label>
            <select name="client_id" class="form-select">
                <option value="">All clients</option>
                @foreach($clients as $c)
                    <option value="{{ $c->id }}" {{ $filters['client_id'] == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-funnel me-1"></i>Filter
            </button>
        </div>
        @if($filters['user_id'] || $filters['client_id'] || $filters['from'] !== now()->startOfMonth()->format('Y-m-d'))
            <div class="col-auto">
                <a href="{{ route('reports.time') }}" class="btn btn-outline-secondary" title="Reset to defaults">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        @endif
    </div>
</form>

{{-- Summary cards --}}
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card shadow-sm card-static">
            <div class="card-body text-center">
                <div class="text-muted small">Total Hours</div>
                <div class="fs-3 fw-bold">{{ number_format($grandTotalMinutes / 60, 1) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm card-static">
            <div class="card-body text-center">
                <div class="text-muted small">Technicians</div>
                <div class="fs-3 fw-bold">{{ $summary->count() }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm card-static">
            <div class="card-body text-center">
                <div class="text-muted small">Tickets Touched</div>
                <div class="fs-3 fw-bold">{{ $summary->sum('ticket_count') }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card shadow-sm card-static">
            <div class="card-body text-center">
                <div class="text-muted small">Time Entries</div>
                <div class="fs-3 fw-bold">{{ $summary->sum('note_count') }}</div>
            </div>
        </div>
    </div>
</div>

{{-- Per-technician breakdown --}}
@if($summary->isEmpty())
    <div class="alert alert-info">
        No time entries found for the selected filters.
    </div>
@else
    <div class="card shadow-sm card-static">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th>Technician</th>
                        <th class="text-end">Hours</th>
                        <th class="text-end">Tickets</th>
                        <th class="text-end">Entries</th>
                        <th class="text-end">Avg/Entry</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($summary as $row)
                        <tr>
                            <td>
                                {{ $row->user_name }}
                                @if($row->is_contractor)
                                    <span class="badge bg-info text-dark ms-1">Contractor</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">{{ number_format($row->total_minutes / 60, 1) }}h</td>
                            <td class="text-end">{{ $row->ticket_count }}</td>
                            <td class="text-end">{{ $row->note_count }}</td>
                            <td class="text-end text-muted">{{ $row->note_count > 0 ? number_format($row->total_minutes / $row->note_count, 0) . 'm' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="table-light">
                    <tr class="fw-bold">
                        <td>Total</td>
                        <td class="text-end">{{ number_format($grandTotalMinutes / 60, 1) }}h</td>
                        <td class="text-end">{{ $summary->sum('ticket_count') }}</td>
                        <td class="text-end">{{ $summary->sum('note_count') }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
@endif
@endsection
