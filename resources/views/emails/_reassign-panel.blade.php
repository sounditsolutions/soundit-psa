{{-- Inline client reassignment panel (same pattern as ticket move) --}}
<div id="emailReassignPanel" class="d-none mt-2 p-2 border rounded bg-light" style="font-size: 0.85rem;">
    <form method="POST" action="{{ route('emails.reassign-client', $email) }}" id="emailReassignForm">
        @csrf
        @method('PATCH')
        <div class="mb-2">
            <input type="text" class="form-control form-control-sm" id="emailReassignSearch"
                   placeholder="Search clients..." autocomplete="off">
            <input type="hidden" name="client_id" id="emailReassignClientId" value="">
            <div id="emailReassignResults" class="list-group mt-1" style="max-height: 150px; overflow-y: auto;"></div>
        </div>
        <div class="mb-2 d-none" id="emailReassignContactGroup">
            <label class="form-label small mb-1">Contact (optional)</label>
            <select name="contact_id" class="form-select form-select-sm" id="emailReassignContactSelect">
                <option value="">Auto-detect from sender</option>
            </select>
        </div>
        @if($email->ticket_id)
        <div class="form-check mb-2">
            <input type="checkbox" name="update_ticket" value="1" class="form-check-input" id="emailReassignTicket" checked>
            <label class="form-check-label small" for="emailReassignTicket">Also update linked ticket</label>
        </div>
        @endif
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary btn-sm" id="emailReassignSubmit" disabled>Reassign</button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="emailReassignCancel">Cancel</button>
        </div>
    </form>
</div>
