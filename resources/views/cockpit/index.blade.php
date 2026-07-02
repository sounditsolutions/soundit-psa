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
                @if(!empty($run->proposed_meta['drafted_by']))
                    <p class="text-muted small mb-2">Drafted by: {{ $run->proposed_meta['drafted_by'] }}</p>
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

            {{-- CORRECTION LANE — decline the proposal with a note; the agent re-assesses the
                 ticket using it. One button (psa-gt66): the prior two did the same thing in v1. --}}
            <div class="mt-3 border-top pt-3">
                @if(data_get($run->proposed_meta, 'informed_by_correction'))
                    <p class="text-muted small mb-2"><i class="bi bi-arrow-repeat me-1"></i>↻ Re-assessed from your correction.</p>
                @endif
                <form method="POST" action="{{ route('cockpit.correct', $run) }}">
                    @csrf
                    <label class="form-label small text-muted mb-1" for="correction-{{ $run->id }}">
                        What did it miss or get wrong?
                    </label>
                    <textarea class="form-control form-control-sm mb-2" id="correction-{{ $run->id }}" name="correction" rows="2" placeholder="The agent will re-assess this ticket with your note (e.g. client is on a no-auto-close contract)."></textarea>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-repeat me-1"></i>Decline &amp; re-assess</button>
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
                    @php($flagMeta = $run->proposed_meta ?? [])
                    @php($flagCategory = \App\Enums\FlagAttentionCategory::fromInput($flagMeta['category'] ?? null))
                    @php($suppressedEscalation = ($flagMeta['escalation']['status'] ?? null) === 'suppressed')
                    <span class="badge bg-warning text-dark">{{ $flagCategory->label() }}</span>
                    @if($suppressedEscalation)
                        <span class="badge bg-info text-dark">Not re-pinged</span>
                    @endif
                    <span class="ms-auto text-muted">{{ optional($run->created_at)->diffForHumans() }}</span>
                </div>

                <p class="text-muted small mb-1">Why the assistant flagged this (it took no action on the ticket):</p>
                <p class="form-control-plaintext border rounded p-2 mb-2 bg-light small">{{ $run->proposed_content }}</p>
                @if($suppressedEscalation)
                    <p class="text-muted small mb-2">
                        Not re-pinged: {{ $flagMeta['escalation']['suppression_reason'] ?? 'same client already has human attention' }}
                    </p>
                @endif

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

{{-- INTAKE — possible duplicate suggestions the AI held for operator calibration (psa-xcyo).
     No merge action: merge is deferred. This lane is a calibration/observation surface only.
     Only rendered when there are held suggestions so the page is byte-identical when quiet. --}}
@if ($intake->isNotEmpty())
    <h2 class="h6 text-muted text-uppercase mt-4 mb-2">Intake — possible duplicates the AI flagged (review)</h2>
    @foreach ($intake as $run)
        @php($meta = $run->proposed_meta ?? [])
        @php($isCall = ($meta['source'] ?? null) === 'call')
        <div class="card mb-2">
            <div class="card-body">
                <div>{{ $isCall ? '📞 Call → ticket' : 'New ticket' }} <strong>#{{ $run->ticket?->id }}</strong>
                    looks like the same issue as open ticket <strong>#{{ $meta['suggested_ticket_id'] ?? '?' }}</strong>
                    ({{ (int) round(($meta['confidence'] ?? 0) * 100) }}% confidence)</div>
                <div class="text-muted small">{{ $run->proposed_content }}</div>
                {{-- No merge action: merge is deferred. This is a calibration signal. --}}
                <form method="POST" action="{{ route('cockpit.intake-dismiss', $run) }}" class="mt-2">
                    @csrf
                    <button class="btn btn-sm btn-outline-secondary">Dismiss</button>
                </form>
            </div>
        </div>
    @endforeach
@endif

{{-- INTAKE — suspected spam calls the AI flagged (psa-xcyo Task 6b).
     Only rendered when non-empty so the page is byte-identical when quiet. --}}
@if ($intakeSpam->isNotEmpty())
    <h2 class="h6 text-muted text-uppercase mt-4 mb-2">Intake — suspected spam calls (block or dismiss)</h2>
    @foreach ($intakeSpam as $call)
        <div class="card mb-2">
            <div class="card-body">
                <div>Call from <strong>{{ $call->from_number }}</strong> looks like spam
                    ({{ (int) round(($call->intake_spam_score ?? 0) * 100) }}% confidence)</div>
                <div class="text-muted small">{{ \Illuminate\Support\Str::limit($call->call_summary, 200) }}</div>
                <div class="d-flex gap-2 mt-2">
                    <form method="POST" action="{{ route('cockpit.intake-spam-block', $call) }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-danger">Mark followed-up + block #</button>
                    </form>
                    <form method="POST" action="{{ route('prospects.dismiss', $call) }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary">Not spam — dismiss</button>
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
