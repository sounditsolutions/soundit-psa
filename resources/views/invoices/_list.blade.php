{{-- Reusable invoice list partial.
     Expects: $invoices, $clients, $filters,
              $statuses (value => label map, e.g. InvoiceStatus::filterOptions())
     Optional: $listRoute (string, default 'invoices.index'), $prefilter (array, default [])
               $columns (array, default null = all columns), $showFilters (bool, default true),
               $showBulkActions (bool, default true)
     Column keys: checkbox, invoice_number, client, contract, profile, date, due_date,
                  subtotal, tax, total, status, sync
--}}
@php
    $listRoute = $listRoute ?? 'invoices.index';
    $prefilter = $prefilter ?? [];
    $showFilters = $showFilters ?? true;
    $showBulkActions = $showBulkActions ?? true;
    $columns = $columns ?? null; // null = show all columns
@endphp

@if($showFilters)
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
@endif

@if($invoices->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="bi bi-receipt" style="font-size: 3rem;"></i>
        <p class="mt-3">No invoices found.</p>
    </div>
@else
    {{-- Desktop: the full table (md+). Below md it is replaced by the stacked
         cards beneath this block so the invoice state and totals stay visible
         without a horizontal scroll (psa-sasp, mirrors the tickets queue psa-6zs7). --}}
    <div class="card shadow-sm card-static d-none d-md-block">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        @if($showBulkActions && (!$columns || in_array('checkbox', $columns)))
                        <th style="width: 40px;" onclick="event.stopPropagation()">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        @endif
                        @if(!$columns || in_array('invoice_number', $columns))
                        <th>Invoice #</th>
                        @endif
                        @if((!$columns || in_array('client', $columns)) && !isset($prefilter['contract_id']))
                        <th>Client</th>
                        @endif
                        @if(!$columns || in_array('contract', $columns))
                        <th>Contract</th>
                        @endif
                        @if(!$columns || in_array('profile', $columns))
                        <th>Profile</th>
                        @endif
                        @if(!$columns || in_array('date', $columns))
                        <th>Date</th>
                        @endif
                        @if(!$columns || in_array('due_date', $columns))
                        <th>Due Date</th>
                        @endif
                        @if(!$columns || in_array('subtotal', $columns))
                        <th class="text-end">Subtotal</th>
                        @endif
                        @if(!$columns || in_array('tax', $columns))
                        <th class="text-end">Tax</th>
                        @endif
                        @if(!$columns || in_array('total', $columns))
                        <th class="text-end">Total</th>
                        @endif
                        @if(!$columns || in_array('status', $columns))
                        <th>Status</th>
                        @endif
                        @if(!$columns || in_array('sync', $columns))
                        <th>Sync</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($invoices as $invoice)
                        <tr class="cursor-pointer" onclick="window.location='{{ route('invoices.show', $invoice) }}'">
                            @if($showBulkActions && (!$columns || in_array('checkbox', $columns)))
                            <td onclick="event.stopPropagation()">
                                <input type="checkbox" class="form-check-input invoice-checkbox" value="{{ $invoice->id }}">
                            </td>
                            @endif
                            @if(!$columns || in_array('invoice_number', $columns))
                            <td>
                                <a href="{{ route('invoices.show', $invoice) }}" class="text-decoration-none fw-semibold">
                                    {{ $invoice->invoice_number }}
                                </a>
                            </td>
                            @endif
                            @if((!$columns || in_array('client', $columns)) && !isset($prefilter['contract_id']))
                            <td class="small"><x-client-badge :client="$invoice->client" fallback="-" /></td>
                            @endif
                            @if(!$columns || in_array('contract', $columns))
                            <td class="small">
                                @if($invoice->contract)
                                    <a href="{{ route('contracts.show', $invoice->contract) }}" class="text-decoration-none" onclick="event.stopPropagation()">{{ $invoice->contract->name }}</a>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            @endif
                            @if(!$columns || in_array('profile', $columns))
                            <td class="small">
                                @if($invoice->profile)
                                    {{ $invoice->profile->name }}
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                            @endif
                            @if(!$columns || in_array('date', $columns))
                            <td class="small">{{ $invoice->invoice_date->format('M j, Y') }}</td>
                            @endif
                            @if(!$columns || in_array('due_date', $columns))
                            <td class="small">{{ $invoice->due_date->format('M j, Y') }}</td>
                            @endif
                            @if(!$columns || in_array('subtotal', $columns))
                            <td class="small text-end">${{ number_format($invoice->subtotal, 2) }}</td>
                            @endif
                            @if(!$columns || in_array('tax', $columns))
                            <td class="small text-end">${{ number_format($invoice->tax, 2) }}</td>
                            @endif
                            @if(!$columns || in_array('total', $columns))
                            <td class="text-end fw-semibold">${{ number_format($invoice->total, 2) }}</td>
                            @endif
                            @if(!$columns || in_array('status', $columns))
                            <td>
                                <span class="badge {{ $invoice->displayStatusBadgeClass() }}">{{ $invoice->displayStatusLabel() }}</span>
                            </td>
                            @endif
                            @if(!$columns || in_array('sync', $columns))
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
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Mobile: stacked cards (below md). Promotes the invoice number plus the
         status, dates, and total into a card so they are readable at a glance
         without a horizontal scroll (psa-sasp). Bulk selection stays a desktop
         affordance, matching the tickets queue fallback (psa-6zs7). --}}
    <div class="d-md-none invoice-cards">
        @foreach($invoices as $invoice)
            <div class="invoice-card" onclick="window.location='{{ route('invoices.show', $invoice) }}'">
                <div class="d-flex justify-content-between align-items-center gap-2 mb-1">
                    <a href="{{ route('invoices.show', $invoice) }}" class="invoice-card-subject fw-semibold text-decoration-none" onclick="event.stopPropagation()">
                        {{ $invoice->invoice_number }}
                    </a>
                    @if($invoice->isOverdue())
                        <span class="badge bg-danger">Overdue</span>
                    @else
                        <span class="badge {{ $invoice->status->badgeClass() }}">{{ $invoice->status->label() }}</span>
                    @endif
                </div>
                @unless(isset($prefilter['contract_id']))
                    <div class="small mb-1" onclick="event.stopPropagation()">
                        <x-client-badge :client="$invoice->client" fallback="-" />
                    </div>
                @endunless
                @if($invoice->contract || $invoice->profile)
                    <div class="small text-muted mb-2 text-truncate">
                        @if($invoice->contract)
                            <a href="{{ route('contracts.show', $invoice->contract) }}" class="text-decoration-none" onclick="event.stopPropagation()">{{ $invoice->contract->name }}</a>
                        @endif
                        @if($invoice->contract && $invoice->profile) &middot; @endif
                        @if($invoice->profile){{ $invoice->profile->name }}@endif
                    </div>
                @endif
                <div class="d-flex flex-wrap align-items-center gap-2 small">
                    <span class="text-muted"><i class="bi bi-calendar3 me-1"></i>{{ $invoice->invoice_date->format('M j, Y') }}</span>
                    <span class="text-muted"><i class="bi bi-calendar-check me-1"></i>Due {{ $invoice->due_date->format('M j, Y') }}</span>
                    <span class="ms-auto fw-semibold">${{ number_format($invoice->total, 2) }}</span>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-3">
        {{ $invoices->links() }}
    </div>
@endif

@if($showBulkActions)
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
            <button class="btn btn-sm btn-success" onclick="confirmBulkAction('mark_paid')">
                <i class="bi bi-cash-coin me-1"></i>Mark as Paid
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
@endif

@push('styles')
<style>
.cursor-pointer { cursor: pointer; }
</style>
@endpush

@push('scripts')
<script>
@if($showBulkActions)
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
            case 'mark_paid':
                title = 'Mark as Paid';
                body = 'Mark ' + count + ' invoice(s) as Paid? Only posted invoices with no Stripe/QuickBooks link will be updated; the rest are skipped.';
                btnClass = 'btn-success';
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
@endif
</script>
@endpush
