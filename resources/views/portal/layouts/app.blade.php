<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', App\Support\PortalConfig::companyName() . ' Portal')</title>
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-sRIl4kxILFvY47J16cr9ZwB07vP4J8+LH7qKQnuqkuIAvNWLzeN8tE5YBujZqJLB" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="{{ asset('css/app.css') }}" rel="stylesheet">
    <link href="{{ asset('css/portal.css') }}" rel="stylesheet">
    @stack('styles')
</head>
<body class="bg-brand-light d-flex flex-column min-vh-100">
    <nav class="navbar navbar-expand-lg portal-navbar">
        <div class="container-fluid">
            <a class="navbar-brand" href="{{ route('portal.dashboard') }}">
                @if(App\Support\PortalConfig::logoUrl())
                    <img src="{{ App\Support\PortalConfig::logoUrl() }}" alt="{{ App\Support\PortalConfig::companyName() }}">
                @else
                    {{ App\Support\PortalConfig::companyName() }}
                @endif
            </a>

            <button class="navbar-toggler border-0 text-white" type="button" data-bs-toggle="collapse" data-bs-target="#portalNav">
                <i class="bi bi-list fs-4"></i>
            </button>

            <div class="collapse navbar-collapse" id="portalNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('portal.dashboard') ? 'active' : '' }}" href="{{ route('portal.dashboard') }}">
                            <i class="bi bi-speedometer2 me-1"></i>Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('portal.tickets.*') ? 'active' : '' }}" href="{{ route('portal.tickets.index') }}">
                            <i class="bi bi-ticket-perforated me-1"></i>Tickets
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('portal.invoices.*') ? 'active' : '' }}" href="{{ route('portal.invoices.index') }}">
                            <i class="bi bi-receipt me-1"></i>Invoices
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('portal.assets.*') ? 'active' : '' }}" href="{{ route('portal.assets.index') }}">
                            <i class="bi bi-pc-display me-1"></i>Devices
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link {{ request()->routeIs('portal.contracts.*') ? 'active' : '' }}" href="{{ route('portal.contracts.index') }}">
                            <i class="bi bi-file-earmark-text me-1"></i>Service Agreements
                        </a>
                    </li>
                    @if(App\Support\PortalConfig::shopEnabled())
                        <li class="nav-item">
                            <a class="nav-link {{ request()->routeIs('portal.shop.*') ? 'active' : '' }}" href="{{ route('portal.shop.index') }}">
                                <i class="bi bi-bag me-1"></i>Shop
                            </a>
                        </li>
                    @endif
                    @if(App\Support\PortalConfig::billingUrl())
                        <li class="nav-item">
                            <a class="nav-link" href="{{ App\Support\PortalConfig::billingUrl() }}" target="_blank">
                                <i class="bi bi-credit-card me-1"></i>{{ App\Support\PortalConfig::billingLabel() }}
                                <i class="bi bi-box-arrow-up-right ms-1" style="font-size: 0.7rem;"></i>
                            </a>
                        </li>
                    @endif
                </ul>

                <ul class="navbar-nav">
                    <li class="nav-item me-2">
                        <a class="btn btn-sm btn-accent" href="{{ route('portal.tickets.create') }}">
                            <i class="bi bi-plus-lg me-1"></i>New Ticket
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i>{{ $portalPerson->first_name }}
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('portal.account') }}"><i class="bi bi-gear me-2"></i>My Account</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('portal.logout') }}">
                                    @csrf
                                    <button type="submit" class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
                                </form>
                            </li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    @if(session('portal_impersonator_id'))
        <div class="bg-warning text-dark text-center py-2 small fw-semibold">
            <i class="bi bi-eye me-1"></i>You are viewing the portal as <strong>{{ $portalPerson->full_name }}</strong>
            <form method="POST" action="{{ route('portal.stop-impersonating') }}" class="d-inline ms-2">
                @csrf
                <button type="submit" class="btn btn-sm btn-dark">
                    <i class="bi bi-x-circle me-1"></i>Stop Impersonating
                </button>
            </form>
        </div>
    @endif

    <main class="container-fluid py-4 flex-grow-1 portal-content">
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

    <footer class="portal-footer text-center">
        <div class="container-fluid">
            @if(App\Support\PortalConfig::supportEmail())
                Need help? Contact us at <a href="mailto:{{ App\Support\PortalConfig::supportEmail() }}">{{ App\Support\PortalConfig::supportEmail() }}</a>
            @endif
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-FKyoEForCGlyvwx9Hj09JcYn3nv7wiPVlz7YYwJrWVcXK/BmnVDxM+D2scQbITxI" crossorigin="anonymous"></script>
    <script src="{{ asset('js/entity-popovers.js') }}?v={{ filemtime(public_path('js/entity-popovers.js')) }}"></script>
    @stack('scripts')
</body>
</html>
