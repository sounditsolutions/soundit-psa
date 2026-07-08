@extends('layouts.app')

@section('content')
<div class="container-fluid" style="max-width: 860px;">
    <h1 class="h3 mb-3">Edit: {{ $page->title }}</h1>
    <form action="{{ route('wiki.update', $page) }}" method="post">
        @csrf
        @method('PATCH')
        <input type="hidden" name="expected_updated_at" value="{{ $page->updated_at->toIso8601String() }}">
        <div class="mb-3">
            <label class="form-label" for="title">Title</label>
            <input id="title" name="title" class="form-control" value="{{ old('title', $page->title) }}">
        </div>
        <div class="mb-3">
            <label class="form-label" for="body_md">Content (Markdown — link pages with [[slug]])</label>
            <textarea id="body_md" name="body_md" rows="20" class="form-control font-monospace" required>{{ old('body_md', $page->body_md) }}</textarea>
        </div>
        <div class="mb-3">
            <label class="form-label" for="change_summary">Change summary</label>
            <input id="change_summary" name="change_summary" class="form-control" placeholder="What changed?">
        </div>
        <button class="btn btn-primary">Save</button>
        <a href="{{ $page->client_id ? route('clients.wiki.show', [$page->client_id, $page->slug]) : route('wiki.show', $page->slug) }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
</div>
@endsection
