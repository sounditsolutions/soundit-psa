@extends('layouts.app')

@section('title', 'Edit ' . $invoice->invoice_number . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('invoices.show', $invoice) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to {{ $invoice->invoice_number }}
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title mb-1">Edit {{ $invoice->invoice_number }}</h4>
        <span class="badge {{ $invoice->status->badgeClass() }}">{{ $invoice->status->label() }}</span>
        <span class="text-muted ms-2 small">{{ $invoice->client->name }}</span>
    </div>
</div>

@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($invoice->qbo_invoice_id)
    <div class="alert alert-info d-flex align-items-center mb-3">
        <i class="bi bi-cloud-arrow-up me-2"></i>
        This invoice is synced to QuickBooks. Changes will be pushed automatically on save.
    </div>
@endif

<form method="POST" action="{{ route('invoices.update', $invoice) }}" id="invoiceForm">
    @csrf
    @method('PATCH')

    <div class="row g-4">
        {{-- Left column: Header fields --}}
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header"><i class="bi bi-info-circle me-2"></i>Invoice Details</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Client</label>
                        <p class="form-control-plaintext">{{ $invoice->client->name }}</p>
                    </div>
                    @if($invoice->contract)
                    <div class="mb-3">
                        <label class="form-label">Contract</label>
                        <p class="form-control-plaintext">{{ $invoice->contract->name }}</p>
                    </div>
                    @endif
                    <div class="mb-3">
                        <label for="invoice_date" class="form-label">Invoice Date</label>
                        <input type="date" class="form-control @error('invoice_date') is-invalid @enderror"
                               id="invoice_date" name="invoice_date"
                               value="{{ old('invoice_date', $invoice->invoice_date->format('Y-m-d')) }}" required>
                        @error('invoice_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="due_date" class="form-label">Due Date</label>
                        <input type="date" class="form-control @error('due_date') is-invalid @enderror"
                               id="due_date" name="due_date"
                               value="{{ old('due_date', $invoice->due_date->format('Y-m-d')) }}" required>
                        @error('due_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control @error('notes') is-invalid @enderror"
                                  id="notes" name="notes" rows="3">{{ old('notes', $invoice->notes) }}</textarea>
                        @error('notes')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- Right column: Line items --}}
        <div class="col-lg-8">
            <div class="card shadow-sm">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div><i class="bi bi-list-ol me-2"></i>Line Items</div>
                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="addLine()">
                        <i class="bi bi-plus-lg me-1"></i>Add Line
                    </button>
                </div>
                <div class="card-body">
                    <div id="linesContainer">
                        @foreach($invoice->lines as $i => $line)
                            @include('invoices._line_row', ['i' => $i, 'line' => $line, 'skus' => $skus])
                        @endforeach
                    </div>

                    <div class="border-top pt-3 mt-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted fw-semibold">Subtotal</span>
                            <span class="fw-bold" id="runningSubtotal">${{ number_format($invoice->subtotal, 2) }}</span>
                        </div>
                        <small class="text-muted">Tax will be calculated when pushed to your billing provider.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="{{ route('invoices.show', $invoice) }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection

@push('scripts')
@include('invoices._line_scripts', ['lineIndex' => $invoice->lines->count(), 'skus' => $skus])
@endpush
