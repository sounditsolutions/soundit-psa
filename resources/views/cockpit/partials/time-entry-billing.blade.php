@php($billingProposals = \App\Models\TimeEntryBillingProposal::where('state', 'pending')->orderBy('id')->get())
@php($canApproveBilling = app(\App\Services\PhoneCallActionService::class)->canApprove(auth()->user()))
@if($billingProposals->isNotEmpty())
<section class="mb-4">
    <h2 class="h5">Billable time changes ({{ $billingProposals->count() }})</h2>
    @foreach($billingProposals as $proposal)
        @php($entry = $proposal->entry_type === 'note' ? \App\Models\TicketNote::find($proposal->entry_id) : \App\Models\PhoneCall::find($proposal->entry_id))
        <div class="card mb-2"><div class="card-body">
            <span class="badge bg-warning text-dark">{{ \App\Support\StagedActionLabels::humanLabel('stage_set_billable_time') }}</span>
            <strong>{{ ucfirst($proposal->entry_type) }} #{{ $proposal->entry_id }}</strong>
            @if($entry)
                @php($currentMinutes = $proposal->entry_type === 'note' ? $entry->billedMinutes() : ($entry->billedSeconds() ?? 0) / 60)
                <p>Current billed time: {{ $currentMinutes }} minutes. Proposed Bill: {{ $proposal->billable_minutes === null ? 'Actual time (clear override)' : $proposal->billable_minutes.' minutes' }}.</p>
            @else
                <p>Entry unavailable.</p>
            @endif
            <p>{{ $proposal->reason }}</p>
            <small>Proposed by {{ $proposal->actor_label }}.</small>
            @if($canApproveBilling)
                <form method="POST" action="{{ route('time-entry-billing.approve', $proposal) }}" class="d-inline">
                    @csrf <button class="btn btn-sm btn-outline-success">Approve</button>
                </form>
                <form method="POST" action="{{ route('time-entry-billing.deny', $proposal) }}" class="d-inline">
                    @csrf <button class="btn btn-sm btn-outline-danger">Deny</button>
                </form>
            @endif
        </div></div>
    @endforeach
</section>
@endif
