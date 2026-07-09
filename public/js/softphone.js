(function () {
    'use strict';

    // ── DOM refs ──
    var panel = document.getElementById('sp-panel');
    var statusText = document.getElementById('sp-status-text');
    var numberInput = document.getElementById('sp-number');
    var callBtn = document.getElementById('sp-call-btn');

    // Views
    var idleView = document.getElementById('sp-idle');
    var incomingView = document.getElementById('sp-incoming');
    var activeView = document.getElementById('sp-active');

    // Incoming
    var incomingNumber = document.getElementById('sp-incoming-number');
    var incomingName = document.getElementById('sp-incoming-name');
    var incomingClient = document.getElementById('sp-incoming-client');
    var incomingContext = document.getElementById('sp-incoming-context');
    var answerBtn = document.getElementById('sp-answer-btn');
    var declineBtn = document.getElementById('sp-decline-btn');

    // Active call
    var activeNumber = document.getElementById('sp-active-number');
    var activeName = document.getElementById('sp-active-name');
    var activeClient = document.getElementById('sp-active-client');
    var activeContext = document.getElementById('sp-active-context');
    var timerEl = document.getElementById('sp-timer');
    var holdLabel = document.getElementById('sp-hold-label');
    var muteBtn = document.getElementById('sp-mute-btn');
    var holdBtn = document.getElementById('sp-hold-btn');
    var dtmfBtn = document.getElementById('sp-dtmf-btn');
    var dtmfPad = document.getElementById('sp-dtmf-pad');
    var hangupBtn = document.getElementById('sp-hangup-btn');

    // ── State ──
    var plivoSdk = null;
    var isRegistered = false;
    var isMuted = false;
    var isHeld = false;
    var holdPending = false;
    var currentCallUuid = null;
    var currentCallerNumber = '';
    var timerInterval = null;
    var timerSeconds = 0;
    var callerLookupInterval = null;
    var inCall = false;

    // ── Credentials ──
    var usernameMeta = document.querySelector('meta[name="plivo-username"]');
    var passwordMeta = document.querySelector('meta[name="plivo-password"]');
    var username = usernameMeta ? usernameMeta.content : null;
    var password = passwordMeta ? passwordMeta.content : null;

    // CSRF token for the server-side hold/unhold endpoints
    var csrfMeta = document.querySelector('meta[name="csrf-token"]');
    var csrfToken = csrfMeta ? csrfMeta.content : '';

    // ── BroadcastChannel (popup ↔ parent tabs communication) ──
    var channel = new BroadcastChannel('psa-softphone');
    channel.addEventListener('message', function (e) {
        var data = e.data;
        if (!data || !data.event) return;
        if (data.event === 'dial' && data.number) {
            numberInput.value = data.number;
            showView('idle');
            makeCall(data.number);
        }
    });

    // ── UI event handlers ──

    // Dial pad keys (idle + DTMF)
    document.querySelectorAll('.sp-key').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var digit = this.getAttribute('data-digit');
            if (inCall) {
                sendDtmf(digit);
            } else {
                numberInput.value += digit;
                numberInput.focus();
            }
        });
    });

    // Call button
    callBtn.addEventListener('click', function () {
        var number = numberInput.value.replace(/[^\d+*#]/g, '');
        if (number.length >= 3) {
            makeCall(number);
        }
    });

    // Enter to call
    numberInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            callBtn.click();
        }
    });

    // Answer / decline
    answerBtn.addEventListener('click', function () {
        if (plivoSdk) plivoSdk.client.answer();
    });

    declineBtn.addEventListener('click', function () {
        if (plivoSdk) plivoSdk.client.reject();
        resetToIdle();
    });

    // Mute
    muteBtn.addEventListener('click', function () {
        if (!plivoSdk) return;
        if (isMuted) {
            plivoSdk.client.unmute();
            isMuted = false;
            muteBtn.classList.remove('active');
            muteBtn.querySelector('i').className = 'bi bi-mic-fill';
        } else {
            plivoSdk.client.mute();
            isMuted = true;
            muteBtn.classList.add('active');
            muteBtn.querySelector('i').className = 'bi bi-mic-mute-fill';
        }
    });

    // Hold / unhold — server-side call control via the Plivo Play API. The
    // browser SDK has no hold primitive, so we POST the call UUID to the app and
    // it plays looping hold music to the remote caller (see softphone hold).
    holdBtn.addEventListener('click', function () {
        if (!inCall || !currentCallUuid || holdPending) return;

        var goingOnHold = !isHeld;
        holdPending = true;
        holdBtn.disabled = true;

        fetch(goingOnHold ? '/softphone/hold' : '/softphone/unhold', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify({ call_uuid: currentCallUuid }),
        })
            .then(function (r) { return r.json().catch(function () { return {}; }); })
            .then(function (data) {
                if (data && data.success) {
                    setHeld(goingOnHold);
                } else {
                    showError(goingOnHold ? 'Could not hold call' : 'Could not resume call');
                }
            })
            .catch(function () {
                showError(goingOnHold ? 'Could not hold call' : 'Could not resume call');
            })
            .finally(function () {
                holdPending = false;
                holdBtn.disabled = false;
            });
    });

    function setHeld(held) {
        isHeld = held;
        holdBtn.classList.toggle('active', held);
        holdBtn.querySelector('i').className = held ? 'bi bi-play-fill' : 'bi bi-pause-fill';
        var label = holdBtn.querySelector('span');
        if (label) label.textContent = held ? 'Resume' : 'Hold';
        holdLabel.style.display = held ? '' : 'none';
    }

    // DTMF toggle — swap context panel and keypad visibility
    dtmfBtn.addEventListener('click', function () {
        var showing = dtmfPad.style.display !== 'none';
        dtmfPad.style.display = showing ? 'none' : '';
        activeContext.style.display = showing ? '' : 'none';
        dtmfBtn.classList.toggle('active', !showing);
    });

    // Hangup
    hangupBtn.addEventListener('click', function () {
        if (plivoSdk) plivoSdk.client.hangup();
    });

    // Notify all tabs when popup closes
    window.addEventListener('pagehide', function () {
        notifyParent({ event: 'closed' });
    });

    // ── Request notification permission (for PWA standalone mode) ──
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission();
    }

    // ── SDK init ──

    if (!username || !password) {
        setStatus('No credentials', 'error');
        callBtn.disabled = true;
        console.warn('[Softphone] No credentials found in meta tags');
    } else {
        initPlivo();
    }

    function initPlivo() {
        try {
            plivoSdk = new window.Plivo({
                debug: 'OFF',
                permOnClick: true,
                enableTracking: true,
                closeProtection: true,
            });
        } catch (e) {
            setStatus('SDK error', 'error');
            callBtn.disabled = true;
            console.error('[Softphone] SDK init failed:', e);
            return;
        }

        console.log('[Softphone] SDK initialized, logging in as:', username);

        plivoSdk.client.on('onLogin', function () {
            isRegistered = true;
            setStatus('Ready', 'connected');
            callBtn.disabled = false;
            console.log('[Softphone] Logged in successfully');
            notifyParent({ event: 'registered', status: true });
            notifyParent({ event: 'ready' });
        });

        plivoSdk.client.on('onLoginFailed', function (cause) {
            isRegistered = false;
            setStatus('Offline', 'error');
            callBtn.disabled = true;
            console.error('[Softphone] Login failed:', cause);
            notifyParent({ event: 'registered', status: false });
        });

        plivoSdk.client.on('onIncomingCall', function (callerName, extraHeaders) {
            var callInfo = plivoSdk.client.getCallUUID();
            currentCallUuid = callInfo;

            var caller = callerName || 'Unknown';
            currentCallerNumber = caller;
            incomingNumber.textContent = formatPhone(caller);
            incomingName.textContent = '';
            incomingClient.textContent = '';

            showView('incoming');
            window.focus();
            notifyParent({ event: 'incoming', caller: caller, callerName: callerName });

            startCallerLookup();
        });

        plivoSdk.client.on('onIncomingCallCanceled', function () {
            resetToIdle();
            notifyParent({ event: 'callEnded' });
        });

        plivoSdk.client.on('onCallRemoteRinging', function (callInfo) {
            // Outbound call ringing
        });

        plivoSdk.client.on('onCallAnswered', function () {
            currentCallUuid = plivoSdk.client.getCallUUID();
            inCall = true;
            showView('active');
            startTimer();
            notifyParent({ event: 'callStarted' });
        });

        plivoSdk.client.on('onCallTerminated', function () {
            inCall = false;
            resetToIdle();
            notifyParent({ event: 'callEnded' });
        });

        plivoSdk.client.on('onCallFailed', function (cause) {
            console.error('[Softphone] Call failed:', cause);
            showError('Call failed');
            resetToIdle();
            notifyParent({ event: 'callEnded' });
        });

        plivoSdk.client.on('onMediaPermission', function (result) {
            if (!result.status) {
                setStatus('Mic denied', 'error');
                showError('Microphone access required');
            }
        });

        plivoSdk.client.login(username, password);
    }

    // ── Helpers ──

    function makeCall(number) {
        if (!isRegistered || !plivoSdk) return;
        var cleaned = number.replace(/[^\d+]/g, '');
        if (cleaned.length < 3) return;

        currentCallerNumber = cleaned;
        activeNumber.textContent = formatPhone(cleaned);
        activeName.textContent = '';
        activeClient.textContent = '';

        var extraHeaders = { 'X-PH-ForwardTo': cleaned };
        plivoSdk.client.call(cleaned, extraHeaders);

        inCall = true;
        showView('active');
        startTimer();
    }

    function sendDtmf(digit) {
        if (plivoSdk && inCall) {
            plivoSdk.client.sendDtmf(digit);
        }
    }

    function startTimer() {
        timerSeconds = 0;
        timerEl.textContent = '00:00';
        clearInterval(timerInterval);
        timerInterval = setInterval(function () {
            timerSeconds++;
            var m = Math.floor(timerSeconds / 60);
            var s = timerSeconds % 60;
            timerEl.textContent =
                (m < 10 ? '0' + m : m) + ':' + (s < 10 ? '0' + s : s);
        }, 1000);
    }

    function stopTimer() {
        clearInterval(timerInterval);
        timerInterval = null;
    }

    function startCallerLookup() {
        var retries = 0;
        var identified = false;
        clearInterval(callerLookupInterval);

        function doLookup() {
            retries++;
            if (retries > 5) {
                clearInterval(callerLookupInterval);
                return;
            }
            fetch('/calls/latest', {
                headers: { 'Accept': 'application/json' },
            })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.call) {
                        if (!identified) {
                            identified = true;
                            notifyParent({
                                event: 'callerIdentified',
                                call_url: data.call.call_url,
                                caller: data.call.from_formatted,
                                client_name: data.call.client_name,
                                person_name: data.call.person_name,
                            });
                        }

                        // Render context panel in whichever view is active
                        renderContextPanel(incomingContext, data.call);
                        renderContextPanel(activeContext, data.call);

                        if (data.call.client_name) {
                            var nameTarget = incomingView.style.display !== 'none' ? incomingName : activeName;
                            var clientTarget = incomingView.style.display !== 'none' ? incomingClient : activeClient;
                            nameTarget.textContent = data.call.person_name || '';
                            clientTarget.textContent = data.call.client_name || '';
                            clearInterval(callerLookupInterval);
                        }
                    }
                })
                .catch(function () {});
        }

        // First attempt after 500ms (webhook creates record before SDK fires)
        setTimeout(doLookup, 500);
        // Then poll every 2s for retries
        callerLookupInterval = setInterval(doLookup, 2000);
    }

    function renderContextPanel(container, call) {
        if (!container) return;
        var html = '';

        // Caller identity links
        if (call.person_name && call.person_url) {
            html += '<a href="' + esc(call.person_url) + '" target="_blank" class="sp-context-link">' + esc(call.person_name) + '</a>';
        }
        if (call.client_name && call.client_url) {
            if (html) html += ' <span style="color:var(--sp-gray);font-size:0.75rem">&mdash;</span> ';
            html += '<a href="' + esc(call.client_url) + '" target="_blank" class="sp-context-link">' + esc(call.client_name) + '</a>';
        } else if (call.client_name) {
            if (html) html += ' <span style="color:var(--sp-gray);font-size:0.75rem">&mdash;</span> ';
            html += '<span style="font-size:0.8rem;color:var(--sp-navy)">' + esc(call.client_name) + '</span>';
        }

        // Call detail link
        if (call.call_url) {
            html += '<br><a href="' + esc(call.call_url) + '" target="_blank" class="sp-context-call-link"><i class="bi bi-box-arrow-up-right"></i> View Call</a>';
        }

        // Recent tickets
        if (call.recent_tickets && call.recent_tickets.length > 0) {
            html += '<div class="sp-section-label">Open Tickets</div>';
            html += '<ul class="sp-ticket-list">';
            for (var i = 0; i < call.recent_tickets.length; i++) {
                var t = call.recent_tickets[i];
                html += '<a href="' + esc(t.url) + '" target="_blank" class="sp-ticket-item">' +
                    '<span class="sp-ticket-id">' + esc(t.display_id) + '</span>' +
                    '<span class="sp-ticket-subject">' + esc(t.subject) + '</span>' +
                    '<span class="sp-ticket-badge ' + esc(t.status_badge) + '">' + esc(t.status) + '</span>' +
                    '</a>';
            }
            html += '</ul>';
        }

        // Call history (shown when caller is unresolved)
        if (call.call_history && call.call_history.length > 0) {
            html += '<div class="sp-section-label">Previous Calls</div>';
            html += '<ul class="sp-ticket-list">';
            for (var i = 0; i < call.call_history.length; i++) {
                var h = call.call_history[i];
                var label = h.client_name || 'Unresolved';
                if (h.person_name) label += ' — ' + h.person_name;
                var meta = h.started_at;
                if (h.answered_by) meta += ' — ' + h.answered_by;
                if (h.duration) {
                    var mins = Math.floor(h.duration / 60);
                    var secs = h.duration % 60;
                    meta += ' — ' + mins + ':' + (secs < 10 ? '0' : '') + secs;
                }
                html += '<a href="' + esc(h.call_url) + '" target="_blank" class="sp-ticket-item">' +
                    '<span class="sp-ticket-id"><i class="bi bi-telephone-' + (h.direction === 'inbound' ? 'inbound' : 'outbound') + '"></i></span>' +
                    '<span class="sp-ticket-subject">' + esc(label) + '</span>' +
                    '<span style="font-size:0.65rem;color:var(--sp-gray)">' + esc(meta) + '</span>' +
                    '</a>';
                if (h.ticket_id) {
                    html += '<a href="' + esc(h.ticket_url) + '" target="_blank" class="sp-ticket-item" style="padding-left:24px;font-size:0.7rem">' +
                        '<span class="sp-ticket-id">' + esc(h.ticket_id) + '</span>' +
                        '</a>';
                }
            }
            html += '</ul>';
        } else if (!call.client_name && call.call_history && call.call_history.length === 0) {
            html += '<div class="sp-section-label" style="color:var(--sp-gray)"><i class="bi bi-clock-history"></i> No previous calls from this number</div>';
        }

        // Activity stream
        if (call.activity && call.activity.length > 0) {
            html += '<div class="sp-section-label">Recent Activity</div>';
            html += '<div class="sp-activity-scroll">';
            for (var i = 0; i < call.activity.length; i++) {
                var a = call.activity[i];
                var hasBody = a.body && a.body.length > 0;
                var isExpandable = hasBody && a.body !== a.preview;
                html += '<div class="sp-activity-item' + (isExpandable ? ' sp-expandable' : '') + '">';
                html += '<div class="sp-activity-header">';
                html += '<i class="bi ' + esc(a.icon) + ' sp-activity-icon"></i>';
                html += '<div class="sp-activity-content">';
                if (a.who) {
                    html += '<span class="sp-activity-who">' + esc(a.who) + '</span> ';
                }
                if (a.ticket_id) {
                    html += '<span class="sp-activity-ticket">' + esc(a.ticket_id) + '</span> ';
                }
                if (a.label) {
                    html += '<span class="sp-activity-label">' + esc(a.label) + '</span>';
                }
                if (a.preview) {
                    html += '<div class="sp-activity-preview">' + esc(a.preview) + '</div>';
                }
                html += '</div>';
                html += '<span class="sp-activity-time">' + esc(a.time) + '</span>';
                if (isExpandable) {
                    html += '<i class="bi bi-chevron-down sp-activity-chevron"></i>';
                } else if (a.url) {
                    html += '<a href="' + esc(a.url) + '" target="_blank" class="sp-activity-open"><i class="bi bi-box-arrow-up-right"></i></a>';
                }
                html += '</div>';
                if (hasBody) {
                    html += '<div class="sp-activity-body" style="display:none;">';
                    html += '<div class="sp-activity-body-text">' + esc(a.body) + '</div>';
                    if (a.url) {
                        html += '<a href="' + esc(a.url) + '" target="_blank" class="sp-context-call-link"><i class="bi bi-box-arrow-up-right"></i> Open</a>';
                    }
                    html += '</div>';
                }
                html += '</div>';
            }
            html += '</div>';
        }

        if (!call.recent_tickets?.length && !call.activity?.length && call.client_name) {
            html += '<div class="sp-no-tickets">No recent activity</div>';
        }

        container.innerHTML = html;

        // Bind expand/collapse on expandable activity items
        container.querySelectorAll('.sp-expandable .sp-activity-header').forEach(function (header) {
            header.addEventListener('click', function (e) {
                if (e.target.closest('a')) return; // don't toggle when clicking links
                var item = header.closest('.sp-activity-item');
                var body = item.querySelector('.sp-activity-body');
                var chevron = header.querySelector('.sp-activity-chevron');
                var showing = body.style.display !== 'none';
                body.style.display = showing ? 'none' : '';
                if (chevron) {
                    chevron.className = 'bi ' + (showing ? 'bi-chevron-down' : 'bi-chevron-up') + ' sp-activity-chevron';
                }
                item.classList.toggle('sp-expanded', !showing);
            });
        });
    }

    function esc(str) {
        if (!str) return '';
        var el = document.createElement('span');
        el.textContent = str;
        return el.innerHTML;
    }

    function clearContextPanels() {
        if (incomingContext) incomingContext.innerHTML = '';
        if (activeContext) activeContext.innerHTML = '';
    }

    function resetToIdle() {
        inCall = false;
        isMuted = false;
        isHeld = false;
        holdPending = false;
        currentCallUuid = null;
        currentCallerNumber = '';
        stopTimer();
        clearInterval(callerLookupInterval);
        clearContextPanels();
        numberInput.value = '';
        muteBtn.classList.remove('active');
        muteBtn.querySelector('i').className = 'bi bi-mic-fill';
        holdBtn.disabled = false;
        holdBtn.classList.remove('active');
        holdBtn.querySelector('i').className = 'bi bi-pause-fill';
        var holdSpan = holdBtn.querySelector('span');
        if (holdSpan) holdSpan.textContent = 'Hold';
        holdLabel.style.display = 'none';
        dtmfBtn.classList.remove('active');
        dtmfPad.style.display = 'none';
        showView('idle');
    }

    function showView(name) {
        idleView.style.display = name === 'idle' ? '' : 'none';
        incomingView.style.display = name === 'incoming' ? '' : 'none';
        activeView.style.display = name === 'active' ? '' : 'none';
    }

    function setStatus(text, cls) {
        statusText.textContent = text;
        statusText.className = 'sp-status';
        if (cls) statusText.classList.add(cls);
    }

    function showError(msg) {
        var el = document.createElement('div');
        el.className = 'sp-error';
        el.textContent = msg;
        panel.appendChild(el);
        setTimeout(function () { el.remove(); }, 3000);
    }

    function notifyParent(data) {
        channel.postMessage(data);
    }

    function formatPhone(number) {
        var d = (number || '').replace(/\D/g, '');
        if (d.length === 11 && d[0] === '1') d = d.substring(1);
        if (d.length === 10) {
            return '(' + d.substring(0, 3) + ') ' + d.substring(3, 6) + '-' + d.substring(6);
        }
        return number;
    }
})();
