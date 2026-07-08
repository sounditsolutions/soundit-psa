@extends('layouts.app')

@section('title', 'Edit ' . $person->fullName . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('people.show', $person) }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to {{ $person->fullName }}
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title">Edit {{ $person->fullName }}</h4>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="POST" action="{{ route('people.update', $person) }}">
                    @csrf
                    @method('PATCH')
                    @include('people._form')

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                        <a href="{{ route('people.show', $person) }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
