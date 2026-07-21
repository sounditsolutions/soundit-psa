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
    @elseif(\App\Support\AssistantConfig::shouldShowDisabledNotice())
        {{-- psa-322qo: the Assistant is OFF by default (psa-98dq), and Charlie's
             condition on that ruling was that it must not be a SILENT absence.
             The notice goes where the affordance was, so someone who used the
             Assistant finds an explanation at the button they used to click.
             Deliberately inert — a dead control that looks live is worse than
             none (psa-uw2o.4).

             psa-uw2o.13 F2: this was a bare @else, so an install with NO AI
             provider — which never wanted an Assistant — was told on every single
             ticket page that its Assistant was disabled. The predicate is
             AssistantConfig's now, shared with the topbar and the timeline.

             psa-uw2o.13 F3: the recovery path was in a title attribute, which
             keyboard and touch users cannot reach. It is visible text now. The
             chip itself stays inert and unclickable. --}}
        <span class="btn btn-sm btn-outline-secondary disabled" tabindex="-1" aria-disabled="true"
              data-assistant-disabled-notice="ticket-actions">
            <i class="bi bi-robot me-1" aria-hidden="true"></i>{{ \App\Support\AssistantConfig::disabledSummary() }}
        </span>
        <span class="small text-muted d-inline-flex align-items-center">
            {{ \App\Support\AssistantConfig::disabledRecovery() }}
        </span>
    @endif
    <button type="button" class="btn btn-sm btn-outline-secondary action-btn" data-action="status">
        <i class="bi bi-arrow-left-right me-1"></i>Change Status
    </button>
</div>
