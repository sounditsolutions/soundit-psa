@extends('layouts.app')

@section('title', 'Alerts Hub')

@section('content')
<div class="row mb-3">
    <div class="col">
        <h4 class="section-title mb-0">Alerts Hub</h4>
    </div>
</div>

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

@if($hasStalePendingDelivery)
    <div class="alert alert-danger shadow-sm" role="alert">
        <div class="fw-semibold">
            <i class="bi bi-exclamation-triangle me-1"></i>queue worker may be down
        </div>
        <div class="small">signals are enqueued but not delivering</div>
    </div>
@endif

<div class="card card-static shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-send me-2"></i>Destinations</span>
        <a class="btn btn-primary btn-sm" href="{{ route('settings.alerts.destinations.create') }}">
            <i class="bi bi-plus-lg me-1"></i>New destination
        </a>
    </div>
    {{-- Desktop: full table (md+). Below md it swaps to stacked label/value rows
         so Status and Actions cannot clip off the right edge of a phone viewport
         (psa-0h6e; mirrors the Contracts table treatment in clients/show, psa-6zs7). --}}
    <div class="table-responsive d-none d-md-block">
        <table class="table table-hover mb-0 align-middle">
            <thead class="thead-brand">
                <tr>
                    <th>Destination</th>
                    <th>Target</th>
                    <th>Status</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($destinations as $destination)
                    <tr>
                        <td>
                            <a href="{{ route('settings.alerts.destinations.show', $destination) }}" class="text-decoration-none fw-semibold">
                                <i class="bi {{ $destination->type === 'webhook' ? 'bi-webhook' : ($destination->type === 'email' ? 'bi-envelope' : 'bi-robot') }} me-1"></i>{{ $destination->label }}
                            </a>
                            <div class="text-muted small">{{ strtoupper($destination->type) }}</div>
                        </td>
                        <td>
                            @if($destination->type === 'mcp')
                                <div><code>{{ $destination->mcp_token_label }}</code></div>
                                @if($destination->masked_wake_url)
                                    <div class="text-muted small">Wake: {{ $destination->masked_wake_url }}</div>
                                @endif
                            @else
                                <span class="font-monospace small">{{ $destination->masked_address ?? 'not set' }}</span>
                            @endif
                        </td>
                        <td>
                            @include('settings.alerts._status_badge', ['enabled' => $destination->enabled])
                            @if($destination->last_delivery_status === 'failed')
                                {{-- At-a-glance broken-destination indicator. last_error can carry
                                     remote-server response text, so it is HTML-escaped ({{ }}) in both
                                     the badge label and the tooltip. Full detail on the detail page. --}}
                                <div class="mt-1">
                                    <span class="badge bg-danger-subtle text-danger-emphasis alerts-destination-failure"
                                          title="{{ $destination->last_error }}">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ \Illuminate\Support\Str::limit($destination->last_error, 40) }}
                                    </span>
                                </div>
                            @endif
                            @if($destination->last_delivery_at)
                                <div class="text-muted small mt-1">{{ $destination->last_delivery_at->toAppTz()->format('Y-m-d H:i') }}</div>
                            @endif
                        </td>
                        <td class="text-end">
                            <a href="{{ route('settings.alerts.destinations.show', $destination) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-gear me-1"></i>Open
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted small">No destinations yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{-- Mobile: stacked rows below md so Target/Status/Actions stay reachable (psa-0h6e). --}}
    <div class="d-md-none">
        @forelse($destinations as $destination)
            <div class="data-row">
                <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                    <a href="{{ route('settings.alerts.destinations.show', $destination) }}" class="text-decoration-none fw-semibold">
                        <i class="bi {{ $destination->type === 'webhook' ? 'bi-webhook' : ($destination->type === 'email' ? 'bi-envelope' : 'bi-robot') }} me-1"></i>{{ $destination->label }}
                    </a>
                    <a href="{{ route('settings.alerts.destinations.show', $destination) }}" class="btn btn-sm btn-outline-secondary flex-shrink-0">
                        <i class="bi bi-gear me-1"></i>Open
                    </a>
                </div>
                <div class="text-muted small mb-1">{{ strtoupper($destination->type) }}</div>
                <div class="d-flex justify-content-between gap-3 small py-1">
                    <span class="data-label">Target</span>
                    <span class="text-end text-break">
                        @if($destination->type === 'mcp')
                            <code>{{ $destination->mcp_token_label }}</code>
                            @if($destination->masked_wake_url)
                                <div class="text-muted">Wake: {{ $destination->masked_wake_url }}</div>
                            @endif
                        @else
                            <span class="font-monospace">{{ $destination->masked_address ?? 'not set' }}</span>
                        @endif
                    </span>
                </div>
                <div class="d-flex justify-content-between gap-3 small py-1">
                    <span class="data-label">Status</span>
                    <span class="text-end">
                        @include('settings.alerts._status_badge', ['enabled' => $destination->enabled])
                        @if($destination->last_delivery_status === 'failed')
                            {{-- last_error is HTML-escaped ({{ }}) — it can carry remote-server text. --}}
                            <div class="mt-1">
                                <span class="badge bg-danger-subtle text-danger-emphasis alerts-destination-failure"
                                      title="{{ $destination->last_error }}">
                                    <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ \Illuminate\Support\Str::limit($destination->last_error, 40) }}
                                </span>
                            </div>
                        @endif
                        @if($destination->last_delivery_at)
                            <div class="text-muted mt-1">{{ $destination->last_delivery_at->toAppTz()->format('Y-m-d H:i') }}</div>
                        @endif
                    </span>
                </div>
            </div>
        @empty
            <div class="data-row text-muted small">No destinations yet.</div>
        @endforelse
    </div>
</div>

<div class="card card-static shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-diagram-3 me-2"></i>Routes</span>
        <a class="btn btn-primary btn-sm" href="{{ route('settings.alerts.routes.create') }}">
            <i class="bi bi-plus-lg me-1"></i>New route
        </a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="thead-brand">
                <tr>
                    <th>Route</th>
                    <th>Filter</th>
                    <th>Steps</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($routes as $route)
                    <tr>
                        <td>
                            <a href="{{ route('settings.alerts.routes.show', $route) }}" class="text-decoration-none fw-semibold">
                                {{ $route->label }}
                            </a>
                            <div>
                                @include('settings.alerts._status_badge', ['enabled' => $route->enabled])
                                <span class="text-muted small ms-1">{{ $route->cooldown_seconds }}s cooldown</span>
                            </div>
                        </td>
                        <td class="small">{{ $route->event_filter_summary }}</td>
                        <td class="small">{{ $route->steps_summary }}</td>
                        <td class="text-end">
                            <a href="{{ route('settings.alerts.routes.show', $route) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-gear me-1"></i>Open
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-muted small">No routes yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mb-3">
    <h5 class="section-title mb-0"><i class="bi bi-activity me-2"></i>Activity</h5>
</div>
@include('settings.alerts.activity')
@endsection
