(function () {
    'use strict';

    var statusDot = document.getElementById('softphone-status');
    var openBtn = document.getElementById('open-softphone');
    var softphonePopup = null;
    var activeCall = false;
    var sdkReady = false;
    var pendingDial = null;
    var closeCheckInterval = null;
    var pendingCallUrl = null;

    // ── BroadcastChannel (same-origin, syncs all open PSA tabs) ──
    var channel = new BroadcastChannel('psa-softphone');

    channel.addEventListener('message', function (e) {
        var data = e.data;
        if (!data || !data.event) return;

        switch (data.event) {
            case 'registered':
                if (statusDot) {
                    statusDot.style.background = data.status ? '#16a34a' : '#dc2626';
                    statusDot.title = data.status ? 'Softphone connected' : 'Softphone offline';
                }
                break;

            case 'ready':
                sdkReady = true;
                // Send any queued dial command
                if (pendingDial) {
                    channel.postMessage({ event: 'dial', number: pendingDial });
                    pendingDial = null;
                }
                break;

            case 'incoming':
                activeCall = true;
                pendingCallUrl = null;
                // Focus the softphone popup from the parent (browsers allow this)
                if (softphonePopup && !softphonePopup.closed) {
                    softphonePopup.focus();
                }
                showCallToast('Incoming call from ' + (data.caller || 'Unknown'), null);
                showBrowserNotification('Incoming Call', data.caller || 'Unknown', null);
                break;

            case 'callerIdentified':
                pendingCallUrl = data.call_url || null;
                var label = (data.person_name || data.caller || 'Unknown') +
                    (data.client_name ? ' — ' + data.client_name : '');
                showCallToast(label, pendingCallUrl);
                showBrowserNotification('Incoming Call', label, pendingCallUrl);
                break;

            case 'callStarted':
                activeCall = true;
                break;

            case 'callEnded':
                activeCall = false;
                pendingCallUrl = null;
                dismissCallToast();
                break;

            case 'closed':
                softphonePopup = null;
                activeCall = false;
                sdkReady = false;
                if (statusDot) {
                    statusDot.style.background = '#6b7280';
                    statusDot.title = 'Softphone closed';
                }
                break;
        }
    });

    // ── Open softphone popup ──
    function openSoftphone() {
        // Request notification permission on user gesture
        if ('Notification' in window && Notification.permission === 'default') {
            Notification.requestPermission();
        }

        // If popup already open, just focus it
        if (softphonePopup && !softphonePopup.closed) {
            softphonePopup.focus();
            return;
        }

        // Position bottom-right of screen
        var w = 320;
        var h = 520;
        var left = screen.availWidth - w - 20;
        var top = screen.availHeight - h - 60;

        softphonePopup = window.open(
            '/softphone',
            'psa-softphone',
            'width=' + w + ',height=' + h + ',left=' + left + ',top=' + top +
            ',menubar=no,toolbar=no,location=no,status=no,resizable=yes'
        );

        if (!softphonePopup) {
            showToast('Popup blocked. Allow popups from your-psa-domain in your browser settings.');
            return;
        }

        sdkReady = false;
        startCloseCheck();
    }

    // ── Nav button click ──
    if (openBtn) {
        openBtn.addEventListener('click', function (e) {
            e.preventDefault();
            openSoftphone();
        });
    }

    // ── Click-to-call: intercept data-phone links ──
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-phone]');
        if (!el) return;
        e.preventDefault();
        var number = el.getAttribute('data-phone');
        if (!number) return;

        // If popup is open and SDK ready, dial immediately
        if (softphonePopup && !softphonePopup.closed && sdkReady) {
            channel.postMessage({ event: 'dial', number: number });
            softphonePopup.focus();
            return;
        }

        // Open popup and queue the dial command
        pendingDial = number;
        openSoftphone();

        // Timeout: if SDK doesn't become ready within 10s, clear pending dial
        setTimeout(function () {
            if (pendingDial) {
                pendingDial = null;
                showToast('Softphone failed to connect. Try again.');
            }
        }, 10000);
    });

    // ── Close detection: poll popup.closed ──
    function startCloseCheck() {
        clearInterval(closeCheckInterval);
        closeCheckInterval = setInterval(function () {
            if (!softphonePopup || softphonePopup.closed) {
                clearInterval(closeCheckInterval);
                closeCheckInterval = null;
                softphonePopup = null;
                activeCall = false;
                sdkReady = false;
                if (statusDot) {
                    statusDot.style.background = '#6b7280';
                    statusDot.title = 'Softphone closed';
                }
            }
        }, 3000);
    }

    // ── beforeunload warning during active call ──
    window.addEventListener('beforeunload', function (e) {
        if (activeCall) {
            e.preventDefault();
            e.returnValue = 'You have an active call. Leaving will hang up.';
            return e.returnValue;
        }
    });

    // ── Browser notifications for incoming calls ──
    function showBrowserNotification(title, body, popUrl) {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        try {
            var n = new Notification(title, {
                body: body,
                icon: '/favicon.ico',
                tag: 'incoming-call',
                requireInteraction: true,
            });
            n.onclick = function () {
                if (popUrl) {
                    window.open(popUrl, '_blank');
                } else {
                    window.focus();
                }
                n.close();
            };
            // Auto-close after 30s
            setTimeout(function () { n.close(); }, 30000);
        } catch (e) {}
    }

    // ── In-page call toast ──
    var callToastEl = null;

    function showCallToast(message, popUrl) {
        dismissCallToast();
        var container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            container.style.zIndex = '1090';
            document.body.appendChild(container);
        }

        var viewLink = popUrl
            ? '<a href="' + popUrl + '" target="_blank" class="btn btn-sm btn-light ms-2">View</a>'
            : '<div class="spinner-border spinner-border-sm ms-2" role="status"></div>';

        callToastEl = document.createElement('div');
        callToastEl.className = 'toast align-items-center text-bg-primary border-0';
        callToastEl.setAttribute('role', 'alert');
        callToastEl.innerHTML =
            '<div class="d-flex align-items-center">' +
                '<div class="toast-body">' +
                    '<i class="bi bi-telephone-inbound me-2"></i>' + message +
                '</div>' +
                viewLink +
                '<button type="button" class="btn-close btn-close-white me-2 ms-1" data-bs-dismiss="toast"></button>' +
            '</div>';

        container.appendChild(callToastEl);
        var bsToast = new bootstrap.Toast(callToastEl, { autohide: false });
        bsToast.show();
    }

    function dismissCallToast() {
        if (callToastEl) {
            var bsToast = bootstrap.Toast.getInstance(callToastEl);
            if (bsToast) bsToast.hide();
            callToastEl.remove();
            callToastEl = null;
        }
    }

    // ── Bootstrap toast for errors ──
    function showToast(message) {
        // Create a simple Bootstrap toast
        var container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
            container.style.zIndex = '1090';
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.className = 'toast align-items-center text-bg-danger border-0';
        toast.setAttribute('role', 'alert');
        toast.innerHTML =
            '<div class="d-flex">' +
                '<div class="toast-body">' + message + '</div>' +
                '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>' +
            '</div>';

        container.appendChild(toast);
        var bsToast = new bootstrap.Toast(toast, { delay: 5000 });
        bsToast.show();
        toast.addEventListener('hidden.bs.toast', function () { toast.remove(); });
    }
})();
