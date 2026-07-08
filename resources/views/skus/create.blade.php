@extends('layouts.app')

@section('title', 'New SKU')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('skus.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to SKUs
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col">
        <h4 class="section-title">New SKU</h4>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-body">
                <form method="POST" action="{{ route('skus.store') }}">
                    @csrf
                    @include('skus._form', ['sku' => null])
                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary">Create SKU</button>
                        <a href="{{ route('skus.index') }}" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
