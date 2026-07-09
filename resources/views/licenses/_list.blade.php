{{-- Reusable license list partial.
     Expects: $licenses, $clients, $licenseTypes, $vendors, $clientId, $licenseTypeId, $vendor, $wasteOnly
     Optional: $listRoute (string, default 'licenses.index'), $prefilter (array, default [])
               $columns (array, default null = all columns), $showFilters (bool, default true)
     Column keys: client, license_type, vendor, qty, utilization, synced, status, actions
--}}
@php
    $listRoute = $listRoute ?? 'licenses.index';
    $prefilter = $prefilter ?? [];
    $showFilters = $showFilters ?? true;
    $columns = $columns ?? null; // null = show all columns
@endphp

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
@if(session('warning'))
    <div class="alert alert-warning alert-dismissible fade show">
        {{ session('warning') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if($showFilters)
<form method="GET" action="{{ route($listRoute, $prefilter ?? []) }}" class="mb-3">
    <div class="row g-2">
        @unless(isset($prefilter['client_id']))
        <div class="col-auto" style="min-width: 200px;">
            <select name="client_id" class="form-select" onchange="this.form.submit()">
                <option value="">All clients</option>
                @foreach($clients as $c)
                    <option value="{{ $c->id }}" {{ $clientId == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        @endunless
        @unless(isset($prefilter['license_type_id']))
        <div class="col-auto" style="min-width: 200px;">
            <select name="license_type_id" class="form-select" onchange="this.form.submit()">
                <option value="">All types</option>
                @foreach($licenseTypes as $lt)
                    <option value="{{ $lt->id }}" {{ $licenseTypeId == $lt->id ? 'selected' : '' }}>{{ $lt->name }}</option>
                @endforeach
            </select>
        </div>
        @endunless
        <div class="col-auto" style="min-width: 150px;">
            <select name="vendor" class="form-select" onchange="this.form.submit()">
                <option value="">All vendors</option>
                @foreach($vendors as $v)
                    <option value="{{ $v }}" {{ $vendor === $v ? 'selected' : '' }}>{{ $v }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-auto">
            <div class="form-check form-switch mt-2">
                <input class="form-check-input" type="checkbox" name="waste_only" value="1"
                       id="waste_only" {{ $wasteOnly ? 'checked' : '' }} onchange="this.form.submit()">
                <label class="form-check-label small" for="waste_only">Waste only</label>
            </div>
        </div>
        @if($clientId || $licenseTypeId || $vendor || $wasteOnly)
            <div class="col-auto">
                <a href="{{ route($listRoute, $prefilter ?? []) }}" class="btn btn-outline-secondary" title="Clear"><i class="bi bi-x-lg"></i></a>
            </div>
        @endif
    </div>
</form>
@endif

@if($licenses->isEmpty())
    <div class="alert alert-info">No licenses found. Create one or configure integration sync.</div>
@else
    <div class="card shadow-sm card-static">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        @if((!$columns || in_array('client', $columns)) && !isset($prefilter['client_id']))
                        <th>Client</th>
                        @endif
                        @if((!$columns || in_array('license_type', $columns)) && !isset($prefilter['license_type_id']))
                        <th>License Type</th>
                        @endif
                        @if(!$columns || in_array('vendor', $columns))
                        <th class="d-none d-md-table-cell">Vendor</th>
                        @endif
                        @if(!$columns || in_array('qty', $columns))
                        <th class="text-end">Qty</th>
                        @endif
                        @if(!$columns || in_array('utilization', $columns))
                        <th class="text-center d-none d-md-table-cell">Utilization</th>
                        @endif
                        @if(!$columns || in_array('synced', $columns))
                        <th class="d-none d-md-table-cell">Synced</th>
                        @endif
                        @if(!$columns || in_array('status', $columns))
                        <th class="text-center" style="width: 90px;">Status</th>
                        @endif
                        @if(!$columns || in_array('actions', $columns))
                        <th style="width: 50px;"></th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($licenses as $license)
                        <tr>
                            @if((!$columns || in_array('client', $columns)) && !isset($prefilter['client_id']))
                            <td>
                                <a href="{{ route('clients.show', $license->client) }}" class="text-decoration-none">
                                    {{ $license->client->name }}
                                </a>
                            </td>
                            @endif
                            @if((!$columns || in_array('license_type', $columns)) && !isset($prefilter['license_type_id']))
                            <td>{{ $license->licenseType->name }}</td>
                            @endif
                            @if(!$columns || in_array('vendor', $columns))
                            <td class="d-none d-md-table-cell">
                                <span class="badge bg-light text-dark">{{ $license->licenseType->vendor }}</span>
                            </td>
                            @endif
                            @if(!$columns || in_array('qty', $columns))
                            <td class="text-end fw-semibold">
                                @if($license->seat_manageable || $license->is_manual)
                                    <span id="qty-display-{{ $license->id }}">
                                        {{ $license->quantity }}
                                        @if($license->scheduled_quantity !== null && $license->scheduled_quantity !== $license->quantity)
                                            <span class="text-warning" title="Scheduled reduction — will be applied at next billing cycle">
                                                <i class="bi bi-arrow-right" style="font-size: 0.7rem;"></i> {{ $license->scheduled_quantity }}
                                                <i class="bi bi-clock" style="font-size: 0.7rem;"></i>
                                            </span>
                                        @endif
                                        <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-muted"
                                                onclick="showSeatEditor({{ $license->id }}, {{ $license->quantity }}, '{{ addslashes($license->licenseType->name) }}', '{{ addslashes($license->client->name) }}', {{ $license->scheduled_quantity !== null ? $license->scheduled_quantity : 'null' }})"
                                                title="Edit quantity">
                                            <i class="bi bi-pencil" style="font-size: 0.75rem;"></i>
                                        </button>
                                    </span>
                                    @if($license->seat_manageable)
                                    <form method="POST" action="{{ route('licenses.update-quantity', $license) }}"
                                          id="qty-form-{{ $license->id }}" style="display:none;"
                                          onsubmit="return confirmSeatChange(this, {{ $license->id }}, {{ $license->quantity }}, '{{ addslashes($license->licenseType->name) }}', '{{ addslashes($license->client->name) }}', {{ $license->scheduled_quantity !== null ? $license->scheduled_quantity : 'null' }})">
                                        @csrf
                                        @method('PATCH')
                                        <div class="input-group input-group-sm" style="width: 140px; display: inline-flex;">
                                            <input type="number" name="quantity" class="form-control form-control-sm text-end"
                                                   value="{{ $license->quantity }}" min="0" max="10000" required>
                                            <button type="submit" class="btn btn-success btn-sm" title="Save">
                                                <i class="bi bi-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    onclick="hideSeatEditor({{ $license->id }})" title="Cancel">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                    </form>
                                    @else
                                    <form method="POST" action="{{ route('licenses.update', $license) }}"
                                          id="qty-form-{{ $license->id }}" style="display:none;">
                                        @csrf
                                        @method('PATCH')
                                        <div class="input-group input-group-sm" style="width: 140px; display: inline-flex;">
                                            <input type="number" name="quantity" class="form-control form-control-sm text-end"
                                                   value="{{ $license->quantity }}" min="0" max="10000" required>
                                            <button type="submit" class="btn btn-success btn-sm" title="Save">
                                                <i class="bi bi-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                    onclick="hideSeatEditor({{ $license->id }})" title="Cancel">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </div>
                                    </form>
                                    @endif
                                @else
                                    {{ $license->quantity }}
                                @endif
                            </td>
                            @endif
                            @if(!$columns || in_array('utilization', $columns))
                            <td class="text-center d-none d-md-table-cell">
                                @if($license->utilization_percent !== null)
                                    @php
                                        $status = $license->utilization_status;
                                        $colorClass = match($status) {
                                            'good' => 'text-success',
                                            'warning' => 'text-warning',
                                            'waste' => 'text-danger',
                                            default => 'text-muted',
                                        };
                                    @endphp
                                    <span class="{{ $colorClass }} fw-semibold" title="{{ $license->utilization_percent }}% utilized — {{ $license->unassigned_quantity }} unassigned">
                                        {{ $license->assigned_quantity }} / {{ $license->quantity }}
                                    </span>
                                @else
                                    <span class="text-muted">—</span>
                                @endif
                            </td>
                            @endif
                            @if(!$columns || in_array('synced', $columns))
                            <td class="d-none d-md-table-cell small text-muted">
                                {{ $license->synced_at?->diffForHumans() ?? 'Manual' }}
                            </td>
                            @endif
                            @if(!$columns || in_array('status', $columns))
                            <td class="text-center">
                                @if($license->status === 'active')
                                    <span class="badge bg-success">Active</span>
                                @elseif($license->status === 'suspended')
                                    <span class="badge bg-warning">Suspended</span>
                                @else
                                    <span class="badge bg-secondary">Cancelled</span>
                                @endif
                            </td>
                            @endif
                            @if(!$columns || in_array('actions', $columns))
                            <td class="text-center">
                                @unless($license->synced_at)
                                    <form method="POST" action="{{ route('licenses.destroy', $license) }}"
                                          onsubmit="return confirm('Delete this license?')">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Delete">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                @endunless
                            </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $licenses->links() }}</div>
@endif

@push('scripts')
<script>
    function showSeatEditor(id, currentQty, product, client, scheduledQty) {
        document.getElementById('qty-display-' + id).style.display = 'none';
        document.getElementById('qty-form-' + id).style.display = '';
        document.querySelector('#qty-form-' + id + ' input[name=quantity]').focus();
    }

    function hideSeatEditor(id) {
        document.getElementById('qty-display-' + id).style.display = '';
        document.getElementById('qty-form-' + id).style.display = 'none';
    }

    function confirmSeatChange(form, id, oldQty, product, client, scheduledQty) {
        const newQty = parseInt(form.querySelector('input[name=quantity]').value);
        if (newQty === oldQty && (scheduledQty === null || scheduledQty === oldQty)) {
            hideSeatEditor(id);
            return false;
        }
        var msg;
        if (newQty === oldQty && scheduledQty !== null && scheduledQty !== oldQty) {
            msg = 'Cancel the scheduled reduction (' + oldQty + ' → ' + scheduledQty + ') for ' + product + ' on ' + client + '?\n\nThis will be pushed to AppRiver.';
        } else {
            msg = 'Change ' + product + ' seat count from ' + oldQty + ' to ' + newQty + ' for ' + client + '?\n\nThis will be pushed to AppRiver.';
        }
        if (!confirm(msg)) {
            return false;
        }
        // Disable submit button to prevent double-click
        form.querySelector('button[type=submit]').disabled = true;
        form.querySelector('button[type=submit]').innerHTML = '<i class="bi bi-hourglass-split"></i>';
        return true;
    }
</script>
@endpush
