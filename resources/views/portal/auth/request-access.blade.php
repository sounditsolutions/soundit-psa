@extends('portal.layouts.guest')

@section('title', 'Request Access - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<div class="card shadow-sm">
    <div class="card-body p-4">
        <h5 class="card-title text-center mb-3">Request Portal Access</h5>
        <p class="text-muted small text-center mb-4">Enter your email address and we'll send you a verification link to activate your portal access.</p>

        <form method="POST" action="{{ route('portal.request-access') }}">
            @csrf

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email') }}" required autofocus>
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn btn-primary w-100">Send Verification Link</button>
        </form>

        <div class="text-center mt-3">
            <a href="{{ route('portal.login') }}" class="text-muted small">Back to login</a>
        </div>
    </div>
</div>
@endsection
