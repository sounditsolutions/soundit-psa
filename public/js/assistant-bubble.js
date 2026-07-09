/**
 * General AI Assistant — floating chat bubble.
 * Available on all pages. Per-tech persistent conversation (context_type = null).
 */
(function() {
    'use strict';

    var CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content;
    var TIMEOUT_MS = 180000;
    var conversationId = null;
    var sending = false;
    var loaded = false;

    var bubble, flyout, messagesEl, inputEl, sendBtn, typingEl, closeBtn;

    document.addEventListener('DOMContentLoaded', function() {
        bubble = document.getElementById('assistantBubble');
        flyout = document.getElementById('assistantFlyout');
        if (!bubble || !flyout) return;

        messagesEl = flyout.querySelector('.ab-messages');
        inputEl = flyout.querySelector('.ab-input');
        sendBtn = flyout.querySelector('.ab-send');
        typingEl = flyout.querySelector('.ab-typing');
        closeBtn = flyout.querySelector('.ab-close');

        bubble.addEventListener('click', toggleFlyout);
        closeBtn.addEventListener('click', closeFlyout);
        sendBtn.addEventListener('click', send);
        inputEl.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                send();
            }
        });

        // Close on click outside
        document.addEventListener('click', function(e) {
            if (flyout.classList.contains('open') && !flyout.contains(e.target) && !bubble.contains(e.target)) {
                closeFlyout();
            }
        });

        setupScrollHide();
    });

    function toggleFlyout() {
        if (flyout.classList.contains('open')) {
            closeFlyout();
        } else {
            openFlyout();
        }
    }

    function openFlyout() {
        flyout.classList.add('open');
        bubble.classList.add('active');
        if (!loaded) {
            loadConversation();
        }
        setTimeout(function() { inputEl.focus(); }, 100);
    }

    function closeFlyout() {
        flyout.classList.remove('open');
        bubble.classList.remove('active');
    }

    // On small screens the fixed bubble sits over the bottom-right of the
    // viewport, where it can cover the content a tech is actively reading —
    // wiki fact text on a client Network page, or right-aligned invoice
    // line-item amounts. Hide it while the user scrolls down through the page
    // and bring it back on scroll-up or near the top, so content is never
    // obscured while it's being read. Desktop keeps the bubble always visible.
    function setupScrollHide() {
        var mobile = window.matchMedia('(max-width: 991.98px)');
        var lastY = window.scrollY || window.pageYOffset || 0;
        var ticking = false;

        function update() {
            ticking = false;
            // Never hide on desktop, or while the chat flyout is open.
            if (!mobile.matches || flyout.classList.contains('open')) {
                bubble.classList.remove('ab-hidden');
                lastY = window.scrollY || window.pageYOffset || 0;
                return;
            }
            var y = window.scrollY || window.pageYOffset || 0;
            if (y > lastY && y > 120) {
                bubble.classList.add('ab-hidden');    // scrolling down, away from the top
            } else {
                bubble.classList.remove('ab-hidden'); // scrolling up or near the top
            }
            lastY = y;
        }

        window.addEventListener('scroll', function() {
            if (!ticking) {
                ticking = true;
                window.requestAnimationFrame(update);
            }
        }, { passive: true });

        // If the viewport grows past the mobile breakpoint, make sure it's shown.
        var onChange = function() { if (!mobile.matches) bubble.classList.remove('ab-hidden'); };
        if (mobile.addEventListener) {
            mobile.addEventListener('change', onChange);
        } else if (mobile.addListener) {
            mobile.addListener(onChange); // Safari < 14
        }
    }

    function loadConversation() {
        fetch('/assistant/general', {
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            conversationId = data.id;
            loaded = true;
            messagesEl.innerHTML = '';
            if (data.messages && data.messages.length > 0) {
                data.messages.forEach(function(m) {
                    appendMessage(m.role, m.content);
                });
            } else {
                showEmpty();
            }
            scrollToBottom();
        })
        .catch(function(err) {
            console.error('Failed to load general conversation:', err);
        });
    }

    function send() {
        if (sending) return;
        var text = inputEl.value.trim();
        if (!text) return;

        sending = true;
        inputEl.value = '';
        inputEl.disabled = true;
        hideEmpty();
        appendMessage('user', text);
        showTyping();

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
            appendMessage('assistant', data.content, data.tools_used);
        })
        .catch(function(err) {
            appendMessage('error', err.name === 'AbortError'
                ? 'Request timed out.'
                : (err.message || 'An error occurred'));
        })
        .finally(function() {
            sending = false;
            hideTyping();
            inputEl.disabled = false;
            inputEl.focus();
        });
    }

    function appendMessage(role, content, toolsUsed) {
        var div = document.createElement('div');
        div.className = 'ab-msg ab-msg-' + role;

        if (role === 'error') {
            div.innerHTML = '<div class="alert alert-danger py-1 px-2 mb-0 small">' + escapeHtml(content) + '</div>';
        } else if (role === 'user') {
            div.innerHTML = '<div class="ab-bubble-user">' + escapeHtml(content) + '</div>';
        } else {
            var html = '<div class="ab-bubble-assistant">' + renderMarkdown(content) + '</div>';
            if (toolsUsed && toolsUsed.length > 0) {
                html += '<div class="mt-1">';
                toolsUsed.forEach(function(t) {
                    html += '<span class="badge bg-light text-dark border me-1" style="font-size: 0.6rem;">' + escapeHtml(t.replace(/_/g, ' ')) + '</span>';
                });
                html += '</div>';
            }
            div.innerHTML = html;
        }

        messagesEl.appendChild(div);
        scrollToBottom();
    }

    function showEmpty() {
        var el = flyout.querySelector('.ab-empty');
        if (el) el.style.display = '';
    }

    function hideEmpty() {
        var el = flyout.querySelector('.ab-empty');
        if (el) el.style.display = 'none';
    }

    function showTyping() { if (typingEl) typingEl.style.display = ''; }
    function hideTyping() { if (typingEl) typingEl.style.display = 'none'; }
    function scrollToBottom() { messagesEl.scrollTop = messagesEl.scrollHeight; }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function renderMarkdown(text) {
        var html = escapeHtml(text);
        html = html.replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code>$2</code></pre>');
        html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
        html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*([^*]+)\*/g, '<em>$1</em>');
        html = html.replace(/^### (.+)$/gm, '<h6>$1</h6>');
        html = html.replace(/^## (.+)$/gm, '<h5>$1</h5>');
        html = html.replace(/^# (.+)$/gm, '<h4>$1</h4>');
        html = html.replace(/^- (.+)$/gm, '<li>$1</li>');
        html = html.replace(/(<li>.*<\/li>)/gs, '<ul>$1</ul>');
        html = html.replace(/<\/ul>\s*<ul>/g, '');
        html = html.replace(/\n\n/g, '</p><p>');
        html = html.replace(/\n/g, '<br>');
        html = '<p>' + html + '</p>';
        return html;
    }
})();
