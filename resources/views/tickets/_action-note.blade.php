{{-- Note action panel --}}
<div class="action-panel d-none" id="actionNote">
    <form method="POST" action="{{ route('tickets.notes.store', $ticket) }}">
        @csrf
        <input type="hidden" name="note_type" value="note">
        <div class="mb-2">
            <x-markdown-editor name="body" id="noteBody" rows="3" placeholder="Add a note..." :required="true"
                :upload-url="route('tickets.attachments.store', $ticket)" />
        </div>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="form-check">
                <input type="checkbox" name="is_private" value="1" class="form-check-input"
                       id="notePrivate" checked>
                <label class="form-check-label small" for="notePrivate">Private</label>
            </div>
            <div class="input-group input-group-sm" style="width: 140px;">
                <span class="input-group-text"><i class="bi bi-clock"></i></span>
                <input type="text" name="time" class="form-control" placeholder="0h 15m" id="noteTimeInput">
            </div>
            <div class="form-check d-none" id="noteBillableGroup">
                <input type="checkbox" name="is_billable" value="1" class="form-check-input"
                       id="noteBillable" {{ ($defaultBillable ?? true) ? 'checked' : '' }}>
                <label class="form-check-label small" for="noteBillable">Billable</label>
            </div>
            <div class="d-none" id="noteContractGroup">
                <select name="contract_id" class="form-select form-select-sm" style="max-width: 180px;">
                    <option value="">Ticket default</option>
                    @foreach($ticket->client?->contracts ?? [] as $ct)
                        <option value="{{ $ct->id }}" {{ $ticket->contract_id == $ct->id ? 'selected' : '' }}>
                            {{ $ct->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="input-group input-group-sm" style="width: auto;">
                <span class="input-group-text"><i class="bi bi-arrow-left-right"></i></span>
                <select name="new_status" class="form-select form-select-sm" id="noteStatusSelect" style="width: auto;">
                    <option value="">No change</option>
                    @foreach($ticket->status->allowedTransitions() as $s)
                        @if($s !== \App\Enums\TicketStatus::Closed)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="d-none" id="noteResolutionGroup">
                <input type="text" name="resolution" class="form-control form-control-sm"
                       placeholder="Brief resolution summary...">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Add</button>
        </div>
    </form>
</div>
