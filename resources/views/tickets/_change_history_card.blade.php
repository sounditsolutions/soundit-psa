{{-- Field-level change audit trail (psa-eif). A structured "field X changed
     from Y to Z" record, complementary to the human-readable timeline notes.
     $activities is supplied by TicketController::show(); fall back to the
     relation so the partial is safe to include standalone. --}}
@php
    $activities = $activities ?? $ticket->activities()->with('user:id,name')->limit(100)->get();
@endphp
@if($activities->isNotEmpty())
<div class="card card-static shadow-sm mt-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-clock-history me-2"></i>Change History</span>
        <span class="badge bg-light text-dark">{{ $activities->count() }}</span>
    </div>
    <div class="card-body p-0">
        <div style="max-height: 320px; overflow-y: auto;">
            <ul class="list-group list-group-flush small mb-0">
                @foreach($activities as $activity)
                    <li class="list-group-item py-2">
                        <div class="d-flex justify-content-between align-items-baseline">
                            <span class="fw-semibold">{{ $activity->fieldLabel() }}</span>
                            <span class="text-muted" style="font-size: 0.75rem;"
                                  title="{{ $activity->created_at->toAppTz()->format('M j, Y g:i A') }}">
                                {{ $activity->created_at->diffForHumans() }}
                            </span>
                        </div>
                        <div class="text-break">
                            <span class="text-muted text-decoration-line-through">{{ $activity->old_value ?? '—' }}</span>
                            <i class="bi bi-arrow-right mx-1 text-muted"></i>
                            <span class="fw-medium">{{ $activity->new_value ?? '—' }}</span>
                        </div>
                        <div class="text-muted" style="font-size: 0.75rem;">
                            <i class="bi bi-person me-1"></i>{{ $activity->user?->name ?? 'System' }}
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
</div>
@endif
