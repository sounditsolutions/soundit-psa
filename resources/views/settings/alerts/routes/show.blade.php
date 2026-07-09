@extends('layouts.app')

@section('title', 'Alerts Hub · '.$route->label)

@section('content')

<a href="{{ route('settings.alerts.index') }}" class="btn btn-sm btn-link text-decoration-none ps-0 mb-2">
    <i class="bi bi-arrow-left me-1"></i>All routes
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
            <i class="bi bi-diagram-3"></i>
            {{ $route->label }}
        </h4>
        <div class="d-flex align-items-center gap-2">
            @include('settings.alerts._status_badge', ['enabled' => $route->enabled])
            <span class="text-muted small">{{ $route->cooldown_seconds }}s cooldown</span>
        </div>
    </div>

    <div class="d-flex align-items-center gap-2">
        <form method="POST" action="{{ route('settings.alerts.routes.toggle', $route) }}" class="d-inline">
            @csrf
            <button type="submit" class="btn {{ $route->enabled ? 'btn-outline-warning' : 'btn-outline-success' }}">
                <i class="bi {{ $route->enabled ? 'bi-pause' : 'bi-play' }} me-1"></i>{{ $route->enabled ? 'Disable' : 'Enable' }}
            </button>
        </form>
    </div>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-sliders me-2"></i>Composer
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.alerts.routes.update', $route) }}">
                    @csrf
                    @method('PUT')
                    @include('settings.alerts.routes._form', ['route' => $route, 'eventTypeGroups' => $eventTypeGroups, 'routeDestinations' => $routeDestinations, 'derivedRecipients' => $derivedRecipients])
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-clock-history me-2"></i>Recent fires
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead class="thead-brand">
                        <tr>
                            <th>Time</th>
                            <th>Event</th>
                            <th>Destination</th>
                            <th>Step</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($recentFires as $d)
                            <tr>
                                <td class="small text-muted">{{ $d->created_at?->toAppTz()->format('Y-m-d H:i') }}</td>
                                <td class="small">{{ $d->event?->type_key }}</td>
                                <td class="small">{{ $d->destination?->label }}</td>
                                <td class="small">{{ $d->step_order }}</td>
                                <td><span class="badge bg-light text-dark border">{{ $d->status }}</span></td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-muted small">No fires yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

@endsection
