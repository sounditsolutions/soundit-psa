@extends('layouts.app')

@section('title', 'File Too Large')

@section('content')
<div class="row justify-content-center mt-5">
    <div class="col-md-6 text-center">
        <div class="mb-3" style="font-size: 4rem;">&#128196;</div>
        <h2 class="section-title">File Too Large</h2>
        <p class="text-muted">
            The file you tried to upload exceeds the maximum allowed size of 20 MB.
            Please reduce the file size and try again.
        </p>
        <a href="{{ url()->previous() }}" class="btn btn-primary">
            <i class="bi bi-arrow-left me-1"></i>Go Back
        </a>
    </div>
</div>
@endsection
