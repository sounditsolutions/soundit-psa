@extends('layouts.app')

@section('title', 'Preferences')

@section('content')
<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <h2 class="section-title">Preferences</h2>

        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-person-badge me-2"></i>Profile Picture
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <x-avatar :user="$user" :size="64" />
                    <div>
                        <div class="fw-semibold">{{ $user->name }}</div>
                        <div class="text-muted small">{{ $user->email }}</div>
                    </div>
                </div>

                <form method="POST" action="{{ route('preferences.avatar.update') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-3">
                        <input type="file"
                               class="form-control @error('avatar') is-invalid @enderror"
                               id="avatar"
                               name="avatar"
                               accept="image/*">
                        @error('avatar')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">JPEG, PNG, GIF, or WebP. Max 2 MB.</div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-upload me-1"></i>Upload Picture
                    </button>
                </form>

                @if($user->avatar_path)
                    <form method="POST" action="{{ route('preferences.avatar.destroy') }}" class="mt-2">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-trash me-1"></i>Remove Picture
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <div class="card card-static shadow-sm mt-4">
            <div class="card-header">
                <i class="bi bi-telephone-forward me-2"></i>SIP Endpoint
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Enter your Plivo endpoint credentials. Create endpoints in the
                    <a href="https://console.plivo.com" target="_blank">Plivo dashboard</a>,
                    then enter the username and password below.
                </p>

                <form method="POST" action="{{ route('preferences.update') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="label" class="form-label">Label</label>
                        <input type="text"
                               class="form-control @error('label') is-invalid @enderror"
                               id="label"
                               name="label"
                               value="{{ old('label', $endpoint?->label) }}"
                               placeholder="e.g. Charlie's Phone"
                               maxlength="100"
                               required>
                        @error('label')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="sip_username" class="form-label">SIP Username</label>
                        <input type="text"
                               class="form-control @error('sip_username') is-invalid @enderror"
                               id="sip_username"
                               name="sip_username"
                               value="{{ old('sip_username', $endpoint?->sip_username) }}"
                               placeholder="e.g. soundit_charlie"
                               maxlength="100"
                               required>
                        @error('sip_username')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="sip_password" class="form-label">SIP Password</label>
                        <input type="password"
                               class="form-control @error('sip_password') is-invalid @enderror"
                               id="sip_password"
                               name="sip_password"
                               placeholder="{{ $endpoint?->sip_username ? 'Leave blank to keep current' : 'Enter password' }}"
                               maxlength="255"
                               {{ $endpoint ? '' : 'required' }}>
                        @error('sip_password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    @if($endpoint?->sip_username)
                    <div class="mb-3">
                        <label class="form-label">SIP URI</label>
                        <div class="form-control bg-light" style="cursor: default;">
                            sip:{{ $endpoint->sip_username }}@phone.plivo.com
                        </div>
                        <div class="form-text">
                            <i class="bi bi-check-circle text-success me-1"></i>Endpoint configured
                        </div>
                    </div>
                    @endif

                    <div class="mb-3 form-check">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox"
                               class="form-check-input"
                               id="is_active"
                               name="is_active"
                               value="1"
                               {{ old('is_active', $endpoint?->is_active ?? true) ? 'checked' : '' }}>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Endpoint
                    </button>
                </form>
            </div>
        </div>

        <div class="card card-static shadow-sm mt-4">
            <div class="card-header">
                <i class="bi bi-envelope me-2"></i>Email Signature
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Personal signature appended to outbound emails you send. Leave blank to use the
                    company-wide signature, or empty both for no signature (e.g. if using Exclaimer).
                </p>

                <form method="POST" action="{{ route('preferences.signature.update') }}">
                    @csrf

                    <div class="mb-3">
                        <textarea class="form-control @error('email_signature') is-invalid @enderror"
                                  id="email_signature"
                                  name="email_signature"
                                  rows="4"
                                  placeholder="e.g. Thanks,&#10;Jane&#10;Acme MSP">{{ old('email_signature', $user->email_signature) }}</textarea>
                        @error('email_signature')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                        <div class="form-text">Plain text. Line breaks are preserved.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Save Signature
                    </button>
                </form>
            </div>
        </div>

        <div class="card card-static shadow-sm mt-4">
            <div class="card-header">
                <i class="bi bi-bell me-2"></i>Email Notifications
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Choose which events send you an email notification.
                    Notifications are sent to your account email ({{ $user->email }}).
                </p>

                <form method="POST" action="{{ route('preferences.notifications.update') }}">
                    @csrf

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
</div>
@endsection
