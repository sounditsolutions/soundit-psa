@extends('layouts.app')

@section('title', 'Tickets')

@section('content')
<div class="row mb-3">
    <div class="col d-flex align-items-center justify-content-between">
        <h4 class="section-title mb-0">
            Tickets
            <span class="text-muted fw-normal" style="font-size: 0.85rem;">({{ $tickets->total() }})</span>
        </h4>
        <a href="{{ route('tickets.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Ticket
        </a>
    </div>
</div>

@include('tickets._list', ['listRoute' => 'tickets.index', 'prefilter' => []])
@endsection
