@extends('layouts.app')

@section('title', 'Assets')

@section('content')
<div class="row mb-3">
    <div class="col d-flex align-items-center justify-content-between">
        <h4 class="section-title mb-0">
            Assets
            <span class="text-muted fw-normal" style="font-size: 0.85rem;">({{ $assets->total() }})</span>
        </h4>
        <a href="{{ route('assets.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Asset
        </a>
    </div>
</div>

@include('assets._list', ['listRoute' => 'assets.index', 'prefilter' => []])
@endsection
