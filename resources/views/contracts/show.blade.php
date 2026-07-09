@extends('layouts.app')

@section('title', $contract->name . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('clients.contracts', $contract->client) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to {{ $contract->client->name }} Contracts
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col d-flex align-items-center justify-content-between">
        <div>
            <h4 class="section-title mb-1">{{ $contract->name }}</h4>
            <span class="badge {{ $contract->status->badgeClass() }}">{{ $contract->status->label() }}</span>
            <span class="badge bg-light text-dark ms-1">{{ $contract->type->label() }}</span>
            @if($contract->term_length_months)
                @if($contract->auto_renew)
                    <span class="badge bg-primary bg-opacity-10 text-primary ms-1" title="Auto-renewing {{ $contract->term_length_months }}-month term">
                        <i class="bi bi-arrow-repeat me-1"></i>Evergreen &mdash; {{ $contract->term_length_months }}mo
                    </span>
                @else
                    <span class="badge bg-light text-dark ms-1">Fixed Term: {{ $contract->term_length_months }}mo</span>
                @endif
            @else
                <span class="badge bg-light text-muted ms-1">Open-ended</span>
            @endif
        </div>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Contract Details --}}
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header">
                <i class="bi bi-pencil me-2"></i>Contract Details
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('contracts.update', $contract) }}">
                    @csrf
                    @method('PATCH')

                    <div class="mb-3">
                        <label for="name" class="form-label">Contract Name</label>
                        <input type="text" class="form-control @error('name') is-invalid @enderror"
                               id="name" name="name" value="{{ old('name', $contract->name) }}" required>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="type" class="form-label">Type</label>
                            <select class="form-select" id="type" name="type" required>
                                @foreach($types as $t)
                                    <option value="{{ $t->value }}" {{ old('type', $contract->type->value) === $t->value ? 'selected' : '' }}>
                                        {{ $t->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status" required>
                                @foreach($statuses as $s)
                                    <option value="{{ $s->value }}" {{ old('status', $contract->status->value) === $s->value ? 'selected' : '' }}>
                                        {{ $s->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="billing_period" class="form-label">Billing Period</label>
                            <select class="form-select" id="billing_period" name="billing_period" required>
                                @foreach($billingPeriods as $bp)
                                    <option value="{{ $bp->value }}" {{ old('billing_period', $contract->billing_period->value) === $bp->value ? 'selected' : '' }}>
                                        {{ $bp->label() }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="billing_day" class="form-label">Billing Day</label>
                            <input type="number" class="form-control" id="billing_day" name="billing_day"
                                   value="{{ old('billing_day', $contract->billing_day) }}" min="1" max="28" required>
                        </div>
                        <div class="col-md-4">
                            <label for="payment_terms_days" class="form-label">Payment Terms (days)</label>
                            <input type="number" class="form-control" id="payment_terms_days" name="payment_terms_days"
                                   value="{{ old('payment_terms_days', $contract->payment_terms_days) }}" min="0" max="365" required>
                        </div>
                        <div class="form-text text-muted">
                            <i class="bi bi-info-circle me-1"></i>Default values inherited by new recurring profiles.
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label for="start_date" class="form-label">Start Date</label>
                            <input type="date" class="form-control" id="start_date" name="start_date"
                                   value="{{ old('start_date', $contract->start_date->format('Y-m-d')) }}" required>
                        </div>
                        <div class="col-md-4">
                            <label for="end_date" class="form-label">End Date</label>
                            <input type="date" class="form-control" id="end_date" name="end_date"
                                   value="{{ old('end_date', $contract->end_date?->format('Y-m-d')) }}">
                        </div>
                        <div class="col-md-4">
                            <label for="term_length_months" class="form-label">Term Length (months)</label>
                            <input type="number" class="form-control" id="term_length_months" name="term_length_months"
                                   value="{{ old('term_length_months', $contract->term_length_months) }}"
                                   min="1" max="120" placeholder="Open-ended">
                        </div>
                    </div>

                    <div class="form-check mb-3">
                        <input type="hidden" name="auto_renew" value="0">
                        <input type="checkbox" class="form-check-input" id="auto_renew" name="auto_renew"
                               value="1" {{ old('auto_renew', $contract->auto_renew) ? 'checked' : '' }}
                              >
                        <label class="form-check-label" for="auto_renew">Auto-renew (evergreen)</label>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="3">{{ old('notes', $contract->notes) }}</textarea>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </form>
            </div>
        </div>

        {{-- Contract tabs --}}
        @php
            $profitService = app(\App\Services\ProfitabilityService::class);
            $profitData = $profitService->contractProfitability($contract);
        @endphp

        @php $hasActiveSubpage = in_array($activeTab ?? '', ['tickets', 'invoices']); @endphp
        <ul class="nav nav-tabs detail-tabs mt-4" id="contractTabs" role="tablist">
            <li class="nav-item" role="presentation">
                @if($hasActiveSubpage)
                    <a class="nav-link" href="{{ route('contracts.show', $contract) }}">
                        <i class="bi bi-arrow-repeat me-1"></i>Profiles
                        @if($contract->profiles->isNotEmpty())
                            <span class="badge bg-primary bg-opacity-10 text-primary ms-1">{{ $contract->profiles->count() }}</span>
                        @endif
                    </a>
                @else
                    <button class="nav-link active" id="profiles-tab" data-bs-toggle="tab" data-bs-target="#profiles" type="button" role="tab">
                        <i class="bi bi-arrow-repeat me-1"></i>Profiles
                        @if($contract->profiles->isNotEmpty())
                            <span class="badge bg-primary bg-opacity-10 text-primary ms-1">{{ $contract->profiles->count() }}</span>
                        @endif
                    </button>
                @endif
            </li>
            <li class="nav-item" role="presentation">
                @if($hasActiveSubpage)
                    <a class="nav-link" href="{{ route('contracts.show', $contract) }}#assignments">
                        <i class="bi bi-diagram-3 me-1"></i>Assignments
                    </a>
                @else
                    <button class="nav-link" id="assignments-tab" data-bs-toggle="tab" data-bs-target="#assignments" type="button" role="tab">
                        <i class="bi bi-diagram-3 me-1"></i>Assignments
                    </button>
                @endif
            </li>
            <li class="nav-item" role="presentation">
                @if(($activeTab ?? '') === 'invoices')
                    <button class="nav-link active" type="button">
                        <i class="bi bi-receipt me-1"></i>Invoices @if(isset($invoices))<span class="text-muted">({{ $invoices->total() }})</span>@endif
                    </button>
                @elseif($hasActiveSubpage)
                    <a class="nav-link" href="{{ route('contracts.invoices', $contract) }}">
                        <i class="bi bi-receipt me-1"></i>Invoices
                    </a>
                @else
                    <button class="nav-link" id="invoices-tab" data-bs-toggle="tab" data-bs-target="#invoices" type="button" role="tab">
                        <i class="bi bi-receipt me-1"></i>Invoices
                        @if($recentInvoices->isNotEmpty())
                            <span class="badge bg-primary bg-opacity-10 text-primary ms-1">{{ $recentInvoices->count() }}</span>
                        @endif
                    </button>
                @endif
            </li>
            <li class="nav-item" role="presentation">
                @if(($activeTab ?? '') === 'tickets')
                    <button class="nav-link active" type="button">
                        <i class="bi bi-ticket-perforated me-1"></i>Tickets @if(isset($tickets))<span class="text-muted">({{ $tickets->total() }})</span>@endif
                    </button>
                @elseif($hasActiveSubpage)
                    <a class="nav-link" href="{{ route('contracts.tickets', $contract) }}">
                        <i class="bi bi-ticket-perforated me-1"></i>Tickets
                    </a>
                @else
                    <a class="nav-link" href="{{ route('contracts.tickets', $contract) }}">
                        <i class="bi bi-ticket-perforated me-1"></i>Tickets
                    </a>
                @endif
            </li>
            @if($contract->has_prepay)
                <li class="nav-item" role="presentation">
                    @if($hasActiveSubpage)
                        <a class="nav-link" href="{{ route('contracts.show', $contract) }}#prepay-history">
                            <i class="bi bi-clock-history me-1"></i>Prepay History
                        </a>
                    @else
                        <button class="nav-link" id="prepay-history-tab" data-bs-toggle="tab" data-bs-target="#prepay-history" type="button" role="tab">
                            <i class="bi bi-clock-history me-1"></i>Prepay History
                        </button>
                    @endif
                </li>
            @endif
        </ul>

        <div class="tab-content mt-3">
            {{-- Profiles Tab (default) --}}
            <div class="tab-pane fade {{ !$hasActiveSubpage ? 'show active' : '' }}" id="profiles" role="tabpanel">
                <div class="card card-static shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-arrow-repeat me-2"></i>Recurring Profiles
                            @if($contract->profiles->isNotEmpty())
                                <span class="badge bg-light text-dark ms-1">{{ $contract->profiles->count() }}</span>
                            @endif
                        </div>
                        <a href="{{ route('profiles.create', $contract) }}" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-plus-lg me-1"></i>New Profile
                        </a>
                    </div>
                    @if($contract->profiles->isEmpty())
                        <div class="card-body text-muted text-center py-3">
                            No recurring profiles. Create one to set up automated billing.
                        </div>
                    @else
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th style="width: 30px;"></th>
                                        <th>Name</th>
                                        <th>Status</th>
                                        <th>Next Run</th>
                                        <th>Last Run</th>
                                        <th>Lines</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($contract->profiles as $profile)
                                        @php $cyclesBehind = $profile->cyclesBehind(); @endphp
                                        <tr class="cursor-pointer" onclick="window.location='{{ route('profiles.show', $profile) }}'">
                                            <td onclick="event.stopPropagation()">
                                                @if($profile->lines->isNotEmpty())
                                                    <a href="#" class="text-muted profile-toggle" data-bs-toggle="collapse" data-bs-target="#profileLines{{ $profile->id }}" onclick="event.preventDefault(); this.querySelector('i').classList.toggle('bi-chevron-right'); this.querySelector('i').classList.toggle('bi-chevron-down');">
                                                        <i class="bi bi-chevron-right"></i>
                                                    </a>
                                                @endif
                                            </td>
                                            <td>
                                                <a href="{{ route('profiles.show', $profile) }}" class="text-decoration-none fw-semibold">
                                                    {{ $profile->name }}
                                                </a>
                                                @if($profile->notes)
                                                    <i class="bi bi-chat-left-text text-muted ms-1" title="{{ $profile->notes }}"></i>
                                                @endif
                                            </td>
                                            <td>
                                                @if($profile->is_active)
                                                    <span class="badge bg-success">Active</span>
                                                @else
                                                    <span class="badge bg-secondary">Inactive</span>
                                                @endif
                                                <span class="badge bg-light text-dark">PSA</span>
                                            </td>
                                            <td class="small">
                                                <div class="d-flex flex-column align-items-start gap-1">
                                                    <span>
                                                        <span class="{{ $cyclesBehind > 0 ? 'text-danger fw-semibold' : '' }}">{{ $profile->next_run_date->format('M j, Y') }}</span>
                                                        @if($cyclesBehind > 0)
                                                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle ms-1"
                                                                  title="Invoice generation is overdue — the next run date has passed">
                                                                <i class="bi bi-exclamation-triangle me-1"></i>{{ $cyclesBehind }} cycle{{ $cyclesBehind > 1 ? 's' : '' }} behind
                                                            </span>
                                                        @endif
                                                    </span>
                                                    @if($cyclesBehind > 0)
                                                        <form method="POST" action="{{ route('profiles.generate', $profile) }}"
                                                              onclick="event.stopPropagation()"
                                                              onsubmit="return confirm('Generate an invoice dated {{ $profile->next_run_date->format('M j, Y') }}?')">
                                                            @csrf
                                                            <button type="submit" class="btn btn-primary btn-sm py-0">
                                                                <i class="bi bi-lightning me-1"></i>Generate Now
                                                            </button>
                                                        </form>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="small">{{ $profile->last_run_date?->format('M j, Y') ?? '-' }}</td>
                                            <td class="small">{{ $profile->lines->count() }}</td>
                                        </tr>
                                        @if($profile->lines->isNotEmpty())
                                            <tr class="collapse" id="profileLines{{ $profile->id }}">
                                                <td colspan="6" class="p-0">
                                                    <table class="table table-sm mb-0 ms-4" style="background: #f8f9fa;">
                                                        <thead>
                                                            <tr class="text-muted small">
                                                                <th>SKU</th>
                                                                <th>Description</th>
                                                                <th>Qty Type</th>
                                                                <th class="text-end">Unit Price</th>
                                                                <th class="text-center">Taxable</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            @foreach($profile->lines as $line)
                                                                <tr class="small">
                                                                    <td>{{ $line->sku?->name ?? '—' }}</td>
                                                                    <td>{{ $line->description ?: '—' }}</td>
                                                                    <td>
                                                                        {{ $line->quantity_type->label() }}
                                                                        @if($line->quantity_type === \App\Enums\QuantityType::Fixed && $line->fixed_quantity)
                                                                            <span class="text-muted">({{ rtrim(rtrim(number_format($line->fixed_quantity, 2), '0'), '.') }})</span>
                                                                        @endif
                                                                    </td>
                                                                    <td class="text-end">${{ number_format($line->unit_price, 2) }}</td>
                                                                    <td class="text-center">
                                                                        @if($line->is_taxable)
                                                                            <i class="bi bi-check text-success"></i>
                                                                        @else
                                                                            <i class="bi bi-dash text-muted"></i>
                                                                        @endif
                                                                    </td>
                                                                </tr>
                                                            @endforeach
                                                        </tbody>
                                                    </table>
                                                </td>
                                            </tr>
                                        @endif
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>

                {{-- Profitability (collapsible, inside Profiles tab) --}}
                @if($profitData['revenue'] > 0 || $profitData['cost'] > 0)
                    <div class="card card-static shadow-sm mt-3">
                        <div class="card-header d-flex justify-content-between align-items-center"
                             data-bs-toggle="collapse" data-bs-target="#profitCollapse" style="cursor: pointer;">
                            <div><i class="bi bi-graph-up me-2"></i>Profitability</div>
                            <i class="bi bi-chevron-down"></i>
                        </div>
                        <div class="collapse" id="profitCollapse">
                            <div class="card-body">
                                <div class="row g-3 text-center">
                                    <div class="col-4">
                                        <div class="text-muted small">Revenue</div>
                                        <div class="fw-bold">${{ number_format($profitData['revenue'], 2) }}</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-muted small">Cost</div>
                                        <div class="fw-bold">${{ number_format($profitData['cost'], 2) }}</div>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-muted small">Margin</div>
                                        <div class="fw-bold {{ $profitData['margin'] >= 0 ? 'text-success' : 'text-danger' }}">
                                            {{ $profitData['marginPct'] !== null ? $profitData['marginPct'] . '%' : '-' }}
                                        </div>
                                    </div>
                                </div>
                                <div class="text-center mt-2">
                                    <a href="{{ route('profitability.contract', $contract) }}" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-graph-up me-1"></i>Full Report
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Assignments Tab --}}
            <div class="tab-pane fade" id="assignments" role="tabpanel">
                <div class="card card-static shadow-sm">
                    <div class="card-header">
                        <i class="bi bi-diagram-3 me-2"></i>Contract Assignments
                    </div>
                    <div class="card-body">
                        @include('contracts._assignments')
                    </div>
                </div>
            </div>

            {{-- Invoices Tab --}}
            <div class="tab-pane fade {{ ($activeTab ?? '') === 'invoices' ? 'show active' : '' }}" id="invoices" role="tabpanel">
                @if(($activeTab ?? '') === 'invoices')
                    @include('invoices._list', [
                        'listRoute' => 'contracts.invoices',
                        'prefilter' => ['contract' => $contract->id, 'contract_id' => (string) $contract->id],
                        'invoices' => $invoices,
                        'filters' => $invoiceFilters,
                        'clients' => $invoiceClients,
                        'statuses' => $invoiceStatuses,
                    ])
                @else
                    <div class="card card-static shadow-sm">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-receipt me-2"></i>Recent Invoices
                                @if($recentInvoices->isNotEmpty())
                                    <span class="badge bg-light text-dark ms-1">{{ $recentInvoices->count() }}</span>
                                @endif
                            </div>
                            <div class="d-flex gap-2">
                                <a href="{{ route('contracts.invoices', $contract) }}" class="btn btn-outline-primary btn-sm">
                                    View all invoices
                                </a>
                                <a href="{{ route('invoices.create', ['client_id' => $contract->client_id, 'contract_id' => $contract->id]) }}" class="btn btn-primary btn-sm">
                                    <i class="bi bi-plus-lg me-1"></i>New Invoice
                                </a>
                            </div>
                        </div>
                        @if($recentInvoices->isEmpty())
                            <div class="card-body text-muted text-center py-3">
                                No invoices generated yet.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Invoice #</th>
                                            <th>Date</th>
                                            <th>Subtotal</th>
                                            <th>Tax</th>
                                            <th>Total</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($recentInvoices as $invoice)
                                            <tr class="cursor-pointer" onclick="window.location='{{ route('invoices.show', $invoice) }}'">
                                                <td>
                                                    <x-invoice-badge :invoice="$invoice" />
                                                </td>
                                                <td class="small">{{ $invoice->invoice_date->format('M j, Y') }}</td>
                                                <td class="small">${{ number_format($invoice->subtotal, 2) }}</td>
                                                <td class="small">${{ number_format($invoice->tax, 2) }}</td>
                                                <td class="small fw-semibold">${{ number_format($invoice->total, 2) }}</td>
                                                <td>
                                                    @if($invoice->isOverdue())
                                                        <span class="badge bg-danger">Overdue</span>
                                                    @else
                                                        <span class="badge {{ $invoice->status->badgeClass() }}">{{ $invoice->status->label() }}</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Prepay History Tab --}}
            @if($contract->has_prepay)
                <div class="tab-pane fade" id="prepay-history" role="tabpanel">
                    <div class="card card-static shadow-sm">
                        <div class="card-header">
                            <i class="bi bi-clock-history me-2"></i>Prepay History
                            @if($contract->prepayTransactions->isNotEmpty())
                                <span class="badge bg-light text-dark ms-1">{{ $contract->prepayTransactions->count() }}</span>
                            @endif
                        </div>
                        @if($contract->prepayTransactions->isEmpty())
                            <div class="card-body text-muted text-center py-3">
                                No prepay transactions recorded yet.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Description</th>
                                            <th class="text-end">{{ $contract->prepay_as_amount ? 'Amount' : 'Hours' }}</th>
                                            <th>Note</th>
                                            <th class="d-none d-md-table-cell">By</th>
                                            <th>Invoice #</th>
                                            @unless($contract->prepay_as_amount)
                                                <th>Expires</th>
                                            @endunless
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($contract->prepayTransactions as $txn)
                                            <tr>
                                                <td class="small text-nowrap">
                                                    @if($txn->source)
                                                        @php
                                                            $txnValue = $contract->prepay_as_amount ? $txn->amount : $txn->hours;
                                                            $txnIsPositive = $txnValue !== null ? (float) $txnValue >= 0 : $txn->source->isCredit();
                                                        @endphp
                                                        <span title="{{ $txn->source->label() }}"
                                                              class="me-1 {{ $txnIsPositive ? 'text-success' : 'text-danger' }}">
                                                            <i class="bi {{ $txnIsPositive ? 'bi-plus-circle' : 'bi-dash-circle' }}"></i>
                                                        </span>
                                                    @endif
                                                    {{ $txn->date?->format('M j, Y') ?? '-' }}
                                                </td>
                                                <td class="small">{{ $txn->description ?? '-' }}</td>
                                                <td class="small text-end">{{ $txn->formatValue($contract->prepay_as_amount) }}</td>
                                                <td class="small">{{ $txn->note ?? '-' }}</td>
                                                <td class="small d-none d-md-table-cell">{{ $txn->user?->name ?? '-' }}</td>
                                                <td class="small">{{ $txn->invoice_number ?? '-' }}</td>
                                                @unless($contract->prepay_as_amount)
                                                    <td class="small text-nowrap">
                                                        @if($txn->expiry_date)
                                                            @php $isExpired = $txn->expiry_date->isPast(); @endphp
                                                            <span class="{{ $isExpired ? 'text-muted text-decoration-line-through' : '' }}">
                                                                {{ $txn->expiry_date->toAppTz()->format('M j, Y') }}
                                                            </span>
                                                            @if($isExpired)
                                                                <span class="text-muted ms-1"><i class="bi bi-x-circle"></i> Expired</span>
                                                            @endif
                                                        @else
                                                            —
                                                        @endif
                                                    </td>
                                                @endunless
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Tickets Tab --}}
            <div class="tab-pane fade {{ ($activeTab ?? '') === 'tickets' ? 'show active' : '' }}" id="tickets" role="tabpanel">
                @if(($activeTab ?? '') === 'tickets')
                    @include('tickets._list', [
                        'listRoute' => 'contracts.tickets',
                        'prefilter' => ['contract' => $contract->id, 'contract_id' => $contract->id],
                        'filters' => $ticketFilters,
                        'clients' => $ticketClients,
                        'users' => $ticketUsers,
                        'statuses' => $ticketStatuses,
                        'priorities' => $ticketPriorities,
                        'types' => $ticketTypes,
                        'sources' => $ticketSources,
                    ])
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4 detail-sidebar">
        <div class="card shadow-sm">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>Summary</div>
            <div class="card-body">
                <table class="table table-borderless table-sm mb-0">
                    <tr>
                        <th class="text-muted">Client</th>
                        <td><x-client-badge :client="$contract->client" :size="24" /></td>
                    </tr>
                    <tr>
                        <th class="text-muted">Created</th>
                        <td>{{ $contract->created_at->format('M j, Y') }}</td>
                    </tr>
                    @if($contract->cancelled_at)
                        <tr>
                            <th class="text-muted">Cancelled</th>
                            <td class="text-danger">{{ $contract->cancelled_at->format('M j, Y') }}</td>
                        </tr>
                        @if($contract->cancellation_reason)
                            <tr>
                                <th class="text-muted">Reason</th>
                                <td class="small">{{ $contract->cancellation_reason }}</td>
                            </tr>
                        @endif
                    @endif
                </table>
            </div>
        </div>

        {{-- Prepay Balance Card --}}
        @if($contract->has_prepay)
            <div class="card shadow-sm mt-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-wallet2 me-2"></i>Prepay Balance</div>
                    <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#prepayAdjustModal">
                        <i class="bi bi-plus-slash-minus me-1"></i>Adjust
                    </button>
                </div>
                <div class="card-body text-center">
                    @php
                        $balance = (float) $contract->prepay_balance;
                        $isAmount = $contract->prepay_as_amount;
                        $lowThreshold = $isAmount ? 500 : 4;
                        $balanceClass = $balance <= 0 ? 'text-danger' : ($balance < $lowThreshold ? 'text-warning' : 'text-success');
                    @endphp

                    <div class="display-6 fw-bold {{ $balanceClass }} mb-3">
                        {{ $contract->prepay_balance_formatted }}
                    </div>

                    <table class="table table-borderless table-sm text-start mb-3">
                        <tr>
                            <th class="text-muted">Purchased</th>
                            <td class="text-end">
                                {{ $isAmount ? '$' . number_format($contract->prepay_total, 2) : number_format($contract->prepay_total, 2) . ' hrs' }}
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Used</th>
                            <td class="text-end">
                                {{ $isAmount ? '$' . number_format($contract->prepay_used, 2) : number_format($contract->prepay_used, 2) . ' hrs' }}
                            </td>
                        </tr>
                        <tr class="border-top">
                            <th class="text-muted">Balance</th>
                            <td class="text-end fw-semibold {{ $balanceClass }}">
                                {{ $contract->prepay_balance_formatted }}
                            </td>
                        </tr>
                    </table>

                    @if($contract->burn_rate && $contract->burn_rate > 0)
                        <div class="small text-muted mb-1">
                            <i class="bi bi-graph-down-arrow me-1"></i>
                            Consuming ~{{ $contract->burn_rate_formatted }} (last 30 days)
                        </div>
                        @if($contract->days_until_depleted)
                            <div class="small text-muted">
                                <i class="bi bi-hourglass-split me-1"></i>
                                ~{{ $contract->days_until_depleted }} days remaining at current rate
                            </div>
                        @endif
                    @endif

                    @if($balance <= 0)
                        <div class="alert alert-danger py-2 mt-3 mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            <strong>Depleted</strong> — prepay balance is exhausted
                        </div>
                    @elseif($balance < $lowThreshold)
                        <div class="alert alert-warning py-2 mt-3 mb-0">
                            <i class="bi bi-exclamation-circle me-1"></i>
                            <strong>Low balance</strong> — consider scheduling a top-up
                        </div>
                    @endif
                </div>
            </div>

            {{-- Prepay Adjust Modal is placed outside the row to avoid z-index issues --}}

            {{-- Portal Purchase SKU --}}
            @if(!$contract->prepay_as_amount)
                <div class="card shadow-sm mt-3">
                    <div class="card-header">
                        <i class="bi bi-cart-plus me-2"></i>Portal Purchases
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('contracts.update-portal-sku', $contract) }}">
                            @csrf
                            @method('PUT')
                            <div class="mb-2">
                                <label for="portalPrepaySku" class="form-label text-muted small">Allow portal clients to purchase prepaid time using this SKU:</label>
                                <select name="portal_prepay_sku_id" id="portalPrepaySku" class="form-select form-select-sm">
                                    <option value="">— Disabled —</option>
                                    @foreach(\App\Models\Sku::active()->whereNotNull('prepaid_time_minutes')->where('prepaid_time_minutes', '>', 0)->orderBy('name')->get() as $sku)
                                        <option value="{{ $sku->id }}" {{ $contract->portal_prepay_sku_id == $sku->id ? 'selected' : '' }}>
                                            {{ $sku->name }} — ${{ number_format($sku->unit_price, 2) }} / {{ number_format($sku->prepaid_time_minutes / 60, 1) }}h
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                        </form>
                    </div>
                </div>
            @endif

            {{-- Prepay Alert & Expiration Settings --}}
            @if($contract->has_prepay && !$contract->prepay_as_amount)
                <div class="card shadow-sm mt-3">
                    <div class="card-header">
                        <i class="bi bi-bell me-2"></i>Balance Alerts &amp; Expiration
                    </div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('contracts.update-alert-settings', $contract) }}">
                            @csrf
                            @method('PUT')
                            <div class="mb-3">
                                <label for="prepayAlertThreshold" class="form-label text-muted small">
                                    Alert when balance drops below (hours):
                                </label>
                                <input type="number" name="prepay_alert_threshold" id="prepayAlertThreshold"
                                       class="form-control form-control-sm" style="max-width: 150px;"
                                       value="{{ $contract->prepay_alert_threshold }}"
                                       min="0" step="0.25" placeholder="Disabled">
                                <div class="form-text">Leave blank to disable alerts.</div>
                            </div>
                            <div class="mb-3">
                                <label for="prepayExpiryMonths" class="form-label text-muted small">
                                    Prepaid time expires after (months):
                                </label>
                                <input type="number" name="prepay_expiry_months" id="prepayExpiryMonths"
                                       class="form-control form-control-sm" style="max-width: 150px;"
                                       value="{{ $contract->prepay_expiry_months }}"
                                       min="1" max="120" step="1" placeholder="Never">
                                <div class="form-text">
                                    Leave blank for no expiration. Applies to credits added from now on (no backfill).
                                </div>
                            </div>
                            @if($contract->portal_prepay_sku_id)
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input type="hidden" name="prepay_auto_topup_enabled" value="0">
                                        <input type="checkbox" name="prepay_auto_topup_enabled" value="1"
                                               class="form-check-input" id="autoTopUpEnabled"
                                               {{ $contract->prepay_auto_topup_enabled ? 'checked' : '' }}>
                                        <label class="form-check-label" for="autoTopUpEnabled">
                                            Auto top-up when balance is low
                                        </label>
                                    </div>
                                </div>
                                <div class="mb-3" id="autoTopUpQtyRow" style="{{ $contract->prepay_auto_topup_enabled ? '' : 'display:none;' }}">
                                    <label for="prepayAutoTopupQty" class="form-label text-muted small">
                                        Auto top-up quantity (units of {{ $contract->portalPrepaySku?->name ?? 'SKU' }}):
                                    </label>
                                    <input type="number" name="prepay_auto_topup_qty" id="prepayAutoTopupQty"
                                           class="form-control form-control-sm" style="max-width: 150px;"
                                           value="{{ $contract->prepay_auto_topup_qty ?? 1 }}"
                                           min="1" max="99">
                                    @if($contract->portalPrepaySku?->prepaid_time_minutes)
                                        <div class="form-text">
                                            {{ number_format($contract->portalPrepaySku->prepaid_time_minutes / 60, 1) }}h per unit
                                        </div>
                                    @endif
                                </div>
                            @endif
                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                            @if($contract->prepay_alert_notified_at)
                                <span class="text-muted small ms-2">
                                    Last alert: {{ $contract->prepay_alert_notified_at->toAppTz()->format('M j, Y g:i A') }}
                                </span>
                            @endif
                        </form>
                    </div>
                </div>
                <script>
                    document.getElementById('autoTopUpEnabled')?.addEventListener('change', function() {
                        document.getElementById('autoTopUpQtyRow').style.display = this.checked ? '' : 'none';
                    });
                </script>
            @endif
        @else
            {{-- Initialize Prepay (small action, not a standalone card) --}}
            <div class="card shadow-sm mt-3">
                <div class="card-body text-center py-3">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#initPrepayModal">
                        <i class="bi bi-wallet2 me-1"></i>Initialize Prepay Tracking
                    </button>
                </div>
            </div>
            {{-- Init Prepay Modal is placed outside the row to avoid z-index issues --}}
        @endif

        {{-- SLA Terms --}}
        <div class="card shadow-sm mt-3">
            <div class="card-header">
                <i class="bi bi-speedometer2 me-2"></i>SLA Terms
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('contracts.update-sla-terms', $contract) }}">
                    @csrf
                    @method('PUT')

                    @php $hasSla = !empty($contract->sla_terms); @endphp

                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="sla_enabled" value="0">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="slaEnabled" name="sla_enabled" value="1"
                               {{ $hasSla ? 'checked' : '' }}
                               onchange="document.getElementById('slaFields').classList.toggle('d-none', !this.checked)">
                        <label class="form-check-label" for="slaEnabled">SLA enabled on this contract</label>
                    </div>

                    <div id="slaFields" class="{{ $hasSla ? '' : 'd-none' }}">
                        <div class="small text-muted mb-2">Response time (hours)</div>
                        <div class="row g-2 mb-3">
                            @foreach(['p1' => 'P1', 'p2' => 'P2', 'p3' => 'P3', 'p4' => 'P4'] as $key => $label)
                            <div class="col-3">
                                <label class="form-label small mb-1">{{ $label }}</label>
                                <input type="number" step="any" min="0.25"
                                       class="form-control form-control-sm"
                                       name="response_{{ $key }}"
                                       value="{{ $contract->sla_terms['response'][$key] ?? '' }}"
                                       placeholder="—">
                            </div>
                            @endforeach
                        </div>

                        <div class="small text-muted mb-2">Resolution time (hours)</div>
                        <div class="row g-2 mb-3">
                            @foreach(['p1' => 'P1', 'p2' => 'P2', 'p3' => 'P3', 'p4' => 'P4'] as $key => $label)
                            <div class="col-3">
                                <label class="form-label small mb-1">{{ $label }}</label>
                                <input type="number" step="any" min="0.25"
                                       class="form-control form-control-sm"
                                       name="resolution_{{ $key }}"
                                       value="{{ $contract->sla_terms['resolution'][$key] ?? '' }}"
                                       placeholder="—">
                            </div>
                            @endforeach
                        </div>
                    </div>

                    <button type="submit" class="btn btn-sm btn-primary">Save SLA Terms</button>
                </form>
            </div>
        </div>

        {{-- Contract Documents --}}
        <div class="card shadow-sm mt-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div><i class="bi bi-file-earmark-pdf me-2"></i>Documents</div>
                @if($contract->documents->isNotEmpty())
                    <span class="badge bg-light text-dark">{{ $contract->documents->count() }}</span>
                @endif
            </div>
            <div class="card-body">
                {{-- Upload form --}}
                <form method="POST" action="{{ route('contracts.upload-document', $contract) }}"
                      enctype="multipart/form-data" class="mb-3" id="doc-upload-form">
                    @csrf
                    <div class="input-group input-group-sm">
                        <input type="file" class="form-control form-control-sm" name="document" id="doc-upload-input" accept=".pdf" required>
                        <button type="submit" class="btn btn-outline-primary btn-sm" id="doc-upload-btn">
                            <i class="bi bi-upload me-1"></i>Upload
                        </button>
                    </div>
                    <div class="text-muted mt-1" style="font-size: 0.7rem;">PDF, max 20 MB</div>
                    <div class="text-danger small mt-1 d-none" id="doc-upload-error"></div>
                    @error('document')
                        <div class="text-danger small mt-1">{{ $message }}</div>
                    @enderror
                </form>

                {{-- Document list --}}
                @forelse($contract->documents as $doc)
                    <div class="doc-item mb-2 pb-2 {{ !$loop->last ? 'border-bottom' : '' }}" data-doc-id="{{ $doc->id }}">
                        <div class="d-flex align-items-start justify-content-between">
                            <div class="small flex-grow-1" style="min-width: 0;">
                                <a href="{{ route('contracts.download-document', [$contract, $doc]) }}"
                                   class="text-decoration-none text-truncate d-block" title="{{ $doc->original_filename }}">
                                    <i class="bi bi-file-earmark-pdf text-danger me-1"></i>{{ $doc->original_filename }}
                                </a>
                                <div class="text-muted" style="font-size: 0.7rem;">
                                    {{ number_format($doc->file_size / 1024) }} KB
                                    &middot; {{ $doc->created_at->diffForHumans() }}
                                    @if($doc->uploader)
                                        &middot; {{ $doc->uploader->name }}
                                    @endif
                                </div>
                                <div class="mt-1">
                                    @if($doc->summary_status === \App\Enums\DocumentSummaryStatus::Completed)
                                        <span class="doc-status-badge badge bg-success" style="font-size: 0.65rem;">
                                            <i class="bi bi-check-circle me-1"></i>Summary ready
                                        </span>
                                    @elseif(in_array($doc->summary_status, [\App\Enums\DocumentSummaryStatus::Pending, \App\Enums\DocumentSummaryStatus::Processing]))
                                        <span class="doc-status-badge badge bg-info" style="font-size: 0.65rem;">
                                            <span class="spinner-border spinner-border-sm" style="width: 0.5rem; height: 0.5rem;"></span>
                                            Summarizing...
                                        </span>
                                    @elseif($doc->summary_status === \App\Enums\DocumentSummaryStatus::Failed)
                                        <span class="doc-status-badge badge bg-danger" style="font-size: 0.65rem;" title="AI summarization failed">
                                            <i class="bi bi-exclamation-triangle me-1"></i>Failed
                                        </span>
                                    @elseif($doc->summary_status === \App\Enums\DocumentSummaryStatus::Skipped)
                                        <span class="doc-status-badge badge bg-warning text-dark" style="font-size: 0.65rem;" title="No extractable text found (may be a scanned image)">
                                            <i class="bi bi-exclamation-circle me-1"></i>No text extracted
                                        </span>
                                    @endif
                                </div>
                            </div>
                            <div class="d-flex gap-1 ms-2 flex-shrink-0">
                                @if($doc->extracted_text && !in_array($doc->summary_status, [\App\Enums\DocumentSummaryStatus::Processing, \App\Enums\DocumentSummaryStatus::Pending]))
                                    <form method="POST" action="{{ route('contracts.resummarize-document', [$contract, $doc]) }}">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-secondary p-1" style="font-size: 0.7rem; line-height: 1;" title="Re-summarize">
                                            <i class="bi bi-arrow-clockwise"></i>
                                        </button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('contracts.delete-document', [$contract, $doc]) }}"
                                      onsubmit="return confirm('Delete this document?')">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-outline-danger p-1" style="font-size: 0.7rem; line-height: 1;" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        {{-- Inline summary (expand/collapse) --}}
                        @if($doc->summary_status === \App\Enums\DocumentSummaryStatus::Completed && $doc->ai_summary)
                            <div class="mt-2">
                                <a class="text-decoration-none small text-primary" data-bs-toggle="collapse"
                                   href="#summary-{{ $doc->id }}" role="button" aria-expanded="false">
                                    <i class="bi bi-robot me-1"></i>View summary
                                </a>
                                <div class="collapse" id="summary-{{ $doc->id }}">
                                    <div class="mt-1 p-2 bg-light rounded small" style="white-space: pre-line; font-size: 0.75rem; max-height: 300px; overflow-y: auto;">{{ $doc->ai_summary }}</div>
                                    <div class="text-muted mt-1" style="font-size: 0.65rem;">
                                        Summarized {{ $doc->summarized_at->diffForHumans() }}
                                        @if($doc->summary_tokens_used)
                                            &middot; {{ number_format($doc->summary_tokens_used) }} tokens
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endif

                        {{-- Placeholder for polling-injected summaries --}}
                        <div class="doc-summary-placeholder"></div>
                    </div>
                @empty
                    <div class="text-muted small text-center">No documents uploaded.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- Modals placed outside .row to avoid sidebar z-index trapping the backdrop --}}
@if(!$contract->has_prepay)
<div class="modal fade" id="initPrepayModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('contracts.initialize-prepay', $contract) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Initialize Prepay</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tracking Unit</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="prepay_as_amount" id="trackHours" value="0" checked>
                            <label class="btn btn-outline-primary" for="trackHours">Hours</label>
                            <input type="radio" class="btn-check" name="prepay_as_amount" id="trackDollars" value="1">
                            <label class="btn btn-outline-primary" for="trackDollars">Dollars</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="initialBalance" class="form-label">Initial Balance (optional)</label>
                        <input type="number" class="form-control" id="initialBalance" name="initial_balance"
                               step="0.01" min="0" placeholder="0">
                    </div>
                    <div class="mb-3">
                        <label for="initNote" class="form-label">Note (optional)</label>
                        <input type="text" class="form-control" id="initNote" name="note"
                               maxlength="500" placeholder="e.g. Migrated from previous system">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Initialize</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endif

@if($contract->has_prepay)
<div class="modal fade" id="prepayAdjustModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" action="{{ route('contracts.prepay-adjust', $contract) }}">
            @csrf
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Adjust Prepay Balance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="type" id="adjustCredit" value="credit" checked>
                            <label class="btn btn-outline-success" for="adjustCredit">Credit (add)</label>
                            <input type="radio" class="btn-check" name="type" id="adjustDebit" value="debit">
                            <label class="btn btn-outline-danger" for="adjustDebit">Debit (subtract)</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="adjustValue" class="form-label">
                            {{ $contract->prepay_as_amount ? 'Amount ($)' : 'Hours' }}
                        </label>
                        <input type="number" class="form-control" id="adjustValue" name="value"
                               step="any" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label for="adjustNote" class="form-label">Note</label>
                        <input type="text" class="form-control" id="adjustNote" name="note"
                               maxlength="500" required placeholder="Reason for adjustment">
                    </div>
                    @unless($contract->prepay_as_amount)
                        <div class="mb-3">
                            <label for="adjustExpiry" class="form-label">Expiry date <span class="text-muted">(credits only)</span></label>
                            <input type="date" class="form-control" id="adjustExpiry" name="expiry_date">
                            <div class="form-text">Leave blank to use this contract's expiration policy.</div>
                        </div>
                    @endunless
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endif

@endsection

@push('styles')
<style>
.cursor-pointer { cursor: pointer; }
</style>
@endpush

@include('components._tab-persistence', ['tabListId' => 'contractTabs', 'storageKey' => 'contract-show-tab'])

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Client-side file size validation (20 MB max, matches server-side rule)
    var maxBytes = 20 * 1024 * 1024;
    var uploadInput = document.getElementById('doc-upload-input');
    var uploadError = document.getElementById('doc-upload-error');
    var uploadBtn = document.getElementById('doc-upload-btn');

    if (uploadInput) {
        uploadInput.addEventListener('change', function () {
            if (this.files.length && this.files[0].size > maxBytes) {
                var sizeMB = (this.files[0].size / 1024 / 1024).toFixed(1);
                uploadError.textContent = 'File is ' + sizeMB + ' MB — exceeds the 20 MB limit.';
                uploadError.classList.remove('d-none');
                uploadBtn.disabled = true;
            } else {
                uploadError.classList.add('d-none');
                uploadBtn.disabled = false;
            }
        });
    }

    // Poll for pending/processing document summaries
    const pendingDocs = document.querySelectorAll('.doc-item');
    const pollIntervals = {};

    pendingDocs.forEach(function (item) {
        const docId = item.dataset.docId;
        const badge = item.querySelector('.doc-status-badge');
        if (!badge) return;

        const badgeText = badge.textContent.trim();
        if (badgeText !== 'Summarizing...') return;

        const contractId = {{ $contract->id }};
        let attempts = 0;
        const maxAttempts = 24; // 2 minutes at 5s intervals

        pollIntervals[docId] = setInterval(function () {
            attempts++;
            if (attempts > maxAttempts) {
                clearInterval(pollIntervals[docId]);
                return;
            }

            fetch(`/contracts/${contractId}/documents/${docId}/status`, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(function (data) {
                if (data.status === 'completed') {
                    clearInterval(pollIntervals[docId]);
                    badge.className = 'doc-status-badge badge bg-success';
                    badge.style.fontSize = '0.65rem';
                    badge.innerHTML = '<i class="bi bi-check-circle me-1"></i>Summary ready';

                    // Inject the summary
                    const placeholder = item.querySelector('.doc-summary-placeholder');
                    if (placeholder && data.summary) {
                        placeholder.innerHTML =
                            '<div class="mt-2">' +
                                '<a class="text-decoration-none small text-primary" data-bs-toggle="collapse" href="#summary-' + docId + '" role="button" aria-expanded="false">' +
                                    '<i class="bi bi-robot me-1"></i>View summary' +
                                '</a>' +
                                '<div class="collapse" id="summary-' + docId + '">' +
                                    '<div class="mt-1 p-2 bg-light rounded small" style="white-space: pre-line; font-size: 0.75rem; max-height: 300px; overflow-y: auto;">' +
                                        data.summary.replace(/</g, '&lt;').replace(/>/g, '&gt;') +
                                    '</div>' +
                                    '<div class="text-muted mt-1" style="font-size: 0.65rem;">Summarized ' + (data.summarized_at || 'just now') + '</div>' +
                                '</div>' +
                            '</div>';
                    }
                } else if (data.status === 'failed') {
                    clearInterval(pollIntervals[docId]);
                    badge.className = 'doc-status-badge badge bg-danger';
                    badge.style.fontSize = '0.65rem';
                    badge.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>Failed';
                } else if (data.status === 'skipped') {
                    clearInterval(pollIntervals[docId]);
                    badge.className = 'doc-status-badge badge bg-warning text-dark';
                    badge.style.fontSize = '0.65rem';
                    badge.innerHTML = '<i class="bi bi-exclamation-circle me-1"></i>No text extracted';
                }
            })
            .catch(function () {
                // Silently ignore network errors during polling
            });
        }, 5000);
    });

});
</script>
@endpush
