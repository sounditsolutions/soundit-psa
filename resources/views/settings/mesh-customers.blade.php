@extends('layouts.app')

@section('title', 'Mesh Customer Mapping')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">Mesh Customer Mapping</h2>
            <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back to Integrations
            </a>
        </div>

        <p class="text-muted mb-3">
            Map Mesh Email Security customers to local clients. This enables license count sync for billing.
        </p>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <form method="POST" action="{{ route('settings.mesh-customers.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Mesh Customer</th>
                                <th class="d-none d-md-table-cell">Service</th>
                                <th class="text-end d-none d-md-table-cell">Licenses</th>
                                <th style="min-width: 220px;">Mapped Client</th>
                                <th class="text-center" style="width: 80px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($customers as $customer)
                            @php
                                $customerId = $customer['id'] ?? '';
                                $mapped = $mappedClients->get($customerId);
                                $licenseCount = $customer['licenses_billed'] ?? 0;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $customer['company_name'] ?? 'Unknown' }}</strong>
                                    <br><small class="text-muted">{{ $customer['primary_domain'] ?? '' }}</small>
                                </td>
                                <td class="d-none d-md-table-cell small">{{ $customer['service_name'] ?? '-' }}</td>
                                <td class="text-end d-none d-md-table-cell fw-semibold">{{ $licenseCount }}</td>
                                <td>
                                    <select name="mappings[{{ $customerId }}]" class="form-select form-select-sm">
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
