@extends('layouts.app')

@section('title', 'Licenses')

@section('content')
<div class="row mb-3">
    <div class="col d-flex justify-content-between align-items-center">
        <h4 class="section-title mb-0">Licenses</h4>
        <a href="{{ route('licenses.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New License
        </a>
    </div>
</div>

@include('licenses._list', ['listRoute' => 'licenses.index', 'prefilter' => []])
@endsection
