{{-- Reading pane fragment for /emails at desktop width (ROXZP7e5, mockup
     design/mockups/emails/mailbox-reading-pane.html). Loaded by EmailController@show
     with ?pane=1, which marks the email read exactly as the full show page does.
     Every action posts to an existing route; return_to brings the user back to the
     folder they were in. --}}
@php
    $isInbound = $email->direction === App\Enums\EmailDirection::Inbound;
    $firstTo = $email->to_recipients ? $email->primaryRecipientAddress() : null;
@endphp
<div class="mb-reader-inner" data-email-id="{{ $email->id }}" data-client-id="{{ $email->client_id }}">
    <div class="mb-toolbar">
        @if($email->ticket_id)
            <a href="{{ route('tickets.show', $email->ticket_id) }}" class="btn btn-accent btn-sm">Open ticket</a>
            <form method="POST" action="{{ route('emails.unlink-ticket', $email) }}" class="d-inline"
                  onsubmit="return confirm('Unlink this email from the ticket?')">
                @csrf
                @method('DELETE')
                <input type="hidden" name="return_to" value="{{ $returnTo }}">
                <button type="submit" class="btn btn-outline-primary btn-sm">Unlink</button>
            </form>
        @else
            @if($email->client_id)
                <a href="{{ route('emails.create-ticket', $email) }}" class="btn btn-accent btn-sm">Create ticket</a>
            @else
                <button type="button" class="btn btn-outline-secondary btn-sm" disabled
                        title="Link a client to this email first">Create ticket</button>
            @endif
            <button type="button" class="btn btn-outline-primary btn-sm" data-mb-picker-toggle>Add to ticket</button>
        @endif
        @if($isInbound)
            <a href="{{ route('emails.show', $email) }}#reply" class="btn btn-outline-primary btn-sm">Reply</a>
        @endif
        @if($email->dismissed_at)
            <form method="POST" action="{{ route('emails.undismiss', $email) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm">Unarchive</button>
            </form>
        @else
            <form method="POST" action="{{ route('emails.dismiss', $email) }}" class="d-inline">
                @csrf
                <button type="submit" class="btn btn-outline-primary btn-sm">Archive</button>
            </form>
        @endif
        <form method="POST" action="{{ route('emails.bulk-action') }}" class="d-inline">
            @csrf
            <input type="hidden" name="action" value="mark_unread">
            <input type="hidden" name="email_ids[]" value="{{ $email->id }}">
            <input type="hidden" name="return_to" value="{{ $returnTo }}">
            <button type="submit" class="btn btn-outline-primary btn-sm">Mark unread</button>
        </form>
    </div>

    <div class="mb-meta">
        <h2>{{ $email->subject }}</h2>
        <div>
            <strong>{{ $email->from_name ?: $email->from_address }}</strong>
            @if($email->from_name)
                &lt;{{ $email->from_address }}&gt;
            @endif
            @if($firstTo)
                to {{ $firstTo }}
            @endif
        </div>
        <div class="mb-chips">
            @if($email->client)
                <span class="mb-chip">{{ $email->client->name }}</span>
            @elseif($isInbound)
                <span class="mb-chip none">No client</span>
            @endif
            @if($email->person)
                <span class="mb-chip">Contact: {{ $email->person->fullName }}</span>
            @endif
            @if($email->ticket)
                <x-ticket-badge :ticket="$email->ticket" />
            @endif
            <span class="mb-when" title="{{ $email->received_at?->toAppTz()->format('d M Y H:i T') }}">{{ $email->received_at?->toAppTz()->format('d M Y H:i') }}</span>
        </div>
    </div>

    @unless($email->ticket_id)
        <form method="POST" action="{{ route('emails.link-ticket', $email) }}" class="mb-picker d-none" data-mb-picker>
            @csrf
            <input type="hidden" name="ticket_id" value="">
            <input type="hidden" name="return_to" value="{{ $returnTo }}">
            <input type="text" class="form-control form-control-sm" placeholder="Find an open ticket" aria-label="Find a ticket" autocomplete="off" data-mb-picker-q>
            <div class="list-group list-group-flush" data-mb-picker-results></div>
        </form>
    @endunless

    <div class="mb-body">
        @if($email->body_text)
            <div style="white-space: pre-wrap; line-height: 1.6;">{{ $email->body_text }}</div>
        @elseif($email->body_preview)
            <p class="mb-0">{{ $email->body_preview }}</p>
        @else
            <p class="text-muted mb-0">No email body available.</p>
        @endif
    </div>
    <div class="mb-pane-foot">
        <a href="{{ route('emails.show', $email) }}" class="small">Open full page</a>
    </div>
</div>
