<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@hasSection('title')@yield('title') · {{ config('app.name') }}@else{{ config('app.name') }}@endif</title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
    @auth
        <link href="{{ asset('css/sidebar.css') }}" rel="stylesheet">
        <link href="{{ asset('css/command-palette.css') }}" rel="stylesheet">
        @if(\App\Support\AssistantConfig::isEnabled())
        <link href="{{ asset('css/assistant-bubble.css') }}?v={{ filemtime(public_path('css/assistant-bubble.css')) }}" rel="stylesheet">
        @endif
    @endauth
    @stack('styles')
    {{-- Prevent sidebar layout flash on page load --}}
    @auth
    <script>
    if(localStorage.getItem('psa-sidebar-collapsed')==='1'&&window.innerWidth>=992)
        document.documentElement.classList.add('sidebar-collapsed');
    </script>
    @endauth
</head>
<body class="@yield('body-class', 'bg-brand-light')">
    @auth
        @include('components.sidebar')
        <div class="psa-content-wrapper d-flex flex-column min-vh-100 {{ \App\Support\AssistantConfig::isEnabled() ? 'has-assistant-bubble' : '' }}">
            @include('components.topbar')
            <main class="container-fluid py-4 flex-grow-1">
                @if (! empty($storageWarnings))
                    <div class="alert alert-warning d-flex align-items-start" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-2 mt-1"></i>
                        <div>
                            <strong>Storage permission issue detected.</strong>
                            The web server cannot write to: {{ implode(', ', $storageWarnings) }}.
                            This will cause errors. Run: <code>sudo chown -R www-data:www-data {{ base_path('storage') }}</code>
                        </div>
                    </div>
                @endif

                @if (session('success'))
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        {{ session('success') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                @if (session('error'))
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        {{ session('error') }}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                @yield('content')
            </main>
            @include('components.footer')
        </div>

        {{-- Command Palette Modal --}}
        <div id="cmd-palette-backdrop" class="cmd-palette-backdrop" aria-hidden="true">
            <div class="cmd-palette" role="dialog" aria-label="Quick search">
                <div class="cmd-palette-input-wrap">
                    <i class="bi bi-search"></i>
                    <input id="cmd-palette-input" class="cmd-palette-input" type="text"
                           placeholder="Search pages, records, or recent items..."
                           autocomplete="off" spellcheck="false"
                           role="combobox" aria-expanded="true"
                           aria-controls="cmd-palette-results" aria-haspopup="listbox">
                </div>
                <div id="cmd-palette-results" class="cmd-palette-results" role="listbox"></div>
                <div class="cmd-palette-footer">
                    <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate</span>
                    <span><kbd>Enter</kbd> open</span>
                    <span><kbd>Esc</kbd> close</span>
                </div>
            </div>
        </div>

        {{-- AI Assistant Bubble --}}
        @if(\App\Support\AssistantConfig::isEnabled())
        <div id="assistantBubble" class="ab-bubble" title="AI Assistant">
            <i class="bi bi-robot"></i>
        </div>
        <div id="assistantFlyout" class="ab-flyout">
            <div class="ab-header">
                <span class="ab-header-title"><i class="bi bi-robot me-1"></i>AI Assistant</span>
                <button type="button" class="ab-close" title="Close"><i class="bi bi-x-lg"></i></button>
            </div>
            <div class="ab-messages">
                <div class="ab-empty">
                    <i class="bi bi-robot"></i>
                    <small>Ask me anything</small>
                </div>
            </div>
            <div class="ab-typing" style="display: none;">
                <div class="d-flex align-items-center gap-2 text-muted small px-2">
                    <div class="spinner-border spinner-border-sm" style="width: 14px; height: 14px;"></div>
                    <span>Thinking...</span>
                </div>
            </div>
            <div class="ab-input-area">
                <input type="text" class="ab-input" placeholder="Ask a question..." aria-label="Message">
                <button type="button" class="ab-send" title="Send"><i class="bi bi-send-fill"></i></button>
            </div>
        </div>
        @endif
    @endauth

    @guest
        <main class="container-fluid py-4 flex-grow-1">
            @yield('content')
        </main>
    @endguest

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="{{ asset('js/entity-popovers.js') }}?v={{ filemtime(public_path('js/entity-popovers.js')) }}"></script>
    @auth
        <script src="{{ asset('js/sidebar.js') }}?v={{ filemtime(public_path('js/sidebar.js')) }}"></script>
        <script src="{{ asset('js/command-palette.js') }}?v={{ filemtime(public_path('js/command-palette.js')) }}"></script>
        @if(auth()->user()->sipEndpoints()->where('is_active', true)->whereNotNull('sip_username')->exists())
        <script src="{{ asset('js/softphone-parent.js') }}?v={{ filemtime(public_path('js/softphone-parent.js')) }}"></script>
        @endif
        @if(\App\Support\AssistantConfig::isEnabled())
        <script src="{{ asset('js/assistant-bubble.js') }}?v={{ filemtime(public_path('js/assistant-bubble.js')) }}"></script>
        @endif
    @endauth
    @stack('scripts')
</body>
</html>
