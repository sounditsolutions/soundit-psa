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
    @endif
    <button type="button" class="btn btn-sm btn-outline-secondary action-btn" data-action="status">
        <i class="bi bi-arrow-left-right me-1"></i>Change Status
    </button>
</div>
