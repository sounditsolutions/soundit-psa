@extends('layouts.app')

@section('title', $email->subject . '')

@section('content')
<div class="row mb-4">
    <div class="col">
        <a href="{{ route('emails.index') }}" class="text-decoration-none small">
            <i class="bi bi-arrow-left me-1"></i>Back to Emails
        </a>
        <h4 class="section-title mt-2">{{ $email->subject }}</h4>
    </div>
    <div class="col-auto">
        <span class="badge {{ $email->direction->badgeClass() }}">{{ $email->direction->label() }}</span>
        @if($email->importance === 'high')
            <span class="badge bg-danger">High Priority</span>
        @endif
    </div>
</div>

<div class="row g-4">
    {{-- Email Content --}}
    <div class="col-md-8">
        {{-- Header --}}
        <div class="card card-static shadow-sm">
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <th class="text-muted" style="width: 60px">From</th>
                        <td>
                            <strong>{{ $email->from_name ?? $email->from_address }}</strong>
                            @if($email->from_name)
                                <small class="text-muted">&lt;{{ $email->from_address }}&gt;</small>
                            @endif
                        </td>
                    </tr>
                    @if($email->to_recipients)
                    <tr>
                        <th class="text-muted">To</th>
                        <td>
                            @foreach($email->to_recipients as $recipient)
                                <span>{{ is_array($recipient) ? ($recipient['name'] ?? $recipient['address'] ?? '?') : $recipient }}</span>@if(!$loop->last), @endif
                            @endforeach
                        </td>
                    </tr>
                    @endif
                    @if($email->cc_recipients)
                    <tr>
                        <th class="text-muted">Cc</th>
                        <td>
                            @foreach($email->cc_recipients as $recipient)
                                <span>{{ is_array($recipient) ? ($recipient['name'] ?? $recipient['address'] ?? '?') : $recipient }}</span>@if(!$loop->last), @endif
                            @endforeach
                        </td>
                    </tr>
                    @endif
                    <tr>
                        <th class="text-muted">Date</th>
                        <td>{{ $email->received_at?->toAppTz()->format('d M Y H:i T') ?? '—' }}</td>
                    </tr>
                </table>
            </div>
        </div>

        {{-- Body --}}
        <div class="card card-static shadow-sm mt-3">
            @if($email->body_text || $email->getRawOriginal('body_html'))
            <div class="card-header p-0">
                <ul class="nav nav-tabs card-header-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="message-tab" data-bs-toggle="tab"
                                data-bs-target="#messagePane" type="button" role="tab">
                            <i class="bi bi-chat-text me-1"></i>Message
                        </button>
                    </li>
                    @if($email->getRawOriginal('body_html'))
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="original-tab" data-bs-toggle="tab"
                                data-bs-target="#originalPane" type="button" role="tab">
                            <i class="bi bi-code-slash me-1"></i>Original
                        </button>
                    </li>
                    @endif
                </ul>
            </div>
            @endif
            <div class="card-body p-0">
                <div class="tab-content">
                    {{-- Message tab (plain text) --}}
                    <div class="tab-pane fade show active p-3" id="messagePane" role="tabpanel">
                        @if($email->body_text)
                            <div style="white-space: pre-wrap; font-family: inherit; line-height: 1.6;">{{ $email->body_text }}</div>
                        @elseif($email->body_preview)
                            <p class="mb-0">{{ $email->body_preview }}</p>
                        @else
                            <div class="text-center text-muted">
                                <i class="bi bi-envelope-x fs-3 d-block mb-2"></i>
                                No email body available.
                            </div>
                        @endif
                    </div>

                    {{-- Original tab (HTML iframe) --}}
                    @if($email->getRawOriginal('body_html'))
                    <div class="tab-pane fade" id="originalPane" role="tabpanel">
                        <iframe sandbox="allow-popups"
                                srcdoc="{{ e($email->sanitizedBodyHtml()) }}"
                                style="width: 100%; min-height: 400px; border: none;"
                                onload="this.style.height = this.contentWindow.document.documentElement.scrollHeight + 'px'"></iframe>
                    </div>
                    @endif
                </div>
            </div>
        </div>

        @if($email->has_attachments)
        <div class="card card-static shadow-sm mt-3">
            <div class="card-body">
                <small class="text-muted">
                    <i class="bi bi-paperclip me-1"></i>This email has attachments (viewing coming soon)
                </small>
            </div>
        </div>
        @endif

        {{-- Reply Form --}}
        @if($email->direction->value === 'inbound')
        <div class="card card-static shadow-sm mt-3">
            <div class="card-header" role="button" data-bs-toggle="collapse" data-bs-target="#replyForm" aria-expanded="false">
                <i class="bi bi-reply me-2"></i>Reply
            </div>
            <div class="collapse" id="replyForm">
                <div class="card-body">
                    <small class="text-muted d-block mb-2">
                        Replying to: {{ $email->from_name ?? $email->from_address }} &mdash; {{ $email->subject }}
                    </small>
                    <form method="POST" action="{{ route('emails.reply', $email) }}">
                        @csrf
                        <div class="mb-3">
                            <label for="replyBody" class="form-label">Message</label>
                            <x-markdown-editor name="body" id="replyBody" rows="6" toolbar="email" :required="true" />
                        </div>
                        <div class="mb-3">
                            <label for="replyCc" class="form-label">CC <small class="text-muted">(comma-separated)</small></label>
                            <input type="text" name="cc" id="replyCc" class="form-control form-control-sm"
                                   value="{{ old('cc') }}" placeholder="user@example.com, other@example.com">
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-send me-1"></i>Send Reply
                        </button>
                    </form>
                </div>
            </div>
        </div>
        @endif
    </div>

    {{-- Sidebar --}}
    <div class="col-md-4">
        {{-- Client / Contact --}}
        @php
            $nameParts = $email->from_name ? preg_split('/\s+/', trim($email->from_name), 2) : [];
            if (count($nameParts) === 2 && str_ends_with($nameParts[0], ',')) {
                $nameParts = [trim($nameParts[1]), rtrim($nameParts[0], ',')];
            }
            $defaultFirst = $nameParts[0] ?? '';
            $defaultLast = $nameParts[1] ?? '';
        @endphp
        <div class="card card-static shadow-sm">
            <div class="card-header">
                <i class="bi bi-building me-2"></i>Client
            </div>
            <div class="card-body">
                @if($email->user)
                    {{-- Staff member --}}
                    <x-user-badge :user="$email->user" :size="24" />
                    <small class="text-muted d-block">{{ $email->user->email }}</small>

                @elseif($email->client && $email->person)
                    {{-- State 3: Fully resolved --}}
                    <div class="mb-1"><x-client-badge :client="$email->client" :size="24" /></div>
                    <x-person-badge :person="$email->person" :size="20" :link="false" />
                    @if($email->person->email)
                        <small class="text-muted d-block">{{ $email->person->email }}</small>
                    @endif
                    <div class="mt-2 d-flex gap-1">
                        <button class="btn btn-outline-secondary btn-sm" type="button" id="emailReassignBtn">
                            <i class="bi bi-arrow-right-circle me-1"></i>Change
                        </button>
                        <form method="POST" action="{{ route('emails.reassign-client', $email) }}" class="d-inline">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                    onclick="return confirm('Clear client assignment from this email?')">
                                <i class="bi bi-x-circle me-1"></i>Clear
                            </button>
                        </form>
                    </div>
                    @include('emails._reassign-panel')

                @elseif($email->client && !$email->person)
                    {{-- State 2: Client known, no person --}}
                    <div class="mb-1"><x-client-badge :client="$email->client" :size="24" /></div>
                    <small class="text-muted d-block mb-2">
                        <i class="bi bi-person-question me-1"></i>{{ $email->from_address }} — not a known contact
                    </small>
                    <div class="d-flex gap-1 mb-2">
                        <button class="btn btn-outline-secondary btn-sm" type="button" id="emailReassignBtn">
                            <i class="bi bi-arrow-right-circle me-1"></i>Change Client
                        </button>
                        <button class="btn btn-outline-primary btn-sm" type="button"
                                data-bs-toggle="collapse" data-bs-target="#linkContactForm">
                            <i class="bi bi-person-check me-1"></i>Link to Contact
                        </button>
                        <button class="btn btn-outline-primary btn-sm" type="button"
                                data-bs-toggle="collapse" data-bs-target="#addContactForm">
                            <i class="bi bi-person-plus me-1"></i>New Contact
                        </button>
                    </div>
                    @include('emails._reassign-panel')
                    <div class="collapse mt-2" id="linkContactForm">
                        <form method="POST" action="{{ route('emails.link-contact', $email) }}">
                            @csrf
                            <div class="mb-2">
                                <label class="form-label small mb-1">Select contact</label>
                                <select name="contact_id" class="form-select form-select-sm" id="linkContactSelect" required>
                                    <option value="">Choose a contact...</option>
                                    @foreach($email->client->people()->active()->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email']) as $contact)
                                        <option value="{{ $contact->id }}">
                                            {{ $contact->full_name }}{{ $contact->email ? " ({$contact->email})" : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-check mb-2">
                                <input type="checkbox" name="save_email" value="1" class="form-check-input" id="linkContactSaveEmail" checked>
                                <label class="form-check-label small" for="linkContactSaveEmail">
                                    Save <strong>{{ $email->from_address }}</strong> on this contact for future matching
                                </label>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-link-45deg me-1"></i>Link to Contact
                            </button>
                        </form>
                    </div>
                    <div class="collapse mt-2" id="addContactForm">
                        <form method="POST" action="{{ route('emails.create-contact', $email) }}">
                            @csrf
                            <div class="mb-2">
                                <input type="text" name="first_name" class="form-control form-control-sm"
                                       placeholder="First name" value="{{ old('first_name', $defaultFirst) }}">
                            </div>
                            <div class="mb-2">
                                <input type="text" name="last_name" class="form-control form-control-sm"
                                       placeholder="Last name" value="{{ old('last_name', $defaultLast) }}">
                            </div>
                            <div class="mb-2">
                                <input type="email" name="email" class="form-control form-control-sm"
                                       placeholder="Email" value="{{ old('email', $email->from_address) }}" required>
                            </div>
                            <button type="submit" class="btn btn-primary btn-sm w-100">
                                <i class="bi bi-check-lg me-1"></i>Create Contact
                            </button>
                            <p class="text-muted small mt-1 mb-0">Creates a new contact under {{ $email->client->name }}.</p>
                        </form>
                    </div>

                @elseif($email->person)
                    {{-- Person known but no client (rare) --}}
                    <h6>{{ $email->person->fullName }}</h6>
                    <small class="text-muted">No client linked</small>

                @else
                    {{-- State 1: Nothing resolved — combined form --}}
                    <p class="text-muted mb-2">
                        <i class="bi bi-question-circle me-1"></i>Sender not resolved
                    </p>
                    <small class="text-muted d-block mb-3">{{ $email->from_address }}</small>
                    <form method="POST" action="{{ route('emails.create-contact', $email) }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label small mb-1">Client</label>
                            <select name="client_id" class="form-select form-select-sm" required>
                                <option value="">Select client...</option>
                                @foreach($clients as $client)
                                    <option value="{{ $client->id }}"
                                        {{ $suggestedClientId == $client->id ? 'selected' : '' }}>
                                        {{ $client->name }}{{ $suggestedClientId == $client->id ? ' (suggested)' : '' }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <input type="text" name="first_name" class="form-control form-control-sm"
                                   placeholder="First name" value="{{ old('first_name', $defaultFirst) }}">
                        </div>
                        <div class="mb-2">
                            <input type="text" name="last_name" class="form-control form-control-sm"
                                   placeholder="Last name" value="{{ old('last_name', $defaultLast) }}">
                        </div>
                        <div class="mb-2">
                            <input type="email" name="email" class="form-control form-control-sm"
                                   placeholder="Email" value="{{ old('email', $email->from_address) }}" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-person-plus me-1"></i>Create Contact & Link
                        </button>
                    </form>
                    <hr class="my-2">
                    <p class="text-muted small mb-1">Or just link a client:</p>
                    <form method="POST" action="{{ route('emails.link-client', $email) }}" class="input-group input-group-sm">
                        @csrf
                        <select name="client_id" class="form-select form-select-sm" required>
                            <option value="">Select client...</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}"
                                    {{ $suggestedClientId == $client->id ? 'selected' : '' }}>
                                    {{ $client->name }}{{ $suggestedClientId == $client->id ? ' (suggested)' : '' }}
                                </option>
                            @endforeach
                        </select>
                        <button type="submit" class="btn btn-outline-primary">Link</button>
                    </form>
                @endif
            </div>
        </div>

        {{-- Linked Ticket --}}
        @if($email->ticket)
        <div class="card card-static shadow-sm mt-3 border-start border-3 border-primary">
            <div class="card-header">
                <i class="bi bi-ticket-perforated me-2"></i>Linked Ticket
            </div>
            <div class="card-body">
                <x-ticket-badge :ticket="$email->ticket" />
                <small class="d-block text-muted mt-1">{{ Str::limit($email->ticket->subject, 60) }}</small>
                <span class="badge {{ $email->ticket->status->badgeClass() }} mt-1">{{ $email->ticket->status->label() }}</span>
            </div>
        </div>
        @endif

        {{-- Quick Actions --}}
        <div class="card card-static shadow-sm mt-3">
            <div class="card-header">
                <i class="bi bi-lightning me-2"></i>Quick Actions
            </div>
            <div class="card-body">
                @if($email->ticket_id)
                    <a href="{{ route('tickets.show', $email->ticket_id) }}" class="btn btn-primary btn-sm w-100 mb-2">
                        <i class="bi bi-ticket-perforated me-1"></i>View Linked Ticket
                    </a>
                    <form method="POST" action="{{ route('emails.unlink-ticket', $email) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn-outline-danger btn-sm w-100 mb-2"
                                onclick="return confirm('Unlink this email from the ticket?')">
                            <i class="bi bi-x-circle me-1"></i>Unlink from Ticket
                        </button>
                    </form>
                @else
                    @if($email->client_id)
                        <a href="{{ route('emails.create-ticket', $email) }}" class="btn btn-accent btn-sm w-100 mb-3">
                            <i class="bi bi-ticket-perforated me-1"></i>Create Ticket
                        </a>
                    @else
                        <button class="btn btn-outline-secondary btn-sm w-100 mb-3 disabled"
                                title="Link a client to this email first">
                            <i class="bi bi-ticket-perforated me-1"></i>Create Ticket
                        </button>
                    @endif
                    <hr class="my-2">
                    @if($openTickets->isNotEmpty())
                        <p class="small text-muted mb-2">Or link to an open ticket:</p>
                        <div class="list-group list-group-flush mb-3">
                            @foreach($openTickets as $ticket)
                            <form method="POST" action="{{ route('emails.link-ticket', $email) }}"
                                  class="list-group-item list-group-item-action d-flex justify-content-between align-items-center p-2">
                                @csrf
                                <input type="hidden" name="ticket_id" value="{{ $ticket->id }}">
                                <div>
                                    <strong>{{ $ticket->display_id }}</strong>
                                    <span class="ms-1">{{ Str::limit($ticket->subject, 55) }}</span>
                                </div>
                                <button type="submit" class="btn btn-sm btn-outline-primary ms-2 flex-shrink-0">Link</button>
                            </form>
                            @endforeach
                        </div>
                    @elseif($email->client_id)
                        <p class="text-muted small mb-2">No open tickets for this client.</p>
                    @endif
                    <form method="POST" action="{{ route('emails.link-ticket', $email) }}" class="input-group input-group-sm">
                        @csrf
                        <input type="number" name="ticket_id" class="form-control" placeholder="Ticket ID" min="1" required>
                        <button type="submit" class="btn btn-primary">Link</button>
                    </form>
                @endif
                <hr class="my-2">
                <button class="btn btn-outline-secondary btn-sm w-100" onclick="navigator.clipboard.writeText('{{ $email->from_address }}').then(() => { this.innerHTML = '<i class=\'bi bi-check-lg me-1\'></i>Copied!'; setTimeout(() => { this.innerHTML = '<i class=\'bi bi-clipboard me-1\'></i>Copy Sender Address'; }, 2000); })">
                    <i class="bi bi-clipboard me-1"></i>Copy Sender Address
                </button>
            </div>
        </div>

        {{-- Metadata --}}
        <div class="card card-static shadow-sm mt-3">
            <div class="card-header">
                <i class="bi bi-info-circle me-2"></i>Details
            </div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tr>
                        <th class="text-muted small">Direction</th>
                        <td class="small">{{ $email->direction->label() }}</td>
                    </tr>
                    <tr>
                        <th class="text-muted small">Importance</th>
                        <td class="small">{{ ucfirst($email->importance) }}</td>
                    </tr>
                    @if($email->conversation_id)
                    <tr>
                        <th class="text-muted small">Thread</th>
                        <td class="small text-truncate" style="max-width: 200px" title="{{ $email->conversation_id }}">
                            {{ Str::limit($email->conversation_id, 20) }}
                        </td>
                    </tr>
                    @endif
                    <tr>
                        <th class="text-muted small">Imported</th>
                        <td class="small">{{ $email->created_at?->toAppTz()->format('d M Y H:i') }}</td>
                    </tr>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const reassignBtn = document.getElementById('emailReassignBtn');
    const reassignPanel = document.getElementById('emailReassignPanel');
    const reassignCancel = document.getElementById('emailReassignCancel');
    const reassignSearch = document.getElementById('emailReassignSearch');
    const reassignClientId = document.getElementById('emailReassignClientId');
    const reassignResults = document.getElementById('emailReassignResults');
    const reassignContactGroup = document.getElementById('emailReassignContactGroup');
    const reassignContactSelect = document.getElementById('emailReassignContactSelect');
    const reassignSubmit = document.getElementById('emailReassignSubmit');

    if (!reassignBtn || !reassignPanel) return;

    reassignBtn.addEventListener('click', function() {
        reassignPanel.classList.toggle('d-none');
        if (!reassignPanel.classList.contains('d-none')) {
            reassignSearch.focus();
        }
    });

    reassignCancel.addEventListener('click', function() {
        reassignPanel.classList.add('d-none');
        reassignSearch.value = '';
        reassignClientId.value = '';
        reassignResults.innerHTML = '';
        reassignContactGroup.classList.add('d-none');
        reassignSubmit.disabled = true;
    });

    let debounce;
    reassignSearch.addEventListener('input', function() {
        clearTimeout(debounce);
        const q = this.value.trim();
        if (q.length < 2) { reassignResults.innerHTML = ''; return; }
        debounce = setTimeout(() => {
            fetch('{{ route("clients.search") }}?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(clients => {
                    reassignResults.innerHTML = '';
                    clients.forEach(c => {
                        const item = document.createElement('a');
                        item.href = '#';
                        item.className = 'list-group-item list-group-item-action py-1 small';
                        item.textContent = c.name;
                        item.addEventListener('click', function(e) {
                            e.preventDefault();
                            reassignClientId.value = c.id;
                            reassignSearch.value = c.name;
                            reassignResults.innerHTML = '';
                            reassignSubmit.disabled = false;
                            // Fetch contacts for the selected client
                            fetch('/api/clients/' + c.id + '/contacts')
                                .then(r => r.json())
                                .then(contacts => {
                                    reassignContactSelect.innerHTML = '<option value="">Auto-detect from sender</option>';
                                    contacts.forEach(ct => {
                                        const opt = document.createElement('option');
                                        opt.value = ct.id;
                                        opt.textContent = ct.name + (ct.email ? ' (' + ct.email + ')' : '');
                                        reassignContactSelect.appendChild(opt);
                                    });
                                    reassignContactGroup.classList.remove('d-none');
                                })
                                .catch(() => {});
                        });
                        reassignResults.appendChild(item);
                    });
                })
                .catch(() => {});
        }, 250);
    });
});
</script>
@endpush
