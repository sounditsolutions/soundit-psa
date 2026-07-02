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

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="destinations-tab" data-bs-toggle="tab" data-bs-target="#destinations" type="button" role="tab" aria-controls="destinations" aria-selected="true">
            <i class="bi bi-send me-1"></i>Destinations
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link disabled" type="button" aria-disabled="true">
            <i class="bi bi-diagram-3 me-1"></i>Routes
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link disabled" type="button" aria-disabled="true">
            <i class="bi bi-activity me-1"></i>Activity
        </button>
    </li>
</ul>

<div class="tab-content">
    <div class="tab-pane fade show active" id="destinations" role="tabpanel" aria-labelledby="destinations-tab">
        @include('settings.alerts.destinations')
    </div>
</div>
@endsection
