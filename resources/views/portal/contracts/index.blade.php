@extends('portal.layouts.app')

@section('title', 'Service Agreements - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<h4 class="mb-3">Service Agreements</h4>

@php
    $hasPurchasableContracts = \App\Models\Contract::where('client_id', $portalClientId)
        ->active()
        ->whereNotNull('portal_prepay_sku_id')
        ->where(fn ($q) => $q->where('prepay_as_amount', false)->orWhereNull('prepay_as_amount'))
        ->exists();
@endphp
@if($hasPurchasableContracts)
    <div class="mb-3">
        <a href="{{ route('portal.prepaid.select') }}" class="btn btn-sm btn-accent">
            <i class="bi bi-cart-plus me-1"></i>Purchase Prepaid Time
        </a>
    </div>
@elseif(App\Support\PortalConfig::orderUrlForClient($portalClientId))
    <div class="mb-3">
        <a href="{{ App\Support\PortalConfig::orderUrlForClient($portalClientId) }}" target="_blank" class="btn btn-sm btn-accent">
            <i class="bi bi-cart-plus me-1"></i>Purchase Prepaid Time <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.7rem;"></i>
        </a>
    </div>
@endif

<div class="card">
    <div class="card-body p-0">
        @if($contracts->isEmpty())
            <p class="text-muted p-3 mb-0">No service agreements found.</p>
        @else
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Agreement</th>
                            <th>Type</th>
                            <th>Start</th>
                            <th>End</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($contracts as $contract)
                            <tr class="cursor-pointer" onclick="window.location='{{ route('portal.contracts.show', $contract) }}'">
                                <td>{{ $contract->name }}</td>
                                <td class="text-muted">{{ $contract->contract_type ?? '—' }}</td>
                                <td class="text-muted">{{ $contract->start_date?->format('M j, Y') ?? '—' }}</td>
                                <td class="text-muted">{{ $contract->end_date?->format('M j, Y') ?? '—' }}</td>
                                <td>
                                    @if($contract->status === App\Enums\ContractStatus::Active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Inactive</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>

@if($contracts->hasPages())
    <div class="mt-3">{{ $contracts->links() }}</div>
@endif
@endsection
