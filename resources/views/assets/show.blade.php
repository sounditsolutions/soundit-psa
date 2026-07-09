@extends('layouts.app')

@section('title', ($asset->hostname ?: $asset->name) . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        @if($asset->client)
            <a href="{{ route('clients.show', $asset->client) }}" class="text-decoration-none text-muted">
                <i class="bi bi-arrow-left me-1"></i>Back to {{ $asset->client->name }}
            </a>
        @else
            <a href="{{ route('assets.index') }}" class="text-decoration-none text-muted">
                <i class="bi bi-arrow-left me-1"></i>Back to Assets
            </a>
        @endif
    </div>
</div>

@if($asset->trashed())
    <div class="alert alert-danger d-flex align-items-center justify-content-between mb-3">
        <div>
            <i class="bi bi-trash me-2"></i>
            This asset was deleted on {{ $asset->deleted_at->toAppTz()->format('M j, Y g:i A') }}.
        </div>
        <form method="POST" action="{{ route('assets.restore', $asset) }}">
            @csrf
            <button type="submit" class="btn btn-outline-danger btn-sm">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
            </button>
        </form>
    </div>
@endif

<div class="row mb-4">
    <div class="col d-flex align-items-center justify-content-between">
        <div>
            <h4 class="section-title mb-1">{{ $asset->hostname ?: $asset->name }}</h4>
            @if($asset->hostname && $asset->hostname !== $asset->name)
                <small class="text-muted">{{ $asset->name }}</small>
            @endif
        </div>
        <div class="d-flex align-items-center gap-2">
            @unless($asset->trashed())
                <a href="#tab-overview" class="text-decoration-none" title="See health breakdown">
                    <x-asset-health-badge :asset="$asset" :showLabel="true" class="fs-6" />
                </a>
            @endunless
            @php $status = $asset->statusBadge; @endphp
            @if($status === 'Online')
                <span class="badge bg-success fs-6" title="Online per RMM">Online</span>
            @elseif($status === 'Offline')
                <span class="badge bg-danger fs-6" title="Offline per RMM">Offline</span>
            @else
                <span class="badge bg-secondary fs-6" title="No RMM status available">Unknown</span>
            @endif
            @unless($asset->is_active)
                <span class="badge bg-secondary fs-6">Inactive</span>
            @endunless
            @if($asset->contracts->isNotEmpty())
                @foreach($asset->contracts as $coveredContract)
                    <a href="{{ route('contracts.show', $coveredContract) }}" class="badge bg-primary text-decoration-none" title="Covered by contract">
                        <i class="bi bi-file-earmark-text me-1"></i>{{ $coveredContract->name }}
                    </a>
                @endforeach
            @endif
            <a href="{{ route('assets.edit', $asset) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            <button type="button" class="btn btn-outline-danger btn-sm" title="Offboard device"
                    data-bs-toggle="modal" data-bs-target="#deleteAssetModal">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
</div>

{{-- Tab Navigation --}}
<ul class="nav nav-tabs mb-0" role="tablist">
    <li class="nav-item">
        @if(($activeTab ?? '') === 'tickets')
            <a class="nav-link" href="{{ route('assets.show', $asset) }}">Overview</a>
        @else
            <a class="nav-link active" data-bs-toggle="tab" href="#tab-overview">Overview</a>
        @endif
    </li>
    {{-- Page-top Network/Storage/Software/Patches tabs. Shown for any RMM-linked
         asset: Ninja/Level hit the Ninja/Level device-data endpoints; a Tactical-only
         asset routes the SAME tabs to its Tactical data (source=tactical) — see the
         AJAX handler's hasTactical branch. Dual-linked (ninja/level + tactical) keeps
         the Ninja/Level tabs (the Tactical card carries the at-a-glance summary). A
         no-RMM asset still shows "not linked to an RMM". psa-ymw8. --}}
    @if($asset->ninja_id || $asset->level_id || $asset->tacticalAsset)
    <li class="nav-item">
        @if(($activeTab ?? '') === 'tickets')
            <a class="nav-link" href="{{ route('assets.show', $asset) }}#tab-network">Network</a>
        @else
            <a class="nav-link" data-bs-toggle="tab" href="#tab-network" data-ajax-section="network">Network</a>
        @endif
    </li>
    <li class="nav-item">
        @if(($activeTab ?? '') === 'tickets')
            <a class="nav-link" href="{{ route('assets.show', $asset) }}#tab-storage">Storage</a>
        @else
            <a class="nav-link" data-bs-toggle="tab" href="#tab-storage" data-ajax-section="storage">Storage</a>
        @endif
    </li>
    <li class="nav-item">
        @if(($activeTab ?? '') === 'tickets')
            <a class="nav-link" href="{{ route('assets.show', $asset) }}#tab-software">Software</a>
        @else
            <a class="nav-link" data-bs-toggle="tab" href="#tab-software" data-ajax-section="software">Software</a>
        @endif
    </li>
    <li class="nav-item">
        @if(($activeTab ?? '') === 'tickets')
            <a class="nav-link" href="{{ route('assets.show', $asset) }}#tab-patches">Patches</a>
        @else
            <a class="nav-link" data-bs-toggle="tab" href="#tab-patches" data-ajax-section="patches">Patches</a>
        @endif
    </li>
    {{-- Checks: Tactical-only (Ninja/Level have no "checks" concept). psa-ymw8. --}}
    @if($asset->tacticalAsset && !$asset->ninja_id && !$asset->level_id)
    <li class="nav-item">
        @if(($activeTab ?? '') === 'tickets')
            <a class="nav-link" href="{{ route('assets.show', $asset) }}#tab-checks">Checks</a>
        @else
            <a class="nav-link" data-bs-toggle="tab" href="#tab-checks" data-ajax-section="checks">Checks</a>
        @endif
    </li>
    @endif
    @endif
    <li class="nav-item">
        @if(($activeTab ?? '') === 'tickets')
            <a class="nav-link" href="{{ route('assets.show', $asset) }}#tab-security">Security</a>
        @else
            <a class="nav-link" data-bs-toggle="tab" href="#tab-security">Security</a>
        @endif
    </li>
    <li class="nav-item">
        @if(($activeTab ?? '') === 'tickets')
            <a class="nav-link active" href="#">
                Alerts & Tickets @if(isset($tickets))<span class="text-muted">({{ $tickets->total() }})</span>@endif
            </a>
        @else
            <a class="nav-link" data-bs-toggle="tab" href="#tab-alerts">Alerts & Tickets</a>
        @endif
    </li>
    @if($asset->ninja_id || $asset->comet_device_id)
    <li class="nav-item">
        @if(($activeTab ?? '') === 'tickets')
            <a class="nav-link" href="{{ route('assets.show', $asset) }}#tab-backup">Backup</a>
        @else
            <a class="nav-link" data-bs-toggle="tab" href="#tab-backup">Backup</a>
        @endif
    </li>
    @endif
</ul>

<div class="tab-content border border-top-0 rounded-bottom bg-white">

    {{-- ==================== TAB 1: OVERVIEW ==================== --}}
    <div class="tab-pane fade {{ ($activeTab ?? '') !== 'tickets' ? 'show active' : '' }} p-3" id="tab-overview">
        @if(!empty($asset->notes))
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-sticky"></i><span>Notes</span>
                </div>
                <div class="card-body">
                    <div class="mb-0" style="white-space: pre-wrap;">{{ $asset->notes }}</div>
                </div>
            </div>
        @endif

        {{-- ==================== ASSET HEALTH SCORE ==================== --}}
        @unless($asset->trashed())
        @php
            $hGrade = $asset->health_grade instanceof \App\Enums\AssetHealthGrade
                ? $asset->health_grade
                : \App\Enums\AssetHealthGrade::fromScore($asset->health_score);
            $hFactors = is_array($asset->health_breakdown) ? $asset->health_breakdown : [];
            $hStatusMeta = [
                'ok' => ['bi-check-circle-fill', 'text-success'],
                'warn' => ['bi-exclamation-circle-fill', 'text-warning'],
                'bad' => ['bi-x-circle-fill', 'text-danger'],
                'unknown' => ['bi-dash-circle', 'text-muted'],
            ];
        @endphp
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span><i class="bi bi-heart-pulse me-2"></i>Health Score</span>
                @if($asset->health_computed_at)
                    <small class="text-muted" title="{{ $asset->health_computed_at->toAppTz()->format('Y-m-d H:i T') }}">
                        Updated {{ $asset->health_computed_at->diffForHumans() }}@if($asset->health_summary_is_ai) · <i class="bi bi-robot"></i> AI @endif
                    </small>
                @endif
            </div>
            <div class="card-body">
                <div class="row g-3 align-items-center">
                    {{-- Score dial --}}
                    <div class="col-auto text-center">
                        <div class="d-inline-flex align-items-center justify-content-center rounded-circle"
                             style="width:84px;height:84px;border:6px solid {{ $hGrade->color() }};">
                            <div>
                                <div style="font-size:1.6rem;font-weight:700;line-height:1;">{{ $asset->health_score ?? '—' }}</div>
                                <div class="text-muted" style="font-size:0.62rem;">/ 100</div>
                            </div>
                        </div>
                        <div><span class="badge {{ $hGrade->badgeClass() }} mt-2">{{ $hGrade->label() }}</span></div>
                    </div>
                    {{-- Explanation + factor breakdown --}}
                    <div class="col">
                        @if($asset->health_summary)
                            <p class="mb-2">{{ $asset->health_summary }}</p>
                        @endif
                        @if(!empty($hFactors))
                            <div class="row g-2 small">
                                @foreach($hFactors as $f)
                                    @php [$fIcon, $fColor] = $hStatusMeta[$f['status'] ?? 'unknown'] ?? $hStatusMeta['unknown']; @endphp
                                    <div class="col-md-6 d-flex align-items-start gap-2">
                                        <i class="bi {{ $fIcon }} {{ $fColor }} mt-1"></i>
                                        <div>
                                            <strong>{{ $f['label'] ?? '' }}</strong>@if(($f['points'] ?? 0) < 0) <span class="text-danger">({{ $f['points'] }})</span>@endif
                                            <br><span class="text-muted">{{ $f['detail'] ?? '' }}</span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
        @endunless

        <div class="row g-4">
            {{-- Left column: Device Identity --}}
            <div class="col-md-6">
                <h6 class="text-muted mb-3"><i class="bi bi-pc-display me-1"></i>Device Identity</h6>
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted" style="width: 140px;">Name</th>
                            <td>{{ $asset->name }}</td>
                        </tr>
                        @if($asset->hostname && $asset->hostname !== $asset->name)
                        <tr>
                            <th class="text-muted">Hostname</th>
                            <td>{{ $asset->hostname }}</td>
                        </tr>
                        @endif
                        <tr>
                            <th class="text-muted">Type</th>
                            <td>{{ $asset->asset_type ?: '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Serial</th>
                            <td>{{ $asset->serial_number ?: '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">OS</th>
                            <td>{{ $asset->os ?: '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">IP Address</th>
                            <td>{{ $asset->ip_address ?: '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Last User</th>
                            <td>
                                @if($lastUserPerson)
                                    <x-person-badge :person="$lastUserPerson" :size="20" />
                                @else
                                    {{ $asset->last_user ?: '-' }}
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Warranty</th>
                            <td>
                                @if($asset->warranty_start)
                                    <span class="text-muted small">System age: {{ $asset->warranty_start->diffForHumans(null, true) }}</span>
                                @endif
                                @if($asset->warranty_end)
                                    @if($asset->warranty_end->isPast())
                                        <span class="text-danger"><i class="bi bi-x-circle me-1"></i>Expired {{ $asset->warranty_end->toAppTz()->format('M j, Y') }}</span>
                                    @else
                                        <span class="text-success"><i class="bi bi-check-circle me-1"></i>Active until {{ $asset->warranty_end->toAppTz()->format('M j, Y') }}</span>
                                    @endif
                                @elseif(!$asset->warranty_start)
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Client</th>
                            <td><x-client-badge :client="$asset->client" :size="24" fallback="Unassigned" /></td>
                        </tr>
                        @if($asset->contracts->isNotEmpty())
                        <tr>
                            <th class="text-muted">Contracts</th>
                            <td>
                                @foreach($asset->contracts as $contract)
                                    <a href="{{ route('contracts.show', $contract) }}" class="badge bg-primary text-decoration-none me-1">
                                        {{ $contract->name }}
                                    </a>
                                @endforeach
                            </td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>

            {{-- Right column: Status & Hardware --}}
            <div class="col-md-6">
                <h6 class="text-muted mb-3"><i class="bi bi-cpu me-1"></i>Status & Hardware</h6>
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted" style="width: 140px;">Status</th>
                            <td>
                                @if($status === 'Online')
                                    <span class="text-success"><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem; vertical-align: middle;"></i>Online</span>
                                @elseif($status === 'Offline')
                                    <span class="text-danger"><i class="bi bi-circle-fill me-1" style="font-size: 0.5rem; vertical-align: middle;"></i>Offline</span>
                                @else
                                    <span class="text-muted">Unknown</span>
                                @endif
                                @if($asset->last_seen_at)
                                    <br><small class="text-muted" title="{{ $asset->last_seen_at->toAppTz()->format('Y-m-d H:i T') }}">Last seen {{ $asset->last_seen_at->diffForHumans() }}</small>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Uptime</th>
                            <td>
                                @if($asset->last_boot_at)
                                    @php
                                        $diff = $asset->last_boot_at->diff(now());
                                        $parts = [];
                                        if ($diff->days > 0) $parts[] = $diff->days . 'd';
                                        if ($diff->h > 0) $parts[] = $diff->h . 'h';
                                        if (empty($parts)) $parts[] = $diff->i . 'm';
                                        $uptimeStr = implode(' ', $parts);
                                    @endphp
                                    {{ $uptimeStr }}
                                    @if($asset->needs_reboot)
                                        <span class="badge bg-warning text-dark ms-2"><i class="bi bi-arrow-clockwise me-1"></i>Reboot needed</span>
                                    @endif
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">CPU</th>
                            <td>{{ $asset->cpu ?: '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">RAM</th>
                            <td>{{ $asset->ram_gb ? $asset->ram_gb . ' GB' : '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Disk</th>
                            <td>{{ $asset->disk_summary ?: '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">RMM</th>
                            <td>
                                @if($asset->ninja_id)
                                    @if($asset->ninja_url)
                                        <a href="{{ $asset->ninja_url }}" target="_blank" class="text-decoration-none">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>Ninja
                                        </a>
                                    @else
                                        Ninja
                                    @endif
                                @endif
                                @if($asset->ninja_id && $asset->level_id)
                                    <span class="text-muted mx-1">|</span>
                                @endif
                                @if($asset->level_id)
                                    @if($asset->level_url)
                                        <a href="{{ $asset->level_url }}" target="_blank" class="text-decoration-none">
                                            <i class="bi bi-box-arrow-up-right me-1"></i>Level
                                        </a>
                                    @else
                                        Level
                                    @endif
                                @endif
                                @if($asset->screenconnect_session_id)
                                    @if($asset->ninja_id || $asset->level_id)
                                        <span class="text-muted mx-1">|</span>
                                    @endif
                                    @php $scUrl = \App\Support\ScreenConnectConfig::sessionUrl($asset->screenconnect_session_id); @endphp
                                    @if($scUrl)
                                        <a href="{{ $scUrl }}" target="_blank" class="text-decoration-none">
                                            <i class="bi bi-display me-1"></i>ScreenConnect
                                        </a>
                                    @else
                                        ScreenConnect
                                    @endif
                                @endif
                                @if($asset->tacticalAsset)
                                    @if($asset->ninja_id || $asset->level_id || $asset->screenconnect_session_id)
                                        <span class="text-muted mx-1">|</span>
                                    @endif
                                    Tactical
                                @endif
                                @if(!$asset->ninja_id && !$asset->level_id && !$asset->screenconnect_session_id && !$asset->tacticalAsset)
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Last Synced</th>
                            <td>
                                @if($asset->ninja_synced_at)
                                    {{ $asset->ninja_synced_at->diffForHumans() }}
                                @elseif($asset->level_synced_at)
                                    {{ $asset->level_synced_at->diffForHumans() }}
                                @else
                                    <span class="text-muted">Never</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>

                @if($asset->ninja_id || $asset->level_id)
                <div class="mt-3 pt-3 border-top">
                    <form method="POST" action="{{ route('assets.refresh', $asset) }}">
                        @csrf
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-arrow-clockwise me-1"></i>Refresh from RMM
                        </button>
                    </form>
                </div>
                @endif
            </div>
        </div>
        {{-- Tactical RMM --}}
        @if($asset->tacticalAsset)
        @php
            $ta = $asset->tacticalAsset;
            // E3: surface the maintenance state best-effort. `maintenance_mode` is
            // NOT currently synced onto tactical_assets (no column — P3 is schema-
            // free), so this attribute is null/absent and the badge stays hidden
            // until the toggle is used. Tracked gap: see the report / INSTALL.md.
            $maintenanceOn = (bool) ($ta->maintenance_mode ?? false);
            // P4 amendment H: freshness sits next to the status badge (one unit),
            // amber when an "online" claim is stale (the dangerous misread).
            $insight = $tacticalInsight ?? null;
            $freshStale = $insight?->stale ?? false;
        @endphp
        <div class="card shadow-sm card-static mb-3 mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-pc-display me-2"></i>Tactical RMM</span>
                <span class="d-flex align-items-center gap-2">
                    {{-- E3: prominent "alerts muted" warning when maintenance is ON,
                         placed right next to the device status. --}}
                    <span class="badge bg-warning text-dark" id="tacticalMaintenanceBadge"
                          style="{{ $maintenanceOn ? '' : 'display:none;' }}">
                        <i class="bi bi-bell-slash me-1"></i>Maintenance — alerts muted
                    </span>
                    {{-- amendment H: status + freshness read as one unit. --}}
                    <span class="badge {{ $ta->statusBadgeClass() }}" id="tacticalStatusBadge">{{ ucfirst($ta->status) }}</span>
                    @if($ta->synced_at)
                    <span class="small tactical-freshness {{ $freshStale ? 'tactical-freshness-stale text-warning-emphasis fw-semibold' : 'text-muted' }}"
                          id="tacticalFreshness"
                          title="{{ $ta->synced_at->toAppTz()->format('Y-m-d H:i T') }}">
                        @if($freshStale)<i class="bi bi-clock-history me-1"></i>@endif synced {{ $ta->synced_at->diffForHumans() }}
                    </span>
                    @endif
                    {{-- amendment J: in-place AJAX refresh-now (no page reload). --}}
                    <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-1" id="tacticalRefreshBtn"
                            data-asset-refresh-url="{{ route('assets.tactical-refresh', $asset) }}"
                            title="Refresh status from the agent now">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </span>
            </div>
            <div class="card-body">
                {{-- Eager at-a-glance health line (amendment B): snapshot / local-DB
                     derived — ZERO live calls on render. Failing-checks count ("—"
                     when unknown, never "0"), open-alerts, pending-patches, last-seen. --}}
                @if($insight)
                <div class="d-flex flex-wrap gap-3 mb-3 pb-3 border-bottom small tactical-health-line" id="tacticalHealthLine">
                    <span title="Failing monitoring checks">
                        <i class="bi bi-clipboard2-check me-1 {{ ($insight->checksFailing ?? 0) > 0 ? 'text-danger' : 'text-muted' }}"></i>
                        {{-- fix #2: a degraded/STALE clean signal must never read as a
                             confident green "all passing" (the amendment-H misread the
                             status badge guards but the chips didn't). Positive copy
                             only when checks were actually read clean AND are fresh;
                             otherwise a muted "as of last sync" qualifier. The negative
                             "N failing" count still shows regardless of staleness. --}}
                        @if($insight->checksFailing === null)
                            checks: <span class="text-muted">—</span>
                        @elseif($insight->checksFailing > 0)
                            <span class="text-danger fw-semibold">{{ $insight->checksFailing }} checks failing</span>
                        @elseif($insight->checksKnownClean() && !$insight->stale)
                            <span class="text-success">checks: all passing</span>
                        @else
                            checks: <span class="text-muted">clean as of last sync</span>
                        @endif
                    </span>
                    <span title="Open alerts">
                        <i class="bi bi-bell me-1 {{ $insight->openAlerts > 0 ? 'text-warning' : 'text-muted' }}"></i>
                        {{ $insight->openAlerts }} {{ \Illuminate\Support\Str::plural('alert', $insight->openAlerts) }}
                    </span>
                    <span title="Pending updates">
                        <i class="bi bi-shield-check me-1 {{ $ta->has_patches_pending ? 'text-warning' : 'text-muted' }}"></i>
                        {{-- fix #2: same staleness guard as the checks chip. A stale
                             "no patches pending" snapshot must not claim a confident
                             green "up to date"; the pending WARNING always shows. --}}
                        @if($ta->has_patches_pending)
                            <span class="text-warning-emphasis">updates pending</span>
                        @elseif(!$insight->stale)
                            <span class="text-success">up to date</span>
                        @else
                            <span class="text-muted">patched as of last sync</span>
                        @endif
                    </span>
                    @if($insight->needsReboot)
                    <span title="A reboot is required" class="text-warning-emphasis">
                        <i class="bi bi-arrow-repeat me-1"></i>reboot needed
                    </span>
                    @endif
                    @if($ta->last_seen_at)
                    <span class="text-muted ms-auto" title="{{ $ta->last_seen_at->toAppTz()->format('Y-m-d H:i T') }}">
                        <i class="bi bi-eye me-1"></i>seen {{ $ta->last_seen_at->diffForHumans() }}
                    </span>
                    @endif
                </div>
                @endif
                <div id="tacticalRefreshResult" class="small mb-2" style="display:none;"></div>
                {{-- E3: maintenance is an ALWAYS-VISIBLE control near the device
                     status (never buried in the script/power card). It drives the
                     non-destructive set_maintenance action (single click, no
                     confirm token). --}}
                <div class="d-flex justify-content-between align-items-center mb-3 pb-3 border-bottom">
                    <div>
                        <div class="fw-semibold small"><i class="bi bi-bell-slash me-1"></i>Maintenance mode</div>
                        <div class="text-muted" style="font-size:0.8rem;" id="tacticalMaintenanceHint">
                            @if($maintenanceOn)
                                Alerts are <strong>muted</strong> for this device.
                            @else
                                Mute monitoring alerts (e.g. while working on this device).
                            @endif
                        </div>
                    </div>
                    <div class="form-check form-switch m-0">
                        <input class="form-check-input" type="checkbox" role="switch"
                               id="tacticalMaintenanceToggle"
                               data-asset-maintenance-url="{{ route('assets.maintenance-tactical', $asset) }}"
                               {{ $maintenanceOn ? 'checked' : '' }}>
                        <label class="form-check-label visually-hidden" for="tacticalMaintenanceToggle">Maintenance mode</label>
                    </div>
                </div>
                <div id="tacticalMaintenanceResult" class="small mb-2" style="display:none;"></div>
                <table class="table table-borderless table-sm mb-0">
                    <tbody>
                        @if($ta->agent_version)
                        <tr>
                            <td class="text-muted" style="width:40%">Agent Version</td>
                            <td>{{ $ta->agent_version }}</td>
                        </tr>
                        @endif
                        @if($ta->last_seen_at)
                        <tr>
                            <td class="text-muted">Last Seen</td>
                            <td>{{ $ta->last_seen_at->diffForHumans() }}</td>
                        </tr>
                        @endif
                        <tr>
                            <td class="text-muted">Needs Reboot</td>
                            <td>
                                @if($ta->needs_reboot)
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Yes</span>
                                @else
                                    <span class="text-success">No</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">Patches Pending</td>
                            <td>
                                @if($ta->has_patches_pending)
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle me-1"></i>Yes</span>
                                @else
                                    <span class="text-success">No</span>
                                @endif
                            </td>
                        </tr>
                        @if($ta->os)
                        <tr>
                            <td class="text-muted">OS</td>
                            <td>{{ $ta->os }}</td>
                        </tr>
                        @endif
                        @if($ta->public_ip)
                        <tr>
                            <td class="text-muted">Public IP</td>
                            <td>{{ $ta->public_ip }}</td>
                        </tr>
                        @endif
                        @if(is_array($ta->local_ips) && count($ta->local_ips) > 0)
                        <tr>
                            <td class="text-muted">Local IPs</td>
                            <td>{{ implode(', ', $ta->local_ips) }}</td>
                        </tr>
                        @endif
                        @if($ta->make_model)
                        <tr>
                            <td class="text-muted">Make/Model</td>
                            <td>{{ $ta->make_model }}</td>
                        </tr>
                        @endif
                        @if($ta->cpu)
                        <tr>
                            <td class="text-muted">CPU</td>
                            <td>{{ $ta->cpu }}</td>
                        </tr>
                        @endif
                        @if($ta->ram_gb)
                        <tr>
                            <td class="text-muted">RAM</td>
                            <td>{{ $ta->ram_gb }} GB</td>
                        </tr>
                        @endif
                        @if($ta->disk_summary)
                        <tr>
                            <td class="text-muted">Disk</td>
                            <td>{{ $ta->disk_summary }}</td>
                        </tr>
                        @endif
                        @if($ta->serial_number)
                        <tr>
                            <td class="text-muted">Serial Number</td>
                            <td>{{ $ta->serial_number }}</td>
                        </tr>
                        @endif
                        @if($ta->synced_at)
                        <tr>
                            <td class="text-muted">Synced</td>
                            <td class="text-muted small">{{ $ta->synced_at->diffForHumans() }}</td>
                        </tr>
                        @endif
                    </tbody>
                </table>

                {{-- psa-ymw8: the under-card Tactical telemetry accordion
                     (Checks/Patches/Software/Network/Storage) was removed — the
                     owner found the collapsed panels buried the data after dev-test.
                     That data now lives in the prominent page-top tabs, which route
                     to Tactical data for a Tactical-only asset (see the AJAX handler's
                     hasTactical branch + window.renderTacticalSection). The Tactical
                     card keeps the at-a-glance summary, refresh-now + recent actions. --}}
            </div>
            {{-- psa-6h5r: the Open-in-Tactical link targets the configured WEB
                 dashboard base (TacticalConfig::webUrl()), NOT the API root.
                 Hidden when unset — no fallback to the API URL (the old bug). --}}
            @if($tacticalWebUrl = \App\Support\TacticalConfig::webUrl())
            <div class="card-footer text-end">
                <a href="{{ rtrim($tacticalWebUrl, '/') }}" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-box-arrow-up-right me-1"></i>Open in Tactical RMM
                </a>
            </div>
            @endif
        </div>
        @endif

        {{-- Recent Tactical actions — ITIL change history (P4 amendment K). NOT a
             flat activity log: each row ties an endpoint change to the incident it
             was performed under (ticket link), distinguishes outcomes (succeeded /
             no-op / error / blocked), caps at 10 newest-first, and renders from the
             already-redacted action-log rows (no re-leak). Co-located under the
             Tactical region. --}}
        @if($asset->tacticalAsset && $tacticalInsight)
        @php
            $recentActions = $tacticalInsight->recentActions;
            $actionTotal = $tacticalActionTotal ?? count($recentActions);
            // ok→success, offline→warning (no-op), error→danger, rejected/denied/
            // blocked→secondary. Reuses the statusBadgeClass colour vocabulary.
            $outcomeBadge = function (string $status): array {
                return match ($status) {
                    'ok' => ['bg-success', 'succeeded'],
                    'offline' => ['bg-warning text-dark', 'no-op (agent unreachable)'],
                    'error' => ['bg-danger', 'error'],
                    'rejected', 'denied', 'blocked' => ['bg-secondary', $status],
                    default => ['bg-secondary', $status],
                };
            };
            $actionLabel = function (string $key): string {
                return \Illuminate\Support\Str::of($key)
                    ->after('tactical.')
                    ->replace('_', ' ')
                    ->title()
                    ->toString();
            };
        @endphp
        <div class="card shadow-sm card-static mb-3" id="tactical-recent-actions">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-clock-history me-2"></i>Recent Tactical actions</span>
                @if($actionTotal > count($recentActions))
                    <span class="badge bg-light text-dark" title="There are more actions than shown">
                        {{ count($recentActions) }} most recent of {{ $actionTotal }}
                    </span>
                @endif
            </div>
            <div class="card-body p-0">
                @if(empty($recentActions))
                    <div class="text-muted small p-3"><i class="bi bi-info-circle me-1"></i>No recent Tactical actions for this device.</div>
                @else
                <ul class="list-group list-group-flush">
                    @foreach($recentActions as $row)
                    @php
                        [$badgeClass, $outcomeText] = $outcomeBadge($row['result_status']);
                        $when = $row['when'] ? \Illuminate\Support\Carbon::parse($row['when']) : null;
                    @endphp
                    <li class="list-group-item py-2 small">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div>
                                <span class="fw-semibold">{{ $actionLabel($row['action']) }}</span>
                                <span class="text-muted">· {{ $row['actor'] }}</span>
                                @if(! empty($row['ticket_id']))
                                    · under
                                    <a href="{{ route('tickets.show', $row['ticket_id']) }}" class="text-decoration-none">#{{ $row['ticket_id'] }}</a>
                                @endif
                                @if($when)
                                    <span class="text-muted">· <span title="{{ $when->toAppTz()->format('Y-m-d H:i T') }}">{{ $when->diffForHumans() }}</span></span>
                                @endif
                            </div>
                            <span class="badge {{ $badgeClass }} flex-shrink-0">{{ $outcomeText }}</span>
                        </div>
                    </li>
                    @endforeach
                </ul>
                @endif
            </div>
        </div>
        @endif

        {{-- Tactical Script Runner + Actions --}}
        {{-- M4: render WHENEVER Tactical-linked. The daily snapshot status is
             advisory only; the bus's offline result is the source of truth, so
             we show the controls with a clear offline affordance rather than
             letting the card vanish. --}}
        @if($asset->tacticalAsset)
        @php
            $tacticalOnline = $asset->tacticalAsset->status === 'online';
            $tacticalHostname = $asset->tacticalAsset->hostname ?? $asset->hostname ?? '';
            $tacticalSyncedAt = $asset->tacticalAsset->synced_at;
            $tacticalIsServer = ($asset->tacticalAsset->monitoring_type ?? null) === 'server';
        @endphp
        <div class="card shadow-sm card-static mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-terminal me-2"></i>Run Script</span>
                @unless($tacticalOnline)
                    <span class="badge bg-secondary" title="Per the last sync. The action will still be attempted and will report if the agent is offline.">
                        <i class="bi bi-cloud-slash me-1"></i>offline (as of {{ $tacticalSyncedAt?->toAppTz()->format('M j, g:i A') ?? 'last sync' }})
                    </span>
                @endunless
            </div>
            <div class="card-body">
                @unless($tacticalOnline)
                    <div class="alert alert-secondary py-2 small mb-2">
                        <i class="bi bi-info-circle me-1"></i>This device was <strong>offline</strong> at the last sync. You can still attempt an action — it will run if the agent has since come online, or report back that it is offline.
                    </div>
                @endunless
                <div class="mb-2">
                    <select class="form-select form-select-sm" id="tacticalScriptSelect">
                        <option value="">Select a script...</option>
                        @php
                            $tacticalScripts = \App\Models\TacticalScript::where('hidden', false)
                                ->orderBy('category')
                                ->orderBy('name')
                                ->get();
                            $grouped = $tacticalScripts->groupBy('category');
                        @endphp
                        @foreach($grouped as $category => $categoryScripts)
                            <optgroup label="{{ $category ?: 'Uncategorized' }}">
                                @foreach($categoryScripts as $script)
                                    <option value="{{ $script->id }}"
                                            data-timeout="{{ $script->default_timeout }}"
                                            data-description="{{ e($script->description) }}"
                                            data-shell="{{ $script->shell }}">
                                        {{ $script->name }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div class="mb-2 small text-muted" id="tacticalScriptDesc" style="display:none;"></div>
                <div class="row g-2 mb-2">
                    <div class="col">
                        <input type="text" class="form-control form-control-sm" id="tacticalScriptArgs"
                               placeholder="Arguments (optional)">
                    </div>
                    <div class="col-auto">
                        <select class="form-select form-select-sm" id="tacticalScriptTimeout" style="width: 110px;">
                            <option value="30">30s</option>
                            <option value="60">60s</option>
                            <option value="120" selected>120s</option>
                            <option value="300">5m</option>
                            <option value="600">10m</option>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-primary btn-sm" id="tacticalRunBtn" disabled
                                onclick="runTacticalScript()">
                            <i class="bi bi-play-fill me-1"></i>Run
                        </button>
                    </div>
                </div>
                <div id="tacticalScriptResult" style="display:none;">
                    <div class="border rounded p-2 bg-dark text-light small font-monospace" style="max-height: 300px; overflow-y: auto; white-space: pre-wrap;" id="tacticalScriptOutput"></div>
                    <div class="mt-1 small text-muted" id="tacticalScriptMeta"></div>
                </div>

                {{-- Run command (DESTRUCTIVE — arbitrary RCE → confirm-gated).
                     E5: shell pre-selected by device OS, still changeable; the
                     command field has a <datalist> of common diagnostics. --}}
                @php
                    // E5 smart default: Windows -> cmd, otherwise shell. Parity
                    // allowlist still lets the tech switch.
                    $tacticalIsWindows = stripos((string) ($asset->tacticalAsset->os ?? $asset->os ?? ''), 'win') !== false;
                    $tacticalDefaultShell = $tacticalIsWindows ? 'cmd' : 'shell';
                @endphp
                <hr class="my-3">
                <div class="mb-2">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small text-muted"><i class="bi bi-terminal-fill me-1"></i>Run command</span>
                    </div>
                    <div class="row g-2">
                        <div class="col-auto">
                            <select class="form-select form-select-sm" id="tacticalCmdShell" style="width: 130px;" aria-label="Shell">
                                <option value="cmd" @selected($tacticalDefaultShell === 'cmd')>cmd</option>
                                <option value="powershell" @selected($tacticalDefaultShell === 'powershell')>powershell</option>
                                <option value="shell" @selected($tacticalDefaultShell === 'shell')>shell</option>
                            </select>
                        </div>
                        <div class="col">
                            <input type="text" class="form-control form-control-sm" id="tacticalCmdInput"
                                   list="tacticalCmdSuggestions" autocomplete="off"
                                   placeholder="Command to run (e.g. whoami)">
                            <datalist id="tacticalCmdSuggestions">
                                <option value="whoami"></option>
                                <option value="hostname"></option>
                                <option value="ipconfig /all"></option>
                                <option value="ip addr"></option>
                                <option value="systeminfo"></option>
                                <option value="uname -a"></option>
                                <option value="Get-ComputerInfo"></option>
                                <option value="gpupdate /force"></option>
                                <option value="nltest /dsgetdc:"></option>
                            </datalist>
                        </div>
                        <div class="col-auto">
                            <button type="button" class="btn btn-outline-danger btn-sm" id="tacticalCmdBtn"
                                    data-bs-toggle="modal" data-bs-target="#tacticalCmdModal" disabled>
                                <i class="bi bi-play-fill me-1"></i>Run…
                            </button>
                        </div>
                    </div>
                    <div id="tacticalCmdResult" class="mt-2" style="display:none;">
                        <div class="border rounded p-2 bg-dark text-light small font-monospace" style="max-height: 300px; overflow-y: auto; white-space: pre-wrap;" id="tacticalCmdOutput"></div>
                    </div>
                </div>

                {{-- Recover agent services (non-destructive → single click, mode=mesh) --}}
                <hr class="my-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small text-muted">
                        <i class="bi bi-arrow-repeat me-1"></i>Recover agent
                        <span class="text-muted d-block" style="font-size:0.75rem;">Restart the monitoring agent's services if it's misbehaving.</span>
                    </span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="tacticalRecoverBtn"
                            data-asset-recover-url="{{ route('assets.recover-tactical', $asset) }}">
                        <i class="bi bi-arrow-repeat me-1"></i>Recover agent
                    </button>
                </div>
                <div id="tacticalRecoverResult" class="mt-2 small" style="display:none;"></div>

                {{-- Remote access via MeshCentral --}}
                <hr class="my-3">
                <div class="mt-3">
                    <label class="form-label small text-muted d-block">Remote access (MeshCentral)</label>
                    <div class="btn-group btn-group-sm" role="group">
                        <button type="button" class="btn btn-outline-secondary" data-mesh-type="control"><i class="bi bi-display me-1"></i>Control</button>
                        <button type="button" class="btn btn-outline-secondary" data-mesh-type="terminal"><i class="bi bi-terminal me-1"></i>Terminal</button>
                        <button type="button" class="btn btn-outline-secondary" data-mesh-type="file"><i class="bi bi-folder me-1"></i>Files</button>
                    </div>
                    <div class="text-danger small mt-1 d-none" data-mesh-error></div>
                </div>
                <script>
                document.querySelectorAll('[data-mesh-type]').forEach(btn => btn.addEventListener('click', async () => {
                    const err = document.querySelector('[data-mesh-error]'); err.classList.add('d-none');
                    const tab = window.open('', '_blank');                          // open SYNCHRONOUSLY in the gesture (popup-safe)
                    const label = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
                    try {
                        const r = await fetch(@json(route('assets.tactical-meshcentral', $asset)), {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                            body: JSON.stringify({ type: btn.dataset.meshType }),
                        });
                        const j = await r.json();
                        if (!r.ok) throw new Error(j.error || 'Could not open remote session.');
                        if (tab) tab.location = j.url; else window.location = j.url;   // open verbatim (G1)
                    } catch (e) {
                        if (tab) tab.close();
                        err.textContent = e.message; err.classList.remove('d-none');
                    } finally { btn.disabled = false; btn.innerHTML = label; }
                }));
                </script>

                {{-- Destructive actions --}}
                <hr class="my-3">
                <div class="d-flex justify-content-between align-items-center">
                    <span class="small text-muted"><i class="bi bi-exclamation-octagon me-1"></i>Power</span>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-danger btn-sm" id="tacticalShutdownBtn"
                                data-bs-toggle="modal" data-bs-target="#tacticalShutdownModal">
                            <i class="bi bi-power me-1"></i>Shut down
                        </button>
                        <button type="button" class="btn btn-outline-danger btn-sm" id="tacticalRebootBtn"
                                data-bs-toggle="modal" data-bs-target="#tacticalRebootModal">
                            <i class="bi bi-arrow-clockwise me-1"></i>Reboot
                        </button>
                    </div>
                </div>
                <div id="tacticalRebootResult" class="mt-2 small" style="display:none;"></div>
                <div id="tacticalShutdownResult" class="mt-2 small" style="display:none;"></div>
            </div>
        </div>

        {{-- Reboot confirm modal: typed-hostname gate (m3) + server caution (M5) --}}
        <div class="modal fade" id="tacticalRebootModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-arrow-clockwise me-2"></i>Reboot device</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        @if($tacticalIsServer)
                            <div class="alert alert-danger py-2">
                                <i class="bi bi-hdd-rack me-1"></i><strong>This is a SERVER.</strong>
                                Rebooting will disconnect users and stop services running on it. Proceed only if you are certain.
                            </div>
                        @else
                            <div class="alert alert-warning py-2">
                                <i class="bi bi-exclamation-triangle me-1"></i>This will reboot the device now. Any unsaved work on it will be lost.
                            </div>
                        @endif
                        <p class="mb-2 small">To confirm, type the device hostname exactly:</p>
                        <p class="mb-2"><code class="user-select-all">{{ $tacticalHostname }}</code></p>
                        <input type="text" class="form-control form-control-sm" id="tacticalRebootHostname"
                               autocomplete="off" placeholder="Type the hostname to confirm">
                        <div class="text-danger small mt-1" id="tacticalRebootError" style="display:none;"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger btn-sm" id="tacticalRebootConfirm" disabled
                                data-expected-hostname="{{ $tacticalHostname }}">
                            <i class="bi bi-arrow-clockwise me-1"></i>Reboot now
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- E2: open tickets on this asset for the OPTIONAL destructive ticket-link. --}}
        @php
            $tacticalOpenTickets = $asset->tickets()->open()
                ->orderByDesc('tickets.updated_at')
                ->limit(50)
                ->get(['tickets.id', 'tickets.subject']);
        @endphp

        {{-- Run-command confirm modal (DESTRUCTIVE — arbitrary RCE).
             A3: the FULL resolved command shows in a multi-line <pre> (nothing
             scrolls out of view). The displayed command is intentionally NOT
             secret-redacted — the tech sees their own input on their own screen;
             the AUDIT row + any ticket note ARE redacted server-side (amendment
             A3/B1). E5: editing the command after it is shown forces a re-confirm.
             E4: usable at ~375px (the <pre>, hostname input, and confirm button
             are all reachable without the button scrolling off). --}}
        <div class="modal fade" id="tacticalCmdModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-terminal-fill me-2"></i>Run command</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger py-2">
                            <i class="bi bi-exclamation-octagon me-1"></i><strong>This runs a command directly on the device</strong>
                            with full agent privileges. Review it carefully — there is no undo.
                        </div>
                        <p class="mb-1 small text-muted">This exact command will run:</p>
                        <pre class="border rounded bg-body-tertiary p-2 mb-3" style="white-space: pre-wrap; word-break: break-word; max-height: 40vh; overflow-y: auto;" id="tacticalCmdPreview"></pre>

                        @if($tacticalOpenTickets->isNotEmpty())
                        <div class="mb-3">
                            <label class="form-label small mb-1" for="tacticalCmdTicket">Link to a ticket (optional)</label>
                            <select class="form-select form-select-sm" id="tacticalCmdTicket">
                                <option value="">— none —</option>
                                @foreach($tacticalOpenTickets as $t)
                                    <option value="{{ $t->id }}">#{{ $t->id }} — {{ \Illuminate\Support\Str::limit($t->subject, 60) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif

                        <p class="mb-2 small">To confirm, type the device hostname exactly:</p>
                        <p class="mb-2"><code class="user-select-all">{{ $tacticalHostname }}</code></p>
                        <input type="text" class="form-control form-control-sm" id="tacticalCmdHostname"
                               autocomplete="off" placeholder="Type the hostname to confirm">
                        <div class="text-danger small mt-1" id="tacticalCmdError" style="display:none;"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger btn-sm" id="tacticalCmdConfirm" disabled
                                data-expected-hostname="{{ $tacticalHostname }}"
                                data-cmd-url="{{ route('assets.run-tactical-command', $asset) }}">
                            <i class="bi bi-play-fill me-1"></i>Run command
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Shutdown confirm modal (DESTRUCTIVE — extra-loud).
             D2 (verbatim): the device cannot be powered back on remotely. E5:
             uniform typed-FULL-hostname (same as reboot). E4: mobile-usable. --}}
        <div class="modal fade" id="tacticalShutdownModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-power me-2"></i>Shut down device</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger py-2">
                            <i class="bi bi-power me-1"></i><strong>This device powers off and cannot be powered back on remotely.</strong>
                            Recovery requires physical or IPMI/out-of-band access. Only shut down if someone can power it back on.
                        </div>
                        @if($tacticalIsServer)
                            <div class="alert alert-warning py-2 small">
                                <i class="bi bi-hdd-rack me-1"></i><strong>This is a SERVER.</strong>
                                Shutting it down disconnects users and stops every service running on it.
                            </div>
                        @endif

                        @if($tacticalOpenTickets->isNotEmpty())
                        <div class="mb-3">
                            <label class="form-label small mb-1" for="tacticalShutdownTicket">Link to a ticket (optional)</label>
                            <select class="form-select form-select-sm" id="tacticalShutdownTicket">
                                <option value="">— none —</option>
                                @foreach($tacticalOpenTickets as $t)
                                    <option value="{{ $t->id }}">#{{ $t->id }} — {{ \Illuminate\Support\Str::limit($t->subject, 60) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @endif

                        <p class="mb-2 small">To confirm, type the device hostname exactly:</p>
                        <p class="mb-2"><code class="user-select-all">{{ $tacticalHostname }}</code></p>
                        <input type="text" class="form-control form-control-sm" id="tacticalShutdownHostname"
                               autocomplete="off" placeholder="Type the hostname to confirm">
                        <div class="text-danger small mt-1" id="tacticalShutdownError" style="display:none;"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-danger btn-sm" id="tacticalShutdownConfirm" disabled
                                data-expected-hostname="{{ $tacticalHostname }}"
                                data-shutdown-url="{{ route('assets.shutdown-tactical', $asset) }}">
                            <i class="bi bi-power me-1"></i>Shut down now
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Users --}}
        <div class="card shadow-sm card-static mb-3 mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-people me-2"></i>Users</span>
            </div>
            @if($asset->users->isEmpty())
                <div class="card-body text-muted text-center py-3 small">
                    No users assigned to this device.
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <tbody>
                            @foreach($asset->users->sortByDesc('pivot.is_primary') as $user)
                                <tr>
                                    <td>
                                        <x-person-badge :person="$user" :size="20" />
                                        @if($user->pivot->is_primary)
                                            <span class="badge bg-warning text-dark ms-1">Primary</span>
                                        @endif
                                    </td>
                                    <td class="small text-muted">
                                        <span class="badge {{ $user->pivot->assignment_source === 'manual' ? 'bg-primary' : 'bg-secondary' }}">
                                            {{ ucfirst($user->pivot->assignment_source) }}
                                        </span>
                                    </td>
                                    <td class="small text-muted">
                                        @if($user->pivot->last_seen_at)
                                            {{ \Carbon\Carbon::parse($user->pivot->last_seen_at)->diffForHumans() }}
                                        @endif
                                    </td>
                                    <td class="text-end text-nowrap">
                                        @unless($user->pivot->is_primary)
                                            <form method="POST" action="{{ route('assets.set-primary-user', [$asset, $user]) }}" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-outline-warning btn-sm py-0 px-1" title="Set as primary">
                                                    <i class="bi bi-star"></i>
                                                </button>
                                            </form>
                                        @endunless
                                        <form method="POST" action="{{ route('assets.remove-user', [$asset, $user]) }}" class="d-inline"
                                              onsubmit="return confirm('Remove {{ $user->full_name }} from this device?')">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-1" title="Remove">
                                                <i class="bi bi-x"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <div class="card-footer">
                <form method="POST" action="{{ route('assets.add-user', $asset) }}" class="d-flex gap-2">
                    @csrf
                    <select name="person_id" class="form-select form-select-sm" required>
                        <option value="">Add user...</option>
                        @foreach($clientPeople ?? [] as $p)
                            <option value="{{ $p->id }}">{{ $p->last_name }}, {{ $p->first_name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm text-nowrap">
                        <i class="bi bi-plus-lg me-1"></i>Add
                    </button>
                </form>
            </div>
        </div>

    </div>

    {{-- TABS 2-6 (Network/Storage/Software/Patches/Checks). Panes render for any
         RMM-linked asset; the AJAX handler routes a Tactical-only asset to its
         Tactical data (source=tactical). Same gate as the nav tabs above. psa-ymw8. --}}
    @if($asset->ninja_id || $asset->level_id || $asset->tacticalAsset)
    {{-- ==================== TAB 2: NETWORK (AJAX) ==================== --}}
    <div class="tab-pane fade" id="tab-network">
        <div class="d-flex justify-content-center py-5"><div class="spinner-border text-primary" role="status"></div></div>
    </div>

    {{-- ==================== TAB 3: STORAGE (AJAX) ==================== --}}
    <div class="tab-pane fade" id="tab-storage">
        <div class="d-flex justify-content-center py-5"><div class="spinner-border text-primary" role="status"></div></div>
    </div>

    {{-- ==================== TAB 4: SOFTWARE (AJAX) ==================== --}}
    <div class="tab-pane fade" id="tab-software">
        <div class="d-flex justify-content-center py-5"><div class="spinner-border text-primary" role="status"></div></div>
    </div>

    {{-- ==================== TAB 5: PATCHES (AJAX) ==================== --}}
    <div class="tab-pane fade" id="tab-patches">
        <div class="d-flex justify-content-center py-5"><div class="spinner-border text-primary" role="status"></div></div>
    </div>

    {{-- ============= TAB 6: CHECKS (AJAX, Tactical-only) ============= --}}
    @if($asset->tacticalAsset && !$asset->ninja_id && !$asset->level_id)
    <div class="tab-pane fade" id="tab-checks">
        <div class="d-flex justify-content-center py-5"><div class="spinner-border text-primary" role="status"></div></div>
    </div>
    @endif
    @endif

    {{-- ==================== TAB 6: SECURITY ==================== --}}
    <div class="tab-pane fade p-3" id="tab-security">

        {{-- M365 / Intune --}}
        @if($asset->m365_device_id)
        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-microsoft me-2"></i>M365 / Intune</div>
            <div class="card-body">
                @if($asset->m365_synced_at?->lt(now()->subDays(2)))
                    <div class="alert alert-warning py-1 px-2 small mb-2">
                        <i class="bi bi-exclamation-triangle me-1"></i>Data may be stale (synced {{ $asset->m365_synced_at->diffForHumans() }})
                    </div>
                @endif
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted" style="width: 140px;">Compliance</th>
                            <td>
                                @if($asset->m365_is_compliant === true)
                                    <span class="badge bg-success">Compliant</span>
                                @elseif($asset->m365_compliance_state)
                                    <span class="badge bg-danger">{{ $asset->m365_compliance_label }}</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                        @if($asset->m365_enrollment_type)
                        <tr>
                            <th class="text-muted">Enrollment</th>
                            <td>{{ $asset->m365_enrollment_type }}</td>
                        </tr>
                        @endif
                        @if($asset->m365_os_version)
                        <tr>
                            <th class="text-muted">OS Version</th>
                            <td>{{ $asset->m365_os_version }}</td>
                        </tr>
                        @endif
                        @if($asset->m365_device_owner_type)
                        <tr>
                            <th class="text-muted">Ownership</th>
                            <td>{{ ucfirst($asset->m365_device_owner_type) }}</td>
                        </tr>
                        @endif
                        @if($asset->m365_defender_status)
                        <tr>
                            <th class="text-muted">Defender</th>
                            <td>
                                {{ $asset->m365_defender_status }}
                                @if($asset->m365_defender_version)
                                    <span class="text-muted ms-1 small">{{ $asset->m365_defender_version }}</span>
                                @endif
                            </td>
                        </tr>
                        @endif
                        @if($asset->m365_last_scan_at)
                        <tr>
                            <th class="text-muted">Last Scan</th>
                            <td>{{ $asset->m365_last_scan_at->diffForHumans() }}</td>
                        </tr>
                        @endif
                        @if($asset->m365_last_sync_at)
                        <tr>
                            <th class="text-muted">Last Intune Sync</th>
                            <td>{{ $asset->m365_last_sync_at->diffForHumans() }}</td>
                        </tr>
                        @endif
                        @if($asset->m365_synced_at)
                        <tr>
                            <th class="text-muted">Data as of</th>
                            <td class="text-muted small">{{ $asset->m365_synced_at->diffForHumans() }}</td>
                        </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- DNS Security (Control D) --}}
        @if($asset->controld_device_id)
        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-shield-lock me-2"></i>DNS Security (Control D)</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted" style="width: 140px;">Profile</th>
                            <td>{{ $asset->controld_profile_name ?: '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Device Status</th>
                            <td>
                                @if($asset->controld_status === 1)
                                    <span class="badge bg-success">Active</span>
                                @elseif($asset->controld_status === 0)
                                    <span class="badge bg-warning text-dark">Pending</span>
                                @elseif($asset->controld_status !== null)
                                    <span class="badge bg-danger">Disabled</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Agent</th>
                            <td>
                                @if($asset->controld_agent_status === 1)
                                    <span class="badge bg-success">Connected</span>
                                @elseif($asset->controld_agent_status !== null)
                                    <span class="badge bg-danger">Disconnected</span>
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                                @if($asset->controld_agent_version)
                                    <span class="text-muted ms-1">{{ $asset->controld_agent_version }}</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Agent Last Seen</th>
                            <td>
                                @if($asset->controld_last_seen_at)
                                    {{ $asset->controld_last_seen_at->diffForHumans() }}
                                @else
                                    <span class="text-muted">-</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <th class="text-muted">Synced</th>
                            <td>
                                @if($asset->controld_synced_at)
                                    {{ $asset->controld_synced_at->diffForHumans() }}
                                @else
                                    <span class="text-muted">Never</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div class="mt-3 pt-3 border-top">
                    <form method="POST" action="{{ route('assets.controld.unlink', $asset) }}" class="d-inline">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm"
                                onclick="return confirm('Remove Control D link from this asset?')">
                            <i class="bi bi-x-circle me-1"></i>Unlink
                        </button>
                    </form>
                    @if(\App\Support\ControlDConfig::isAnalyticsConfigured())
                        <button type="button" class="btn btn-outline-primary btn-sm ms-2" id="btnDnsActivity" onclick="loadDnsActivity(1)">
                            <i class="bi bi-activity me-1"></i>View DNS Activity
                        </button>
                    @endif
                </div>
            </div>
        </div>

        {{-- DNS Activity Log (loaded via AJAX) --}}
        @if(\App\Support\ControlDConfig::isAnalyticsConfigured())
        <div id="dnsActivityContainer" style="display: none;">
            <div class="card shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-activity me-2"></i>DNS Activity</span>
                    <div class="btn-group btn-group-sm" id="dnsTimeRange">
                        <button type="button" class="btn btn-outline-secondary active" data-hours="1">1h</button>
                        <button type="button" class="btn btn-outline-secondary" data-hours="4">4h</button>
                        <button type="button" class="btn btn-outline-secondary" data-hours="24">24h</button>
                    </div>
                </div>
                <div class="card-body p-0" id="dnsActivityBody">
                    <div class="text-center py-4">
                        <div class="spinner-border spinner-border-sm text-muted" role="status"></div>
                        <span class="text-muted ms-2">Loading DNS activity...</span>
                    </div>
                </div>
            </div>
        </div>
        @endif

        @elseif($controldDevices !== null)
        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-shield-lock me-2"></i>DNS Security (Control D)</div>
            <div class="card-body">
                @if(count($controldDevices) > 0)
                    <form method="POST" action="{{ route('assets.controld.link', $asset) }}">
                        @csrf
                        <div class="mb-3">
                            <label for="controld_device_id" class="form-label">Link to Control D Device</label>
                            <select name="controld_device_id" id="controld_device_id" class="form-select" required>
                                <option value="">Select a device...</option>
                                @foreach($controldDevices as $cd)
                                    <option value="{{ $cd['PK'] }}">
                                        {{ $cd['name'] ?? $cd['PK'] }}
                                        @if(isset($cd['profile']['name']))
                                            &mdash; {{ $cd['profile']['name'] }}
                                        @endif
                                        @if(isset($cd['ctrld']['status']) && $cd['ctrld']['status'] === 1)
                                            (Connected)
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-link-45deg me-1"></i>Link
                        </button>
                    </form>
                @else
                    <p class="text-muted mb-0">
                        <i class="bi bi-info-circle me-1"></i>All Control D devices in this organization are already linked to assets.
                    </p>
                @endif
            </div>
        </div>
        @endif

        {{-- DNS Security (Zorus) --}}
        @if($asset->zorus_endpoint_id)
        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-shield-check me-2"></i>DNS Security (Zorus)</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        @if($asset->zorus_group_name)
                        <tr><td class="text-muted" style="width:40%">Group</td><td>{{ $asset->zorus_group_name }}</td></tr>
                        @endif
                        <tr>
                            <td class="text-muted">Filtering</td>
                            <td>
                                @if($asset->zorus_filtering_enabled)
                                    <span class="badge bg-success">Enabled</span>
                                @else
                                    <span class="badge bg-secondary">Disabled</span>
                                @endif
                            </td>
                        </tr>
                        <tr>
                            <td class="text-muted">CyberSight</td>
                            <td>
                                @if($asset->zorus_cybersight_enabled)
                                    <span class="badge bg-success">Enabled</span>
                                @else
                                    <span class="badge bg-secondary">Disabled</span>
                                @endif
                            </td>
                        </tr>
                        @if($asset->zorus_agent_version)
                        <tr><td class="text-muted">Agent Version</td><td>{{ $asset->zorus_agent_version }}</td></tr>
                        @endif
                        @if($asset->zorus_agent_state)
                        <tr>
                            <td class="text-muted">Agent State</td>
                            <td>
                                @if($asset->zorus_agent_state === 'Online')
                                    <span class="badge bg-success">{{ $asset->zorus_agent_state }}</span>
                                @else
                                    <span class="badge bg-warning text-dark">{{ $asset->zorus_agent_state }}</span>
                                @endif
                            </td>
                        </tr>
                        @endif
                        @if($asset->zorus_last_seen_at)
                        <tr><td class="text-muted">Last Seen</td><td>{{ $asset->zorus_last_seen_at->toAppTz()->format('Y-m-d H:i T') }}</td></tr>
                        @endif
                        @if($asset->zorus_synced_at)
                        <tr><td class="text-muted">Synced</td><td>{{ $asset->zorus_synced_at->toAppTz()->format('Y-m-d H:i T') }}</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
            <div class="card-footer text-end">
                <form method="POST" action="{{ route('assets.zorus.unlink', $asset) }}" class="d-inline"
                      onsubmit="return confirm('Remove the Zorus link from this asset?')">
                    @csrf
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-x-circle me-1"></i>Unlink
                    </button>
                </form>
            </div>
        </div>

        @elseif($zorusEndpoints !== null)
        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-shield-check me-2"></i>DNS Security (Zorus)</div>
            <div class="card-body">
                @if(count($zorusEndpoints) > 0)
                    <form method="POST" action="{{ route('assets.zorus.link', $asset) }}">
                        @csrf
                        <div class="mb-3">
                            <label for="zorus_endpoint_id" class="form-label">Link to Zorus Endpoint</label>
                            <select name="zorus_endpoint_id" id="zorus_endpoint_id" class="form-select" required>
                                <option value="">Select an endpoint...</option>
                                @foreach($zorusEndpoints as $ep)
                                    <option value="{{ $ep['uuid'] }}">
                                        {{ $ep['name'] ?? $ep['uuid'] }}
                                        @if($ep['agentState'] ?? false)
                                            ({{ $ep['agentState'] }})
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                        </div>
                        <button type="submit" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-link-45deg me-1"></i>Link
                        </button>
                    </form>
                @else
                    <p class="text-muted mb-0">
                        <i class="bi bi-info-circle me-1"></i>All Zorus endpoints for this customer are already linked to assets.
                    </p>
                @endif
            </div>
        </div>
        @endif

        {{-- ScreenConnect --}}
        @if($asset->screenconnect_session_id)
        <div class="card shadow-sm mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div><i class="bi bi-display me-2"></i>ScreenConnect</div>
                @if($asset->screenconnect_online)
                    <span class="badge bg-success">Online</span>
                @elseif($asset->screenconnect_online === false)
                    <span class="badge bg-secondary">Offline</span>
                @else
                    <span class="badge bg-light text-dark">Unknown</span>
                @endif
            </div>
            <div class="card-body">
                @if($asset->screenconnect_synced_at?->lt(now()->subDays(7)))
                    <div class="alert alert-warning py-1 px-2 small mb-2">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        No events received since {{ $asset->screenconnect_synced_at->diffForHumans() }}
                    </div>
                @endif
                <table class="table table-borderless table-sm mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted" style="width: 140px;">Session ID</th>
                            <td class="font-monospace small">{{ Str::limit($asset->screenconnect_session_id, 20) }}</td>
                        </tr>
                        @if($asset->screenconnect_client_version)
                        <tr>
                            <th class="text-muted">Agent Version</th>
                            <td>{{ $asset->screenconnect_client_version }}</td>
                        </tr>
                        @endif
                        <tr>
                            <th class="text-muted">Last Seen</th>
                            <td>{{ $asset->screenconnect_last_seen_at?->diffForHumans() ?? '-' }}</td>
                        </tr>
                        <tr>
                            <th class="text-muted">Last Synced</th>
                            <td>{{ $asset->screenconnect_synced_at?->diffForHumans() ?? '-' }}</td>
                        </tr>
                    </tbody>
                </table>

                @php
                    $scUrl = \App\Support\ScreenConnectConfig::sessionUrl($asset->screenconnect_session_id);
                    $recentScEvents = \App\Models\ScreenConnectEvent::where('asset_id', $asset->id)
                        ->orderByDesc('event_time')
                        ->limit(10)
                        ->get();
                @endphp

                @if($scUrl)
                <div class="mt-2 pt-2 border-top">
                    <a href="{{ $scUrl }}" target="_blank" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-box-arrow-up-right me-1"></i>Open in ScreenConnect
                    </a>
                </div>
                @endif

                @if($recentScEvents->isNotEmpty())
                <div class="mt-3 pt-3 border-top">
                    <h6 class="small fw-bold mb-2">Recent Activity</h6>
                    <div class="small" style="max-height: 200px; overflow-y: auto;">
                        @foreach($recentScEvents as $evt)
                            <div class="d-flex justify-content-between py-1 {{ !$loop->last ? 'border-bottom' : '' }}">
                                <div>
                                    <span class="badge bg-light text-dark">{{ $evt->event_type }}</span>
                                    @if($evt->host)
                                        <span class="text-muted">by {{ $evt->host }}</span>
                                    @endif
                                    @if($evt->data)
                                        <div class="text-muted text-truncate" style="max-width: 300px;"
                                             title="{{ $evt->data }}">{{ $evt->data }}</div>
                                    @endif
                                </div>
                                <span class="text-muted text-nowrap ms-2"
                                      title="{{ $evt->event_time?->toAppTz()->format('Y-m-d H:i T') }}">
                                    {{ $evt->event_time?->diffForHumans() }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </div>
        @endif

        {{-- MFA Status from resolved person --}}
        @if($lastUserPerson && $lastUserPerson->mfa_enabled !== null)
        <div class="card shadow-sm mb-3">
            <div class="card-header"><i class="bi bi-shield-lock-fill me-2"></i>MFA Status</div>
            <div class="card-body">
                <table class="table table-borderless mb-0">
                    <tbody>
                        <tr>
                            <th class="text-muted" style="width: 140px;">User</th>
                            <td><x-person-badge :person="$lastUserPerson" :size="20" /></td>
                        </tr>
                        <tr>
                            <th class="text-muted">MFA</th>
                            <td>
                                @if($lastUserPerson->mfa_enabled)
                                    <span class="badge bg-success">Enabled</span>
                                @else
                                    <span class="badge bg-danger">Disabled</span>
                                @endif
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        @if(!$asset->m365_device_id && !$asset->controld_device_id && $controldDevices === null && !$asset->zorus_endpoint_id && $zorusEndpoints === null && !$asset->screenconnect_session_id)
            <p class="text-muted">No security integrations linked to this asset.</p>
        @endif
    </div>

    {{-- ==================== TAB 7: ALERTS & TICKETS ==================== --}}
    <div class="tab-pane fade {{ ($activeTab ?? '') === 'tickets' ? 'show active' : '' }} p-3" id="tab-alerts">
        @if(($activeTab ?? '') === 'tickets')
            @include('tickets._list', [
                'listRoute' => 'assets.tickets',
                'prefilter' => ['asset' => $asset->id, 'asset_id' => $asset->id],
                'filters' => $ticketFilters,
                'clients' => $ticketClients,
                'users' => $ticketUsers,
                'statuses' => $ticketStatuses,
                'priorities' => $ticketPriorities,
                'types' => $ticketTypes,
                'sources' => $ticketSources,
                'closedTicketCount' => $closedTicketCount ?? 0,
            ])
        @else
        {{-- Unified Alerts --}}
        @php
            $activeAlerts = $asset->activeAlerts;
            $resolvedAlerts = $asset->alerts()->where('status', 'resolved')->latest('resolved_at')->limit(10)->get();
        @endphp
        @if($activeAlerts->isNotEmpty() || $resolvedAlerts->isNotEmpty())
        <div class="card shadow-sm mb-3 {{ $activeAlerts->isNotEmpty() ? 'border-warning' : '' }}">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span>
                    <i class="bi bi-bell me-2"></i>Alerts
                    @if($activeAlerts->isNotEmpty())
                        <span class="badge bg-danger ms-1">{{ $activeAlerts->count() }} active</span>
                    @endif
                </span>
                <div class="d-flex gap-2">
                    @if($resolvedAlerts->isNotEmpty())
                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#resolvedAlerts">
                            Show resolved
                        </button>
                    @endif
                    <a href="{{ route('alerts.index', ['asset_id' => $asset->id]) }}" class="btn btn-sm btn-outline-primary">
                        View all
                    </a>
                </div>
            </div>
            <div class="card-body p-0">
                @if($activeAlerts->isNotEmpty())
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Source</th>
                            <th>Severity</th>
                            <th>Alert</th>
                            <th>Message</th>
                            <th>Fired</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($activeAlerts as $alert)
                        <tr>
                            <td class="text-nowrap">
                                <i class="bi {{ $alert->source->icon() }} me-1" title="{{ $alert->source->label() }}"></i>
                                <small class="text-muted">{{ $alert->source->label() }}</small>
                            </td>
                            <td>
                                <span class="badge {{ $alert->severity->badgeClass() }}">{{ $alert->severity->label() }}</span>
                            </td>
                            <td>
                                <a href="#" class="text-decoration-none alert-detail-link"
                                   data-alert-title="{{ e($alert->title) }}"
                                   data-alert-message="{{ e($alert->message) }}"
                                   data-alert-severity="{{ $alert->severity->label() }}"
                                   data-alert-source="{{ $alert->source->label() }}"
                                   data-alert-hostname="{{ $alert->hostname ?? $asset->hostname ?? '-' }}"
                                   data-alert-client="{{ $asset->client?->name ?? '-' }}"
                                   data-alert-status="{{ $alert->status->label() }}"
                                   data-alert-fired="{{ $alert->fired_at?->toAppTz()->format('M j, Y g:ia T') ?? '-' }}"
                                   data-alert-refired="{{ $alert->refired_count }}"
                                   data-alert-acknowledged="{{ $alert->acknowledged_at?->toAppTz()->format('M j, Y g:ia T') ?? '' }}"
                                   data-alert-acknowledged-by="{{ $alert->acknowledgedByUser?->name ?? '' }}"
                                   data-alert-resolved="{{ $alert->resolved_at?->toAppTz()->format('M j, Y g:ia T') ?? '' }}"
                                   data-alert-source-url="{{ $alert->sourceUrl() ?? '' }}"
                                   data-alert-metadata="{{ e(json_encode($alert->metadata)) }}"
                                   title="Click for details">{{ $alert->title }}</a>
                            </td>
                            <td class="small">{{ Str::limit($alert->message, 100) }}</td>
                            <td class="text-nowrap small">{{ $alert->fired_at?->toAppTz()->format('M j, g:i A') }}</td>
                            <td>
                                <span class="badge {{ $alert->status->badgeClass() }}">{{ $alert->status->label() }}</span>
                                @if($alert->ticket_id)
                                    <a href="{{ route('tickets.show', $alert->ticket_id) }}" class="badge bg-primary text-decoration-none ms-1">
                                        #{{ $alert->ticket?->display_id }}
                                    </a>
                                @endif
                            </td>
                            <td class="text-nowrap">
                                @if($alert->status->value === 'active')
                                    <form method="POST" action="{{ route('alerts.acknowledge', $alert) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-xs btn-outline-info" title="Acknowledge">
                                            <i class="bi bi-check"></i>
                                        </button>
                                    </form>
                                @endif
                                @if(!$alert->ticket_id)
                                    <form method="POST" action="{{ route('alerts.create-ticket', $alert) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-xs btn-outline-primary" title="Create Ticket">
                                            <i class="bi bi-ticket-perforated"></i>
                                        </button>
                                    </form>
                                @endif
                                <form method="POST" action="{{ route('alerts.resolve', $alert) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-xs btn-outline-secondary" title="Resolve"
                                        onclick="return confirm('Resolve this alert?')">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @else
                    <p class="text-muted small p-3 mb-0">No active alerts.</p>
                @endif

                @if($resolvedAlerts->isNotEmpty())
                <div class="collapse" id="resolvedAlerts">
                    <hr class="my-0">
                    <table class="table table-sm mb-0 table-light">
                        <thead>
                            <tr>
                                <th>Source</th>
                                <th>Severity</th>
                                <th>Alert</th>
                                <th>Message</th>
                                <th>Fired</th>
                                <th>Resolved</th>
                                <th>Ticket</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($resolvedAlerts as $alert)
                            <tr class="text-muted">
                                <td class="text-nowrap">
                                    <i class="bi {{ $alert->source->icon() }} me-1"></i>
                                    <small>{{ $alert->source->label() }}</small>
                                </td>
                                <td>
                                    <span class="badge {{ $alert->severity->badgeClass() }}">{{ $alert->severity->label() }}</span>
                                </td>
                                <td>
                                    <a href="#" class="text-muted text-decoration-none alert-detail-link"
                                       data-alert-title="{{ e($alert->title) }}"
                                       data-alert-message="{{ e($alert->message) }}"
                                       data-alert-severity="{{ $alert->severity->label() }}"
                                       data-alert-source="{{ $alert->source->label() }}"
                                       data-alert-hostname="{{ $alert->hostname ?? $asset->hostname ?? '-' }}"
                                       data-alert-client="{{ $asset->client?->name ?? '-' }}"
                                       data-alert-status="{{ $alert->status->label() }}"
                                       data-alert-fired="{{ $alert->fired_at?->toAppTz()->format('M j, Y g:ia T') ?? '-' }}"
                                       data-alert-refired="{{ $alert->refired_count }}"
                                       data-alert-acknowledged="{{ $alert->acknowledged_at?->toAppTz()->format('M j, Y g:ia T') ?? '' }}"
                                       data-alert-acknowledged-by="{{ $alert->acknowledgedByUser?->name ?? '' }}"
                                       data-alert-resolved="{{ $alert->resolved_at?->toAppTz()->format('M j, Y g:ia T') ?? '' }}"
                                       data-alert-source-url="{{ $alert->sourceUrl() ?? '' }}"
                                       data-alert-metadata="{{ e(json_encode($alert->metadata)) }}"
                                       title="Click for details">{{ $alert->title }}</a>
                                </td>
                                <td class="small">{{ Str::limit($alert->message, 100) }}</td>
                                <td class="text-nowrap small">{{ $alert->fired_at?->toAppTz()->format('M j, g:i A') }}</td>
                                <td class="text-nowrap small">{{ $alert->resolved_at?->toAppTz()->format('M j, g:i A') }}</td>
                                <td>
                                    @if($alert->ticket_id)
                                        <a href="{{ route('tickets.show', $alert->ticket_id) }}">
                                            #{{ $alert->ticket?->display_id }}
                                        </a>
                                    @endif
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
        </div>
        @include('alerts._detail_modal')
        @else
            <p class="text-muted small mb-3">No alerts for this device.</p>
        @endif

        {{-- Recent Tickets --}}
        @php $recentTickets = $asset->tickets()->with('assignee')->latest('updated_at')->limit(15)->get(); @endphp
        @if($recentTickets->isNotEmpty())
        <div class="card shadow-sm">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-ticket-perforated me-2"></i>Recent Tickets
                </div>
                <a href="{{ route('assets.tickets', $asset) }}" class="btn btn-outline-primary btn-sm">View all tickets</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Subject</th>
                            <th>Priority</th>
                            <th>Status</th>
                            <th>Assignee</th>
                            <th>Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($recentTickets as $ticket)
                            <tr class="cursor-pointer" onclick="window.location='{{ route('tickets.show', $ticket) }}'">
                                <td class="small text-muted">{{ $ticket->display_id }}</td>
                                <td>
                                    <a href="{{ route('tickets.show', $ticket) }}" class="text-decoration-none">
                                        {{ Str::limit($ticket->subject, 50) }}
                                    </a>
                                </td>
                                <td>
                                    @if($ticket->priority)
                                        <span class="badge {{ $ticket->priority->badgeClass() }}">{{ $ticket->priority->label() }}</span>
                                    @else
                                        <span class="text-muted">-</span>
                                    @endif
                                </td>
                                <td><span class="badge {{ $ticket->status->badgeClass() }}">{{ $ticket->status->label() }}</span></td>
                                <td class="small">{{ $ticket->assignee?->name ?? '-' }}</td>
                                <td class="small">{{ $ticket->updated_at?->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @else
            <p class="text-muted">No tickets linked to this asset.</p>
        @endif
        @endif
    </div>

    {{-- ==================== TAB 8: BACKUP ==================== --}}
    <div class="tab-pane fade p-3" id="tab-backup">
        @if($asset->client && $asset->client->comet_group_id)
            <div class="d-flex align-items-center gap-2 mb-3">
                <form action="{{ route('assets.comet.toggle-backup', $asset) }}" method="POST" class="d-inline">
                    @csrf
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox"
                               {{ $asset->comet_backup_enabled ? 'checked' : '' }}
                               onchange="this.form.submit()">
                        <label class="form-check-label">
                            {{ $asset->comet_backup_enabled ? 'Backup enabled' : 'Backup not enabled' }}
                        </label>
                    </div>
                </form>
            </div>
        @endif
        @if($asset->client && $asset->client->servosity_company_id)
            <div class="d-flex align-items-center gap-2 mb-3">
                <form action="{{ route('assets.servosity.toggle-backup', $asset) }}" method="POST" class="d-inline">
                    @csrf
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox"
                               {{ $asset->servosity_backup_enabled ? 'checked' : '' }}
                               {{ !$asset->tacticalAsset && !$asset->servosity_backup_enabled ? 'disabled' : '' }}
                               onchange="this.form.submit()">
                        <label class="form-check-label">
                            Servosity: {{ $asset->servosity_backup_enabled ? 'Backup enabled' : 'Backup not enabled' }}
                            @if(!$asset->tacticalAsset && !$asset->servosity_backup_enabled)
                                <small class="text-muted">(no Tactical agent linked)</small>
                            @endif
                        </label>
                    </div>
                </form>
            </div>
            @if($asset->servosity_backup_enabled)
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-cloud-arrow-up me-2"></i>Servosity DR Backup</div>
                    <div class="card-body">
                        @if(!$asset->servosity_dr_backup_id)
                            <div class="d-flex align-items-center text-warning mb-3">
                                <i class="bi bi-hourglass-split me-2"></i>
                                <div>
                                    <strong>Waiting for deployment to complete.</strong>
                                    <br><small class="text-muted">Servosity One, ScreenConnect, and the backup user will be installed automatically via Tactical. DR backup account will be provisioned once the agent registers (checked hourly).</small>
                                </div>
                            </div>
                        @else
                            <div class="d-flex align-items-center text-success mb-3">
                                <i class="bi bi-check-circle me-2"></i>
                                <strong>DR backup account provisioned</strong>
                                <small class="text-muted ms-1">(ID: {{ $asset->servosity_dr_backup_id }})</small>
                            </div>
                        @endif

                        @if($asset->servosity_backup_password)
                            <div class="card bg-light mb-3">
                                <div class="card-body py-2">
                                    <div class="fw-semibold mb-1">Backup Credential</div>
                                    <div class="row g-2 small">
                                        <div class="col-sm-3 text-muted">Username</div>
                                        <div class="col-sm-9"><code>{{ \App\Support\ServosityConfig::get('credential_username') }}</code></div>
                                        <div class="col-sm-3 text-muted">Password</div>
                                        <div class="col-sm-9">
                                            <code id="servosity-pass" class="user-select-all">{{ $asset->servosity_backup_password }}</code>
                                            <button type="button" class="btn btn-outline-secondary btn-sm ms-1 py-0 px-1" onclick="navigator.clipboard.writeText(document.getElementById('servosity-pass').textContent)" title="Copy">
                                                <i class="bi bi-clipboard"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <div class="mt-2 small text-muted">
                                        A local admin account with this username and password is created automatically on the device.
                                        Add these credentials to the <strong>Credential & Keys</strong> page in the Servosity portal for this company.
                                    </div>
                                    <a href="https://portal.servosity.com" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm mt-2">
                                        <i class="bi bi-box-arrow-up-right me-1"></i>Open Servosity Portal
                                    </a>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif
        @endif
        @if($asset->comet_device_id)
            {{-- Comet Backup Storage --}}
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-cloud-arrow-up me-2"></i>Backup Storage (Comet)</div>
                <div class="card-body">
                    @if($asset->backup_synced_at)
                        <div class="row">
                            <div class="col-md-4">
                                <strong>Cloud Storage</strong><br>
                                {{ $asset->backup_cloud_bytes ? \App\Support\Format::bytes($asset->backup_cloud_bytes) : '—' }}
                            </div>
                            <div class="col-md-4">
                                <strong>Local Storage</strong><br>
                                {{ $asset->backup_local_bytes ? \App\Support\Format::bytes($asset->backup_local_bytes) : '—' }}
                            </div>
                            <div class="col-md-4">
                                <strong>Last Synced</strong><br>
                                <span title="{{ $asset->backup_synced_at->toAppTz()->format('Y-m-d H:i:s T') }}">
                                    {{ $asset->backup_synced_at->diffForHumans() }}
                                </span>
                            </div>
                        </div>
                    @else
                        <p class="text-muted mb-0">No backup data synced yet.</p>
                    @endif
                </div>
            </div>

            {{-- Comet Backup Jobs --}}
            @if($cometJobData && !empty($cometJobData['jobs']))
                <div class="card mb-3">
                    <div class="card-header">
                        <i class="bi bi-clock-history me-2"></i>Recent Backup Jobs
                        @if($cometJobData['last_success'])
                            <span class="badge bg-success ms-2">Last success: {{ $cometJobData['last_success']['started'] }}</span>
                        @endif
                        @if($cometJobData['last_failure'])
                            <span class="badge bg-danger ms-2">Last failure: {{ $cometJobData['last_failure']['started'] }}</span>
                        @endif
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Status</th>
                                        <th>Type</th>
                                        <th>Started</th>
                                        <th>Duration</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($cometJobData['jobs'] as $job)
                                        <tr>
                                            <td>
                                                @php
                                                    $badgeClass = match($job['status']) {
                                                        'Completed' => 'bg-success',
                                                        'Failed' => 'bg-danger',
                                                        'Warning' => 'bg-warning text-dark',
                                                        'Running' => 'bg-info',
                                                        'Cancelled' => 'bg-secondary',
                                                        default => 'bg-secondary',
                                                    };
                                                @endphp
                                                <span class="badge {{ $badgeClass }}">{{ $job['status'] }}</span>
                                            </td>
                                            <td>{{ $job['classification'] }}</td>
                                            <td>{{ $job['started'] }}</td>
                                            <td>
                                                @if($job['duration_seconds'])
                                                    {{ gmdate('H:i:s', $job['duration_seconds']) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            @elseif($cometJobData)
                <div class="card mb-3">
                    <div class="card-header"><i class="bi bi-clock-history me-2"></i>Recent Backup Jobs</div>
                    <div class="card-body">
                        <p class="text-muted mb-0">No recent backup jobs found.</p>
                    </div>
                </div>
            @endif
        @elseif($asset->ninja_id)
            {{-- Backup Storage --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header"><i class="bi bi-cloud-arrow-up me-2"></i>Backup Storage</div>
                <div class="card-body">
                    @if($asset->backup_synced_at)
                        <table class="table table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <th class="text-muted" style="width: 140px;">Cloud Storage</th>
                                    <td>
                                        @if($asset->backup_cloud_bytes !== null)
                                            {{ $asset->backup_cloud_bytes > 0 ? \App\Support\Format::bytes($asset->backup_cloud_bytes) : '0 B' }}
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Local Storage</th>
                                    <td>
                                        @if($asset->backup_local_bytes !== null)
                                            {{ $asset->backup_local_bytes > 0 ? \App\Support\Format::bytes($asset->backup_local_bytes) : '0 B' }}
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Revisions</th>
                                    <td>
                                        @if($asset->backup_revisions_bytes !== null)
                                            {{ $asset->backup_revisions_bytes > 0 ? \App\Support\Format::bytes($asset->backup_revisions_bytes) : '0 B' }}
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Data as of</th>
                                    <td>
                                        {{ $asset->backup_synced_at->diffForHumans() }}
                                        <br><small class="text-muted">{{ $asset->backup_synced_at->toAppTz()->format('Y-m-d H:i T') }}</small>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    @else
                        <p class="text-muted mb-0">
                            <i class="bi bi-shield-x me-1"></i>No backup detected.
                            <br><small>If this device has backup enabled in NinjaRMM, data will appear after the next sync.</small>
                        </p>
                    @endif
                </div>
            </div>

            {{-- Backup Jobs --}}
            @if($backupJobs !== null)
                @if(!empty($backupJobs['jobs']))
                @php
                    $lastSuccess = collect($backupJobs['jobs'])->firstWhere('status', 'COMPLETED');
                    $lastFailure = collect($backupJobs['jobs'])->firstWhere('status', 'FAILED');
                @endphp
                <div class="card shadow-sm mb-3">
                    <div class="card-header"><i class="bi bi-clock-history me-2"></i>Recent Backup Jobs</div>
                    <div class="card-body pb-2">
                        <div class="d-flex gap-4 mb-3">
                            <div>
                                <small class="text-muted d-block">Last Successful</small>
                                @if($lastSuccess && isset($lastSuccess['startTime']))
                                    <span class="text-success fw-semibold">{{ \Carbon\Carbon::parse($lastSuccess['startTime'])->toAppTz()->format('M j, g:i A') }}</span>
                                @else
                                    <span class="text-muted">None</span>
                                @endif
                            </div>
                            <div>
                                <small class="text-muted d-block">Last Failed</small>
                                @if($lastFailure && isset($lastFailure['startTime']))
                                    <span class="text-danger fw-semibold">{{ \Carbon\Carbon::parse($lastFailure['startTime'])->toAppTz()->format('M j, g:i A') }}</span>
                                @else
                                    <span class="text-muted">None</span>
                                @endif
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Plan Type</th>
                                    <th>Started</th>
                                    <th>Duration</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($backupJobs['jobs'] as $job)
                                    <tr>
                                        <td>
                                            @switch($job['status'] ?? '')
                                                @case('COMPLETED')
                                                    <span class="badge bg-success">Completed</span>
                                                    @break
                                                @case('FAILED')
                                                    <span class="badge bg-danger">Failed</span>
                                                    @break
                                                @case('RUNNING')
                                                    <span class="badge bg-primary"><i class="bi bi-arrow-repeat me-1"></i>Running</span>
                                                    @break
                                                @case('PROCESSING')
                                                    <span class="badge bg-warning text-dark">Processing</span>
                                                    @break
                                                @case('CANCELED')
                                                    <span class="badge bg-secondary">Canceled</span>
                                                    @break
                                                @default
                                                    <span class="badge bg-secondary">{{ $job['status'] ?? 'Unknown' }}</span>
                                            @endswitch
                                        </td>
                                        <td>
                                            @if(($job['planType'] ?? '') === 'IMAGE')
                                                <i class="bi bi-hdd me-1" title="Image"></i>Image
                                            @elseif(($job['planType'] ?? '') === 'FILE_FOLDER')
                                                <i class="bi bi-folder me-1" title="File/Folder"></i>File/Folder
                                            @else
                                                {{ $job['planType'] ?? '-' }}
                                            @endif
                                        </td>
                                        <td>
                                            @if(isset($job['startTime']))
                                                {{ \Carbon\Carbon::parse($job['startTime'])->toAppTz()->format('M j, g:i A') }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                        <td>
                                            @if(isset($job['startTime'], $job['endTime']))
                                                @php
                                                    $start = \Carbon\Carbon::parse($job['startTime']);
                                                    $end = \Carbon\Carbon::parse($job['endTime']);
                                                    $diffMinutes = $start->diffInMinutes($end);
                                                @endphp
                                                @if($diffMinutes < 1)
                                                    < 1 min
                                                @elseif($diffMinutes < 60)
                                                    {{ $diffMinutes }} min
                                                @else
                                                    {{ floor($diffMinutes / 60) }}h {{ $diffMinutes % 60 }}m
                                                @endif
                                            @elseif(($job['status'] ?? '') === 'RUNNING')
                                                <span class="text-muted">In progress</span>
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif

                @if(!empty($backupJobs['integrityChecks']))
                <div class="card shadow-sm mb-3">
                    <div class="card-header"><i class="bi bi-shield-check me-2"></i>Integrity Checks</div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Plan Type</th>
                                    <th>Started</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($backupJobs['integrityChecks'] as $check)
                                    <tr>
                                        <td>
                                            @switch($check['status'] ?? '')
                                                @case('COMPLETED')
                                                    <span class="badge bg-success">Completed</span>
                                                    @break
                                                @case('FAILED')
                                                    <span class="badge bg-danger">Failed</span>
                                                    @break
                                                @case('RUNNING')
                                                    <span class="badge bg-primary"><i class="bi bi-arrow-repeat me-1"></i>Running</span>
                                                    @break
                                                @case('PROCESSING')
                                                    <span class="badge bg-warning text-dark">Processing</span>
                                                    @break
                                                @case('CANCELED')
                                                    <span class="badge bg-secondary">Canceled</span>
                                                    @break
                                                @default
                                                    <span class="badge bg-secondary">{{ $check['status'] ?? 'Unknown' }}</span>
                                            @endswitch
                                        </td>
                                        <td>
                                            @if(($check['planType'] ?? '') === 'IMAGE')
                                                <i class="bi bi-hdd me-1" title="Image"></i>Image
                                            @elseif(($check['planType'] ?? '') === 'FILE_FOLDER')
                                                <i class="bi bi-folder me-1" title="File/Folder"></i>File/Folder
                                            @else
                                                {{ $check['planType'] ?? '-' }}
                                            @endif
                                        </td>
                                        <td>
                                            @if(isset($check['startTime']))
                                                {{ \Carbon\Carbon::parse($check['startTime'])->toAppTz()->format('M j, g:i A') }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
                @endif
            @elseif($asset->backup_synced_at)
                <p class="text-muted small mb-0"><i class="bi bi-exclamation-circle me-1"></i>Backup job history unavailable.</p>
            @endif
        @else
            <p class="text-muted">Backup data is only available for NinjaRMM-linked devices.</p>
        @endif
    </div>

</div>{{-- end tab-content --}}

{{-- Delete Modal --}}
@php $assetDeleteName = $asset->hostname ?: $asset->name; @endphp
<div class="modal fade" id="deleteAssetModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Offboard Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>This will soft-delete <strong>{{ $assetDeleteName }}</strong>.</p>
                <p class="text-muted small">The record can be restored later if needed.</p>
                <label for="deleteAssetConfirm" class="form-label mt-2">
                    To confirm, type <code>{{ $assetDeleteName }}</code> below.
                </label>
                <input type="text" class="form-control" id="deleteAssetConfirm" autocomplete="off">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="{{ route('assets.destroy', $asset) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger" id="deleteAssetBtn" disabled>
                        <i class="bi bi-trash me-1"></i>Offboard Device
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
(function() {
    var expected = @json($assetDeleteName);
    var input = document.getElementById('deleteAssetConfirm');
    var btn = document.getElementById('deleteAssetBtn');
    input?.addEventListener('input', function() {
        btn.disabled = input.value !== expected;
    });
    document.getElementById('deleteAssetModal')?.addEventListener('hidden.bs.modal', function() {
        input.value = '';
        btn.disabled = true;
    });
})();
</script>

{{-- Control D DNS Activity JS --}}
@if($asset->controld_device_id && \App\Support\ControlDConfig::isAnalyticsConfigured())
<script>
function loadDnsActivity(hours) {
    var container = document.getElementById('dnsActivityContainer');
    var body = document.getElementById('dnsActivityBody');
    container.style.display = '';

    // Update active button
    document.querySelectorAll('#dnsTimeRange button').forEach(function(btn) {
        btn.classList.toggle('active', parseInt(btn.dataset.hours) === hours);
    });

    body.innerHTML = '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-muted" role="status"></div><span class="text-muted ms-2">Loading DNS activity...</span></div>';

    fetch('{{ route("assets.controld.activity", $asset) }}?hours=' + hours, {
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function(r) {
        if (!r.ok) return r.json().then(function(d) { throw new Error(d.error || 'Request failed'); });
        return r.json();
    })
    .then(function(queries) {
        if (!queries.length) {
            body.innerHTML = '<div class="text-center py-4 text-muted">No DNS queries found in the last ' + hours + ' hour' + (hours > 1 ? 's' : '') + '.</div>';
            return;
        }
        var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">';
        html += '<thead class="table-light"><tr><th>Time</th><th>Domain</th><th>Action</th><th>Trigger</th><th>Type</th></tr></thead><tbody>';
        queries.forEach(function(q) {
            var badge = 'secondary';
            var label = q.action || 'unknown';
            if (q.action === 'allowed') badge = 'success';
            else if (q.action === 'blocked') badge = 'danger';
            else if (q.action === 'nxdomain') badge = 'warning';

            var time = q.timestamp ? new Date(q.timestamp * 1000).toLocaleTimeString() : '-';
            html += '<tr>';
            html += '<td class="text-nowrap small">' + time + '</td>';
            html += '<td class="small text-break" style="max-width: 400px;">' + (q.domain || '-') + '</td>';
            html += '<td><span class="badge bg-' + badge + '">' + label + '</span></td>';
            html += '<td class="small text-muted">' + (q.trigger || '-') + '</td>';
            html += '<td class="small text-muted">' + (q.type || '-') + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table></div>';
        if (queries.length >= 100) {
            html += '<div class="text-center py-2 text-muted small">Showing first 100 queries</div>';
        }
        body.innerHTML = html;
    })
    .catch(function(err) {
        body.innerHTML = '<div class="text-center py-4 text-danger"><i class="bi bi-exclamation-triangle me-1"></i>' + err.message + '</div>';
    });
}

document.querySelectorAll('#dnsTimeRange button').forEach(function(btn) {
    btn.addEventListener('click', function() { loadDnsActivity(parseInt(this.dataset.hours)); });
});
</script>
@endif

{{-- AJAX Tab Render Functions + Fetch Handler --}}
<script>
// Escape helper
function esc(str) {
    if (!str) return '';
    var div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

function formatBytes(bytes) {
    if (!bytes) return '0 B';
    var k = 1024, sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function renderNetwork(data) {
    if (data.level_fallback) {
        return '<div class="p-4"><p class="text-muted mb-1">Limited network data for Level devices.</p>'
            + '<table class="table table-sm"><tr><th style="width:140px" class="text-muted">IP Address</th><td>'
            + esc(data.ip_address || '-') + '</td></tr></table></div>';
    }
    if (!data.interfaces || !data.interfaces.length) {
        return '<p class="text-muted p-4">No network interface data available.</p>';
    }
    var html = '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
        + '<thead><tr><th>Interface</th><th>Status</th><th>MAC Address</th><th>IPv4</th><th>Subnet</th><th>Gateway</th><th>DNS</th><th>Speed</th></tr></thead><tbody>';
    data.interfaces.forEach(function(iface) {
        var statusBadge = iface.status === 'Up' || iface.status === 'Connected'
            ? '<span class="badge bg-success">Connected</span>'
            : '<span class="badge bg-secondary">' + esc(iface.status || 'Unknown') + '</span>';
        // Ninja: ipAddress (array), ipv4Address (some APIs)
        var ipArr = Array.isArray(iface.ipAddress) ? iface.ipAddress : (Array.isArray(iface.ipAddresses) ? iface.ipAddresses : []);
        var ipv4 = iface.ipv4Address || ipArr.filter(function(ip) { return ip && ip.indexOf(':') === -1; }).join(', ') || '-';
        // Ninja: dnsServers can be a string or array
        var dns = Array.isArray(iface.dnsServers) ? iface.dnsServers.join(', ') : (iface.dnsServers || '-');
        // Ninja: macAddress can be array
        var mac = Array.isArray(iface.macAddress) ? iface.macAddress.join(', ') : (iface.macAddress || '-');
        // Ninja: linkSpeed in bps (string), speed in Mbps
        var speedVal = iface.speed || (iface.linkSpeed ? Math.round(parseInt(iface.linkSpeed) / 1000000) : 0);
        var speed = speedVal ? (speedVal >= 1000 ? (speedVal/1000) + ' Gbps' : speedVal + ' Mbps') : '-';
        html += '<tr><td>' + esc(iface.interfaceName || iface.name || iface.description || '-') + '</td>'
            + '<td>' + statusBadge + '</td>'
            + '<td><code>' + esc(mac) + '</code></td>'
            + '<td>' + esc(ipv4) + '</td>'
            + '<td>' + esc(iface.subnetMask || '-') + '</td>'
            + '<td>' + esc(iface.gateway || iface.defaultGateway || '-') + '</td>'
            + '<td class="small">' + esc(dns) + '</td>'
            + '<td>' + speed + '</td></tr>';
    });
    html += '</tbody></table></div>';
    return html;
}

function renderStorage(data) {
    if (data.level_fallback) {
        return '<div class="p-4"><p class="text-muted mb-1">Limited storage data for Level devices.</p>'
            + '<pre class="mb-0">' + esc(data.disk_summary || 'No disk data') + '</pre></div>';
    }
    var html = '';

    // Physical Disks
    if (data.disks && data.disks.length) {
        html += '<h6 class="px-3 pt-3 mb-2"><i class="bi bi-device-hdd me-1"></i>Physical Disks</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
            + '<thead><tr><th>Model</th><th>Capacity</th><th>Type</th><th>Interface</th><th>SMART</th><th>Temp</th></tr></thead><tbody>';
        data.disks.forEach(function(d) {
            var cap = d.size ? formatBytes(d.size) : '-';
            var smartBadge = d.smartStatus === 'OK' || d.smartStatus === 'Healthy'
                ? '<span class="badge bg-success">Healthy</span>'
                : '<span class="badge bg-danger">' + esc(d.smartStatus || 'Unknown') + '</span>';
            var temp = d.temperature ? d.temperature + '\u00B0C' : '-';
            html += '<tr><td>' + esc(d.model || '-') + '</td><td>' + cap + '</td>'
                + '<td>' + esc(d.mediaType || '-') + '</td>'
                + '<td>' + esc(d.interfaceType || '-') + '</td>'
                + '<td>' + smartBadge + '</td><td>' + temp + '</td></tr>';
        });
        html += '</tbody></table></div>';
    }

    // Logical Volumes
    if (data.volumes && data.volumes.length) {
        html += '<h6 class="px-3 pt-3 mb-2"><i class="bi bi-hdd me-1"></i>Volumes</h6>';
        html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0">'
            + '<thead><tr><th>Drive</th><th>File System</th><th>Capacity</th><th>Free</th><th>Usage</th></tr></thead><tbody>';
        data.volumes.forEach(function(v) {
            var cap = v.capacity ? formatBytes(v.capacity) : '-';
            var free = v.freeSpace != null ? formatBytes(v.freeSpace) : '-';
            var pct = (v.capacity && v.freeSpace != null) ? Math.round(((v.capacity - v.freeSpace) / v.capacity) * 100) : null;
            var bar = pct !== null
                ? '<div class="progress" style="height:18px;min-width:100px"><div class="progress-bar '
                    + (pct > 90 ? 'bg-danger' : pct > 75 ? 'bg-warning' : 'bg-success')
                    + '" style="width:' + pct + '%">' + pct + '%</div></div>'
                : '-';
            html += '<tr><td><strong>' + esc(v.name || '-') + '</strong></td><td>' + esc(v.fileSystem || '-') + '</td>'
                + '<td>' + cap + '</td><td>' + free + '</td><td>' + bar + '</td></tr>';
        });
        html += '</tbody></table></div>';
    }

    return html || '<p class="text-muted p-4">No storage data available.</p>';
}

function renderSoftware(data) {
    if (data.level_fallback || data.message) {
        return '<p class="text-muted p-4">' + esc(data.message || 'Software inventory not available.') + '</p>';
    }
    if (!data.software || !data.software.length) {
        return '<p class="text-muted p-4">No software inventory available.</p>';
    }

    // Sort by name
    data.software.sort(function(a, b) { return (a.name || '').localeCompare(b.name || ''); });

    var html = '<div class="p-3 pb-0">'
        + '<input type="text" class="form-control form-control-sm mb-3" id="softwareSearch" placeholder="Search software..." style="max-width:300px">'
        + '</div>';
    html += '<div class="table-responsive"><table class="table table-sm table-hover mb-0" id="softwareTable">'
        + '<thead><tr><th>Name</th><th>Version</th><th>Vendor</th><th>Installed</th></tr></thead><tbody>';
    data.software.forEach(function(s) {
        var installed = s.installDate ? new Date(s.installDate * 1000).toLocaleDateString() : '-';
        html += '<tr><td>' + esc(s.name || '-') + '</td>'
            + '<td><code>' + esc(s.version || '-') + '</code></td>'
            + '<td class="text-muted">' + esc(s.vendor || '-') + '</td>'
            + '<td class="text-muted">' + installed + '</td></tr>';
    });
    html += '</tbody></table></div>';
    html += '<div class="p-3 text-muted small">' + data.software.length + ' application(s)</div>';

    // After rendering, wire up search filter
    setTimeout(function() {
        var input = document.getElementById('softwareSearch');
        if (input) {
            input.addEventListener('input', function() {
                var filter = this.value.toLowerCase();
                var rows = document.querySelectorAll('#softwareTable tbody tr');
                rows.forEach(function(row) {
                    row.style.display = row.textContent.toLowerCase().indexOf(filter) > -1 ? '' : 'none';
                });
            });
        }
    }, 0);

    return html;
}

function renderPatches(data) {
    if (data.level_fallback || data.message) {
        return '<p class="text-muted p-4">' + esc(data.message || 'Patch data not available.') + '</p>';
    }
    if (!data.patches || !data.patches.length) {
        return '<p class="text-muted p-4">No patch data available.</p>';
    }

    // Separate pending vs installed
    var pending = data.patches.filter(function(p) { return p.status !== 'INSTALLED' && p.installDate == null; });
    var installed = data.patches.filter(function(p) { return p.status === 'INSTALLED' || p.installDate != null; });

    var html = '<div class="p-3 pb-0">'
        + '<span class="badge bg-warning text-dark me-2">' + pending.length + ' pending</span>'
        + '<span class="badge bg-success">' + installed.length + ' installed</span>'
        + '<button class="btn btn-sm btn-outline-secondary ms-3" id="toggleInstalled" type="button">Show installed</button>'
        + '</div>';

    function patchTable(patches, tableId) {
        var t = '<div class="table-responsive"><table class="table table-sm table-hover mb-0" id="' + tableId + '">'
            + '<thead><tr><th>Title</th><th>KB</th><th>Severity</th><th>Security</th><th>Status</th><th>Released</th></tr></thead><tbody>';
        patches.forEach(function(p) {
            var sevClass = {'CRITICAL':'bg-danger','IMPORTANT':'bg-warning text-dark','MODERATE':'bg-info text-dark','LOW':'bg-secondary'};
            var sev = (p.severity || 'UNKNOWN').toUpperCase();
            var sevBadge = '<span class="badge ' + (sevClass[sev] || 'bg-secondary') + '">' + esc(sev) + '</span>';
            var secBadge = p.isSecurityUpdate ? '<span class="badge bg-danger">Yes</span>' : '<span class="text-muted">No</span>';
            var status = p.installDate ? '<span class="badge bg-success">Installed</span>' : '<span class="badge bg-warning text-dark">Pending</span>';
            var released = p.releaseDate ? new Date(p.releaseDate * 1000).toLocaleDateString() : '-';
            t += '<tr><td>' + esc(p.title || '-') + '</td>'
                + '<td><code>' + esc(p.id || '-') + '</code></td>'
                + '<td>' + sevBadge + '</td>'
                + '<td>' + secBadge + '</td>'
                + '<td>' + status + '</td>'
                + '<td class="text-muted">' + released + '</td></tr>';
        });
        t += '</tbody></table></div>';
        return t;
    }

    html += '<h6 class="px-3 pt-3 mb-2">Pending Updates</h6>';
    html += pending.length ? patchTable(pending, 'pendingPatches') : '<p class="text-muted px-3">No pending updates.</p>';
    html += '<div id="installedSection" style="display:none">';
    html += '<h6 class="px-3 pt-3 mb-2">Installed Updates</h6>';
    html += installed.length ? patchTable(installed, 'installedPatches') : '<p class="text-muted px-3">No installed updates recorded.</p>';
    html += '</div>';

    setTimeout(function() {
        var btn = document.getElementById('toggleInstalled');
        if (btn) {
            btn.addEventListener('click', function() {
                var sec = document.getElementById('installedSection');
                var showing = sec.style.display !== 'none';
                sec.style.display = showing ? 'none' : '';
                btn.textContent = showing ? 'Show installed' : 'Hide installed';
            });
        }
    }, 0);

    return html;
}

// AJAX fetch handler for tabs
(function() {
    var cache = {};
    var assetId = {{ $asset->id }};
    var hasNinja = {{ $asset->ninja_id ? 'true' : 'false' }};
    var hasLevel = {{ $asset->level_id ? 'true' : 'false' }};
    var hasTactical = {{ $asset->tacticalAsset ? 'true' : 'false' }};
    // psa-ymw8: a Tactical-only asset routes the SAME page-top tabs to its Tactical
    // data (source=tactical) + the Tactical renderers. A Ninja/Level asset (incl.
    // dual-linked) is unchanged — it keeps the Ninja/Level endpoint + renderers.
    var useTactical = !hasNinja && !hasLevel && hasTactical;

    document.querySelectorAll('[data-ajax-section]').forEach(function(tab) {
        tab.addEventListener('shown.bs.tab', function() {
            var section = this.dataset.ajaxSection;
            var pane = document.querySelector(this.getAttribute('href'));
            if (!pane || cache[section]) return;

            if (!hasNinja && !hasLevel && !hasTactical) {
                pane.innerHTML = '<p class="text-muted p-4">Asset not linked to an RMM — no live data available.</p>';
                cache[section] = true;
                return;
            }

            // Show spinner (already in the pane from server render)
            fetch('/assets/' + assetId + '/device-data/' + section + (useTactical ? '?source=tactical' : ''), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.ok ? r.json() : Promise.reject(r); })
            .then(function(data) {
                if (data.error) {
                    pane.innerHTML = '<div class="alert alert-warning m-3"><i class="bi bi-exclamation-triangle me-2"></i>' + esc(data.error) + '</div>';
                } else {
                    pane.innerHTML = useTactical
                        ? window.renderTacticalSection(section, data)
                        : window['render' + section.charAt(0).toUpperCase() + section.slice(1)](data);
                }
                cache[section] = true;
            })
            .catch(function() {
                pane.innerHTML = '<div class="alert alert-danger m-3"><i class="bi bi-x-circle me-2"></i>Could not load data. Try refreshing the page.</div>';
                // Don't cache errors — allow retry
            });
        });
    });
})();
</script>

{{-- Tactical Script Runner JS --}}
{{-- M4: load WHENEVER Tactical-linked (not gated on the stale snapshot). The IIFE
     early-returns if the elements aren't present, so this is safe either way. --}}
@if($asset->tacticalAsset)
<script>
(function() {
    var select = document.getElementById('tacticalScriptSelect');
    var runBtn = document.getElementById('tacticalRunBtn');
    var descEl = document.getElementById('tacticalScriptDesc');
    var timeoutSelect = document.getElementById('tacticalScriptTimeout');
    if (!select || !runBtn) return;

    select.addEventListener('change', function() {
        runBtn.disabled = !this.value;
        var opt = this.options[this.selectedIndex];
        if (opt && opt.dataset.description) {
            descEl.textContent = opt.dataset.description;
            descEl.style.display = '';
        } else {
            descEl.style.display = 'none';
        }
        if (opt && opt.dataset.timeout) {
            var t = parseInt(opt.dataset.timeout);
            var options = timeoutSelect.options;
            for (var i = 0; i < options.length; i++) {
                if (parseInt(options[i].value) >= t) {
                    timeoutSelect.selectedIndex = i;
                    break;
                }
            }
        }
    });

    window.runTacticalScript = function() {
        var scriptId = select.value;
        if (!scriptId) return;

        runBtn.disabled = true;
        runBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Running...';

        var resultDiv = document.getElementById('tacticalScriptResult');
        var outputDiv = document.getElementById('tacticalScriptOutput');
        var metaDiv = document.getElementById('tacticalScriptMeta');
        resultDiv.style.display = 'none';

        fetch('{{ route("assets.run-tactical-script", $asset) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                script_id: scriptId,
                args: document.getElementById('tacticalScriptArgs').value,
                timeout: document.getElementById('tacticalScriptTimeout').value,
            }),
        })
        .then(function(r) { return r.json().then(function(d) { d._status = r.status; return d; }); })
        .then(function(data) {
            resultDiv.style.display = '';
            if (data.error) {
                outputDiv.className = 'border rounded p-2 bg-danger bg-opacity-10 text-danger small font-monospace';
                outputDiv.style.cssText = 'max-height: 300px; overflow-y: auto; white-space: pre-wrap;';
                outputDiv.textContent = data.error;
                metaDiv.textContent = '';
            } else {
                var output = data.stdout || '(no output)';
                if (data.stderr) output += '\n\nSTDERR:\n' + data.stderr;
                var isError = data.retcode !== 0 && data.retcode !== null;
                outputDiv.className = 'border rounded p-2 small font-monospace ' + (isError ? 'bg-danger bg-opacity-10 text-danger' : 'bg-dark text-light');
                outputDiv.style.cssText = 'max-height: 300px; overflow-y: auto; white-space: pre-wrap;';
                outputDiv.textContent = output;
                var meta = 'Return code: ' + (data.retcode ?? 'unknown');
                if (data.execution_time) meta += ' | Time: ' + data.execution_time + 's';
                meta += ' | Script: ' + data.script_name;
                metaDiv.textContent = meta;
            }
        })
        .catch(function(err) {
            resultDiv.style.display = '';
            outputDiv.className = 'border rounded p-2 bg-danger bg-opacity-10 text-danger small font-monospace';
            outputDiv.style.cssText = 'max-height: 300px; overflow-y: auto; white-space: pre-wrap;';
            outputDiv.textContent = 'Request failed: ' + err.message;
            metaDiv.textContent = '';
        })
        .finally(function() {
            runBtn.disabled = false;
            runBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run';
        });
    };
})();

// Reboot confirm modal (destructive): typed-hostname gate + token round-trip.
(function() {
    var confirmBtn = document.getElementById('tacticalRebootConfirm');
    var input = document.getElementById('tacticalRebootHostname');
    var errEl = document.getElementById('tacticalRebootError');
    var resultEl = document.getElementById('tacticalRebootResult');
    if (!confirmBtn || !input) return;

    var expected = (confirmBtn.dataset.expectedHostname || '').trim().toLowerCase();

    function matches() {
        return input.value.trim().toLowerCase() === expected && expected !== '';
    }
    input.addEventListener('input', function() {
        confirmBtn.disabled = !matches();
        errEl.style.display = 'none';
    });

    confirmBtn.addEventListener('click', function() {
        if (!matches()) return;
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Rebooting...';

        fetch('{{ route("assets.reboot-tactical", $asset) }}', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ hostname: input.value }),
        })
        .then(function(r) { return r.json().then(function(d) { d._status = r.status; return d; }); })
        .then(function(data) {
            if (data.error) {
                // A bound confirmation can expire (~10 min TTL, m3) — surface a
                // clear re-confirm hint rather than a generic failure.
                var msg = data.error;
                if (/confirm|expired/i.test(msg)) {
                    msg = 'Confirmation expired — please re-confirm.';
                }
                errEl.textContent = msg;
                errEl.style.display = '';
                return;
            }
            // Success: close the modal and show the result on the card.
            var modalEl = document.getElementById('tacticalRebootModal');
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            if (resultEl) {
                resultEl.className = 'mt-2 small text-success';
                resultEl.textContent = data.message || 'Reboot command sent.';
                resultEl.style.display = '';
            }
            input.value = '';
        })
        .catch(function(err) {
            errEl.textContent = 'Request failed: ' + err.message;
            errEl.style.display = '';
        })
        .finally(function() {
            confirmBtn.disabled = !matches();
            confirmBtn.innerHTML = '<i class="bi bi-arrow-clockwise me-1"></i>Reboot now';
        });
    });
})();

// Run-command confirm modal (DESTRUCTIVE — arbitrary RCE). The command shown
// in the <pre> is the EXACT string sent; editing the shell/command after the
// modal opens re-renders the preview AND resets the confirm with a clear
// "command changed — re-confirm" message (amendment E5). The hostname gate +
// payloadHash-bound token are enforced server-side (A1).
(function() {
    var shellSel = document.getElementById('tacticalCmdShell');
    var cmdInput = document.getElementById('tacticalCmdInput');
    var openBtn = document.getElementById('tacticalCmdBtn');
    var modalEl = document.getElementById('tacticalCmdModal');
    var preview = document.getElementById('tacticalCmdPreview');
    var hostInput = document.getElementById('tacticalCmdHostname');
    var confirmBtn = document.getElementById('tacticalCmdConfirm');
    var errEl = document.getElementById('tacticalCmdError');
    var ticketSel = document.getElementById('tacticalCmdTicket');
    var resultDiv = document.getElementById('tacticalCmdResult');
    var outputDiv = document.getElementById('tacticalCmdOutput');
    if (!cmdInput || !openBtn || !modalEl) return;

    var expected = (confirmBtn.dataset.expectedHostname || '').trim().toLowerCase();
    // The command snapshot the preview/confirm is bound to (set on modal open).
    var confirmed = { shell: null, cmd: null };

    function enableOpen() {
        openBtn.disabled = cmdInput.value.trim() === '';
    }
    cmdInput.addEventListener('input', enableOpen);
    enableOpen();

    function resolvedText() {
        return '[' + shellSel.value + '] ' + cmdInput.value;
    }

    function hostMatches() {
        return hostInput.value.trim().toLowerCase() === expected && expected !== '';
    }

    function refreshConfirmState() {
        confirmBtn.disabled = !hostMatches();
    }

    // Snapshot the command when the modal opens and render the exact preview.
    modalEl.addEventListener('show.bs.modal', function() {
        confirmed.shell = shellSel.value;
        confirmed.cmd = cmdInput.value;
        preview.textContent = resolvedText();
        errEl.style.display = 'none';
        hostInput.value = '';
        confirmBtn.disabled = true;
    });

    // E5: if the underlying shell/command changes while the modal is open, the
    // displayed command no longer matches what was confirmed — re-render and
    // force a re-confirm.
    function onCommandEdited() {
        if (!modalEl.classList.contains('show')) return;
        if (shellSel.value === confirmed.shell && cmdInput.value === confirmed.cmd) return;
        confirmed.shell = shellSel.value;
        confirmed.cmd = cmdInput.value;
        preview.textContent = resolvedText();
        hostInput.value = '';
        confirmBtn.disabled = true;
        errEl.textContent = 'Command changed — re-confirm by typing the hostname again.';
        errEl.style.display = '';
    }
    shellSel.addEventListener('change', onCommandEdited);
    cmdInput.addEventListener('input', onCommandEdited);

    hostInput.addEventListener('input', function() {
        refreshConfirmState();
        if (hostMatches()) errEl.style.display = 'none';
    });

    confirmBtn.addEventListener('click', function() {
        if (!hostMatches()) return;
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Running...';

        var body = {
            hostname: hostInput.value,
            shell: confirmed.shell,
            cmd: confirmed.cmd,
            timeout: 60,
        };
        if (ticketSel && ticketSel.value) body.ticket_id = ticketSel.value;

        fetch(confirmBtn.dataset.cmdUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
        })
        .then(function(r) { return r.json().then(function(d) { d._status = r.status; return d; }); })
        .then(function(data) {
            if (data.error) {
                var msg = data.error;
                if (/confirm|expired/i.test(msg)) msg = 'Confirmation expired — please re-confirm.';
                errEl.textContent = msg;
                errEl.style.display = '';
                return;
            }
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            if (resultDiv && outputDiv) {
                outputDiv.textContent = data.message || '(command sent)';
                resultDiv.style.display = '';
            }
            hostInput.value = '';
        })
        .catch(function(err) {
            errEl.textContent = 'Request failed: ' + err.message;
            errEl.style.display = '';
        })
        .finally(function() {
            confirmBtn.disabled = !hostMatches();
            confirmBtn.innerHTML = '<i class="bi bi-play-fill me-1"></i>Run command';
        });
    });
})();

// Shutdown confirm modal (DESTRUCTIVE — the box stays OFF). Mirrors reboot:
// typed-full-hostname gate + a confirm token minted server-side. Optional
// ticket link (E2).
(function() {
    var confirmBtn = document.getElementById('tacticalShutdownConfirm');
    var input = document.getElementById('tacticalShutdownHostname');
    var errEl = document.getElementById('tacticalShutdownError');
    var resultEl = document.getElementById('tacticalShutdownResult');
    var ticketSel = document.getElementById('tacticalShutdownTicket');
    if (!confirmBtn || !input) return;

    var expected = (confirmBtn.dataset.expectedHostname || '').trim().toLowerCase();

    function matches() {
        return input.value.trim().toLowerCase() === expected && expected !== '';
    }
    input.addEventListener('input', function() {
        confirmBtn.disabled = !matches();
        errEl.style.display = 'none';
    });

    confirmBtn.addEventListener('click', function() {
        if (!matches()) return;
        confirmBtn.disabled = true;
        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Shutting down...';

        var body = { hostname: input.value };
        if (ticketSel && ticketSel.value) body.ticket_id = ticketSel.value;

        fetch(confirmBtn.dataset.shutdownUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify(body),
        })
        .then(function(r) { return r.json().then(function(d) { d._status = r.status; return d; }); })
        .then(function(data) {
            if (data.error) {
                var msg = data.error;
                if (/confirm|expired/i.test(msg)) msg = 'Confirmation expired — please re-confirm.';
                errEl.textContent = msg;
                errEl.style.display = '';
                return;
            }
            var modalEl = document.getElementById('tacticalShutdownModal');
            var modal = bootstrap.Modal.getInstance(modalEl);
            if (modal) modal.hide();
            if (resultEl) {
                resultEl.className = 'mt-2 small text-success';
                resultEl.textContent = data.message || 'Shutdown command sent.';
                resultEl.style.display = '';
            }
            input.value = '';
        })
        .catch(function(err) {
            errEl.textContent = 'Request failed: ' + err.message;
            errEl.style.display = '';
        })
        .finally(function() {
            confirmBtn.disabled = !matches();
            confirmBtn.innerHTML = '<i class="bi bi-power me-1"></i>Shut down now';
        });
    });
})();

// Recover agent services (non-destructive, single click — no confirm token).
(function() {
    var btn = document.getElementById('tacticalRecoverBtn');
    var resultEl = document.getElementById('tacticalRecoverResult');
    if (!btn) return;

    btn.addEventListener('click', function() {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Recovering...';
        if (resultEl) resultEl.style.display = 'none';

        fetch(btn.dataset.assetRecoverUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ mode: 'mesh' }),
        })
        .then(function(r) { return r.json().then(function(d) { d._status = r.status; return d; }); })
        .then(function(data) {
            if (resultEl) {
                resultEl.style.display = '';
                if (data.error) {
                    resultEl.className = 'mt-2 small text-danger';
                    resultEl.textContent = data.error;
                } else {
                    resultEl.className = 'mt-2 small text-success';
                    resultEl.textContent = data.message || 'Recovery initiated.';
                }
            }
        })
        .catch(function(err) {
            if (resultEl) {
                resultEl.style.display = '';
                resultEl.className = 'mt-2 small text-danger';
                resultEl.textContent = 'Request failed: ' + err.message;
            }
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Recover agent';
        });
    });
})();

// Maintenance-mode toggle (non-destructive, single click — no confirm token).
// On a server error the switch reverts to its prior position so it never lies
// about the device's actual alerting state.
(function() {
    var toggle = document.getElementById('tacticalMaintenanceToggle');
    var badge = document.getElementById('tacticalMaintenanceBadge');
    var hint = document.getElementById('tacticalMaintenanceHint');
    var resultEl = document.getElementById('tacticalMaintenanceResult');
    if (!toggle) return;

    toggle.addEventListener('change', function() {
        var enabled = toggle.checked;
        toggle.disabled = true;
        if (resultEl) resultEl.style.display = 'none';

        fetch(toggle.dataset.assetMaintenanceUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
            body: JSON.stringify({ enabled: enabled }),
        })
        .then(function(r) { return r.json().then(function(d) { d._status = r.status; return d; }); })
        .then(function(data) {
            if (data.error) {
                // Revert the switch — the change did NOT take effect.
                toggle.checked = !enabled;
                if (resultEl) {
                    resultEl.style.display = '';
                    resultEl.className = 'small mb-2 text-danger';
                    resultEl.textContent = data.error;
                }
                return;
            }
            // Reflect the new, confirmed state.
            if (badge) badge.style.display = enabled ? '' : 'none';
            if (hint) {
                hint.innerHTML = enabled
                    ? 'Alerts are <strong>muted</strong> for this device.'
                    : 'Mute monitoring alerts (e.g. while working on this device).';
            }
            if (resultEl) {
                resultEl.style.display = '';
                resultEl.className = 'small mb-2 text-success';
                resultEl.textContent = data.message || (enabled ? 'Maintenance mode enabled.' : 'Maintenance mode disabled.');
            }
        })
        .catch(function(err) {
            toggle.checked = !enabled;
            if (resultEl) {
                resultEl.style.display = '';
                resultEl.className = 'small mb-2 text-danger';
                resultEl.textContent = 'Request failed: ' + err.message;
            }
        })
        .finally(function() {
            toggle.disabled = false;
        });
    });
})();

// In-place refresh-now (P4 amendment J). A READ — POST to a non-bus, non-audited
// endpoint that re-syncs the local snapshot; updates the status badge + freshness
// in place (no full-page reload, no quickLook cache). An in-button spinner
// re-enables on completion/failure (the ~3s bound guarantees no infinite spin).
// A degraded result is a 200 with degraded:true — copy sits adjacent to status.
(function() {
    var btn = document.getElementById('tacticalRefreshBtn');
    if (!btn) return;
    var statusBadge = document.getElementById('tacticalStatusBadge');
    var freshness = document.getElementById('tacticalFreshness');
    var resultEl = document.getElementById('tacticalRefreshResult');
    var original = btn.innerHTML;
    var STATUS_CLASSES = ['bg-success', 'bg-danger', 'bg-warning', 'text-dark', 'bg-secondary'];

    function statusClass(status) {
        switch ((status || '').toLowerCase()) {
            case 'online': return ['bg-success'];
            case 'offline': return ['bg-danger'];
            case 'overdue': return ['bg-warning', 'text-dark'];
            default: return ['bg-secondary'];
        }
    }

    btn.addEventListener('click', function() {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
        if (resultEl) resultEl.style.display = 'none';

        fetch(btn.dataset.assetRefreshUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
        })
        .then(function(r) { return r.json().then(function(d) { d._status = r.status; return d; }); })
        .then(function(data) {
            if (data.error) {
                if (resultEl) {
                    resultEl.style.display = '';
                    resultEl.className = 'small mb-2 text-danger';
                    resultEl.textContent = data.error;
                }
                return;
            }
            // Update the status badge in place (only on a non-degraded read — a
            // degraded read leaves the last-known status untouched).
            if (!data.degraded && data.status_label && statusBadge) {
                STATUS_CLASSES.forEach(function(c) { statusBadge.classList.remove(c); });
                statusClass(data.status).forEach(function(c) { statusBadge.classList.add(c); });
                statusBadge.textContent = data.status_label;
            }
            // Freshness flips to "Refreshed just now" (clears the stale-amber).
            if (freshness) {
                if (data.degraded) {
                    freshness.classList.remove('tactical-freshness-stale', 'text-warning-emphasis', 'fw-semibold');
                    freshness.classList.add('text-muted');
                    freshness.textContent = ' synced ' + (data.freshAsOf || 'a while ago');
                } else {
                    freshness.classList.remove('tactical-freshness-stale', 'text-warning-emphasis', 'fw-semibold');
                    freshness.classList.add('text-muted');
                    freshness.textContent = ' Refreshed just now';
                }
            }
            if (resultEl) {
                resultEl.style.display = '';
                resultEl.className = data.degraded ? 'small mb-2 text-warning-emphasis' : 'small mb-2 text-success';
                resultEl.textContent = data.degraded
                    ? (data.message || 'Couldn’t reach the agent — showing last sync.')
                    : (data.message || 'Refreshed just now.');
            }
        })
        .catch(function(err) {
            if (resultEl) {
                resultEl.style.display = '';
                resultEl.className = 'small mb-2 text-danger';
                resultEl.textContent = 'Request failed: ' + err.message;
            }
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = original;
        });
    });
})();

// Tactical telemetry renderers (P4 amendment I + G), used by the page-top
// Network/Storage/Software/Patches/Checks tabs for Tactical-only assets. Each
// renders one of three states: data, genuinely-empty (positive copy), or — via
// the caller — could-not-load. stdout stays escaped (amendment-G). psa-ymw8.
(function() {
    // psa-ymw8: these Tactical telemetry renderers used to back the under-card
    // accordion; that accordion was removed and they now serve the page-top tabs
    // for Tactical-only assets (exposed below as window.renderTacticalSection).
    var tacticalWebUrl = @json(\App\Support\TacticalConfig::webUrl() ?: '');

    function viewInTacticalLink() {
        if (!tacticalWebUrl) return '';
        return '<a href="' + esc(tacticalWebUrl) + '" target="_blank" rel="noopener noreferrer" ' +
               'class="btn btn-outline-secondary btn-sm mt-2"><i class="bi bi-box-arrow-up-right me-1"></i>View in Tactical</a>';
    }

    function renderChecks(d) {
        if (typeof d.checks_failing !== 'number' || d.checks_failing === 0) {
            return '<div class="text-success"><i class="bi bi-check-circle me-1"></i>All checks passing' +
                   (typeof d.checks_total === 'number' ? ' (' + d.checks_total + ')' : '') + '.</div>';
        }
        var html = '<div class="fw-semibold text-danger mb-2"><i class="bi bi-exclamation-triangle me-1"></i>' +
                   d.checks_failing + ' of ' + d.checks_total + ' check' + (d.checks_failing === 1 ? '' : 's') + ' failing</div>';
        (d.failing_checks || []).forEach(function(c) {
            html += '<div class="border-start border-danger border-2 ps-2 mb-2">' +
                    '<div class="fw-semibold">' + esc(c.name) +
                    (c.retcode !== null && c.retcode !== undefined ? ' <span class="text-muted">(rc=' + esc(String(c.retcode)) + ')</span>' : '') +
                    '</div>';
            if (c.stdout) {
                html += '<pre class="mb-0 mt-1 small text-muted" style="white-space:pre-wrap;word-break:break-word;">' + esc(c.stdout) + '</pre>';
            }
            html += '</div>';
        });
        return html;
    }

    function renderPatches(d) {
        // Shape we couldn't parse (amendment F) — explicit, never "fully patched".
        if (d.shape_error) {
            return '<div class="text-warning-emphasis"><i class="bi bi-question-circle me-1"></i>' + esc(d.shape_error) + '</div>' + viewInTacticalLink();
        }
        if (d.pending_count === 0) {
            return '<div class="text-success"><i class="bi bi-check-circle me-1"></i>No pending updates.</div>';
        }
        var sev = d.severity || {};
        var html = '<div class="fw-semibold mb-1"><i class="bi bi-shield-exclamation me-1 text-warning"></i>' +
                   d.pending_count + ' pending update' + (d.pending_count === 1 ? '' : 's') + '</div>';
        var chips = [];
        if (sev.critical) chips.push('<span class="badge bg-danger me-1">' + sev.critical + ' critical</span>');
        if (sev.important) chips.push('<span class="badge bg-warning text-dark me-1">' + sev.important + ' important</span>');
        if (sev.other) chips.push('<span class="badge bg-secondary me-1">' + sev.other + ' other</span>');
        if (chips.length) html += '<div class="mb-1">' + chips.join('') + '</div>';
        if (d.needs_reboot) html += '<div class="small text-warning-emphasis mb-1"><i class="bi bi-arrow-repeat me-1"></i>A reboot is needed to finish applying updates.</div>';
        // Full list is opt-in.
        if ((d.patches || []).length) {
            html += '<button class="btn btn-link btn-sm p-0 mt-1" type="button" data-bs-toggle="collapse" data-bs-target="#tacticalPatchList">Show all</button>';
            html += '<div class="collapse mt-1" id="tacticalPatchList"><ul class="list-unstyled mb-0">';
            d.patches.forEach(function(p) {
                html += '<li class="border-bottom py-1">' + (p.kb ? '<span class="badge bg-light text-dark me-1">' + esc(p.kb) + '</span>' : '') +
                        esc(p.title) + (p.severity ? ' <span class="text-muted">— ' + esc(p.severity) + '</span>' : '') + '</li>';
            });
            html += '</ul></div>';
        }
        html += viewInTacticalLink();
        return html;
    }

    function renderSoftware(d) {
        var list = d.software || [];
        if (!list.length) {
            return '<div class="text-muted"><i class="bi bi-info-circle me-1"></i>No software inventory reported.</div>';
        }
        var html = '<div class="table-responsive"><table class="table table-sm table-borderless mb-0"><tbody>';
        list.forEach(function(s) {
            html += '<tr><td>' + esc(s.name) + '</td><td class="text-muted text-end">' + esc(s.version || '') + '</td></tr>';
        });
        html += '</tbody></table></div>';
        if (list.length >= 500) html += '<div class="small text-muted">Showing the first 500 entries.</div>';
        return html;
    }

    function renderNetwork(d) {
        var html = '';
        // Public/local IP summary chips (present even when no adapters parsed).
        var chips = [];
        if (d.public_ip) chips.push('<span class="text-muted me-3"><i class="bi bi-globe2 me-1"></i>Public ' + esc(d.public_ip) + '</span>');
        if (d.local_ips) chips.push('<span class="text-muted"><i class="bi bi-house me-1"></i>Local ' + esc(d.local_ips) + '</span>');
        if (chips.length) html += '<div class="mb-2">' + chips.join('') + '</div>';

        var adapters = d.adapters || [];
        if (!adapters.length) {
            // (b) genuinely-empty — a reachable agent with no IP-enabled adapters.
            html += '<div class="text-muted"><i class="bi bi-info-circle me-1"></i>No active network adapters reported.</div>';
            return html;
        }
        adapters.forEach(function(a) {
            html += '<div class="border rounded p-2 mb-2">';
            html += '<div class="fw-semibold mb-1">' + esc(a.caption || 'Adapter') +
                    (a.dhcp_enabled ? ' <span class="badge bg-light text-dark">DHCP</span>' : ' <span class="badge bg-light text-dark">Static</span>') + '</div>';
            html += '<table class="table table-sm table-borderless mb-0"><tbody>';
            html += netRow('IP', (a.ip_addresses || []).join(', '));
            html += netRow('Subnet', (a.subnets || []).join(', '));
            html += netRow('Gateway', (a.gateway || []).join(', '));
            html += netRow('DNS', (a.dns_servers || []).join(', '));
            html += netRow('MAC', a.mac_address || '');
            html += '</tbody></table></div>';
        });
        return html;
    }

    function netRow(label, value) {
        if (!value) return '';
        return '<tr><th class="text-muted fw-normal" style="width:90px;">' + esc(label) +
               '</th><td style="word-break:break-word;">' + esc(value) + '</td></tr>';
    }

    function renderStorage(d) {
        var vols = d.volumes || [];
        if (!vols.length) {
            // (b) genuinely-empty — a reachable agent reporting no disks.
            return '<div class="text-muted"><i class="bi bi-info-circle me-1"></i>No disk volumes reported.</div>';
        }
        var html = '';
        vols.forEach(function(v) {
            var pct = (typeof v.percent_used === 'number') ? v.percent_used : null;
            var barClass = v.low_disk ? 'bg-danger' : (pct !== null && pct >= 75 ? 'bg-warning' : 'bg-success');
            var free = (typeof v.free_gb === 'number') ? v.free_gb + ' GB free' : '';
            var total = (typeof v.total_gb === 'number') ? ' of ' + v.total_gb + ' GB' : '';
            html += '<div class="mb-3">';
            html += '<div class="d-flex justify-content-between align-items-center mb-1">' +
                    '<span class="fw-semibold">' + esc(v.drive || 'Volume') +
                    (v.low_disk ? ' <span class="badge bg-danger ms-1"><i class="bi bi-exclamation-triangle me-1"></i>Low disk</span>' : '') + '</span>' +
                    '<span class="text-muted small">' + esc(free + total) + '</span></div>';
            if (pct !== null) {
                html += '<div class="progress" role="progressbar" aria-valuenow="' + pct + '" aria-valuemin="0" aria-valuemax="100" style="height:8px;">' +
                        '<div class="progress-bar ' + barClass + '" style="width:' + pct + '%;"></div></div>' +
                        '<div class="small text-muted mt-1">' + pct + '% used</div>';
            }
            html += '</div>';
        });
        return html;
    }

    var renderers = { checks: renderChecks, patches: renderPatches, software: renderSoftware, network: renderNetwork, storage: renderStorage };

    // psa-ymw8: expose the Tactical renderers so the page-top Network/Storage/
    // Software/Patches/Checks tabs (the hasTactical branch in the AJAX handler)
    // render Tactical-shaped data with amendment-G stdout redaction preserved.
    // The could-not-load (error) and genuinely-empty states are handled by the
    // caller and the individual renderers respectively. The under-card accordion
    // that previously consumed these was removed.
    window.renderTacticalSection = function(section, data) {
        return renderers[section] ? renderers[section](data) : '';
    };
})();
</script>
@endif
@endsection
