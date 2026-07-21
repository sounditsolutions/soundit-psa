/**
 * Inline AI chat for ticket timeline.
 * Handles: Ask AI button, create/resume conversations, send messages,
 * render responses in timeline chat blocks.
 */
(function() {
    'use strict';

    var CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content;
    var TIMEOUT_MS = 180000; // 3 minutes
    var ticketId = null;
    var activeConversationId = null;
    var sending = false;

    document.addEventListener('DOMContentLoaded', function() {
        // Get ticket ID from the page
        var match = window.location.pathname.match(/\/tickets\/(\d+)/);
        if (!match) return;
        ticketId = match[1];

        // Wire up Ask AI button
        var askAiBtn = document.getElementById('askAiBtn');
        if (askAiBtn) {
            askAiBtn.addEventListener('click', handleAskAi);
        }

        // Wire up any active chat inputs already on page (from server render)
        wireUpChatInputs();

        // Detect active conversation from server-rendered blocks
        var activeBlock = document.querySelector('[data-is-active="1"]');
        if (activeBlock) {
            activeConversationId = parseInt(activeBlock.dataset.conversationId);
        }
    });

    function handleAskAi() {
        // If there's an active conversation, scroll to it and focus input
        if (activeConversationId) {
            var block = document.getElementById('ai-chat-' + activeConversationId);
            if (block) {
                block.scrollIntoView({ behavior: 'smooth', block: 'center' });
                var input = block.querySelector('.ai-chat-text');
                if (input) input.focus();

                // Highlight the Ask AI button
                var btn = document.getElementById('askAiBtn');
                if (btn) {
                    btn.classList.remove('btn-outline-secondary');
                    btn.classList.add('btn-secondary');
                }
                return;
            }
        }

        // Create a new conversation
        createConversation();
    }

    function createConversation() {
        fetch('/assistant/conversations', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                context_type: 'ticket',
                context_id: parseInt(ticketId)
            })
        })
        // psa-uw2o.4: without the r.ok check a refusal parsed cleanly, so
        // data.id was undefined and we injected a chat block id="ai-chat-undefined"
        // badged "Active" with a live input, AND highlighted the Ask AI button to
        // confirm it — the UI affirmatively fabricated a session that did not
        // exist. Fail visibly instead of manufacturing success.
        .then(function(r) {
            if (!r.ok) return r.json().then(function(d) { throw new Error(d.error || 'Could not start an AI conversation.'); });
            return r.json();
        })
        .then(function(data) {
            if (!data || !data.id) throw new Error('Could not start an AI conversation.');

            activeConversationId = data.id;
            insertActiveChatBlock(data.id);

            // Highlight Ask AI button
            var btn = document.getElementById('askAiBtn');
            if (btn) {
                btn.classList.remove('btn-outline-secondary');
                btn.classList.add('btn-secondary');
            }
        })
        .catch(function(err) {
            console.error('Failed to create conversation:', err);
            window.alert(err.message || 'Could not start an AI conversation.');
        });
    }

    function insertActiveChatBlock(conversationId) {
        var timeline = document.querySelector('#notes .card-body');
        if (!timeline) return;

        // Insert after action panels but before the first timeline entry
        var insertBefore = null;
        for (var i = 0; i < timeline.children.length; i++) {
            var child = timeline.children[i];
            // Skip action buttons and panels
            if (child.id === 'actionButtons' || child.classList.contains('action-panel')) continue;
            // First real timeline entry
            insertBefore = child;
            break;
        }

        var block = document.createElement('div');
        block.className = 'd-flex gap-3 py-3 border-bottom';
        block.id = 'ai-chat-' + conversationId;
        block.dataset.conversationId = conversationId;
        block.dataset.isActive = '1';

        block.innerHTML = '<div class="flex-shrink-0">' +
            '<div class="rounded-circle d-flex align-items-center justify-content-center text-white fw-bold" ' +
            'style="width: 36px; height: 36px; background: #6f42c1; font-size: 0.9rem;">' +
            '<i class="bi bi-robot"></i></div></div>' +
            '<div class="flex-grow-1">' +
            '<div class="d-flex align-items-center gap-2 mb-1 flex-wrap">' +
            '<strong class="small">AI Conversation</strong>' +
            '<span class="badge bg-success" style="font-size: 0.65rem;">Active</span></div>' +
            '<div class="ai-chat-messages mt-2" id="ai-chat-messages-' + conversationId + '"></div>' +
            '<div class="ai-chat-typing d-none mt-2" id="ai-chat-typing-' + conversationId + '">' +
            '<div class="d-flex align-items-center gap-2 text-muted small">' +
            '<div class="spinner-border spinner-border-sm" role="status" style="width: 14px; height: 14px;"></div>' +
            '<span>Thinking...</span></div></div>' +
            '<div class="ai-chat-input mt-2" id="ai-chat-input-' + conversationId + '">' +
            '<div class="input-group input-group-sm">' +
            '<input type="text" class="form-control ai-chat-text" placeholder="Ask a question..." ' +
            'data-conversation-id="' + conversationId + '">' +
            '<button class="btn btn-outline-primary ai-chat-send" type="button" ' +
            'data-conversation-id="' + conversationId + '">' +
            '<i class="bi bi-send-fill"></i></button></div></div></div>';

        if (insertBefore) {
            timeline.insertBefore(block, insertBefore);
        } else {
            timeline.appendChild(block);
        }

        wireUpChatInputs();
        block.querySelector('.ai-chat-text').focus();
    }

    function wireUpChatInputs() {
        // Send buttons
        document.querySelectorAll('.ai-chat-send').forEach(function(btn) {
            if (btn.dataset.wired) return;
            btn.dataset.wired = '1';
            btn.addEventListener('click', function() {
                var convId = this.dataset.conversationId;
                sendMessage(convId);
            });
        });

        // Enter key on input fields
        document.querySelectorAll('.ai-chat-text').forEach(function(input) {
            if (input.dataset.wired) return;
            input.dataset.wired = '1';
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    var convId = this.dataset.conversationId;
                    sendMessage(convId);
                }
            });
        });
    }

    function sendMessage(conversationId) {
        if (sending) return;

        var input = document.querySelector('#ai-chat-input-' + conversationId + ' .ai-chat-text');
        var text = input ? input.value.trim() : '';
        if (!text) return;

        sending = true;
        input.value = '';
        input.disabled = true;

        // Append user message
        appendMessage(conversationId, 'user', text);

        // Show typing
        var typing = document.getElementById('ai-chat-typing-' + conversationId);
        if (typing) typing.classList.remove('d-none');

        var controller = new AbortController();
        var timeoutId = setTimeout(function() { controller.abort(); }, TIMEOUT_MS);

        fetch('/assistant/conversations/' + conversationId + '/messages', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': CSRF_TOKEN,
                'Accept': 'application/json'
            },
            body: JSON.stringify({ message: text }),
            signal: controller.signal
        })
        .then(function(r) {
            clearTimeout(timeoutId);
            if (!r.ok) return r.json().then(function(d) { throw new Error(d.error || 'Request failed'); });
            return r.json();
        })
        .then(function(data) {
            appendMessage(conversationId, 'assistant', data.content, data.tools_used);
        })
        .catch(function(err) {
            appendMessage(conversationId, 'error', err.name === 'AbortError'
                ? 'Request timed out. The AI may still be processing.'
                : (err.message || 'An error occurred'));
        })
        .finally(function() {
            sending = false;
            if (typing) typing.classList.add('d-none');
            if (input) {
                input.disabled = false;
                input.focus();
            }
        });
    }

    function appendMessage(conversationId, role, content, toolsUsed) {
        var container = document.getElementById('ai-chat-messages-' + conversationId);
        if (!container) return;

        var div = document.createElement('div');
        div.className = 'ai-chat-msg ai-chat-msg-' + role + ' mb-2';

        if (role === 'error') {
            div.innerHTML = '<div class="alert alert-danger py-1 px-2 mb-0 small">' + escapeHtml(content) + '</div>';
        } else if (role === 'user') {
            div.innerHTML = '<div class="ai-chat-msg-bubble ai-chat-msg-user-bubble">' + escapeHtml(content) + '</div>';
        } else {
            var html = '<div class="ai-chat-msg-bubble ai-chat-msg-assistant-bubble note-body">' + renderMarkdown(content) + '</div>';
            if (toolsUsed && toolsUsed.length > 0) {
                html += '<div class="mt-1">';
                toolsUsed.forEach(function(t) {
                    html += '<span class="badge bg-light text-dark border me-1" style="font-size: 0.65rem;">' + escapeHtml(formatToolName(t)) + '</span>';
                });
                html += '</div>';
            }
            div.innerHTML = html;
        }

        container.appendChild(div);

        // Scroll the chat block into view
        div.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatToolName(name) {
        var prefixes = { ninja: 'NINJA', level: 'LEVEL', mesh: 'MESH', cipp: 'CIPP', controld: 'CTRL-D', zorus: 'ZORUS' };
        for (var prefix in prefixes) {
            if (name.startsWith(prefix + '_')) {
                return prefixes[prefix] + ': ' + name.substring(prefix.length + 1).replace(/_/g, ' ');
            }
        }
        return name.replace(/_/g, ' ');
    }

    function renderMarkdown(text) {
        // Basic markdown rendering
        var html = escapeHtml(text);
        // Code blocks
        html = html.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>');
        // Inline code
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        // Bold
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        // Italic
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        // Headers
        html = html.replace(/^### (.+)$/gm, '<h6>$1</h6>');
        html = html.replace(/^## (.+)$/gm, '<h5>$1</h5>');
        html = html.replace(/^# (.+)$/gm, '<h4>$1</h4>');
        // Lists
        html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>');
        html = html.replace(/<\/ul>\s*<ul>/g, '');
        // Paragraphs
        html = html.replace(/\n\n/g, '</p><p>');
        html = html.replace(/\n/g, '<br>');
        html = '<p>' + html + '</p>';
        return html;
    }
})();
