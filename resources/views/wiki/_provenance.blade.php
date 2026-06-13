{{-- §8.1 item 1: provenance on demand. item 3: right-sized actions. item 5: addendum blocks. --}}
@if ($facts->isNotEmpty())
<details class="card mt-3">
    {{-- UX review (WCAG AA): text-muted (#6b7280) on the navy .card-header is 2.76:1 — fails 4.5:1.
         Drop text-muted; the card-header's own color meets contrast. --}}
    <summary class="card-header small text-uppercase" style="cursor: pointer;">
        Show provenance ({{ $facts->count() }})
    </summary>
    <div class="card-body p-2">
        {{-- Architecture review: BOTH sides of a dispute have status=Disputed and a non-null
             disputed_with_fact_id, so keying the whole disputed set double-renders the AI-challenge
             block (once per side, roles inverted). Scope $challengers to the MINED (Ticket-sourced)
             side only — that is the genuine challenger. --}}
        @php($challengers = $facts
            ->filter(fn ($f) => $f->status === \App\Enums\WikiFactStatus::Disputed
                && $f->source_type === \App\Enums\WikiFactSource::Ticket
                && $f->disputed_with_fact_id !== null)
            ->keyBy('disputed_with_fact_id'))
        @php($factsById = $facts->keyBy('id'))
        @foreach ($facts as $fact)
            @if ($fact->status === \App\Enums\WikiFactStatus::Disputed && $challengers->has($fact->id))
                @php($challenger = $challengers->get($fact->id))
                {{-- item 5: flat tonal AI-challenge block — border, tint, radius, NO shadow/alert --}}
                <div class="p-2 mb-2" style="border: 1px solid #e5e7eb; background: #f8fafc; border-radius: 8px;">
                    <div class="small fw-semibold text-muted mb-1"><i class="bi bi-robot"></i> AI challenge</div>
                    <div class="small mb-1">Current: {{ $fact->statement }}</div>
                    <div class="small mb-2">Suggests: <strong>{{ $challenger->statement }}</strong>
                        <span class="text-muted">({{ collect($challenger->source_refs)->map(fn ($r) => ($r['type'] ?? '?').' #'.($r['id'] ?? '?'))->implode(', ') }})</span>
                    </div>
                    <form method="POST" action="{{ route('wiki.facts.resolve', $challenger) }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="resolution" value="accept">
                        <button class="btn btn-outline-secondary btn-sm">Accept</button>
                    </form>
                    <form method="POST" action="{{ route('wiki.facts.resolve', $challenger) }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="resolution" value="dismiss">
                        <button class="btn btn-outline-danger btn-sm">Dismiss</button>
                    </form>
                </div>
            @elseif ($fact->status === \App\Enums\WikiFactStatus::Disputed
                && $fact->source_type === \App\Enums\WikiFactSource::Ticket
                && $factsById->has($fact->disputed_with_fact_id))
                {{-- Live challenger side: already rendered INSIDE its incumbent's AI-challenge
                     block above (the incumbent IS in $facts). Skip it here — rendering it as a
                     standalone row would expose a Confirm action that sets only the challenger
                     Confirmed while the incumbent stays Disputed, corrupting the pair. --}}
            @else
                {{-- Non-disputed facts AND orphaned-disputed facts (status=Disputed but the
                     partner was independently retired, so it is absent from $facts) render as a
                     normal row. The orphaned case would otherwise fall through every branch and
                     silently vanish, stranding the fact with no way to resolve it. The "Disputed"
                     badge signals the stale state; the actions let staff clear it. --}}
                <div class="d-flex align-items-start justify-content-between gap-2 py-1 border-bottom">
                    <div class="small">
                        {{ $fact->statement }}
                        <span class="badge {{ $fact->status->badgeClass() }}">{{ $fact->status->label() }}</span>
                        <span class="text-muted">
                            {{ collect($fact->source_refs)->map(fn ($r) => ($r['type'] ?? '?').' #'.($r['id'] ?? '?'))->implode(', ') }}
                        </span>
                    </div>
                    <div class="text-nowrap">
                        @if ($fact->status === \App\Enums\WikiFactStatus::Unverified)
                            <form method="POST" action="{{ route('wiki.facts.confirm', $fact) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-outline-secondary btn-sm" title="Confirm">Confirm</button>
                            </form>
                        @endif
                        <details class="d-inline-block">
                            <summary class="btn btn-outline-secondary btn-sm">Correct</summary>
                            <form method="POST" action="{{ route('wiki.facts.correct', $fact) }}" class="mt-1">
                                @csrf
                                @method('PATCH')
                                <input name="statement" class="form-control form-control-sm mb-1" value="{{ $fact->statement }}" maxlength="300">
                                <button class="btn btn-outline-secondary btn-sm">Save</button>
                            </form>
                        </details>
                        <details class="d-inline-block">
                            <summary class="btn btn-outline-danger btn-sm">Retire</summary>
                            <span class="small">Retire?</span>
                            <form method="POST" action="{{ route('wiki.facts.retire', $fact) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-outline-danger btn-sm">Yes</button>
                            </form>
                        </details>
                    </div>
                </div>
            @endif
        @endforeach
    </div>
</details>
@endif
