<aside id="psa-sidebar" class="psa-sidebar" aria-label="Main navigation">
    {{-- Brand / Logo --}}
    <div class="sidebar-brand">
        <a href="{{ route('dashboard') }}">
            <img src="{{ asset('images/SoundIT_head_overlay_high-res.png') }}" alt="{{ config('app.name') }}"
                 class="sidebar-logo-full">
            <img src="{{ asset('images/SoundIT_head_overlay_high-res.png') }}" alt="{{ config('app.name') }}"
                 class="sidebar-logo-icon">
        </a>
    </div>

    {{-- Navigation --}}
    <nav class="sidebar-nav">
        {{-- Dashboard (ungrouped) --}}
        <div class="sidebar-group">
            <a href="{{ route('dashboard') }}"
               class="sidebar-link {{ request()->routeIs('dashboard') ? 'active' : '' }}"
               @if(request()->routeIs('dashboard')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Dashboard">
                <i class="bi bi-speedometer2 sidebar-icon"></i>
                <span class="sidebar-label">Dashboard</span>
            </a>
        </div>

        {{-- Manage --}}
        <div class="sidebar-group">
            <div class="sidebar-group-label">Manage</div>
            <a href="{{ route('clients.index') }}"
               class="sidebar-link {{ request()->routeIs('clients.*') ? 'active' : '' }}"
               @if(request()->routeIs('clients.*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Clients">
                <i class="bi bi-building sidebar-icon"></i>
                <span class="sidebar-label">Clients</span>
            </a>
            <a href="{{ route('people.index') }}"
               class="sidebar-link {{ request()->routeIs('people.*') ? 'active' : '' }}"
               @if(request()->routeIs('people.*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="People">
                <i class="bi bi-person sidebar-icon"></i>
                <span class="sidebar-label">People</span>
            </a>
            <a href="{{ route('assets.index') }}"
               class="sidebar-link {{ request()->routeIs('assets.*') ? 'active' : '' }}"
               @if(request()->routeIs('assets.*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Assets">
                <i class="bi bi-pc-display sidebar-icon"></i>
                <span class="sidebar-label">Assets</span>
            </a>
        </div>

        {{-- Service --}}
        <div class="sidebar-group">
            <div class="sidebar-group-label">Service</div>
            <a href="{{ route('tickets.index') }}"
               class="sidebar-link {{ request()->routeIs('tickets.*') ? 'active' : '' }}"
               @if(request()->routeIs('tickets.*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Tickets">
                <i class="bi bi-ticket-perforated sidebar-icon"></i>
                <span class="sidebar-label">Tickets</span>
            </a>
            <a href="{{ route('cockpit.index') }}"
               class="sidebar-link {{ request()->routeIs('cockpit.*') ? 'active' : '' }}"
               @if(request()->routeIs('cockpit.*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Cockpit">
                <i class="bi bi-robot sidebar-icon"></i>
                <span class="sidebar-label">Cockpit</span>
                @php $cockpitPending = \Illuminate\Support\Facades\Cache::remember('sidebar:cockpit_pending', 60, fn () => app(\App\Services\Technician\Cockpit\CockpitQuery::class)->pendingCount()); @endphp
                @if($cockpitPending > 0)
                    <span class="sidebar-badge bg-danger">{{ $cockpitPending }}</span>
                @endif
            </a>
            <a href="{{ route('calls.index') }}"
               class="sidebar-link {{ request()->routeIs('calls.*') ? 'active' : '' }}"
               @if(request()->routeIs('calls.*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Calls">
                <i class="bi bi-telephone sidebar-icon"></i>
                <span class="sidebar-label">Calls</span>
                @php $missedCount = \Illuminate\Support\Facades\Cache::remember('sidebar:missed_calls', 60, fn() => \App\Models\PhoneCall::unfollowedUp()->count()); @endphp
                @if($missedCount > 0)
                    <span class="sidebar-badge bg-danger">{{ $missedCount }}</span>
                @endif
            </a>
            <a href="{{ route('phone-directory.index') }}"
               class="sidebar-link {{ request()->routeIs('phone-directory.*') ? 'active' : '' }}"
               @if(request()->routeIs('phone-directory.*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Phone Directory">
                <i class="bi bi-shield-lock sidebar-icon"></i>
                <span class="sidebar-label">Phone Directory</span>
            </a>
            <a href="{{ route('emails.index') }}"
               class="sidebar-link {{ request()->routeIs('emails.*') ? 'active' : '' }}"
               @if(request()->routeIs('emails.*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Emails">
                <i class="bi bi-envelope sidebar-icon"></i>
                <span class="sidebar-label">Emails</span>
                @php $emailAttentionCount = \Illuminate\Support\Facades\Cache::remember('sidebar:emails_attention', 60, fn() => \App\Models\Email::needsAttention()->count()); @endphp
                @if($emailAttentionCount > 0)
                    <span class="sidebar-badge bg-warning text-dark">{{ $emailAttentionCount }}</span>
                @endif
            </a>
            <a href="{{ route('alerts.index') }}"
               class="sidebar-link {{ request()->routeIs('alerts.*') ? 'active' : '' }}"
               @if(request()->routeIs('alerts.*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Alerts">
                <i class="bi bi-bell sidebar-icon"></i>
                <span class="sidebar-label">Alerts</span>
                @php $openAlertCount = \Illuminate\Support\Facades\Cache::remember('sidebar:open_alerts', 60, fn() => \App\Models\Alert::open()->count()); @endphp
                @if($openAlertCount > 0)
                    <span class="sidebar-badge bg-danger">{{ $openAlertCount }}</span>
                @endif
            </a>
        </div>

        {{-- Billing (collapsible) --}}
        @php $billingActive = request()->routeIs('invoices.*', 'contracts.*', 'profiles.*', 'prepay.*', 'skus.*', 'profitability.*', 'licenses.*', 'license-types.*', 'reseller-report.*', 'reports.*'); @endphp
        <div class="sidebar-group">
            <div class="sidebar-group-label">Billing</div>
            <a href="#sidebarBilling"
               class="sidebar-link sidebar-toggle {{ $billingActive ? 'active' : '' }}"
               data-bs-toggle="collapse"
               aria-expanded="{{ $billingActive ? 'true' : 'false' }}"
               aria-controls="sidebarBilling"
               id="billing-toggle">
                <i class="bi bi-receipt sidebar-icon"></i>
                <span class="sidebar-label">Billing</span>
                <i class="bi bi-chevron-down sidebar-chevron"></i>
            </a>
            <div id="sidebarBilling" class="sidebar-submenu collapse {{ $billingActive ? 'show' : '' }}">
                <a href="{{ route('invoices.index') }}"
                   class="sidebar-link {{ request()->routeIs('invoices.*') ? 'active' : '' }}"
                   @if(request()->routeIs('invoices.*')) aria-current="page" @endif
                   data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Invoices">
                    <i class="bi bi-receipt sidebar-icon"></i>
                    <span class="sidebar-label">Invoices</span>
                </a>
                <a href="{{ route('contracts.index-all') }}"
                   class="sidebar-link {{ request()->routeIs('contracts.index-all') ? 'active' : '' }}"
                   @if(request()->routeIs('contracts.index-all')) aria-current="page" @endif
                   data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Contracts">
                    <i class="bi bi-file-earmark-text sidebar-icon"></i>
                    <span class="sidebar-label">Contracts</span>
                </a>
                <a href="{{ route('profiles.index') }}"
                   class="sidebar-link {{ request()->routeIs('profiles.index') ? 'active' : '' }}"
                   @if(request()->routeIs('profiles.index')) aria-current="page" @endif
                   data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Recurring Profiles">
                    <i class="bi bi-arrow-repeat sidebar-icon"></i>
                    <span class="sidebar-label">Recurring Profiles</span>
                </a>
                <a href="{{ route('prepay.index') }}"
                   class="sidebar-link {{ request()->routeIs('prepay.*') ? 'active' : '' }}"
                   @if(request()->routeIs('prepay.*')) aria-current="page" @endif
                   data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Prepay">
                    <i class="bi bi-wallet2 sidebar-icon"></i>
                    <span class="sidebar-label">Prepay</span>
                </a>
                <a href="{{ route('skus.index') }}"
                   class="sidebar-link {{ request()->routeIs('skus.*') ? 'active' : '' }}"
                   @if(request()->routeIs('skus.*')) aria-current="page" @endif
                   data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="SKUs">
                    <i class="bi bi-box sidebar-icon"></i>
                    <span class="sidebar-label">SKUs</span>
                </a>
                <a href="{{ route('licenses.index') }}"
                   class="sidebar-link {{ request()->routeIs('licenses.*') ? 'active' : '' }}"
                   @if(request()->routeIs('licenses.*')) aria-current="page" @endif
                   data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Licenses">
                    <i class="bi bi-key sidebar-icon"></i>
                    <span class="sidebar-label">Licenses</span>
                </a>
                <a href="{{ route('license-types.index') }}"
                   class="sidebar-link {{ request()->routeIs('license-types.*') ? 'active' : '' }}"
                   @if(request()->routeIs('license-types.*')) aria-current="page" @endif
                   data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="License Types">
                    <i class="bi bi-tags sidebar-icon"></i>
                    <span class="sidebar-label">License Types</span>
                </a>
                <a href="{{ route('profitability.index') }}"
                   class="sidebar-link {{ request()->routeIs('profitability.*') ? 'active' : '' }}"
                   @if(request()->routeIs('profitability.*')) aria-current="page" @endif
                   data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Profitability">
                    <i class="bi bi-graph-up sidebar-icon"></i>
                    <span class="sidebar-label">Profitability</span>
                </a>
                <a href="{{ route('reseller-report.index') }}"
                   class="sidebar-link {{ request()->routeIs('reseller-report.*') ? 'active' : '' }}"
                   @if(request()->routeIs('reseller-report.*')) aria-current="page" @endif
                   data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Reseller Report">
                    <i class="bi bi-diagram-3 sidebar-icon"></i>
                    <span class="sidebar-label">Reseller Report</span>
                </a>
                <a href="{{ route('reports.time') }}"
                   class="sidebar-link {{ request()->routeIs('reports.time') ? 'active' : '' }}"
                   @if(request()->routeIs('reports.time')) aria-current="page" @endif
                   data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Time Report">
                    <i class="bi bi-clock-history sidebar-icon"></i>
                    <span class="sidebar-label">Time Report</span>
                </a>
            </div>
        </div>

        {{-- Settings --}}
        <div class="sidebar-group">
            <div class="sidebar-group-label">Settings</div>
            <a href="{{ route('settings.general') }}"
               class="sidebar-link {{ request()->routeIs('settings.general*') ? 'active' : '' }}"
               @if(request()->routeIs('settings.general*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="General Settings">
                <i class="bi bi-sliders sidebar-icon"></i>
                <span class="sidebar-label">General</span>
            </a>
            <a href="{{ route('settings.staff.index') }}"
               class="sidebar-link {{ request()->routeIs('settings.staff*') ? 'active' : '' }}"
               @if(request()->routeIs('settings.staff*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Staff">
                <i class="bi bi-people sidebar-icon"></i>
                <span class="sidebar-label">Staff</span>
            </a>
            <a href="{{ route('settings.integrations') }}"
               class="sidebar-link {{ request()->routeIs('settings.integrations*') ? 'active' : '' }}"
               @if(request()->routeIs('settings.integrations*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Integrations">
                <i class="bi bi-plug sidebar-icon"></i>
                <span class="sidebar-label">Integrations</span>
            </a>
            <a href="{{ route('settings.alerts.index') }}"
               class="sidebar-link {{ request()->routeIs('settings.alerts*') ? 'active' : '' }}"
               @if(request()->routeIs('settings.alerts*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="Alerts Hub">
                <i class="bi bi-bell sidebar-icon"></i>
                <span class="sidebar-label">Alerts Hub</span>
            </a>
            <a href="{{ route('settings.mcp-tokens.index') }}"
               class="sidebar-link {{ request()->routeIs('settings.mcp-tokens*') ? 'active' : '' }}"
               @if(request()->routeIs('settings.mcp-tokens*')) aria-current="page" @endif
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="MCP Tokens">
                <i class="bi bi-key sidebar-icon"></i>
                <span class="sidebar-label">MCP Tokens</span>
            </a>
        </div>

        {{-- Recent Items --}}
        @php $recentItems = \App\Support\RecentItems::get(auth()->id()); @endphp
        @if($recentItems->isNotEmpty())
        <div class="sidebar-group sidebar-recent-group">
            <div class="sidebar-group-label">Recent</div>
            @foreach($recentItems->take(8) as $item)
            <a href="{{ $item->url }}"
               class="sidebar-link"
               data-bs-toggle="tooltip" data-bs-placement="right" data-bs-title="{{ $item->label }}">
                <i class="bi {{ \App\Support\RecentItems::iconFor($item->item_type) }} sidebar-icon"></i>
                <span class="sidebar-label">{{ Str::limit($item->label, 25) }}</span>
            </a>
            @endforeach
        </div>
        @endif
    </nav>

    {{-- Collapse Toggle --}}
    <button id="sidebar-collapse-btn" class="sidebar-collapse-btn"
            title="Collapse sidebar" aria-expanded="true" aria-label="Toggle sidebar">
        <i class="bi bi-chevron-bar-left"></i>
    </button>
</aside>

{{-- Mobile backdrop --}}
<div id="sidebar-backdrop" class="sidebar-backdrop" aria-hidden="true"></div>
