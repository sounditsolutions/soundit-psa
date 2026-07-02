{{-- Reply action panel --}}
@php $contactEmail = $ticket->contact?->email; @endphp
<div class="action-panel d-none" id="actionReply">
    <form method="POST" action="{{ route('tickets.notes.store', $ticket) }}">
        @csrf
        <input type="hidden" name="note_type" value="reply">
        <input type="hidden" name="is_private" value="0">
        <div class="row g-2 mb-2">
            <div class="col-md-6">
                <div class="input-group input-group-sm">
                    <span class="input-group-text" style="width: 38px;">To</span>
                    <input type="email" name="to_email" class="form-control" id="replyToEmail"
                           value="{{ $contactEmail }}" placeholder="recipient@example.com"
                           list="contactEmails">
                </div>
            </div>
            <div class="col-md-6">
                <div class="input-group input-group-sm">
                    <span class="input-group-text" style="width: 38px;">Cc</span>
                    <input type="text" name="cc_emails" class="form-control"
                           placeholder="comma-separated emails">
                </div>
            </div>
        </div>
        <datalist id="contactEmails"></datalist>
        <div class="mb-2">
            <x-markdown-editor name="body" id="replyBody" rows="3" placeholder="Write a reply..." :required="true"
                :upload-url="route('tickets.attachments.store', $ticket)" />
        </div>
        <div class="d-flex flex-wrap align-items-center gap-3">
            @if(\App\Support\AiConfig::isConfigured())
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-outline-secondary" id="draftReplyBtn" title="AI Draft Reply">
                    <i class="bi bi-robot me-1"></i>Draft
                </button>
                <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split"
                        data-bs-toggle="dropdown" aria-expanded="false" title="Draft with instructions">
                    <span class="visually-hidden">Toggle instructions</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end p-2" style="min-width: 320px;" id="draftInstructionsDropdown">
                    <label class="form-label small mb-1">Instructions for AI</label>
                    <input type="text" class="form-control form-control-sm" id="draftInstructions"
                           placeholder="e.g., Tell them we replaced the drive" maxlength="500">
                    <button type="button" class="btn btn-outline-secondary btn-sm w-100 mt-2" id="draftWithInstructionsBtn">
                        <i class="bi bi-robot me-1"></i>Draft with instructions
                    </button>
                </div>
            </div>
            @endif
            <div class="input-group input-group-sm" style="width: 140px;">
                <span class="input-group-text"><i class="bi bi-clock"></i></span>
                <input type="text" name="time" class="form-control" placeholder="0h 15m" id="replyTimeInput">
            </div>
            <div class="input-group input-group-sm" style="width: auto;">
                <span class="input-group-text"><i class="bi bi-arrow-left-right"></i></span>
                <select name="new_status" class="form-select form-select-sm" id="replyStatusSelect" style="width: auto;">
                    <option value="">No change</option>
                    @foreach($ticket->status->allowedTransitions() as $s)
                        @if($s !== \App\Enums\TicketStatus::Closed)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="d-none" id="replyResolutionGroup">
                <input type="text" name="resolution" class="form-control form-control-sm"
                       placeholder="Brief resolution summary...">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Send</button>
        </div>
        <div class="mt-1 small">
            <span class="text-muted">
                <i class="bi bi-envelope me-1"></i>This reply will be emailed to the recipients above.
            </span>
        </div>
    </form>
</div>
