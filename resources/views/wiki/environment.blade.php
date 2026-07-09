@extends('layouts.app')

@section('title', $client->name.' — Wiki')

@push('styles')
<style>
    /* psa-s5bf: keep anchor jumps clear of the sticky topbar. */
    .wiki-env-section { scroll-margin-top: calc(var(--topbar-height) + 1rem); }
    #wiki-env-nav .list-group-item.active-section {
        background-color: #eef2f7;
        box-shadow: inset 3px 0 0 var(--accent, #c8a24b);
    }
    #wiki-env-nav .list-group-item.active-section > a { font-weight: 600; }
</style>
@endpush

@section('content')
<div class="container-fluid">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h3 mb-0">{{ $client->name }} — Wiki</h1>
        <div class="btn-group">
            <a href="{{ route('wiki.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-globe"></i> Global wiki
            </a>
            <a href="{{ route('wiki.create', ['client_id' => $client->id]) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-plus-lg"></i> New page
            </a>
        </div>
    </div>

    {{-- §8.1 advisory: search is the primary affordance at the top --}}
    <form action="{{ route('wiki.search') }}" method="get" class="mb-4">
        <input type="hidden" name="client_id" value="{{ $client->id }}">
        <div class="input-group">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="search" name="q" class="form-control" placeholder="Search {{ $client->name }}'s wiki…" aria-label="Search the wiki">
        </div>
    </form>

    <div class="row">
        {{-- Consolidated single-scroll environment. Content-left mirrors wiki/show. --}}
        <div class="col-lg-9">
            @forelse ($sections as $section)
                <section id="{{ $section['anchor'] }}" class="wiki-env-section mb-4">
                    <div class="d-flex align-items-center justify-content-between border-bottom pb-2 mb-3">
                        <h2 class="h4 mb-0">{{ $section['page']->title }}</h2>
                        <a href="{{ route('clients.wiki.show', [$client, $section['page']->slug]) }}"
                           class="btn btn-outline-secondary btn-sm flex-shrink-0"
                           title="Open {{ $section['page']->title }} to edit, view history, or manage facts">
                            <i class="bi bi-box-arrow-up-right"></i> Open
                        </a>
                    </div>
                    {{-- §8.1.1: ambient per-section provenance count, silent when clean --}}
                    @if ($section['summary'])
                        <div class="small text-muted mb-2">{{ $section['summary'] }}</div>
                    @endif
                    <div class="wiki-content">{!! $section['html'] !!}</div>
                </section>
            @empty
                <p class="text-muted">No environment pages yet.</p>
            @endforelse
        </div>

        {{-- Sticky in-page nav + secondary links --}}
        <div class="col-lg-3">
            <div class="detail-sidebar">
                <nav class="card mb-3" id="wiki-env-nav" aria-label="On this page">
                    <div class="card-header small text-uppercase">On this page</div>
                    <ul class="list-group list-group-flush">
                        @foreach ($sections as $section)
                            <li class="list-group-item py-1 px-3" data-anchor-item="{{ $section['anchor'] }}">
                                <a href="#{{ $section['anchor'] }}"
                                   class="small text-decoration-none d-flex justify-content-between align-items-center gap-2">
                                    <span>{{ $section['page']->title }}</span>
                                    @if ($section['summary'])
                                        <i class="bi bi-dot text-secondary" title="{{ $section['summary'] }}" aria-label="Needs review: {{ $section['summary'] }}"></i>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>

                @if ($otherPages->isNotEmpty())
                    <div class="card mb-3">
                        <div class="card-header small text-uppercase">More pages</div>
                        <ul class="list-group list-group-flush">
                            @foreach ($otherPages as $page)
                                <li class="list-group-item py-1 px-3">
                                    <a href="{{ route('clients.wiki.show', [$client, $page->slug]) }}" class="small text-decoration-none">
                                        {{ $page->title }}
                                        <span class="text-muted">· {{ $page->kind->label() }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- §8.1.4: health counters — secondary, muted, zero-state silent, never a nag --}}
                @if (($health['unverified'] ?? 0) > 0 || ($health['disputed'] ?? 0) > 0 || ($health['stale'] ?? 0) > 0)
                    <div class="small text-muted px-1">
                        Needs review:
                        @if ($health['unverified'] > 0)<span class="badge bg-secondary">{{ $health['unverified'] }} unverified</span>@endif
                        @if ($health['disputed'] > 0)<span class="badge bg-secondary">{{ $health['disputed'] }} disputed</span>@endif
                        @if ($health['stale'] > 0)<span class="badge bg-secondary">{{ $health['stale'] }} stale</span>@endif
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // psa-s5bf: highlight the section currently in view in the on-page nav.
    // Progressive enhancement — the anchor links work without it.
    (function () {
        var sections = Array.prototype.slice.call(document.querySelectorAll('.wiki-env-section'));
        if (!sections.length || !('IntersectionObserver' in window)) return;

        var items = {};
        document.querySelectorAll('#wiki-env-nav [data-anchor-item]').forEach(function (li) {
            items[li.getAttribute('data-anchor-item')] = li;
        });

        var visible = {};
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                visible[entry.target.id] = entry.isIntersecting;
            });
            // Mark the topmost section currently in view as active.
            var activeId = null;
            for (var i = 0; i < sections.length; i++) {
                if (visible[sections[i].id]) { activeId = sections[i].id; break; }
            }
            Object.keys(items).forEach(function (id) {
                items[id].classList.toggle('active-section', id === activeId);
            });
        }, { rootMargin: '-20% 0px -70% 0px' });

        sections.forEach(function (s) { observer.observe(s); });
    })();
</script>
@endpush
