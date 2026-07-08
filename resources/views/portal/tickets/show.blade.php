@extends('portal.layouts.app')

@section('title', '#' . $ticket->id . ' ' . $ticket->subject . ' - ' . App\Support\PortalConfig::companyName() . ' Portal')

@php
    $priorityLabel = match($ticket->priority) {
        App\Enums\TicketPriority::P1 => 'Critical',
        App\Enums\TicketPriority::P2 => 'High',
        App\Enums\TicketPriority::P3 => 'Normal',
        App\Enums\TicketPriority::P4 => 'Low',
        default => '—',
    };
@endphp

@section('content')
<div class="mb-3">
    <a href="{{ route('portal.tickets.index') }}" class="text-muted small"><i class="bi bi-arrow-left me-1"></i>Back to Tickets</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-3">
            <div>
                <h5 class="mb-1">{{ $ticket->subject }}</h5>
                <div class="text-muted small">
                    Ticket #{{ $ticket->id }}
                    &middot; Opened {{ ($ticket->opened_at ?? $ticket->created_at)->toAppTz()->format('M j, Y g:i A') }}
                    @if($ticket->assignee)
                        &middot; Assigned to {{ $ticket->assignee->first_name ?? $ticket->assignee->name }}
                    @endif
                </div>
            </div>
            <div class="text-end">
                <span class="badge {{ $ticket->status->badgeClass() }} me-1">{{ $ticket->status->label() }}</span>
                <span class="badge {{ $ticket->priority?->badgeClass() ?? 'bg-secondary' }}">{{ $priorityLabel }}</span>
            </div>
        </div>

        @if($ticket->contract)
            <div class="text-muted small mb-2">
                <i class="bi bi-file-earmark-text me-1"></i>Service Agreement: {{ $ticket->contract->name }}
            </div>
        @endif

        {{-- Resolved ticket actions --}}
        @if($ticket->status === App\Enums\TicketStatus::Resolved)
            <div class="alert alert-info d-flex align-items-center gap-2 mt-3 mb-0">
                <i class="bi bi-check-circle me-1"></i>
                <span class="flex-grow-1">This ticket has been marked as resolved. Is the issue fixed?</span>
                <form method="POST" action="{{ route('portal.tickets.confirm-resolved', $ticket) }}" class="d-inline">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-success">Yes, Confirm Resolved</button>
                </form>
                <button type="button" class="btn btn-sm btn-outline-warning" data-bs-toggle="collapse" data-bs-target="#reopenForm">Still an Issue</button>
            </div>
            <div class="collapse mt-2" id="reopenForm">
                <form method="POST" action="{{ route('portal.tickets.reopen', $ticket) }}">
                    @csrf
                    <textarea name="body" rows="3" class="form-control mb-2" placeholder="Please describe what's still wrong..." required></textarea>
                    <button type="submit" class="btn btn-sm btn-warning">Reopen Ticket</button>
                </form>
            </div>
        @endif
    </div>
</div>

{{-- Note timeline --}}
<div class="card mb-4">
    <div class="card-header">
        <h6 class="mb-0">Conversation</h6>
    </div>
    <div class="card-body">
        @if($notes->isEmpty() && $ticket->description)
            <div class="portal-note note-client mb-3">
                <div class="fw-semibold small mb-1 d-flex align-items-center gap-1">
                    <x-avatar :avatarUrl="$ticket->contact?->avatar_url" :name="$ticket->contact?->fullName ?? 'You'" :size="24" />
                    {{ $ticket->contact?->full_name ?? 'You' }}
                    <span class="text-muted fw-normal">&middot; {{ ($ticket->opened_at ?? $ticket->created_at)->toAppTz()->format('M j, Y g:i A') }}</span>
                </div>
                <div class="note-body">{!! $ticket->rendered_description !!}</div>
                @if($ticket->attachments->where('is_inline', false)->isNotEmpty())
                    <div class="mt-2">
                        @foreach($ticket->attachments->where('is_inline', false) as $att)
                            <a href="{{ route('portal.attachments.show', [$att->id, $att->filename]) }}" class="badge bg-light text-dark border me-1" target="_blank">
                                <i class="bi bi-paperclip me-1"></i>{{ $att->original_filename }}
                                <span class="text-muted">({{ number_format($att->size_bytes / 1024, 0) }} KB)</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        @php
            // Merge notes and public call summaries into a single chronological timeline
            $portalTimeline = $notes->map(fn ($n) => ['type' => 'note', 'item' => $n, 'at' => $n->noted_at])
                ->concat(($publicCalls ?? collect())->map(fn ($c) => ['type' => 'call', 'item' => $c, 'at' => $c->started_at]))
                ->sortBy('at')
                ->values();
        @endphp

        @foreach($portalTimeline as $entry)
            @if($entry['type'] === 'call')
                @php $call = $entry['item']; @endphp
                <div class="portal-note note-staff">
                    <div class="fw-semibold small mb-1 d-flex align-items-center gap-1">
                        <span class="rounded-circle d-inline-flex align-items-center justify-content-center bg-primary text-white" style="width: 24px; height: 24px; font-size: 0.7rem;">
                            <i class="bi bi-telephone"></i>
                        </span>
                        {{ $call->answeredBy?->name ?? 'Support' }}
                        <span class="text-muted fw-normal">&middot; {{ $call->started_at?->toAppTz()->format('M j, Y g:i A') ?? '' }}</span>
                        <span class="badge bg-light text-dark ms-1" style="font-size: 0.7rem;">Phone Call</span>
                    </div>
                    <div>{!! App\Helpers\MarkdownRenderer::render($call->call_summary) !!}</div>
                </div>
            @else
                @php
                    $note = $entry['item'];
                    $isClient = $note->who_type === App\Enums\WhoType::EndUser;
                @endphp
                <div class="portal-note {{ $isClient ? 'note-client' : 'note-staff' }}">
                    <div class="fw-semibold small mb-1 d-flex align-items-center gap-1">
                        @if($isClient)
                            <x-avatar :avatarUrl="$ticket->contact?->avatar_url" :name="$note->display_author" :size="24" />
                        @else
                            <x-avatar :user="$note->author" :name="$note->display_author" :size="24" />
                        @endif
                        {{ $note->display_author }}
                        @if(! $isClient && $note->ai_authored)
                            <span class="badge bg-info text-dark ms-1"><i class="bi bi-robot me-1"></i>AI-authored</span>
                        @endif
                        <span class="text-muted fw-normal">&middot; {{ $note->noted_at?->toAppTz()->format('M j, Y g:i A') ?? '' }}</span>
                    </div>
                    @if($note->email)
                        <div class="small text-muted mb-1" style="font-size: 0.8rem;">
                            @if($note->email->from_address)
                                <span><strong>From:</strong> {{ $note->email->from_name ?? $note->email->from_address }}</span>
                            @endif
                            @if($note->email->to_recipients)
                                <span class="ms-2"><strong>To:</strong> {{ collect($note->email->to_recipients)->map(fn($r) => $r['name'] ?? $r['address'])->join(', ') }}</span>
                            @endif
                            @if($note->email->cc_recipients)
                                <span class="ms-2"><strong>Cc:</strong> {{ collect($note->email->cc_recipients)->map(fn($r) => $r['name'] ?? $r['address'])->join(', ') }}</span>
                            @endif
                        </div>
                    @endif
                    <div>{!! $note->rendered_body !!}</div>
                    @if($note->attachments->where('is_inline', false)->isNotEmpty())
                        <div class="mt-2">
                            @foreach($note->attachments->where('is_inline', false) as $att)
                                <a href="{{ route('portal.attachments.show', [$att->id, $att->filename]) }}" class="badge bg-light text-dark border me-1" target="_blank">
                                    <i class="bi bi-paperclip me-1"></i>{{ $att->original_filename }}
                                    <span class="text-muted">({{ number_format($att->size_bytes / 1024, 0) }} KB)</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        @endforeach
    </div>
</div>

{{-- Reply form (only for open tickets) --}}
@if($ticket->status->isOpen())
    <div class="card">
        <div class="card-header">
            <h6 class="mb-0">Add a Reply</h6>
        </div>
        <div class="card-body">
            <form method="POST" action="{{ route('portal.tickets.reply', $ticket) }}">
                @csrf
                <textarea name="body" rows="4" class="form-control @error('body') is-invalid @enderror" placeholder="Type your reply..." required>{{ old('body') }}</textarea>
                @error('body')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
                <div class="mt-2">
                    <button type="submit" class="btn btn-primary">Send Reply</button>
                </div>
            </form>
        </div>
    </div>
@endif
@endsection
