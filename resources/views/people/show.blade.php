@extends('layouts.app')

@section('title', $person->fullName . '')

@section('content')
<div class="row mb-3">
    <div class="col">
        <a href="{{ route('people.index') }}" class="text-decoration-none text-muted">
            <i class="bi bi-arrow-left me-1"></i>Back to People
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-3">
            <x-avatar :avatarUrl="$person->avatar_url" :name="$person->fullName" :size="48" />
            <div>
                <h4 class="section-title mb-1">{{ $person->fullName }}</h4>
                @if($person->is_primary)
                    <span class="badge bg-warning text-dark">Primary</span>
                @endif
                @if($person->person_type !== \App\Enums\PersonType::User)
                    <span class="badge bg-secondary">
                        <i class="{{ $person->person_type->icon() }} me-1"></i>{{ $person->person_type->label() }}
                    </span>
                @endif
                @unless($person->is_active)
                    <span class="badge bg-secondary">Inactive</span>
                @endunless
            </div>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('people.edit', $person) }}" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-pencil me-1"></i>Edit
            </a>
            @if(($mergeCandidates ?? collect())->isNotEmpty())
                <button type="button" class="btn btn-outline-secondary btn-sm" title="Merge a duplicate contact into this one"
                        data-bs-toggle="modal" data-bs-target="#mergePersonModal">
                    <i class="bi bi-box-arrow-in-down-left me-1"></i>Merge
                </button>
            @endif
            <button type="button" class="btn btn-outline-danger btn-sm" title="Delete contact"
                    data-bs-toggle="modal" data-bs-target="#deletePersonModal">
                <i class="bi bi-trash"></i>
            </button>
        </div>
    </div>
</div>

{{-- Tabs --}}
<ul class="nav nav-tabs detail-tabs mb-3" id="personTabs" role="tablist">
    <li class="nav-item" role="presentation">
        @if(($activeTab ?? '') === 'tickets')
            <a class="nav-link" href="{{ route('people.show', $person) }}">Overview</a>
        @else
            <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">Overview</button>
        @endif
    </li>
    <li class="nav-item" role="presentation">
        @if(($activeTab ?? '') === 'tickets')
            <a class="nav-link" href="{{ route('people.show', $person) }}#person-activity">Activity</a>
        @else
            <button class="nav-link" id="person-activity-tab" data-bs-toggle="tab" data-bs-target="#person-activity" type="button" role="tab">Activity</button>
        @endif
    </li>
    <li class="nav-item" role="presentation">
        @if(($activeTab ?? '') === 'tickets')
            <button class="nav-link active" type="button">
                Tickets @if(isset($tickets))<span class="text-muted">({{ $tickets->total() }})</span>@endif
            </button>
        @else
            <a class="nav-link" href="{{ route('people.tickets', $person) }}">
                Tickets @if($recentTickets->isNotEmpty())<span class="text-muted">({{ $recentTickets->count() }})</span>@endif
            </a>
        @endif
    </li>
</ul>

<div class="tab-content">
    {{-- Overview Tab --}}
    <div class="tab-pane fade {{ ($activeTab ?? '') !== 'tickets' ? 'show active' : '' }}" id="overview" role="tabpanel">
        @if(!empty($person->notes))
            <div class="card shadow-sm mb-4">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-sticky"></i><span>Notes</span>
                </div>
                <div class="card-body">
                    <div class="mb-0" style="white-space: pre-wrap;">{{ $person->notes }}</div>
                </div>
            </div>
        @endif
        <div class="row g-4">
            {{-- Contact Info --}}
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header"><i class="bi bi-person me-2"></i>Contact Info</div>
                    <div class="card-body">
                        <table class="table table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <th class="text-muted" style="width: 120px;">Name</th>
                                    <td>{{ $person->fullName }}</td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Email</th>
                                    <td>
                                        @if($person->email)
                                            <a href="mailto:{{ $person->email }}">{{ $person->email }}</a>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                        @foreach($person->additionalEmailAddresses as $extra)
                                            <div class="small text-muted mt-1">
                                                <a href="mailto:{{ $extra->email }}" class="text-muted">{{ $extra->email }}</a>
                                                @if($extra->label)
                                                    <span class="badge bg-light text-dark border ms-1">{{ $extra->label }}</span>
                                                @endif
                                            </div>
                                        @endforeach
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Phone</th>
                                    <td>
                                        @if($person->phone_display)
                                            <a href="#" data-phone="{{ $person->phone }}" class="text-decoration-none dial-link">
                                                {{ $person->phone_display }}
                                            </a>
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Mobile</th>
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
                                <tr>
                                    <th class="text-muted">Job Title</th>
                                    <td>{{ $person->job_title ?: '-' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            {{-- Details --}}
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header"><i class="bi bi-building me-2"></i>Details</div>
                    <div class="card-body">
                        <table class="table table-borderless mb-0">
                            <tbody>
                                <tr>
                                    <th class="text-muted" style="width: 120px;">Client</th>
                                    <td>
                                        <x-client-badge :client="$person->client" :size="24" fallback="Unassigned" />
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Type</th>
                                    <td>
                                        <i class="{{ $person->person_type->icon() }} me-1"></i>{{ $person->person_type->label() }}
                                        @unless($person->person_type->isBillable())
                                            <span class="text-muted small">(not billed)</span>
                                        @endunless
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Primary</th>
                                    <td>
                                        @if($person->is_primary)
                                            <span class="badge bg-warning text-dark">Yes</span>
                                        @else
                                            No
                                        @endif
                                    </td>
                                </tr>
                                <tr>
                                    <th class="text-muted">Status</th>
                                    <td>
                                        @if($person->is_active)
                                            <span class="badge bg-success">Active</span>
                                        @else
                                            <span class="badge bg-secondary">Inactive</span>
                                        @endif
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        {{-- M365 Details (CIPP enrichment) --}}
        @if($person->cipp_user_id)
        <div class="row mt-4">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-header"><i class="bi bi-microsoft me-2"></i>M365 Details</div>
                    <div class="card-body">
                        @if($person->cipp_enriched_at?->lt(now()->subDays(2)))
                            <div class="alert alert-warning py-1 px-2 small mb-2">
                                <i class="bi bi-exclamation-triangle me-1"></i>Data may be stale (last enriched {{ $person->cipp_enriched_at->diffForHumans() }})
                            </div>
                        @endif
                        <table class="table table-borderless mb-0">
                            <tbody>
                                @if($person->cipp_upn)
                                <tr>
                                    <th class="text-muted" style="width: 130px;">UPN</th>
                                    <td class="small">{{ $person->cipp_upn }}</td>
                                </tr>
                                @endif
                                @if($person->department)
                                <tr>
                                    <th class="text-muted">Department</th>
                                    <td>{{ $person->department }}</td>
                                </tr>
                                @endif
                                @if($person->office_location)
                                <tr>
                                    <th class="text-muted">Office</th>
                                    <td>{{ $person->office_location }}</td>
                                </tr>
                                @endif
                                @if($person->m365_user_type)
                                <tr>
                                    <th class="text-muted">User Type</th>
                                    <td>
                                        @if($person->m365_user_type === 'Guest')
                                            <span class="badge bg-info">Guest</span>
                                        @else
                                            <span class="badge bg-primary">Member</span>
                                        @endif
                                    </td>
                                </tr>
                                @endif
                                @if($person->is_hybrid !== null)
                                <tr>
                                    <th class="text-muted">Identity</th>
                                    <td>
                                        @if($person->is_hybrid)
                                            <span class="badge bg-warning text-dark">Hybrid (AD Sync)</span>
                                        @else
                                            <span class="badge bg-success">Cloud-Only</span>
                                        @endif
                                    </td>
                                </tr>
                                @endif
                                @if($person->mfa_enabled !== null)
                                <tr>
                                    <th class="text-muted">MFA</th>
                                    <td>
                                        @if($person->mfa_enabled)
                                            <span class="badge bg-success"><i class="bi bi-shield-check me-1"></i>Enabled</span>
                                        @else
                                            <span class="badge bg-danger"><i class="bi bi-shield-x me-1"></i>Not Registered</span>
                                        @endif
                                    </td>
                                </tr>
                                @endif
                                @if($person->mailbox_size_bytes !== null)
                                <tr>
                                    <th class="text-muted">Mailbox</th>
                                    <td>
                                        {{ $person->mailbox_size_formatted }}
                                        @if($person->mailbox_item_count !== null)
                                            <small class="text-muted">({{ number_format($person->mailbox_item_count) }} items)</small>
                                        @endif
                                    </td>
                                </tr>
                                @endif
                                @if($person->mailbox_forwarding_smtp)
                                <tr>
                                    <th class="text-muted">Forward</th>
                                    <td>
                                        @if($person->hasExternalForward())
                                            <span class="badge bg-danger" data-bs-toggle="tooltip" title="External SMTP forward — common BEC indicator, review immediately">
                                                <i class="bi bi-exclamation-triangle me-1"></i>External
                                            </span>
                                        @else
                                            <span class="badge bg-secondary">Internal</span>
                                        @endif
                                        <code class="ms-2">{{ $person->mailbox_forwarding_smtp }}</code>
                                        @if($person->mailbox_deliver_and_forward)
                                            <div class="small text-muted mt-1">Also delivers to local mailbox</div>
                                        @endif
                                    </td>
                                </tr>
                                @endif
                                @if($person->cipp_inactive)
                                <tr>
                                    <th class="text-muted">Activity</th>
                                    <td>
                                        <span class="badge bg-warning text-dark" data-bs-toggle="tooltip" title="CIPP flagged this account as inactive (no recent sign-in)">
                                            <i class="bi bi-moon-stars me-1"></i>Inactive
                                        </span>
                                        @if($person->last_sign_in_at)
                                            <small class="text-muted ms-2">last seen {{ $person->last_sign_in_at->toAppTz()->diffForHumans() }}</small>
                                        @endif
                                    </td>
                                </tr>
                                @endif
                                @if($person->cipp_synced_at)
                                <tr>
                                    <th class="text-muted">Synced</th>
                                    <td class="text-muted small">{{ $person->cipp_synced_at->diffForHumans() }}</td>
                                </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Recent Tickets --}}
        @if($recentTickets->isNotEmpty())
        <div class="row mt-4">
            <div class="col">
                <div class="card shadow-sm">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-ticket-perforated me-2"></i>Recent Tickets
                        </div>
                        <a href="{{ route('people.tickets', $person) }}" class="btn btn-outline-primary btn-sm">View all tickets</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Subject</th>
                                    <th>Status</th>
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
                                        <td><span class="badge {{ $ticket->status->badgeClass() }}">{{ $ticket->status->label() }}</span></td>
                                        <td class="small">{{ $ticket->updated_at?->diffForHumans() }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        @endif

        {{-- Devices --}}
        @if($person->assets->isNotEmpty())
        <div class="row mt-4">
            <div class="col">
                <div class="card shadow-sm card-static">
                    <div class="card-header">
                        <i class="bi bi-pc-display me-2"></i>Devices
                        <span class="badge bg-light text-dark ms-1">{{ $person->assets->count() }}</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Device</th>
                                    <th>Type</th>
                                    <th class="text-center">Role</th>
                                    <th>Last Seen</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($person->assets->sortByDesc('pivot.is_primary') as $asset)
                                    <tr class="cursor-pointer" onclick="window.location='{{ route('assets.show', $asset) }}'">
                                        <td>
                                            <strong>{{ $asset->hostname ?: $asset->name }}</strong>
                                            @if($asset->hostname && $asset->hostname !== $asset->name)
                                                <br><small class="text-muted">{{ $asset->name }}</small>
                                            @endif
                                        </td>
                                        <td class="small">{{ $asset->asset_type ?: '—' }}</td>
                                        <td class="text-center">
                                            @if($asset->pivot->is_primary)
                                                <span class="badge bg-warning text-dark">Primary</span>
                                            @else
                                                <span class="text-muted small">User</span>
                                            @endif
                                        </td>
                                        <td class="small">
                                            @if($asset->pivot->last_seen_at)
                                                {{ \Carbon\Carbon::parse($asset->pivot->last_seen_at)->diffForHumans() }}
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
        </div>
        @endif

    </div>{{-- /overview tab --}}

    {{-- Activity Tab --}}
    <div class="tab-pane fade" id="person-activity" role="tabpanel" data-activity-url="{{ route('people.activity', $person) }}">
        {{-- Filter chips --}}
        <div class="stream-filters mb-3">
            <button class="stream-filter active" data-filter="all" onclick="personActivityFilter(this)">All</button>
            <button class="stream-filter" data-filter="ticket" onclick="personActivityFilter(this)">Tickets</button>
            <button class="stream-filter" data-filter="call" onclick="personActivityFilter(this)">Calls</button>
            <button class="stream-filter" data-filter="email" onclick="personActivityFilter(this)">Emails</button>
        </div>

        <div id="personActivityStream">
            <div class="text-center py-5 text-muted">
                <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                Loading activity...
            </div>
        </div>

        <div class="text-center mt-3" id="personLoadMoreContainer" style="display: none;">
            <button class="btn btn-outline-primary btn-sm" id="personLoadMoreBtn" onclick="personLoadMore()">
                <i class="bi bi-arrow-down me-1"></i>Load More
            </button>
        </div>
    </div>

    {{-- Tickets Tab --}}
    <div class="tab-pane fade {{ ($activeTab ?? '') === 'tickets' ? 'show active' : '' }}" id="tickets" role="tabpanel">
        @if(($activeTab ?? '') === 'tickets')
            @include('tickets._list', [
                'listRoute' => 'people.tickets',
                'prefilter' => ['person' => $person->id, 'client_id' => $person->client_id],
                'filters' => $ticketFilters,
                'clients' => $ticketClients,
                'users' => $ticketUsers,
                'statuses' => $ticketStatuses,
                'priorities' => $ticketPriorities,
                'types' => $ticketTypes,
                'sources' => $ticketSources,
            ])
        @endif
    </div>
</div>{{-- /tab-content --}}

{{-- Delete Modal --}}
<div class="modal fade" id="deletePersonModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Delete Contact</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>This will soft-delete <strong>{{ $person->fullName }}</strong>.</p>
                <p class="text-muted small">Contacts with open tickets cannot be deleted.</p>
                <label for="deletePersonConfirm" class="form-label mt-2">
                    To confirm, type <code>{{ $person->fullName }}</code> below.
                </label>
                <input type="text" class="form-control" id="deletePersonConfirm" autocomplete="off">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="{{ route('people.destroy', $person) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger" id="deletePersonBtn" disabled>
                        <i class="bi bi-trash me-1"></i>Delete Contact
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

{{-- Merge Modal --}}
@if(($mergeCandidates ?? collect())->isNotEmpty())
<div class="modal fade" id="mergePersonModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('people.merge', $person) }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-in-down-left me-2"></i>Merge Duplicate Contact</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="mergeDuplicateSelect" class="form-label">Contact to merge into this one</label>
                        <select class="form-select" id="mergeDuplicateSelect" name="duplicate_id" required>
                            <option value="" selected disabled>Choose a contact…</option>
                            @foreach($mergeCandidates as $candidate)
                                <option value="{{ $candidate->id }}" data-name="{{ $candidate->fullName }}">{{ $candidate->last_name }}, {{ $candidate->first_name }}@if($candidate->email) — {{ $candidate->email }}@endif@unless($candidate->is_active) (inactive)@endunless @if($candidate->portal_enabled)(portal)@endif</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="alert alert-warning small py-2 mb-2">
                        <i class="bi bi-exclamation-triangle me-1"></i><strong>This cannot be undone.</strong>
                        All tickets, calls, emails, contract &amp; device assignments, and email addresses
                        from the selected contact move to this contact, and its portal &amp; company-wide
                        access transfer here too. The selected contact is then removed (soft-deleted).
                        If they sign in to the portal, their sign-in email changes to
                        <strong>{{ $person->email ?? 'this contact’s email' }}</strong> — let them know.
                    </div>
                    <p class="mb-0" id="mergeDirection" style="display: none;">
                        Merge <strong id="mergeDuplicateName"></strong> into
                        <strong>{{ $person->fullName }}</strong> <span class="text-muted">(kept)</span>.
                    </p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning" id="mergePersonBtn" disabled>
                        <i class="bi bi-box-arrow-in-down-left me-1"></i>Merge contacts
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif
@endsection

@push('styles')
<link href="{{ asset('css/activity-stream.css') }}" rel="stylesheet">
@endpush

@push('scripts')
<script>
(function() {
    var expected = @json($person->fullName);
    var input = document.getElementById('deletePersonConfirm');
    var btn = document.getElementById('deletePersonBtn');
    input?.addEventListener('input', function() {
        btn.disabled = input.value !== expected;
    });
    document.getElementById('deletePersonModal')?.addEventListener('hidden.bs.modal', function() {
        input.value = '';
        btn.disabled = true;
    });
})();
</script>
<script>
(function() {
    var select = document.getElementById('mergeDuplicateSelect');
    var btn = document.getElementById('mergePersonBtn');
    var direction = document.getElementById('mergeDirection');
    var nameEl = document.getElementById('mergeDuplicateName');
    var modal = document.getElementById('mergePersonModal');
    if (!select || !btn) return;
    select.addEventListener('change', function() {
        var opt = select.options[select.selectedIndex];
        if (select.value) {
            btn.disabled = false;
            if (nameEl) nameEl.textContent = (opt && opt.getAttribute('data-name')) || 'this contact';
            if (direction) direction.style.display = '';
        } else {
            btn.disabled = true;
            if (direction) direction.style.display = 'none';
        }
    });
    modal?.addEventListener('hidden.bs.modal', function() {
        select.value = '';
        btn.disabled = true;
        if (direction) direction.style.display = 'none';
    });
})();
</script>
@if(($activeTab ?? '') !== 'tickets')
@include('components._tab-persistence', ['tabListId' => 'personTabs', 'storageKey' => 'person-show-tab'])
<script>
(function() {
    var activityLoaded = false;
    var activityFilters = new Set(['all']);

    // Load activity when tab is shown
    document.getElementById('person-activity-tab').addEventListener('shown.bs.tab', function() {
        if (!activityLoaded) {
            loadPersonActivity();
        }
    });

    // Also load if tab persistence restores the activity tab on page load
    setTimeout(function() {
        var activeTab = document.querySelector('#personTabs .nav-link.active');
        if (activeTab && activeTab.id === 'person-activity-tab' && !activityLoaded) {
            loadPersonActivity();
        }
    }, 100);

    function loadPersonActivity(before) {
        var pane = document.getElementById('person-activity');
        var url = pane.dataset.activityUrl;
        if (before) url += '?before=' + encodeURIComponent(before);

        var types = getActivityFilterParam();
        if (types) url += (before ? '&' : '?') + 'types=' + encodeURIComponent(types);

        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function(r) { return r.text(); })
            .then(function(html) {
                html = html.trim();
                var stream = document.getElementById('personActivityStream');
                if (before) {
                    if (!html || html.includes('No recent activity')) {
                        document.getElementById('personLoadMoreContainer').innerHTML =
                            '<span class="text-muted small">No more activity.</span>';
                        return;
                    }
                    stream.insertAdjacentHTML('beforeend', html);
                } else {
                    stream.innerHTML = html;
                    activityLoaded = true;
                }
                applyActivityFilters();
                var items = stream.querySelectorAll('.activity-item');
                var container = document.getElementById('personLoadMoreContainer');
                if (items.length >= 30) {
                    container.style.display = '';
                }
                var btn = document.getElementById('personLoadMoreBtn');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-down me-1"></i>Load More';
                }
            })
            .catch(function() {
                if (!before) {
                    document.getElementById('personActivityStream').innerHTML =
                        '<div class="text-center py-5 text-muted">Failed to load activity.</div>';
                }
            });
    }

    // Filter logic
    window.personActivityFilter = function(chip) {
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

        document.querySelectorAll('#person-activity .stream-filter').forEach(function(c) {
            c.classList.toggle('active', activityFilters.has(c.dataset.filter));
        });

        applyActivityFilters();
    };

    function applyActivityFilters() {
        var showAll = activityFilters.has('all');
        document.querySelectorAll('#personActivityStream .activity-item').forEach(function(el) {
            el.style.display = (showAll || activityFilters.has(el.dataset.type)) ? '' : 'none';
        });
    }

    function getActivityFilterParam() {
        if (activityFilters.has('all')) return '';
        return Array.from(activityFilters).join(',');
    }

    // Load More
    window.personLoadMore = function() {
        var items = document.querySelectorAll('#personActivityStream .activity-item');
        if (items.length === 0) return;

        var lastItem = items[items.length - 1];
        var before = lastItem.dataset.timestamp;
        var btn = document.getElementById('personLoadMoreBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Loading...';

        loadPersonActivity(before);
    };
})();
</script>
@endif
@endpush
