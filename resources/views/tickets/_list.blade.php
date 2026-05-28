{{-- Reusable ticket list partial.
     Expects: $tickets, $filters, $clients, $users, $statuses, $priorities, $types, $sources, $unassignedCount
     Optional: $listRoute (string, default 'tickets.index'), $prefilter (array, default [])
               $columns (array, default null = all), $showFilters (bool, default true), $showBulkActions (bool, default true)
--}}
@php
    $listRoute = $listRoute ?? 'tickets.index';
    $prefilter = $prefilter ?? [];
    $showFilters = $showFilters ?? true;
    $showBulkActions = $showBulkActions ?? true;
    $columns = $columns ?? null; // null = show all columns
@endphp

@if($showFilters)
{{-- My Tickets / All / Unassigned toggle + Quick filters --}}
<div class="mb-3 d-flex flex-wrap align-items-center gap-2">
    <div class="btn-group btn-group-sm">
        <a href="{{ route($listRoute, array_merge($prefilter, request()->except('assignee_id', 'page'), ['assignee_id' => auth()->id()])) }}"
           class="btn {{ ($filters['assignee_id'] ?? '') == auth()->id() ? 'btn-primary' : 'btn-outline-primary' }}">
            My Tickets
        </a>
        <a href="{{ route($listRoute, array_merge($prefilter, request()->except('assignee_id', 'page'), ['assignee_id' => 'all'])) }}"
           class="btn {{ ($filters['assignee_id'] ?? '') === 'all' ? 'btn-primary' : 'btn-outline-primary' }}">
            All
            @if($unassignedCount > 0)
                <span class="badge bg-warning text-dark ms-1">{{ $unassignedCount }}</span>
            @endif
        </a>
        <a href="{{ route($listRoute, array_merge($prefilter, request()->except('assignee_id', 'page'), ['assignee_id' => 'unassigned'])) }}"
           class="btn {{ ($filters['assignee_id'] ?? '') === 'unassigned' ? 'btn-primary' : 'btn-outline-primary' }}">
            Unassigned
        </a>
    </div>

    {{-- Quick filter pills --}}
    @php
        $isNeedsAction = ($filters['status'] ?? '') === 'needs_action';
        $isOverdue = !empty($filters['overdue']);
        $isWaiting = ($filters['status'] ?? '') === 'pending_client';
    @endphp
    <a href="{{ route($listRoute, array_merge(
        $prefilter,
        request()->except('status', 'page'),
        $isNeedsAction ? [] : ['status' => 'needs_action']
    )) }}"
       class="btn btn-sm {{ $isNeedsAction ? 'btn-primary' : 'btn-outline-primary' }}">
        <i class="bi bi-bell me-1"></i>Needs Action
    </a>
    <a href="{{ route($listRoute, array_merge(
        $prefilter,
        request()->except('overdue', 'page'),
        $isOverdue ? [] : ['overdue' => '1']
    )) }}"
       class="btn btn-sm {{ $isOverdue ? 'btn-danger' : 'btn-outline-danger' }}">
        <i class="bi bi-exclamation-triangle me-1"></i>Overdue
    </a>
    <a href="{{ route($listRoute, array_merge(
        $prefilter,
        request()->except('status', 'page'),
        $isWaiting ? [] : ['status' => 'pending_client']
    )) }}"
       class="btn btn-sm {{ $isWaiting ? 'btn-warning text-dark' : 'btn-outline-warning' }}">
        <i class="bi bi-hourglass-split me-1"></i>Waiting
    </a>

    @php
        $activeFilters = [];
        if ($isNeedsAction) $activeFilters[] = 'Needs Action';
        elseif ($isWaiting) $activeFilters[] = 'Waiting on Client';
        elseif (!empty($filters['status'])) $activeFilters[] = App\Enums\TicketStatus::tryFrom($filters['status'])?->label() ?? $filters['status'];
        if (!empty($filters['priority'])) $activeFilters[] = App\Enums\TicketPriority::tryFrom($filters['priority'])?->label() ?? $filters['priority'];
        if (!empty($filters['type'])) $activeFilters[] = App\Enums\TicketType::tryFrom($filters['type'])?->label() ?? $filters['type'];
        if (!empty($filters['source'])) $activeFilters[] = App\Enums\TicketSource::tryFrom($filters['source'])?->label() ?? $filters['source'];
        if (!empty($filters['client_id']) && !isset($prefilter['client_id'])) $activeFilters[] = $clients->firstWhere('id', $filters['client_id'])?->name ?? 'Client';
        if (!empty($filters['search'])) $activeFilters[] = '"' . $filters['search'] . '"';
        if ($filters['show_closed'] ?? false) $activeFilters[] = 'Including closed';
        if ($isOverdue) $activeFilters[] = 'Overdue only';
    @endphp

    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCard">
        <i class="bi bi-funnel me-1"></i>Filters
        @if(count($activeFilters))
            <span class="text-muted ms-1">({{ implode(', ', $activeFilters) }})</span>
        @endif
    </button>
</div>

{{-- Filter card (auto-expand when non-default filters active) --}}
@php
    $hasAdvancedFilters = (!empty($filters['status']) && !$isNeedsAction && !$isWaiting) || !empty($filters['priority']) || !empty($filters['type'])
        || !empty($filters['source']) || (!empty($filters['client_id']) && !isset($prefilter['client_id'])) || !empty($filters['search'])
        || ($filters['show_closed'] ?? false);
@endphp
<div class="collapse {{ $hasAdvancedFilters ? 'show' : '' }} mb-3" id="filterCard">
    <div class="card shadow-sm card-static">
        <div class="card-body">
            <form method="GET" action="{{ route($listRoute, $prefilter) }}">
                <input type="hidden" name="assignee_id" value="{{ $filters['assignee_id'] ?? auth()->id() }}">
                @if(!empty($filters['sort']) && $filters['sort'] !== 'priority')
                    <input type="hidden" name="sort" value="{{ $filters['sort'] }}">
                @endif
                @if(!empty($filters['direction']) && $filters['direction'] !== 'asc')
                    <input type="hidden" name="direction" value="{{ $filters['direction'] }}">
                @endif
                @if(!empty($filters['overdue']))
                    <input type="hidden" name="overdue" value="1">
                @endif
                <div class="row g-2">
                    <div class="col-lg-2 col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="">All Statuses</option>
                            @foreach($statuses as $s)
                                <option value="{{ $s->value }}" {{ ($filters['status'] ?? '') === $s->value ? 'selected' : '' }}>
                                    {{ $s->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <select name="priority" class="form-select form-select-sm">
                            <option value="">All Priorities</option>
                            @foreach($priorities as $p)
                                <option value="{{ $p->value }}" {{ ($filters['priority'] ?? '') === $p->value ? 'selected' : '' }}>
                                    {{ $p->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <select name="type" class="form-select form-select-sm">
                            <option value="">All Types</option>
                            @foreach($types as $t)
                                <option value="{{ $t->value }}" {{ ($filters['type'] ?? '') === $t->value ? 'selected' : '' }}>
                                    {{ $t->label() }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <select name="source" class="form-select form-select-sm">
                            <option value="">All Sources</option>
                            @foreach($sources as $src)
                                <option value="{{ $src->value }}" {{ ($filters['source'] ?? '') === $src->value ? 'selected' : '' }}>
                                    {{ $src->label() }}
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
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Search..." value="{{ $filters['search'] ?? '' }}">
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col d-flex align-items-center gap-3">
                        <div class="form-check form-check-inline mb-0">
                            <input type="checkbox" name="show_closed" value="1" class="form-check-input"
                                   id="showClosed" {{ ($filters['show_closed'] ?? false) ? 'checked' : '' }}>
                            <label class="form-check-label small" for="showClosed">Include closed</label>
                        </div>
                        <div class="d-flex gap-2 ms-auto">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Search</button>
                            <a href="{{ route($listRoute, $prefilter) }}" class="btn btn-outline-secondary btn-sm" title="Clear all filters">
                                <i class="bi bi-x-lg me-1"></i>Clear
                            </a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@if($tickets->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="bi bi-ticket-perforated" style="font-size: 3rem;"></i>
        <p class="mt-3">No tickets found.</p>
        <a href="{{ route('tickets.create', isset($prefilter['client_id']) ? ['client_id' => $prefilter['client_id']] : []) }}" class="btn btn-primary btn-sm">Create a Ticket</a>
    </div>
@else
    @php
        $currentSort = $filters['sort'] ?? 'priority';
        $currentDir = $filters['direction'] ?? 'asc';

        // Default sort directions per column (what makes sense when first clicking)
        $defaultDirs = [
            'priority' => 'asc', 'status' => 'asc', 'client' => 'asc', 'assignee' => 'asc',
            'updated_at' => 'desc', 'opened_at' => 'desc', 'due_at' => 'asc', 'created_at' => 'desc',
        ];
    @endphp

    <div class="card shadow-sm card-static">
        @if($showBulkActions)
        <div class="text-center py-2 bg-light border-bottom small" id="selectAllBanner" style="display:none;">
            <span id="selectAllBannerText"></span>
        </div>
        @endif
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        @if(!$columns || in_array('checkbox', $columns))
                        <th style="width: 30px;"><input type="checkbox" class="form-check-input" id="selectAll"></th>
                        @endif
                        @if(!$columns || in_array('id', $columns))
                        <th>ID</th>
                        @endif
                        @if(!$columns || in_array('subject', $columns))
                        <th>Subject</th>
                        @endif
                        @if((!$columns || in_array('client', $columns)) && !isset($prefilter['client_id']))
                        <th class="{{ $currentSort === 'client' ? 'active-sort' : '' }}">
                            <a href="{{ ticketSortUrl('client', $currentSort, $currentDir, $defaultDirs) }}" class="sortable-th">
                                Client <i class="bi {{ ticketSortIcon('client', $currentSort, $currentDir) }} sort-icon"></i>
                            </a>
                        </th>
                        @endif
                        @if(!$columns || in_array('priority', $columns))
                        <th class="{{ $currentSort === 'priority' ? 'active-sort' : '' }}">
                            <a href="{{ ticketSortUrl('priority', $currentSort, $currentDir, $defaultDirs) }}" class="sortable-th">
                                Priority <i class="bi {{ ticketSortIcon('priority', $currentSort, $currentDir) }} sort-icon"></i>
                            </a>
                        </th>
                        @endif
                        @if(!$columns || in_array('status', $columns))
                        <th class="{{ $currentSort === 'status' ? 'active-sort' : '' }}">
                            <a href="{{ ticketSortUrl('status', $currentSort, $currentDir, $defaultDirs) }}" class="sortable-th">
                                Status <i class="bi {{ ticketSortIcon('status', $currentSort, $currentDir) }} sort-icon"></i>
                            </a>
                        </th>
                        @endif
                        @if(!$columns || in_array('type', $columns))
                        <th>Type</th>
                        @endif
                        @if(!$columns || in_array('source', $columns))
                        <th>Source</th>
                        @endif
                        @if(!$columns || in_array('assets', $columns))
                        <th>Assets</th>
                        @endif
                        @if(!$columns || in_array('assignee', $columns))
                        <th class="{{ $currentSort === 'assignee' ? 'active-sort' : '' }}">
                            <a href="{{ ticketSortUrl('assignee', $currentSort, $currentDir, $defaultDirs) }}" class="sortable-th">
                                Assignee <i class="bi {{ ticketSortIcon('assignee', $currentSort, $currentDir) }} sort-icon"></i>
                            </a>
                        </th>
                        @endif
                        @if(!$columns || in_array('time', $columns))
                        <th>Time</th>
                        @endif
                        @if(!$columns || in_array('opened_at', $columns))
                        <th class="{{ $currentSort === 'opened_at' ? 'active-sort' : '' }}">
                            <a href="{{ ticketSortUrl('opened_at', $currentSort, $currentDir, $defaultDirs) }}" class="sortable-th">
                                Opened <i class="bi {{ ticketSortIcon('opened_at', $currentSort, $currentDir) }} sort-icon"></i>
                            </a>
                        </th>
                        @endif
                        @if(!$columns || in_array('updated_at', $columns))
                        <th class="{{ $currentSort === 'updated_at' ? 'active-sort' : '' }}">
                            <a href="{{ ticketSortUrl('updated_at', $currentSort, $currentDir, $defaultDirs) }}" class="sortable-th">
                                Last Activity <i class="bi {{ ticketSortIcon('updated_at', $currentSort, $currentDir) }} sort-icon"></i>
                            </a>
                        </th>
                        @endif
                        @if(!$columns || in_array('due_at', $columns))
                        <th class="{{ $currentSort === 'due_at' ? 'active-sort' : '' }}">
                            <a href="{{ ticketSortUrl('due_at', $currentSort, $currentDir, $defaultDirs) }}" class="sortable-th">
                                Due <i class="bi {{ ticketSortIcon('due_at', $currentSort, $currentDir) }} sort-icon"></i>
                            </a>
                        </th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($tickets as $ticket)
                        @php
                            $priorityBorderColor = match($ticket->priority) {
                                App\Enums\TicketPriority::P1 => '#dc3545',
                                App\Enums\TicketPriority::P2 => '#ffc107',
                                App\Enums\TicketPriority::P3 => '#0dcaf0',
                                App\Enums\TicketPriority::P4 => '#6c757d',
                            };
                        @endphp
                        <tr class="cursor-pointer" onclick="window.location='{{ route('tickets.show', $ticket) }}'"
                            style="border-left: 3px solid {{ $priorityBorderColor }};">
                            @if(!$columns || in_array('checkbox', $columns))
                            <td onclick="event.stopPropagation()">
                                <input type="checkbox" class="form-check-input ticket-checkbox" value="{{ $ticket->id }}">
                            </td>
                            @endif
                            @if(!$columns || in_array('id', $columns))
                            <td class="text-muted small">
                                {{ $ticket->display_id }}
                                @if($ticket->triage_runs_count > 0)
                                    @php
                                        $classification = $ticket->latestTriageRun?->classification();
                                        $triageColor = match($classification['client_type'] ?? '') {
                                            'managed_services' => '#198754',
                                            'break_fix' => '#ffc107',
                                            'no_contract' => '#dc3545',
                                            default => '#6f42c1',
                                        };
                                        $triageLabel = match($classification['client_type'] ?? '') {
                                            'managed_services' => 'Managed',
                                            'break_fix' => 'Break/Fix',
                                            'no_contract' => 'No Contract',
                                            default => 'AI Triaged',
                                        };
                                    @endphp
                                    <i class="bi bi-robot ms-1" style="color: {{ $triageColor }}; font-size: 0.7rem;" title="{{ $triageLabel }}"></i>
                                @endif
                            </td>
                            @endif
                            @if(!$columns || in_array('subject', $columns))
                            <td>
                                <a href="{{ route('tickets.show', $ticket) }}" class="text-decoration-none fw-semibold">
                                    {{ Str::limit($ticket->subject, 60) }}
                                </a>
                            </td>
                            @endif
                            @if((!$columns || in_array('client', $columns)) && !isset($prefilter['client_id']))
                            <td class="small"><x-client-badge :client="$ticket->client" fallback="-" /></td>
                            @endif
                            @if(!$columns || in_array('priority', $columns))
                            <td><span class="badge {{ $ticket->priority->badgeClass() }}">{{ $ticket->priority->label() }}</span></td>
                            @endif
                            @if(!$columns || in_array('status', $columns))
                            <td><span class="badge {{ $ticket->status->badgeClass() }}">{{ $ticket->status->label() }}</span></td>
                            @endif
                            @if(!$columns || in_array('type', $columns))
                            <td class="small">
                                <i class="bi {{ $ticket->type->icon() }} me-1"></i>{{ $ticket->type->label() }}
                            </td>
                            @endif
                            @if(!$columns || in_array('source', $columns))
                            <td class="small text-muted">{{ $ticket->source->label() }}</td>
                            @endif
                            @if(!$columns || in_array('assets', $columns))
                            <td class="small" onclick="event.stopPropagation()">
                                @forelse($ticket->assets as $ticketAsset)
                                    <div class="mb-1"><x-asset-badge :asset="$ticketAsset" :link="false" /></div>
                                @empty
                                    <span class="text-muted">-</span>
                                @endforelse
                            </td>
                            @endif
                            @if(!$columns || in_array('assignee', $columns))
                            <td class="small"><x-user-badge :user="$ticket->assignee" fallback="-" /></td>
                            @endif
                            @if(!$columns || in_array('time', $columns))
                            <td class="small">{{ $ticket->formatted_total_time ?? '-' }}</td>
                            @endif
                            @if(!$columns || in_array('opened_at', $columns))
                            <td class="small" title="{{ $ticket->opened_at?->toAppTz()->format('Y-m-d H:i T') }}">
                                {{ $ticket->opened_at?->diffForHumans() ?? '-' }}
                            </td>
                            @endif
                            @if(!$columns || in_array('updated_at', $columns))
                            <td class="small" title="{{ $ticket->updated_at?->toAppTz()->format('Y-m-d H:i T') }}">
                                {{ $ticket->updated_at?->diffForHumans() }}
                            </td>
                            @endif
                            @if(!$columns || in_array('due_at', $columns))
                            <td class="small {{ $ticket->isOverdue() || $ticket->isResponseOverdue() ? 'text-danger fw-bold' : '' }}">
                                @if($ticket->isResponseOverdue())
                                    <i class="bi bi-exclamation-triangle-fill text-danger me-1" title="Response SLA breach"></i>
                                @elseif($ticket->isOverdue())
                                    <i class="bi bi-exclamation-triangle-fill text-danger me-1" title="Resolution SLA breach"></i>
                                @endif
                                @if($ticket->due_at)
                                    {{ $ticket->due_at->diffForHumans() }}
                                @elseif($ticket->response_due_at && !$ticket->responded_at)
                                    {{ $ticket->response_due_at->diffForHumans() }}
                                @else
                                    -
                                @endif
                            </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 d-flex align-items-center justify-content-between">
        <div>{{ $tickets->links() }}</div>
        @if($showFilters)
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="saveDefaults" title="Remember current filters and sort as your default view">
                <i class="bi bi-bookmark me-1"></i>Save as default
            </button>
            @if(false) {{-- Only show reset if defaults exist — controlled by JS --}}
            @endif
            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="resetDefaults" title="Clear saved default view">
                <i class="bi bi-bookmark-x me-1"></i>Reset default
            </button>
        </div>
        @endif
    </div>

    @if($showBulkActions)
    {{-- Bulk Action Bar --}}
    <div class="bulk-action-bar" id="bulkBar">
        <span id="bulkCount" class="fw-semibold me-2">0 selected</span>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('close')">
            <i class="bi bi-x-circle me-1"></i>Close
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('reassign')">
            <i class="bi bi-person-check me-1"></i>Reassign
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('priority')">
            <i class="bi bi-flag me-1"></i>Priority
        </button>
        @if(\App\Support\TriageConfig::isEnabled())
        <button type="button" class="btn btn-sm btn-outline-info" onclick="openBulkModal('triage')">
            <i class="bi bi-robot me-1"></i>Triage
        </button>
        <button type="button" class="btn btn-sm btn-outline-info" onclick="openBulkModal('review')">
            <i class="bi bi-robot me-1"></i>Review
        </button>
        @endif
        <a href="#" class="text-light ms-auto small" onclick="deselectAll(); return false;">Deselect All</a>
    </div>

    {{-- Bulk Action Modal --}}
    <div class="modal fade" id="bulkActionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('tickets.bulk-action') }}" id="bulkForm">
                    @csrf
                    <input type="hidden" name="action" id="bulkActionField">
                    <div id="bulkTicketInputs"></div>
                    <div class="modal-header">
                        <h5 class="modal-title" id="bulkModalTitle">Confirm</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body" id="bulkModalBody"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm" id="bulkSubmitBtn">Confirm</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    @endif
@endif

@push('styles')
<style>
.cursor-pointer { cursor: pointer; }
.sortable-th {
    color: #fff !important;
    text-decoration: none !important;
    white-space: nowrap;
}
.sortable-th:hover { color: var(--accent) !important; }
.sort-icon { font-size: 0.7rem; opacity: 0.4; margin-left: 2px; }
.active-sort .sort-icon { opacity: 1; }
.bulk-action-bar {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: var(--primary-dark, #0f2440);
    color: #fff;
    padding: 0.6rem 1.5rem;
    display: none;
    align-items: center;
    gap: 0.5rem;
    z-index: 1050;
    border-top: 2px solid var(--accent, #fed136);
    box-shadow: 0 -4px 20px rgba(0,0,0,0.3);
}
.bulk-action-bar.active { display: flex; }
</style>
@endpush

@push('scripts')
<script>
@if($showFilters)
// Preference persistence via localStorage
(function() {
    var STORAGE_KEY = 'ticketListDefaults_{{ $listRoute }}';

    // On bare /tickets (no query params): redirect to saved defaults
    if (!window.location.search) {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            window.location.replace(window.location.pathname + saved);
            return;
        }
    }

    // Save defaults button
    var saveBtn = document.getElementById('saveDefaults');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            localStorage.setItem(STORAGE_KEY, window.location.search || '?');
            this.innerHTML = '<i class="bi bi-check me-1"></i>Saved!';
            setTimeout(function() {
                saveBtn.innerHTML = '<i class="bi bi-bookmark me-1"></i>Save as default';
            }, 1500);
            document.getElementById('resetDefaults').classList.remove('d-none');
        });
    }

    // Reset defaults button
    var resetBtn = document.getElementById('resetDefaults');
    if (resetBtn) {
        if (localStorage.getItem(STORAGE_KEY)) {
            resetBtn.classList.remove('d-none');
        }
        resetBtn.addEventListener('click', function() {
            localStorage.removeItem(STORAGE_KEY);
            this.classList.add('d-none');
            window.location.href = window.location.pathname;
        });
    }

    // Auto-submit filter dropdowns on change
    document.querySelectorAll('#filterCard select').forEach(function(sel) {
        sel.addEventListener('change', function() { this.closest('form').submit(); });
    });
})();
@endif

@if($showBulkActions)
// Bulk actions
(function() {
    var selectedIds = new Set();
    var selectAllFilter = false;
    var bar = document.getElementById('bulkBar');
    var countEl = document.getElementById('bulkCount');
    var selectAllEl = document.getElementById('selectAll');
    var banner = document.getElementById('selectAllBanner');
    var bannerText = document.getElementById('selectAllBannerText');
    if (!bar || !selectAllEl) return;

    var totalFilterCount = {{ $tickets->total() }};
    var pageTicketCount = {{ $tickets->count() }};
    @php
        $jsFilters = array_filter([
            'status' => $filters['status'] ?? null,
            'priority' => $filters['priority'] ?? null,
            'type' => $filters['type'] ?? null,
            'source' => $filters['source'] ?? null,
            'client_id' => $filters['client_id'] ?? null,
            'assignee_id' => $filters['assignee_id'] ?? null,
            'search' => $filters['search'] ?? null,
            'show_closed' => ($filters['show_closed'] ?? false) ? '1' : null,
            'overdue' => ($filters['overdue'] ?? false) ? '1' : null,
        ], fn($v) => $v !== null);
    @endphp
    var currentFilters = @json($jsFilters);

    function updateBar() {
        var displayCount = selectAllFilter ? totalFilterCount : selectedIds.size;
        countEl.textContent = displayCount + ' selected';
        bar.classList.toggle('active', displayCount > 0);

        var allOnPage = selectedIds.size === pageTicketCount && pageTicketCount > 0;
        selectAllEl.indeterminate = selectedIds.size > 0 && selectedIds.size < pageTicketCount;
        selectAllEl.checked = allOnPage;

        // Show banner when all on page selected and there are more pages
        if (banner) {
            if (selectAllFilter) {
                banner.style.display = '';
                bannerText.innerHTML = 'All <strong>' + totalFilterCount + '</strong> tickets in this filter are selected. <a href="#" onclick="window.clearFilterSelection(); return false;">Clear selection</a>';
            } else if (allOnPage && totalFilterCount > pageTicketCount) {
                banner.style.display = '';
                bannerText.innerHTML = 'All <strong>' + pageTicketCount + '</strong> tickets on this page are selected. <a href="#" onclick="window.selectAllInFilter(); return false;">Select all <strong>' + totalFilterCount + '</strong> tickets matching this filter</a>';
            } else {
                banner.style.display = 'none';
            }
        }
    }

    selectAllEl.addEventListener('change', function() {
        selectAllFilter = false;
        document.querySelectorAll('.ticket-checkbox').forEach(function(cb) {
            cb.checked = selectAllEl.checked;
            if (selectAllEl.checked) selectedIds.add(cb.value);
            else selectedIds.delete(cb.value);
        });
        updateBar();
    });

    document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('ticket-checkbox')) return;
        if (selectAllFilter) selectAllFilter = false;
        if (e.target.checked) selectedIds.add(e.target.value);
        else selectedIds.delete(e.target.value);
        updateBar();
    });

    window.selectAllInFilter = function() {
        selectAllFilter = true;
        // Check all visible checkboxes too
        document.querySelectorAll('.ticket-checkbox').forEach(function(cb) {
            cb.checked = true;
            selectedIds.add(cb.value);
        });
        updateBar();
    };

    window.clearFilterSelection = function() {
        selectAllFilter = false;
        selectedIds.clear();
        document.querySelectorAll('.ticket-checkbox').forEach(function(cb) { cb.checked = false; });
        selectAllEl.checked = false;
        selectAllEl.indeterminate = false;
        updateBar();
    };

    window.deselectAll = function() {
        selectAllFilter = false;
        selectedIds.clear();
        document.querySelectorAll('.ticket-checkbox').forEach(function(cb) { cb.checked = false; });
        selectAllEl.checked = false;
        selectAllEl.indeterminate = false;
        updateBar();
    };

    window.openBulkModal = function(action) {
        var count = selectAllFilter ? totalFilterCount : selectedIds.size;
        if (count === 0) return;

        document.getElementById('bulkActionField').value = action;

        // Populate hidden inputs
        var container = document.getElementById('bulkTicketInputs');
        container.innerHTML = '';

        if (selectAllFilter) {
            var saf = document.createElement('input');
            saf.type = 'hidden'; saf.name = 'select_all_filter'; saf.value = '1';
            container.appendChild(saf);
            for (var key in currentFilters) {
                var fi = document.createElement('input');
                fi.type = 'hidden';
                fi.name = 'filter_' + key;
                fi.value = currentFilters[key];
                container.appendChild(fi);
            }
        } else {
            selectedIds.forEach(function(id) {
                var input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'ticket_ids[]';
                input.value = id;
                container.appendChild(input);
            });
        }

        var title = document.getElementById('bulkModalTitle');
        var body = document.getElementById('bulkModalBody');
        var btn = document.getElementById('bulkSubmitBtn');

        body.innerHTML = '';

        if (action === 'close') {
            title.textContent = 'Close Tickets';
            body.innerHTML = '<p>Close <strong>' + count + '</strong> ticket(s)? This will set their status to Closed.</p>';
            btn.textContent = 'Close ' + count + ' ticket(s)';
            btn.className = 'btn btn-danger btn-sm';
        } else if (action === 'reassign') {
            title.textContent = 'Reassign Tickets';
            body.innerHTML = '<p>Reassign <strong>' + count + '</strong> ticket(s) to:</p>'
                + '<select name="assignee_id" class="form-select form-select-sm" required>'
                + '<option value="">Select technician...</option>'
                + @json($users->map(fn($u) => ['id' => $u->id, 'name' => $u->name])).map(function(u) {
                    return '<option value="' + u.id + '">' + u.name + '</option>';
                }).join('')
                + '</select>';
            btn.textContent = 'Reassign ' + count + ' ticket(s)';
            btn.className = 'btn btn-primary btn-sm';
        } else if (action === 'priority') {
            title.textContent = 'Change Priority';
            body.innerHTML = '<p>Change priority on <strong>' + count + '</strong> ticket(s) to:</p>'
                + '<select name="priority" class="form-select form-select-sm" required>'
                + '<option value="">Select priority...</option>'
                + @json(collect(App\Enums\TicketPriority::cases())->map(fn($p) => ['value' => $p->value, 'label' => $p->label()])).map(function(p) {
                    return '<option value="' + p.value + '">' + p.label + '</option>';
                }).join('')
                + '</select>';
            btn.textContent = 'Update ' + count + ' ticket(s)';
            btn.className = 'btn btn-primary btn-sm';
        } else if (action === 'triage') {
            title.textContent = 'Queue AI Triage';
            body.innerHTML = '<p>Queue AI Triage for <strong>' + count + '</strong> ticket(s)? Each ticket will be processed individually.</p>';
            btn.textContent = 'Queue ' + count + ' ticket(s)';
            btn.className = 'btn btn-info btn-sm';
        } else if (action === 'review') {
            title.textContent = 'Queue AI Review';
            body.innerHTML = '<p>Queue AI Review for <strong>' + count + '</strong> ticket(s)? Each ticket will be reviewed individually.</p>';
            btn.textContent = 'Queue ' + count + ' ticket(s)';
            btn.className = 'btn btn-info btn-sm';
        }

        new bootstrap.Modal(document.getElementById('bulkActionModal')).show();
    };
})();
@endif
</script>
@endpush