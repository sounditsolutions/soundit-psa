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
        @elseif(\App\Support\AiConfig::isConfigured())
        {{-- psa-322qo / psa-uw2o.12: the topbar is global chrome on EVERY page, so
             it is the most reachable place someone looks for the Assistant — leaving
             it simply absent is the silent disable Charlie ruled against.

             Gated on an AI provider actually being configured, so a deployment that
             never wanted an assistant sees nothing at all. A quiet disabled control
             rather than a banner: it stays silent until someone reaches for it,
             where a page-width banner would demand attention on every page. --}}
        <button type="button" class="assistant-trigger opacity-50" disabled
                title="AI Assistant is disabled — turn it on in Settings &rsaquo; Integrations">
            <i class="bi bi-robot"></i>
            <span class="d-none d-sm-inline">AI off</span>
        </button>
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
