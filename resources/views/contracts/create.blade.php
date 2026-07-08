@extends('layouts.app')

@section('title', 'New Contract')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('clients.contracts', $client) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Contracts
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title">New Contract for {{ $client->name }}</h4>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="POST" action="{{ route('contracts.store', $client) }}">
                    @csrf

                    <div class="mb-3">
                        <label for="name" class="form-label">Contract Name</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name" value="{{ old('name') }}" required>
                        @error('name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="type" class="form-label">Type</label>
                            <select class="form-select @error('type') is-invalid @enderror" id="type" name="type" required>
                                @foreach($types as $t)
                                    <option value="{{ $t->value }}" {{ old('type', 'managed') === $t->value ? 'selected' : '' }}>
                                        {{ $t->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select @error('status') is-invalid @enderror" id="status" name="status" required>
                                @foreach($statuses as $s)
                                    <option value="{{ $s->value }}" {{ old('status', 'active') === $s->value ? 'selected' : '' }}>
                                        {{ $s->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-1">
                        <div class="col-md-4">
                            <label for="billing_period" class="form-label">Billing Period</label>
                            <select class="form-select @error('billing_period') is-invalid @enderror" id="billing_period" name="billing_period" required>
                                @foreach($billingPeriods as $bp)
                                    <option value="{{ $bp->value }}" {{ old('billing_period', 'monthly') === $bp->value ? 'selected' : '' }}>
                                        {{ $bp->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="billing_day" class="form-label">Billing Day</label>
                            <input type="number" class="form-control @error('billing_day') is-invalid @enderror"
                                   id="billing_day" name="billing_day" value="{{ old('billing_day', 1) }}"
                                   min="1" max="28" required>
                        </div>
                        <div class="col-md-4">
                            <label for="payment_terms_days" class="form-label">Payment Terms (days)</label>
                            <input type="number" class="form-control @error('payment_terms_days') is-invalid @enderror"
                                   id="payment_terms_days" name="payment_terms_days"
                                   value="{{ old('payment_terms_days', config('billing.default_payment_terms_days', 30)) }}"
                                   min="0" max="365" required>
                        </div>
                    </div>
                    <div class="form-text text-muted mb-3">Default values inherited by new recurring profiles.</div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control @error('start_date') is-invalid @enderror"
                                   id="start_date" name="start_date" value="{{ old('start_date', date('Y-m-d')) }}" required>
                        </div>
                        <div class="col-md-4">
                            <label for="end_date" class="form-label">End Date <span class="text-muted">(optional)</span></label>
                            <input type="date" class="form-control @error('end_date') is-invalid @enderror"
                                   id="end_date" name="end_date" value="{{ old('end_date') }}">
                        </div>
                        <div class="col-md-4">
                            <label for="term_length_months" class="form-label">Term Length <span class="text-muted">(months)</span></label>
                            <input type="number" class="form-control @error('term_length_months') is-invalid @enderror"
                                   id="term_length_months" name="term_length_months"
                                   value="{{ old('term_length_months') }}"
                                   min="1" max="120" placeholder="Open-ended">
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input type="hidden" name="auto_renew" value="0">
                        <input type="checkbox" class="form-check-input" id="auto_renew" name="auto_renew"
                               value="1" {{ old('auto_renew') ? 'checked' : '' }}>
                        <label class="form-check-label" for="auto_renew">Auto-renew (evergreen)</label>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes <span class="text-muted">(optional)</span></label>
                        <textarea class="form-control @error('notes') is-invalid @enderror"
                                  id="notes" name="notes" rows="3">{{ old('notes') }}</textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Create Contract</button>
                        <a href="{{ route('clients.contracts', $client) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
