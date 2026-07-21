{{-- Action button row: Note, Reply, Ask AI, Change Status --}}
<div class="d-flex flex-wrap gap-2 mb-3" id="actionButtons">
    <button type="button" class="btn btn-sm btn-outline-secondary action-btn" data-action="note">
        <i class="bi bi-sticky me-1"></i>Note
    </button>
    <button type="button" class="btn btn-sm btn-outline-secondary action-btn" data-action="reply">
        <i class="bi bi-envelope me-1"></i>Reply
    </button>
    @if(\App\Support\AssistantConfig::isEnabled())
        <button type="button" class="btn btn-sm btn-outline-secondary action-btn" data-action="ask-ai" id="askAiBtn">
            <i class="bi bi-robot me-1"></i>Ask AI
        </button>
    @else
        {{-- psa-322qo: the Assistant is OFF by default (psa-98dq), and Charlie's
             condition on that ruling was that it must not be a SILENT absence.
             The notice goes where the affordance was, so someone who used the
             Assistant finds an explanation at the button they used to click.
             Deliberately inert — a dead control that looks live is worse than
             none (psa-uw2o.4). --}}
        <span class="btn btn-sm btn-outline-secondary disabled" tabindex="-1" aria-disabled="true"
              title="Turn it on in Settings &rsaquo; Integrations &rsaquo; AI Assistant">
            <i class="bi bi-robot me-1"></i>AI Assistant is disabled
        </span>
    @endif
    <button type="button" class="btn btn-sm btn-outline-secondary action-btn" data-action="status">
        <i class="bi bi-arrow-left-right me-1"></i>Change Status
    </button>
</div>
