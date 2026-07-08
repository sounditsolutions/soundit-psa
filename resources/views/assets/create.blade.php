@extends('layouts.app')

@section('title', 'New Asset')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('assets.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Assets
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title">New Asset</h4>
        <p class="text-muted small mb-0">For devices monitored by NinjaRMM or Level, data syncs automatically.</p>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="POST" action="{{ route('assets.store') }}">
                    @csrf
                    @include('assets._form')

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Create Asset</button>
                        <a href="{{ route('assets.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
