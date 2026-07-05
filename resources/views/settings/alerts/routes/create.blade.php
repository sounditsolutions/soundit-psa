@extends('layouts.app')

@section('title', 'Alerts Hub · New route')

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

<div class="mb-4">
    <h4 class="section-title mb-1">New route</h4>
</div>

<div class="row g-4">
    <div class="col-xl-7">
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-sliders me-2"></i>Composer
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.alerts.routes.store') }}">
                    @csrf
                    @include('settings.alerts.routes._form', ['route' => null, 'eventTypeGroups' => $eventTypeGroups, 'routeDestinations' => $routeDestinations])
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i>Create route
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

@endsection
