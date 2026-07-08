@extends('layouts.app')

@section('title', 'New Invoice')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('invoices.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Invoices
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title mb-0">New Invoice</h4>
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

<form method="POST" action="{{ route('invoices.store') }}" id="invoiceForm">
    @csrf

    <div class="row g-4">
        {{-- Left column: Header fields --}}
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header"><i class="bi bi-info-circle me-2"></i>Invoice Details</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="client_id" class="form-label">Client <span class="text-danger">*</span></label>
                        <select class="form-select @error('client_id') is-invalid @enderror"
                                id="client_id" name="client_id" required onchange="onClientChanged()">
                            <option value="">-- Select Client --</option>
                            @foreach($clients as $c)
                                <option value="{{ $c->id }}" {{ old('client_id', $preselectedClientId) == $c->id ? 'selected' : '' }}>
                                    {{ $c->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('client_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="contract_id" class="form-label">Contract</label>
                        <select class="form-select @error('contract_id') is-invalid @enderror"
                                id="contract_id" name="contract_id" onchange="onContractChanged()">
                            <option value="">-- None (standalone) --</option>
                        </select>
                        <div class="form-text">Optional — link to a contract for reporting.</div>
                        @error('contract_id')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="invoice_date" class="form-label">Invoice Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control @error('invoice_date') is-invalid @enderror"
                               id="invoice_date" name="invoice_date"
                               value="{{ old('invoice_date', now()->format('Y-m-d')) }}" required>
                        @error('invoice_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="due_date" class="form-label">Due Date <span class="text-danger">*</span></label>
                        <input type="date" class="form-control @error('due_date') is-invalid @enderror"
                               id="due_date" name="due_date"
                               value="{{ old('due_date', now()->addDays(30)->format('Y-m-d')) }}" required>
                        @error('due_date')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control @error('notes') is-invalid @enderror"
                                  id="notes" name="notes" rows="3">{{ old('notes') }}</textarea>
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
                        {{-- Lines will be added by JS --}}
                    </div>

                    <div class="border-top pt-3 mt-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="text-muted fw-semibold">Subtotal</span>
                            <span class="fw-bold" id="runningSubtotal">$0.00</span>
                        </div>
                        <small class="text-muted">Tax will be calculated when pushed to your billing provider.</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Create Invoice</button>
        <a href="{{ route('invoices.index') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection

@push('scripts')
@include('invoices._line_scripts', ['lineIndex' => 0, 'skus' => $skus])

<script>
const contractsByClient = @json($contractsByClient);
const preselectedContractId = {{ $preselectedContractId ? (int)$preselectedContractId : 'null' }};

function onClientChanged() {
    const clientId = document.getElementById('client_id').value;
    const contractSelect = document.getElementById('contract_id');
    const contracts = contractsByClient[clientId] || [];

    contractSelect.innerHTML = '<option value="">-- None (standalone) --</option>';
    contracts.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        opt.dataset.paymentTerms = c.payment_terms_days || '';
        contractSelect.appendChild(opt);
    });

    // Auto-select preselected contract on initial load
    if (preselectedContractId) {
        contractSelect.value = preselectedContractId;
        onContractChanged();
    }
}

function onContractChanged() {
    const contractSelect = document.getElementById('contract_id');
    const opt = contractSelect.options[contractSelect.selectedIndex];
    const terms = opt?.dataset?.paymentTerms;

    if (terms && parseInt(terms) > 0) {
        const invoiceDate = document.getElementById('invoice_date').value;
        if (invoiceDate) {
            const d = new Date(invoiceDate + 'T00:00:00');
            d.setDate(d.getDate() + parseInt(terms));
            document.getElementById('due_date').value = d.toISOString().split('T')[0];
        }
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const clientId = document.getElementById('client_id').value;
    if (clientId) {
        onClientChanged();
    }
    // Add one blank line to start
    addLine();
});
</script>
@endpush
