@extends('layouts.app')

@section('title', 'Custom Quantity Types')

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h2 class="section-title mb-0">Custom Quantity Types</h2>
            <a href="{{ route('settings.general') }}" class="text-decoration-none text-muted small">
                <i class="bi bi-arrow-left me-1"></i>Back to General Settings
            </a>
        </div>
        <p class="text-muted small mb-4">
            Define your own billing quantity types that count assets by type. These extend the built-in
            <strong>Per Workstation</strong> and <strong>Per Server</strong> counters to any asset category
            you track (firewalls, switches, printers, access points, and so on). A custom type can then be
            chosen as the quantity type on a recurring invoice profile line, where it counts active assets —
            contract-scoped when the contract has asset assignments, client-wide otherwise.
        </p>

        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                {{ session('success') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                {{ session('error') }}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif

        {{-- Existing types --}}
        <div class="card card-static shadow-sm mb-4">
            <div class="card-header"><i class="bi bi-tags me-2"></i>Defined Types</div>
            <div class="card-body">
                @if($customTypes->isEmpty())
                    <p class="text-muted mb-0">No custom quantity types yet. Add one below.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Asset Types Counted</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">In Use</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($customTypes as $ct)
                                    <tr>
                                        <td>
                                            <span class="fw-semibold">{{ $ct->name }}</span>
                                            @if($ct->description)
                                                <div class="text-muted small">{{ $ct->description }}</div>
                                            @endif
                                        </td>
                                        <td>
                                            @foreach($ct->asset_types as $type)
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis me-1">{{ $type }}</span>
                                            @endforeach
                                        </td>
                                        <td class="text-center">
                                            @if($ct->is_active)
                                                <span class="badge bg-success-subtle text-success-emphasis">Active</span>
                                            @else
                                                <span class="badge bg-secondary-subtle text-secondary-emphasis">Inactive</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            @if($ct->profile_lines_count > 0)
                                                <span class="badge bg-info-subtle text-info-emphasis" title="Used on {{ $ct->profile_lines_count }} profile line(s)">{{ $ct->profile_lines_count }}</span>
                                            @else
                                                <span class="text-muted">&mdash;</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <a href="{{ route('settings.quantity-types.edit', $ct) }}" class="btn btn-sm btn-outline-secondary">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <form method="POST" action="{{ route('settings.quantity-types.destroy', $ct) }}" class="d-inline"
                                                  onsubmit="return confirm('Delete custom quantity type &quot;{{ $ct->name }}&quot;?');">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" {{ $ct->profile_lines_count > 0 ? 'disabled title=In use — deactivate instead' : '' }}>
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Add new --}}
        <div class="card card-static shadow-sm">
            <div class="card-header"><i class="bi bi-plus-lg me-2"></i>Add Custom Quantity Type</div>
            <div class="card-body">
                @if(empty($allAssetTypes))
                    <div class="alert alert-info mb-0">
                        No asset types found in your database yet. Sync devices from an RMM (or add assets) first —
                        custom quantity types count assets by their type.
                    </div>
                @else
                    <form method="POST" action="{{ route('settings.quantity-types.store') }}">
                        @csrf

                        <div class="row g-3">
                            <div class="col-md-5">
                                <label for="name" class="form-label">Name</label>
                                <input type="text" class="form-control @error('name') is-invalid @enderror"
                                       id="name" name="name" value="{{ old('name') }}"
                                       placeholder="e.g., Per Firewall" required>
                                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-5">
                                <label for="description" class="form-label">Description <span class="text-muted">(optional)</span></label>
                                <input type="text" class="form-control @error('description') is-invalid @enderror"
                                       id="description" name="description" value="{{ old('description') }}"
                                       placeholder="What this counts">
                                @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="form-check mb-2">
                                    <input type="hidden" name="is_active" value="0">
                                    <input type="checkbox" class="form-check-input" id="is_active" name="is_active"
                                           value="1" {{ old('is_active', true) ? 'checked' : '' }}>
                                    <label class="form-check-label" for="is_active">Active</label>
                                </div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label fw-semibold">Asset Types to Count</label>
                            @error('asset_types')<div class="text-danger small mb-1">{{ $message }}</div>@enderror
                            <div class="row">
                                @foreach($allAssetTypes as $type)
                                    <div class="col-md-4">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input"
                                                   name="asset_types[]" value="{{ $type }}"
                                                   id="at_{{ Str::slug($type) }}"
                                                   {{ in_array($type, old('asset_types', [])) ? 'checked' : '' }}>
                                            <label class="form-check-label" for="at_{{ Str::slug($type) }}">{{ $type }}</label>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary mt-3">
                            <i class="bi bi-check-lg me-1"></i>Create
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection
