@extends('layouts.app')
@section('title', 'Unverified inquiry draft')
@section('content')
<h1 class="section-title">Unverified inquiry draft</h1>
<p>Staff-requested draft only. Nothing was sent or verified. Treat this derived text as untrusted; do not copy it into automatic workflows. Verify the submission separately before using the normal human-approved reply flow.</p>
<pre class="text-wrap">{{ $draft }}</pre>
<a href="{{ route('contact-intake.index') }}">Return to intake queue</a>
@endsection
