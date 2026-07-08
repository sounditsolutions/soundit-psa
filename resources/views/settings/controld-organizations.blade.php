@extends('layouts.app')

@section('title', 'Control D Organization Mapping')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">Control D Organization Mapping</h2>
            <div class="d-flex gap-2">
                <a href="{{ route('settings.controld-orgs.auto-match') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-magic me-1"></i>Auto-Match by Name
                </a>
                <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Integrations
                </a>
            </div>
        </div>

        <p class="text-muted mb-3">
            Map Control D sub-organizations to local clients. This enables endpoint and router device count sync for billing.
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

        <form method="POST" action="{{ route('settings.controld-orgs.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Control D Sub-Organization</th>
                                <th class="text-end d-none d-md-table-cell">Endpoints</th>
                                <th class="text-end d-none d-md-table-cell">Routers</th>
                                <th style="min-width: 220px;">Mapped Client</th>
                                <th class="text-center" style="width: 80px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($subOrgs as $org)
                            @php
                                $orgPk = $org['PK'] ?? '';
                                $mapped = $mappedClients->get($orgPk);
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $org['name'] ?? 'Unknown' }}</strong>
                                    <br><small class="text-muted">PK: {{ $orgPk }}</small>
                                </td>
                                <td class="text-end d-none d-md-table-cell fw-semibold">{{ $org['users']['count'] ?? 0 }}</td>
                                <td class="text-end d-none d-md-table-cell fw-semibold">{{ $org['routers']['count'] ?? 0 }}</td>
                                <td>
                                    <select name="mappings[{{ $orgPk }}]" class="form-select form-select-sm client-select" data-selected="{{ $mapped?->id }}">
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
