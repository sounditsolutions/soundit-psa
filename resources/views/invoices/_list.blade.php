{{-- Reusable invoice list partial.
     Expects: $invoices, $clients, $filters,
              $statuses (value => label map, e.g. InvoiceStatus::filterOptions())
     Optional: $listRoute (string, default 'invoices.index'), $prefilter (array, default [])
--}}
@php
    $listRoute = $listRoute ?? 'invoices.index';
    $prefilter = $prefilter ?? [];
@endphp

{{-- Filters --}}
<div class="card shadow-sm card-static mb-3">
    <div class="card-body">
        <form method="GET" action="{{ route($listRoute, $prefilter) }}">
            @foreach($prefilter as $key => $val)
                <input type="hidden" name="{{ $key }}" value="{{ $val }}">
            @endforeach
            <div class="row g-2">
                @unless(isset($prefilter['contract_id']))
                <div class="col-md-2">
                    <select name="client_id" class="form-select form-select-sm">
                        <option value="">All Clients</option>
                        @foreach($clients as $c)
                            <option value="{{ $c->id }}" {{ ($filters['client_id'] ?? '') == $c->id ? 'selected' : '' }}>
                                {{ $c->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                @endunless
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        @foreach($statuses as $value => $label)
                            <option value="{{ $value }}" {{ ($filters['status'] ?? '') === $value ? 'selected' : '' }}>
                                {{ $label }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="from_date" class="form-control form-control-sm"
                           value="{{ $filters['from_date'] ?? '' }}" placeholder="From">
                </div>
                <div class="col-md-2">
                    <input type="date" name="to_date" class="form-control form-control-sm"
                           value="{{ $filters['to_date'] ?? '' }}" placeholder="To">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
                    <a href="{{ route($listRoute, $prefilter) }}" class="btn btn-outline-secondary btn-sm" title="Clear">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

@if($invoices->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="bi bi-receipt" style="font-size: 3rem;"></i>
        <p class="mt-3">No invoices found.</p>
    </div>
@else
    <div class="card shadow-sm card-static">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th style="width: 40px;" onclick="event.stopPropagation()">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        <th>Invoice #</th>
                        @unless(isset($prefilter['contract_id']))
                        <th>Client</th>
                        @endunless
                        <th>Contract</th>
                        <th>Profile</th>
                        <th>Date</th>
                        <th>Due Date</th>
                        <th class="text-end">Subtotal</th>
                        <th class="text-end">Tax</th>
                        <th class="text-end">Total</th>
                        <th>Status</th>
                        <th>Sync</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices as $invoice)
                        <tr class="cursor-pointer" onclick="window.location='{{ route('invoices.show', $invoice) }}'">
                            <td onclick="event.stopPropagation()">
                                <input type="checkbox" class="form-check-input invoice-checkbox" value="{{ $invoice->id }}">
                            </td>
                            <td>
                                <a href="{{ route('invoices.show', $invoice) }}" class="text-decoration-none fw-semibold">
                                    {{ $invoice->invoice_number }}
                                </a>
                            </td>
                            @unless(isset($prefilter['contract_id']))
                            <td class="small"><x-client-badge :client="$invoice->client" fallback="-" /></td>
                            @endunless
                            <td class="small">
                                @if($invoice->contract)
                                    <a href="{{ route('contracts.show', $invoice->contract) }}" class="text-decoration-none" onclick="event.stopPropagation()">{{ $invoice->contract->name }}</a>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="small">
                                @if($invoice->profile)
                                    {{ $invoice->profile->name }}
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            <td class="small">{{ $invoice->invoice_date->format('M j, Y') }}</td>
                            <td class="small">{{ $invoice->due_date->format('M j, Y') }}</td>
                            <td class="small text-end">${{ number_format($invoice->subtotal, 2) }}</td>
                            <td class="small text-end">${{ number_format($invoice->tax, 2) }}</td>
                            <td class="text-end fw-semibold">${{ number_format($invoice->total, 2) }}</td>
                            <td>
                                @if($invoice->isOverdue())
                                    <span class="badge bg-danger">Overdue</span>
                                @else
                                    <span class="badge {{ $invoice->status->badgeClass() }}">{{ $invoice->status->label() }}</span>
                                @endif
                            </td>
                            <td>
                                @if($invoice->stripe_sync_error || $invoice->qbo_sync_error)
                                    <i class="bi bi-exclamation-triangle-fill text-danger" title="{{ $invoice->stripe_sync_error ?: $invoice->qbo_sync_error }}"></i>
                                @elseif($invoice->stripe_invoice_id)
                                    <i class="bi bi-stripe text-success" title="Synced to Stripe"></i>
                                @elseif($invoice->qbo_invoice_id)
                                    <i class="bi bi-cloud-check text-success" title="Synced to QBO"></i>
                                @else
                                    <i class="bi bi-dash text-muted" title="Not synced"></i>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $invoices->links() }}
    </div>
@endif

{{-- Bulk Action Bar --}}
<div id="bulkBar" style="display: none; position: fixed; bottom: 0; left: 0; right: 0; z-index: 1050; background: var(--primary, #1a365d); color: #fff; border-top: 3px solid var(--accent, #fed136); padding: 12px 24px;">
    <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <strong><span id="bulkCount">0</span> selected</strong>
            <button class="btn btn-sm btn-light" onclick="confirmBulkAction('push')">
                <i class="bi bi-cloud-upload me-1"></i>Push to Billing
            </button>
            <button class="btn btn-sm btn-info text-white" onclick="confirmBulkAction('post')">
                <i class="bi bi-check-circle me-1"></i>Mark as Posted
            </button>
            <button class="btn btn-sm btn-outline-danger" onclick="confirmBulkAction('void')" style="border-color: rgba(255,255,255,0.5); color: #fff;">
                <i class="bi bi-x-circle me-1"></i>Void
            </button>
        </div>
        <a href="#" class="text-white text-decoration-none small" onclick="deselectAll(); return false;">Deselect All</a>
    </div>
</div>

{{-- Bulk Action Modal --}}
<div class="modal fade" id="bulkActionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="bulkModalTitle">Confirm Action</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="bulkModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="{{ route('invoices.bulk-action') }}" id="bulkActionForm">
                    @csrf
                    <input type="hidden" name="action" id="bulkActionInput">
                    <div id="bulkIdsContainer"></div>
                    <button type="submit" class="btn btn-primary" id="bulkConfirmBtn">Confirm</button>
                </form>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
.cursor-pointer { cursor: pointer; }
</style>
@endpush

@push('scripts')
<script>
(function() {
    var selectedIds = new Set();

    var selectAllEl = document.getElementById('selectAll');
    if (selectAllEl) {
        selectAllEl.addEventListener('change', function() {
            var checked = this.checked;
            document.querySelectorAll('.invoice-checkbox').forEach(function(cb) {
                cb.checked = checked;
                if (checked) {
                    selectedIds.add(cb.value);
                } else {
                    selectedIds.delete(cb.value);
                }
            });
            updateBar();
        });
    }

    document.querySelectorAll('.invoice-checkbox').forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (this.checked) {
                selectedIds.add(this.value);
            } else {
                selectedIds.delete(this.value);
            }
            updateSelectAll();
            updateBar();
        });
    });

    function updateSelectAll() {
        if (!selectAllEl) return;
        var all = document.querySelectorAll('.invoice-checkbox');
        var checked = document.querySelectorAll('.invoice-checkbox:checked');
        selectAllEl.checked = all.length > 0 && checked.length === all.length;
        selectAllEl.indeterminate = checked.length > 0 && checked.length < all.length;
    }

    function updateBar() {
        var bar = document.getElementById('bulkBar');
        var count = document.getElementById('bulkCount');
        if (selectedIds.size > 0) {
            bar.style.display = '';
            count.textContent = selectedIds.size;
        } else {
            bar.style.display = 'none';
        }
    }

    window.deselectAll = function() {
        selectedIds.clear();
        document.querySelectorAll('.invoice-checkbox').forEach(function(cb) { cb.checked = false; });
        if (selectAllEl) { selectAllEl.checked = false; selectAllEl.indeterminate = false; }
        updateBar();
    };

    window.confirmBulkAction = function(action) {
        var count = selectedIds.size;
        var title, body, btnClass;

        switch (action) {
            case 'push':
                title = 'Push to Billing';
                body = 'Push ' + count + ' invoice(s) to QBO/Stripe? Invoices will be routed to the appropriate billing backend based on client mapping. Only Draft/Pending Sync invoices with a mapped client will be pushed.';
                btnClass = 'btn-primary';
                break;
            case 'post':
                title = 'Mark as Posted';
                body = 'Mark ' + count + ' invoice(s) as Posted? Only Draft invoices will be updated.';
                btnClass = 'btn-info text-white';
                break;
            case 'void':
                title = 'Void Invoices';
                body = 'Void ' + count + ' invoice(s)? Already-voided invoices will be skipped.';
                btnClass = 'btn-danger';
                break;
        }

        document.getElementById('bulkModalTitle').textContent = title;
        document.getElementById('bulkModalBody').textContent = body;
        document.getElementById('bulkActionInput').value = action;

        var btn = document.getElementById('bulkConfirmBtn');
        btn.className = 'btn ' + btnClass;
        btn.textContent = title;

        var container = document.getElementById('bulkIdsContainer');
        container.innerHTML = '';
        selectedIds.forEach(function(id) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'invoice_ids[]';
            input.value = id;
            container.appendChild(input);
        });

        new bootstrap.Modal(document.getElementById('bulkActionModal')).show();
    };
})();
</script>
@endpush
