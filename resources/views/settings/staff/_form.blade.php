<div class="mb-3">
    <label for="name" class="form-label">Name</label>
    <input type="text" class="form-control @error('name') is-invalid @enderror"
           id="name" name="name" value="{{ old('name', $user?->name) }}" required>
    @error('name')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

<div class="mb-3">
    <label for="email" class="form-label">Email</label>
    <input type="email" class="form-control @error('email') is-invalid @enderror"
           id="email" name="email" value="{{ old('email', $user?->email) }}" required>
    @error('email')
        <div class="invalid-feedback">{{ $message }}</div>
    @enderror
</div>

@if($user?->exists)
    <div class="mb-3">
        <label for="role" class="form-label">Role</label>
        <select class="form-select @error('role') is-invalid @enderror" id="role" name="role">
            @foreach(\App\Enums\UserRole::cases() as $role)
                <option value="{{ $role->value }}" @selected(old('role', $user->role?->value) === $role->value)>
                    {{ $role->label() }}
                </option>
            @endforeach
        </select>
        <div class="form-text">Controls what this staff member can access. New staff default to Administrator.</div>
        @error('role')
            <div class="invalid-feedback">{{ $message }}</div>
        @enderror
    </div>
@endif

@if($user?->exists && $user->id !== auth()->id())
    <div class="form-check mb-3">
        <input type="hidden" name="is_active" value="0">
        <input type="checkbox" class="form-check-input" id="is_active" name="is_active" value="1"
               {{ old('is_active', $user->is_active) ? 'checked' : '' }}>
        <label class="form-check-label" for="is_active">Active</label>
    </div>
@endif

@if($user?->exists)
    <div class="form-check mb-3">
        <input type="hidden" name="is_contractor" value="0">
        <input type="checkbox" class="form-check-input" id="is_contractor" name="is_contractor" value="1"
               {{ old('is_contractor', $user->is_contractor) ? 'checked' : '' }}>
        <label class="form-check-label" for="is_contractor">
            Contractor <small class="text-muted">— Enable contractor time pool tracking</small>
        </label>
    </div>
@endif
