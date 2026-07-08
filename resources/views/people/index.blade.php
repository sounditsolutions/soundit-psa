@extends('layouts.app')

@section('title', 'People')

@section('content')
<div class="row mb-3">
    <div class="col d-flex justify-content-between align-items-center">
        <h4 class="section-title mb-0">People</h4>
        <a href="{{ route('people.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Contact
        </a>
    </div>
</div>

@include('people._list', ['listRoute' => 'people.index', 'prefilter' => []])
@endsection
