@extends('layouts.app')

@section('title', 'Compose Email')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('emails.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Emails
        </a>
    </div>
</div>

<div class="row mb-3">
    <div class="col">
        <h4 class="section-title">Compose Email</h4>
    </div>
</div>

<div class="card card-static shadow-sm">
    <div class="card-body">
        <form method="POST" action="{{ route('emails.send') }}">
            @csrf

            <div class="row g-3">
                <div class="col-md-6">
                    <label for="to" class="form-label">To <span class="text-danger">*</span></label>
                    <input type="email" name="to" id="to"
                           class="form-control @error('to') is-invalid @enderror"
                           value="{{ old('to', $to) }}" required autofocus>
                    @error('to')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-md-6">
                    <label for="to_name" class="form-label">Recipient Name</label>
                    <input type="text" name="to_name" id="to_name"
                           class="form-control"
                           value="{{ old('to_name', $toName) }}" placeholder="Optional">
                </div>

                <div class="col-12">
                    <label for="subject" class="form-label">Subject <span class="text-danger">*</span></label>
                    <input type="text" name="subject" id="subject"
                           class="form-control @error('subject') is-invalid @enderror"
                           value="{{ old('subject', $subject) }}" required>
                    @error('subject')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="col-12">
                    <label for="body" class="form-label">Message <span class="text-danger">*</span></label>
                    <x-markdown-editor name="body" id="body" rows="10" toolbar="email" :required="true" />
                    <small class="form-text text-muted">Supports markdown formatting. Email signature will be appended automatically.</small>
                </div>

                <div class="col-md-6">
                    <label for="cc" class="form-label">CC <small class="text-muted">(comma-separated)</small></label>
                    <input type="text" name="cc" id="cc" class="form-control"
                           value="{{ old('cc') }}" placeholder="user@example.com, other@example.com">
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-send me-1"></i>Send Email
                </button>
                <a href="{{ route('emails.index') }}" class="btn btn-outline-secondary ms-2">Cancel</a>
            </div>
        </form>
    </div>
</div>
@endsection
