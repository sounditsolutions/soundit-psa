@extends('portal.layouts.app')

@section('title', 'Purchase Prepaid Time - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<h4 class="mb-4">Purchase Prepaid Time</h4>

@if($contracts->isEmpty())
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-clock text-muted" style="font-size: 3rem;"></i>
            <p class="text-muted mt-3 mb-2">Prepaid time purchasing isn't available for your account yet.</p>
            <p class="text-muted small">Contact us at {{ App\Support\PortalConfig::supportEmail() ?? App\Support\PortalConfig::companyName() }} to get started.</p>
            @if($fallbackUrl)
                <a href="{{ $fallbackUrl }}" target="_blank" class="btn btn-accent mt-2">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Contact Us
                </a>
            @endif
        </div>
    </div>
@else
    <p class="text-muted mb-3">Select the service agreement you'd like to add prepaid time to:</p>

    <div class="row g-3">
        @foreach($contracts as $contract)
            <div class="col-md-6">
                <a href="{{ route('portal.prepaid.form', $contract) }}" class="text-decoration-none">
                    <div class="card h-100 border-hover">
                        <div class="card-body">
                            <h6 class="mb-2">{{ $contract->name }}</h6>
                            @if($contract->prepay_balance !== null)
                                <div class="text-muted small mb-2">
                                    Current balance: <strong>{{ number_format($contract->prepay_balance, 1) }}h</strong>
                                </div>
                            @endif
                            @if($contract->portalPrepaySku)
                                <div class="text-muted small">
                                    {{ $contract->portalPrepaySku->name }}
                                    &mdash; ${{ number_format($contract->portalPrepaySku->unit_price, 2) }}
                                    / {{ number_format($contract->portalPrepaySku->prepaid_time_minutes / 60, 1) }}h
                                </div>
                            @endif
                        </div>
                        <div class="card-footer bg-transparent text-end">
                            <span class="btn btn-sm btn-accent">Select <i class="bi bi-arrow-right ms-1"></i></span>
                        </div>
                    </div>
                </a>
            </div>
        @endforeach
    </div>
@endif
@endsection
