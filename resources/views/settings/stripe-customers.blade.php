@extends('layouts.app')

@section('title', 'Stripe Customer Matching')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h2 class="section-title mb-0">Stripe Customer Matching</h2>
            <div class="d-flex gap-2">
                <a href="{{ route('settings.stripe-customers.auto-match') }}" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-lightning me-1"></i>Auto-Match
                </a>
                <a href="{{ route('settings.integrations') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back to Integrations
                </a>
            </div>
        </div>

        <p class="text-muted mb-3">
            Map Stripe customers to local PSA clients. This enables invoice push and payment tracking.
        </p>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        <form method="POST" action="{{ route('settings.stripe-customers.update') }}">
            @csrf

            <div class="card card-static shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Stripe Customer</th>
                                <th class="d-none d-md-table-cell">Email</th>
                                <th style="min-width: 220px;">Mapped Client</th>
                                <th class="text-center" style="width: 80px;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($customers as $customer)
                            @php
                                $stripeId = $customer['id'];
                                $mapped = $mappedClients->get($stripeId);
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $customer['name'] ?: 'Unnamed' }}</strong>
                                    <br><small class="text-muted">{{ $stripeId }}</small>
                                </td>
                                <td class="d-none d-md-table-cell small text-muted">{{ $customer['email'] ?: '-' }}</td>
                                <td>
                                    <select name="mappings[{{ $stripeId }}]" class="form-select form-select-sm">
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
