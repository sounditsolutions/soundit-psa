<div class="mb-3">
    <label for="client_id" class="form-label">Client</label>
    <select class="form-select @error('client_id') is-invalid @enderror" id="client_id" name="client_id">
        <option value="">No client (unassigned)</option>
        @foreach($clients as $c)
            <option value="{{ $c->id }}" {{ old('client_id', $selectedClientId ?? ($asset->client_id ?? '')) == $c->id ? 'selected' : '' }}>
                {{ $c->name }}
            </option>
        @endforeach
    </select>
    @error('client_id')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="name" class="form-label">Device Name</label>
    <input type="text" class="form-control @error('name') is-invalid @enderror"
           id="name" name="name" value="{{ old('name', $asset->name ?? '') }}" required>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="notes" class="form-label">Notes</label>
    <textarea class="form-control @error('notes') is-invalid @enderror"
              id="notes" name="notes" rows="4">{{ old('notes', $asset->notes ?? '') }}</textarea>
    <div class="form-text">Free-form notes about this device. Visible to technicians and the AI assistant.</div>
    @error('notes')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label for="hostname" class="form-label">Hostname</label>
        <input type="text" class="form-control @error('hostname') is-invalid @enderror"
               id="hostname" name="hostname" value="{{ old('hostname', $asset->hostname ?? '') }}">
        @error('hostname')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label for="asset_type" class="form-label">Type</label>
        <select class="form-select @error('asset_type') is-invalid @enderror" id="asset_type" name="asset_type">
            <option value="">Select type...</option>
            @php
                $types = ['Workstation', 'Laptop', 'Server', 'Network Device', 'Printer', 'Mobile', 'Other'];
                $currentType = old('asset_type', $asset->asset_type ?? '');
            @endphp
            @foreach($types as $type)
                <option value="{{ $type }}" {{ $currentType === $type ? 'selected' : '' }}>{{ $type }}</option>
            @endforeach
        </select>
        @error('asset_type')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <label for="serial_number" class="form-label">Serial Number</label>
        <input type="text" class="form-control @error('serial_number') is-invalid @enderror"
               id="serial_number" name="serial_number" value="{{ old('serial_number', $asset->serial_number ?? '') }}">
        @error('serial_number')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
    <div class="col-md-6">
        <label for="os" class="form-label">Operating System</label>
        <input type="text" class="form-control @error('os') is-invalid @enderror"
               id="os" name="os" value="{{ old('os', $asset->os ?? '') }}">
        @error('os')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
</div>

<div class="mb-3">
    <label for="ip_address" class="form-label">Primary IP Address</label>
    <input type="text" class="form-control @error('ip_address') is-invalid @enderror"
           id="ip_address" name="ip_address" value="{{ old('ip_address', $asset->ip_address ?? '') }}"
           placeholder="e.g. 192.168.1.100">
    @error('ip_address')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

@if(isset($asset) && $asset->exists)
<div class="mb-3">
    <div class="form-check">
        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
               {{ old('is_active', $asset->is_active) ? 'checked' : '' }}>
        <label class="form-check-label" for="is_active">Active</label>
    </div>
</div>
@endif
