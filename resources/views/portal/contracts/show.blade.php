@extends('portal.layouts.app')

@section('title', $contract->name . ' - Service Agreements - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<div class="mb-3">
    <a href="{{ route('portal.contracts.index') }}" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Service Agreements</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h5 class="mb-3">{{ $contract->name }}</h5>

        <div class="row">
            <div class="col-md-6">
                <table class="table table-sm table-borderless">
                    <tr>
                        <td class="text-muted" style="width:140px">Type</td>
                        <td>{{ $contract->contract_type ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">Status</td>
                        <td>
                            @if($contract->status === App\Enums\ContractStatus::Active)
                                <span class="badge bg-success">Active</span>
                            @else
                                <span class="badge bg-secondary">Inactive</span>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">Start Date</td>
                        <td>{{ $contract->start_date?->format('M j, Y') ?? '—' }}</td>
                    </tr>
                    <tr>
                        <td class="text-muted">End Date</td>
                        <td>{{ $contract->end_date?->format('M j, Y') ?? '—' }}</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Prepay balance --}}
@if($contract->prepay_balance !== null && !$contract->prepay_as_amount)
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-clock me-1"></i>Support Hours Remaining</h6>
        </div>
        <div class="card-body">
            <div class="portal-stat-card p-3 d-inline-block">
                <div class="stat-value">{{ number_format($contract->prepay_balance, 1) }}h</div>
                <div class="stat-label">Prepaid Balance</div>
            </div>
            @if($contract->is_portal_purchasable)
                <div class="mt-2">
                    <a href="{{ route('portal.prepaid.form', $contract) }}" class="btn btn-sm btn-accent">
                        <i class="bi bi-cart-plus me-1"></i>Purchase Prepaid Time
                    </a>
                </div>
            @elseif(App\Support\PortalConfig::orderUrlForClient($portalClientId))
                <div class="mt-2">
                    <a href="{{ App\Support\PortalConfig::orderUrlForClient($portalClientId) }}" target="_blank" class="btn btn-sm btn-accent">
                        <i class="bi bi-cart-plus me-1"></i>Purchase Prepaid Time <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.7rem;"></i>
                    </a>
                </div>
            @endif
        </div>
    </div>
@endif

{{-- Prepay alert settings (company-wide access only) --}}
@if($contract->prepay_balance !== null && !$contract->prepay_as_amount && ($portalPerson->company_wide_access ?? false))
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-bell me-1"></i>Low Balance Alerts</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('portal.prepaid.update-alert-settings', $contract) }}">
                @csrf
                @method('PUT')
                <div class="row g-3 align-items-end">
                    <div class="col-auto">
                        <label for="prepayAlertThreshold" class="form-label small">Alert when below (hours):</label>
                        <input type="number" name="prepay_alert_threshold" id="prepayAlertThreshold"
                               class="form-control form-control-sm" style="max-width: 120px;"
                               value="{{ $contract->prepay_alert_threshold }}"
                               min="0" step="0.25" placeholder="Off">
                    </div>
                    @if($contract->is_portal_purchasable)
                        <div class="col-auto">
                            <div class="form-check">
                                <input type="hidden" name="prepay_auto_topup_enabled" value="0">
                                <input type="checkbox" name="prepay_auto_topup_enabled" value="1"
                                       class="form-check-input" id="portalAutoTopUp"
                                       {{ $contract->prepay_auto_topup_enabled ? 'checked' : '' }}>
                                <label class="form-check-label small" for="portalAutoTopUp">Auto top-up</label>
                            </div>
                        </div>
                        <div class="col-auto" id="portalTopUpQty" style="{{ $contract->prepay_auto_topup_enabled ? '' : 'display:none;' }}">
                            <label for="prepayAutoTopupQty" class="form-label small">Units:</label>
                            <input type="number" name="prepay_auto_topup_qty" id="prepayAutoTopupQty"
                                   class="form-control form-control-sm" style="max-width: 80px;"
                                   value="{{ $contract->prepay_auto_topup_qty ?? 1 }}"
                                   min="1" max="99">
                        </div>
                    @endif
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    </div>
                </div>
                @if($contract->is_portal_purchasable && $contract->portalPrepaySku)
                    <div class="form-text mt-2">
                        {{ $contract->portalPrepaySku->name }} — ${{ number_format($contract->portalPrepaySku->unit_price, 2) }} / {{ number_format($contract->portalPrepaySku->prepaid_time_minutes / 60, 1) }}h per unit
                    </div>
                @endif
            </form>
        </div>
    </div>
    <script>
        document.getElementById('portalAutoTopUp')?.addEventListener('change', function() {
            document.getElementById('portalTopUpQty').style.display = this.checked ? '' : 'none';
        });
    </script>
@endif

{{-- Prepay activity ledger (company-wide access only) --}}
@if($prepayTransactions && $prepayTransactions->isNotEmpty())
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0"><i class="bi bi-journal-text me-1"></i>Prepaid Time Activity</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th>
                            <th>Description</th>
                            <th class="text-end">Hours</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($prepayTransactions as $txn)
                            <tr>
                                <td class="text-muted small">{{ $txn->date?->toAppTz()->format('M j, Y') ?? '—' }}</td>
                                <td>{{ $txn->description ?? $txn->source->label() }}</td>
                                <td class="text-end fw-semibold {{ (float) $txn->hours >= 0 ? 'text-success' : 'text-danger' }}">
                                    {{ (float) $txn->hours >= 0 ? '+' : '' }}{{ number_format($txn->hours, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @if($prepayTransactions->hasPages())
            <div class="card-footer">
                {{ $prepayTransactions->links() }}
            </div>
        @endif
    </div>
@endif

{{-- Assigned devices --}}
@if($contract->assets->isNotEmpty())
    <div class="card mb-4">
        <div class="card-header">
            <h6 class="mb-0">Assigned Devices</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Hostname</th>
                            <th>Type</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contract->assets as $asset)
                            <tr class="cursor-pointer" onclick="window.location='{{ route('portal.assets.show', $asset) }}'">
                                <td>{{ $asset->hostname ?? $asset->name }}</td>
                                <td class="text-muted">{{ $asset->asset_type ?? '—' }}</td>
                                <td>
                                    @if($asset->rmm_online)
                                        <span class="badge bg-success">Online</span>
                                    @else
                                        <span class="badge bg-secondary">Offline</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif

{{-- Assigned people --}}
@if($contract->people->isNotEmpty())
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0">Assigned People</h6>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contract->people as $person)
                            <tr>
                                <td><x-person-badge :person="$person" :size="24" :link="false" /></td>
                                <td class="text-muted">{{ $person->email ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@endif
@endsection
