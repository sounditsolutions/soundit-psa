@forelse($stream as $item)
    @include('dashboard._activity-item-' . $item->type, ['item' => $item])
@empty
    <div class="text-center py-5 text-muted">
        <i class="bi bi-activity" style="font-size: 2.5rem;"></i>
        <p class="mt-2 mb-0">No recent activity.</p>
    </div>
@endforelse
