/**
 * AI Assistant Chat Panel
 * Vanilla JS IIFE — same pattern as command-palette.js
 */
(function () {
    'use strict';

    const STORAGE_KEY = 'psa-assistant';
    const PANEL_STATE_KEY = 'psa-assistant-open';
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content;
    const TIMEOUT_MS = 180_000; // 3 min — AI tool loops can take time

    let panel, messagesEl, inputEl, sendBtn, contextEl, typingEl, footerEl;
    let conversationId = null;
    let contextType = null;
    let contextId = null;
    let sending = false;

    // ── Init ──

    function init() {
        panel = document.getElementById('assistant-panel');
        if (!panel) return;

        messagesEl = panel.querySelector('.assistant-messages');
        inputEl = panel.querySelector('.assistant-input');
        sendBtn = panel.querySelector('.assistant-send-btn');
        contextEl = panel.querySelector('.assistant-context');
        typingEl = panel.querySelector('.assistant-typing');
        footerEl = panel.querySelector('.assistant-footer');

        // Detect page context via data attributes
        detectContext();

        // Restore conversation from localStorage
        restoreSession();

        // Event listeners
        sendBtn.addEventListener('click', send);
        inputEl.addEventListener('keydown', onKeyDown);
        inputEl.addEventListener('input', autoResize);

        // Toggle buttons
        document.querySelectorAll('[data-assistant-toggle]').forEach(function (btn) {
            btn.addEventListener('click', toggle);
        });

        // New conversation button
        panel.querySelector('.assistant-new-btn')?.addEventListener('click', newConversation);

        // Close button
        panel.querySelector('.assistant-close-btn')?.addEventListener('click', close);

        // ESC to close
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && document.body.classList.contains('right-panel-open')) {
                close();
            }
        });

        // Cross-tab sync: detect when another tab changes the conversation
        window.addEventListener('storage', onStorageChange);

        // Restore panel open/closed state
        if (localStorage.getItem(PANEL_STATE_KEY) === '1') {
            open();
        }

        updateContextDisplay();
    }

    // ── Context Detection ──

    function detectContext() {
        // Primary: data attributes set by Blade templates
        var ctxEl = document.querySelector('[data-assistant-context]');
        if (ctxEl) {
            contextType = ctxEl.getAttribute('data-assistant-context');
            contextId = parseInt(ctxEl.getAttribute('data-assistant-context-id'), 10) || null;
            return;
        }

        // Fallback: URL parsing
        var path = window.location.pathname;
        var ticketMatch = path.match(/^\/tickets\/(\d+)/);
        if (ticketMatch) {
            contextType = 'ticket';
            contextId = parseInt(ticketMatch[1], 10);
            return;
        }

        var clientMatch = path.match(/^\/clients\/(\d+)/);
        if (clientMatch) {
            contextType = 'client';
            contextId = parseInt(clientMatch[1], 10);
            return;
        }

        contextType = null;
        contextId = null;
    }

    function updateContextDisplay() {
        if (!contextEl) return;

        var stored = loadSession();
        if (stored && stored.conversationId) {
            // Show stored context (conversation is in progress)
            if (stored.contextType === 'ticket') {
                contextEl.textContent = 'Ticket #' + stored.contextId;
            } else if (stored.contextType === 'client') {
                contextEl.textContent = 'Client #' + stored.contextId;
            } else {
                contextEl.textContent = 'General';
            }

            // If page context differs from conversation context, hint user
            if (contextType && (contextType !== stored.contextType || contextId !== stored.contextId)) {
                contextEl.title = 'This conversation is scoped to a different page. Click "New" for current page context.';
                contextEl.style.borderColor = '#fbbf24';
            }
        } else {
            // No active conversation — show page context
            if (contextType === 'ticket') {
                contextEl.textContent = 'Ticket #' + contextId;
            } else if (contextType === 'client') {
                contextEl.textContent = 'Client #' + contextId;
            } else {
                contextEl.textContent = 'General';
            }
        }
    }

    // ── Session Persistence ──

    function loadSession() {
        try {
            return JSON.parse(localStorage.getItem(STORAGE_KEY));
        } catch (e) {
            return null;
        }
    }

    function saveSession() {
        localStorage.setItem(STORAGE_KEY, JSON.stringify({
            conversationId: conversationId,
            contextType: contextType,
            contextId: contextId,
        }));
    }

    function clearSession() {
        localStorage.removeItem(STORAGE_KEY);
        conversationId = null;
    }

    function restoreSession() {
        var stored = loadSession();
        if (stored && stored.conversationId) {
            conversationId = stored.conversationId;
            // Keep the stored context for the existing conversation
            // Don't overwrite with page context — the conversation is scoped

            // Rehydrate message history from server
            loadConversationHistory(stored.conversationId);
        }
    }

    function loadConversationHistory(convId) {
        fetchJson('/assistant/conversations/' + convId, { method: 'GET' })
            .then(function (data) {
                if (data.messages && data.messages.length > 0) {
                    hideEmptyState();
                    data.messages.forEach(function (msg) {
                        appendMessage(msg.role, msg.content, null, msg.role === 'assistant' ? msg.id : null);
                    });
                }
            })
            .catch(function () {
                // Conversation may have been deleted — clear and start fresh
                clearSession();
                showEmptyState();
            });
    }

    // ── Cross-Tab Sync ──

    function onStorageChange(e) {
        if (e.key === STORAGE_KEY) {
            // Another tab changed the conversation
            var newData = null;
            try { newData = JSON.parse(e.newValue); } catch (ignored) {}

            var oldConvId = conversationId;
            if (!newData || !newData.conversationId) {
                // Other tab cleared the session (clicked New)
                conversationId = null;
                messagesEl.innerHTML = '';
                detectContext();
                showEmptyState();
                updateContextDisplay();
                updateFooter(0, 0);
            } else if (newData.conversationId !== oldConvId) {
                // Other tab started a new conversation
                conversationId = newData.conversationId;
                messagesEl.innerHTML = '';
                hideEmptyState();
                loadConversationHistory(conversationId);
                updateContextDisplay();
                updateFooter(0, 0);
            } else {
                // Same conversation — other tab sent a message, reload history
                messagesEl.innerHTML = '';
                hideEmptyState();
                loadConversationHistory(conversationId);
            }
        }

        if (e.key === PANEL_STATE_KEY) {
            // Sync panel open/closed state across tabs
            if (e.newValue === '1') {
                document.body.classList.add('right-panel-open');
            } else {
                document.body.classList.remove('right-panel-open');
            }
        }
    }

    // ── Toggle / New / Close ──

    function toggle() {
        if (document.body.classList.contains('right-panel-open')) {
            close();
        } else {
            open();
        }
    }

    function open() {
        document.body.classList.add('right-panel-open');
        localStorage.setItem(PANEL_STATE_KEY, '1');
        setTimeout(function () { inputEl.focus(); }, 250);
    }

    function close() {
        document.body.classList.remove('right-panel-open');
        localStorage.setItem(PANEL_STATE_KEY, '0');
    }

    function newConversation() {
        clearSession();
        detectContext(); // Re-read page context
        messagesEl.innerHTML = '';
        showEmptyState();
        updateContextDisplay();
        updateFooter(0, 0);
        inputEl.focus();
    }

    // ── Send Message ──

    function send() {
        var text = inputEl.value.trim();
        if (!text || sending) return;

        sending = true;
        sendBtn.disabled = true;
        hideEmptyState();

        // Add user bubble
        appendMessage('user', text);
        inputEl.value = '';
        autoResize();

        // Show typing indicator
        showTyping('Thinking...');

        // Create conversation if needed, then send
        var p = conversationId ? Promise.resolve() : createConversation();
        p.then(function () {
            return sendToApi(text);
        }).then(function (data) {
            hideTyping();
            appendMessage('assistant', data.content, data.tools_used, data.id);
            updateFooter(data.input_tokens, data.output_tokens);
        }).catch(function (err) {
            hideTyping();
            showError(err.message || 'An error occurred. Please try again.');
        }).finally(function () {
            sending = false;
            sendBtn.disabled = false;
            inputEl.focus();
        });
    }

    function createConversation() {
        return fetchJson('/assistant/conversations', {
            method: 'POST',
            body: JSON.stringify({
                context_type: contextType,
                context_id: contextId,
            }),
        }).then(function (data) {
            conversationId = data.id;
            saveSession();
            updateContextDisplay();
        });
    }

    function sendToApi(message) {
        return fetchJson('/assistant/conversations/' + conversationId + '/messages', {
            method: 'POST',
            body: JSON.stringify({ message: message }),
        });
    }

    function saveAsNote(messageId, btn) {
        if (btn.classList.contains('saved')) return;

        fetchJson('/assistant/conversations/' + conversationId + '/save-note', {
            method: 'POST',
            body: JSON.stringify({ message_id: messageId }),
        }).then(function () {
            btn.innerHTML = '<i class="bi bi-check2"></i> Saved';
            btn.classList.add('saved');
        }).catch(function (err) {
            alert('Failed to save note: ' + (err.message || 'Unknown error'));
        });
    }

    // ── Fetch Helper ──

    function fetchJson(url, opts) {
        var controller = new AbortController();
        var timer = setTimeout(function () { controller.abort(); }, TIMEOUT_MS);

        opts.headers = {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': CSRF,
        };
        if (opts.method !== 'GET') {
            opts.headers['Content-Type'] = 'application/json';
        }
        opts.signal = controller.signal;

        return fetch(url, opts).then(function (res) {
            clearTimeout(timer);
            var ct = res.headers.get('content-type') || '';
            if (!ct.includes('application/json')) {
                throw new Error(res.status === 502 || res.status === 504
                    ? 'Request timed out. The AI may still be processing — try again shortly.'
                    : 'Unexpected response from server (HTTP ' + res.status + ')');
            }
            return res.json().then(function (data) {
                if (!res.ok) {
                    throw new Error(data.error || 'Request failed');
                }
                return data;
            });
        }).catch(function (err) {
            clearTimeout(timer);
            if (err.name === 'AbortError') {
                throw new Error('Request timed out. The AI may still be processing — try again shortly.');
            }
            throw err;
        });
    }

    // ── UI Helpers ──

    function appendMessage(role, content, toolsUsed, messageId) {
        var msg = document.createElement('div');
        msg.className = 'assistant-msg ' + role;

        var avatar = document.createElement('div');
        avatar.className = 'assistant-msg-avatar';
        avatar.innerHTML = role === 'assistant' ? '<i class="bi bi-robot"></i>' : '<i class="bi bi-person-fill"></i>';

        var bubbleWrap = document.createElement('div');

        var bubble = document.createElement('div');
        bubble.className = 'assistant-msg-bubble';

        if (role === 'assistant') {
            bubble.innerHTML = renderMarkdown(content);
        } else {
            bubble.textContent = content;
        }

        bubbleWrap.appendChild(bubble);

        // Tool badges
        if (toolsUsed && toolsUsed.length > 0) {
            var tools = document.createElement('div');
            tools.className = 'assistant-msg-tools';
            toolsUsed.forEach(function (t) {
                var badge = document.createElement('span');
                badge.className = 'assistant-tool-badge';
                badge.textContent = formatToolName(t);
                tools.appendChild(badge);
            });
            bubbleWrap.appendChild(tools);
        }

        // Save as note button (ticket context only)
        if (role === 'assistant' && messageId) {
            var stored = loadSession();
            if (stored && stored.contextType === 'ticket') {
                var saveBtn = document.createElement('button');
                saveBtn.className = 'assistant-save-note';
                saveBtn.innerHTML = '<i class="bi bi-sticky"></i> Save as note';
                saveBtn.addEventListener('click', function () {
                    saveAsNote(messageId, saveBtn);
                });
                bubbleWrap.appendChild(saveBtn);
            }
        }

        msg.appendChild(avatar);
        msg.appendChild(bubbleWrap);
        messagesEl.appendChild(msg);
        scrollToBottom();
    }

    function showEmptyState() {
        if (messagesEl.querySelector('.assistant-empty')) return;
        var empty = document.createElement('div');
        empty.className = 'assistant-empty';
        empty.innerHTML = '<i class="bi bi-robot"></i>'
            + '<p>AI Assistant</p>'
            + '<p class="text-muted">Ask questions about this ticket, client, or their infrastructure.</p>';
        messagesEl.appendChild(empty);
    }

    function hideEmptyState() {
        var el = messagesEl.querySelector('.assistant-empty');
        if (el) el.remove();
    }

    function showTyping(label) {
        if (typingEl) {
            typingEl.querySelector('.assistant-typing-label').textContent = label || 'Thinking...';
            typingEl.style.display = 'flex';
            scrollToBottom();
        }
    }

    function hideTyping() {
        if (typingEl) typingEl.style.display = 'none';
    }

    function showError(message) {
        var el = document.createElement('div');
        el.className = 'assistant-error';
        el.textContent = message;
        messagesEl.appendChild(el);
        scrollToBottom();

        // Auto-dismiss after 8s
        setTimeout(function () {
            if (el.parentNode) el.remove();
        }, 8000);
    }

    function updateFooter(inputTokens, outputTokens) {
        if (!footerEl) return;
        if (inputTokens || outputTokens) {
            footerEl.textContent = 'Tokens: ' + (inputTokens + outputTokens).toLocaleString();
        } else {
            footerEl.textContent = '';
        }
    }

    function scrollToBottom() {
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    // ── Keyboard ──

    function onKeyDown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            send();
        }
    }

    function autoResize() {
        inputEl.style.height = 'auto';
        inputEl.style.height = Math.min(inputEl.scrollHeight, 120) + 'px';
    }

    // ── Markdown Rendering ──

    function renderMarkdown(text) {
        if (!text) return '';

        // Escape HTML first (XSS prevention)
        var s = escapeHtml(text);

        // Code blocks (``` ... ```)
        s = s.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>');

        // Inline code
        s = s.replace(/`([^`]+)`/g, '<code>$1</code>');

        // Bold
        s = s.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

        // Italic
        s = s.replace(/\*([^*]+)\*/g, '<em>$1</em>');

        // Headers
        s = s.replace(/^### (.+)$/gm, '<h3>$1</h3>');
        s = s.replace(/^## (.+)$/gm, '<h2>$1</h2>');
        s = s.replace(/^# (.+)$/gm, '<h1>$1</h1>');

        // Ordered lists (must be before unordered to avoid collision)
        s = s.replace(/^\d+\. (.+)$/gm, '<oli>$1</oli>');
        s = s.replace(/(<oli>[\s\S]*?<\/oli>)/g, '<ol>$1</ol>');
        s = s.replace(/<\/ol>\s*<ol>/g, '');
        s = s.replace(/<\/?oli>/g, function (m) { return m.replace('oli', 'li'); });

        // Unordered lists
        s = s.replace(/^- (.+)$/gm, '<li>$1</li>');
        s = s.replace(/(<li>[\s\S]*?<\/li>)/g, '<ul>$1</ul>');
        // Clean up duplicate wrapping
        s = s.replace(/<\/ul>\s*<ul>/g, '');

        // Paragraphs (double newline)
        s = s.replace(/\n\n+/g, '</p><p>');
        // Single newlines to <br> (except inside pre/code)
        s = s.replace(/\n/g, '<br>');

        // Wrap in paragraph
        s = '<p>' + s + '</p>';

        // Clean empty paragraphs
        s = s.replace(/<p>\s*<\/p>/g, '');

        // Fix headers inside paragraphs
        s = s.replace(/<p>(<h[123]>)/g, '$1');
        s = s.replace(/(<\/h[123]>)<\/p>/g, '$1');

        // Fix pre inside paragraphs
        s = s.replace(/<p>(<pre>)/g, '$1');
        s = s.replace(/(<\/pre>)<\/p>/g, '$1');

        // Fix ul/ol inside paragraphs
        s = s.replace(/<p>(<[uo]l>)/g, '$1');
        s = s.replace(/(<\/[uo]l>)<\/p>/g, '$1');

        return s;
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    function formatToolName(name) {
        return name.replace(/_/g, ' ').replace(/^(ninja|level|mesh|cipp) /, function (m) {
            return m.trim().toUpperCase() + ': ';
        });
    }

    // ── Start ──

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Show empty state on first load if no conversation
    document.addEventListener('DOMContentLoaded', function () {
        if (messagesEl && !conversationId) {
            showEmptyState();
        }
    });
})();
