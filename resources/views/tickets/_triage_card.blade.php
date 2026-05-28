{{-- AI Triage sidebar card --}}
@php
    $triageRun = $ticket->triageRuns()->first();
    $classification = $triageRun?->classification();
    $triageEnabled = \App\Support\TriageConfig::isEnabled();
@endphp

@if($triageRun || $triageEnabled)
<div class="card shadow-sm mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-robot me-2"></i>AI Triage</span>
        @if($triageRun)
            @if($triageRun->isRunning())
                <span class="badge bg-info">Running...</span>
            @elseif($triageRun->isComplete())
                <span class="badge bg-success">Complete</span>
            @elseif($triageRun->hasFailed())
                <span class="badge bg-danger">Failed</span>
            @endif
        @endif
    </div>
    <div class="card-body py-2">
        @if($triageRun && $classification)
            {{-- Classification badge --}}
            <div class="mb-2">
                @php
                    $typeBadge = match($classification['client_type'] ?? '') {
                        'managed_services' => 'bg-success',
                        'break_fix' => 'bg-warning text-dark',
                        'no_contract' => 'bg-danger',
                        default => 'bg-secondary',
                    };
                    $typeLabel = match($classification['client_type'] ?? '') {
                        'managed_services' => 'Managed',
                        'break_fix' => 'Break/Fix',
                        'no_contract' => 'No Contract',
                        default => 'Unknown',
                    };
                @endphp
                <span class="badge {{ $typeBadge }}">{{ $typeLabel }}</span>
                @if($classification['work_covered_by_managed'] ?? false)
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Covered</span>
                @else
                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-circle me-1"></i>Not Covered</span>
                @endif
            </div>

            {{-- Reasoning (collapsible) --}}
            @if($classification['reasoning'] ?? null)
                <div class="small text-muted mb-2">
                    <a class="text-decoration-none" data-bs-toggle="collapse" href="#triageReasoning">
                        <i class="bi bi-chevron-right me-1"></i>Reasoning
                    </a>
                    <div class="collapse" id="triageReasoning">
                        <div class="mt-1 p-2 bg-light rounded small">{{ $classification['reasoning'] }}</div>
                    </div>
                </div>
            @endif
        @elseif($triageRun && !$classification)
            <div class="small text-muted mb-2">
                Stages: {{ implode(', ', $triageRun->stages_completed ?? []) ?: 'None' }}
            </div>
        @endif

        @if($triageRun)
            {{-- Run metadata --}}
            <div class="small text-muted mb-2">
                <i class="bi bi-clock me-1"></i>{{ $triageRun->completed_at?->diffForHumans() ?? $triageRun->created_at->diffForHumans() }}
                @if($triageRun->duration_ms)
                    ({{ number_format($triageRun->duration_ms / 1000, 1) }}s)
                @endif
                @php $tokens = $triageRun->ai_tokens_used; @endphp
                @if($tokens)
                    &middot; {{ number_format(($tokens['input_tokens'] ?? 0) + ($tokens['output_tokens'] ?? 0)) }} tokens
                @endif
            </div>

            @if($triageRun->errors)
                <div class="small text-danger mb-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>{{ count($triageRun->errors) }} error(s)
                </div>
            @endif

            {{-- Feedback toggle --}}
            @if($triageRun->isComplete())
                <div id="triageFeedback" class="border-top pt-2 mt-2">
                    @if($triageRun->feedback_correct !== null)
                        {{-- Feedback already submitted --}}
                        <div class="d-flex align-items-center gap-2">
                            @if($triageRun->feedback_correct)
                                <span class="badge bg-success"><i class="bi bi-hand-thumbs-up-fill me-1"></i>Correct</span>
                            @else
                                <span class="badge bg-danger"><i class="bi bi-hand-thumbs-down-fill me-1"></i>Incorrect</span>
                            @endif
                            <a href="#" class="small text-muted" onclick="clearTriageFeedback({{ $triageRun->id }}); return false;">clear</a>
                        </div>
                        @if($triageRun->feedback_note)
                            <div class="small text-muted mt-1">{{ $triageRun->feedback_note }}</div>
                        @endif
                    @else
                        {{-- No feedback yet --}}
                        <div id="feedbackButtons" class="d-flex align-items-center gap-1">
                            <span class="small text-muted me-1">Accurate?</span>
                            <button type="button" class="btn btn-outline-success btn-sm py-0 px-1" title="Correct"
                                    onclick="submitTriageFeedback({{ $triageRun->id }}, true)">
                                <i class="bi bi-hand-thumbs-up"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-sm py-0 px-1" title="Incorrect"
                                    onclick="showFeedbackNote({{ $triageRun->id }})">
                                <i class="bi bi-hand-thumbs-down"></i>
                            </button>
                        </div>
                        <div id="feedbackNoteForm" class="d-none mt-2">
                            <input type="text" id="feedbackNoteInput" class="form-control form-control-sm mb-1"
                                   placeholder="What was wrong?" maxlength="1000">
                            <button type="button" class="btn btn-danger btn-sm py-0 px-2"
                                    onclick="submitTriageFeedback({{ $triageRun->id }}, false, document.getElementById('feedbackNoteInput').value)">
                                Submit
                            </button>
                        </div>
                    @endif
                </div>
            @endif
        @endif

        {{-- Action buttons --}}
        @if($triageEnabled)
            <div class="d-flex gap-2 mt-2">
                <form method="POST" action="{{ route('tickets.triage', $ticket) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-robot me-1"></i>{{ $triageRun ? 'Re-triage' : 'Run Triage' }}
                    </button>
                </form>
                @if($ticket->status->isOpen())
                    <form method="POST" action="{{ route('tickets.review', $ticket) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-search me-1"></i>Review
                        </button>
                    </form>
                @endif
            </div>
        @endif
    </div>
</div>

@if($triageRun?->isComplete())
@push('scripts')
<script>
function submitTriageFeedback(runId, correct, note) {
    fetch(`/triage-runs/${runId}/feedback`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({
            feedback_correct: correct,
            feedback_note: note || null,
        }),
    })
    .then(r => { if (r.ok) location.reload(); })
    .catch(() => {});
}

function showFeedbackNote(runId) {
    document.getElementById('feedbackButtons').classList.add('d-none');
    document.getElementById('feedbackNoteForm').classList.remove('d-none');
    document.getElementById('feedbackNoteInput').focus();
}

function clearTriageFeedback(runId) {
    fetch(`/triage-runs/${runId}/feedback`, {
        method: 'DELETE',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
    })
    .then(r => { if (r.ok) location.reload(); })
    .catch(() => {});
}
</script>
@endpush
@endif
@endif
