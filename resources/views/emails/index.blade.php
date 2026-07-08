@extends('layouts.app')

@section('title', 'Emails')

@section('content')
<div class="row mb-3">
    <div class="col d-flex align-items-center justify-content-between">
        <h4 class="section-title mb-0">
            Emails
            <span class="text-muted fw-normal" style="font-size: 0.85rem;">({{ $emails->total() }})</span>
        </h4>
        <a href="{{ route('emails.compose') }}" class="btn btn-accent btn-sm">
            <i class="bi bi-pencil-square me-1"></i>Compose
        </a>
    </div>
</div>

{{-- Quick filter pills --}}
@php
    $currentPreset = $filters['preset'] ?? '';
    $hasNoClient = !empty($filters['no_client']);
@endphp
<div class="mb-3 d-flex flex-wrap align-items-center gap-2">
    <div class="btn-group btn-group-sm">
        <a href="{{ route('emails.index', ['preset' => 'needs_attention']) }}"
           class="btn {{ $currentPreset === 'needs_attention' ? 'btn-primary' : 'btn-outline-primary' }}">
            Needs Attention
            @if($needsAttentionCount > 0)
                <span class="badge bg-warning text-dark ms-1">{{ $needsAttentionCount }}</span>
            @endif
        </a>
        <a href="{{ route('emails.index', ['preset' => 'inbound']) }}"
           class="btn {{ $currentPreset === 'inbound' ? 'btn-primary' : 'btn-outline-primary' }}">
            All Inbound
        </a>
        <a href="{{ route('emails.index', ['preset' => 'outbound']) }}"
           class="btn {{ $currentPreset === 'outbound' ? 'btn-primary' : 'btn-outline-primary' }}">
            Outbound
        </a>
        <a href="{{ route('emails.index', ['preset' => 'dismissed']) }}"
           class="btn {{ $currentPreset === 'dismissed' ? 'btn-primary' : 'btn-outline-primary' }}">
            Dismissed
        </a>
        <a href="{{ route('emails.index', ['preset' => 'all']) }}"
           class="btn {{ $currentPreset === 'all' ? 'btn-primary' : 'btn-outline-primary' }}">
            All
        </a>
    </div>

    {{-- No Client toggle pill --}}
    <a href="{{ route('emails.index', array_merge(
        request()->except('no_client', 'page'),
        $hasNoClient ? [] : ['no_client' => '1']
    )) }}"
       class="btn btn-sm {{ $hasNoClient ? 'btn-danger' : 'btn-outline-danger' }}">
        <i class="bi bi-person-x me-1"></i>No Client
        @if($noClientCount > 0)
            <span class="badge {{ $hasNoClient ? 'bg-light text-danger' : 'bg-danger' }} ms-1">{{ $noClientCount }}</span>
        @endif
    </a>

    @php
        $activeFilters = [];
        if (!empty($filters['search'])) $activeFilters[] = '"' . $filters['search'] . '"';
        if (isset($filters['is_read']) && $filters['is_read'] !== '') $activeFilters[] = $filters['is_read'] ? 'Read' : 'Unread';
        if (!empty($filters['date_from'])) $activeFilters[] = 'From: ' . $filters['date_from'];
        if (!empty($filters['date_to'])) $activeFilters[] = 'To: ' . $filters['date_to'];
        if (!empty($filters['client_id'])) $activeFilters[] = $clients->firstWhere('id', $filters['client_id'])?->name ?? 'Client';
    @endphp

    <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#filterCard">
        <i class="bi bi-funnel me-1"></i>Filters
        @if(count($activeFilters))
            <span class="text-muted ms-1">({{ implode(', ', $activeFilters) }})</span>
        @endif
    </button>
</div>

{{-- Advanced filter card --}}
@php
    $hasAdvancedFilters = !empty($filters['search']) || (isset($filters['is_read']) && $filters['is_read'] !== '')
        || !empty($filters['date_from']) || !empty($filters['date_to']) || !empty($filters['client_id']);
@endphp
<div class="collapse {{ $hasAdvancedFilters ? 'show' : '' }} mb-3" id="filterCard">
    <div class="card shadow-sm card-static">
        <div class="card-body">
            <form method="GET" action="{{ route('emails.index') }}">
                @if($currentPreset)
                    <input type="hidden" name="preset" value="{{ $currentPreset }}">
                @endif
                @if($hasNoClient)
                    <input type="hidden" name="no_client" value="1">
                @endif
                <div class="row g-2">
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label small mb-1">Date From</label>
                        <input type="date" name="date_from" class="form-control form-control-sm"
                               value="{{ $filters['date_from'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label small mb-1">Date To</label>
                        <input type="date" name="date_to" class="form-control form-control-sm"
                               value="{{ $filters['date_to'] ?? '' }}">
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label small mb-1">Read Status</label>
                        <select name="is_read" class="form-select form-select-sm">
                            <option value="">All</option>
                            <option value="0" {{ (isset($filters['is_read']) && $filters['is_read'] === '0') ? 'selected' : '' }}>Unread</option>
                            <option value="1" {{ (isset($filters['is_read']) && $filters['is_read'] === '1') ? 'selected' : '' }}>Read</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-3">
                        <label class="form-label small mb-1">Client</label>
                        <select name="client_id" class="form-select form-select-sm">
                            <option value="">All Clients</option>
                            @foreach($clients as $c)
                                <option value="{{ $c->id }}" {{ ($filters['client_id'] ?? '') == $c->id ? 'selected' : '' }}>
                                    {{ $c->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-4">
                        <label class="form-label small mb-1">Search</label>
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Subject, sender, or preview" value="{{ $filters['search'] ?? '' }}">
                    </div>
                    <div class="col-lg-1 col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
                        <a href="{{ route('emails.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

@if($emails->isEmpty())
    <div class="text-center py-5 text-muted">
        <i class="bi bi-envelope-check" style="font-size: 3rem;"></i>
        <p class="mt-3">
            @if($currentPreset === 'needs_attention')
                All caught up! No emails need attention.
            @else
                No emails found for the selected filters.
            @endif
        </p>
    </div>
@else
    <div class="card shadow-sm card-static">
        <div class="text-center py-2 bg-light border-bottom small" id="selectAllBanner" style="display:none;">
            <span id="selectAllBannerText"></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="thead-brand">
                    <tr>
                        <th style="width: 30px;"><input type="checkbox" class="form-check-input" id="selectAll"></th>
                        <th style="width: 30px"></th>
                        <th style="width: 110px">Received</th>
                        <th style="width: 180px">From</th>
                        <th style="width: 180px">To</th>
                        <th>Subject</th>
                        <th style="width: 120px">Ticket</th>
                        <th style="width: 160px">Client</th>
                        <th style="width: 40px"></th>
                        <th style="width: 100px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($emails as $email)
                    @php
                        $isNeedsAttention = $email->direction === App\Enums\EmailDirection::Inbound
                            && !$email->ticket_id
                            && !$email->dismissed_at;
                    @endphp
                    <tr class="{{ !$email->is_read ? 'fw-bold' : '' }} cursor-pointer" id="email-row-{{ $email->id }}"
                        onclick="window.location='{{ route('emails.show', $email) }}'"
                        style="{{ $isNeedsAttention ? 'border-left: 3px solid var(--accent, #fed136);' : '' }}">
                        {{-- Checkbox --}}
                        <td onclick="event.stopPropagation()">
                            <input type="checkbox" class="form-check-input email-checkbox" value="{{ $email->id }}">
                        </td>

                        {{-- Read/unread indicator --}}
                        <td class="text-center">
                            @if(!$email->is_read)
                                <i class="bi bi-envelope-fill text-primary" style="font-size: 0.7rem;" title="Unread"></i>
                            @else
                                <i class="bi bi-envelope-open text-muted" style="font-size: 0.7rem;" title="Read"></i>
                            @endif
                        </td>

                        {{-- Received timestamp --}}
                        <td class="text-nowrap">
                            <small title="{{ $email->received_at?->toAppTz()->format('d M Y H:i T') }}">
                                {{ $email->received_at?->diffForHumans() ?? '—' }}
                            </small>
                        </td>

                        {{-- From --}}
                        <td>
                            @if($email->direction === App\Enums\EmailDirection::Inbound)
                                @if($email->user)
                                    <x-user-badge :user="$email->user" :size="20" />
                                @elseif($email->person)
                                    <x-person-badge :person="$email->person" :link="false" />
                                @else
                                    <span class="text-truncate d-inline-block" style="max-width: 170px">{{ $email->senderDisplay() }}</span>
                                @endif
                            @else
                                <span class="text-truncate d-inline-block" style="max-width: 170px">{{ $email->senderDisplay() }}</span>
                            @endif
                        </td>

                        {{-- To --}}
                        <td>
                            @if($email->direction === App\Enums\EmailDirection::Outbound && ($email->user || $email->person))
                                @if($email->user)
                                    <x-user-badge :user="$email->user" :size="20" />
                                @elseif($email->person)
                                    <x-person-badge :person="$email->person" :link="false" />
                                @endif
                            @else
                                <span class="text-truncate d-inline-block" style="max-width: 170px" title="{{ $email->primaryRecipientAddress() }}">
                                    {{ $email->primaryRecipientDisplay() }}
                                </span>
                                @if(count($email->to_recipients ?? []) > 1)
                                    <small class="text-muted">+{{ count($email->to_recipients) - 1 }}</small>
                                @endif
                            @endif
                        </td>

                        {{-- Subject + preview --}}
                        <td>
                            <a href="{{ route('emails.show', $email) }}" class="text-decoration-none {{ !$email->is_read ? 'fw-bold' : '' }}" onclick="event.stopPropagation()">
                                {{ Str::limit($email->subject, 80) }}
                            </a>
                            @if($email->body_preview)
                                <small class="d-block text-muted text-truncate" style="max-width: 500px">{{ $email->body_preview }}</small>
                            @endif
                        </td>

                        {{-- Ticket link --}}
                        <td>
                            @if($email->ticket)
                                <span onclick="event.stopPropagation()">
                                    <x-ticket-badge :ticket="$email->ticket" />
                                </span>
                            @elseif($email->direction === App\Enums\EmailDirection::Inbound && !$email->dismissed_at)
                                <span class="text-muted small fst-italic">No ticket</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>

                        {{-- Client --}}
                        <td>
                            <span onclick="event.stopPropagation()">
                                <x-client-badge :client="$email->client" />
                            </span>
                        </td>

                        {{-- Icons --}}
                        <td class="text-end text-nowrap">
                            @if($email->has_attachments)
                                <i class="bi bi-paperclip text-muted" title="Has attachments"></i>
                            @endif
                            @if($email->importance === 'high')
                                <i class="bi bi-exclamation-circle text-danger" title="High importance"></i>
                            @endif
                        </td>

                        {{-- Actions --}}
                        <td onclick="event.stopPropagation()" class="text-nowrap">
                            @if($currentPreset === 'dismissed')
                                <form method="POST" action="{{ route('emails.undismiss', $email) }}" class="d-inline">
                                    @csrf
                                    <button type="submit" class="btn btn-sm btn-outline-secondary p-0 px-1" title="Restore">
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                    </button>
                                </form>
                            @else
                                <a href="{{ route('emails.create-ticket', $email) }}" class="btn btn-sm btn-outline-secondary p-0 px-1" title="Create Ticket">
                                    <i class="bi bi-ticket-perforated"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-secondary p-0 px-1" title="Link to Ticket"
                                        onclick="openLinkModal({{ $email->id }})">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                                @if(!$email->dismissed_at)
                                <button type="button" class="btn btn-sm btn-outline-secondary p-0 px-1" title="Dismiss"
                                        onclick="dismissEmail({{ $email->id }})">
                                    <i class="bi bi-x-lg"></i>
                                </button>
                                @endif
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3 d-flex align-items-center justify-content-between">
        <div>{{ $emails->links() }}</div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="saveDefaults" title="Remember current filters as your default view">
                <i class="bi bi-bookmark me-1"></i>Save as default
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="resetDefaults" title="Clear saved default view">
                <i class="bi bi-bookmark-x me-1"></i>Reset default
            </button>
        </div>
    </div>

    {{-- Bulk Action Bar --}}
    <div class="bulk-action-bar" id="bulkBar">
        <span id="bulkCount" class="fw-semibold me-2">0 selected</span>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('dismiss')">
            <i class="bi bi-x-circle me-1"></i>Dismiss
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" onclick="openBulkModal('link_ticket')">
            <i class="bi bi-link-45deg me-1"></i>Link to Ticket
        </button>
        <a href="#" class="text-light ms-auto small" onclick="deselectAll(); return false;">Deselect All</a>
    </div>

    {{-- Bulk Action Modal --}}
    <div class="modal fade" id="bulkActionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="{{ route('emails.bulk-action') }}" id="bulkForm">
                    @csrf
                    <input type="hidden" name="action" id="bulkActionField">
                    <div id="bulkEmailInputs"></div>
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

    {{-- Link to Ticket Modal (single email) --}}
    <div class="modal fade" id="linkTicketModal" tabindex="-1">
        <div class="modal-dialog modal-sm">
            <div class="modal-content">
                <form method="POST" id="linkTicketForm">
                    @csrf
                    <input type="hidden" name="ticket_id" id="linkTicketId">
                    <div class="modal-header">
                        <h5 class="modal-title">Link to Ticket</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <input type="text" class="form-control form-control-sm" id="linkTicketSearch"
                               placeholder="Search by ticket ID or subject..." autocomplete="off">
                        <div id="linkTicketResults" class="list-group mt-2" style="max-height: 250px; overflow-y: auto;"></div>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif
@endsection

@push('styles')
<style>
.cursor-pointer { cursor: pointer; }
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
// Preference persistence
(function() {
    var STORAGE_KEY = 'emailListDefaults';

    if (!window.location.search) {
        var saved = localStorage.getItem(STORAGE_KEY);
        if (saved) {
            window.location.replace(window.location.pathname + saved);
            return;
        }
    }

    var saveBtn = document.getElementById('saveDefaults');
    if (saveBtn) {
        saveBtn.addEventListener('click', function() {
            localStorage.setItem(STORAGE_KEY, window.location.search || '?preset=needs_attention');
            this.innerHTML = '<i class="bi bi-check me-1"></i>Saved!';
            var self = this;
            setTimeout(function() { self.innerHTML = '<i class="bi bi-bookmark me-1"></i>Save as default'; }, 1500);
            document.getElementById('resetDefaults').classList.remove('d-none');
        });
    }

    var resetBtn = document.getElementById('resetDefaults');
    if (resetBtn) {
        if (localStorage.getItem(STORAGE_KEY)) resetBtn.classList.remove('d-none');
        resetBtn.addEventListener('click', function() {
            localStorage.removeItem(STORAGE_KEY);
            this.classList.add('d-none');
            window.location.href = window.location.pathname;
        });
    }
})();

// Inline dismiss (AJAX)
window.dismissEmail = function(emailId) {
    var token = document.querySelector('meta[name="csrf-token"]').content;
    fetch('/emails/' + emailId + '/dismiss', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }
    }).then(function(r) {
        if (r.ok) {
            var row = document.getElementById('email-row-' + emailId);
            if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity = '0';
                setTimeout(function() { row.remove(); }, 300);
            }
            // Update needs-attention count in nav badge
            var badge = document.querySelector('.navbar .badge.bg-warning');
            if (badge) {
                var count = parseInt(badge.textContent) - 1;
                if (count <= 0) badge.remove();
                else badge.textContent = count;
            }
        }
    });
};

// Link to Ticket modal (single email)
var linkEmailId = null;
window.openLinkModal = function(emailId) {
    linkEmailId = emailId;
    document.getElementById('linkTicketSearch').value = '';
    document.getElementById('linkTicketResults').innerHTML = '';
    document.getElementById('linkTicketForm').action = '/emails/' + emailId + '/link-ticket';
    new bootstrap.Modal(document.getElementById('linkTicketModal')).show();
    setTimeout(function() { document.getElementById('linkTicketSearch').focus(); }, 300);
};

// Ticket search for link modal
(function() {
    var searchInput = document.getElementById('linkTicketSearch');
    var resultsDiv = document.getElementById('linkTicketResults');
    if (!searchInput) return;

    var debounce = null;
    searchInput.addEventListener('input', function() {
        clearTimeout(debounce);
        var q = this.value.trim();
        if (q.length < 2) { resultsDiv.innerHTML = ''; return; }
        debounce = setTimeout(function() {
            fetch('/api/tickets/search?q=' + encodeURIComponent(q))
                .then(function(r) { return r.json(); })
                .then(function(tickets) {
                    resultsDiv.innerHTML = tickets.map(function(t) {
                        return '<button type="button" class="list-group-item list-group-item-action small py-1" onclick="selectLinkTicket(' + t.id + ')">'
                            + '<strong>' + t.display_id + '</strong> '
                            + '<span class="badge ' + t.priority_class + ' me-1">' + t.priority + '</span>'
                            + t.subject
                            + '</button>';
                    }).join('') || '<div class="text-muted small p-2">No tickets found</div>';
                });
        }, 250);
    });
})();

window.selectLinkTicket = function(ticketId) {
    if (linkEmailId) {
        // Single email link — submit form
        document.getElementById('linkTicketId').value = ticketId;
        document.getElementById('linkTicketForm').action = '/emails/' + linkEmailId + '/link-ticket';
        document.getElementById('linkTicketForm').submit();
    } else {
        // Bulk link — set ticket_id in bulk form and submit
        document.getElementById('bulkLinkTicketId').value = ticketId;
        document.getElementById('bulkForm').submit();
    }
};

// Bulk actions
(function() {
    var selectedIds = new Set();
    var bar = document.getElementById('bulkBar');
    var countEl = document.getElementById('bulkCount');
    var selectAllEl = document.getElementById('selectAll');
    var banner = document.getElementById('selectAllBanner');
    var bannerText = document.getElementById('selectAllBannerText');
    if (!bar || !selectAllEl) return;

    var totalFilterCount = {{ $emails->total() }};
    var pageEmailCount = {{ $emails->count() }};

    function updateBar() {
        var displayCount = selectedIds.size;
        countEl.textContent = displayCount + ' selected';
        bar.classList.toggle('active', displayCount > 0);

        var allOnPage = selectedIds.size === pageEmailCount && pageEmailCount > 0;
        selectAllEl.indeterminate = selectedIds.size > 0 && selectedIds.size < pageEmailCount;
        selectAllEl.checked = allOnPage;

        if (banner) {
            if (allOnPage && totalFilterCount > pageEmailCount) {
                banner.style.display = '';
                bannerText.innerHTML = 'All <strong>' + pageEmailCount + '</strong> emails on this page are selected.';
            } else {
                banner.style.display = 'none';
            }
        }
    }

    selectAllEl.addEventListener('change', function() {
        document.querySelectorAll('.email-checkbox').forEach(function(cb) {
            cb.checked = selectAllEl.checked;
            if (selectAllEl.checked) selectedIds.add(cb.value);
            else selectedIds.delete(cb.value);
        });
        updateBar();
    });

    document.addEventListener('change', function(e) {
        if (!e.target.classList.contains('email-checkbox')) return;
        if (e.target.checked) selectedIds.add(e.target.value);
        else selectedIds.delete(e.target.value);
        updateBar();
    });

    window.deselectAll = function() {
        selectedIds.clear();
        document.querySelectorAll('.email-checkbox').forEach(function(cb) { cb.checked = false; });
        selectAllEl.checked = false;
        selectAllEl.indeterminate = false;
        updateBar();
    };

    window.openBulkModal = function(action) {
        var count = selectedIds.size;
        if (count === 0) return;

        document.getElementById('bulkActionField').value = action;

        var container = document.getElementById('bulkEmailInputs');
        container.innerHTML = '';
        selectedIds.forEach(function(id) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = 'email_ids[]'; input.value = id;
            container.appendChild(input);
        });

        var title = document.getElementById('bulkModalTitle');
        var body = document.getElementById('bulkModalBody');
        var btn = document.getElementById('bulkSubmitBtn');
        body.innerHTML = '';

        if (action === 'dismiss') {
            title.textContent = 'Dismiss Emails';
            body.innerHTML = '<p>Dismiss <strong>' + count + '</strong> email(s)? They will be removed from the attention queue.</p>';
            btn.textContent = 'Dismiss ' + count + ' email(s)';
            btn.className = 'btn btn-warning btn-sm';
            btn.type = 'submit';
            new bootstrap.Modal(document.getElementById('bulkActionModal')).show();
        } else if (action === 'link_ticket') {
            title.textContent = 'Link Emails to Ticket';
            body.innerHTML = '<p>Link <strong>' + count + '</strong> email(s) to a ticket:</p>'
                + '<input type="text" class="form-control form-control-sm" id="bulkTicketSearch" placeholder="Search by ticket ID or subject..." autocomplete="off">'
                + '<input type="hidden" name="ticket_id" id="bulkLinkTicketId">'
                + '<div id="bulkTicketResults" class="list-group mt-2" style="max-height: 250px; overflow-y: auto;"></div>';
            btn.textContent = 'Link';
            btn.className = 'btn btn-primary btn-sm';
            btn.type = 'button';
            btn.style.display = 'none';
            linkEmailId = null; // signal bulk mode for selectLinkTicket

            new bootstrap.Modal(document.getElementById('bulkActionModal')).show();

            // Wire up ticket search in bulk modal
            setTimeout(function() {
                var bulkSearch = document.getElementById('bulkTicketSearch');
                var bulkResults = document.getElementById('bulkTicketResults');
                if (!bulkSearch) return;
                bulkSearch.focus();
                var deb = null;
                bulkSearch.addEventListener('input', function() {
                    clearTimeout(deb);
                    var q = this.value.trim();
                    if (q.length < 2) { bulkResults.innerHTML = ''; return; }
                    deb = setTimeout(function() {
                        fetch('/api/tickets/search?q=' + encodeURIComponent(q))
                            .then(function(r) { return r.json(); })
                            .then(function(tickets) {
                                bulkResults.innerHTML = tickets.map(function(t) {
                                    return '<button type="button" class="list-group-item list-group-item-action small py-1" onclick="selectLinkTicket(' + t.id + ')">'
                                        + '<strong>' + t.display_id + '</strong> '
                                        + '<span class="badge ' + t.priority_class + ' me-1">' + t.priority + '</span>'
                                        + t.subject
                                        + '</button>';
                                }).join('') || '<div class="text-muted small p-2">No tickets found</div>';
                            });
                    }, 250);
                });
            }, 300);
        }
    };
})();
</script>
@endpush
