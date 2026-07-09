/**
 * Client-portal AI chatbot (psa-2ab).
 *
 * Blocking-JSON transport (no streaming): POST the message + conversation id,
 * render the single JSON reply. All message text is inserted via textContent,
 * so model/user output is never interpreted as HTML.
 */
(function () {
    'use strict';

    var messagesEl = document.getElementById('chatbot-messages');
    var form = document.getElementById('chatbot-form');
    var input = document.getElementById('chatbot-input');
    var sendBtn = document.getElementById('chatbot-send');
    var newBtn = document.getElementById('chatbot-new');

    if (!messagesEl || !form || !input) {
        return; // Assistant unavailable / not rendered.
    }

    var sendUrl = messagesEl.getAttribute('data-send-url');
    var conversationId = messagesEl.getAttribute('data-conversation-id') || '';
    var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
    var busy = false;

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function removeEmptyPlaceholder() {
        var empty = document.getElementById('chatbot-empty');
        if (empty) {
            empty.remove();
        }
    }

    function addBubble(role, text, opts) {
        opts = opts || {};
        var row = document.createElement('div');
        row.className = 'chat-msg chat-msg--' + (role === 'user' ? 'user' : 'assistant');
        var bubble = document.createElement('div');
        bubble.className = 'chat-bubble';
        if (opts.typing) {
            row.id = 'chat-typing-row';
            bubble.className += ' chat-typing';
        }
        if (opts.error) {
            bubble.classList.add('text-danger');
        }
        bubble.textContent = text;
        row.appendChild(bubble);
        messagesEl.appendChild(row);
        scrollToBottom();
        return row;
    }

    function setBusy(state) {
        busy = state;
        input.disabled = state;
        if (sendBtn) {
            sendBtn.disabled = state;
        }
    }

    function send(message) {
        removeEmptyPlaceholder();
        addBubble('user', message);
        var typingRow = addBubble('assistant', 'Thinking…', { typing: true });
        setBusy(true);

        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, 120000);

        fetch(sendUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrf
            },
            body: JSON.stringify({ message: message, conversation_id: conversationId || null }),
            signal: controller.signal
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok, data: data };
                });
            })
            .then(function (result) {
                clearTimeout(timer);
                if (typingRow) { typingRow.remove(); }
                if (result.ok && result.data && typeof result.data.reply === 'string') {
                    if (result.data.conversation_id) {
                        conversationId = String(result.data.conversation_id);
                        messagesEl.setAttribute('data-conversation-id', conversationId);
                    }
                    addBubble('assistant', result.data.reply);
                } else {
                    var msg = (result.data && result.data.error)
                        ? result.data.error
                        : 'Sorry, something went wrong. Please try again.';
                    addBubble('assistant', msg, { error: true });
                }
            })
            .catch(function () {
                clearTimeout(timer);
                if (typingRow) { typingRow.remove(); }
                addBubble('assistant', 'The assistant took too long or could not be reached. Please try again.', { error: true });
            })
            .finally(function () {
                setBusy(false);
                input.focus();
            });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) { return; }
        var message = (input.value || '').trim();
        if (!message) { return; }
        input.value = '';
        send(message);
    });

    // Enter sends; Shift+Enter inserts a newline.
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!busy) {
                form.dispatchEvent(new Event('submit', { cancelable: true }));
            }
        }
    });

    if (newBtn) {
        newBtn.addEventListener('click', function () {
            if (busy) { return; }
            conversationId = '';
            messagesEl.setAttribute('data-conversation-id', '');
            messagesEl.innerHTML = '';
            var empty = document.createElement('div');
            empty.className = 'chatbot-empty';
            empty.id = 'chatbot-empty';
            empty.textContent = 'New chat started — ask me anything about your account.';
            messagesEl.appendChild(empty);
            input.focus();
        });
    }

    scrollToBottom();
})();
