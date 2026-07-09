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
            <input type="search" name="q" id="wikiIndexFilter" class="form-control" placeholder="Search the wiki…" aria-label="Search the wiki" autocomplete="off" autofocus>
        </div>
    </form>

    @foreach ($pages as $kind => $group)
        <div data-wiki-group>
            <h2 class="h6 text-uppercase text-muted mt-4">{{ \App\Enums\WikiPageKind::from($kind)->label() }}</h2>
            <div class="list-group">
                @foreach ($group as $page)
                    <a class="list-group-item list-group-item-action" data-wiki-item
                       data-wiki-search="{{ \Illuminate\Support\Str::lower($page->title.' '.$page->slug) }}"
                       href="{{ $client ? route('clients.wiki.show', [$client, $page->slug]) : route('wiki.show', $page->slug) }}">
                        {{ $page->title }}
                        <span class="text-muted small ms-2">{{ $page->slug }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    @endforeach

    {{-- Shown by the live filter (below) when a query matches no pages on this index;
         the server-side "no pages exist" state is a separate message. Enter still runs
         the full page+fact search at route('wiki.search'). --}}
    <p class="text-muted d-none" data-wiki-empty>No pages match your search. Press Enter to search facts too.</p>

    @if ($pages->isEmpty())
        <p class="text-muted">No pages yet. Create the first one.</p>
    @endif

    {{-- §8.1.4: health counters BELOW the content index, muted, zero-state silent --}}
    @if (($health['unverified'] ?? 0) > 0 || ($health['disputed'] ?? 0) > 0 || ($health['stale'] ?? 0) > 0)
        <div class="mt-4 small text-muted">
            Needs review:
            @if ($health['unverified'] > 0)
                <span class="badge bg-secondary">{{ $health['unverified'] }} unverified</span>
            @endif
            @if ($health['disputed'] > 0)
                <span class="badge bg-secondary">{{ $health['disputed'] }} disputed</span>
            @endif
            @if (($health['stale'] ?? 0) > 0)
                <span class="badge bg-secondary">{{ $health['stale'] }} stale</span>
            @endif
        </div>
    @endif
</div>
@endsection

@push('scripts')
{{-- psa-voy3: live client-side filtering of the on-page list as the tech types. The full
     page+fact search (route('wiki.search')) still runs on submit (Enter). --}}
<script>
(function () {
    var input = document.getElementById('wikiIndexFilter');
    if (!input) return;
    var groups = Array.prototype.slice.call(document.querySelectorAll('[data-wiki-group]'));
    if (!groups.length) return;
    var empty = document.querySelector('[data-wiki-empty]');

    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var anyVisible = false;

        groups.forEach(function (group) {
            var groupMatch = false;
            group.querySelectorAll('[data-wiki-item]').forEach(function (item) {
                var hay = item.getAttribute('data-wiki-search') || item.textContent.toLowerCase();
                var match = q === '' || hay.indexOf(q) !== -1;
                item.style.display = match ? '' : 'none';
                if (match) groupMatch = true;
            });
            group.style.display = groupMatch ? '' : 'none';
            if (groupMatch) anyVisible = true;
        });

        if (empty) empty.classList.toggle('d-none', anyVisible || q === '');
    });
})();
</script>
@endpush
