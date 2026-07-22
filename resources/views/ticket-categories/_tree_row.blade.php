{{-- Recursive tree row: $node (with tickets_count + eager children), $depth (1-based). --}}
<tr class="{{ $node->is_active ? '' : 'opacity-50' }}">
    <td>
        <span style="padding-left: {{ ($depth - 1) * 1.75 }}rem;">
            @if($depth > 1)
                <i class="bi bi-arrow-return-right text-muted me-1"></i>
            @endif
            <a href="{{ route('ticket-categories.show', $node) }}" class="text-decoration-none {{ $depth === 1 ? 'fw-semibold' : '' }}">
                {{ $node->name }}
            </a>
        </span>
    </td>
    <td><span class="badge {{ $node->sop_status->badgeClass() }}">{{ $node->sop_status->label() }}</span></td>
    <td class="d-none d-md-table-cell">
        @if($node->record_type_hint)
            <span class="badge {{ $node->record_type_hint->badgeClass() }}">{{ $node->record_type_hint->label() }}</span>
        @else
            <span class="text-muted">-</span>
        @endif
    </td>
    <td class="text-end d-none d-md-table-cell">{{ $node->tickets_count > 0 ? number_format($node->tickets_count) : '-' }}</td>
    <td class="d-none d-lg-table-cell"><small class="text-muted">{{ $node->updated_at->diffForHumans() }}</small></td>
    <td class="text-end">
        @if(! $node->is_active)
            <span class="badge bg-secondary me-1">Retired</span>
        @endif
        @if($depth < \App\Services\Taxonomy\TicketCategoryTreeGuard::MAX_DEPTH)
            <a href="{{ route('ticket-categories.create', ['parent' => $node->id]) }}"
               class="btn btn-link btn-sm p-0 text-muted" title="Add child category">
                <i class="bi bi-plus-circle"></i>
            </a>
        @endif
    </td>
</tr>
{{-- Depth <= 3 invariant: bottom-tier nodes have no children, so don't lazy-query for them. --}}
@if($depth < \App\Services\Taxonomy\TicketCategoryTreeGuard::MAX_DEPTH)
    @foreach($node->children as $child)
        @include('ticket-categories._tree_row', ['node' => $child, 'depth' => $depth + 1])
    @endforeach
@endif
