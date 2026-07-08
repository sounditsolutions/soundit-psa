<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $endpoint = auth()->user()->sipEndpoints()->where('is_active', true)->first();
    @endphp
    @if($endpoint && $endpoint->sip_username)
        <meta name="plivo-username" content="{{ $endpoint->sip_username }}">
        <meta name="plivo-password" content="{{ $endpoint->sip_password }}">
    @endif
    <title>Softphone</title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="manifest" href="/softphone-manifest.json">
    <meta name="theme-color" content="#0f2440">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/softphone.css') }}?v={{ filemtime(public_path('css/softphone.css')) }}" rel="stylesheet">
</head>
<body style="margin:0;padding:0;overflow:hidden;">
    {{-- Audio elements required by Plivo Browser SDK --}}
    <audio id="remoteAudio" autoplay></audio>
    <audio id="ringbackAudio"></audio>

    <div id="softphone" class="softphone-widget">

        {{-- Panel (always visible in popup window) --}}
        <div id="sp-panel" class="sp-panel">

            {{-- Header --}}
            <div class="sp-header">
                <span class="sp-title">Softphone</span>
                <span id="sp-status-text" class="sp-status">Connecting...</span>
                <button class="sp-header-btn" title="Close" onclick="window.close()">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            {{-- Idle view --}}
            <div id="sp-idle" class="sp-view">
                <div class="sp-number-row">
                    <input type="tel" id="sp-number" class="sp-number-input" placeholder="Enter number..."
                           autocomplete="off" spellcheck="false">
                </div>

                <div class="sp-dialpad">
                    <button class="sp-key" data-digit="1">1<span class="sp-sub">&nbsp;</span></button>
                    <button class="sp-key" data-digit="2">2<span class="sp-sub">ABC</span></button>
                    <button class="sp-key" data-digit="3">3<span class="sp-sub">DEF</span></button>
                    <button class="sp-key" data-digit="4">4<span class="sp-sub">GHI</span></button>
                    <button class="sp-key" data-digit="5">5<span class="sp-sub">JKL</span></button>
                    <button class="sp-key" data-digit="6">6<span class="sp-sub">MNO</span></button>
                    <button class="sp-key" data-digit="7">7<span class="sp-sub">PQRS</span></button>
                    <button class="sp-key" data-digit="8">8<span class="sp-sub">TUV</span></button>
                    <button class="sp-key" data-digit="9">9<span class="sp-sub">WXYZ</span></button>
                    <button class="sp-key" data-digit="*">*<span class="sp-sub">&nbsp;</span></button>
                    <button class="sp-key" data-digit="0">0<span class="sp-sub">+</span></button>
                    <button class="sp-key" data-digit="#">#<span class="sp-sub">&nbsp;</span></button>
                </div>

                <div class="sp-actions">
                    <button id="sp-call-btn" class="sp-call-btn" title="Call">
                        <i class="bi bi-telephone-fill"></i>
                    </button>
                </div>
            </div>

            {{-- Incoming call view --}}
            <div id="sp-incoming" class="sp-view" style="display:none;">
                <div class="sp-incoming-info">
                    <div class="sp-pulse-ring"></div>
                    <i class="bi bi-telephone-inbound-fill sp-incoming-icon"></i>
                    <div id="sp-incoming-number" class="sp-caller-number"></div>
                    <div id="sp-incoming-name" class="sp-caller-name"></div>
                    <div id="sp-incoming-client" class="sp-caller-client"></div>
                    <div id="sp-incoming-context" class="sp-context-panel"></div>
                </div>
                <div class="sp-incoming-actions">
                    <button id="sp-answer-btn" class="sp-answer-btn" title="Answer">
                        <i class="bi bi-telephone-fill"></i>
                    </button>
                    <button id="sp-decline-btn" class="sp-decline-btn" title="Decline">
                        <i class="bi bi-telephone-x-fill"></i>
                    </button>
                </div>
            </div>

            {{-- Active call view --}}
            <div id="sp-active" class="sp-view" style="display:none;">
                <div class="sp-active-info">
                    <div id="sp-active-number" class="sp-caller-number"></div>
                    <div id="sp-active-name" class="sp-caller-name"></div>
                    <div id="sp-active-client" class="sp-caller-client"></div>
                    <div id="sp-active-context" class="sp-context-panel"></div>
                    <div id="sp-timer" class="sp-timer">00:00</div>
                    <div id="sp-hold-label" class="sp-hold-label" style="display:none;">ON HOLD</div>
                </div>

                <div class="sp-controls">
                    <button id="sp-mute-btn" class="sp-control-btn" title="Mute">
                        <i class="bi bi-mic-fill"></i>
                        <span>Mute</span>
                    </button>
                    <button id="sp-hold-btn" class="sp-control-btn" title="Hold">
                        <i class="bi bi-pause-fill"></i>
                        <span>Hold</span>
                    </button>
                    <button id="sp-dtmf-btn" class="sp-control-btn" title="Keypad">
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                        <span>Keypad</span>
                    </button>
                    <button id="sp-hangup-btn" class="sp-hangup-btn" title="Hang up">
                        <i class="bi bi-telephone-x-fill"></i>
                    </button>
                </div>

                {{-- In-call DTMF pad (toggled via Keypad button) --}}
                <div id="sp-dtmf-pad" class="sp-dialpad sp-dialpad-small" style="display:none;">
                    <button class="sp-key sp-key-sm" data-digit="1">1</button>
                    <button class="sp-key sp-key-sm" data-digit="2">2</button>
                    <button class="sp-key sp-key-sm" data-digit="3">3</button>
                    <button class="sp-key sp-key-sm" data-digit="4">4</button>
                    <button class="sp-key sp-key-sm" data-digit="5">5</button>
                    <button class="sp-key sp-key-sm" data-digit="6">6</button>
                    <button class="sp-key sp-key-sm" data-digit="7">7</button>
                    <button class="sp-key sp-key-sm" data-digit="8">8</button>
                    <button class="sp-key sp-key-sm" data-digit="9">9</button>
                    <button class="sp-key sp-key-sm" data-digit="*">*</button>
                    <button class="sp-key sp-key-sm" data-digit="0">0</button>
                    <button class="sp-key sp-key-sm" data-digit="#">#</button>
                </div>
            </div>

        </div>
    </div>

    <script src="https://cdn.plivo.com/sdk/browser/v2/plivo.min.js"></script>
    <script src="{{ asset('js/softphone.js') }}?v={{ filemtime(public_path('js/softphone.js')) }}"></script>
</body>
</html>
