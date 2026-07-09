@extends('layouts.app')

@section('title', 'Edit Custom Quantity Type')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="section-title mb-0">Edit Custom Quantity Type</h2>
            <a href="{{ route('settings.quantity-types.index') }}" class="text-decoration-none text-muted small">
                <i class="bi bi-arrow-left me-1"></i>Back to Custom Quantity Types
            </a>
        </div>

        <div class="card card-static shadow-sm">
            <div class="card-header"><i class="bi bi-pencil me-2"></i>{{ $customType->name }}</div>
            <div class="card-body">
                <form method="POST" action="{{ route('settings.quantity-types.update', $customType) }}">
                    @csrf
                    @method('PATCH')

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" class="form-control @error('name') is-invalid @enderror"
                                   id="name" name="name" value="{{ old('name', $customType->name) }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label for="description" class="form-label">Description <span class="text-muted">(optional)</span></label>
                            <input type="text" class="form-control @error('description') is-invalid @enderror"
                                   id="description" name="description" value="{{ old('description', $customType->description) }}">
                            @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <div class="form-check mb-2">
                                <input type="hidden" name="is_active" value="0">
                                <input type="checkbox" class="form-check-input" id="is_active" name="is_active"
                                       value="1" {{ old('is_active', $customType->is_active) ? 'checked' : '' }}>
                                <label class="form-check-label" for="is_active">Active</label>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label fw-semibold">Asset Types to Count</label>
                        @error('asset_types')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                        @php
                            $selected = old('asset_types', $customType->asset_types ?? []);
                            // Include any stored asset types no longer present in the live asset list so
                            // an operator can still see (and keep) them.
                            $options = collect($allAssetTypes)->merge($selected)->unique()->sort()->values();
                        @endphp
                        @if($options->isEmpty())
                            <div class="alert alert-info mb-0">No asset types available.</div>
                        @else
                            <div class="row">
                                @foreach($options as $type)
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input"
                                                   name="asset_types[]" value="{{ $type }}"
                                                   id="at_{{ Str::slug($type) }}"
                                                   {{ in_array($type, $selected) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="at_{{ Str::slug($type) }}">
                                                {{ $type }}
                                                @if(! in_array($type, $allAssetTypes))
                                                    <span class="badge bg-warning-subtle text-warning-emphasis ms-1" title="Not currently present in your asset database">stale</span>
                                                @endif
                                            </label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i>Save Changes
                        </button>
                        <a href="{{ route('settings.quantity-types.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
