@extends('layouts.app')

@section('content')
<div class="container-fluid" style="max-width: 860px;">
    <h1 class="h3 mb-3">New wiki page {{ $client ? 'for '.$client->name : '(global)' }}</h1>
    <form action="{{ route('wiki.store') }}" method="post">
        @csrf
        @if ($client)<input type="hidden" name="client_id" value="{{ $client->id }}">@endif
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="title">Title</label>
                <input id="title" name="title" class="form-control @error('title') is-invalid @enderror" value="{{ old('title') }}" required>
                @error('title')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label" for="slug">Slug</label>
                <input id="slug" name="slug" class="form-control @error('slug') is-invalid @enderror" value="{{ old('slug') }}" placeholder="vendors/fortinet" required>
                @error('slug')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-3">
                <label class="form-label" for="kind">Kind</label>
                <select id="kind" name="kind" class="form-select">
                    @foreach ($kinds as $kind)
                        <option value="{{ $kind->value }}" @selected(old('kind') === $kind->value)>{{ $kind->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="body_md">Content (Markdown — link pages with [[slug]])</label>
                <textarea id="body_md" name="body_md" rows="16" class="form-control font-monospace">{{ old('body_md') }}</textarea>
            </div>
        </div>
        <div class="mt-3">
            <button class="btn btn-primary">Create page</button>
            <a href="{{ $client ? route('clients.wiki.index', $client) : route('wiki.index') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
@endsection
