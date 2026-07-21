<header class="psa-topbar">
    {{-- Mobile hamburger (hidden on desktop) --}}
    <button id="sidebar-mobile-toggle" class="btn btn-link text-white d-lg-none me-2 p-0"
            type="button" title="Toggle menu" aria-label="Toggle navigation menu">
        <i class="bi bi-list fs-4"></i>
    </button>

    {{-- Page title (auto-derived from @section('title')) --}}
    @php $pageTitle = trim($__env->yieldContent('title')); @endphp
    @if($pageTitle)
    <span class="text-white fw-semibold text-truncate" style="font-size: 0.95rem; max-width: 300px;">
        {{ $pageTitle }}
    </span>
    @endif

    {{-- Spacer --}}
    <div class="flex-grow-1"></div>

    {{-- Right side items --}}
    <div class="d-flex align-items-center gap-2">
        {{-- Quick search / command palette --}}
        <button type="button" class="cmd-palette-trigger" data-cmd-palette title="Quick search (Ctrl+K)">
            <i class="bi bi-search"></i>
            <span class="d-none d-sm-inline">Search</span>
            <kbd class="d-none d-lg-inline">Ctrl+K</kbd>
        </button>

        {{-- AI Assistant --}}
        @if(\App\Support\AssistantConfig::isEnabled())
        <button type="button" class="assistant-trigger" data-assistant-toggle title="AI Assistant">
            <i class="bi bi-robot"></i>
            <span class="d-none d-sm-inline">Ask AI</span>
        </button>
        @elseif(\App\Support\AssistantConfig::shouldShowDisabledNotice())
        {{-- psa-322qo / psa-uw2o.12: the topbar is global chrome on EVERY page, so
             it is the most reachable place someone looks for the Assistant — leaving
             it simply absent is the silent disable Charlie ruled against.

             The predicate and the wording both come from AssistantConfig, which is
             the one place that knows the difference between "never wanted an
             Assistant" (say nothing), "cannot run here" and "switched off"
             (psa-uw2o.13 F2 — this site was right and the other two had drifted).

             psa-uw2o.13 F3: this was a DISABLED <button>, whose explanation lived
             only in a title. A disabled button is not keyboard focusable, so that
             text was unreachable without a mouse. It is a <span> now — it was never
             a control, and saying so costs nothing and makes the sentence readable.

             psa-uw2o.20: the visible label was then the hard-coded string "AI off",
             wrapped in `d-none d-sm-inline`. Three consequences, and the previous
             comment here defended all of them as deliberate:
               - both ineligible states rendered the SAME two words, so the
                 three-state model AssistantConfig exists to express was invisible
                 on the one surface that appears on every page;
               - below `sm` even that disappeared, leaving a bare robot icon on
                 exactly the touch devices that cannot hover a tooltip;
               - the state and the recovery path existed only in a `title` and a
                 `visually-hidden` span, so a sighted keyboard or touch user was
                 told LESS than a screen-reader user.

             The instinct behind it was sound and is kept: a full sentence on
             global chrome would nag every screen in the product. So chrome gets a
             chrome-sized copy — state in two words, plus a pointer naming where
             the fix lives — held at ALL widths, while the ticket sites (where
             someone is actually reaching for the Assistant) spell out which
             switch. Both strings come from AssistantConfig for the same reason
             the sentences do: three views restating the same copy is what caused
             the F2 drift.

             The visible run is aria-hidden and the full sentence sits in one
             visually-hidden span, so the accessibility tree gets the long form
             exactly ONCE. There is deliberately no `title`: it duplicated that
             sentence a third time, it is not reachable by keyboard or touch
             anyway, and screen readers commonly announce it as a description —
             which is the same sentence read out twice. --}}
        <span class="assistant-status-off" data-assistant-disabled-notice="topbar">
            <i class="bi bi-robot" aria-hidden="true"></i>
            <span aria-hidden="true">{{ \App\Support\AssistantConfig::disabledChromeLabel() }}</span>
            <span class="assistant-status-path" aria-hidden="true">
                <i class="bi bi-gear" aria-hidden="true"></i>{{ \App\Support\AssistantConfig::disabledChromePointer() }}
            </span>
            <span class="visually-hidden">
                {{ \App\Support\AssistantConfig::disabledSummary() }}.
                {{ \App\Support\AssistantConfig::disabledRecovery() }}
            </span>
        </span>
        @endif

        {{-- Softphone button (conditional) --}}
        @if(auth()->user()->sipEndpoints()->where('is_active', true)->whereNotNull('sip_username')->exists())
        <button id="open-softphone" class="btn btn-link text-white p-1" type="button" title="Open Softphone">
            <i class="bi bi-telephone-fill"></i>
            <span id="softphone-status" class="d-inline-block rounded-circle ms-1"
                  style="width:8px;height:8px;background:#6b7280;vertical-align:middle;"></span>
        </button>
        @endif

        {{-- User dropdown --}}
        <div class="dropdown">
            <a class="d-flex align-items-center text-white text-decoration-none dropdown-toggle"
               href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <x-avatar :user="auth()->user()" :size="28" class="me-1" />
                <span class="d-none d-md-inline">{{ auth()->user()->name }}</span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end">
                <li>
                    <a class="dropdown-item" href="{{ route('preferences.edit') }}">
                        <i class="bi bi-gear me-2"></i>Preferences
                    </a>
                </li>
                <li>
                    @php $__updateCount = \Illuminate\Support\Facades\Cache::get('psa_version_updates')['commits_behind'] ?? 0; @endphp
                    <a class="dropdown-item {{ request()->routeIs('about') ? 'active' : '' }}"
                       href="{{ route('about') }}">
                        <i class="bi bi-info-circle me-2"></i>About
                        <span id="update-badge" class="badge bg-warning text-dark rounded-pill ms-1"
                              style="font-size: 0.65rem;{{ $__updateCount > 0 ? '' : ' display: none;' }}">{{ $__updateCount }}</span>
                    </a>
                </li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dropdown-item">
                            <i class="bi bi-box-arrow-right me-2"></i>Logout
                        </button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
