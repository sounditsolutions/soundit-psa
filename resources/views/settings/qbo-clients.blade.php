@extends('layouts.app')

@section('title', 'QBO Client Matching')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">QuickBooks Client Matching</h2>
            <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Integrations
            </a>
        </div>

        <p class="text-muted mb-3">
            Map QuickBooks Online customers to local PSA clients. This enables invoice sync to QBO.
        </p>

        <div class="d-flex gap-2 mb-3">
            <a href="{{ route('settings.qbo-clients.auto-match') }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-magic me-1"></i>Auto-Match by Name
            </a>
        </div>

        <form method="POST" action="{{ route('settings.qbo-clients.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>QBO Customer</th>
                                <th>Matched PSA Client</th>
                                <th class="text-center" style="width: 100px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($qboCustomers as $qc)
                            @php
                                $qboId = $qc['Id'];
                                $mapped = $mappedClients->get($qboId);
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $qc['DisplayName'] }}</strong>
                                    @if($qc['PrimaryEmailAddr'])
                                        <br><small class="text-muted">{{ $qc['PrimaryEmailAddr'] }}</small>
                                    @endif
                                </td>
                                <td>
                                    <input type="hidden" name="mappings[{{ $qboId }}][display_name]" value="{{ $qc['DisplayName'] }}">
                                    <select class="form-select form-select-sm qbo-client-select"
                                            name="mappings[{{ $qboId }}][client_id]"
                                            data-selected="{{ $mapped?->id ?? '' }}">
                                    </select>
                                </td>
                                <td class="text-center">
                                    @if($mapped)
                                        <span class="badge bg-success">Mapped</span>
                                    @else
                                        <span class="badge bg-secondary">Unmapped</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Save Mappings
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function() {
    const clients = @json($allClients->map(fn($c) => ['id' => $c->id, 'name' => $c->name]));

    // Build options HTML once
    let optionsHtml = '<option value="">-- Not mapped --</option>';
    clients.forEach(c => {
        optionsHtml += '<option value="' + c.id + '">' + c.name.replace(/&/g,'&amp;').replace(/</g,'&lt;') + '</option>';
    });

    // Populate each select and restore selected value
    document.querySelectorAll('.qbo-client-select').forEach(select => {
        select.innerHTML = optionsHtml;
        const selected = select.dataset.selected;
        if (selected) {
            select.value = selected;
        }
    });
})();
</script>
@endpush
