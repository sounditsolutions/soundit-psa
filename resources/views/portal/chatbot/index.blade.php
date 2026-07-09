@extends('portal.layouts.app')

@section('title', 'Ask AI - ' . App\Support\PortalConfig::companyName() . ' Portal')

@push('styles')
<style>
    .chatbot-card { max-width: 820px; margin: 0 auto; }
    .chatbot-window {
        height: min(60vh, 520px);
        overflow-y: auto;
        padding: 1rem;
        background: var(--bs-body-bg, #fff);
        border: 1px solid var(--bs-border-color, #dee2e6);
        border-radius: .5rem;
    }
    .chat-msg { display: flex; margin-bottom: .75rem; }
    .chat-msg--user { justify-content: flex-end; }
    .chat-msg--assistant { justify-content: flex-start; }
    .chat-bubble {
        max-width: 85%;
        padding: .6rem .85rem;
        border-radius: .9rem;
        white-space: pre-wrap;
        word-wrap: break-word;
        line-height: 1.45;
        font-size: .95rem;
    }
    .chat-msg--user .chat-bubble { background: #0d6efd; color: #fff; border-bottom-right-radius: .2rem; }
    .chat-msg--assistant .chat-bubble { background: #f1f3f5; color: #212529; border-bottom-left-radius: .2rem; }
    .chat-typing { font-style: italic; color: #6c757d; }
    .chatbot-empty { color: #6c757d; text-align: center; padding: 2rem 1rem; }
</style>
@endpush

@section('content')
<div class="chatbot-card">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-robot me-2"></i>Ask AI</h1>
        @if($available)
            <button type="button" class="btn btn-sm btn-outline-secondary" id="chatbot-new">
                <i class="bi bi-plus-lg me-1"></i>New chat
            </button>
        @endif
    </div>

    @unless($available)
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-1"></i>
            The AI assistant isn't available right now. You can still
            <a href="{{ route('portal.tickets.create') }}">open a support ticket</a> and our team will help.
        </div>
    @else
        <p class="text-muted small">
            Ask about your tickets, invoices, devices, and service agreements. The assistant can only
            see your organization's account and can't make changes — to make a change, open a ticket.
        </p>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="chatbot-window" id="chatbot-messages"
                     data-send-url="{{ route('portal.chatbot.send') }}"
                     data-conversation-id="{{ $conversationId }}">
                    @forelse($messages as $m)
                        <div class="chat-msg chat-msg--{{ $m->role === 'user' ? 'user' : 'assistant' }}">
                            <div class="chat-bubble">{{ $m->content }}</div>
                        </div>
                    @empty
                        <div class="chatbot-empty" id="chatbot-empty">
                            <i class="bi bi-chat-dots fs-3 d-block mb-2"></i>
                            Ask me anything about your account — for example,
                            "What tickets are open?" or "Do I have any overdue invoices?"
                        </div>
                    @endforelse
                </div>

                <form id="chatbot-form" class="mt-3">
                    <div class="input-group">
                        <textarea id="chatbot-input" class="form-control" rows="1" maxlength="2000"
                                  placeholder="Type your question…" autocomplete="off" required></textarea>
                        <button type="submit" class="btn btn-primary" id="chatbot-send">
                            <i class="bi bi-send"></i>
                        </button>
                    </div>
                    <div class="form-text">
                        AI responses can be inaccurate — double-check anything important.
                    </div>
                </form>
            </div>
        </div>
    @endunless
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/portal-chatbot.js') }}?v={{ filemtime(public_path('js/portal-chatbot.js')) }}"></script>
@endpush
