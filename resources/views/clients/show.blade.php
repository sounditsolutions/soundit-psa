@extends('layouts.app')

@section('title', $client->name . '')

@section('content')
<div data-assistant-context="client" data-assistant-context-id="{{ $client->id }}"></div>
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('clients.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to Clients
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <x-avatar :avatarUrl="$client->logo_url" :name="$client->name" :size="48" />
            <div>
                <h4 class="section-title mb-1">{{ $client->name }}</h4>
                @if($client->stage === \App\Enums\ClientStage::Prospect)
                    <span class="badge bg-warning text-dark badge-prospect">Prospect</span>
                @endif
                @unless($client->is_active)
                    <span class="badge bg-secondary">Inactive</span>
                @endunless
                @if($client->reseller)
                    <a href="{{ route('clients.show', $client->reseller) }}" class="badge bg-info text-decoration-none">Reseller: {{ $client->reseller->name }}</a>
                @endif
                @if($client->resellerChildren->isNotEmpty())
                    <a href="{{ route('clients.index', ['search' => '']) }}" class="badge bg-info text-decoration-none">Reseller ({{ $client->resellerChildren->count() }} clients)</a>
                @endif
            </div>
        </div>

        <div class="d-flex gap-2">
            <a href="{{ route('clients.portal', $client) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-person-lines-fill me-1"></i>Portal
            </a>
            @if (\App\Support\WikiConfig::isEnabled())
            <a href="{{ route('clients.wiki.index', $client) }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-journal-text"></i> Wiki
            </a>
            @endif
            <a href="{{ route('clients.edit', $client) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            <button type="button" class="btn btn-outline-danger btn-sm" title="Delete client"
                    data-bs-toggle="modal" data-bs-target="#deleteClientModal">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
</div>

{{-- Billing Coverage Check --}}
@php
    $totalAssets = $client->assets()->where('is_active', true)->whereNull('deleted_at')->count();
    $assignedAssetIds = \Illuminate\Support\Facades\DB::table('contract_asset')
        ->join('contracts', 'contracts.id', '=', 'contract_asset.contract_id')
        ->where('contracts.client_id', $client->id)
        ->distinct()
        ->count('contract_asset.asset_id');
    $unassignedCount = $totalAssets - $assignedAssetIds;
@endphp
@if($unassignedCount > 0 && $totalAssets > 0)
<div class="alert alert-warning mb-4 d-flex align-items-center">
    <i class="bi bi-exclamation-triangle me-2 fs-5"></i>
    <div>
        <strong>{{ $unassignedCount }} of {{ $totalAssets }} active assets</strong> are not assigned to any contract.
        <a href="{{ route('clients.contracts', $client) }}" class="alert-link">Review contracts</a> to assign them.
    </div>
</div>
@endif

@php
    $activeContracts = $client->contracts()->active()->withCount('profiles')->get();
    if (!in_array($activeTab ?? '', ['tickets', 'assets', 'people', 'contracts', 'licenses'])) {
        // True open-ticket count for the badge (uncapped); $openTickets stays a 5-row preview.
        $openTicketsCount = $client->tickets()->open()->count();
        $openTickets = $client->tickets()->open()->orderByDesc('updated_at')->limit(5)->get();
        $closedTickets = $client->tickets()->closed()->orderByDesc('updated_at')->limit(3)->get();
        $allRecent = $openTickets->merge($closedTickets);
    } else {
        $openTicketsCount = 0;
        $openTickets = collect();
        $closedTickets = collect();
        $allRecent = collect();
    }
    $clientLicenses = ($activeTab ?? '') === 'licenses' ? collect() : $client->licenses()->with('licenseType')->where('status', 'active')->get();
    $isTabOverride = in_array($activeTab ?? '', ['tickets', 'assets', 'people', 'contracts', 'licenses']);
@endphp

<div class="row g-4">
    {{-- Main content column --}}
    <div class="col-md-8">
        {{-- Tabs --}}
        <ul class="nav nav-tabs detail-tabs mb-3" id="clientTabs" role="tablist">
            <li class="nav-item" role="presentation">
                @if($isTabOverride)
                    <a class="nav-link" href="{{ route('clients.show', $client) }}">Overview</a>
                @else
                    <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">Overview</button>
                @endif
            </li>
            <li class="nav-item" role="presentation">
                @if($isTabOverride)
                    <a class="nav-link" href="{{ route('clients.show', $client) }}#activity">Activity</a>
                @else
                    <button class="nav-link" id="activity-tab" data-bs-toggle="tab" data-bs-target="#activity" type="button" role="tab">Activity</button>
                @endif
            </li>
            <li class="nav-item" role="presentation">
                @if(($activeTab ?? '') === 'tickets')
                    <button class="nav-link active" type="button">
                        Tickets @if(isset($ticketFilters))<span class="text-muted">({{ $tickets->total() }})</span>@endif
                    </button>
                @else
                    <a class="nav-link" href="{{ route('clients.tickets', $client) }}">
                        Tickets @if(!$isTabOverride && $openTicketsCount > 0)<span class="text-muted">({{ $openTicketsCount }})</span>@endif
                    </a>
                @endif
            </li>
            <li class="nav-item" role="presentation">
                @if(($activeTab ?? '') === 'people')
                    <button class="nav-link active" type="button">
                        People @if(isset($people))<span class="text-muted">({{ $people->total() }})</span>@endif
                    </button>
                @else
                    <a class="nav-link" href="{{ route('clients.people', $client) }}">
                        People <span class="text-muted">({{ $client->people->count() }})</span>
                    </a>
                @endif
            </li>
            <li class="nav-item" role="presentation">
                @if(($activeTab ?? '') === 'assets')
                    <button class="nav-link active" type="button">
                        Assets @if(isset($assetFilters))<span class="text-muted">({{ $assetList->total() }})</span>@endif
                    </button>
                @else
                    <a class="nav-link" href="{{ route('clients.assets', $client) }}">
                        Assets @if($client->assets_count > 0)<span class="text-muted">({{ $client->assets_count }})</span>@endif
                    </a>
                @endif
            </li>
            <li class="nav-item" role="presentation">
                @if(($activeTab ?? '') === 'contracts')
                    <button class="nav-link active" type="button">
                        Contracts @if(isset($contractList))<span class="text-muted">({{ $contractList->total() }})</span>@endif
                    </button>
                @else
                    <a class="nav-link" href="{{ route('clients.contracts', $client) }}">
                        Contracts @if(!$isTabOverride && $activeContracts->isNotEmpty())<span class="text-muted">({{ $activeContracts->count() }})</span>@endif
                    </a>
                @endif
            </li>
            <li class="nav-item" role="presentation">
                @if($isTabOverride)
                    <a class="nav-link" href="{{ route('clients.show', $client) }}#notes">Notes & Creds</a>
                @else
                    <button class="nav-link" id="notes-tab" data-bs-toggle="tab" data-bs-target="#notes" type="button" role="tab">Notes & Creds</button>
                @endif
            </li>
            <li class="nav-item" role="presentation">
                @if(($activeTab ?? '') === 'licenses')
                    <button class="nav-link active" type="button">
                        Licenses @if(isset($licenseList))<span class="text-muted">({{ $licenseList->total() }})</span>@endif
                    </button>
                @else
                    <a class="nav-link" href="{{ route('clients.licenses', $client) }}">
                        Licenses @if(!$isTabOverride && $clientLicenses->isNotEmpty())<span class="text-muted">({{ $clientLicenses->count() }})</span>@endif
                    </a>
                @endif
            </li>
            @if(count($integrations) > 0)
            <li class="nav-item" role="presentation">
                @if($isTabOverride)
                    <a class="nav-link" href="{{ route('clients.show', $client) }}#integrations">Integrations</a>
                @else
                    <button class="nav-link" id="integrations-tab" data-bs-toggle="tab" data-bs-target="#integrations" type="button" role="tab">Integrations</button>
                @endif
            </li>
            @endif
        </ul>

        <div class="tab-content">
            {{-- Overview Tab (default) --}}
            <div class="tab-pane fade {{ !$isTabOverride ? 'show active' : '' }}" id="overview" role="tabpanel">
                @if(!empty($client->notes))
                    <div class="card shadow-sm card-static mb-4">
                        <div class="card-header d-flex align-items-center gap-2">
                            <i class="bi bi-sticky"></i><span>Notes</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-0" style="white-space: pre-wrap;">{{ $client->notes }}</div>
                        </div>
                    </div>
                @endif
                {{-- People --}}
                <div class="card shadow-sm card-static mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-people me-2"></i>People
                            @if($client->people->isNotEmpty())
                                <span class="badge bg-light text-dark ms-1">{{ $client->people->count() }}</span>
                            @endif
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('clients.people', $client) }}" class="btn btn-outline-primary btn-sm">View all</a>
                            <a href="{{ route('people.create', ['client_id' => $client->id]) }}" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-lg me-1"></i>New
                            </a>
                        </div>
                    </div>
                    @if($client->people->isEmpty())
                        <div class="card-body text-muted text-center py-3">
                            No people synced for this client.
                        </div>
                    @else
                        <div class="table-responsive d-none d-md-block">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Mobile</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($client->people as $person)
                                        <tr>
                                            <td>
                                                <x-person-badge :person="$person" :size="24" />
                                                @if($person->is_primary)
                                                    <span class="badge bg-warning text-dark ms-1">Primary</span>
                                                @endif
                                                @if($person->person_type !== \App\Enums\PersonType::User)
                                                    <span class="badge bg-secondary ms-1" title="{{ $person->person_type->label() }}">
                                                        <i class="{{ $person->person_type->icon() }}"></i>
                                                    </span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($person->email)
                                                    <a href="mailto:{{ $person->email }}">{{ $person->email }}</a>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($person->phone_display)
                                                    <a href="#" data-phone="{{ $person->phone }}" class="text-decoration-none dial-link">
                                                        {{ $person->phone_display }}
                                                    </a>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td>
                                                @if($person->mobile_display)
                                                    <a href="#" data-phone="{{ $person->mobile }}" class="text-decoration-none dial-link">
                                                        {{ $person->mobile_display }}
                                                    </a>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{-- Mobile: stacked rows below md so Email/Phone do not clip (psa-6zs7) --}}
                        <div class="d-md-none">
                            @foreach($client->people as $person)
                                <div class="data-row">
                                    <div class="fw-semibold mb-1">
                                        <x-person-badge :person="$person" :size="24" />
                                        @if($person->is_primary)
                                            <span class="badge bg-warning text-dark ms-1">Primary</span>
                                        @endif
                                        @if($person->person_type !== \App\Enums\PersonType::User)
                                            <span class="badge bg-secondary ms-1" title="{{ $person->person_type->label() }}">
                                                <i class="{{ $person->person_type->icon() }}"></i>
                                            </span>
                                        @endif
                                    </div>
                                    @if($person->email)
                                        <div class="d-flex justify-content-between gap-3 small py-1">
                                            <span class="data-label">Email</span>
                                            <a href="mailto:{{ $person->email }}" class="text-break text-end">{{ $person->email }}</a>
                                        </div>
                                    @endif
                                    @if($person->phone_display)
                                        <div class="d-flex justify-content-between gap-3 small py-1">
                                            <span class="data-label">Phone</span>
                                            <a href="#" data-phone="{{ $person->phone }}" class="text-decoration-none dial-link text-end">{{ $person->phone_display }}</a>
                                        </div>
                                    @endif
                                    @if($person->mobile_display)
                                        <div class="d-flex justify-content-between gap-3 small py-1">
                                            <span class="data-label">Mobile</span>
                                            <a href="#" data-phone="{{ $person->mobile }}" class="text-decoration-none dial-link text-end">{{ $person->mobile_display }}</a>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Contracts --}}
                <div class="card shadow-sm card-static">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-file-earmark-text me-2"></i>Contracts
                            @if($activeContracts->isNotEmpty())
                                <span class="badge bg-light text-dark ms-1">{{ $activeContracts->count() }} active</span>
                            @endif
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ route('invoices.create', ['client_id' => $client->id]) }}" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-receipt me-1"></i>New Invoice
                            </a>
                            <a href="{{ route('clients.contracts', $client) }}" class="btn btn-outline-primary btn-sm">View all</a>
                            <a href="{{ route('contracts.create', $client) }}" class="btn btn-primary btn-sm">
                                <i class="bi bi-plus-lg me-1"></i>New
                            </a>
                        </div>
                    </div>
                    @if($activeContracts->isEmpty())
                        <div class="card-body text-muted text-center py-3">
                            No active contracts.
                        </div>
                    @else
                        <div class="table-responsive d-none d-md-block">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Billing</th>
                                        <th>Prepay</th>
                                        <th>Profiles</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($activeContracts as $contract)
                                        <tr class="cursor-pointer" onclick="window.location='{{ route('contracts.show', $contract) }}'">
                                            <td>
                                                <a href="{{ route('contracts.show', $contract) }}" class="text-decoration-none">
                                                    {{ $contract->name }}
                                                </a>
                                            </td>
                                            <td class="small">{{ $contract->type->label() }}</td>
                                            <td class="small">{{ $contract->billing_period->label() }}</td>
                                            <td class="small {{ $contract->has_prepay && (float) $contract->prepay_balance <= 0 ? 'fw-semibold text-danger' : '' }}">
                                                {{ $contract->prepay_balance_formatted }}
                                            </td>
                                            <td class="small">{{ $contract->profiles_count }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                        {{-- Mobile: stacked rows below md so Prepay/Profiles do not clip (psa-6zs7) --}}
                        <div class="d-md-none">
                            @foreach($activeContracts as $contract)
                                <div class="data-row" style="cursor:pointer;" onclick="window.location='{{ route('contracts.show', $contract) }}'">
                                    <div class="fw-semibold mb-1">
                                        <a href="{{ route('contracts.show', $contract) }}" class="text-decoration-none" onclick="event.stopPropagation()">{{ $contract->name }}</a>
                                    </div>
                                    <div class="d-flex justify-content-between gap-3 small py-1">
                                        <span class="data-label">Type</span><span>{{ $contract->type->label() }}</span>
                                    </div>
                                    <div class="d-flex justify-content-between gap-3 small py-1">
                                        <span class="data-label">Billing</span><span>{{ $contract->billing_period->label() }}</span>
                                    </div>
                                    <div class="d-flex justify-content-between gap-3 small py-1">
                                        <span class="data-label">Prepay</span>
                                        <span class="{{ $contract->has_prepay && (float) $contract->prepay_balance <= 0 ? 'fw-semibold text-danger' : '' }}">{{ $contract->prepay_balance_formatted }}</span>
                                    </div>
                                    <div class="d-flex justify-content-between gap-3 small py-1">
                                        <span class="data-label">Profiles</span><span>{{ $contract->profiles_count }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Self-Service Install Link --}}
                @php $availableRmms = $client->availableRmms(); @endphp
                @if(! empty($availableRmms))
                    <div class="card mb-3">
                        <div class="card-header d-flex align-items-center gap-2">
                            <i class="bi bi-download"></i>
                            <span>Self-Service Install Link</span>
                        </div>
                        <div class="card-body">
                            @if($client->portal_install_token)
                                @php $installUrl = url('/setup/' . $client->portal_install_token); @endphp
                                <label class="form-label small text-muted mb-1">Install URL</label>
                                <div class="input-group input-group-sm mb-3">
                                    <input type="text" class="form-control font-monospace" readonly value="{{ $installUrl }}" id="installUrlInput">
                                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('installUrlInput').value)">
                                        <i class="bi bi-clipboard"></i>
                                    </button>
                                    <a href="{{ $installUrl }}" target="_blank" class="btn btn-outline-secondary">
                                        <i class="bi bi-box-arrow-up-right"></i>
                                    </a>
                                </div>

                                @if(count($availableRmms) > 1)
                                    <form method="POST" action="{{ route('clients.portal-primary-rmm.update', $client) }}" class="mb-3">
                                        @csrf @method('PATCH')
                                        <label class="form-label small text-muted mb-1">Primary RMM</label>
                                        <div class="input-group input-group-sm">
                                            <select name="portal_primary_rmm" class="form-select">
                                                @foreach($availableRmms as $rmm)
                                                    <option value="{{ $rmm }}" {{ $client->portal_primary_rmm === $rmm ? 'selected' : '' }}>
                                                        {{ ucfirst($rmm) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <button type="submit" class="btn btn-outline-primary">Update</button>
                                        </div>
                                    </form>
                                @endif

                                <div class="d-flex gap-2">
                                    <form method="POST" action="{{ route('clients.install-link.rotate', $client) }}" onsubmit="return confirm('Rotate this link? The old URL will stop working immediately.')">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-warning">
                                            <i class="bi bi-arrow-repeat me-1"></i>Rotate link
                                        </button>
                                    </form>
                                    <form method="POST" action="{{ route('clients.install-link.disable', $client) }}" onsubmit="return confirm('Disable this link entirely?')">
                                        @csrf
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-x-lg me-1"></i>Disable
                                        </button>
                                    </form>
                                </div>
                            @else
                                <p class="text-muted small mb-3">
                                    Generate a shareable link that lets end users install the RMM agent on new devices without contacting support.
                                </p>
                                <form method="POST" action="{{ route('clients.install-link.generate', $client) }}">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-primary">
                                        <i class="bi bi-plus-lg me-1"></i>Generate install link
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                @endif

            </div>

            {{-- Activity Tab (lazy-loaded via AJAX) --}}
            <div class="tab-pane fade" id="activity" role="tabpanel" data-activity-url="{{ route('clients.activity', $client) }}">
                {{-- Filter chips --}}
                <div class="stream-filters mb-3">
                    <button class="stream-filter active" data-filter="all" onclick="clientActivityFilter(this)">All</button>
                    <button class="stream-filter" data-filter="ticket" onclick="clientActivityFilter(this)">Tickets</button>
                    <button class="stream-filter" data-filter="call" onclick="clientActivityFilter(this)">Calls</button>
                    <button class="stream-filter" data-filter="email" onclick="clientActivityFilter(this)">Emails</button>
                    <button class="stream-filter" data-filter="contract" onclick="clientActivityFilter(this)">Contracts</button>
                    <button class="stream-filter" data-filter="triage" onclick="clientActivityFilter(this)">Triage</button>
                    <button class="stream-filter" data-filter="invoice" onclick="clientActivityFilter(this)">Billing</button>
                </div>

                <div id="clientActivityStream">
                    <div class="text-center py-5 text-muted">
                        <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                        Loading activity...
                    </div>
                </div>

                <div class="text-center mt-3" id="clientLoadMoreContainer" style="display: none;">
                    <button class="btn btn-outline-primary btn-sm" id="clientLoadMoreBtn" onclick="clientLoadMore()">
                        <i class="bi bi-arrow-down me-1"></i>Load More
                    </button>
                </div>
            </div>

            {{-- Tickets Tab --}}
            <div class="tab-pane fade {{ ($activeTab ?? '') === 'tickets' ? 'show active' : '' }}" id="tickets" role="tabpanel">
                @if(($activeTab ?? '') === 'tickets')
                    @include('tickets._list', [
                        'listRoute' => 'clients.tickets',
                        'prefilter' => ['client' => $client->id, 'client_id' => $client->id],
                        'filters' => $ticketFilters,
                        'clients' => $ticketClients,
                        'users' => $ticketUsers,
                        'statuses' => $ticketStatuses,
                        'priorities' => $ticketPriorities,
                        'types' => $ticketTypes,
                        'sources' => $ticketSources,
                    ])
                @else
                    <div class="card shadow-sm card-static">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-ticket-perforated me-2"></i>Recent Tickets
                                @if($openTicketsCount > 0)
                                    <span class="badge bg-light text-dark ms-1">{{ $openTicketsCount }} open</span>
                                @endif
                            </div>
                            <a href="{{ route('clients.tickets', $client) }}"
                               class="btn btn-outline-primary btn-sm">View all tickets</a>
                        </div>
                        @if($allRecent->isEmpty())
                            <div class="card-body text-muted text-center py-3">
                                No tickets for this client.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Subject</th>
                                            <th>Priority</th>
                                            <th>Status</th>
                                            <th>Updated</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($allRecent as $ticket)
                                            <tr class="cursor-pointer" onclick="window.location='{{ route('tickets.show', $ticket) }}'">
                                                <td class="small text-muted">{{ $ticket->display_id }}</td>
                                                <td>
                                                    <a href="{{ route('tickets.show', $ticket) }}" class="text-decoration-none">
                                                        {{ Str::limit($ticket->subject, 50) }}
                                                    </a>
                                                </td>
                                                <td><span class="badge {{ $ticket->priority->badgeClass() }}">{{ $ticket->priority->label() }}</span></td>
                                                <td><span class="badge {{ $ticket->status->badgeClass() }}">{{ $ticket->status->label() }}</span></td>
                                                <td class="small">{{ $ticket->updated_at?->diffForHumans() }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- People Tab --}}
            <div class="tab-pane fade {{ ($activeTab ?? '') === 'people' ? 'show active' : '' }}" id="people" role="tabpanel">
                @if(($activeTab ?? '') === 'people')
                    @include('people._list', [
                        'listRoute' => 'clients.people',
                        'prefilter' => ['client' => $client->id, 'client_id' => $client->id],
                        'people' => $people,
                        'clients' => $peopleClients,
                        'search' => $peopleSearch,
                        'clientId' => $peopleClientId,
                        'personType' => $peoplePersonType,
                    ])
                @endif
            </div>

            {{-- Assets Tab --}}
            <div class="tab-pane fade {{ ($activeTab ?? '') === 'assets' ? 'show active' : '' }}" id="assets" role="tabpanel">
                @if(($activeTab ?? '') === 'assets')
                    @include('assets._list', [
                        'listRoute' => 'clients.assets',
                        'prefilter' => ['client' => $client->id, 'client_id' => $client->id],
                        'assets' => $assetList,
                        'filters' => $assetFilters,
                        'clients' => $assetClients,
                        'assetTypes' => $assetTypes,
                    ])
                @else
                    <div class="card shadow-sm card-static">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-pc-display me-2"></i>Assets
                                @if($client->assets_count > 0)
                                    <span class="badge bg-light text-dark ms-1">{{ $client->assets_count }}</span>
                                @endif
                            </div>
                            @if($client->assets_count > 25)
                                <a href="{{ route('clients.assets', $client) }}" class="btn btn-outline-primary btn-sm">
                                    View all {{ $client->assets_count }} assets
                                </a>
                            @endif
                        </div>
                        @if($assets->isEmpty())
                            <div class="card-body text-muted text-center py-3">
                                No assets synced for this client.
                            </div>
                        @else
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>Device</th>
                                            <th class="d-none d-md-table-cell">Type</th>
                                            <th class="d-none d-md-table-cell">OS</th>
                                            <th class="d-none d-md-table-cell">Last Seen</th>
                                            <th class="text-center" style="width: 90px;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($assets as $asset)
                                            <tr style="cursor:pointer;" onclick="window.location='{{ route('assets.show', $asset) }}'">
                                                <td>
                                                    <strong>{{ $asset->hostname ?: $asset->name }}</strong>
                                                    @if($asset->hostname && $asset->hostname !== $asset->name)
                                                        <br><small class="text-muted">{{ $asset->name }}</small>
                                                    @endif
                                                </td>
                                                <td class="d-none d-md-table-cell">{{ $asset->asset_type ?: '-' }}</td>
                                                <td class="d-none d-md-table-cell">{{ $asset->os ?: '-' }}</td>
                                                <td class="d-none d-md-table-cell">
                                                    @if($asset->last_seen_at)
                                                        <span title="{{ $asset->last_seen_at->toAppTz()->format('Y-m-d H:i T') }}">
                                                            {{ $asset->last_seen_at->diffForHumans() }}
                                                        </span>
                                                    @else
                                                        <span class="text-muted">-</span>
                                                    @endif
                                                </td>
                                                <td class="text-center">
                                                    @php $status = $asset->statusBadge; @endphp
                                                    @if($status === 'Online')
                                                        <span class="badge bg-success" title="Online per RMM">Online</span>
                                                    @elseif($status === 'Offline')
                                                        <span class="badge bg-danger" title="Offline per RMM">Offline</span>
                                                    @else
                                                        <span class="badge bg-secondary" title="No RMM status available">Unknown</span>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Contracts Tab --}}
            <div class="tab-pane fade {{ ($activeTab ?? '') === 'contracts' ? 'show active' : '' }}" id="contracts-tab-pane" role="tabpanel">
                @if(($activeTab ?? '') === 'contracts')
                    @include('contracts._list', [
                        'listRoute' => 'clients.contracts',
                        'prefilter' => ['client' => $client->id, 'client_id' => $client->id],
                        'contracts' => $contractList,
                        'filters' => $contractFilters,
                        'clients' => $contractClients,
                        'statuses' => $contractStatuses,
                        'types' => $contractTypes,
                        'prepaySkus' => $prepaySkus,
                    ])
                @endif
            </div>

            {{-- Notes & Credentials Tab --}}
            <div class="tab-pane fade" id="notes" role="tabpanel">
                {{-- Site Notes --}}
                <div class="card shadow-sm card-static mb-4" id="siteNotes">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-journal-text me-2"></i>Site Notes
                            @if($client->site_notes_updated_at)
                                <span class="text-muted small ms-2">
                                    Updated {{ $client->site_notes_updated_at->diffForHumans() }}
                                    @if($client->siteNotesUpdatedBy)
                                        by {{ $client->siteNotesUpdatedBy->name }}
                                    @endif
                                </span>
                            @endif
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="editSiteNotesBtn"
                                onclick="toggleSiteNotesEdit()">
                            <i class="bi bi-pencil me-1"></i>Edit
                        </button>
                    </div>
                    <div class="card-body">
                        {{-- Display mode --}}
                        <div id="siteNotesDisplay">
                            @if($client->site_notes_html)
                                <div class="note-body">{!! $client->site_notes_html !!}</div>
                            @else
                                <p class="text-muted mb-0">No site notes yet. Click Edit to document this client's environment, key contacts, and special procedures.</p>
                            @endif
                        </div>

                        {{-- Edit mode (hidden by default) --}}
                        <div id="siteNotesEdit" style="display: none;">
                            <form method="POST" action="{{ route('clients.site-notes.update', $client) }}" id="siteNotesForm">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="site_notes_updated_at" value="{{ $client->site_notes_updated_at?->toIso8601String() }}">
                                <x-markdown-editor name="site_notes" id="site_notes_editor" :value="$client->site_notes ?? ''"
                                                   rows="10" placeholder="Document this client's environment, network layout, servers, special procedures..." :lazy="true" />
                                <div class="d-flex gap-2 mt-3">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="bi bi-check-lg me-1"></i>Save Notes
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleSiteNotesEdit()">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                {{-- Credentials --}}
                <div class="card shadow-sm card-static border-warning border-opacity-25">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-shield-lock me-2"></i>Credentials
                            <span class="badge bg-warning text-dark ms-2" style="font-size: 0.7rem;">Not shared with AI</span>
                            @if($client->credentials_updated_at)
                                <span class="text-muted small ms-2">
                                    Updated {{ $client->credentials_updated_at->diffForHumans() }}
                                    @if($client->credentialsUpdatedBy)
                                        by {{ $client->credentialsUpdatedBy->name }}
                                    @endif
                                </span>
                            @endif
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm" id="editCredentialsBtn"
                                onclick="toggleCredentialsEdit()">
                            <i class="bi bi-pencil me-1"></i>Edit
                        </button>
                    </div>
                    <div class="card-body">
                        {{-- Display mode --}}
                        <div id="credentialsDisplay">
                            @if($client->credentials)
                                <div class="note-body">{!! $client->credentials_rendered !!}</div>
                            @else
                                <p class="text-muted mb-0">No credentials stored. Click Edit to add vault references, access codes, and site-specific credentials.</p>
                            @endif
                        </div>

                        {{-- Edit mode (hidden by default) --}}
                        <div id="credentialsEdit" style="display: none;">
                            <form method="POST" action="{{ route('clients.credentials.update', $client) }}" id="credentialsForm">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="credentials_updated_at" value="{{ $client->credentials_updated_at?->toIso8601String() }}">
                                <x-markdown-editor name="credentials" id="credentials_editor" :value="$client->credentials ?? ''"
                                                   rows="6" placeholder="Vault references, alarm codes, WiFi passwords, admin credentials..." :lazy="true" />
                                <div class="d-flex gap-2 mt-3">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="bi bi-check-lg me-1"></i>Save Credentials
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="toggleCredentialsEdit()">Cancel</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Licenses Tab --}}
            <div class="tab-pane fade {{ ($activeTab ?? '') === 'licenses' ? 'show active' : '' }}" id="licenses" role="tabpanel">
                @if(($activeTab ?? '') === 'licenses')
                    @include('licenses._list', [
                        'listRoute' => 'clients.licenses',
                        'prefilter' => ['client' => $client->id, 'client_id' => $client->id],
                        'licenses' => $licenseList,
                        'clients' => $licenseClients,
                        'licenseTypes' => $licenseTypes,
                        'vendors' => $licenseVendors,
                        'clientId' => $licenseClientId,
                        'licenseTypeId' => $licenseTypeId,
                        'vendor' => $licenseVendor,
                        'wasteOnly' => $licenseWasteOnly,
                    ])
                @elseif($clientLicenses->isNotEmpty())
                    <div class="card shadow-sm card-static">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <div>
                                <i class="bi bi-key me-2"></i>Licenses
                                <span class="badge bg-light text-dark ms-1">{{ $clientLicenses->sum('quantity') }} total</span>
                            </div>
                            <a href="{{ route('clients.licenses', $client) }}" class="btn btn-outline-primary btn-sm">View all</a>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>License Type</th>
                                        <th class="d-none d-md-table-cell">Vendor</th>
                                        <th class="text-end">Qty</th>
                                        <th class="text-end d-none d-md-table-cell">Assigned</th>
                                        <th class="d-none d-lg-table-cell">Utilization</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($clientLicenses->sortBy('licenseType.name') as $lic)
                                        <tr>
                                            <td>{{ $lic->licenseType->name }}</td>
                                            <td class="d-none d-md-table-cell"><span class="badge bg-light text-dark">{{ $lic->licenseType->vendor }}</span></td>
                                            <td class="text-end fw-semibold">
                                                @if($lic->seat_manageable)
                                                    <span id="clic-qty-display-{{ $lic->id }}">
                                                        {{ $lic->quantity }}
                                                        @if($lic->scheduled_quantity !== null && $lic->scheduled_quantity !== $lic->quantity)
                                                            <span class="text-warning" title="Scheduled reduction — will be applied at next billing cycle">
                                                                <i class="bi bi-arrow-right" style="font-size: 0.7rem;"></i> {{ $lic->scheduled_quantity }}
                                                                <i class="bi bi-clock" style="font-size: 0.7rem;"></i>
                                                            </span>
                                                        @endif
                                                        <button type="button" class="btn btn-link btn-sm p-0 ms-1 text-muted"
                                                                onclick="showClientSeatEditor({{ $lic->id }})"
                                                                title="Edit quantity">
                                                            <i class="bi bi-pencil" style="font-size: 0.75rem;"></i>
                                                        </button>
                                                    </span>
                                                    <form method="POST" action="{{ route('licenses.update-quantity', $lic) }}"
                                                          id="clic-qty-form-{{ $lic->id }}" style="display:none;"
                                                          onsubmit="return confirmClientSeatChange(this, {{ $lic->id }}, {{ $lic->quantity }}, '{{ addslashes($lic->licenseType->name) }}', {{ $lic->scheduled_quantity !== null ? $lic->scheduled_quantity : 'null' }})">
                                                        @csrf
                                                        @method('PATCH')
                                                        <div class="input-group input-group-sm" style="width: 140px; display: inline-flex;">
                                                            <input type="number" name="quantity" class="form-control form-control-sm text-end"
                                                                   value="{{ $lic->quantity }}" min="1" max="10000" required>
                                                            <button type="submit" class="btn btn-success btn-sm" title="Save">
                                                                <i class="bi bi-check"></i>
                                                            </button>
                                                            <button type="button" class="btn btn-outline-secondary btn-sm"
                                                                    onclick="hideClientSeatEditor({{ $lic->id }})" title="Cancel">
                                                                <i class="bi bi-x"></i>
                                                            </button>
                                                        </div>
                                                    </form>
                                                @else
                                                    {{ $lic->quantity }}
                                                @endif
                                            </td>
                                            <td class="text-end d-none d-md-table-cell">
                                                @if($lic->assigned_quantity !== null)
                                                    {{ $lic->assigned_quantity }}
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                            <td class="d-none d-lg-table-cell">
                                                @if($lic->assigned_quantity !== null && $lic->quantity > 0)
                                                    @php $util = $lic->utilization_percent; @endphp
                                                    <div class="progress" style="height: 16px; min-width: 60px;">
                                                        <div class="progress-bar {{ $util >= 90 ? 'bg-success' : ($util >= 70 ? 'bg-warning' : 'bg-danger') }}"
                                                             style="width: {{ min($util, 100) }}%">
                                                            {{ $util }}%
                                                        </div>
                                                    </div>
                                                @else
                                                    <span class="text-muted">-</span>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Integrations Tab (conditional) --}}
            @if(count($integrations) > 0)
            <div class="tab-pane fade" id="integrations" role="tabpanel">
                @php
                    $mapped = collect($integrations)->where('mapped', true);
                    $unmapped = collect($integrations)->where('mapped', false);
                @endphp

                {{-- Mapped integrations --}}
                @if($mapped->isNotEmpty())
                <div class="row g-3 mb-3">
                    @foreach($mapped as $vendor => $int)
                    <div class="col-md-6">
                        <div class="card shadow-sm card-static h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi {{ $int['icon'] }} fs-5"></i>
                                        <strong>{{ $int['label'] }}</strong>
                                    </div>
                                    <span class="badge bg-success">Linked</span>
                                </div>
                                <div class="text-muted small mb-2">
                                    <i class="bi bi-link-45deg me-1"></i>{{ $int['entity_display'] }}
                                </div>
                                @if($int['license_count'] > 0)
                                <div class="text-muted small mb-1">
                                    <i class="bi bi-key me-1"></i>{{ $int['license_count'] }} license{{ $int['license_count'] !== 1 ? 's' : '' }}
                                    @if($int['last_synced'])
                                        <span class="ms-2"><i class="bi bi-arrow-repeat me-1"></i>{{ \Carbon\Carbon::parse($int['last_synced'])->toAppTz()->format('M j, g:ia') }}</span>
                                    @endif
                                </div>
                                @endif
                                <form method="POST" action="{{ route('clients.integrations.unlink', [$client, $vendor]) }}"
                                      onsubmit="return confirm('Unlink {{ $int['label'] }} \'{{ addslashes($int['entity_display']) }}\'?{{ $int['license_count'] > 0 ? ' This will deactivate synced licenses for this vendor.' : '' }}')">
                                    @csrf
                                    <button type="submit" class="btn btn-outline-danger btn-sm mt-2">
                                        <i class="bi bi-x-circle me-1"></i>Unlink
                                    </button>
                                </form>

                                {{-- Comet: deployment credentials + per-device backup toggles --}}
                                @if($vendor === 'comet')
                                    @if($client->comet_backup_user)
                                        <div class="mt-3 pt-3 border-top">
                                            <h6 class="small fw-bold mb-2"><i class="bi bi-key me-1"></i>Deployment Credentials</h6>
                                            <div class="row g-2 small">
                                                <div class="col-md-4">
                                                    <strong>Server URL</strong><br>
                                                    <code>{{ \App\Support\CometConfig::get('comet_server_url') }}</code>
                                                </div>
                                                <div class="col-md-4">
                                                    <strong>Username</strong><br>
                                                    <code>{{ $client->comet_backup_user }}</code>
                                                </div>
                                                <div class="col-md-4">
                                                    <strong>Password</strong><br>
                                                    <span class="font-monospace comet-pw-mask">••••••••</span>
                                                    <span class="font-monospace comet-pw-value d-none">{{ $client->comet_backup_password }}</span>
                                                    <button type="button" class="btn btn-link btn-sm p-0 ms-1" onclick="this.previousElementSibling.classList.toggle('d-none'); this.previousElementSibling.previousElementSibling.classList.toggle('d-none');">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <div class="mt-3 pt-3 border-top">
                                            <div class="alert alert-warning small mb-0">
                                                <i class="bi bi-exclamation-triangle me-1"></i>
                                                Comet group linked but no backup user created yet. Backup user is needed to deploy agents.
                                                <form action="{{ route('clients.comet.provision-user', $client) }}" method="POST" class="d-inline ms-2">
                                                    @csrf
                                                    <button type="submit" class="btn btn-warning btn-sm">
                                                        <i class="bi bi-person-plus me-1"></i>Create Backup User
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    @endif

                                    @php
                                        $clientAssets = $client->assets()->where('is_active', true)->orderBy('hostname')->get();
                                    @endphp
                                    @if($clientAssets->count() > 0)
                                        <div class="mt-3 pt-3 border-top">
                                            <h6 class="small fw-bold mb-2"><i class="bi bi-hdd me-1"></i>Device Backup</h6>
                                            <div class="table-responsive">
                                                <table class="table table-sm mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Device</th>
                                                            <th>Type</th>
                                                            <th style="width: 100px;">Backup</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach($clientAssets as $clientAsset)
                                                            <tr>
                                                                <td>
                                                                    <a href="{{ route('assets.show', $clientAsset) }}">{{ $clientAsset->hostname ?: $clientAsset->name }}</a>
                                                                </td>
                                                                <td class="text-muted">{{ $clientAsset->asset_type ?? '—' }}</td>
                                                                <td>
                                                                    @if($client->comet_backup_user)
                                                                        <form action="{{ route('assets.comet.toggle-backup', $clientAsset) }}" method="POST" class="d-inline">
                                                                            @csrf
                                                                            <div class="form-check form-switch">
                                                                                <input class="form-check-input" type="checkbox"
                                                                                       {{ $clientAsset->comet_backup_enabled ? 'checked' : '' }}
                                                                                       onchange="this.form.submit()">
                                                                            </div>
                                                                        </form>
                                                                    @else
                                                                        <span class="text-muted small">—</span>
                                                                    @endif
                                                                </td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    @endif
                                @endif
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif

                {{-- Unmapped integrations --}}
                {{-- Tactical RMM card (standalone — Tactical's Client|Site model is managed via the bulk mapping page, not the registry). --}}
                @if(\App\Support\TacticalConfig::isConfigured())
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="card shadow-sm card-static h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-hdd-rack fs-5 {{ $client->tactical_site_id ? '' : 'text-muted' }}"></i>
                                        <strong class="{{ $client->tactical_site_id ? '' : 'text-muted' }}">Tactical RMM</strong>
                                    </div>
                                    @if($client->tactical_site_id)
                                        <span class="badge bg-success">Linked</span>
                                    @else
                                        <span class="badge bg-light text-muted">Not linked</span>
                                    @endif
                                </div>

                                @if($client->tactical_site_id)
                                    <div class="text-muted small mb-2">
                                        <i class="bi bi-link-45deg me-1"></i>{{ $client->tactical_site_id }}
                                    </div>
                                    <a href="{{ route('settings.tactical-sites.index') }}" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-gear me-1"></i>Manage in Settings
                                    </a>
                                @else
                                    @php $tacticalPolicies = \App\Services\Tactical\TacticalClient::cachedPolicies(); @endphp
                                    <form action="{{ route('clients.tactical.provision', $client) }}" method="POST"
                                          onsubmit="return confirm('Create a Tactical RMM client and default site for {{ addslashes($client->name) }}?')">
                                        @csrf
                                        @if(! empty($tacticalPolicies))
                                            <div class="row g-2 mb-2">
                                                <div class="col-12">
                                                    <label class="form-label small text-muted mb-1">Workstation policy</label>
                                                    <select name="workstation_policy_id" class="form-select form-select-sm">
                                                        <option value="">— None (no policy) —</option>
                                                        @foreach($tacticalPolicies as $policy)
                                                            <option value="{{ $policy['id'] }}">{{ $policy['name'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label small text-muted mb-1">Server policy</label>
                                                    <select name="server_policy_id" class="form-select form-select-sm">
                                                        <option value="">— None (no policy) —</option>
                                                        @foreach($tacticalPolicies as $policy)
                                                            <option value="{{ $policy['id'] }}">{{ $policy['name'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                            </div>
                                        @else
                                            <div class="text-muted small mb-2">
                                                <i class="bi bi-exclamation-triangle me-1"></i>Could not load Tactical policies — client will be created without a policy.
                                            </div>
                                        @endif
                                        <button type="submit" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-plus-circle me-1"></i>Create
                                        </button>
                                        <a href="{{ route('settings.tactical-sites.index') }}" class="btn btn-outline-secondary btn-sm ms-1">
                                            <i class="bi bi-link me-1"></i>Link to existing
                                        </a>
                                    </form>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                {{-- M365 security posture (synced daily via cipp:sync-tenant-security). --}}
                @php $sec = $client->securitySnapshot(); @endphp
                @if($sec)
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="card shadow-sm card-static h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi bi-shield-lock fs-5"></i>
                                        <strong>M365 Security Posture</strong>
                                    </div>
                                    <span class="text-muted small">{{ $sec['synced_at']->toAppTz()->diffForHumans() }}</span>
                                </div>
                                <table class="table table-sm mb-0 align-middle">
                                    <tbody>
                                        <tr>
                                            <th class="text-muted small">Transport rules</th>
                                            <td>{{ $sec['transport_rule_count'] }}</td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted small">Safe Links</th>
                                            <td>
                                                @if($sec['safe_links_active'])
                                                    <span class="badge bg-success">Active</span>
                                                @else
                                                    <span class="badge bg-warning text-dark">Not configured</span>
                                                @endif
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted small">Safe Attachments</th>
                                            <td>
                                                @if($sec['safe_attachments_active'])
                                                    <span class="badge bg-success">Active</span>
                                                @else
                                                    <span class="badge bg-warning text-dark">Not configured</span>
                                                @endif
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted small">Conditional Access</th>
                                            <td>
                                                @if($sec['ca_policy_enabled'] > 0)
                                                    <span class="badge bg-success">{{ $sec['ca_policy_enabled'] }} enabled</span>
                                                    @if($sec['ca_policy_total'] > $sec['ca_policy_enabled'])
                                                        <small class="text-muted ms-1">({{ $sec['ca_policy_total'] }} total)</small>
                                                    @endif
                                                @elseif($sec['ca_policy_total'] > 0)
                                                    <span class="badge bg-warning text-dark">{{ $sec['ca_policy_total'] }} defined, none enabled</span>
                                                @else
                                                    <span class="badge bg-danger">None</span>
                                                @endif
                                            </td>
                                        </tr>
                                        <tr>
                                            <th class="text-muted small">Intune compliance</th>
                                            <td>
                                                @if($sec['compliance_policy_count'] > 0)
                                                    <span class="badge bg-success">{{ $sec['compliance_policy_count'] }} policies</span>
                                                @else
                                                    <span class="badge bg-warning text-dark">None</span>
                                                @endif
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                @endif

                @if($unmapped->isNotEmpty())
                <div class="row g-3">
                    @foreach($unmapped as $vendor => $int)
                    <div class="col-md-6">
                        <div class="card shadow-sm card-static h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div class="d-flex align-items-center gap-2">
                                        <i class="bi {{ $int['icon'] }} fs-5 text-muted"></i>
                                        <strong class="text-muted">{{ $int['label'] }}</strong>
                                    </div>
                                    <span class="badge bg-light text-muted">Not linked</span>
                                </div>

                                {{-- Link button (shown by default) --}}
                                <button type="button" class="btn btn-outline-primary btn-sm integration-link-btn" data-vendor="{{ $vendor }}" data-client="{{ $client->id }}">
                                    <i class="bi bi-link me-1"></i>Link
                                </button>

                                {{-- Comet Backup: one-click provisioning --}}
                                @if($vendor === 'comet' && \App\Support\CometConfig::isConfigured())
                                    <form action="{{ route('clients.comet.provision', $client) }}" method="POST" class="d-inline ms-1"
                                          onsubmit="return confirm('Create a Comet Backup group and user for {{ addslashes($client->name) }}?')">
                                        @csrf
                                        <button type="submit" class="btn btn-outline-success btn-sm">
                                            <i class="bi bi-plus-circle me-1"></i>Create
                                        </button>
                                    </form>
                                @endif

                                {{-- Link form (hidden by default) --}}
                                <div class="integration-link-form d-none" data-vendor="{{ $vendor }}">
                                    <div class="integration-loading text-muted small mb-2">
                                        <span class="spinner-border spinner-border-sm me-1"></span>Loading {{ $int['label'] }} entities...
                                    </div>
                                    <div class="integration-loaded d-none">
                                        <input type="text" class="form-control form-control-sm mb-2 integration-filter" placeholder="Filter..." data-vendor="{{ $vendor }}">
                                        <select class="form-select form-select-sm mb-2 integration-select" data-vendor="{{ $vendor }}">
                                            <option value="">Select...</option>
                                        </select>
                                        @if($vendor === 'qbo')
                                        <input type="hidden" class="integration-display-name" data-vendor="{{ $vendor }}">
                                        @endif
                                        <form method="POST" action="{{ route('clients.integrations.link', [$client, $vendor]) }}" class="integration-save-form" data-vendor="{{ $vendor }}">
                                            @csrf
                                            <input type="hidden" name="entity_id" class="integration-entity-id" data-vendor="{{ $vendor }}">
                                            @if($vendor === 'qbo')
                                            <input type="hidden" name="display_name" class="integration-display-name-input" data-vendor="{{ $vendor }}">
                                            @endif
                                            <div class="d-flex gap-2">
                                                <button type="submit" class="btn btn-primary btn-sm" disabled>Save</button>
                                                <button type="button" class="btn btn-outline-secondary btn-sm integration-cancel-btn" data-vendor="{{ $vendor }}">Cancel</button>
                                            </div>
                                        </form>
                                    </div>
                                    <div class="integration-error d-none">
                                        <div class="alert alert-warning small mb-2 py-1 px-2"></div>
                                        <button type="button" class="btn btn-outline-secondary btn-sm integration-cancel-btn" data-vendor="{{ $vendor }}">Cancel</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    @endforeach
                </div>
                @endif
            </div>
            @endif
        </div>{{-- /tab-content --}}

    </div>{{-- /col-md-8 --}}

    {{-- Sidebar --}}
    <div class="col-md-4 detail-sidebar">
        <div class="card shadow-sm card-static mb-3">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>Details</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted" style="width: 110px;">Phone</th>
                            <td>
                                @if($client->phone_display)
                                    <a href="#" data-phone="{{ $client->phone }}" class="text-decoration-none dial-link">
                                        {{ $client->phone_display }}
                                    </a>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Email</th>
                            <td>
                                @if($client->email)
                                    <a href="mailto:{{ $client->email }}">{{ $client->email }}</a>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Website</th>
                            <td>
                                @if($client->website)
                                    <a href="{{ $client->website }}" target="_blank">{{ $client->website }}</a>
                                @else
                                    -
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Primary Tech</th>
                            <td>{{ $client->primaryTech?->name ?? "\u{2014}" }}</td>
                        </tr>
                        @if($client->reseller_id)
                        <tr>
                            <th class="text-muted">Reseller</th>
                            <td><a href="{{ route('clients.show', $client->reseller_id) }}">{{ $client->reseller?->name }}</a></td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card shadow-sm card-static">
            <div class="card-header"><i class="bi bi-geo-alt me-2"></i>Address</div>
            <div class="card-body">
                @if($client->fullAddress)
                    <p class="mb-0">{{ $client->fullAddress }}</p>
                @else
                    <p class="text-muted mb-0">No address on file.</p>
                @endif
            </div>
        </div>
    </div>{{-- /col-md-4 --}}
</div>{{-- /row --}}

{{-- Delete Modal --}}
<div class="modal fade" id="deleteClientModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Delete Client</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>This will soft-delete <strong>{{ $client->name }}</strong> and all associated data.</p>
                <p class="text-muted small">Clients with open tickets, active contracts, or unpaid invoices cannot be deleted.</p>
                <label for="deleteClientConfirm" class="form-label mt-2">
                    To confirm, type <code>{{ $client->name }}</code> below.
                </label>
                <input type="text" class="form-control" id="deleteClientConfirm" autocomplete="off">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="{{ route('clients.destroy', $client) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger" id="deleteClientBtn" disabled>
                        <i class="bi bi-trash me-1"></i>Delete Client
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var expected = @json($client->name);
    var input = document.getElementById('deleteClientConfirm');
    var btn = document.getElementById('deleteClientBtn');
    input?.addEventListener('input', function() {
        btn.disabled = input.value !== expected;
    });
    document.getElementById('deleteClientModal')?.addEventListener('hidden.bs.modal', function() {
        input.value = '';
        btn.disabled = true;
    });
})();
</script>
@endsection

@push('scripts')
<script>
(function() {
    var siteNotesEditor = null;
    var credentialsEditor = null;
    var siteNotesDirty = false;
    var credentialsDirty = false;

    // Lazy EasyMDE initializer — only creates editor on first Edit click
    function initEditor(textareaId) {
        var el = document.getElementById(textareaId);
        if (!el || el.dataset.easymdeInit) return null;
        el.dataset.easymdeInit = 'true';

        var rows = parseInt(el.rows) || 4;
        var editor = new EasyMDE({
            element: el,
            toolbar: [
                { name: 'bold', action: EasyMDE.toggleBold, className: 'bi bi-type-bold', title: 'Bold' },
                { name: 'italic', action: EasyMDE.toggleItalic, className: 'bi bi-type-italic', title: 'Italic' },
                { name: 'heading', action: EasyMDE.toggleHeadingSmaller, className: 'bi bi-type-h2', title: 'Heading' },
                '|',
                { name: 'unordered-list', action: EasyMDE.toggleUnorderedList, className: 'bi bi-list-ul', title: 'Unordered List' },
                { name: 'ordered-list', action: EasyMDE.toggleOrderedList, className: 'bi bi-list-ol', title: 'Ordered List' },
                '|',
                { name: 'link', action: EasyMDE.drawLink, className: 'bi bi-link-45deg', title: 'Link' },
                { name: 'code', action: EasyMDE.toggleCodeBlock, className: 'bi bi-code-slash', title: 'Code' },
                { name: 'quote', action: EasyMDE.toggleBlockquote, className: 'bi bi-blockquote-left', title: 'Quote' },
                '|',
                { name: 'preview', action: EasyMDE.togglePreview, className: 'bi bi-eye no-disable', noDisable: true, title: 'Preview' }
            ],
            spellChecker: false,
            status: false,
            placeholder: el.placeholder || '',
            minHeight: (rows * 24) + 'px',
            autoDownloadFontAwesome: false,
            forceSync: true,
            renderingConfig: { codeSyntaxHighlighting: false }
        });

        // Sync value before form submit
        var form = el.closest('form');
        if (form) {
            form.addEventListener('submit', function() { el.value = editor.value(); });
        }

        return editor;
    }

    window.toggleSiteNotesEdit = function() {
        var display = document.getElementById('siteNotesDisplay');
        var edit = document.getElementById('siteNotesEdit');
        var btn = document.getElementById('editSiteNotesBtn');
        var isEditing = edit.style.display !== 'none';

        display.style.display = isEditing ? '' : 'none';
        edit.style.display = isEditing ? 'none' : '';
        btn.style.display = isEditing ? '' : 'none';

        if (!isEditing && !siteNotesEditor) {
            siteNotesEditor = initEditor('site_notes_editor');
            if (siteNotesEditor) {
                siteNotesEditor.codemirror.on('change', function() { siteNotesDirty = true; });
            }
        }
        if (isEditing) {
            siteNotesDirty = false;
        }
    };

    window.toggleCredentialsEdit = function() {
        var display = document.getElementById('credentialsDisplay');
        var edit = document.getElementById('credentialsEdit');
        var btn = document.getElementById('editCredentialsBtn');
        var isEditing = edit.style.display !== 'none';

        display.style.display = isEditing ? '' : 'none';
        edit.style.display = isEditing ? 'none' : '';
        btn.style.display = isEditing ? '' : 'none';

        if (!isEditing && !credentialsEditor) {
            credentialsEditor = initEditor('credentials_editor');
            if (credentialsEditor) {
                credentialsEditor.codemirror.on('change', function() { credentialsDirty = true; });
            }
        }
        if (isEditing) {
            credentialsDirty = false;
        }
    };

    // Warn before navigating away with unsaved changes
    window.addEventListener('beforeunload', function(e) {
        if (siteNotesDirty || credentialsDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    // Clear dirty flag on form submit
    document.addEventListener('submit', function(e) {
        if (e.target.id === 'siteNotesForm') siteNotesDirty = false;
        if (e.target.id === 'credentialsForm') credentialsDirty = false;
    });
})();

function showClientSeatEditor(id) {
    document.getElementById('clic-qty-display-' + id).style.display = 'none';
    document.getElementById('clic-qty-form-' + id).style.display = '';
    document.querySelector('#clic-qty-form-' + id + ' input[name=quantity]').focus();
}
function hideClientSeatEditor(id) {
    document.getElementById('clic-qty-display-' + id).style.display = '';
    document.getElementById('clic-qty-form-' + id).style.display = 'none';
}
function confirmClientSeatChange(form, id, oldQty, product, scheduledQty) {
    var newQty = parseInt(form.querySelector('input[name=quantity]').value);
    if (newQty === oldQty && (scheduledQty === null || scheduledQty === oldQty)) {
        hideClientSeatEditor(id);
        return false;
    }
    var msg;
    if (newQty === oldQty && scheduledQty !== null && scheduledQty !== oldQty) {
        msg = 'Cancel the scheduled reduction (' + oldQty + ' → ' + scheduledQty + ') for ' + product + '?\n\nThis will be pushed to AppRiver.';
    } else {
        msg = 'Change ' + product + ' seat count from ' + oldQty + ' to ' + newQty + '?\n\nThis will be pushed to AppRiver.';
    }
    if (!confirm(msg)) return false;
    form.querySelector('button[type=submit]').disabled = true;
    form.querySelector('button[type=submit]').innerHTML = '<i class="bi bi-hourglass-split"></i>';
    return true;
}
</script>
@include('components._tab-persistence', ['tabListId' => 'clientTabs', 'storageKey' => 'client-show-tab'])
<script>
(function() {
    var activityLoaded = false;
    var activityFilters = new Set(['all']);

    // Load activity when tab is shown (element absent on sub-tab pages where it renders as a link)
    var activityTabEl = document.getElementById('activity-tab');
    if (activityTabEl) {
        activityTabEl.addEventListener('shown.bs.tab', function() {
            if (!activityLoaded) {
                loadClientActivity();
            }
        });
    }

    // Also load if tab persistence restores the activity tab on page load
    setTimeout(function() {
        var activeTab = document.querySelector('#clientTabs .nav-link.active');
        if (activeTab && activeTab.id === 'activity-tab' && !activityLoaded) {
            loadClientActivity();
        }
    }, 100);

    function loadClientActivity(before) {
        var pane = document.getElementById('activity');
        var url = pane.dataset.activityUrl;
        if (before) url += '?before=' + encodeURIComponent(before);

        var types = getActivityFilterParam();
        if (types) url += (before ? '&' : '?') + 'types=' + encodeURIComponent(types);

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                html = html.trim();
                var stream = document.getElementById('clientActivityStream');
                if (before) {
                    // Appending more items
                    if (!html || html.includes('No recent activity')) {
                        document.getElementById('clientLoadMoreContainer').innerHTML =
                            '<span class="text-muted small">No more activity.</span>';
                        return;
                    }
                    stream.insertAdjacentHTML('beforeend', html);
                } else {
                    // Initial load
                    stream.innerHTML = html;
                    activityLoaded = true;
                }
                applyActivityFilters();
                // Show/hide Load More
                var items = stream.querySelectorAll('.activity-item');
                var container = document.getElementById('clientLoadMoreContainer');
                if (items.length >= 30) {
                    container.style.display = '';
                }
                // Reset Load More button
                var btn = document.getElementById('clientLoadMoreBtn');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-down me-1"></i>Load More';
                }
            })
            .catch(function() {
                if (!before) {
                    document.getElementById('clientActivityStream').innerHTML =
                        '<div class="text-center py-5 text-muted">Failed to load activity.</div>';
                }
            });
    }

    // Filter logic
    window.clientActivityFilter = function(chip) {
        var filter = chip.dataset.filter;

        if (filter === 'all') {
            activityFilters.clear();
            activityFilters.add('all');
        } else {
            activityFilters.delete('all');
            if (activityFilters.has(filter)) {
                activityFilters.delete(filter);
            } else {
                activityFilters.add(filter);
            }
            if (activityFilters.size === 0) {
                activityFilters.add('all');
            }
        }

        document.querySelectorAll('#activity .stream-filter').forEach(function(c) {
            c.classList.toggle('active', activityFilters.has(c.dataset.filter));
        });

        applyActivityFilters();
    };

    function applyActivityFilters() {
        var showAll = activityFilters.has('all');
        document.querySelectorAll('#clientActivityStream .activity-item').forEach(function(el) {
            el.style.display = (showAll || activityFilters.has(el.dataset.type)) ? '' : 'none';
        });
    }

    function getActivityFilterParam() {
        if (activityFilters.has('all')) return '';
        return Array.from(activityFilters).join(',');
    }

    // Load More
    window.clientLoadMore = function() {
        var items = document.querySelectorAll('#clientActivityStream .activity-item');
        if (items.length === 0) return;

        var lastItem = items[items.length - 1];
        var before = lastItem.dataset.timestamp;
        var btn = document.getElementById('clientLoadMoreBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading...';

        loadClientActivity(before);
    };
})();
</script>
@if(count($integrations) > 0)
<script>
(function() {
    // Integration link/unlink UI
    document.querySelectorAll('.integration-link-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var vendor = btn.dataset.vendor;
            var clientId = btn.dataset.client;
            var form = document.querySelector('.integration-link-form[data-vendor="' + vendor + '"]');
            var loading = form.querySelector('.integration-loading');
            var loaded = form.querySelector('.integration-loaded');
            var errorDiv = form.querySelector('.integration-error');

            btn.classList.add('d-none');
            form.classList.remove('d-none');
            loading.classList.remove('d-none');
            loaded.classList.add('d-none');
            errorDiv.classList.add('d-none');

            var controller = new AbortController();
            var timeout = setTimeout(function() { controller.abort(); }, 20000);

            fetch('/clients/' + clientId + '/integrations/' + vendor + '/entities', {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal
            })
            .then(function(r) {
                clearTimeout(timeout);
                if (!r.ok) return r.json().then(function(d) { throw new Error(d.error || 'API error'); });
                return r.json();
            })
            .then(function(entities) {
                if (entities.error) throw new Error(entities.error);

                var select = form.querySelector('.integration-select');
                select.innerHTML = '<option value="">Select...</option>';
                entities.forEach(function(e) {
                    var opt = document.createElement('option');
                    opt.value = e.id;
                    opt.textContent = e.name || e.id;
                    opt.dataset.name = e.name || '';
                    select.appendChild(opt);
                });

                loading.classList.add('d-none');
                loaded.classList.remove('d-none');

                // Wire up filter
                var filter = form.querySelector('.integration-filter');
                filter.value = '';
                filter.addEventListener('input', function() {
                    var term = filter.value.toLowerCase();
                    Array.from(select.options).forEach(function(opt, i) {
                        if (i === 0) return; // keep "Select..."
                        opt.style.display = (opt.textContent.toLowerCase().indexOf(term) !== -1) ? '' : 'none';
                    });
                });

                // Wire up select change
                select.addEventListener('change', function() {
                    var entityIdInput = form.querySelector('.integration-entity-id');
                    var saveBtn = form.querySelector('button[type="submit"]');
                    entityIdInput.value = select.value;
                    saveBtn.disabled = !select.value;

                    // For QBO, pass display name
                    var displayInput = form.querySelector('.integration-display-name-input');
                    if (displayInput && select.selectedOptions[0]) {
                        displayInput.value = select.selectedOptions[0].dataset.name || '';
                    }
                });
            })
            .catch(function(err) {
                clearTimeout(timeout);
                loading.classList.add('d-none');
                var msg = err.name === 'AbortError'
                    ? 'Request timed out. The vendor API may be slow or unavailable.'
                    : (err.message || 'Could not load entities.');
                errorDiv.querySelector('.alert').textContent = msg;
                errorDiv.classList.remove('d-none');
            });
        });
    });

    // Cancel buttons
    document.querySelectorAll('.integration-cancel-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var vendor = btn.dataset.vendor;
            var form = document.querySelector('.integration-link-form[data-vendor="' + vendor + '"]');
            var linkBtn = document.querySelector('.integration-link-btn[data-vendor="' + vendor + '"]');
            form.classList.add('d-none');
            linkBtn.classList.remove('d-none');
        });
    });
})();
</script>
@endif
@endpush

@push('styles')
<link href="{{ asset('css/activity-stream.css') }}" rel="stylesheet">
<style>.cursor-pointer { cursor: pointer; }</style>
@endpush
