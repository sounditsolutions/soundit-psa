{{-- §8.1 / psa-7ph7: page nav partial — always present on wiki/show (right column).
     Provides on-page search, sibling-page list, and back-to-index link.
     Variables: $siblings (array of {title,url,active}), $indexUrl, $searchAction. --}}
<div class="card mb-3">
    <div class="card-header small text-uppercase">Wiki</div>
    <div class="card-body py-2">
        <form action="{{ $searchAction }}" method="get" class="mb-0">
            @if (isset($searchClientId))
                <input type="hidden" name="client_id" value="{{ $searchClientId }}">
            @endif
            <input type="search" name="q" class="form-control form-control-sm" placeholder="Search this wiki…" aria-label="Search wiki">
        </form>
    </div>
    <ul class="list-group list-group-flush">
        @foreach ($siblings as $sib)
            <li class="list-group-item py-1 px-3 {{ $sib['active'] ? 'active' : '' }}"
                @if ($sib['active']) style="background-color:#1e3a5f;border-color:#1e3a5f;" @endif>
                <a href="{{ $sib['url'] }}"
                   class="small text-decoration-none {{ $sib['active'] ? 'text-white' : '' }}">
                    {{ $sib['title'] }}
                </a>
            </li>
        @endforeach
    </ul>
    <div class="card-footer py-1">
        <a href="{{ $indexUrl }}" class="small text-muted text-decoration-none">
            <i class="bi bi-grid"></i> All pages
        </a>
    </div>
</div>
