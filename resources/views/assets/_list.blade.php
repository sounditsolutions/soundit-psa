{{-- Reusable asset list partial.
     Expects: $assets, $filters, $clients, $assetTypes
     Optional: $listRoute (string, default 'assets.index'), $prefilter (array, default [])
--}}
@php
    $listRoute = $listRoute ?? 'assets.index';
    $prefilter = $prefilter ?? [];
@endphp

{{-- Quick filter pills --}}
@php
    $isOnline = ($filters['status'] ?? '') === 'online';
    $isOffline = ($filters['status'] ?? '') === 'offline';
    $isUnlinked = ($filters['rmm'] ?? '') === 'unlinked';
    $isUnhealthy = ($filters['health'] ?? '') === 'unhealthy';
@endphp
<div class="mb-3 d-flex flex-wrap align-items-center gap-2">
    <a href="{{ route($listRoute, array_merge(
        $prefilter,
        request()->except('status', 'page'),
        $isOnline ? [] : ['status' => 'online']
    )) }}"
       class="btn btn-sm {{ $isOnline ? 'btn-success' : 'btn-outline-success' }}">
        <i class="bi bi-wifi me-1"></i>Online
    </a>
    <a href="{{ route($listRoute, array_merge(
        $prefilter,
        request()->except('status', 'page'),
        $isOffline ? [] : ['status' => 'offline']
    )) }}"
       class="btn btn-sm {{ $isOffline ? 'btn-danger' : 'btn-outline-danger' }}">
        <i class="bi bi-wifi-off me-1"></i>Offline
    </a>
    <a href="{{ route($listRoute, array_merge(
        $prefilter,
        request()->except('rmm', 'page'),
        $isUnlinked ? [] : ['rmm' => 'unlinked']
    )) }}"
       class="btn btn-sm {{ $isUnlinked ? 'btn-warning text-dark' : 'btn-outline-warning' }}">
        <i class="bi bi-link-45deg me-1"></i>Unlinked
    </a>
    <a href="{{ route($listRoute, array_merge(
        $prefilter,
        request()->except('health', 'page'),
        $isUnhealthy ? [] : ['health' => 'unhealthy']
    )) }}"
       class="btn btn-sm {{ $isUnhealthy ? 'btn-danger' : 'btn-outline-danger' }}"
       title="Devices with a Poor health score">
        <i class="bi bi-heart-pulse me-1"></i>Unhealthy
    </a>

    @php
        $activeFilters = [];
        if ($isOnline) $activeFilters[] = 'Online';
        elseif ($isOffline) $activeFilters[] = 'Offline';
        elseif (($filters['status'] ?? '') === 'unknown') $activeFilters[] = 'Unknown status';
        if ($isUnlinked) $activeFilters[] = 'No RMM';
        elseif (($filters['rmm'] ?? '') === 'linked') $activeFilters[] = 'RMM linked';
        if ($isUnhealthy) $activeFilters[] = 'Unhealthy';
        if (!empty($filters['asset_type'])) $activeFilters[] = $filters['asset_type'];
        if (!empty($filters['client_id']) && !isset($prefilter['client_id'])) $activeFilters[] = $clients->firstWhere('id', $filters['client_id'])?->name ?? 'Client';
        if (!empty($filters['search'])) $activeFilters[] = '"' . $filters['search'] . '"';
        if (!empty($filters['user_assignment'])) $activeFilters[] = $filters['user_assignment'] === 'assigned' ? 'Has users' : 'No users';
        if ($filters['show_inactive'] ?? false) $activeFilters[] = 'Including inactive';
        if ($filters['show_deleted'] ?? false) $activeFilters[] = 'Including deleted';
    @endphp

    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCard">
        <i class="bi bi-funnel me-1"></i>Filters
        @if(count($activeFilters))
            <span class="text-muted ms-1">({{ implode(', ', $activeFilters) }})</span>
        @endif
    </button>
</div>

{{-- Filter card --}}
@php
    $hasAdvancedFilters = (!empty($filters['asset_type'])) || (!empty($filters['client_id']) && !isset($prefilter['client_id']))
        || (!empty($filters['search'])) || (!empty($filters['status']) && !$isOnline && !$isOffline)
        || (!empty($filters['rmm']) && !$isUnlinked)
        || (!empty($filters['user_assignment']))
        || ($filters['show_inactive'] ?? false)
        || ($filters['show_deleted'] ?? false);
@endphp
<div class="collapse {{ $hasAdvancedFilters ? 'show' : '' }} mb-3" id="filterCard">
    <div class="card shadow-sm card-static">
        <div class="card-body">
            <form method="GET" action="{{ route($listRoute, $prefilter) }}">
                @if(!empty($filters['sort']) && $filters['sort'] !== 'hostname')
                    <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                @endif
                @if(!empty($filters['direction']) && $filters['direction'] !== 'asc')
                    <input type="hidden" name="direction" value="{{ $filters['direction'] }}">
                @endif
                @if(!empty($filters['status']))
                    <input type="hidden" name="status" value="{{ $filters['status'] }}">
                @endif
                @if(!empty($filters['rmm']))
                    <input type="hidden" name="rmm" value="{{ $filters['rmm'] }}">
                @endif
                @if(!empty($filters['health']))
                    <input type="hidden" name="health" value="{{ $filters['health'] }}">
                @endif
                <div class="row g-2">
                    <div class="col-lg-2 col-md-3">
                        <select name="asset_type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            @foreach($assetTypes as $type)
                                <option value="{{ $type }}" {{ ($filters['asset_type'] ?? '') === $type ? 'selected' : '' }}>
                                    {{ $type }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    @unless(isset($prefilter['client_id']))
                    <div class="col-lg-2 col-md-3">
                        <select name="client_id" class="form-select form-select-sm">
                            <option value="">All Clients</option>
                            @foreach($clients as $c)
                                <option value="{{ $c->id }}" {{ ($filters['client_id'] ?? '') == $c->id ? 'selected' : '' }}>
                                    {{ $c->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    @endunless
                    <div class="col-lg-2 col-md-3">
                        <select name="user_assignment" class="form-select form-select-sm">
                            <option value="">Users: Any</option>
                            <option value="assigned" {{ ($filters['user_assignment'] ?? '') === 'assigned' ? 'selected' : '' }}>Assigned</option>
                            <option value="unassigned" {{ ($filters['user_assignment'] ?? '') === 'unassigned' ? 'selected' : '' }}>Unassigned</option>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-4">
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Search hostname, name, serial, IP..."
                               value="{{ $filters['search'] ?? '' }}">
                    </div>
                    <div class="col-lg-3 col-md-4 d-flex align-items-center gap-3">
                        <div class="form-check">
                            <input type="checkbox" name="show_inactive" value="1" class="form-check-input"
                                   id="showInactive" {{ ($filters['show_inactive'] ?? false) ? 'checked' : '' }}>
                            <label class="form-check-label small" for="showInactive">Show inactive</label>
                        </div>
                        <div class="form-check">
                            <input type="checkbox" name="show_deleted" value="1" class="form-check-input"
                                   id="showDeleted" {{ ($filters['show_deleted'] ?? false) ? 'checked' : '' }}>
                            <label class="form-check-label small" for="showDeleted">Show deleted</label>
                        </div>
                    </div>
                    <div class="col-auto d-flex gap-1">
                        <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search me-1"></i>Search</button>
                        <a href="{{ route($listRoute, $prefilter) }}" class="btn btn-outline-secondary btn-sm">Clear</a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

@if($assets->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="bi bi-pc-display" style="font-size: 3rem;"></i>
        <p class="mt-3">No assets found.</p>
        <a href="{{ route('assets.create', isset($prefilter['client_id']) ? ['client_id' => $prefilter['client_id']] : []) }}" class="btn btn-primary btn-sm">Add an Asset</a>
    </div>
@else
    @php
        $currentSort = $filters['sort'] ?? 'hostname';
        $currentDir = $filters['direction'] ?? 'asc';

        $defaultDirs = [
            'hostname' => 'asc', 'name' => 'asc', 'type' => 'asc', 'client' => 'asc',
            'os' => 'asc', 'last_seen' => 'desc', 'status' => 'asc', 'health' => 'asc',
        ];
    @endphp

    <div class="card shadow-sm card-static">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th class="{{ $currentSort === 'hostname' ? 'active-sort' : '' }}">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'hostname', 'direction' => $currentSort === 'hostname' ? ($currentDir === 'asc' ? 'desc' : 'asc') : ($defaultDirs['hostname'] ?? 'asc'), 'page' => null]) }}" class="sortable-th">
                                Device <i class="bi {{ $currentSort === 'hostname' ? ($currentDir === 'asc' ? 'bi-chevron-up' : 'bi-chevron-down') : 'bi-chevron-expand' }} sort-icon"></i>
                            </a>
                        </th>
                        <th class="d-none d-md-table-cell {{ $currentSort === 'type' ? 'active-sort' : '' }}">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'type', 'direction' => $currentSort === 'type' ? ($currentDir === 'asc' ? 'desc' : 'asc') : ($defaultDirs['type'] ?? 'asc'), 'page' => null]) }}" class="sortable-th">
                                Type <i class="bi {{ $currentSort === 'type' ? ($currentDir === 'asc' ? 'bi-chevron-up' : 'bi-chevron-down') : 'bi-chevron-expand' }} sort-icon"></i>
                            </a>
                        </th>
                        @unless(isset($prefilter['client_id']))
                        <th class="{{ $currentSort === 'client' ? 'active-sort' : '' }}">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'client', 'direction' => $currentSort === 'client' ? ($currentDir === 'asc' ? 'desc' : 'asc') : ($defaultDirs['client'] ?? 'asc'), 'page' => null]) }}" class="sortable-th">
                                Client <i class="bi {{ $currentSort === 'client' ? ($currentDir === 'asc' ? 'bi-chevron-up' : 'bi-chevron-down') : 'bi-chevron-expand' }} sort-icon"></i>
                            </a>
                        </th>
                        @endunless
                        <th class="d-none d-md-table-cell {{ $currentSort === 'os' ? 'active-sort' : '' }}">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'os', 'direction' => $currentSort === 'os' ? ($currentDir === 'asc' ? 'desc' : 'asc') : ($defaultDirs['os'] ?? 'asc'), 'page' => null]) }}" class="sortable-th">
                                OS <i class="bi {{ $currentSort === 'os' ? ($currentDir === 'asc' ? 'bi-chevron-up' : 'bi-chevron-down') : 'bi-chevron-expand' }} sort-icon"></i>
                            </a>
                        </th>
                        <th class="d-none d-lg-table-cell">IP</th>
                        <th class="d-none d-md-table-cell {{ $currentSort === 'last_seen' ? 'active-sort' : '' }}">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'last_seen', 'direction' => $currentSort === 'last_seen' ? ($currentDir === 'asc' ? 'desc' : 'asc') : ($defaultDirs['last_seen'] ?? 'desc'), 'page' => null]) }}" class="sortable-th">
                                Last Seen <i class="bi {{ $currentSort === 'last_seen' ? ($currentDir === 'asc' ? 'bi-chevron-up' : 'bi-chevron-down') : 'bi-chevron-expand' }} sort-icon"></i>
                            </a>
                        </th>
                        <th class="text-center {{ $currentSort === 'status' ? 'active-sort' : '' }}" style="width: 90px;">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'status', 'direction' => $currentSort === 'status' ? ($currentDir === 'asc' ? 'desc' : 'asc') : ($defaultDirs['status'] ?? 'asc'), 'page' => null]) }}" class="sortable-th">
                                Status <i class="bi {{ $currentSort === 'status' ? ($currentDir === 'asc' ? 'bi-chevron-up' : 'bi-chevron-down') : 'bi-chevron-expand' }} sort-icon"></i>
                            </a>
                        </th>
                        <th class="text-center d-none d-sm-table-cell {{ $currentSort === 'health' ? 'active-sort' : '' }}" style="width: 80px;">
                            <a href="{{ request()->fullUrlWithQuery(['sort' => 'health', 'direction' => $currentSort === 'health' ? ($currentDir === 'asc' ? 'desc' : 'asc') : ($defaultDirs['health'] ?? 'asc'), 'page' => null]) }}" class="sortable-th">
                                Health <i class="bi {{ $currentSort === 'health' ? ($currentDir === 'asc' ? 'bi-chevron-up' : 'bi-chevron-down') : 'bi-chevron-expand' }} sort-icon"></i>
                            </a>
                        </th>
                        <th class="d-none d-lg-table-cell">Primary User</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($assets as $asset)
                        @php $status = $asset->statusBadge; @endphp
                        <tr style="cursor:pointer;" onclick="window.location='{{ route('assets.show', $asset) }}'"
                            class="{{ $status === 'Offline' ? 'table-danger-subtle' : '' }}">
                            <td>
                                <strong>{{ $asset->hostname ?: $asset->name }}</strong>
                                @if($asset->hostname && $asset->hostname !== $asset->name)
                                    <br><small class="text-muted">{{ $asset->name }}</small>
                                @endif
                                @if($asset->trashed())
                                    <span class="badge bg-danger ms-1" style="font-size: 0.6rem;">Deleted</span>
                                @elseif(!$asset->is_active)
                                    <span class="badge bg-secondary ms-1" style="font-size: 0.6rem;">Inactive</span>
                                @endif
                            </td>
                            <td class="d-none d-md-table-cell">{{ $asset->asset_type ?: '-' }}</td>
                            @unless(isset($prefilter['client_id']))
                            <td>
                                <span onclick="event.stopPropagation()">
                                    <x-client-badge :client="$asset->client" fallback="-" />
                                </span>
                            </td>
                            @endunless
                            <td class="d-none d-md-table-cell">{{ $asset->os ?: '-' }}</td>
                            <td class="d-none d-lg-table-cell">{{ $asset->ip_address ?: '-' }}</td>
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
                                @if($status === 'Online')
                                    <span class="badge bg-success" title="Online per RMM">Online</span>
                                @elseif($status === 'Offline')
                                    <span class="badge bg-danger" title="Offline per RMM">Offline</span>
                                @else
                                    <span class="badge bg-secondary" title="No RMM status available">Unknown</span>
                                @endif
                            </td>
                            <td class="text-center d-none d-sm-table-cell">
                                <span onclick="event.stopPropagation()">
                                    <x-asset-health-badge :asset="$asset" />
                                </span>
                            </td>
                            <td class="d-none d-lg-table-cell small">
                                @php $primaryUser = $asset->users->first(); @endphp
                                @if($primaryUser)
                                    <span onclick="event.stopPropagation()">
                                        <x-person-badge :person="$primaryUser" :size="18" />
                                    </span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        {{ $assets->links() }}
    </div>
@endif

@push('styles')
<style>
.sortable-th {
    color: #fff !important;
    text-decoration: none !important;
    white-space: nowrap;
}
.sortable-th:hover { color: var(--accent) !important; }
.sort-icon { font-size: 0.7rem; opacity: 0.4; margin-left: 2px; }
.active-sort .sort-icon { opacity: 1; }
</style>
@endpush

@push('scripts')
<script>
document.querySelectorAll('#filterCard select').forEach(function(sel) {
    sel.addEventListener('change', function() { this.closest('form').submit(); });
});
</script>
@endpush
