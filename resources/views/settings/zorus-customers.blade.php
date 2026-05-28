@extends('layouts.app')

@section('title', 'Zorus Customer Mapping')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">Zorus Customer Mapping</h2>
            <div class="d-flex gap-2">
                <a href="{{ route('settings.zorus-customers.auto-match') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-magic me-1"></i>Auto-Match by Name
                </a>
                <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Integrations
                </a>
            </div>
        </div>

        <p class="text-muted mb-3">
            Map Zorus customers to local clients. This enables endpoint count sync for billing and device data enrichment on assets.
            Auto-Match uses exact name matching (case-insensitive).
        </p>

        @if(session('info'))
            <div class="alert alert-info alert-dismissible fade show">
                {{ session('info') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <form method="POST" action="{{ route('settings.zorus-customers.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Zorus Customer</th>
                                <th class="text-end d-none d-md-table-cell">Endpoints</th>
                                <th class="text-end d-none d-md-table-cell">Filtering</th>
                                <th class="text-end d-none d-md-table-cell">CyberSight</th>
                                <th style="min-width: 220px;">Mapped Client</th>
                                <th class="text-center" style="width: 80px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($customers as $customer)
                            @php
                                $uuid = $customer['uuid'] ?? '';
                                $mapped = $mappedClients->get($uuid);
                                $deployment = $customer['deploymentInfo'] ?? [];
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $customer['name'] ?? 'Unknown' }}</strong>
                                    @if(!($customer['isEnabled'] ?? true))
                                        <span class="badge bg-warning text-dark ms-1">Disabled</span>
                                    @endif
                                </td>
                                <td class="text-end d-none d-md-table-cell fw-semibold">{{ $deployment['deployedEndpointCount'] ?? 0 }}</td>
                                <td class="text-end d-none d-md-table-cell fw-semibold">{{ $deployment['filteringEnabledCount'] ?? 0 }}</td>
                                <td class="text-end d-none d-md-table-cell fw-semibold">{{ $deployment['cyberSightEnabledCount'] ?? 0 }}</td>
                                <td>
                                    <select name="mappings[{{ $uuid }}]" class="form-select form-select-sm client-select" data-selected="{{ $mapped?->id }}">
                                        <option value="">— Not mapped —</option>
                                    </select>
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
    const clients = @json($allClients->map(fn ($c) => ['id' => $c->id, 'name' => $c->name]));

    document.querySelectorAll('.client-select').forEach(select => {
        const selected = select.dataset.selected;
        clients.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.name;
            if (String(c.id) === selected) opt.selected = true;
            select.appendChild(opt);
        });
    });
</script>
@endpush
