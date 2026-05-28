@extends('layouts.app')

@section('title', 'Add Staff')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('settings.staff.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Staff
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title">Add Staff Member</h4>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="POST" action="{{ route('settings.staff.store') }}">
                    @csrf
                    @include('settings.staff._form', ['user' => null])
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Create Staff Member</button>
                        <a href="{{ route('settings.staff.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
