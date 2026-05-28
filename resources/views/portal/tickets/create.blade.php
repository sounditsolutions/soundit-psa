@extends('portal.layouts.app')

@section('title', 'New Ticket - ' . App\Support\PortalConfig::companyName() . ' Portal')

@section('content')
<div class="mb-3">
    <a href="{{ route('portal.tickets.index') }}" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Tickets</a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Submit a New Ticket</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('portal.tickets.store') }}">
                    @csrf

                    <div class="mb-3">
                        <label for="subject" class="form-label">Subject</label>
                        <input type="text" name="subject" id="subject" class="form-control @error('subject') is-invalid @enderror"
                               value="{{ old('subject') }}" required autofocus placeholder="Brief summary of the issue">
                        @error('subject')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">Description</label>
                        <textarea name="description" id="description" rows="6" class="form-control @error('description') is-invalid @enderror"
                                  required placeholder="Please describe the issue in detail. Include any error messages, what you were doing when it happened, and the impact on your work.">{{ old('description') }}</textarea>
                        @error('description')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="mb-4">
                        <div class="form-check form-switch">
                            <input type="checkbox" name="urgent" id="urgent" class="form-check-input" value="1" {{ old('urgent') ? 'checked' : '' }}>
                            <label for="urgent" class="form-check-label">
                                <strong>This is urgent</strong>
                                <span class="text-muted d-block small">Check this if the issue is preventing you from working or is time-critical.</span>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Submit Ticket
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
