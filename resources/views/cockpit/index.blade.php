@extends('layouts.app')

@section('title', 'Cockpit')

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-robot me-2"></i>Cockpit</h1>
    <span class="text-muted small">{{ $drafts->count() }} awaiting approval · {{ $flagged->count() }} flagged · {{ $needs->count() }} need you</span>
</div>

{{-- APPROVAL QUEUE --}}
<h2 class="h6 text-muted text-uppercase mb-2">Awaiting your approval</h2>
@forelse ($drafts as $run)
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            {{-- Header: ticket link, client badge, action badge, timestamp (common to all) --}}
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
                <a href="{{ route('tickets.show', $run->ticket_id) }}" class="fw-semibold text-decoration-none">
                    {{ optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id }}
                </a>
                @if($run->ticket?->client)
                    <span class="badge bg-light text-dark border">{{ $run->ticket->client->name }}</span>
                @endif
                @php
                    [$badgeClass, $badgeLabel] = match ($run->action_type) {
                        'propose_close'      => ['bg-warning text-dark', 'Proposed close'],
                        'propose_resolution' => ['bg-info',              'Proposed resolution'],
                        default              => ['bg-primary',           'Reply'],
                    };
                @endphp
                <span class="badge {{ $badgeClass }}">{{ $badgeLabel }}</span>
                <span class="ms-auto text-muted">{{ optional($run->created_at)->diffForHumans() }}</span>
            </div>

            @if ($run->action_type === 'propose_close')
                {{-- PROPOSE-CLOSE ARM: read-only reason; no body sent to client --}}
                <p class="text-muted small mb-1">Reason (not sent to the client — this closes the ticket silently):</p>
                <p class="form-control-plaintext border rounded p-2 mb-2 bg-light small">{{ $run->proposed_content }}</p>
                @if ($run->confidence)
                    <p class="text-muted small mb-2">Confidence: {{ number_format($run->confidence * 100, 1) }}%</p>
                @endif

                <div class="d-flex gap-2">
                    <form id="approve-{{ $run->id }}" method="POST" action="{{ route('cockpit.approve', $run) }}">
                        @csrf
                        <button type="submit" class="btn btn-warning"><i class="bi bi-archive me-1"></i>Approve close</button>
                    </form>
                    <form method="POST" action="{{ route('cockpit.deny', $run) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Hold it</button>
                    </form>
                </div>
            @else
                {{-- REPLY/RESOLUTION ARM: editable send text, disclosure notice --}}
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
            @endif

            {{-- CORRECTION LANE — operator can decline with context or add context & re-assess --}}
            <div class="mt-3 border-top pt-3">
                @if(data_get($run->proposed_meta, 'informed_by_correction'))
                    <p class="text-muted small mb-2"><i class="bi bi-arrow-repeat me-1"></i>↻ Re-assessed from your correction.</p>
                @endif
                <form method="POST" action="{{ route('cockpit.correct', $run) }}">
                    @csrf
                    <label class="form-label small text-muted mb-1" for="correction-{{ $run->id }}">
                        Correction or context for the assistant:
                    </label>
                    <textarea class="form-control form-control-sm mb-2" id="correction-{{ $run->id }}" name="correction" rows="2" placeholder="e.g. client is on a no-auto-close contract"></textarea>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1"></i>Decline &amp; correct</button>
                        <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i>Add context &amp; re-assess</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@empty
    <p class="text-muted">Nothing waiting — you're clear.</p>
@endforelse

{{-- FLAGGED FOR ATTENTION (Increment H) — held notices, NOT executable proposals. --}}
@if($flagged->isNotEmpty())
    <h2 class="h6 text-muted text-uppercase mt-4 mb-2">Flagged for your attention</h2>
    @foreach ($flagged as $run)
        <div class="card shadow-sm mb-3 border-start border-warning border-3">
            <div class="card-body">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2 small">
                    <a href="{{ route('tickets.show', $run->ticket_id) }}" class="fw-semibold text-decoration-none">
                        {{ optional($run->ticket)->subject ?? 'Ticket #'.$run->ticket_id }}
                    </a>
                    @if($run->ticket?->client)
                        <span class="badge bg-light text-dark border">{{ $run->ticket->client->name }}</span>
                    @endif
                    @php($flagCategory = \App\Enums\FlagAttentionCategory::fromInput(($run->proposed_meta ?? [])['category'] ?? null))
                    <span class="badge bg-warning text-dark">{{ $flagCategory->label() }}</span>
                    <span class="ms-auto text-muted">{{ optional($run->created_at)->diffForHumans() }}</span>
                </div>

                <p class="text-muted small mb-1">Why the assistant flagged this (it took no action on the ticket):</p>
                <p class="form-control-plaintext border rounded p-2 mb-2 bg-light small">{{ $run->proposed_content }}</p>

                <div class="d-flex gap-2">
                    <form method="POST" action="{{ route('cockpit.acknowledge', $run) }}">
                        @csrf
                        <button type="submit" class="btn btn-warning"><i class="bi bi-check2 me-1"></i>I’ve got it</button>
                    </form>
                    <form method="POST" action="{{ route('cockpit.dismiss', $run) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary"><i class="bi bi-x-lg me-1"></i>Dismiss</button>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
@endif

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
