@if(($phoneCallResolutions ?? collect())->isNotEmpty())
<section class="mb-4">
    <h2 class="h5">Phone call identity approvals ({{ $phoneCallResolutions->count() }})</h2>
    @foreach($phoneCallResolutions as $proposal)
        <div class="card mb-2"><div class="card-body">
            <span class="badge bg-info">{{ \App\Support\StagedActionLabels::humanLabel('stage_resolve_phone_call') }}</span>
            <strong>Call #{{ $proposal->phone_call_id }}</strong>
            → Client #{{ $proposal->client_id }}, contact #{{ $proposal->payload['contact_id'] ?? '?' }}
            @if($proposal->payload['replace_confirmed'] ?? false)
                <p class="mb-1">Replaces contact #{{ $proposal->payload['replaces_person_id'] ?? '?' }} at the same client.</p>
            @endif
            <p class="mb-1">Ticket association is preserved. Approval revalidates identity and ownership.</p>
            <p>{{ $proposal->payload['reason'] ?? '' }}</p>
            <small>Proposed by {{ $proposal->drafted_by }}</small>
            @if($canApprovePhoneCallResolution ?? false)
                <form method="POST" action="{{ route('phone-call-resolutions.approve', $proposal->id) }}" class="d-inline">
                    @csrf <button class="btn btn-sm btn-success">Approve caller identity</button>
                </form>
                <form method="POST" action="{{ route('phone-call-resolutions.deny', $proposal->id) }}" class="d-inline">
                    @csrf <button class="btn btn-sm btn-outline-danger">Deny</button>
                </form>
            @endif
        </div></div>
    @endforeach
</section>
@endif
