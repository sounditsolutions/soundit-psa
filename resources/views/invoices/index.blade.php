@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
{{-- Bulk actions (e.g. void) redirect here with a warning when a backend
     reconciliation is needed; the app layout renders success/error but not
     warning, so surface it locally (psa-bl36l MF7). --}}
@if(session('warning'))
    <div class="alert alert-warning alert-dismissible fade show">
        {{ session('warning') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="row mb-3">
    <div class="col d-flex align-items-center justify-content-between">
        <h4 class="section-title mb-0">Invoices</h4>
        <a href="{{ route('invoices.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Invoice
        </a>
    </div>
</div>

@include('invoices._list', ['listRoute' => 'invoices.index', 'prefilter' => []])
@endsection
