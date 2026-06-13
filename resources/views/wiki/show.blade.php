@extends('layouts.app')

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h3 mb-0">{{ $page->title }}</h1>
            <div class="small text-muted">
                {{ $page->kind->label() }} · {{ $page->slug }}
                @if ($client) · {{ $client->name }} @endif
                @if (! empty($deviationAnchors))
                    · <span class="badge bg-secondary">client deviations applied</span>
                @endif
            </div>
        </div>
        <div class="btn-group">
            <a href="{{ route('wiki.edit', $page) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i> Edit</a>
            <a href="{{ route('wiki.history', $page) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history"></i> History</a>
            <details class="d-inline-block">
                <summary class="btn btn-outline-danger btn-sm"><i class="bi bi-archive"></i> Archive</summary>
                <form method="POST" action="{{ route('wiki.archive', $page) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-outline-danger btn-sm">Confirm archive</button>
                </form>
            </details>
        </div>
    </div>

    {{-- §8.1.1: ambient per-section provenance counts (zero-state silent) --}}
    @if (! empty($sectionSummaries))
        <div class="small text-muted mb-2">
            @foreach ($sectionSummaries as $anchor => $summary)
                <span class="me-3">{{ \Illuminate\Support\Str::headline($anchor) }}: {{ $summary }}</span>
            @endforeach
        </div>
    @endif

    <div class="row">
        <div class="col-lg-9">
            <div class="card"><div class="card-body wiki-content">{!! $html !!}</div></div>
        </div>
        <div class="col-lg-3">
            @if ($backlinks->isNotEmpty())
                <div class="card">
                    <div class="card-header small text-uppercase text-muted">Linked from</div>
                    <ul class="list-group list-group-flush">
                        @foreach ($backlinks as $link)
                            <li class="list-group-item">
                                <a href="{{ $link->fromPage->client_id
                                    ? route('clients.wiki.show', [$link->fromPage->client_id, $link->fromPage->slug])
                                    : route('wiki.show', $link->fromPage->slug) }}">
                                    {{ $link->fromPage->title }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @include('wiki._provenance', ['facts' => $facts])
        </div>
    </div>
</div>
@endsection
