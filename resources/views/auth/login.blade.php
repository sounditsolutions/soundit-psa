@extends('layouts.app')

@section('title', 'Login')
@section('body-class', 'login-page')

@section('content')
<div class="login-card p-4 p-md-5 text-center">
    <img src="{{ asset('images/SoundIT_head_overlay_high-res.png') }}" alt="{{ config('app.name') }}" class="login-logo">
    <h5 class="mb-4">Staff Portal</h5>

    @if ($errors->has('sso'))
        <div class="alert alert-danger small mb-4">{{ $errors->first('sso') }}</div>
    @endif

    <a href="{{ route('auth.microsoft') }}" class="btn btn-accent w-100">
        <i class="bi bi-microsoft me-2"></i>Sign in with Microsoft
    </a>

    <p class="text-muted small mt-4 mb-0">Sign in with your organization's Microsoft 365 account.</p>
</div>
@endsection
