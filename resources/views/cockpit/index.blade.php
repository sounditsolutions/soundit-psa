@extends('layouts.app')

@section('title', 'Cockpit')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-robot me-2"></i>Cockpit</h1>
    <span class="text-muted small">{{ $drafts->count() }} awaiting approval · {{ $needs->count() }} need you</span>
</div>

{{-- APPROVAL QUEUE --}}
<h2 class="h6 text-muted text-uppercase mb-2">Awaiting your approval</h2>
@forelse ($drafts as $run)
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
                <a href="{{ route('tickets.show', $run->ticket_id) }}" class="fw-semibold text-decoration-none">
                    {{ optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id }}
                </a>
                @if($run->ticket?->client)
                    <span class="badge bg-light text-dark border">{{ $run->ticket->client->name }}</span>
                @endif
                <span class="badge {{ $run->action_type === 'propose_resolution' ? 'bg-info' : 'bg-primary' }}">
                    {{ $run->action_type === 'propose_resolution' ? 'Proposed resolution' : 'Reply' }}
                </span>
                <span class="ms-auto text-muted">{{ optional($run->created_at)->diffForHumans() }}</span>
            </div>

            {{-- SEND-TEXT-FIRST: the exact outgoing text, editable, ABOVE the Send button --}}
            <label class="form-label small text-muted mb-1" for="body-{{ $run->id }}">Message to the client (edit before sending):</label>
            <textarea class="form-control mb-1" id="body-{{ $run->id }}" name="body" rows="5" form="approve-{{ $run->id }}">{{ $run->proposed_content }}</textarea>
            <p class="text-muted small mb-2">
                <i class="bi bi-info-circle me-1"></i>A disclosure line ("— Sent by {{ \App\Support\TechnicianConfig::aiActorName() }}, an AI assistant for our team.") is added automatically.
            </p>

            {{-- the "why", collapsed --}}
            @if(!empty($run->proposed_meta['reasons']))
                <p class="text-muted small mb-2">Why: {{ implode(' · ', (array) $run->proposed_meta['reasons']) }}@if($run->confidence) (confidence {{ number_format($run->confidence, 2) }})@endif</p>
            @endif

            {{-- Two sibling forms side by side; textarea is bound to the approve form via the `form` attribute --}}
            <div class="d-flex gap-2">
                <form id="approve-{{ $run->id }}" method="POST" action="{{ route('cockpit.approve', $run) }}">
                    @csrf
                    <button type="submit" class="btn btn-success"><i class="bi bi-send me-1"></i>Send this</button>
                </form>
                <form method="POST" action="{{ route('cockpit.deny', $run) }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Hold it</button>
                </form>
            </div>
        </div>
    </div>
@empty
    <p class="text-muted">Nothing waiting — you're clear.</p>
@endforelse

{{-- NEEDS YOU --}}
@if($needs->isNotEmpty())
    <h2 class="h6 text-muted text-uppercase mt-4 mb-2">Needs you — the assistant couldn't draft these</h2>
    @foreach ($needs as $ticket)
        <a href="{{ route('tickets.show', $ticket->id) }}"
           class="d-block card shadow-sm mb-2 text-decoration-none text-reset cockpit-needs-card">
            <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2 small">
                <span class="fw-semibold">{{ $ticket->subject }}</span>
                @if($ticket->client)<span class="badge bg-light text-dark border">{{ $ticket->client->name }}</span>@endif
                <span class="ms-auto text-muted">{{ optional($ticket->updated_at)->diffForHumans() }}</span>
            </div>
        </a>
    @endforeach
@endif
@endsection
