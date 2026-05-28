@extends('layouts.app')

@section('title', 'AppRiver Customer Mapping')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">AppRiver Customer Mapping</h2>
            <div class="d-flex gap-2">
                <a href="{{ route('settings.appriver-customers.auto-match') }}" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-magic me-1"></i>Auto-Match by Name
                </a>
                <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Integrations
                </a>
            </div>
        </div>

        <p class="text-muted mb-3">
            Map AppRiver customers to local clients. This enables M365 subscription seat count sync and seat management.
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

        <form method="POST" action="{{ route('settings.appriver-customers.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>AppRiver Customer</th>
                                <th class="d-none d-md-table-cell">Type</th>
                                <th class="d-none d-md-table-cell">Domain</th>
                                <th style="min-width: 220px;">Mapped Client</th>
                                <th class="text-center" style="width: 80px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($customers as $customer)
                            @php
                                $customerId = $customer['CustomerId'] ?? '';
                                $mapped = $mappedClients->get($customerId);
                                $customerType = $customer['CustomerType'] ?? '';
                                $domain = $customer['PrimaryDomain'] ?? $customer['Website'] ?? '';
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $customer['Name'] ?? 'Unknown' }}</strong>
                                </td>
                                <td class="d-none d-md-table-cell">
                                    @if($customerType)
                                        <span class="badge bg-light text-dark">{{ $customerType }}</span>
                                    @endif
                                </td>
                                <td class="d-none d-md-table-cell small text-muted">{{ $domain }}</td>
                                <td>
                                    <select name="mappings[{{ $customerId }}]" class="form-select form-select-sm client-select" data-selected="{{ $mapped?->id }}">
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
