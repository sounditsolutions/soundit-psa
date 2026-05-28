@extends('layouts.app')

@section('title', 'Invoices')

@section('content')
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
