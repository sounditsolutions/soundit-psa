@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h3 mb-0">{{ $client ? $client->name.' — Wiki' : 'Wiki' }}</h1>
        <a href="{{ route('wiki.create', $client ? ['client_id' => $client->id] : []) }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-plus-lg"></i> New page
        </a>
    </div>

    {{-- §8.1 advisory: search is the primary affordance at the top --}}
    <form action="{{ route('wiki.search') }}" method="get" class="mb-4">
        @if ($client)<input type="hidden" name="client_id" value="{{ $client->id }}">@endif
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" class="form-control" placeholder="Search the wiki…" autofocus>
        </div>
    </form>

    @foreach ($pages as $kind => $group)
        <h2 class="h6 text-uppercase text-muted mt-4">{{ \App\Enums\WikiPageKind::from($kind)->label() }}</h2>
        <div class="list-group">
            @foreach ($group as $page)
                <a class="list-group-item list-group-item-action"
                   href="{{ $client ? route('clients.wiki.show', [$client, $page->slug]) : route('wiki.show', $page->slug) }}">
                    {{ $page->title }}
                    <span class="text-muted small ms-2">{{ $page->slug }}</span>
                </a>
            @endforeach
        </div>
    @endforeach

    @if ($pages->isEmpty())
        <p class="text-muted">No pages yet. Create the first one.</p>
    @endif

    {{-- §8.1.4: health counters BELOW the content index, muted, zero-state silent --}}
    @if (($health['unverified'] ?? 0) > 0 || ($health['disputed'] ?? 0) > 0)
        <div class="mt-4 small text-muted">
            Needs review:
            @if ($health['unverified'] > 0)
                <span class="badge bg-secondary">{{ $health['unverified'] }} unverified</span>
            @endif
            @if ($health['disputed'] > 0)
                <span class="badge bg-secondary">{{ $health['disputed'] }} disputed</span>
            @endif
        </div>
    @endif
</div>
@endsection
