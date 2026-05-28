@extends('layouts.app')

@section('title', 'CIPP Tenant Mapping')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-12">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">CIPP Tenant Mapping</h2>
            <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Integrations
            </a>
        </div>

        <p class="text-muted mb-3">
            Map CIPP/M365 tenants to local clients. This enables M365 license sync for billing.
            Optionally select a security group per tenant to filter which M365 users sync as contacts.
        </p>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <form method="POST" action="{{ route('settings.cipp-tenants.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Tenant</th>
                                <th class="d-none d-md-table-cell">Domain</th>
                                <th style="min-width: 220px;">Mapped Client</th>
                                <th style="min-width: 200px;">Contact Sync Group</th>
                                <th class="text-center" style="width: 80px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tenants as $tenant)
                            @php
                                $domain = $tenant['defaultDomainName'] ?? '';
                                $mapped = $mappedClients->get($domain);
                                $currentGroupId = $mapped?->cipp_sync_group_id ?? '';
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $tenant['displayName'] ?? 'Unknown' }}</strong>
                                </td>
                                <td class="d-none d-md-table-cell small text-muted">{{ $domain }}</td>
                                <td>
                                    <select name="mappings[{{ $domain }}]" class="form-select form-select-sm mapping-select"
                                            data-domain="{{ $domain }}">
                                        <option value="">— Not mapped —</option>
                                        @foreach($allClients as $c)
                                            <option value="{{ $c->id }}" {{ $mapped?->id == $c->id ? 'selected' : '' }}>
                                                {{ $c->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    @if($mapped)
                                        <select name="groups[{{ $domain }}]" class="form-select form-select-sm group-select"
                                                data-domain="{{ $domain }}" data-current="{{ $currentGroupId }}">
                                            <option value="">All users (no filter)</option>
                                            <option value="" disabled>Loading groups...</option>
                                        </select>
                                    @else
                                        <span class="text-muted">—</span>
                                    @endif
                                </td>
                                <td class="text-center">
                                    @if($mapped)
                                        <span class="badge bg-success">Mapped</span>
                                    @else
                                        <span class="badge bg-secondary">-</span>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary">Save Mappings</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Lazy-load groups for each mapped tenant's group dropdown
    document.querySelectorAll('.group-select').forEach(function(select) {
        loadGroups(select);
    });

    function loadGroups(select) {
        const domain = select.dataset.domain;
        const currentGroupId = select.dataset.current || '';

        fetch(`/settings/integrations/cipp/tenants/${encodeURIComponent(domain)}/groups`, {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''
            },
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(groups => {
            // Clear loading placeholder
            select.innerHTML = '<option value="">All users (no filter)</option>';

            groups.forEach(function(g) {
                const opt = document.createElement('option');
                opt.value = g.id;
                opt.textContent = g.displayName;
                if (g.id === currentGroupId) opt.selected = true;
                select.appendChild(opt);
            });
        })
        .catch(() => {
            select.innerHTML = '<option value="">All users (no filter)</option>';
        });
    }
});
</script>
@endpush
