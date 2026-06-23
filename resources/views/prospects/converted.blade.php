@extends('layouts.app')

@section('title', 'Client Converted — ' . $client->name)

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('clients.show', $client) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Go to {{ $client->name }}
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title mb-1">
            <i class="bi bi-check-circle-fill text-success me-2"></i>{{ $client->name }} is now an active client
        </h4>
        <p class="text-muted mb-0">
            Agreement signed. Complete the onboarding steps below to get them fully set up.
        </p>
    </div>
</div>

{{-- Onboarding prompts (prompts only — nothing below auto-runs) --}}
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-list-check me-2"></i>Onboarding checklist
    </div>
    <div class="list-group list-group-flush">
        <a href="{{ route('clients.edit', $client) }}"
           class="list-group-item list-group-item-action d-flex align-items-center gap-2">
            <i class="bi bi-person-gear text-primary fs-5"></i>
            <div>
                <div class="fw-semibold">Assign primary technician</div>
                <div class="text-muted small">Set the tech who owns this client relationship.</div>
            </div>
            <i class="bi bi-chevron-right ms-auto text-muted"></i>
        </a>

        <a href="{{ route('contracts.create', $client) }}"
           class="list-group-item list-group-item-action d-flex align-items-center gap-2">
            <i class="bi bi-file-earmark-text text-primary fs-5"></i>
            <div>
                <div class="fw-semibold">Create a Contract</div>
                <div class="text-muted small">Set up the service agreement and billing profile.</div>
            </div>
            <i class="bi bi-chevron-right ms-auto text-muted"></i>
        </a>

        <a href="{{ route('invoices.create', ['client_id' => $client->id]) }}"
           class="list-group-item list-group-item-action d-flex align-items-center gap-2">
            <i class="bi bi-receipt text-primary fs-5"></i>
            <div>
                <div class="fw-semibold">Create the seed-block first invoice</div>
                <div class="text-muted small">Issue the onboarding / deposit invoice. This is the client's first bill — agreement came first.</div>
            </div>
            <i class="bi bi-chevron-right ms-auto text-muted"></i>
        </a>

        <a href="{{ route('clients.portal', $client) }}"
           class="list-group-item list-group-item-action d-flex align-items-center gap-2">
            <i class="bi bi-display text-primary fs-5"></i>
            <div>
                <div class="fw-semibold">Provision RMM / M365</div>
                <div class="text-muted small">Link the client to their RMM platform and Microsoft 365 tenant.</div>
            </div>
            <i class="bi bi-chevron-right ms-auto text-muted"></i>
        </a>
    </div>
</div>

{{-- Original captured request — the context the closer needs --}}
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-ticket-perforated me-2"></i>Original captured request
        <span class="badge bg-secondary ms-1">{{ $openTickets->count() }} open</span>
    </div>

    @if($openTickets->isEmpty())
        <div class="card-body text-muted">
            No open tickets found for this prospect.
        </div>
    @else
        @foreach($openTickets as $ticket)
            <div class="card-body border-bottom">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <a href="{{ route('tickets.show', $ticket) }}" class="fw-semibold text-decoration-none">
                            {{ $ticket->display_id }}
                        </a>
                        <span class="ms-2 text-body">{{ $ticket->subject }}</span>
                    </div>
                    <span class="badge {{ $ticket->status->badgeClass() }} ms-2 flex-shrink-0">
                        {{ $ticket->status->label() }}
                    </span>
                </div>

                @if($ticket->description)
                    <div class="mb-2 text-muted small ps-2 border-start border-2">
                        {{ \Illuminate\Support\Str::limit(strip_tags($ticket->description), 400) }}
                    </div>
                @endif

                @if($ticket->notes->isNotEmpty())
                    <div class="mt-2">
                        <div class="small fw-semibold text-muted mb-1">Notes</div>
                        @foreach($ticket->notes->take(5) as $note)
                            <div class="small ps-2 border-start border-2 mb-1 text-muted">
                                {{ \Illuminate\Support\Str::limit(strip_tags($note->body ?? ''), 300) }}
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach

        <div class="card-footer">
            <a href="{{ route('clients.tickets', $client) }}" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-ticket me-1"></i>View all tickets
            </a>
        </div>
    @endif
</div>
@endsection
