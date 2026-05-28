@extends('layouts.app')

@section('title', 'Printix Tenant Mapping')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">Printix Tenant Mapping</h2>
            <div class="d-flex gap-2">
                <a href="{{ route('settings.printix-tenants.auto-match') }}" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-magic me-1"></i>Auto-Match
                </a>
                <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Integrations
                </a>
            </div>
        </div>

        <p class="text-muted mb-3">
            Map Printix tenants to local clients. This enables license sync for billing.
        </p>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <form method="POST" action="{{ route('settings.printix-tenants.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Tenant</th>
                                <th class="d-none d-md-table-cell">Domain</th>
                                <th style="min-width: 220px;">Mapped Client</th>
                                <th class="text-center" style="width: 80px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tenants as $tenant)
                            @php
                                $tenantId = $tenant['id'] ?? '';
                                $mapped = $mappedClients->get($tenantId);
                            @endphp
                            <tr>
                                <td><strong>{{ $tenant['tenant_name'] ?? 'Unknown' }}</strong></td>
                                <td class="d-none d-md-table-cell small text-muted">{{ $tenant['tenant_domain'] ?? '-' }}</td>
                                <td>
                                    <select name="mappings[{{ $tenantId }}]" class="form-select form-select-sm">
                                        <option value="">— Not mapped —</option>
                                        @foreach($allClients as $c)
                                            <option value="{{ $c->id }}" {{ $mapped?->id == $c->id ? 'selected' : '' }}>
                                                {{ $c->name }}
                                            </option>
                                        @endforeach
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
