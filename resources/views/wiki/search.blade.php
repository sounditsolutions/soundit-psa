@extends('layouts.app')

@section('content')
<div class="container-fluid" style="max-width: 860px;">
    <h1 class="h3 mb-3">Wiki search {{ $client ? '— '.$client->name : '' }}</h1>

    <form method="get" class="mb-4">
        @if ($client)<input type="hidden" name="client_id" value="{{ $client->id }}">@endif
        <div class="input-group">
            <input type="search" name="q" value="{{ $query }}" class="form-control" placeholder="Search pages and facts…" aria-label="Search the wiki" autofocus>
            <button class="btn btn-outline-secondary">Search</button>
        </div>
    </form>

    @if ($query !== '')
        <h2 class="h6 text-uppercase text-muted">Pages ({{ $results['pages']->count() }})</h2>
        <div class="list-group mb-4">
            @forelse ($results['pages'] as $page)
                <a class="list-group-item list-group-item-action"
                   href="{{ $page->client_id ? route('clients.wiki.show', [$page->client_id, $page->slug]) : route('wiki.show', $page->slug) }}">
                    {{ $page->title }}
                    <span class="text-muted small ms-2">{{ $page->client_id ? $page->client?->name : 'global' }} · {{ $page->slug }}</span>
                </a>
            @empty
                <div class="list-group-item text-muted">No matching pages.</div>
            @endforelse
        </div>

        <h2 class="h6 text-uppercase text-muted">Facts ({{ $results['facts']->count() }})</h2>
        <div class="list-group">
            @forelse ($results['facts'] as $fact)
                <a class="list-group-item list-group-item-action"
                   href="{{ $fact->page->client_id ? route('clients.wiki.show', [$fact->page->client_id, $fact->page->slug]) : route('wiki.show', $fact->page->slug) }}">
                    {{ $fact->statement }}
                    <span class="badge {{ $fact->status->badgeClass() }} ms-2">{{ $fact->status->label() }}</span>
                </a>
            @empty
                <div class="list-group-item text-muted">No matching facts.</div>
            @endforelse
        </div>
    @endif
</div>
@endsection
