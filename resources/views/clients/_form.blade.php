<div class="mb-3">
    <label for="name" class="form-label">Company Name</label>
    <input type="text" class="form-control @error('name') is-invalid @enderror"
           id="name" name="name" value="{{ old('name', $client->name ?? '') }}" required autofocus>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="notes" class="form-label">Notes</label>
    <textarea class="form-control @error('notes') is-invalid @enderror"
              id="notes" name="notes" rows="4">{{ old('notes', $client->notes ?? '') }}</textarea>
    <div class="form-text">Free-form notes about this client. Visible to technicians and the AI assistant.</div>
    @error('notes')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label for="phone" class="form-label">Phone</label>
        <input type="text" class="form-control @error('phone') is-invalid @enderror"
               id="phone" name="phone" value="{{ old('phone', $client->phone_display ?? '') }}">
        @error('phone')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label for="email" class="form-label">Email</label>
        <input type="email" class="form-control @error('email') is-invalid @enderror"
               id="email" name="email" value="{{ old('email', $client->email ?? '') }}">
        @error('email')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="mb-3">
    <label for="website" class="form-label">Website</label>
    <input type="url" class="form-control @error('website') is-invalid @enderror"
           id="website" name="website" value="{{ old('website', $client->website ?? '') }}"
           placeholder="https://">
    @error('website')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="primary_tech_id" class="form-label">Primary Tech</label>
    <select class="form-select @error('primary_tech_id') is-invalid @enderror"
            id="primary_tech_id" name="primary_tech_id">
        <option value="">-- None --</option>
        @foreach($users as $user)
            <option value="{{ $user->id }}" {{ old('primary_tech_id', $client->primary_tech_id ?? '') == $user->id ? 'selected' : '' }}>
                {{ $user->name }}
            </option>
        @endforeach
    </select>
    <div class="form-text">Default technician for AI triage auto-assignment</div>
    @error('primary_tech_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="reseller_id" class="form-label">Reseller</label>
    <select class="form-select @error('reseller_id') is-invalid @enderror"
            id="reseller_id" name="reseller_id">
        <option value="">-- None (direct client) --</option>
        @foreach($resellerCandidates as $candidate)
            <option value="{{ $candidate->id }}" {{ old('reseller_id', $client->reseller_id ?? '') == $candidate->id ? 'selected' : '' }}>
                {{ $candidate->name }}
            </option>
        @endforeach
    </select>
    <div class="form-text">If this client is serviced through a reseller/partner MSP, select the reseller here</div>
    @error('reseller_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<hr class="my-4">
<h6 class="text-muted mb-3">Address</h6>

<div class="mb-3">
    <label for="address_line1" class="form-label">Address Line 1</label>
    <input type="text" class="form-control @error('address_line1') is-invalid @enderror"
           id="address_line1" name="address_line1" value="{{ old('address_line1', $client->address_line1 ?? '') }}">
    @error('address_line1')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="address_line2" class="form-label">Address Line 2</label>
    <input type="text" class="form-control @error('address_line2') is-invalid @enderror"
           id="address_line2" name="address_line2" value="{{ old('address_line2', $client->address_line2 ?? '') }}">
    @error('address_line2')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="row g-3 mb-3">
    <div class="col-md-5">
        <label for="city" class="form-label">City</label>
        <input type="text" class="form-control @error('city') is-invalid @enderror"
               id="city" name="city" value="{{ old('city', $client->city ?? '') }}">
        @error('city')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-4">
        <label for="state" class="form-label">State</label>
        <input type="text" class="form-control @error('state') is-invalid @enderror"
               id="state" name="state" value="{{ old('state', $client->state ?? '') }}">
        @error('state')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-3">
        <label for="postcode" class="form-label">Zip Code</label>
        <input type="text" class="form-control @error('postcode') is-invalid @enderror"
               id="postcode" name="postcode" value="{{ old('postcode', $client->postcode ?? '') }}">
        @error('postcode')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

@if(isset($client) && $client->exists)
<hr class="my-4">
<h6 class="text-muted mb-3">Site Notes</h6>

<div class="mb-3">
    <x-markdown-editor name="site_notes" id="form_site_notes" :value="$client->site_notes ?? ''"
                       rows="8" placeholder="Document this client's environment, network layout, servers, special procedures..." />
    @if($client->site_notes_updated_at)
        <div class="form-text">
            Last updated {{ $client->site_notes_updated_at->diffForHumans() }}
            @if($client->siteNotesUpdatedBy)
                by {{ $client->siteNotesUpdatedBy->name }}
            @endif
        </div>
    @endif
</div>

<hr class="my-4">
<h6 class="text-muted mb-3">
    <i class="bi bi-shield-lock me-1"></i>Credentials
    <span class="badge bg-warning text-dark ms-2" style="font-size: 0.7rem;">Not shared with AI</span>
</h6>

<div class="mb-3">
    <x-markdown-editor name="credentials" id="form_credentials" :value="$client->credentials ?? ''"
                       rows="5" placeholder="Vault references, alarm codes, WiFi passwords, admin credentials..." />
    <div class="form-text">Credentials are never shared with AI triage. Store vault references, access codes, and site-specific credentials here.</div>
    @if($client->credentials_updated_at)
        <div class="form-text">
            Last updated {{ $client->credentials_updated_at->diffForHumans() }}
            @if($client->credentialsUpdatedBy)
                by {{ $client->credentialsUpdatedBy->name }}
            @endif
        </div>
    @endif
</div>

<hr class="my-4">

<div class="mb-3">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
               {{ old('is_active', $client->is_active) ? 'checked' : '' }}>
        <label class="form-check-label" for="is_active">Active</label>
    </div>
</div>
@endif
