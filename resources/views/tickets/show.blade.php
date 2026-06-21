@extends('layouts.app')

@section('title', $ticket->display_id . ' ' . $ticket->subject . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('tickets.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Tickets
        </a>
    </div>
</div>

{{-- Prepay balance warnings --}}
@if($ticket->client)
    @php $prepayContracts = $ticket->client->contracts->where('has_prepay', true); @endphp
    @foreach($prepayContracts as $pc)
        @if((float) $pc->prepay_balance <= 0)
            <div class="alert alert-danger py-2 mb-3">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>Prepay depleted:</strong> {{ $pc->name }} &mdash; {{ $pc->prepay_balance_formatted }}
            </div>
        @elseif((float) $pc->prepay_balance < ($pc->prepay_as_amount ? 500 : 4))
            <div class="alert alert-warning py-2 mb-3">
                <i class="bi bi-exclamation-circle me-1"></i>
                <strong>Low prepay:</strong> {{ $pc->name }} &mdash; {{ $pc->prepay_balance_formatted }} remaining
            </div>
        @endif
    @endforeach
@endif

<div class="row g-4">
    {{-- Left column: Subject, Description, Notes --}}
    <div class="col-md-8 order-2 order-md-1">
        <h4 class="section-title mb-1">{{ $ticket->display_id }}</h4>
        <h5 class="mb-3">{{ $ticket->subject }}</h5>

        @if($ticket->rendered_description)
            <div class="card card-static shadow-sm mb-3">
                <div class="card-body">
                    <div class="note-body">{!! $ticket->rendered_description !!}</div>
                </div>
                @if($ticket->attachments->where('is_inline', false)->isNotEmpty())
                    <div class="card-footer bg-transparent pt-0">
                        @foreach($ticket->attachments->where('is_inline', false) as $att)
                            <a href="{{ $att->url }}" class="badge bg-light text-dark border me-1" target="_blank">
                                <i class="bi bi-paperclip me-1"></i>{{ $att->original_filename }}
                                <span class="text-muted">({{ number_format($att->size_bytes / 1024, 0) }} KB)</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        @if($ticket->resolution)
            <div class="card card-static shadow-sm mb-3 border-start border-3 {{ $ticket->status->isOpen() ? 'border-secondary' : 'border-success' }}">
                <div class="card-body">
                    <label class="form-label small text-muted mb-1">{{ $ticket->status->isOpen() ? 'Prior resolution (ticket reopened)' : 'Resolution' }}
                        @if($ticket->resolution_ai_drafted)
                            <span class="badge bg-info ms-2">AI-drafted · review</span>
                        @endif
                    </label>
                    <div class="note-body">{!! App\Helpers\MarkdownRenderer::render($ticket->resolution) !!}</div>
                </div>
            </div>
        @endif

        {{-- Notes --}}
        <div class="card shadow-sm mt-4" id="notes">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-chat-left-text me-2"></i>Notes</span>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="toggleSystemNotes" onclick="toggleSystemNotes()">
                    <i class="bi bi-gear me-1"></i>Show system notes
                </button>
            </div>
            <div class="card-body">
                {{-- Action buttons --}}
                @include('tickets._action-buttons')

                {{-- Action panels (one visible at a time) --}}
                @include('tickets._action-note')
                @include('tickets._action-reply')
                @include('tickets._action-status')

                {{-- Activity timeline (notes + phone calls, newest first) --}}
                @forelse($timeline as $item)
                    @if($item instanceof App\Models\AssistantConversation)
                        @include('tickets._timeline-ai-chat', ['conversation' => $item])
                    @elseif($item instanceof App\Models\PhoneCall)
                        {{-- Phone call entry --}}
                        <div class="d-flex gap-3 py-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                            <div class="flex-shrink-0">
                                <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                     style="width: 36px; height: 36px; background: #0d6efd; font-size: 0.9rem;">
                                    <i class="bi bi-telephone"></i>
                                </div>
                            </div>
                            <div class="flex-grow-1">
                                <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                    <strong class="small">{{ $item->answeredBy?->name ?? $item->person?->fullName ?? $item->client?->name ?? 'Unknown' }}</strong>
                                    <span class="badge bg-light text-dark small">
                                        <i class="bi bi-telephone{{ $item->direction->value === 'inbound' ? '-inbound' : '-outbound' }} me-1"></i>{{ ucfirst($item->direction->value) }} Call
                                    </span>
                                    <span class="badge {{ $item->status->badgeClass() }} small">{{ $item->status->label() }}</span>
                                    @if($item->duration !== null)
                                        <span class="badge bg-light text-dark small">
                                            <i class="bi bi-clock me-1"></i>{{ gmdate($item->duration >= 3600 ? 'H:i:s' : 'i:s', $item->duration) }}
                                            @if($item->is_billable)
                                                <i class="bi bi-currency-dollar text-success ms-1" title="Billable"></i>
                                            @endif
                                        </span>
                                    @endif
                                    <span class="text-muted small ms-auto" title="{{ $item->started_at?->toAppTz()->format('Y-m-d H:i T') }}">
                                        {{ $item->started_at?->diffForHumans() }}
                                    </span>
                                    <div class="btn-group btn-group-sm ms-2">
                                        <button type="button" class="btn btn-link btn-sm text-muted p-0 px-1"
                                                data-bs-toggle="modal" data-bs-target="#editCallModal{{ $item->id }}"
                                                title="Edit call">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <a href="{{ route('calls.show', $item) }}" class="btn btn-link btn-sm text-muted p-0 px-1"
                                           title="View call detail">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                    </div>
                                </div>
                                <div class="small">
                                    {{ \App\Support\PhoneNumber::format($item->from_number) }} &rarr; {{ $item->to_number ?? 'Unknown' }}
                                    @if($item->person)
                                        &mdash; {{ $item->person->fullName }}
                                    @endif
                                </div>
                                @if($item->notes)
                                    <div class="small text-muted mt-1">{{ $item->notes }}</div>
                                @endif
                                @if($item->call_summary)
                                    <div class="small mt-1">{!! App\Helpers\MarkdownRenderer::render($item->call_summary) !!}</div>
                                @endif
                            </div>
                        </div>
                    @else
                        @php
                            $note = $item;
                            $isAiTriage = $note->note_type === App\Enums\NoteType::AiTriage;
                            $isSystem = $note->note_type->isSystemGenerated() || $note->who_type === App\Enums\WhoType::System;
                            $isResolution = $note->note_type === App\Enums\NoteType::Resolution;
                            $isDeleted = $note->trashed();
                        @endphp

                        @if($isDeleted)
                            {{-- Deleted note placeholder --}}
                            <div class="d-flex gap-3 py-2 {{ !$loop->last ? 'border-bottom' : '' }}" style="opacity: 0.5;">
                                <div class="flex-shrink-0">
                                    <div class="rounded-circle d-flex align-items-center justify-content-center bg-light text-muted"
                                         style="width: 36px; height: 36px;">
                                        <i class="bi bi-trash"></i>
                                    </div>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1">
                                        <span class="small text-muted">
                                            <i class="bi bi-trash me-1"></i>Note deleted
                                            @if($note->editor)
                                                by {{ $note->editor->name }}
                                            @endif
                                            @if($note->edited_at)
                                                {{ $note->edited_at->diffForHumans() }}
                                            @endif
                                        </span>
                                        <a href="#" class="small text-muted text-decoration-none ms-auto"
                                           data-bs-toggle="collapse" data-bs-target="#deletedNote{{ $note->id }}">
                                            <i class="bi bi-eye me-1"></i>Show
                                        </a>
                                    </div>
                                    <div class="collapse" id="deletedNote{{ $note->id }}">
                                        <div class="small text-muted mt-1 p-2 bg-light rounded">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <strong>{{ $note->display_author }}</strong>
                                                <span class="badge bg-light text-dark small">
                                                    <i class="bi {{ $note->note_type->icon() }} me-1"></i>{{ $note->note_type->label() }}
                                                </span>
                                                @if($note->formatted_time)
                                                    <span class="badge bg-light text-dark small">
                                                        <i class="bi bi-clock me-1"></i>{{ $note->formatted_time }}
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="note-body">{!! $note->rendered_body !!}</div>
                                            @if($note->attachments->where('is_inline', false)->isNotEmpty())
                                                <div class="mt-2">
                                                    @foreach($note->attachments->where('is_inline', false) as $att)
                                                        <a href="{{ $att->url }}" class="badge bg-light text-dark border me-1" target="_blank">
                                                            <i class="bi bi-paperclip me-1"></i>{{ $att->original_filename }}
                                                            <span class="text-muted">({{ number_format($att->size_bytes / 1024, 0) }} KB)</span>
                                                        </a>
                                                    @endforeach
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @elseif($isAiTriage)
                            {{-- AI Triage note: collapsed by default with summary line --}}
                            <div class="d-flex gap-3 py-3 {{ !$loop->last ? 'border-bottom' : '' }}">
                                <div class="flex-shrink-0">
                                    @if($note->author)
                                        <x-avatar :user="$note->author" :size="36" />
                                    @else
                                        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
                                             style="width: 36px; height: 36px; background: #6f42c1; font-size: 0.9rem;">
                                            <i class="bi bi-robot"></i>
                                        </div>
                                    @endif
                                </div>
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                        <strong class="small">{{ $note->display_author }}</strong>
                                        <span class="badge" style="background: #6f42c1; color: white; font-size: 0.7rem;">
                                            <i class="bi bi-robot me-1"></i>AI Analysis
                                        </span>
                                        @if($note->is_private)
                                            <span class="badge bg-warning text-dark small"><i class="bi bi-lock-fill me-1"></i>Private</span>
                                        @endif
                                        <span class="text-muted small ms-auto" title="{{ $note->noted_at?->toAppTz()->format('Y-m-d H:i T') }}">
                                            {{ $note->noted_at?->diffForHumans() }}
                                        </span>
                                    </div>
                                    @php
                                        $noteBody = strip_tags($note->body ?? '');
                                        $summaryLine = Str::limit($noteBody, 120);
                                    @endphp
                                    <div class="small text-muted">{{ $summaryLine }}</div>
                                    @if(strlen($noteBody) > 120)
                                        <a class="small text-decoration-none" data-bs-toggle="collapse" href="#aiNote{{ $note->id }}">
                                            <i class="bi bi-chevron-down me-1"></i>Show full analysis
                                        </a>
                                        <div class="collapse" id="aiNote{{ $note->id }}">
                                            <div class="small mt-2 p-2 bg-light rounded note-body">{!! $note->rendered_body !!}</div>
                                        </div>
                                    @endif
                                    @if($note->attachments->where('is_inline', false)->isNotEmpty())
                                        <div class="mt-2">
                                            @foreach($note->attachments->where('is_inline', false) as $att)
                                                <a href="{{ $att->url }}" class="badge bg-light text-dark border me-1" target="_blank">
                                                    <i class="bi bi-paperclip me-1"></i>{{ $att->original_filename }}
                                                    <span class="text-muted">({{ number_format($att->size_bytes / 1024, 0) }} KB)</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @elseif($note->note_type === App\Enums\NoteType::StatusChange || ($isSystem && !$note->body))
                            {{-- Status change / empty system: compact message --}}
                            <div class="d-flex align-items-center text-muted small py-2 system-note {{ !$loop->last ? 'border-bottom' : '' }}" style="display: none;">
                                <i class="bi {{ $note->note_type->icon() }} me-2"></i>
                                <span>
                                    {{ $note->display_author }} &mdash;
                                    @if($note->status_from && $note->status_to)
                                        <span class="badge {{ $note->status_from->badgeClass() }} badge-sm">{{ $note->status_from->label() }}</span>
                                        <i class="bi bi-arrow-right mx-1"></i>
                                        <span class="badge {{ $note->status_to->badgeClass() }} badge-sm">{{ $note->status_to->label() }}</span>
                                    @endif
                                    @if($note->body && !str_starts_with($note->body, 'Status changed'))
                                        &mdash; {{ Str::limit(strip_tags($note->body), 100) }}
                                    @endif
                                </span>
                                <span class="ms-auto" title="{{ $note->noted_at?->toAppTz()->format('Y-m-d H:i T') }}">
                                    {{ $note->noted_at?->diffForHumans() }}
                                </span>
                            </div>
                        @else
                            {{-- Regular note/reply/resolution/phone/escalation --}}
                            <div class="d-flex gap-3 py-3 {{ $isSystem ? 'system-note' : '' }} {{ !$loop->last ? 'border-bottom' : '' }} {{ $isResolution ? 'border-start border-3 border-success ps-3' : '' }}"
                                 @if($isSystem) style="display: none;" @endif>
                                <div class="flex-shrink-0">
                                    @if($note->author)
                                        <x-avatar :user="$note->author" :size="36" />
                                    @elseif($note->who_type === App\Enums\WhoType::EndUser && $ticket->contact)
                                        <x-avatar :avatarUrl="$ticket->contact->avatar_url" :name="$note->display_author" :size="36" />
                                    @else
                                        <x-avatar :name="$note->display_author" :size="36" />
                                    @endif
                                </div>
                                <div class="flex-grow-1" style="{{ $isSystem ? 'opacity: 0.6;' : '' }}">
                                    <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
                                        <strong class="small">{{ $note->display_author }}</strong>
                                        <span class="badge bg-light text-dark small">
                                            <i class="bi {{ $note->note_type->icon() }} me-1"></i>{{ $note->note_type->label() }}
                                        </span>
                                        @if($note->is_private)
                                            <span class="badge bg-warning text-dark small"><i class="bi bi-lock-fill me-1"></i>Private</span>
                                        @endif
                                        @if($note->formatted_time)
                                            <span class="badge bg-light text-dark small">
                                                <i class="bi bi-clock me-1"></i>{{ $note->formatted_time }}
                                                @if($note->is_billable)
                                                    <i class="bi bi-currency-dollar text-success ms-1" title="Billable"></i>
                                                @endif
                                            </span>
                                        @endif
                                        @if($note->contract_id && $note->contract_id !== $ticket->contract_id)
                                            <span class="badge bg-info text-dark small" title="Billed to {{ $note->contract?->name }}">
                                                <i class="bi bi-file-earmark-text me-1"></i>{{ $note->contract?->name }}
                                            </span>
                                        @endif
                                        <span class="text-muted small ms-auto" title="{{ $note->noted_at?->toAppTz()->format('Y-m-d H:i T') }}">
                                            {{ $note->noted_at?->diffForHumans() }}
                                            @if($note->edited_at)
                                                <span class="text-muted" title="Edited {{ $note->edited_at->toAppTz()->format('Y-m-d H:i T') }} by {{ $note->editor?->name ?? 'unknown' }}">(edited)</span>
                                            @endif
                                        </span>
                                        @if(!$note->note_type->isSystemGenerated())
                                            <div class="btn-group btn-group-sm ms-2">
                                                <button type="button" class="btn btn-link btn-sm text-muted p-0 px-1"
                                                        data-bs-toggle="modal" data-bs-target="#editNoteModal{{ $note->id }}"
                                                        title="Edit note" aria-label="Edit note">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <button type="button" class="btn btn-link btn-sm text-muted p-0 px-1"
                                                        onclick="if(confirm('Delete this note? It will be hidden from clients and its time will stop counting.')) document.getElementById('deleteNote{{ $note->id }}').submit();"
                                                        title="Delete note" aria-label="Delete note">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                                <form id="deleteNote{{ $note->id }}" method="POST"
                                                      action="{{ route('tickets.notes.destroy', [$ticket, $note]) }}" style="display:none;">
                                                    @csrf @method('DELETE')
                                                </form>
                                            </div>
                                        @endif
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
                                    <div class="small note-body">{!! $note->rendered_body !!}</div>
                                    @if($note->attachments->where('is_inline', false)->isNotEmpty())
                                        <div class="mt-2">
                                            @foreach($note->attachments->where('is_inline', false) as $att)
                                                <a href="{{ $att->url }}" class="badge bg-light text-dark border me-1" target="_blank">
                                                    <i class="bi bi-paperclip me-1"></i>{{ $att->original_filename }}
                                                    <span class="text-muted">({{ number_format($att->size_bytes / 1024, 0) }} KB)</span>
                                                </a>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endif
                @empty
                    <p class="text-muted small mb-0">No activity yet.</p>
                @endforelse
            </div>
        </div>

        {{-- Edit note modals --}}
        @foreach($timeline as $item)
            @if($item instanceof App\Models\TicketNote && !$item->trashed() && !$item->note_type->isSystemGenerated())
                <div class="modal fade" id="editNoteModal{{ $item->id }}" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <form method="POST" action="{{ route('tickets.notes.update', [$ticket, $item]) }}">
                            @csrf @method('PUT')
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h6 class="modal-title">Edit Note</h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label small">Content</label>
                                        <x-markdown-editor name="body" :id="'editBody'.$item->id" :value="$item->body"
                                            rows="6" :required="true" :lazy="true"
                                            :upload-url="route('tickets.attachments.store', $ticket)"
                                            :note-id="$item->id" />
                                    </div>
                                    <div class="row g-3">
                                        <div class="col-auto">
                                            <label class="form-label small">Type</label>
                                            <select name="note_type" class="form-select form-select-sm">
                                                <option value="note" {{ $item->note_type === App\Enums\NoteType::Note ? 'selected' : '' }}>Note</option>
                                                <option value="reply" {{ $item->note_type === App\Enums\NoteType::Reply ? 'selected' : '' }}>Reply</option>
                                                <option value="phone_call" {{ $item->note_type === App\Enums\NoteType::PhoneCall ? 'selected' : '' }}>Phone Call</option>
                                                <option value="resolution" {{ $item->note_type === App\Enums\NoteType::Resolution ? 'selected' : '' }}>Resolution</option>
                                            </select>
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small">Date/time</label>
                                            <input type="datetime-local" name="noted_at" class="form-control form-control-sm"
                                                   value="{{ $item->noted_at?->toAppTz()->format('Y-m-d\TH:i') }}"
                                                   max="{{ now()->toAppTz()->format('Y-m-d\TH:i') }}" style="width: 200px;"
                                                   title="Adjust when this note is dated — controls its position in the timeline">
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small">Time</label>
                                            <input type="text" name="time" class="form-control form-control-sm"
                                                   value="{{ $item->formatted_time }}" placeholder="0h 15m" style="width: 100px;">
                                        </div>
                                        <div class="col-auto d-flex align-items-end gap-3">
                                            <div class="form-check">
                                                <input type="hidden" name="is_private" value="0">
                                                <input type="checkbox" name="is_private" value="1" class="form-check-input"
                                                       id="editPrivate{{ $item->id }}" {{ $item->is_private ? 'checked' : '' }}>
                                                <label class="form-check-label small" for="editPrivate{{ $item->id }}">Private</label>
                                            </div>
                                            <div class="form-check">
                                                <input type="hidden" name="is_billable" value="0">
                                                <input type="checkbox" name="is_billable" value="1" class="form-check-input"
                                                       id="editBillable{{ $item->id }}" {{ $item->is_billable ? 'checked' : '' }}>
                                                <label class="form-check-label small" for="editBillable{{ $item->id }}">Billable</label>
                                            </div>
                                        </div>
                                        <div class="col-auto">
                                            <label class="form-label small">Contract</label>
                                            <select name="contract_id" class="form-select form-select-sm">
                                                <option value="">Ticket default</option>
                                                @foreach($ticket->client?->contracts ?? [] as $ct)
                                                    <option value="{{ $ct->id }}" {{ $item->contract_id == $ct->id ? 'selected' : '' }}>
                                                        {{ $ct->name }}
                                                    </option>
                                                @endforeach
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endforeach

        {{-- Edit phone call modals --}}
        @foreach($timeline as $item)
            @if($item instanceof App\Models\PhoneCall)
                <div class="modal fade" id="editCallModal{{ $item->id }}" tabindex="-1">
                    <div class="modal-dialog">
                        <form method="POST" action="{{ route('calls.update-notes', $item) }}">
                            @csrf @method('PATCH')
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h6 class="modal-title">Edit Phone Call</h6>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    @if($item->call_summary)
                                        <div class="mb-3">
                                            <label class="form-label small text-muted">AI Summary</label>
                                            <div class="small p-2 bg-light rounded" style="max-height: 150px; overflow-y: auto;">
                                                {!! App\Helpers\MarkdownRenderer::render($item->call_summary) !!}
                                            </div>
                                        </div>
                                    @endif
                                    <div class="mb-3">
                                        <label class="form-label small">Notes</label>
                                        <textarea name="notes" class="form-control form-control-sm" rows="4">{{ $item->notes }}</textarea>
                                    </div>
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="form-check">
                                            <input type="hidden" name="is_billable" value="0">
                                            <input type="checkbox" name="is_billable" value="1" class="form-check-input"
                                                   id="editCallBillable{{ $item->id }}" {{ $item->is_billable ? 'checked' : '' }}>
                                            <label class="form-check-label small" for="editCallBillable{{ $item->id }}">Billable</label>
                                        </div>
                                        @if($item->call_summary)
                                        <div class="form-check">
                                            <input type="hidden" name="summary_is_public" value="0">
                                            <input type="checkbox" name="summary_is_public" value="1" class="form-check-input"
                                                   id="editCallPublic{{ $item->id }}" {{ $item->summary_is_public ? 'checked' : '' }}>
                                            <label class="form-check-label small" for="editCallPublic{{ $item->id }}">Share summary with client</label>
                                        </div>
                                        @endif
                                        @if($item->duration !== null)
                                            <span class="small text-muted">
                                                <i class="bi bi-clock me-1"></i>{{ gmdate($item->duration >= 3600 ? 'H:i:s' : 'i:s', $item->duration) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                    <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        @endforeach
    </div>

    {{-- Right column: Status, Details, Triage --}}
    <div class="col-md-4 order-1 order-md-2 detail-sidebar">
        {{-- Status & Actions --}}
        <div class="card card-static shadow-sm mb-3">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    {{-- Status badge (changes via the note form status dropdown) --}}
                    <span class="badge {{ $ticket->status->badgeClass() }}">{{ $ticket->status->label() }}</span>
                    {{-- Priority --}}
                    <div class="dropdown">
                        <button class="btn btn-sm {{ $ticket->priority->badgeClass() }} dropdown-toggle" data-bs-toggle="dropdown">
                            {{ $ticket->priority->label() }}
                        </button>
                        <div class="dropdown-menu">
                            @foreach($priorities as $p)
                                @if($p !== $ticket->priority)
                                    <form method="POST" action="{{ route('tickets.update', $ticket) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="priority" value="{{ $p->value }}">
                                        <button type="submit" class="dropdown-item">
                                            <span class="badge {{ $p->badgeClass() }} me-1">{{ $p->label() }}</span>
                                        </button>
                                    </form>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </div>
                {{-- Assignee --}}
                <form method="POST" action="{{ route('tickets.update', $ticket) }}" class="mb-2">
                    @csrf
                    @method('PATCH')
                    <div class="input-group input-group-sm">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <select name="assignee_id" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="">Unassigned</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}" {{ $ticket->assignee_id == $u->id ? 'selected' : '' }}>
                                    {{ $u->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </form>
                {{-- Quick actions --}}
                <div class="d-flex gap-2">
                    @if($ticket->status->isOpen())
                        <button type="button" class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#resolveModal">
                            <i class="bi bi-check-lg me-1"></i>Resolve
                        </button>
                    @endif
                    @if($ticket->status !== App\Enums\TicketStatus::Closed)
                        <form method="POST" action="{{ route('tickets.update-status', $ticket) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="closed">
                            <button type="submit" class="btn btn-secondary btn-sm">
                                <i class="bi bi-x-circle me-1"></i>Close
                            </button>
                        </form>
                    @endif
                    @if(! $ticket->status->isOpen())
                        <form method="POST" action="{{ route('tickets.update-status', $ticket) }}">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="status" value="in_progress">
                            <button type="submit" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-arrow-counterclockwise me-1"></i>Reopen
                            </button>
                        </form>
                    @endif
                    @php
                        $tacticalAssets = $ticket->assets->filter(fn ($a) => $a->tacticalAsset && $a->tacticalAsset->status === 'online');
                    @endphp
                    @if($tacticalAssets->isNotEmpty())
                        <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#tacticalScriptModal">
                            <i class="bi bi-terminal me-1"></i>Run Script
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#tacticalCmdModal">
                            <i class="bi bi-terminal-fill me-1"></i>Run Command
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- Contractor Balance --}}
        @if($ticket->assignee && $ticket->assignee->is_contractor)
            @php
                $contractorBalance = app(\App\Services\ContractorTimeService::class)->getBalance($ticket->assignee);
            @endphp
            <div class="card card-static shadow-sm mb-3 {{ $contractorBalance < 0 ? 'border-danger' : ($contractorBalance < 4 ? 'border-warning' : '') }}">
                <div class="card-body py-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="small text-muted">
                            <i class="bi bi-person-gear me-1"></i>{{ $ticket->assignee->name }}
                        </span>
                        <span class="fw-bold {{ $contractorBalance < 0 ? 'text-danger' : ($contractorBalance < 4 ? 'text-warning' : 'text-success') }}">
                            {{ number_format($contractorBalance, 1) }}h remaining
                            @if($contractorBalance < 0)
                                <i class="bi bi-exclamation-triangle-fill small"></i>
                            @endif
                        </span>
                    </div>
                    <a href="{{ route('contractors.time-pool', $ticket->assignee) }}" class="small">View time pool</a>
                </div>
            </div>
        @endif

        {{-- Ticket Info (editable fields) --}}
        <div class="card card-static shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-pencil me-2"></i>Ticket Info</div>
            <div class="card-body">
                <form method="POST" action="{{ route('tickets.update', $ticket) }}">
                    @csrf
                    @method('PATCH')
                    <div class="mb-2">
                        <label class="form-label small text-muted mb-1">Subject</label>
                        <input type="text" name="subject" class="form-control form-control-sm" value="{{ $ticket->subject }}">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small text-muted mb-1">Category</label>
                            <select name="category" class="form-select form-select-sm" id="editCategory">
                                <option value="">-- None --</option>
                                @foreach(array_keys($categories) as $cat)
                                    <option value="{{ $cat }}" {{ $ticket->category === $cat ? 'selected' : '' }}>
                                        {{ $cat }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label small text-muted mb-1">Subcategory</label>
                            <select name="subcategory" class="form-select form-select-sm" id="editSubcategory">
                                <option value="">-- None --</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    </div>
                </form>
                <table class="table table-borderless table-sm mb-0 small">
                    <tr>
                        <th class="text-muted" style="width: 80px;">Type</th>
                        <td><i class="bi {{ $ticket->type->icon() }} me-1"></i>{{ $ticket->type->label() }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted">Source</th>
                        <td><span class="badge bg-light text-dark">{{ $ticket->source->label() }}</span></td>
                    </tr>
                </table>
            </div>
        </div>

        {{-- Details --}}
        <div class="card card-static shadow-sm">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>Details</div>
            <div class="card-body">
                <table class="table table-borderless table-sm mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted">Client</th>
                            <td>
                                <x-client-badge :client="$ticket->client" :size="24" fallback="-" />
                                <button type="button" class="btn btn-link btn-sm p-0 ms-1" id="moveTicketBtn" title="Move to another client">
                                    <i class="bi bi-arrow-right-circle" style="font-size: 0.8rem;"></i>
                                </button>
                                {{-- Move panel --}}
                                <div id="movePanel" class="d-none mt-2 p-2 border rounded bg-light" style="font-size: 0.85rem;">
                                    <div class="text-warning small mb-2">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Moving will clear the current contact, contract, and linked assets.
                                    </div>
                                    <form method="POST" action="{{ route('tickets.move', $ticket) }}" id="moveForm">
                                        @csrf
                                        @method('PATCH')
                                        <div class="mb-2">
                                            <input type="text" class="form-control form-control-sm" id="moveClientSearch"
                                                   placeholder="Search clients..." autocomplete="off">
                                            <input type="hidden" name="client_id" id="moveClientId" value="">
                                            <div id="moveClientResults" class="list-group mt-1" style="max-height: 150px; overflow-y: auto;"></div>
                                        </div>
                                        <div class="mb-2 d-none" id="moveContactGroup">
                                            <label class="form-label small mb-1">Contact (optional)</label>
                                            <select name="contact_id" class="form-select form-select-sm" id="moveContactSelect">
                                                <option value="">None</option>
                                            </select>
                                        </div>
                                        <div class="d-flex gap-2">
                                            <button type="submit" class="btn btn-primary btn-sm" id="moveSubmitBtn" disabled>Move</button>
                                            <button type="button" class="btn btn-outline-secondary btn-sm" id="moveCancelBtn">Cancel</button>
                                        </div>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Contact</th>
                            <td>
                                @if($ticket->client)
                                    <form method="POST" action="{{ route('tickets.move', $ticket) }}" class="d-inline">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="client_id" value="{{ $ticket->client_id }}">
                                        <select name="contact_id" class="form-select form-select-sm" onchange="this.form.submit()" style="max-width: 200px;">
                                            <option value="">None</option>
                                            @foreach($clientContacts as $c)
                                                <option value="{{ $c->id }}" {{ $ticket->contact_id == $c->id ? 'selected' : '' }}>
                                                    {{ $c->full_name }}
                                                </option>
                                            @endforeach
                                        </select>
                                    </form>
                                @else
                                    <x-person-badge :person="$ticket->contact" :size="24" fallback="-" />
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted align-top">Assets</th>
                            <td>
                                @if($ticket->assets->isNotEmpty())
                                    @foreach($ticket->assets as $a)
                                        <div class="d-flex align-items-center gap-1 mb-1">
                                            <x-asset-badge :asset="$a" class="small" />
                                            @if($a->pivot->is_primary)
                                                <span class="badge bg-primary" style="font-size: 0.65rem;">primary</span>
                                            @endif
                                            <form method="POST" action="{{ route('tickets.unlinkAsset', [$ticket, $a]) }}" class="d-inline ms-auto">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-link btn-sm text-danger p-0" title="Unlink"
                                                        onclick="return confirm('Unlink this asset from the ticket?')">
                                                    <i class="bi bi-x-lg" style="font-size: 0.7rem;"></i>
                                                </button>
                                            </form>
                                        </div>
                                    @endforeach
                                @else
                                    <span class="text-muted small">None</span>
                                @endif
                                @if($clientAssets->isNotEmpty())
                                    <form method="POST" action="{{ route('tickets.linkAsset', $ticket) }}" class="mt-1">
                                        @csrf
                                        <div class="input-group input-group-sm">
                                            <select name="asset_id" class="form-select form-select-sm" required>
                                                <option value="">+ Link asset...</option>
                                                @foreach($clientAssets as $ca)
                                                    <option value="{{ $ca->id }}">{{ $ca->name }}{{ $ca->asset_type ? " ({$ca->asset_type})" : '' }}</option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="btn btn-outline-primary btn-sm">
                                                <i class="bi bi-link-45deg"></i>
                                            </button>
                                        </div>
                                    </form>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Contract</th>
                            <td>
                                <form method="POST" action="{{ route('tickets.update', $ticket) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <select name="contract_id" class="form-select form-select-sm" onchange="this.form.submit()" style="max-width: 200px;">
                                        <option value="">None</option>
                                        @foreach($ticket->client?->contracts ?? [] as $ct)
                                            <option value="{{ $ct->id }}" {{ $ticket->contract_id == $ct->id ? 'selected' : '' }}>
                                                {{ $ct->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </form>
                                @if(!$ticket->contract && $ticket->assets->isNotEmpty())
                                    @php $assetContracts = $ticket->assets->flatMap->contracts->unique('id'); @endphp
                                    @if($assetContracts->isNotEmpty())
                                        <div class="mt-1 small text-muted">
                                            Coverage: @foreach($assetContracts as $ac)<x-contract-badge :contract="$ac" />@endforeach
                                        </div>
                                    @endif
                                @endif
                            </td>
                        </tr>
                        @php
                            $prepayContracts = collect();
                            if ($ticket->contract && $ticket->contract->has_prepay) {
                                $prepayContracts = collect([$ticket->contract]);
                            } elseif ($ticket->client) {
                                $prepayContracts = ($ticket->client->contracts ?? collect())->where('has_prepay', true)->where('status', \App\Enums\ContractStatus::Active);
                            }
                        @endphp
                        @foreach($prepayContracts as $pc)
                        <tr>
                            <th class="text-muted">Prepay</th>
                            <td>
                                @php
                                    $bal = (float) $pc->prepay_balance;
                                    $lowThreshold = $pc->prepay_as_amount ? 500 : 4;
                                    $balClass = $bal <= 0 ? 'text-danger fw-semibold' : ($bal < $lowThreshold ? 'text-warning fw-semibold' : 'text-success');
                                @endphp
                                <span class="{{ $balClass }}">{{ $pc->prepay_balance_formatted }}</span>
                                @if($prepayContracts->count() > 1)
                                    <span class="text-muted small">({{ Str::limit($pc->name, 20) }})</span>
                                @endif
                                <a href="{{ route('contracts.show', $pc) }}" class="ms-1" title="View contract">
                                    <i class="bi bi-box-arrow-up-right" style="font-size: 0.7rem;"></i>
                                </a>
                            </td>
                        </tr>
                        @endforeach
                        @if($ticket->sla_name)
                        <tr>
                            <th class="text-muted">SLA</th>
                            <td>{{ $ticket->sla_name }}</td>
                        </tr>
                        @endif
                        @if($ticket->reported_by)
                        <tr>
                            <th class="text-muted">Reported By</th>
                            <td class="small">{{ $ticket->reported_by }}</td>
                        </tr>
                        @endif
                        @if($ticket->response_due_at)
                        <tr>
                            <th class="text-muted">Response Due</th>
                            <td>
                                @if($ticket->responded_at)
                                    <small class="text-success">
                                        <i class="bi bi-check-circle me-1"></i>Responded {{ $ticket->responded_at->toAppTz()->format('M j, g:i A') }}
                                    </small>
                                @elseif($ticket->isResponseOverdue())
                                    <small class="text-danger fw-bold">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>{{ $ticket->response_due_at->diffForHumans() }}
                                    </small>
                                @else
                                    <small>{{ $ticket->response_due_at->toAppTz()->format('M j, g:i A') }} ({{ $ticket->response_due_at->diffForHumans() }})</small>
                                @endif
                            </td>
                        </tr>
                        @endif
                        <tr>
                            <th class="text-muted">Due</th>
                            <td>
                                <form method="POST" action="{{ route('tickets.update', $ticket) }}" class="d-inline">
                                    @csrf
                                    @method('PATCH')
                                    <div class="input-group input-group-sm">
                                        <input type="datetime-local" name="due_at" class="form-control form-control-sm"
                                               value="{{ $ticket->due_at?->toAppTz()->format('Y-m-d\TH:i') }}"
                                               onchange="this.form.submit()">
                                    </div>
                                </form>
                                @if($ticket->isOverdue())
                                    <small class="text-danger fw-bold">
                                        <i class="bi bi-exclamation-triangle-fill me-1"></i>SLA Breach
                                    </small>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Total Time</th>
                            <td>{{ $ticket->formatted_total_time ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Created By</th>
                            <td>{{ $ticket->createdBy?->name ?? '-' }}</td>
                        </tr>
                        @if($ticket->halo_id)
                        <tr>
                            <th class="text-muted">Halo ID</th>
                            <td>{{ $ticket->halo_id }}</td>
                        </tr>
                        @endif
                        @if($ticket->parentTicket)
                        <tr>
                            <th class="text-muted">Parent</th>
                            <td>
                                <x-ticket-badge :ticket="$ticket->parentTicket" />
                            </td>
                        </tr>
                        @endif
                        @if($ticket->childTickets->isNotEmpty())
                        <tr>
                            <th class="text-muted align-top">Merged</th>
                            <td>
                                @foreach($ticket->childTickets as $child)
                                    <div class="small mb-1">
                                        <x-ticket-badge :ticket="$child" />
                                    </div>
                                @endforeach
                            </td>
                        </tr>
                        @endif
                        <tr>
                            <th class="text-muted">Merge</th>
                            <td>
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="mergeTicketBtn">
                                    <i class="bi bi-box-arrow-in-down-left me-1"></i>Merge into this
                                </button>
                                <div id="mergePanel" class="d-none mt-2 p-2 border rounded bg-light" style="font-size: 0.85rem;">
                                    <div class="text-info small mb-2">
                                        <i class="bi bi-info-circle me-1"></i>Search for a ticket to merge INTO this one. All notes, calls, emails, and assets will be moved here.
                                    </div>
                                    <div class="mb-2">
                                        <input type="text" class="form-control form-control-sm" id="mergeTicketSearch"
                                               placeholder="Search tickets..." autocomplete="off">
                                        <div id="mergeTicketResults" class="list-group mt-1" style="max-height: 200px; overflow-y: auto;"></div>
                                    </div>
                                    <div id="mergeSelectedTicket" class="d-none mb-2 p-2 border rounded bg-white">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong id="mergeSelectedId"></strong>
                                                <span id="mergeSelectedSubject" class="small text-muted ms-1"></span>
                                            </div>
                                            <button type="button" class="btn btn-link btn-sm text-danger p-0" id="mergeClearSelection">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="button" class="btn btn-warning btn-sm" id="mergeSubmitBtn" disabled
                                                data-bs-toggle="modal" data-bs-target="#mergeConfirmModal">
                                            <i class="bi bi-box-arrow-in-down-left me-1"></i>Merge
                                        </button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" id="mergeCancelBtn">Cancel</button>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @if($ticket->total_pending_minutes > 0 || $ticket->pending_since)
                        <tr>
                            <th class="text-muted">Pending Time</th>
                            <td>
                                @php
                                    $pendingTotal = $ticket->total_pending_minutes;
                                    if ($ticket->pending_since) {
                                        $pendingTotal += $ticket->pending_since->diffInMinutes(now());
                                    }
                                    $ph = intdiv($pendingTotal, 60);
                                    $pm = $pendingTotal % 60;
                                @endphp
                                {{ $ph > 0 ? "{$ph}h " : '' }}{{ $pm }}m
                                @if($ticket->pending_since)
                                    <span class="badge bg-info text-dark">Active</span>
                                @endif
                            </td>
                        </tr>
                        @endif
                    </tbody>
                </table>
                {{-- Timestamps --}}
                <div class="border-top mt-2 pt-2">
                    <div class="row small text-muted">
                        <div class="col-6 mb-1">
                            <strong>Opened:</strong><br>
                            {{ $ticket->opened_at?->toAppTz()->format('M j H:i') }}
                        </div>
                        <div class="col-6 mb-1">
                            <strong>Responded:</strong><br>
                            {{ $ticket->responded_at?->toAppTz()->format('M j H:i') ?? '-' }}
                        </div>
                        <div class="col-6">
                            <strong>Resolved:</strong><br>
                            {{ $ticket->resolved_at?->toAppTz()->format('M j H:i') ?? '-' }}
                        </div>
                        <div class="col-6">
                            <strong>Closed:</strong><br>
                            {{ $ticket->closed_at?->toAppTz()->format('M j H:i') ?? '-' }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        @include('tickets._triage_card')
        @include('tickets._site_notes_card')
    </div>
</div>

{{-- Merge confirmation modal --}}
<div class="modal fade" id="mergeConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Confirm Merge</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Merge <strong id="mergeModalId"></strong> into <strong>{{ $ticket->display_id }}</strong>?</p>
                <div class="alert alert-warning small py-2 mb-2">
                    <i class="bi bi-exclamation-triangle me-1"></i>This cannot be undone. The following will be moved to this ticket:
                </div>
                <ul class="small mb-0" id="mergeModalCounts"></ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="{{ route('tickets.merge', $ticket) }}" id="mergeForm">
                    @csrf
                    <input type="hidden" name="secondary_ticket_id" id="mergeSecondaryId" value="">
                    <button type="submit" class="btn btn-warning btn-sm">
                        <i class="bi bi-box-arrow-in-down-left me-1"></i>Confirm Merge
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Tactical RMM script runner modal --}}
@if(isset($tacticalAssets) && $tacticalAssets->isNotEmpty())
<div class="modal fade" id="tacticalScriptModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-terminal me-1"></i>Run Script</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small fw-bold">Device</label>
                    <select class="form-select form-select-sm" id="ticketTacticalAsset">
                        @foreach($tacticalAssets as $ta)
                            <option value="{{ $ta->id }}">{{ $ta->hostname ?? $ta->name }} ({{ $ta->tacticalAsset->status }})</option>
                        @endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Script</label>
                    <select class="form-select form-select-sm" id="ticketTacticalScript">
                        <option value="">Select a script...</option>
                        @php
                            $tacticalScripts = \App\Models\TacticalScript::where('hidden', false)->orderBy('category')->orderBy('name')->get();
                            $groupedScripts = $tacticalScripts->groupBy('category');
                        @endphp
                        @foreach($groupedScripts as $category => $categoryScripts)
                            <optgroup label="{{ $category ?: 'Uncategorized' }}">
                                @foreach($categoryScripts as $script)
                                    <option value="{{ $script->id }}" data-timeout="{{ $script->default_timeout }}">{{ $script->name }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col">
                        <input type="text" class="form-control form-control-sm" id="ticketTacticalArgs" placeholder="Arguments (optional)">
                    </div>
                    <div class="col-auto">
                        <select class="form-select form-select-sm" id="ticketTacticalTimeout" style="width: 100px;">
                            <option value="30">30s</option>
                            <option value="60">60s</option>
                            <option value="120" selected>120s</option>
                            <option value="300">5m</option>
                            <option value="600">10m</option>
                        </select>
                    </div>
                </div>
                <div id="ticketTacticalResult" style="display:none;">
                    <div class="border rounded p-2 bg-dark text-light small font-monospace" style="max-height: 200px; overflow-y: auto; white-space: pre-wrap;" id="ticketTacticalOutput"></div>
                    <div class="mt-1 small text-muted" id="ticketTacticalMeta"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary btn-sm" id="ticketTacticalRunBtn" onclick="runTicketScript()">
                    <i class="bi bi-play-fill me-1"></i>Run
                </button>
            </div>
        </div>
    </div>
</div>

{{-- Run-command modal (DESTRUCTIVE — arbitrary RCE → confirm-gated; cmd ONLY per
     amendment G1). Mirrors the asset-page cmd modal: shell select + command field
     (+ a <datalist> of common diagnostics) + the FULL resolved command in a
     multi-line <pre> (A3, nothing scrolled out of view) + a typed-hostname confirm.
     The device is picked from the ticket's online tactical assets; the typed
     hostname must match the SELECTED device. The shown command is intentionally
     NOT secret-redacted (the tech sees their own input on their own screen); the
     AUDIT row + the ticket note ARE redacted server-side (A3/B3). Usable at ~375px
     (E4). --}}
<div class="modal fade" id="tacticalCmdModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-terminal-fill me-2"></i>Run command</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2">
                    <i class="bi bi-exclamation-octagon me-1"></i><strong>This runs a command directly on the device</strong>
                    with full agent privileges. Review it carefully — there is no undo. The command and its output are saved (redacted) as a ticket note.
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-bold">Device</label>
                    <select class="form-select form-select-sm" id="ticketCmdAsset">
                        @foreach($tacticalAssets as $ta)
                            @php $taHost = $ta->tacticalAsset->hostname ?? $ta->hostname ?? $ta->name; @endphp
                            <option value="{{ $ta->id }}"
                                    data-hostname="{{ $taHost }}"
                                    data-shell="{{ stripos((string) ($ta->tacticalAsset->os ?? $ta->os ?? ''), 'win') !== false ? 'cmd' : 'shell' }}">
                                {{ $taHost }} ({{ $ta->tacticalAsset->status }})
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-auto">
                        <select class="form-select form-select-sm" id="ticketCmdShell" style="width: 130px;" aria-label="Shell">
                            <option value="cmd">cmd</option>
                            <option value="powershell">powershell</option>
                            <option value="shell">shell</option>
                        </select>
                    </div>
                    <div class="col">
                        <input type="text" class="form-control form-control-sm" id="ticketCmdInput"
                               list="ticketCmdSuggestions" autocomplete="off"
                               placeholder="Command to run (e.g. whoami)">
                        <datalist id="ticketCmdSuggestions">
                            <option value="whoami"></option>
                            <option value="hostname"></option>
                            <option value="ipconfig /all"></option>
                            <option value="ip addr"></option>
                            <option value="systeminfo"></option>
                            <option value="uname -a"></option>
                            <option value="Get-ComputerInfo"></option>
                            <option value="gpupdate /force"></option>
                            <option value="nltest /dsgetdc:"></option>
                        </datalist>
                    </div>
                </div>
                <p class="mb-1 small text-muted">This exact command will run:</p>
                <pre class="border rounded bg-body-tertiary p-2 mb-3" style="white-space: pre-wrap; word-break: break-word; max-height: 30vh; overflow-y: auto;" id="ticketCmdPreview"></pre>
                <p class="mb-2 small">To confirm, type the device hostname exactly:</p>
                <p class="mb-2"><code class="user-select-all" id="ticketCmdExpectedHost"></code></p>
                <input type="text" class="form-control form-control-sm" id="ticketCmdHostname"
                       autocomplete="off" placeholder="Type the hostname to confirm">
                <div class="text-danger small mt-1" id="ticketCmdError" style="display:none;"></div>
                <div id="ticketCmdResult" class="mt-2" style="display:none;">
                    <div class="border rounded p-2 bg-dark text-light small font-monospace" style="max-height: 200px; overflow-y: auto; white-space: pre-wrap;" id="ticketCmdOutput"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm" id="ticketCmdConfirm" disabled
                        data-cmd-url="{{ route('tickets.run-tactical-command', $ticket) }}">
                    <i class="bi bi-play-fill me-1"></i>Run command
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Resolve modal — captures a resolution so the wiki can mine it --}}
@if($ticket->status->isOpen())
<div class="modal fade" id="resolveModal" tabindex="-1" aria-labelledby="resolveModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('tickets.update-status', $ticket) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="status" value="resolved">
                <div class="modal-header">
                    <h5 class="modal-title" id="resolveModalLabel">Resolve ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <label for="resolveResolution" class="form-label mb-0">Resolution summary <span class="text-muted">(recommended)</span></label>
                        @if(\App\Support\AiConfig::isConfigured())
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="draftResolutionBtn"
                                data-url="{{ route('tickets.draft-resolution', $ticket) }}"
                                title="Draft with AI">
                            <i class="bi bi-robot me-1"></i>Draft with AI
                        </button>
                        @endif
                    </div>
                    <textarea name="resolution" id="resolveResolution" class="form-control" rows="3"
                              placeholder="How was this resolved?"></textarea>
                    <div class="form-text">A short summary is recorded on the ticket and feeds the client wiki — it helps future tickets.@if(\App\Support\AiConfig::isConfigured()) Leave it blank and we'll draft one from the ticket's notes.@endif</div>
                    <div class="mt-1 small text-danger d-none" id="draftResolutionError"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Resolve</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts')
<script src="{{ asset('js/ticket-actions.js') }}?v={{ filemtime(public_path('js/ticket-actions.js')) }}"></script>
<script src="{{ asset('js/ticket-ai-chat.js') }}?v={{ filemtime(public_path('js/ticket-ai-chat.js')) }}"></script>
<script>document.body.dataset.clientId = '{{ $ticket->client_id }}';</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // AI Draft Reply
    const draftReplyBtn = document.getElementById('draftReplyBtn');
    if (draftReplyBtn) {
        function triggerDraft(btn, instructions) {
            const bodyTextarea = document.getElementById('replyBody');
            const editor = bodyTextarea ? bodyTextarea.easyMDE : null;

            // Overwrite protection
            const currentContent = editor ? editor.value() : (bodyTextarea ? bodyTextarea.value : '');
            if (currentContent.trim() && !confirm('Replace current text with AI draft?')) {
                return;
            }

            const originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Drafting...';

            fetch('{{ route("tickets.draft-reply", $ticket) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ instructions: instructions || null }),
            })
            .then(function(r) {
                if (!r.ok) return r.json().then(function(d) { throw new Error(d.error || 'Request failed'); });
                return r.json();
            })
            .then(function(data) {
                if (editor) {
                    editor.value(data.draft);
                } else if (bodyTextarea) {
                    bodyTextarea.value = data.draft;
                }
                // Populate recipients
                const toField = document.getElementById('replyToEmail');
                if (toField && data.to) toField.value = data.to;
                const ccField = document.getElementById('replyCcEmails');
                if (ccField && data.cc && data.cc.length) ccField.value = data.cc.join(', ');
                // Pre-select suggested status on reply panel
                const replyStatusSel = document.getElementById('replyStatusSelect');
                if (replyStatusSel && data.status) {
                    const opt = replyStatusSel.querySelector('option[value="' + data.status + '"]');
                    if (opt) {
                        replyStatusSel.value = data.status;
                        replyStatusSel.dispatchEvent(new Event('change'));
                    }
                }
                // Clear instructions after successful draft
                const instructionsInput = document.getElementById('draftInstructions');
                if (instructionsInput) instructionsInput.value = '';
                // Close the dropdown
                const dropdown = bootstrap.Dropdown.getInstance(draftReplyBtn.nextElementSibling);
                if (dropdown) dropdown.hide();
                // Scroll notes into view
                document.getElementById('notes').scrollIntoView({ behavior: 'smooth', block: 'start' });
            })
            .catch(function(err) {
                alert('Draft failed: ' + err.message);
            })
            .finally(function() {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
        }

        // Main button — draft without instructions
        draftReplyBtn.addEventListener('click', function() {
            triggerDraft(this, '');
        });

        // Dropdown button — draft with instructions
        const draftWithBtn = document.getElementById('draftWithInstructionsBtn');
        if (draftWithBtn) {
            draftWithBtn.addEventListener('click', function() {
                const input = document.getElementById('draftInstructions');
                triggerDraft(draftReplyBtn, input ? input.value.trim() : '');
            });
        }

        // Keep dropdown open when clicking inside the input
        const dropdownMenu = document.getElementById('draftInstructionsDropdown');
        if (dropdownMenu) {
            dropdownMenu.addEventListener('click', function(e) { e.stopPropagation(); });
        }
    }

    // AI Draft Resolution
    const draftResolutionBtn = document.getElementById('draftResolutionBtn');
    if (draftResolutionBtn) {
        draftResolutionBtn.addEventListener('click', function() {
            const textarea = document.getElementById('resolveResolution');
            const errorEl = document.getElementById('draftResolutionError');

            // Overwrite protection
            if (textarea && textarea.value.trim() && !confirm('Replace current text with AI draft?')) {
                return;
            }

            const originalHtml = draftResolutionBtn.innerHTML;
            draftResolutionBtn.disabled = true;
            draftResolutionBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Drafting...';
            if (errorEl) errorEl.classList.add('d-none');

            fetch(draftResolutionBtn.dataset.url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({}),
            })
            .then(function(r) {
                if (!r.ok) return r.json().then(function(d) { throw new Error(d.error || 'Request failed'); });
                return r.json();
            })
            .then(function(data) {
                if (textarea) {
                    textarea.value = data.resolution;
                    textarea.focus();
                }
            })
            .catch(function(err) {
                if (errorEl) {
                    errorEl.textContent = err.message;
                    errorEl.classList.remove('d-none');
                } else {
                    alert('Draft failed: ' + err.message);
                }
            })
            .finally(function() {
                draftResolutionBtn.disabled = false;
                draftResolutionBtn.innerHTML = originalHtml;
            });
        });
    }

    // Move ticket panel
    const moveBtn = document.getElementById('moveTicketBtn');
    const movePanel = document.getElementById('movePanel');
    const moveCancelBtn = document.getElementById('moveCancelBtn');
    const moveClientSearch = document.getElementById('moveClientSearch');
    const moveClientId = document.getElementById('moveClientId');
    const moveClientResults = document.getElementById('moveClientResults');
    const moveContactGroup = document.getElementById('moveContactGroup');
    const moveContactSelect = document.getElementById('moveContactSelect');
    const moveSubmitBtn = document.getElementById('moveSubmitBtn');

    if (moveBtn) {
        moveBtn.addEventListener('click', function() {
            movePanel.classList.toggle('d-none');
            if (!movePanel.classList.contains('d-none')) {
                moveClientSearch.focus();
            }
        });
    }

    if (moveCancelBtn) {
        moveCancelBtn.addEventListener('click', function() {
            movePanel.classList.add('d-none');
            moveClientSearch.value = '';
            moveClientId.value = '';
            moveClientResults.innerHTML = '';
            moveContactGroup.classList.add('d-none');
            moveSubmitBtn.disabled = true;
        });
    }

    let moveSearchTimeout;
    if (moveClientSearch) {
        moveClientSearch.addEventListener('input', function() {
            clearTimeout(moveSearchTimeout);
            const q = this.value.trim();
            if (q.length < 2) {
                moveClientResults.innerHTML = '';
                return;
            }
            moveSearchTimeout = setTimeout(function() {
                fetch('{{ route("api.clients.search") }}?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(function(clients) {
                        moveClientResults.innerHTML = '';
                        clients.forEach(function(c) {
                            const a = document.createElement('a');
                            a.href = '#';
                            a.className = 'list-group-item list-group-item-action small py-1';
                            a.textContent = c.name;
                            a.addEventListener('click', function(e) {
                                e.preventDefault();
                                moveClientSearch.value = c.name;
                                moveClientId.value = c.id;
                                moveClientResults.innerHTML = '';
                                moveSubmitBtn.disabled = false;
                                // Fetch contacts for selected client
                                fetch('/api/clients/' + c.id + '/contacts')
                                    .then(r => r.json())
                                    .then(function(contacts) {
                                        moveContactSelect.innerHTML = '<option value="">None</option>';
                                        contacts.forEach(function(ct) {
                                            const opt = document.createElement('option');
                                            opt.value = ct.id;
                                            opt.textContent = ct.name;
                                            moveContactSelect.appendChild(opt);
                                        });
                                        moveContactGroup.classList.toggle('d-none', contacts.length === 0);
                                    });
                            });
                            moveClientResults.appendChild(a);
                        });
                    });
            }, 300);
        });
    }

    // Merge ticket panel
    const mergeBtn = document.getElementById('mergeTicketBtn');
    const mergePanel = document.getElementById('mergePanel');
    const mergeCancelBtn = document.getElementById('mergeCancelBtn');
    const mergeSearch = document.getElementById('mergeTicketSearch');
    const mergeResults = document.getElementById('mergeTicketResults');
    const mergeSelectedDiv = document.getElementById('mergeSelectedTicket');
    const mergeSelectedId = document.getElementById('mergeSelectedId');
    const mergeSelectedSubject = document.getElementById('mergeSelectedSubject');
    const mergeClearSelection = document.getElementById('mergeClearSelection');
    const mergeSubmitBtn = document.getElementById('mergeSubmitBtn');
    const mergeSecondaryId = document.getElementById('mergeSecondaryId');
    const mergeModalId = document.getElementById('mergeModalId');
    const mergeModalCounts = document.getElementById('mergeModalCounts');

    function truncateStr(str, len) {
        return str.length > len ? str.substring(0, len) + '...' : str;
    }

    function resetMergePanel() {
        if (mergeSearch) mergeSearch.value = '';
        if (mergeResults) mergeResults.innerHTML = '';
        if (mergeSelectedDiv) mergeSelectedDiv.classList.add('d-none');
        if (mergeSubmitBtn) mergeSubmitBtn.disabled = true;
        if (mergeSecondaryId) mergeSecondaryId.value = '';
    }

    if (mergeBtn) {
        mergeBtn.addEventListener('click', function() {
            mergePanel.classList.toggle('d-none');
            if (!mergePanel.classList.contains('d-none')) {
                mergeSearch.focus();
            }
        });
    }

    if (mergeCancelBtn) {
        mergeCancelBtn.addEventListener('click', function() {
            mergePanel.classList.add('d-none');
            resetMergePanel();
        });
    }

    if (mergeClearSelection) {
        mergeClearSelection.addEventListener('click', function() {
            mergeSelectedDiv.classList.add('d-none');
            mergeSubmitBtn.disabled = true;
            mergeSecondaryId.value = '';
            mergeSearch.value = '';
        });
    }

    let mergeSearchTimeout;
    if (mergeSearch) {
        mergeSearch.addEventListener('input', function() {
            clearTimeout(mergeSearchTimeout);
            const q = this.value.trim();
            if (q.length < 2) {
                mergeResults.innerHTML = '';
                return;
            }
            mergeSearchTimeout = setTimeout(function() {
                const params = new URLSearchParams({q: q, exclude: {{ $ticket->id }}});
                @if($ticket->client_id)
                    params.set('client_id', '{{ $ticket->client_id }}');
                @endif
                fetch('{{ route("api.tickets.search") }}?' + params.toString())
                    .then(r => r.json())
                    .then(function(tickets) {
                        mergeResults.innerHTML = '';
                        if (tickets.length === 0) {
                            mergeResults.innerHTML = '<div class="list-group-item small text-muted">No tickets found</div>';
                            return;
                        }
                        tickets.forEach(function(t) {
                            const a = document.createElement('a');
                            a.href = '#';
                            a.className = 'list-group-item list-group-item-action small py-1 d-flex justify-content-between align-items-center';
                            a.innerHTML = '<span><strong>' + t.display_id + '</strong> ' +
                                truncateStr(t.subject, 35) + '</span>' +
                                '<span class="badge ' + t.priority_class + '" style="font-size: 0.65rem;">' + t.priority + '</span>';
                            a.addEventListener('click', function(e) {
                                e.preventDefault();
                                mergeResults.innerHTML = '';
                                mergeSearch.value = '';
                                mergeSelectedDiv.classList.remove('d-none');
                                mergeSelectedId.textContent = t.display_id;
                                mergeSelectedSubject.textContent = truncateStr(t.subject, 50);
                                mergeSubmitBtn.disabled = false;
                                mergeSecondaryId.value = t.id;
                                // Populate confirmation modal
                                mergeModalId.textContent = t.display_id;
                                mergeModalCounts.innerHTML = '';
                                const items = [];
                                if (t.notes_count > 0) items.push(t.notes_count + ' ' + (t.notes_count === 1 ? 'note' : 'notes'));
                                if (t.calls_count > 0) items.push(t.calls_count + ' ' + (t.calls_count === 1 ? 'call' : 'calls'));
                                if (t.emails_count > 0) items.push(t.emails_count + ' ' + (t.emails_count === 1 ? 'email' : 'emails'));
                                if (t.assets_count > 0) items.push(t.assets_count + ' ' + (t.assets_count === 1 ? 'asset' : 'assets'));
                                if (items.length === 0) items.push('No activity to move');
                                items.forEach(function(item) {
                                    const li = document.createElement('li');
                                    li.textContent = item;
                                    mergeModalCounts.appendChild(li);
                                });
                            });
                            mergeResults.appendChild(a);
                        });
                    });
            }, 300);
        });
    }

    // Category/Subcategory cascade on edit form
    const categories = @json($categories);
    const editCategory = document.getElementById('editCategory');
    const editSubcategory = document.getElementById('editSubcategory');
    const currentSub = @json($ticket->subcategory);

    function updateEditSubcategories() {
        const cat = editCategory.value;
        editSubcategory.innerHTML = '<option value="">-- None --</option>';
        if (cat && categories[cat]) {
            categories[cat].forEach(function(sub) {
                const opt = document.createElement('option');
                opt.value = sub;
                opt.textContent = sub;
                if (sub === currentSub) opt.selected = true;
                editSubcategory.appendChild(opt);
            });
        }
    }

    if (editCategory) {
        editCategory.addEventListener('change', updateEditSubcategories);
        updateEditSubcategories();
    }
});

// Toggle system notes visibility
function toggleSystemNotes() {
    const notes = document.querySelectorAll('.system-note');
    const btn = document.getElementById('toggleSystemNotes');
    const isHidden = notes.length > 0 && notes[0].style.display === 'none';

    notes.forEach(function(el) {
        el.style.display = isHidden ? '' : 'none';
    });

    btn.innerHTML = isHidden
        ? '<i class="bi bi-gear me-1"></i>Hide system notes'
        : '<i class="bi bi-gear me-1"></i>Show system notes';
}

// Tactical RMM: update timeout when script changes
var scriptSelect = document.getElementById('ticketTacticalScript');
if (scriptSelect) {
    scriptSelect.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        var dt = opt.getAttribute('data-timeout');
        if (dt) {
            var timeoutSel = document.getElementById('ticketTacticalTimeout');
            for (var i = 0; i < timeoutSel.options.length; i++) {
                if (parseInt(timeoutSel.options[i].value) >= parseInt(dt)) {
                    timeoutSel.selectedIndex = i;
                    break;
                }
            }
        }
    });
}

window.runTicketScript = function() {
    var btn = document.getElementById('ticketTacticalRunBtn');
    var scriptId = document.getElementById('ticketTacticalScript').value;
    var assetId = document.getElementById('ticketTacticalAsset').value;
    if (!scriptId || !assetId) { alert('Select a device and script.'); return; }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Running...';

    var resultDiv = document.getElementById('ticketTacticalResult');
    var outputDiv = document.getElementById('ticketTacticalOutput');
    var metaDiv = document.getElementById('ticketTacticalMeta');
    resultDiv.style.display = 'none';

    fetch('{{ route("tickets.run-tactical-script", $ticket) }}', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            'Accept': 'application/json',
        },
        body: JSON.stringify({
            asset_id: assetId,
            script_id: scriptId,
            args: document.getElementById('ticketTacticalArgs').value,
            timeout: document.getElementById('ticketTacticalTimeout').value,
        }),
    })
    .then(function(r) { return r.json().then(function(d) { d._status = r.status; return d; }); })
    .then(function(data) {
        resultDiv.style.display = '';
        if (data.error) {
            outputDiv.className = 'border rounded p-2 bg-danger bg-opacity-10 text-danger small font-monospace';
            outputDiv.style.cssText = 'max-height: 200px; overflow-y: auto; white-space: pre-wrap;';
            outputDiv.textContent = data.error;
            metaDiv.textContent = '';
        } else {
            var output = data.stdout || '(no output)';
            if (data.stderr) output += '\n\nSTDERR:\n' + data.stderr;
            var isError = data.retcode !== 0 && data.retcode !== null;
            outputDiv.className = 'border rounded p-2 small font-monospace ' + (isError ? 'bg-danger bg-opacity-10 text-danger' : 'bg-dark text-light');
            outputDiv.style.cssText = 'max-height: 200px; overflow-y: auto; white-space: pre-wrap;';
            outputDiv.textContent = output;
            metaDiv.innerHTML = 'Return code: ' + (data.retcode ?? 'unknown') + ' | <span class="text-success">Output saved as ticket note</span>';
        }
    })
    .catch(function(err) {
        resultDiv.style.display = '';
        outputDiv.className = 'border rounded p-2 bg-danger bg-opacity-10 text-danger small font-monospace';
        outputDiv.textContent = 'Request failed: ' + err.message;
        metaDiv.textContent = '';
    })
    .finally(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run';
    });
};

// Run-command modal (DESTRUCTIVE — arbitrary RCE; cmd ONLY per amendment G1).
// Mirrors the asset-page cmd modal but the device is chosen from the ticket's
// online tactical assets, so the expected hostname (and the smart shell default)
// follow the selected device. The command shown in the <pre> is the EXACT string
// sent; editing the shell/command/device after the modal opens re-renders the
// preview and resets the confirm with a clear "command changed — re-confirm"
// message (E5). The hostname gate + payloadHash-bound token are enforced
// server-side (A1); the audit row + ticket note are redacted server-side (B3).
(function() {
    var assetSel = document.getElementById('ticketCmdAsset');
    var shellSel = document.getElementById('ticketCmdShell');
    var cmdInput = document.getElementById('ticketCmdInput');
    var modalEl = document.getElementById('tacticalCmdModal');
    var preview = document.getElementById('ticketCmdPreview');
    var hostInput = document.getElementById('ticketCmdHostname');
    var expectedEl = document.getElementById('ticketCmdExpectedHost');
    var confirmBtn = document.getElementById('ticketCmdConfirm');
    var errEl = document.getElementById('ticketCmdError');
    var resultDiv = document.getElementById('ticketCmdResult');
    var outputDiv = document.getElementById('ticketCmdOutput');
    if (!assetSel || !cmdInput || !modalEl) return;

    // The command snapshot the preview/confirm is bound to (set on modal open).
    var confirmed = { assetId: null, shell: null, cmd: null };

    function selectedOption() {
        return assetSel.options[assetSel.selectedIndex];
    }
    function expectedHost() {
        var opt = selectedOption();
        return ((opt && opt.dataset.hostname) || '').trim().toLowerCase();
    }
    function resolvedText() {
        return '[' + shellSel.value + '] ' + cmdInput.value;
    }
    function hostMatches() {
        var exp = expectedHost();
        return exp !== '' && hostInput.value.trim().toLowerCase() === exp;
    }

    // Smart shell default by the selected device's OS (E5), still changeable.
    function applyDeviceDefault() {
        var opt = selectedOption();
        if (opt && opt.dataset.shell) shellSel.value = opt.dataset.shell;
        if (expectedEl) expectedEl.textContent = (opt && opt.dataset.hostname) || '';
    }
    assetSel.addEventListener('change', function() {
        applyDeviceDefault();
        onCommandEdited();
    });

    function snapshot() {
        confirmed.assetId = assetSel.value;
        confirmed.shell = shellSel.value;
        confirmed.cmd = cmdInput.value;
        preview.textContent = resolvedText();
    }

    // Snapshot the command + device when the modal opens and render the preview.
    modalEl.addEventListener('show.bs.modal', function() {
        applyDeviceDefault();
        snapshot();
        errEl.style.display = 'none';
        if (resultDiv) resultDiv.style.display = 'none';
        hostInput.value = '';
        confirmBtn.disabled = true;
    });

    // E5: if the device/shell/command changes while the modal is open, the shown
    // command no longer matches what was confirmed — re-render + force re-confirm.
    function onCommandEdited() {
        if (!modalEl.classList.contains('show')) return;
        if (assetSel.value === confirmed.assetId && shellSel.value === confirmed.shell && cmdInput.value === confirmed.cmd) return;
        snapshot();
        hostInput.value = '';
        confirmBtn.disabled = true;
        errEl.textContent = 'Command changed — re-confirm by typing the hostname again.';
        errEl.style.display = '';
    }
    shellSel.addEventListener('change', onCommandEdited);
    cmdInput.addEventListener('input', onCommandEdited);

    hostInput.addEventListener('input', function() {
        confirmBtn.disabled = !hostMatches();
        if (hostMatches()) errEl.style.display = 'none';
    });

    confirmBtn.addEventListener('click', function() {
        if (!hostMatches()) return;
        if (!confirmed.cmd || confirmed.cmd.trim() === '') {
            errEl.textContent = 'Enter a command to run.';
            errEl.style.display = '';
            return;
        }
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Running...';

        fetch(confirmBtn.dataset.cmdUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                asset_id: confirmed.assetId,
                hostname: hostInput.value,
                shell: confirmed.shell,
                cmd: confirmed.cmd,
                timeout: 60,
            }),
        })
        .then(function(r) { return r.json().then(function(d) { d._status = r.status; return d; }); })
        .then(function(data) {
            if (data.error) {
                var msg = data.error;
                if (/confirm|expired/i.test(msg)) msg = 'Confirmation expired — please re-confirm.';
                errEl.textContent = msg;
                errEl.style.display = '';
                return;
            }
            if (resultDiv && outputDiv) {
                outputDiv.textContent = data.message || '(command sent)';
                resultDiv.style.display = '';
            }
            hostInput.value = '';
            confirmBtn.disabled = true;
        })
        .catch(function(err) {
            errEl.textContent = 'Request failed: ' + err.message;
            errEl.style.display = '';
        })
        .finally(function() {
            confirmBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run command';
        });
    });
})();
</script>
@endpush

@push('styles')
<link rel="stylesheet" href="{{ asset('css/ticket-ai-chat.css') }}?v={{ filemtime(public_path('css/ticket-ai-chat.css')) }}">
<style>
.note-body img { max-width: 100%; height: auto; border-radius: 4px; margin: 4px 0; }
.note-body table { font-size: 0.85rem; }
.note-body pre { font-size: 0.8rem; background: #f8f9fa; padding: 8px; border-radius: 4px; overflow-x: auto; }
</style>
@endpush
