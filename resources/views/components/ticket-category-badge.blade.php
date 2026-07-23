{{-- ITIL taxonomy category chip for ticket lists (psa-717bn).
     Leaf name in the row, full path on hover; null → gap; retired node preserved.
     Pass the ticket's categoryNode; eager-load categoryNode.parent.parent on the
     list query so pathString() walks the ancestor chain in-memory (no N+1). --}}
@props(['node' => null, 'fallback' => '—'])
@if($node)
    <span class="badge bg-light text-dark border fw-normal" title="{{ $node->pathString() }}">{{ $node->name }}@unless($node->is_active) <span class="text-muted fst-italic ms-1">(retired)</span>@endunless</span>
@else
    <span class="text-muted">{{ $fallback }}</span>
@endif
