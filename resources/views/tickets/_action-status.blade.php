{{-- Change Status action panel --}}
<div class="action-panel d-none" id="actionStatus">
    <form method="POST" action="{{ route('tickets.update-status', $ticket) }}">
        @csrf
        @method('PATCH')
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="input-group input-group-sm" style="width: auto;">
                <span class="input-group-text"><i class="bi bi-arrow-left-right"></i></span>
                <select name="status" class="form-select form-select-sm" id="statusOnlySelect" style="width: auto;" required>
                    <option value="">Select status...</option>
                    @foreach($ticket->status->allowedTransitions() as $s)
                        @if($s !== \App\Enums\TicketStatus::Closed)
                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
            <div class="d-none flex-grow-1" id="statusResolutionGroup" style="max-width: 400px;">
                <input type="text" name="resolution" class="form-control form-control-sm"
                       placeholder="Brief resolution summary...">
            </div>
            <button type="submit" class="btn btn-primary btn-sm">Update</button>
        </div>
    </form>
</div>
