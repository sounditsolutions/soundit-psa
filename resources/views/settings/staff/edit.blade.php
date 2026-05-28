@extends('layouts.app')

@section('title', $user->name . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('settings.staff.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Staff
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title mb-1">{{ $user->name }}</h4>
        @if(!$user->is_active)
            <span class="badge bg-secondary">Inactive</span>
        @endif
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header"><i class="bi bi-pencil me-2"></i>Staff Details</div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.staff.update', $user) }}">
                    @csrf
                    @method('PATCH')
                    @include('settings.staff._form', ['user' => $user])
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="{{ route('settings.staff.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mt-4">
            <div class="card-header"><i class="bi bi-bell me-2"></i>Notification Preferences</div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Choose which events send email notifications to {{ $user->name }} ({{ $user->email }}).
                </p>

                <form method="POST" action="{{ route('settings.staff.notifications.update', $user) }}">
                    @csrf
                    @method('PATCH')

                    @foreach($notificationTypes as $type)
                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="notify_{{ $type->value }}" value="0">
                        <input class="form-check-input"
                               type="checkbox"
                               role="switch"
                               id="notify_{{ $type->value }}"
                               name="notify_{{ $type->value }}"
                               value="1"
                               {{ $user->wantsNotification($type) ? 'checked' : '' }}>
                        <label class="form-check-label" for="notify_{{ $type->value }}">
                            <i class="bi {{ $type->icon() }} me-1 text-muted"></i>{{ $type->label() }}
                            <div class="form-text">{{ $type->description() }}</div>
                        </label>
                    </div>
                    @endforeach

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Notification Preferences
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-4">
            <div class="card-header"><i class="bi bi-person-badge me-2"></i>Profile Picture</div>
            <div class="card-body text-center">
                <div class="mb-3">
                    <x-avatar :user="$user" :size="80" />
                </div>

                <form method="POST" action="{{ route('settings.staff.avatar.update', $user) }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-2">
                        <input type="file"
                               class="form-control form-control-sm @error('avatar') is-invalid @enderror"
                               name="avatar"
                               accept="image/*">
                        @error('avatar')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-upload me-1"></i>Upload
                    </button>
                </form>

                @if($user->avatar_path)
                    <form method="POST" action="{{ route('settings.staff.avatar.destroy', $user) }}" class="mt-2">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                            <i class="bi bi-trash me-1"></i>Remove
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><i class="bi bi-shield-lock me-2"></i>SSO Status</div>
            <div class="card-body">
                @if($user->microsoft_id)
                    <p class="mb-1"><i class="bi bi-check-circle text-success me-1"></i>Linked to Microsoft Entra ID</p>
                    <p class="text-muted small mb-0">Microsoft ID: {{ Str::limit($user->microsoft_id, 20) }}</p>
                @else
                    <p class="text-muted mb-0">Not yet linked. The account will be linked automatically on their first SSO login.</p>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
