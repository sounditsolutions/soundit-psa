<div class="mb-3">
    <label for="client_id" class="form-label">Client</label>
    <select class="form-select @error('client_id') is-invalid @enderror" id="client_id" name="client_id" required>
        <option value="">Select a client...</option>
        @foreach($clients as $c)
            <option value="{{ $c->id }}" {{ old('client_id', $selectedClientId ?? ($person->client_id ?? '')) == $c->id ? 'selected' : '' }}>
                {{ $c->name }}
            </option>
        @endforeach
    </select>
    @error('client_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label for="first_name" class="form-label">First Name</label>
        <input type="text" class="form-control @error('first_name') is-invalid @enderror"
               id="first_name" name="first_name" value="{{ old('first_name', $person->first_name ?? '') }}">
        @error('first_name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label for="last_name" class="form-label">Last Name</label>
        <input type="text" class="form-control @error('last_name') is-invalid @enderror"
               id="last_name" name="last_name" value="{{ old('last_name', $person->last_name ?? '') }}">
        @error('last_name')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="mb-3">
    <label for="email" class="form-label">Primary Email</label>
    <input type="email" class="form-control @error('email') is-invalid @enderror"
           id="email" name="email" value="{{ old('email', $person->email ?? '') }}">
    @error('email')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div class="form-text">Used for portal login and notifications.</div>
</div>

<div class="mb-3">
    <label class="form-label">Additional Email Addresses</label>
    <div id="additional-emails-container">
        @php
            $additionalEmails = old('additional_emails', isset($person) && $person->exists
                ? $person->additionalEmailAddresses->map(fn ($e) => ['email' => $e->email, 'label' => $e->label])->toArray()
                : []);
        @endphp
        @foreach($additionalEmails as $i => $entry)
            <div class="input-group mb-2" data-email-row>
                <input type="email" class="form-control" name="additional_emails[{{ $i }}][email]"
                       value="{{ $entry['email'] ?? '' }}" placeholder="Email address" required>
                <input type="text" class="form-control" style="max-width: 140px;"
                       name="additional_emails[{{ $i }}][label]"
                       value="{{ $entry['label'] ?? '' }}" placeholder="Label">
                <button type="button" class="btn btn-outline-danger" onclick="this.closest('[data-email-row]').remove()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
        @endforeach
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="add-email-btn">
        <i class="bi bi-plus-lg"></i> Add Email
    </button>
    @error('additional_emails.*.email')
        <div class="text-danger small mt-1">{{ $message }}</div>
    @enderror
</div>

<script>
document.getElementById('add-email-btn').addEventListener('click', function() {
    const container = document.getElementById('additional-emails-container');
    const index = container.querySelectorAll('[data-email-row]').length;
    const row = document.createElement('div');
    row.className = 'input-group mb-2';
    row.setAttribute('data-email-row', '');
    row.innerHTML = `
        <input type="email" class="form-control" name="additional_emails[${index}][email]"
               placeholder="Email address" required>
        <input type="text" class="form-control" style="max-width: 140px;"
               name="additional_emails[${index}][label]" placeholder="Label">
        <button type="button" class="btn btn-outline-danger" onclick="this.closest('[data-email-row]').remove()">
            <i class="bi bi-x-lg"></i>
        </button>
    `;
    container.appendChild(row);
});
</script>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label for="phone" class="form-label">Phone</label>
        <input type="text" class="form-control @error('phone') is-invalid @enderror"
               id="phone" name="phone" value="{{ old('phone', $person->phone_display ?? '') }}">
        @error('phone')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label for="mobile" class="form-label">Mobile</label>
        <input type="text" class="form-control @error('mobile') is-invalid @enderror"
               id="mobile" name="mobile" value="{{ old('mobile', $person->mobile_display ?? '') }}">
        @error('mobile')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="mb-3">
    <label for="job_title" class="form-label">Job Title</label>
    <input type="text" class="form-control @error('job_title') is-invalid @enderror"
           id="job_title" name="job_title" value="{{ old('job_title', $person->job_title ?? '') }}">
    @error('job_title')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="person_type" class="form-label">Type</label>
    <select class="form-select @error('person_type') is-invalid @enderror" id="person_type" name="person_type">
        @foreach(\App\Enums\PersonType::cases() as $type)
            <option value="{{ $type->value }}" {{ old('person_type', $person->person_type?->value ?? 'user') === $type->value ? 'selected' : '' }}>
                {{ $type->label() }}
            </option>
        @endforeach
    </select>
    @error('person_type')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
    <div class="form-text">Non-user types are excluded from per-user billing and contract auto-assignment.</div>
</div>

<div class="mb-3">
    <label for="notes" class="form-label">Notes</label>
    <textarea class="form-control @error('notes') is-invalid @enderror"
              id="notes" name="notes" rows="4">{{ old('notes', $person->notes ?? '') }}</textarea>
    <div class="form-text">Free-form notes about this contact. Visible to technicians and the AI assistant.</div>
    @error('notes')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <div class="form-check">
        <input type="hidden" name="is_primary" value="0">
        <input class="form-check-input" type="checkbox" id="is_primary" name="is_primary" value="1"
               {{ old('is_primary', $person->is_primary ?? false) ? 'checked' : '' }}>
        <label class="form-check-label" for="is_primary">Primary contact for this client</label>
    </div>
</div>

@if(isset($person) && $person->exists)
<div class="mb-3">
    <div class="form-check">
        <input type="hidden" name="is_active" value="0">
        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
               {{ old('is_active', $person->is_active) ? 'checked' : '' }}>
        <label class="form-check-label" for="is_active">Active</label>
    </div>
</div>
@endif
