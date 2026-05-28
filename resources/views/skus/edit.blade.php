@extends('layouts.app')

@section('title', $sku->name . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('skus.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to SKUs
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col d-flex justify-content-between align-items-center">
        <div>
            <h4 class="section-title mb-1">{{ $sku->name }}</h4>
            <span class="text-muted">{{ $sku->sku_code }}</span>
            @if(!$sku->is_active)
                <span class="badge bg-secondary ms-2">Inactive</span>
            @endif
        </div>
        <div class="d-flex gap-2">
            @if(\App\Support\StripeConfig::isConfigured())
                <form method="POST" action="{{ route('skus.push-stripe', $sku) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-cloud-upload me-1"></i>{{ $sku->stripe_product_id ? 'Push to Stripe' : 'Create in Stripe' }}
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('skus.push-qbo', $sku) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-cloud-upload me-1"></i>{{ $sku->qbo_item_id ? 'Push to QBO' : 'Create in QBO' }}
                    </button>
                </form>
            @endif
            <button type="button" class="btn btn-outline-danger btn-sm" title="Delete SKU"
                    data-bs-toggle="modal" data-bs-target="#deleteSkuModal">
                <i class="bi bi-trash"></i>
            </button>
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

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header"><i class="bi bi-pencil me-2"></i>SKU Details</div>
            <div class="card-body">
                <form method="POST" action="{{ route('skus.update', $sku) }}">
                    @csrf
                    @method('PATCH')
                    @include('skus._form', ['sku' => $sku])
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="{{ route('skus.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        @if(\App\Support\StripeConfig::isConfigured())
            <div class="card shadow-sm mb-3">
                <div class="card-header"><i class="bi bi-stripe me-2"></i>Stripe</div>
                <div class="card-body">
                    @if($sku->stripe_product_id)
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <th class="text-muted" style="width:40%">Product ID</th>
                                <td class="small font-monospace">{{ $sku->stripe_product_id }}</td>
                            </tr>
                            <tr>
                                <th class="text-muted">Price ID</th>
                                <td class="small font-monospace">{{ $sku->stripe_price_id ?? '—' }}</td>
                            </tr>
                            <tr>
                                <th class="text-muted">Last Synced</th>
                                <td>{{ $sku->stripe_synced_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        </table>
                    @else
                        <p class="text-muted small mb-0">Not linked to Stripe. Use "Import from Stripe" or "Create in Stripe" to link.</p>
                    @endif
                </div>
            </div>
        @endif

        @if($sku->qbo_item_id || !\App\Support\StripeConfig::isConfigured())
            <div class="card shadow-sm mb-3">
                <div class="card-header"><i class="bi bi-cloud me-2"></i>QuickBooks</div>
                <div class="card-body">
                    @if($sku->qbo_item_id)
                        <table class="table table-borderless table-sm mb-0">
                            <tr>
                                <th class="text-muted" style="width:40%">QBO Item ID</th>
                                <td class="small font-monospace">{{ $sku->qbo_item_id }}</td>
                            </tr>
                            <tr>
                                <th class="text-muted">Last Synced</th>
                                <td>{{ $sku->qbo_synced_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                            @if($sku->qbo_sync_error)
                                <tr>
                                    <th class="text-muted">Error</th>
                                    <td class="text-danger small">{{ $sku->qbo_sync_error }}</td>
                                </tr>
                            @endif
                        </table>
                    @else
                        <p class="text-muted small mb-0">Not linked to QuickBooks.</p>
                    @endif
                </div>
            </div>
        @endif

        @if($sku->margin !== null)
            <div class="card shadow-sm mb-3">
                <div class="card-header"><i class="bi bi-graph-up me-2"></i>Margin</div>
                <div class="card-body text-center">
                    <div class="display-6 fw-bold {{ $sku->margin >= 50 ? 'text-success' : ($sku->margin >= 20 ? '' : 'text-danger') }}">
                        {{ $sku->margin }}%
                    </div>
                    <div class="small text-muted mt-1">
                        ${{ number_format($sku->unit_price - $sku->unit_cost, 2) }} per unit
                    </div>
                </div>
            </div>
        @endif

    </div>
</div>

<div class="modal fade" id="deleteSkuModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Delete SKU</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>This will soft-delete <strong>{{ $sku->name }}</strong> ({{ $sku->sku_code }}).</p>
                <p class="text-muted small">The record can be restored later if needed.</p>
                <label for="deleteSkuConfirm" class="form-label mt-2">
                    To confirm, type <code>{{ $sku->sku_code }}</code> below.
                </label>
                <input type="text" class="form-control" id="deleteSkuConfirm" autocomplete="off">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="{{ route('skus.destroy', $sku) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger" id="deleteSkuBtn" disabled>
                        <i class="bi bi-trash me-1"></i>Delete SKU
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var expected = @json($sku->sku_code);
    var input = document.getElementById('deleteSkuConfirm');
    var btn = document.getElementById('deleteSkuBtn');
    input?.addEventListener('input', function() {
        btn.disabled = input.value !== expected;
    });
    document.getElementById('deleteSkuModal')?.addEventListener('hidden.bs.modal', function() {
        input.value = '';
        btn.disabled = true;
    });
})();
</script>

{{-- Usage Tabs --}}
@if($profileLines->isNotEmpty() || $invoiceLines->isNotEmpty())
<div class="card shadow-sm mt-4">
    <div class="card-header p-0">
        <ul class="nav nav-tabs card-header-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="profiles-tab" data-bs-toggle="tab"
                        data-bs-target="#profiles-pane" type="button" role="tab">
                    <i class="bi bi-arrow-repeat me-1"></i>Recurring Profiles
                    <span class="badge bg-light text-dark ms-1">{{ $profileLines->count() }}</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="invoices-tab" data-bs-toggle="tab"
                        data-bs-target="#invoices-pane" type="button" role="tab">
                    <i class="bi bi-receipt me-1"></i>Invoices
                    <span class="badge bg-light text-dark ms-1">{{ $invoiceLines->count() }}</span>
                </button>
            </li>
        </ul>
    </div>
    <div class="tab-content">
        {{-- Recurring Profiles Pane --}}
        <div class="tab-pane fade show active" id="profiles-pane" role="tabpanel">
            @if($profileLines->isEmpty())
                <div class="card-body text-muted text-center py-3">
                    Not used in any recurring profiles.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Profile</th>
                                <th>Contract</th>
                                <th>Client</th>
                                <th>Qty Type</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Unit Price</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($profileLines as $line)
                                <tr>
                                    <td>
                                        @if($line->profile?->contract)
                                            <a href="{{ route('profiles.show', $line->profile) }}">
                                                {{ $line->profile->name }}
                                            </a>
                                        @else
                                            {{ $line->profile?->name ?? '—' }}
                                        @endif
                                    </td>
                                    <td>
                                        @if($line->profile?->contract)
                                            <a href="{{ route('contracts.show', $line->profile->contract) }}">
                                                {{ $line->profile->contract->name }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $line->profile?->contract?->client?->name ?? '—' }}</td>
                                    <td class="small">{{ $line->quantity_type->label() }}</td>
                                    <td class="text-end">{{ $line->fixed_quantity }}</td>
                                    <td class="text-end">${{ number_format($line->unit_price, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Invoices Pane --}}
        <div class="tab-pane fade" id="invoices-pane" role="tabpanel">
            @if($invoiceLines->isEmpty())
                <div class="card-body text-muted text-center py-3">
                    Not used in any invoices yet.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Invoice #</th>
                                <th>Client</th>
                                <th>Date</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($invoiceLines as $line)
                                <tr>
                                    <td>
                                        @if($line->invoice)
                                            <a href="{{ route('invoices.show', $line->invoice) }}">
                                                {{ $line->invoice->invoice_number ?: '#' . $line->invoice->id }}
                                            </a>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td>{{ $line->invoice?->client?->name ?? '—' }}</td>
                                    <td class="small">{{ $line->invoice?->invoice_date?->format('M j, Y') ?? '—' }}</td>
                                    <td class="text-end">{{ $line->quantity }}</td>
                                    <td class="text-end">${{ number_format($line->amount, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        @if($invoiceLines->count() >= 50)
                            <tfoot>
                                <tr>
                                    <td colspan="5" class="text-center text-muted small py-2">
                                        Showing 50 most recent. <a href="{{ route('invoices.index', ['search' => $sku->name]) }}">View all invoices</a>
                                    </td>
                                </tr>
                            </tfoot>
                        @endif
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
@endif
@endsection
