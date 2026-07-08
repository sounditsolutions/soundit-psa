@extends('portal.layouts.guest')

@section('title', 'Login - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<div class="card shadow-sm">
    <div class="card-body p-4">
        <h5 class="card-title text-center mb-4">Sign In</h5>

        <form method="POST" action="{{ route('portal.login') }}">
            @csrf

            <div class="mb-3">
                <label for="email" class="form-label">Email Address</label>
                <input type="email" name="email" id="email" class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email') }}" required autofocus>
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3">
                <label for="password" class="form-label">Password</label>
                <input type="password" name="password" id="password" class="form-control @error('password') is-invalid @enderror"
                       required>
                @error('password')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="mb-3 form-check">
                <input type="checkbox" name="remember" id="remember" class="form-check-input"
                       {{ old('remember') ? 'checked' : '' }}>
                <label for="remember" class="form-check-label">Remember me</label>
            </div>

            <button type="submit" class="btn btn-primary w-100">Sign In</button>
        </form>

        <div class="text-center mt-3">
            <a href="{{ route('portal.password.request') }}" class="text-muted small">Forgot your password?</a>
        </div>

        <div class="text-center mt-2">
            <a href="{{ route('portal.request-access') }}" class="text-muted small">Request portal access</a>
        </div>
    </div>
</div>
@endsection
