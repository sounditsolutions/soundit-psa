@extends('layouts.app')

@section('title', 'Contracts')

@section('content')
<div class="row mb-3">
    <div class="col d-flex align-items-center justify-content-between">
        <h4 class="section-title mb-0">Contracts</h4>
    </div>
</div>

@include('contracts._list', [
    'listRoute' => 'contracts.index-all',
    'prefilter' => [],
])
@endsection
