@extends('layouts.app')

@section('title', $licenseType->name . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('license-types.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to License Types
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col"><h4 class="section-title">{{ $licenseType->name }}</h4></div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="POST" action="{{ route('license-types.update', $licenseType) }}">
                    @csrf
                    @method('PATCH')
                    @include('license-types._form', ['licenseType' => $licenseType])
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="{{ route('license-types.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
