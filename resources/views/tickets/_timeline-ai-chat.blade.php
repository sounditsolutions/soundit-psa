{{-- AI conversation timeline entry --}}
@php
    $isOwner = auth()->id() === $conversation->user_id;
    $lastMessage = $conversation->messages->last();
    // psa-uw2o.4: the live input is an affordance for an endpoint that refuses
    // while the Assistant is off. Without this check the technician reloads the
    // ticket during an incident and still sees a chat box badged "Active" with a
    // working-looking send button — inside the same 30-minute window the
    // incident lives in. Falling through to the collapsed summary keeps the
    // HISTORY (you turn the AI off because of what it said; the record must not
    // vanish) and drops only the dead input.
    $isActive = $isOwner
        && \App\Support\AssistantConfig::isEnabled()
        && $lastMessage?->created_at?->gt(now()->subMinutes(30));
    $messageCount = $conversation->messages->count();
@endphp

<div class="d-flex gap-3 py-3 {{ !$loop->last ? 'border-bottom' : '' }}"
     id="ai-chat-{{ $conversation->id }}"
     data-conversation-id="{{ $conversation->id }}"
     data-is-active="{{ $isActive ? '1' : '0' }}">
    <div class="flex-shrink-0">
        <div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold"
             style="width: 36px; height: 36px; background: #6f42c1; font-size: 0.9rem;">
            <i class="bi bi-robot"></i>
        </div>
    </div>
    <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 mb-1 flex-wrap">
            <strong class="small">{{ $conversation->user?->name ?? 'Unknown' }}</strong>
            <span class="badge" style="background: #6f42c1; color: white; font-size: 0.7rem;">
                <i class="bi bi-robot me-1"></i>AI Conversation
            </span>
            @if($isActive)
                <span class="badge bg-success" style="font-size: 0.65rem;">Active</span>
            @endif
            <span class="text-muted small ms-auto" title="{{ $conversation->created_at->toAppTz()->format('Y-m-d H:i T') }}">
                {{ $conversation->created_at->diffForHumans() }}
            </span>
        </div>

        @if($isActive)
            {{-- Active chat: show all messages + input --}}
            <div class="ai-chat-messages mt-2" id="ai-chat-messages-{{ $conversation->id }}">
                @foreach($conversation->messages as $msg)
                    <div class="ai-chat-msg ai-chat-msg-{{ $msg->role }} mb-2">
                        <div class="ai-chat-msg-bubble">
                            @if($msg->role === 'user')
                                {{ $msg->content }}
                            @else
                                <div class="note-body">{!! \App\Helpers\MarkdownRenderer::render($msg->content) !!}</div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="ai-chat-typing d-none mt-2" id="ai-chat-typing-{{ $conversation->id }}">
                <div class="d-flex align-items-center gap-2 text-muted small">
                    <div class="spinner-border spinner-border-sm" role="status" style="width: 14px; height: 14px;"></div>
                    <span>Thinking...</span>
                </div>
            </div>
            <div class="ai-chat-input mt-2" id="ai-chat-input-{{ $conversation->id }}">
                <div class="input-group input-group-sm">
                    <input type="text" class="form-control ai-chat-text" placeholder="Ask a question..."
                           data-conversation-id="{{ $conversation->id }}">
                    <button class="btn btn-outline-primary ai-chat-send" type="button"
                            data-conversation-id="{{ $conversation->id }}">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
            </div>
        @else
            {{-- Read-only: collapsed summary --}}
            <div class="small text-muted">
                {{ $messageCount }} messages
                @if($isOwner && \App\Support\AssistantConfig::shouldShowDisabledNotice())
                    {{-- psa-322qo: this conversation would otherwise just stop
                         being usable with no reason given — say why, so the dead
                         input explains itself to the person who was using it.

                         psa-uw2o.13 F2: the predicate was a bare !isEnabled(), so
                         this also fired on installs with no AI provider at all.
                         F3: it said the conversation was read-only and stopped
                         there — no recovery path in any form, not even a tooltip.
                         Both now come from AssistantConfig. --}}
                    <span class="ms-1" data-assistant-disabled-notice="timeline">
                        &middot; {{ \App\Support\AssistantConfig::disabledSummary() }}, so this conversation is
                        read-only. {{ \App\Support\AssistantConfig::disabledRecovery() }}
                    </span>
                @endif
            </div>
            <a class="small text-decoration-none" data-bs-toggle="collapse"
               href="#ai-chat-history-{{ $conversation->id }}">
                <i class="bi bi-chevron-down me-1"></i>Show conversation
            </a>
            <div class="collapse" id="ai-chat-history-{{ $conversation->id }}">
                <div class="mt-2 p-2 bg-light rounded">
                    @foreach($conversation->messages as $msg)
                        <div class="mb-2 small">
                            <strong class="{{ $msg->role === 'user' ? 'text-primary' : '' }}" style="{{ $msg->role === 'assistant' ? 'color: #6f42c1;' : '' }}">
                                {{ $msg->role === 'user' ? ($conversation->user?->name ?? 'Tech') : 'AI' }}:
                            </strong>
                            @if($msg->role === 'user')
                                {{ $msg->content }}
                            @else
                                <div class="note-body">{!! \App\Helpers\MarkdownRenderer::render($msg->content) !!}</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
