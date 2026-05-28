@extends('layouts.app')

@section('title', 'Call Detail')

@section('content')
<div class="row mb-4">
    <div class="col">
        <a href="{{ route('calls.index') }}" class="text-decoration-none small">
            <i class="bi bi-arrow-left me-1"></i>Back to Call Log
        </a>
        <h4 class="section-title mt-2">Call Detail</h4>
    </div>
    <div class="col-auto">
        <span class="badge {{ $call->status->badgeClass() }} fs-6">{{ $call->status->label() }}</span>
    </div>
</div>

<div class="row g-4">
    {{-- Call Info --}}
    <div class="col-md-6">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-telephone me-2"></i>Call Information
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <th class="text-muted" style="width: 140px">From</th>
                        <td>
                            {{ \App\Support\PhoneNumber::format($call->from_number) }}
                            @if($call->from_number)
                            <a href="#" data-phone="{{ $call->from_number }}" class="ms-1 text-decoration-none" title="Call">
                                <i class="bi bi-telephone-outbound text-muted small"></i>
                            </a>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted">To</th>
                        <td>{{ $call->to_number ?? '—' }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Direction</th>
                        <td>{{ $call->direction->label() }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Started</th>
                        <td>{{ $call->started_at?->toAppTz()->format('d M Y H:i T') ?? '—' }}</td>
                    </tr>
                    @if($call->answered_at)
                    <tr>
                        <th class="text-muted">Answered</th>
                        <td>{{ $call->answered_at->toAppTz()->format('H:i') }}</td>
                    </tr>
                    @endif
                    @if($call->ended_at)
                    <tr>
                        <th class="text-muted">Ended</th>
                        <td>{{ $call->ended_at->toAppTz()->format('H:i') }}</td>
                    </tr>
                    @endif
                    <tr>
                        <th class="text-muted">Duration</th>
                        <td>
                            @if($call->duration !== null)
                                {{ gmdate($call->duration >= 3600 ? 'H:i:s' : 'i:s', $call->duration) }}
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <th class="text-muted">Answered By</th>
                        <td><x-user-badge :user="$call->answeredBy" /></td>
                    </tr>
                </table>
            </div>
        </div>

        {{-- Call Notes --}}
        <div class="card card-static shadow-sm mt-3">
            <div class="card-header">
                <i class="bi bi-journal-text me-2"></i>Call Notes
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('calls.update-notes', $call) }}">
                    @csrf @method('PATCH')
                    <textarea name="notes" class="form-control form-control-sm mb-2" rows="3"
                              placeholder="Jot down notes from this call...">{{ old('notes', $call->notes) }}</textarea>
                    <button type="submit" class="btn btn-outline-primary btn-sm">Save Notes</button>
                </form>
            </div>
        </div>

        {{-- Recording --}}
        @if($call->recording_url)
        <div class="card card-static shadow-sm mt-3">
            <div class="card-header">
                <i class="bi bi-mic me-2"></i>Recording
            </div>
            <div class="card-body">
                <audio controls class="w-100" preload="none">
                    <source src="{{ route('calls.recording', $call) }}" type="audio/mp3">
                    Your browser does not support audio playback.
                </audio>
                @if($call->recording_duration)
                <small class="text-muted mt-1 d-block">
                    Duration: {{ gmdate($call->recording_duration >= 3600 ? 'H:i:s' : 'i:s', $call->recording_duration) }}
                </small>
                @endif
            </div>
        </div>

        {{-- Transcription --}}
        <div class="card card-static shadow-sm mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div><i class="bi bi-file-earmark-text me-2"></i>Transcription</div>
                @if($call->transcription_status)
                    <span class="badge {{ $call->transcription_status->badgeClass() }}">{{ $call->transcription_status->label() }}</span>
                @endif
            </div>
            <div class="card-body">
                @if($call->isTranscribed())
                    {{-- Structured badges --}}
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        @if($call->sentiment_score)
                            @php
                                $sentimentClass = $call->sentiment_score >= 7 ? 'bg-success' : ($call->sentiment_score >= 4 ? 'bg-warning text-dark' : 'bg-danger');
                            @endphp
                            <span class="badge {{ $sentimentClass }}">
                                <i class="bi bi-emoji-smile me-1"></i>Sentiment: {{ $call->sentiment_score }}/10
                            </span>
                        @endif
                        @if($call->charge_classification)
                            <span class="badge {{ $call->charge_classification->badgeClass() }}">
                                {{ $call->charge_classification->label() }}
                            </span>
                        @endif
                    </div>

                    {{-- Call Summary --}}
                    @if($call->call_summary)
                    <div class="mb-3">
                        {!! App\Helpers\MarkdownRenderer::render($call->call_summary) !!}
                    </div>
                    @endif

                    {{-- Next Steps --}}
                    @if($call->next_steps)
                    <div class="mb-3">
                        <strong>Next Steps</strong>
                        {!! App\Helpers\MarkdownRenderer::render($call->next_steps) !!}
                    </div>
                    @endif

                    {{-- Coaching notes (collapsed) --}}
                    @if($call->coaching_notes)
                    <div class="mb-3">
                        <a class="text-decoration-none small" data-bs-toggle="collapse" href="#coaching-notes" role="button" aria-expanded="false">
                            <i class="bi bi-mortarboard me-1"></i>Coaching Notes
                        </a>
                        <div class="collapse mt-2" id="coaching-notes">
                            <div class="card card-body bg-light small">
                                {!! App\Helpers\MarkdownRenderer::render($call->coaching_notes) !!}
                            </div>
                        </div>
                    </div>
                    @endif

                    {{-- Cleaned transcript (collapsed, prefer over raw) --}}
                    @if($call->cleaned_transcript || $call->transcription)
                    <div class="mb-3">
                        <a class="text-decoration-none small" data-bs-toggle="collapse" href="#raw-transcript" role="button" aria-expanded="false">
                            <i class="bi bi-file-text me-1"></i>Transcript
                        </a>
                        <div class="collapse mt-2" id="raw-transcript">
                            <pre class="bg-light p-3 rounded small" style="white-space: pre-wrap; max-height: 400px; overflow-y: auto;">{{ $call->cleaned_transcript ?? $call->transcription }}</pre>
                        </div>
                    </div>
                    @endif

                    <div class="d-flex flex-wrap gap-2 mt-2">

                        {{-- Re-transcribe button --}}
                        <form method="POST" action="{{ route('calls.transcribe', $call) }}">
                            @csrf
                            <button type="submit" class="btn btn-outline-secondary btn-sm"
                                    onclick="return confirm('Re-transcribe this call? This will replace the existing transcription.')">
                                <i class="bi bi-arrow-clockwise me-1"></i>Re-transcribe
                            </button>
                        </form>
                    </div>

                @elseif($call->isTranscribing())
                    <div class="text-center py-3">
                        <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                        <span class="text-muted">Transcription in progress...</span>
                    </div>

                @elseif($call->transcription_status === \App\Enums\TranscriptionStatus::Failed)
                    <div class="alert alert-danger mb-2">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Transcription failed{{ $call->transcription_error ? ': ' . $call->transcription_error : '' }}
                    </div>
                    <form method="POST" action="{{ route('calls.transcribe', $call) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-warning btn-sm">
                            <i class="bi bi-arrow-clockwise me-1"></i>Retry
                        </button>
                    </form>

                @elseif(\App\Support\TranscriptionConfig::isConfigured())
                    <form method="POST" action="{{ route('calls.transcribe', $call) }}">
                        @csrf
                        <button type="submit" class="btn btn-accent btn-sm">
                            <i class="bi bi-file-earmark-text me-1"></i>Transcribe
                        </button>
                    </form>

                @else
                    <p class="text-muted small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        <a href="{{ route('settings.integrations') }}">Configure transcription</a> in Settings to enable.
                    </p>
                @endif
            </div>
        </div>
        @endif
    </div>

    {{-- Client & Ticket --}}
    <div class="col-md-6">
        {{-- Client Info --}}
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-building me-2"></i>Client
            </div>
            <div class="card-body">
                @if($call->client)
                    <h6>
                        <a href="{{ route('clients.show', $call->client) }}" class="text-decoration-none">
                            {{ $call->client->name }}
                        </a>
                    </h6>
                    @if($call->person)
                        <small class="text-muted d-block">{{ $call->person->fullName }}</small>
                    @endif
                @elseif($call->halo_client_name)
                    <h6>{{ $call->halo_client_name }}</h6>
                @else
                    <p class="text-muted mb-2">
                        <i class="bi bi-question-circle me-1"></i>Client not resolved
                    </p>
                    <div class="border-top pt-2">
                    @if($callHistory->isNotEmpty())
                            <small class="text-muted d-block mb-2">
                                <i class="bi bi-clock-history me-1"></i>Previous calls ({{ $callHistory->count() }})
                            </small>
                            @foreach($callHistory as $prev)
                                <div class="d-flex align-items-start gap-2 mb-2 small">
                                    <i class="bi bi-telephone-{{ $prev->direction->value === 'inbound' ? 'inbound' : 'outbound' }} text-muted mt-1"></i>
                                    <div class="flex-grow-1">
                                        <a href="{{ route('calls.show', $prev) }}" class="text-decoration-none">
                                            @if($prev->client)
                                                <strong>{{ $prev->client->name }}</strong>
                                                @if($prev->person)
                                                    <span class="text-muted">— {{ $prev->person->fullName }}</span>
                                                @endif
                                            @else
                                                <span class="text-muted">Unresolved</span>
                                            @endif
                                        </a>
                                        <div class="text-muted" style="font-size: 0.75rem;">
                                            {{ $prev->started_at?->diffForHumans() }}
                                            @if($prev->answeredBy)
                                                — {{ $prev->answeredBy->name }}
                                            @endif
                                            @if($prev->duration)
                                                — {{ gmdate($prev->duration >= 3600 ? 'H:i:s' : 'i:s', $prev->duration) }}
                                            @endif
                                            @if($prev->ticket)
                                                — <a href="{{ route('tickets.show', $prev->ticket) }}" class="text-decoration-none">{{ $prev->ticket->display_id }}</a>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                    @else
                        <small class="text-muted">
                            <i class="bi bi-clock-history me-1"></i>No previous calls from this number
                        </small>
                    @endif
                    </div>
                @endif

                @if($candidates->count() > 1)
                    <div class="mt-2 pt-2 border-top">
                        <small class="text-muted d-block mb-2">
                            <i class="bi bi-people me-1"></i>{{ $candidates->count() }} contacts match this number:
                        </small>
                        @foreach($candidates as $candidate)
                            <div class="d-flex justify-content-between align-items-center mb-1 p-2 rounded {{ $candidate->id === $call->person_id ? 'bg-primary bg-opacity-10' : '' }}">
                                <div>
                                    <span class="{{ $candidate->id === $call->person_id ? 'fw-bold' : '' }}">
                                        {{ $candidate->fullName }}
                                    </span>
                                    @if($candidate->job_title)
                                        <small class="text-muted ms-1">{{ $candidate->job_title }}</small>
                                    @endif
                                    @if($candidate->is_primary)
                                        <span class="badge bg-warning text-dark ms-1" style="font-size: 0.65em">Primary</span>
                                    @endif
                                    @if($candidate->open_ticket_count > 0)
                                        <span class="badge bg-info ms-1" style="font-size: 0.65em">{{ $candidate->open_ticket_count }} open</span>
                                    @endif
                                    <small class="text-muted d-block">{{ $candidate->client?->name }}</small>
                                </div>
                                @if($candidate->id !== $call->person_id)
                                    <form method="POST" action="{{ route('calls.update-person', $call) }}">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="person_id" value="{{ $candidate->id }}">
                                        <button type="submit" class="btn btn-outline-primary btn-sm">Select</button>
                                    </form>
                                @else
                                    <span class="badge bg-primary">Current</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Manual caller override --}}
                <div class="mt-2 pt-2 border-top">
                    <a class="text-decoration-none small" data-bs-toggle="collapse" href="#manual-caller-override" role="button" aria-expanded="false">
                        <i class="bi bi-pencil me-1"></i>Change caller manually
                    </a>
                    <div class="collapse mt-2" id="manual-caller-override">
                        <form method="POST" action="{{ route('calls.update-person', $call) }}" id="manual-caller-form">
                            @csrf @method('PATCH')
                            <select id="override-client-select" class="form-select form-select-sm mb-2">
                                <option value="">Select client...</option>
                                @foreach($clients as $client)
                                    <option value="{{ $client->id }}" {{ $call->client_id == $client->id ? 'selected' : '' }}>
                                        {{ $client->name }}
                                    </option>
                                @endforeach
                            </select>
                            <select name="person_id" id="override-person-select" class="form-select form-select-sm mb-2" disabled required>
                                <option value="">Select contact...</option>
                            </select>
                            <button type="submit" class="btn btn-outline-primary btn-sm w-100" disabled id="override-submit-btn">
                                <i class="bi bi-check-lg me-1"></i>Set Caller
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        {{-- Ticket --}}
        <div class="card card-static shadow-sm mt-3">
            <div class="card-header">
                <i class="bi bi-ticket-perforated me-2"></i>Ticket
            </div>
            <div class="card-body">
                @if($call->ticket)
                    {{-- Ticket linked --}}
                    <div class="d-flex gap-2 mb-2">
                        <a href="{{ route('tickets.show', $call->ticket) }}" class="btn btn-primary btn-sm flex-fill">
                            <i class="bi bi-ticket-perforated me-1"></i>View Linked Ticket ({{ $call->ticket->display_id }})
                        </a>
                        <form method="POST" action="{{ route('calls.unlink-ticket', $call) }}">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                    onclick="return confirm('Unlink this call from the ticket?')">
                                <i class="bi bi-x-circle"></i>
                            </button>
                        </form>
                    </div>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <span class="badge {{ $call->is_billable ? 'bg-success' : 'bg-secondary' }}">
                            <i class="bi {{ $call->is_billable ? 'bi-currency-dollar' : 'bi-dash-circle' }} me-1"></i>{{ $call->is_billable ? 'Billable' : 'Non-billable' }}
                        </span>
                        <form method="POST" action="{{ route('calls.toggle-billable', $call) }}" class="flex-fill">
                            @csrf
                            <button type="submit" class="btn btn-sm w-100 {{ $call->is_billable ? 'btn-outline-secondary' : 'btn-outline-success' }}">
                                Mark {{ $call->is_billable ? 'Non-billable' : 'Billable' }}
                            </button>
                        </form>
                    </div>
                @else
                    {{-- No ticket — offer create or link --}}
                    <a href="{{ route('calls.create-ticket', $call) }}" class="btn btn-accent btn-sm w-100 mb-3">
                        <i class="bi bi-ticket-perforated me-1"></i>Create Ticket from Call
                    </a>
                    <hr class="my-2">

                    @if($recentTickets->isNotEmpty())
                    <p class="small text-muted mb-2">Or link to an existing ticket:</p>
                    <div class="list-group list-group-flush mb-3" style="max-height: 300px; overflow-y: auto;">
                        @foreach($recentTickets as $ticket)
                        <form method="POST" action="{{ route('calls.link-ticket', $call) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-2">
                            @csrf
                            <input type="hidden" name="ticket_id" value="{{ $ticket->id }}">
                            <div class="me-2" style="min-width: 0;">
                                <strong>{{ $ticket->display_id }}</strong>
                                <span class="badge {{ $ticket->status->badgeClass() }} ms-1">{{ $ticket->status->label() }}</span>
                                <div class="text-truncate small text-muted">{{ $ticket->subject }}</div>
                            </div>
                            <button type="submit" class="btn btn-sm btn-outline-primary flex-shrink-0">Link</button>
                        </form>
                        @endforeach
                    </div>
                    @else
                    <p class="text-muted small mb-2">No recent tickets for this client.</p>
                    @endif

                    <form method="POST" action="{{ route('calls.link-ticket', $call) }}" class="input-group input-group-sm">
                        @csrf
                        <input type="number" name="ticket_id" class="form-control" placeholder="Ticket ID" min="1" required>
                        <button type="submit" class="btn btn-primary">Link</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Follow-up --}}
        @if($call->needsFollowUp())
        <div class="card card-static shadow-sm mt-3 border-warning">
            <div class="card-body text-center">
                <p class="mb-2"><i class="bi bi-exclamation-triangle text-warning me-1"></i>This call needs follow-up</p>
                <form method="POST" action="{{ route('calls.mark-followed-up', $call) }}">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-outline-success">
                        <i class="bi bi-check-lg me-1"></i>Mark as Followed Up
                    </button>
                </form>
            </div>
        </div>
        @elseif($call->isFollowedUp())
        <div class="card card-static shadow-sm mt-3">
            <div class="card-body">
                <small class="text-muted">
                    <i class="bi bi-check-circle text-success me-1"></i>
                    Followed up by {{ $call->followedUpBy?->name ?? 'Unknown' }}
                    on {{ $call->followed_up_at->toAppTz()->format('d M Y H:i') }}
                </small>
            </div>
        </div>
        @endif

        {{-- Phone directory: block or allow this caller. Only for inbound calls with a parseable number. --}}
        @if($call->direction?->value === 'inbound' && $call->from_number)
            @php $directoryEntry = \App\Models\PhoneDirectoryEntry::lookup($call->from_number); @endphp
            <div class="card card-static shadow-sm mt-3">
                <div class="card-body text-center">
                    @if($directoryEntry?->isBlocked())
                        <span class="badge bg-danger mb-1"><i class="bi bi-shield-x me-1"></i>Caller is blocked</span>
                        <div><small class="text-muted">Future calls from this number will be hung up by the IVR.</small></div>
                        <a href="{{ route('phone-directory.index', ['tab' => 'blocked']) }}" class="btn btn-sm btn-link mt-1">Manage phone directory</a>
                    @elseif($directoryEntry?->isAllowed())
                        <span class="badge bg-success mb-1"><i class="bi bi-shield-check me-1"></i>Caller is allowed</span>
                        @if($directoryEntry->label)
                            <div class="small">{{ $directoryEntry->label }}</div>
                        @endif
                        <div><small class="text-muted">IVR rings this caller through with their label.</small></div>
                        <a href="{{ route('phone-directory.index', ['tab' => 'allowed']) }}" class="btn btn-sm btn-link mt-1">Manage phone directory</a>
                    @else
                        <div class="d-grid gap-2">
                            <form method="POST" action="{{ route('calls.block-caller', $call) }}"
                                  onsubmit="return confirm('Block {{ \App\Support\PhoneNumber::format($call->from_number) }} from calling our number?')">
                                @csrf
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-shield-x me-1"></i>Block this caller
                                </button>
                            </form>
                            <form method="POST" action="{{ route('calls.allow-caller', $call) }}"
                                  onsubmit="return promptAllow(this);">
                                @csrf
                                <input type="hidden" name="label" value="">
                                <button type="submit" class="btn btn-sm btn-outline-success">
                                    <i class="bi bi-shield-check me-1"></i>Allow this caller
                                </button>
                            </form>
                        </div>
                        <small class="text-muted d-block mt-2">Block hangs up future calls; Allow rings them through with a label.</small>
                        <script>
                            function promptAllow(form) {
                                const label = prompt('Label for this caller (spoken in greeting):', '');
                                if (label === null) return false;
                                form.querySelector('input[name=label]').value = label.trim();
                                return true;
                            }
                        </script>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>

@push('scripts')
<script>
(function() {
    const clientSelect = document.getElementById('override-client-select');
    const personSelect = document.getElementById('override-person-select');
    const submitBtn = document.getElementById('override-submit-btn');
    if (!clientSelect) return;

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

    clientSelect.addEventListener('change', function() {
        personSelect.innerHTML = '<option value="">Select contact...</option>';
        personSelect.disabled = true;
        submitBtn.disabled = true;

        if (!this.value) return;

        fetch('/api/clients/' + this.value + '/contacts', {
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' }
        })
        .then(r => r.json())
        .then(contacts => {
            personSelect.disabled = false;
            contacts.forEach(function(c) {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name + (c.email ? ' (' + c.email + ')' : '');
                personSelect.appendChild(opt);
            });
        })
        .catch(function() { personSelect.disabled = true; });
    });

    personSelect.addEventListener('change', function() {
        submitBtn.disabled = !this.value;
    });

    // Auto-load contacts if client is pre-selected
    if (clientSelect.value) {
        clientSelect.dispatchEvent(new Event('change'));
    }
})();
</script>
@endpush
@endsection
