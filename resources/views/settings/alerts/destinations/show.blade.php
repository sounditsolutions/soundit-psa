@extends('layouts.app')

@section('title', 'Alerts Hub · '.$destination->label)

@section('content')

<a href="{{ route('settings.alerts.index') }}" class="btn btn-sm btn-link text-decoration-none ps-0 mb-2">
    <i class="bi bi-arrow-left me-1"></i>All destinations
</a>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="d-flex align-items-start justify-content-between flex-wrap gap-3 mb-4">
    <div>
        <h4 class="section-title mb-1 d-flex align-items-center gap-2">
            <i class="bi {{ $destination->type === 'webhook' ? 'bi-webhook' : ($destination->type === 'email' ? 'bi-envelope' : 'bi-robot') }}"></i>
            {{ $destination->label }}
        </h4>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-light text-dark border">{{ strtoupper($destination->type) }}</span>
            @include('settings.alerts._status_badge', ['enabled' => $destination->enabled])
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">
        <form method="POST" action="{{ route('settings.alerts.destinations.test', $destination) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn btn-outline-primary">
                <i class="bi bi-send me-1"></i>Test send
            </button>
        </form>
        <form method="POST" action="{{ route('settings.alerts.destinations.toggle', $destination) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn {{ $destination->enabled ? 'btn-outline-warning' : 'btn-outline-success' }}">
                <i class="bi {{ $destination->enabled ? 'bi-pause' : 'bi-play' }} me-1"></i>{{ $destination->enabled ? 'Disable' : 'Enable' }}
            </button>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-6">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-sliders me-2"></i>Config
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.alerts.destinations.update', $destination) }}">
                    @csrf
                    @method('PUT')
                    @include('settings.alerts.destinations._form', ['destination' => $destination, 'mcpTokens' => $mcpTokens, 'secretMask' => $secretMask])
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header">
                <i class="bi bi-heart-pulse me-2"></i>Delivery health
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Last delivery</dt>
                    <dd class="col-sm-8">{{ $destination->last_delivery_at?->toAppTz()->format('Y-m-d H:i') ?? 'Never' }}</dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8">
                        @if($destination->last_delivery_status)
                            <span class="badge bg-light text-dark border">{{ $destination->last_delivery_status }}</span>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </dd>

                    @if($destination->last_error)
                        <dt class="col-sm-4">Last error</dt>
                        <dd class="col-sm-8 text-danger">{{ $destination->last_error }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-clock-history me-2"></i>Recent deliveries
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="thead-brand">
                        <tr>
                            <th>Time</th>
                            <th>Event</th>
                            <th>Status</th>
                            <th>Error</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentDeliveries as $d)
                            <tr>
                                <td class="small text-muted">{{ $d->created_at?->toAppTz()->format('Y-m-d H:i') }}</td>
                                <td class="small">{{ $d->event?->type_key }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $d->status }}</span></td>
                                <td class="small text-danger">{{ $d->error }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-muted small">No deliveries yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
