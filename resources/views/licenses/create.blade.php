@extends('layouts.app')

@section('title', 'New License')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('licenses.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Licenses
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col"><h4 class="section-title">New License</h4></div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="POST" action="{{ route('licenses.store') }}">
                    @csrf

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label for="client_id" class="form-label">Client</label>
                            <select class="form-select @error('client_id') is-invalid @enderror"
                                    id="client_id" name="client_id" required>
                                <option value="">Select client...</option>
                                @foreach($clients as $c)
                                    <option value="{{ $c->id }}" {{ old('client_id') == $c->id ? 'selected' : '' }}>
                                        {{ $c->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('client_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label for="license_type_id" class="form-label">License Type</label>
                            <select class="form-select @error('license_type_id') is-invalid @enderror"
                                    id="license_type_id" name="license_type_id" required>
                                <option value="">Select type...</option>
                                @foreach($licenseTypes as $lt)
                                    <option value="{{ $lt->id }}" {{ old('license_type_id') == $lt->id ? 'selected' : '' }}>
                                        {{ $lt->name }} ({{ $lt->vendor }})
                                    </option>
                                @endforeach
                            </select>
                            @error('license_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label for="quantity" class="form-label">Quantity</label>
                            <input type="number" class="form-control @error('quantity') is-invalid @enderror"
                                   id="quantity" name="quantity" value="{{ old('quantity', 1) }}" min="1" required>
                            @error('quantity')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label">Status</label>
                            <select class="form-select" id="status" name="status">
                                <option value="active" selected>Active</option>
                                <option value="suspended">Suspended</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label for="vendor_ref" class="form-label">Vendor Reference</label>
                            <input type="text" class="form-control" id="vendor_ref" name="vendor_ref"
                                   value="{{ old('vendor_ref') }}" placeholder="Optional ID from vendor">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="notes" class="form-label">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="2">{{ old('notes') }}</textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Create License</button>
                        <a href="{{ route('licenses.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
