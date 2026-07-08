@extends('layouts.app')

@section('title', 'QuickBooks Disconnected')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-6 text-center py-5">
        <i class="bi bi-cloud-slash" style="font-size: 3rem; color: var(--text-muted);"></i>
        <h3 class="mt-3">QuickBooks Online Disconnected</h3>
        <p class="text-muted mb-4">
            The connection between {{ config('app.name') }} and QuickBooks Online has been removed.
            Invoice sync is paused until you reconnect.
        </p>
        @auth
            <a href="{{ route('settings.integrations') }}" class="btn btn-primary">
                <i class="bi bi-gear me-1"></i>Go to Integrations
            </a>
        @else
            <a href="{{ route('login') }}" class="btn btn-primary">
                <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
            </a>
        @endauth
    </div>
</div>
@endsection
