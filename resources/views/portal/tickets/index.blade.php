@extends('portal.layouts.app')

@section('title', 'Tickets - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Tickets</h4>
    <a href="{{ route('portal.tickets.create') }}" class="btn btn-accent">
        <i class="bi bi-plus-lg me-1"></i>New Ticket
    </a>
</div>

{{-- Search + Tabs --}}
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="row align-items-center">
            <div class="col-md-6">
                <ul class="nav nav-pills nav-sm">
                    <li class="nav-item">
                        <a class="nav-link {{ $tab === 'open' ? 'active' : '' }}" href="{{ route('portal.tickets.index', ['tab' => 'open', 'search' => $search]) }}">Open</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ $tab === 'closed' ? 'active' : '' }}" href="{{ route('portal.tickets.index', ['tab' => 'closed', 'search' => $search]) }}">Resolved / Closed</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ $tab === 'all' ? 'active' : '' }}" href="{{ route('portal.tickets.index', ['tab' => 'all', 'search' => $search]) }}">All</a>
                    </li>
                </ul>
            </div>
            <div class="col-md-6">
                <form method="GET" action="{{ route('portal.tickets.index') }}" class="d-flex">
                    <input type="hidden" name="tab" value="{{ $tab }}">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search by subject..." value="{{ $search }}">
                    <button type="submit" class="btn btn-sm btn-outline-primary ms-2">Search</button>
                </form>
            </div>
        </div>
    </div>
</div>

@php
    $priorityLabel = fn($p) => match($p) {
        App\Enums\TicketPriority::P1 => 'Critical',
        App\Enums\TicketPriority::P2 => 'High',
        App\Enums\TicketPriority::P3 => 'Normal',
        App\Enums\TicketPriority::P4 => 'Low',
        default => '—',
    };
@endphp

<div class="card">
    <div class="card-body p-0">
        @if($tickets->isEmpty())
            <p class="text-muted p-3 mb-0">No tickets found.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Created</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tickets as $ticket)
                            <tr class="cursor-pointer" onclick="window.location='{{ route('portal.tickets.show', $ticket) }}'">
                                <td class="text-muted">{{ $ticket->id }}</td>
                                <td>{{ Str::limit($ticket->subject, 80) }}</td>
                                <td><span class="badge {{ $ticket->status->badgeClass() }}">{{ $ticket->status->label() }}</span></td>
                                <td><span class="badge {{ $ticket->priority?->badgeClass() ?? 'bg-secondary' }}">{{ $priorityLabel($ticket->priority) }}</span></td>
                                <td class="text-muted small">{{ $ticket->opened_at?->toAppTz()->format('M j, Y') ?? $ticket->created_at->toAppTz()->format('M j, Y') }}</td>
                                <td class="text-muted small">{{ $ticket->updated_at->toAppTz()->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if($tickets->hasPages())
    <div class="mt-3">{{ $tickets->links() }}</div>
@endif
@endsection
