@extends('layouts.app')

@section('title', $profile->name . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('contracts.show', $profile->contract) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to {{ $profile->contract->name }}
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col d-flex align-items-center justify-content-between">
        <div>
            <h4 class="section-title mb-1">{{ $profile->name }}</h4>
            @if($profile->is_active)
                <span class="badge bg-success">Active</span>
            @else
                <span class="badge bg-secondary">Inactive</span>
            @endif
            <span class="text-muted ms-2 small">
                {{ $profile->contract->client->name }} &mdash; {{ $profile->contract->name }}
            </span>
        </div>
        <div class="d-flex gap-2">
            @if($profile->is_active && $profile->next_run_date && $profile->next_run_date->isPast())
                <form method="POST" action="{{ route('profiles.generate', $profile) }}"
                      onsubmit="return confirm('Generate an invoice dated {{ $profile->next_run_date->format('M j, Y') }}?')">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-lightning me-1"></i>Generate Now
                    </button>
                </form>
            @endif
            <button type="button" class="btn btn-outline-info btn-sm" id="previewBtn" onclick="previewInvoice()">
                <i class="bi bi-eye me-1"></i>Preview Next Invoice
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
@if(session('warning'))
    <div class="alert alert-warning alert-dismissible fade show">
        {{ session('warning') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger alert-dismissible fade show">
        <strong>Please fix the following errors:</strong>
        <ul class="mb-0 mt-1">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<form method="POST" action="{{ route('profiles.update', $profile) }}" id="profileForm">
    @csrf
    @method('PATCH')

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card shadow-sm">
                <div class="card-header"><i class="bi bi-gear me-2"></i>Profile Settings</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="name" class="form-label">Name</label>
                        <input type="text" class="form-control" id="name" name="name"
                               value="{{ old('name', $profile->name) }}" required>
                    </div>
                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2"
                                  placeholder="Special billing instructions, service details..."
                                 >{{ old('notes', $profile->notes) }}</textarea>
                    </div>
                    <div class="mb-3">
                        <label for="next_run_date" class="form-label">Next Run Date</label>
                        <input type="date" class="form-control" id="next_run_date" name="next_run_date"
                               value="{{ old('next_run_date', $profile->next_run_date->format('Y-m-d')) }}" required>
                        @if($profile->next_run_date->isPast())
                            @php
                                $months = $profile->billing_period->months();
                                $cyclesBehind = 0;
                                $d = $profile->next_run_date->copy();
                                while ($d->isPast()) { $d->addMonths($months); $cyclesBehind++; }
                            @endphp
                            <div class="form-text text-danger">
                                <i class="bi bi-exclamation-triangle me-1"></i>{{ $cyclesBehind }} billing cycle{{ $cyclesBehind > 1 ? 's' : '' }} behind
                            </div>
                        @endif
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Last Run Date</label>
                        <p class="form-control-plaintext">{{ $profile->last_run_date?->format('M j, Y') ?? 'Never' }}</p>
                    </div>
                    <div class="form-check mb-3">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" class="form-check-input" id="is_active" name="is_active"
                               value="1" {{ old('is_active', $profile->is_active) ? 'checked' : '' }}>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                    @php $skipVal = old('skip_zero_invoices', $profile->skip_zero_invoices); @endphp
                    <div class="mb-3">
                        <label for="skip_zero_invoices" class="form-label">Skip Empty Invoices</label>
                        <select class="form-select" id="skip_zero_invoices" name="skip_zero_invoices">
                            <option value="" {{ $skipVal === null || $skipVal === '' ? 'selected' : '' }}>Use global default</option>
                            <option value="1" {{ $skipVal !== null && $skipVal !== '' && (int)$skipVal === 1 ? 'selected' : '' }}>Always skip</option>
                            <option value="0" {{ $skipVal !== null && $skipVal !== '' && (int)$skipVal === 0 ? 'selected' : '' }}>Never skip</option>
                        </select>
                        <div class="form-text" id="skip-zero-help">
                            @php $globalSkip = \App\Models\Setting::getValue('billing_skip_zero_invoices'); @endphp
                            <span data-skip-msg="default">Global default: empty invoices will {{ $globalSkip ? 'be skipped' : 'still generate' }}.</span>
                            <span data-skip-msg="1" style="display:none">Empty invoices will <strong>always be skipped</strong> for this profile.</span>
                            <span data-skip-msg="0" style="display:none">Empty invoices will <strong>always generate</strong> for this profile.</span>
                        </div>
                        <script>
                            document.getElementById('skip_zero_invoices').addEventListener('change', function() {
                                document.querySelectorAll('#skip-zero-help [data-skip-msg]').forEach(el => el.style.display = 'none');
                                const key = this.value === '' ? 'default' : this.value;
                                document.querySelector('#skip-zero-help [data-skip-msg="' + key + '"]').style.display = '';
                            });
                            document.getElementById('skip_zero_invoices').dispatchEvent(new Event('change'));
                        </script>
                    </div>
                    @php $pushVal = old('auto_push_mode', $profile->auto_push_mode?->value); @endphp
                    <div class="mb-3">
                        <label for="auto_push_mode" class="form-label">Auto-push to billing</label>
                        <select class="form-select" id="auto_push_mode" name="auto_push_mode">
                            <option value="" {{ $pushVal === null || $pushVal === '' ? 'selected' : '' }}>Disabled</option>
                            <option value="push" {{ $pushVal === 'push' ? 'selected' : '' }}>Push on generation</option>
                            <option value="push_and_send" {{ $pushVal === 'push_and_send' ? 'selected' : '' }}>Push and send on generation</option>
                        </select>
                        <div class="form-text">
                            Automatically push generated invoices to QBO or Stripe. "Push and send" also emails the invoice to the customer via Stripe. Client must be mapped to a billing backend.
                        </div>
                    </div>
                    <hr>
                    <div class="mb-3">
                        <label for="billing_period" class="form-label">Billing Period</label>
                        <select class="form-select" id="billing_period" name="billing_period" required
                               >
                            @foreach($billingPeriods as $bp)
                                <option value="{{ $bp->value }}"
                                    {{ old('billing_period', $profile->billing_period->value) === $bp->value ? 'selected' : '' }}>
                                    {{ $bp->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="billing_day" class="form-label">Billing Day</label>
                        <input type="number" class="form-control" id="billing_day" name="billing_day"
                               value="{{ old('billing_day', $profile->billing_day) }}"
                               min="1" max="28" required>
                        <div class="form-text">Day of month when invoices generate.</div>
                    </div>
                    <div class="mb-3">
                        <label for="payment_terms_days" class="form-label">Payment Terms (days)</label>
                        <input type="number" class="form-control" id="payment_terms_days" name="payment_terms_days"
                               value="{{ old('payment_terms_days', $profile->payment_terms_days) }}"
                               min="0" max="365" required>
                    </div>
                </div>
            </div>
        </div>

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
                        @foreach($profile->lines as $i => $line)
                            <div class="line-item border rounded p-3 mb-3" data-index="{{ $i }}">
                                <div class="row g-2">
                                    <div class="col-md-3">
                                        <label class="form-label small">SKU</label>
                                        <select class="form-select form-select-sm sku-select"
                                                name="lines[{{ $i }}][sku_id]"
                                                onchange="onSkuSelected(this)">
                                            <option value="">-- Manual --</option>
                                            @foreach($skus as $s)
                                                <option value="{{ $s->id }}"
                                                    data-price="{{ $s->unit_price }}"
                                                    data-taxable="{{ $s->is_taxable ? '1' : '0' }}"
                                                    data-description="{{ $s->name }}"
                                                    data-included-per-unit="{{ $s->included_per_unit }}"
                                                    data-default-quantity-type="{{ $s->default_quantity_type?->value }}"
                                                    data-default-license-type-id="{{ $s->default_license_type_id }}"
                                                    {{ old("lines.{$i}.sku_id", $line->sku_id) == $s->id ? 'selected' : '' }}>
                                                    {{ $s->sku_code }} — {{ $s->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">Description</label>
                                        <input type="text" class="form-control form-control-sm desc-input"
                                               name="lines[{{ $i }}][description]"
                                               value="{{ old("lines.{$i}.description", $line->description) }}" required>
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label small">Price</label>
                                        <input type="number" class="form-control form-control-sm price-input"
                                               name="lines[{{ $i }}][unit_price]" step="0.01" min="0"
                                               value="{{ old("lines.{$i}.unit_price", $line->unit_price) }}" required>
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label small">Cost</label>
                                        <input type="number" class="form-control form-control-sm"
                                               name="lines[{{ $i }}][unit_cost_override]" step="0.01" min="0"
                                               value="{{ old("lines.{$i}.unit_cost_override", $line->unit_cost_override) }}"
                                               placeholder="SKU" title="Cost override (blank = use SKU cost)">
                                    </div>
                                    <div class="col-md-1">
                                        <label class="form-label small">Prepay</label>
                                        <input type="number" class="form-control form-control-sm"
                                               name="lines[{{ $i }}][prepaid_time_override]" step="1" min="0"
                                               value="{{ old("lines.{$i}.prepaid_time_override", $line->prepaid_time_override) }}"
                                               placeholder="SKU" title="Prepaid time override in minutes (blank = use SKU default)">
                                    </div>
                                    <div class="col-md-2">
                                        <label class="form-label small">Quantity Type</label>
                                        <select class="form-select form-select-sm qty-type-select"
                                                name="lines[{{ $i }}][quantity_type]" onchange="toggleFixedQty(this)">
                                            @foreach($quantityTypes as $qt)
                                                <option value="{{ $qt->value }}"
                                                    {{ old("lines.{$i}.quantity_type", $line->quantity_type->value) === $qt->value ? 'selected' : '' }}>
                                                    {{ $qt->label() }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-1 fixed-qty-col" style="{{ $line->quantity_type !== \App\Enums\QuantityType::Fixed ? 'display:none' : '' }}">
                                        <label class="form-label small">Qty</label>
                                        <input type="number" class="form-control form-control-sm"
                                               name="lines[{{ $i }}][fixed_quantity]"
                                               value="{{ old("lines.{$i}.fixed_quantity", $line->fixed_quantity) }}"
                                               step="0.01" min="0">
                                    </div>
                                    <div class="col-md-2 license-type-col" style="{{ !in_array($line->quantity_type, [\App\Enums\QuantityType::PerLicenseType, \App\Enums\QuantityType::PerResellerLicenseType]) ? 'display:none' : '' }}">
                                        <label class="form-label small">License Type</label>
                                        <select class="form-select form-select-sm" name="lines[{{ $i }}][license_type_id]">
                                            <option value="">Select...</option>
                                            @foreach($licenseTypes as $lt)
                                                <option value="{{ $lt->id }}" {{ old("lines.{$i}.license_type_id", $line->license_type_id) == $lt->id ? 'selected' : '' }}>
                                                    {{ $lt->name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div class="col-md-1 d-flex align-items-end gap-2">
                                        <div class="form-check mb-2">
                                            <input type="hidden" name="lines[{{ $i }}][is_taxable]" value="0">
                                            <input type="checkbox" class="form-check-input taxable-check"
                                                   name="lines[{{ $i }}][is_taxable]" value="1"
                                                   {{ old("lines.{$i}.is_taxable", $line->is_taxable) ? 'checked' : '' }}>
                                            <label class="form-check-label small">Tax</label>
                                        </div>
                                        <button type="button" class="btn btn-outline-danger btn-sm mb-2" onclick="removeLine(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="overage-config-panel mt-2 p-2 border rounded bg-light" style="{{ $line->quantity_type !== \App\Enums\QuantityType::Overage ? 'display:none' : '' }}">
                                    <div class="row g-2">
                                        <div class="col-md-3">
                                            <label class="form-label small">Usage License Type</label>
                                            <select class="form-select form-select-sm" name="lines[{{ $i }}][usage_license_type_id]">
                                                <option value="">Select...</option>
                                                @foreach($licenseTypes as $lt)
                                                    <option value="{{ $lt->id }}" {{ old("lines.{$i}.usage_license_type_id", $line->usage_license_type_id) == $lt->id ? 'selected' : '' }}>
                                                        {{ $lt->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="form-text">What to measure</div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small">Base License Type</label>
                                            <select class="form-select form-select-sm" name="lines[{{ $i }}][base_license_type_id]">
                                                <option value="">(none — use 1)</option>
                                                @foreach($licenseTypes as $lt)
                                                    <option value="{{ $lt->id }}" {{ old("lines.{$i}.base_license_type_id", $line->base_license_type_id) == $lt->id ? 'selected' : '' }}>
                                                        {{ $lt->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="form-text">What provides included amount</div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small">Included per Base</label>
                                            <input type="number" class="form-control form-control-sm included-per-base-input"
                                                   name="lines[{{ $i }}][included_per_base_unit]" min="0" step="1"
                                                   value="{{ old("lines.{$i}.included_per_base_unit", $line->included_per_base_unit) }}"
                                                   placeholder="e.g. 1024">
                                            <div class="form-text">Units included per base unit</div>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label small">Overage Divisor</label>
                                            <input type="number" class="form-control form-control-sm"
                                                   name="lines[{{ $i }}][overage_divisor]" min="1" step="1"
                                                   value="{{ old("lines.{$i}.overage_divisor", $line->overage_divisor ?? 1) }}">
                                            <div class="form-text">Convert raw overage to billing units</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button type="submit" class="btn btn-primary">Save Changes</button>
        <a href="{{ route('contracts.show', $profile->contract) }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>

{{-- Invoice History --}}
@if($invoices->isNotEmpty())
<div class="row mt-5">
    <div class="col">
        <h5 class="section-title mb-3"><i class="bi bi-clock-history me-2"></i>Invoice History ({{ $invoices->count() }})</h5>
        <div class="accordion" id="invoiceHistory">
            @foreach($invoices as $inv)
                @php
                    $statusColor = match($inv->status) {
                        \App\Enums\InvoiceStatus::Paid => 'success',
                        \App\Enums\InvoiceStatus::Void => 'secondary',
                        \App\Enums\InvoiceStatus::Draft => 'warning',
                        default => 'primary',
                    };
                @endphp
                <div class="accordion-item">
                    <h2 class="accordion-header">
                        <button class="accordion-button collapsed py-2" type="button"
                                data-bs-toggle="collapse" data-bs-target="#inv-{{ $inv->id }}">
                            <div class="d-flex w-100 align-items-center gap-3 me-3">
                                <span class="fw-semibold">{{ $inv->invoice_number ?: '#' . $inv->id }}</span>
                                <span class="text-muted small">{{ $inv->invoice_date->format('M j, Y') }}</span>
                                <span class="badge bg-{{ $statusColor }}">{{ $inv->status->label() }}</span>
                                <span class="ms-auto fw-semibold">${{ number_format($inv->total, 2) }}</span>
                            </div>
                        </button>
                    </h2>
                    <div id="inv-{{ $inv->id }}" class="accordion-collapse collapse" data-bs-parent="#invoiceHistory">
                        <div class="accordion-body p-0">
                            <table class="table table-sm mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Description</th>
                                        <th class="text-end" style="width:70px;">Qty</th>
                                        <th class="text-end" style="width:100px;">Unit Price</th>
                                        <th class="text-end" style="width:100px;">Amount</th>
                                        <th class="d-none d-md-table-cell small" style="width:180px;">Source</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($inv->lines as $line)
                                        <tr>
                                            <td>{{ $line->description }}</td>
                                            <td class="text-end">{{ rtrim(rtrim(number_format($line->quantity, 2), '0'), '.') }}</td>
                                            <td class="text-end">${{ number_format($line->unit_price, 2) }}</td>
                                            <td class="text-end">${{ number_format($line->amount, 2) }}</td>
                                            <td class="d-none d-md-table-cell small text-muted">{{ $line->quantity_source }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <td colspan="3" class="text-end fw-semibold">Subtotal</td>
                                        <td class="text-end fw-semibold">${{ number_format($inv->subtotal, 2) }}</td>
                                        <td class="d-none d-md-table-cell"></td>
                                    </tr>
                                    @if($inv->tax > 0)
                                    <tr class="table-light">
                                        <td colspan="3" class="text-end">Tax</td>
                                        <td class="text-end">${{ number_format($inv->tax, 2) }}</td>
                                        <td class="d-none d-md-table-cell"></td>
                                    </tr>
                                    <tr class="table-light">
                                        <td colspan="3" class="text-end fw-bold">Total</td>
                                        <td class="text-end fw-bold">${{ number_format($inv->total, 2) }}</td>
                                        <td class="d-none d-md-table-cell"></td>
                                    </tr>
                                    @endif
                                </tfoot>
                            </table>
                            <div class="px-3 py-2 d-flex justify-content-between align-items-center border-top">
                                <span class="small text-muted">
                                    Due {{ $inv->due_date->format('M j, Y') }}
                                    @if($inv->notes) &mdash; {{ $inv->notes }} @endif
                                </span>
                                <a href="{{ route('invoices.show', $inv) }}" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-box-arrow-up-right me-1"></i>View
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endif

{{-- Preview Modal --}}
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Invoice Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="previewBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">Computing quantities...</p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let lineIndex = {{ $profile->lines->count() }};

function addLine() {
    const container = document.getElementById('linesContainer');
    const quantityOptions = `@foreach($quantityTypes as $qt)<option value="{{ $qt->value }}">{{ $qt->label() }}</option>@endforeach`;
    const skuOptions = `<option value="">-- Manual --</option>@foreach($skus as $s)<option value="{{ $s->id }}" data-price="{{ $s->unit_price }}" data-taxable="{{ $s->is_taxable ? '1' : '0' }}" data-description="{{ $s->name }}" data-included-per-unit="{{ $s->included_per_unit }}" data-default-quantity-type="{{ $s->default_quantity_type?->value }}" data-default-license-type-id="{{ $s->default_license_type_id }}">{{ $s->sku_code }} — {{ $s->name }}</option>@endforeach`;
    const licenseTypeOptions = `<option value="">Select...</option>@foreach($licenseTypes as $lt)<option value="{{ $lt->id }}">{{ $lt->name }} ({{ $lt->vendor }})</option>@endforeach`;
    const licenseTypeOptionsWithNone = `<option value="">(none — use 1)</option>@foreach($licenseTypes as $lt)<option value="{{ $lt->id }}">{{ $lt->name }} ({{ $lt->vendor }})</option>@endforeach`;

    const html = `
        <div class="line-item border rounded p-3 mb-3" data-index="${lineIndex}">
            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small">SKU</label>
                    <select class="form-select form-select-sm sku-select"
                            name="lines[${lineIndex}][sku_id]"
                            onchange="onSkuSelected(this)">
                        ${skuOptions}
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Description</label>
                    <input type="text" class="form-control form-control-sm desc-input"
                           name="lines[${lineIndex}][description]" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Unit Price</label>
                    <input type="number" class="form-control form-control-sm price-input"
                           name="lines[${lineIndex}][unit_price]" step="0.01" min="0" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label small">Quantity Type</label>
                    <select class="form-select form-select-sm qty-type-select"
                            name="lines[${lineIndex}][quantity_type]" onchange="toggleFixedQty(this)">
                        ${quantityOptions}
                    </select>
                </div>
                <div class="col-md-1 fixed-qty-col">
                    <label class="form-label small">Qty</label>
                    <input type="number" class="form-control form-control-sm"
                           name="lines[${lineIndex}][fixed_quantity]" value="1" step="0.01" min="0">
                </div>
                <div class="col-md-1 d-flex align-items-end gap-2">
                    <div class="form-check mb-2">
                        <input type="hidden" name="lines[${lineIndex}][is_taxable]" value="0">
                        <input type="checkbox" class="form-check-input taxable-check" name="lines[${lineIndex}][is_taxable]"
                               value="1" checked>
                        <label class="form-check-label small">Tax</label>
                    </div>
                    <button type="button" class="btn btn-outline-danger btn-sm mb-2" onclick="removeLine(this)">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            <div class="overage-config-panel mt-2 p-2 border rounded bg-light" style="display:none">
                <div class="row g-2">
                    <div class="col-md-3">
                        <label class="form-label small">Usage License Type</label>
                        <select class="form-select form-select-sm" name="lines[${lineIndex}][usage_license_type_id]">
                            ${licenseTypeOptions}
                        </select>
                        <div class="form-text">What to measure</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Base License Type</label>
                        <select class="form-select form-select-sm" name="lines[${lineIndex}][base_license_type_id]">
                            ${licenseTypeOptionsWithNone}
                        </select>
                        <div class="form-text">What provides included amount</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Included per Base</label>
                        <input type="number" class="form-control form-control-sm included-per-base-input"
                               name="lines[${lineIndex}][included_per_base_unit]" min="0" step="1" placeholder="e.g. 1024">
                        <div class="form-text">Units included per base unit</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Overage Divisor</label>
                        <input type="number" class="form-control form-control-sm"
                               name="lines[${lineIndex}][overage_divisor]" min="1" step="1" value="1">
                        <div class="form-text">Convert raw overage to billing units</div>
                    </div>
                </div>
            </div>
        </div>`;

    container.insertAdjacentHTML('beforeend', html);
    lineIndex++;
}

function onSkuSelected(select) {
    const opt = select.options[select.selectedIndex];
    if (!opt.value) return; // "Manual" selected — leave fields as-is

    const lineItem = select.closest('.line-item');
    const row = select.closest('.row');
    const desc = row.querySelector('.desc-input');
    const price = row.querySelector('.price-input');
    const taxable = row.querySelector('.taxable-check');

    if (desc && opt.dataset.description) desc.value = opt.dataset.description;
    if (price && opt.dataset.price) price.value = parseFloat(opt.dataset.price).toFixed(2);
    if (taxable) taxable.checked = opt.dataset.taxable === '1';

    // Auto-fill included_per_base_unit from SKU's included_per_unit
    const includedInput = lineItem.querySelector('.included-per-base-input');
    if (includedInput && opt.dataset.includedPerUnit) {
        includedInput.value = opt.dataset.includedPerUnit;
    }

    // Auto-fill quantity type from SKU default
    const qtyTypeSelect = lineItem.querySelector('.qty-type-select');
    if (qtyTypeSelect && opt.dataset.defaultQuantityType) {
        qtyTypeSelect.value = opt.dataset.defaultQuantityType;
        toggleFixedQty(qtyTypeSelect);
    }

    // Auto-fill license type from SKU default
    if (opt.dataset.defaultLicenseTypeId) {
        const qtyType = opt.dataset.defaultQuantityType;
        if (qtyType === 'per_license_type' || qtyType === 'per_reseller_license_type') {
            const ltSelect = lineItem.querySelector('[name$="[license_type_id]"]');
            if (ltSelect) ltSelect.value = opt.dataset.defaultLicenseTypeId;
        } else if (qtyType === 'overage') {
            const usageLtSelect = lineItem.querySelector('[name$="[usage_license_type_id]"]');
            if (usageLtSelect) usageLtSelect.value = opt.dataset.defaultLicenseTypeId;
        }
    }
}

function removeLine(btn) {
    const lines = document.querySelectorAll('.line-item');
    if (lines.length <= 1) return;
    btn.closest('.line-item').remove();
}

function toggleFixedQty(select) {
    const lineItem = select.closest('.line-item');
    const row = select.closest('.row');
    const fixedCol = row.querySelector('.fixed-qty-col');
    const licenseCol = row.querySelector('.license-type-col');
    const overagePanel = lineItem.querySelector('.overage-config-panel');

    if (fixedCol) fixedCol.style.display = select.value === 'fixed' ? '' : 'none';
    if (licenseCol) licenseCol.style.display = (select.value === 'per_license_type' || select.value === 'per_reseller_license_type') ? '' : 'none';
    if (overagePanel) overagePanel.style.display = select.value === 'overage' ? '' : 'none';
}

function previewInvoice() {
    const modal = new bootstrap.Modal(document.getElementById('previewModal'));
    const body = document.getElementById('previewBody');

    body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2">Computing quantities...</p></div>';
    modal.show();

    fetch('{{ route('profiles.preview', $profile) }}')
        .then(r => r.json())
        .then(data => {
            const formatHours = mins => {
                if (!mins) return '';
                const h = Math.floor(mins / 60);
                const m = mins % 60;
                if (h && m) return `${h}h ${m}m`;
                if (h) return `${h}h`;
                return `${m}m`;
            };

            let rows = data.lines.map(l =>
                `<tr>
                    <td>${l.description}</td>
                    <td class="text-end">${l.quantity}</td>
                    <td class="small text-muted">${l.quantity_type}</td>
                    <td class="text-end">$${Number(l.unit_price).toFixed(2)}</td>
                    <td class="text-end fw-semibold">$${Number(l.amount).toFixed(2)}</td>
                    <td class="text-end small text-muted">${l.prepaid_time_minutes ? formatHours(l.prepaid_time_minutes) : '—'}</td>
                </tr>`
            ).join('');

            const prepaidTotalCell = data.total_prepaid_minutes
                ? `${formatHours(data.total_prepaid_minutes)}`
                : '—';

            body.innerHTML = `
                <div class="mb-3">
                    <strong>Client:</strong> ${data.client}<br>
                    <strong>Invoice Date:</strong> ${data.invoice_date}<br>
                    <strong>Due Date:</strong> ${data.due_date}
                </div>
                <table class="table table-sm">
                    <thead><tr>
                        <th>Description</th>
                        <th class="text-end">Qty</th>
                        <th>Source</th>
                        <th class="text-end">Unit Price</th>
                        <th class="text-end">Amount</th>
                        <th class="text-end">Prepaid Time</th>
                    </tr></thead>
                    <tbody>${rows}</tbody>
                    <tfoot>
                        <tr class="fw-bold">
                            <td colspan="4" class="text-end">Subtotal</td>
                            <td class="text-end">$${Number(data.subtotal).toFixed(2)}</td>
                            <td class="text-end">${prepaidTotalCell}</td>
                        </tr>
                    </tfoot>
                </table>
                ${data.total_prepaid_minutes ? `<p class="small text-muted mb-1"><i class="bi bi-clock-history me-1"></i>${formatHours(data.total_prepaid_minutes)} of prepaid time will be deposited into the contract balance when this invoice is marked Paid.</p>` : ''}
                <p class="text-muted small mb-0">Tax will be calculated by QuickBooks after syncing.</p>`;
        })
        .catch(err => {
            body.innerHTML = `<div class="alert alert-danger">Failed to load preview: ${err.message}</div>`;
        });
}

// Initialize visibility on page load
document.querySelectorAll('.qty-type-select').forEach(toggleFixedQty);
</script>
@endpush
