@extends('layouts.app')

@section('title', 'Emails')

{{-- Support mailbox (ROXZP7e5): folder rail, mailbox list, reading pane at desktop
     width. Spec: design/mockups/emails/mailbox-{inbox,reading-pane,bulk,filed}.html. --}}
@php
    $currentPreset = $filters['preset'] ?? '';
    $hasNoClient = !empty($filters['no_client']);
    $folderLabels = ['inbox' => 'Inbox', 'filed' => 'Filed', 'archive' => 'Archive', 'sent' => 'Sent', 'all' => 'All mail', 'inbound' => 'All inbound'];
    $folderTitle = $hasNoClient && $folder === 'inbox' ? 'Inbox: no client' : ($folderLabels[$folder] ?? 'Emails');
    $folderNotes = [
        'inbox' => 'Inbound mail with no ticket that has not been archived.',
        'filed' => 'Inbound mail linked to a ticket. Opening a message offers Open ticket and Unlink; the ticket is where work happens.',
        'archive' => 'Archived mail. Unarchive puts a message back where it was.',
        'sent' => 'Mail sent from the PSA.',
        'all' => 'Every message, inbound and sent. Without a search or date, the last 7 days.',
        'inbound' => 'Every inbound message.',
    ];
    $returnTo = '/' . ltrim(request()->getRequestUri(), '/');
    $activeFilters = [];
    if (!empty($filters['search'])) $activeFilters[] = '"' . $filters['search'] . '"';
    if (isset($filters['is_read']) && $filters['is_read'] !== '') $activeFilters[] = $filters['is_read'] ? 'Read' : 'Unread';
    if (!empty($filters['date_from'])) $activeFilters[] = 'From: ' . $filters['date_from'];
    if (!empty($filters['date_to'])) $activeFilters[] = 'To: ' . $filters['date_to'];
    if (!empty($filters['client_id'])) $activeFilters[] = $clients->firstWhere('id', $filters['client_id'])?->name ?? 'Client';
    $hasAdvancedFilters = count($activeFilters) > 0;
    $windowLabel = 'last 7 days; search or set a date for older';
    $railLink = fn (string $p) => route('emails.index', ['preset' => $p]);
@endphp

@section('content')
<div class="mb-shell" id="mbShell">
    {{-- Folder rail --}}
    <nav class="mb-rail" aria-label="Mail folders">
        <a href="{{ route('emails.compose') }}" class="mb-compose">Compose</a>
        <a href="{{ $railLink('inbox') }}" class="{{ $folder === 'inbox' && !$hasNoClient ? 'on' : '' }}" @if($folder === 'inbox' && !$hasNoClient) aria-current="page" @endif>
            <span>Inbox</span>
            <span class="mb-cnt {{ $folderCounts['inbox'] > 0 ? 'hot' : '' }}" data-folder-count="inbox">{{ number_format($folderCounts['inbox']) }}</span>
        </a>
        <a href="{{ $railLink('filed') }}" class="{{ $folder === 'filed' ? 'on' : '' }}" @if($folder === 'filed') aria-current="page" @endif>
            <span>Filed</span><span class="mb-cnt" data-folder-count="filed">{{ number_format($folderCounts['filed']) }}</span>
        </a>
        <a href="{{ $railLink('archive') }}" class="{{ $folder === 'archive' ? 'on' : '' }}" @if($folder === 'archive') aria-current="page" @endif>
            <span>Archive</span><span class="mb-cnt" data-folder-count="archive">{{ number_format($folderCounts['archive']) }}</span>
        </a>
        <a href="{{ $railLink('sent') }}" class="{{ $folder === 'sent' ? 'on' : '' }}" @if($folder === 'sent') aria-current="page" @endif>
            <span>Sent</span><span class="mb-cnt" data-folder-count="sent">{{ number_format($folderCounts['sent']) }}</span>
        </a>
        <a href="{{ $railLink('all') }}" class="mb-wrap {{ $folder === 'all' ? 'on' : '' }}" @if($folder === 'all') aria-current="page" @endif>
            <span>All mail</span><span class="mb-cnt" data-folder-count="all">{{ number_format($folderCounts['all']) }}</span>
            <span class="mb-cnt-sub">{{ $windowLabel }}</span>
        </a>
        <div class="mb-sep"></div>
        <a href="{{ route('emails.index', ['preset' => 'inbox', 'no_client' => 1]) }}" class="{{ $folder === 'inbox' && $hasNoClient ? 'on' : '' }}" @if($folder === 'inbox' && $hasNoClient) aria-current="page" @endif>
            <span>Inbox: no client</span><span class="mb-cnt" data-folder-count="no_client">{{ number_format($folderCounts['no_client']) }}</span>
        </a>
        <p class="mb-note">Inbox: no client = Inbox messages with no client set.</p>
    </nav>

    {{-- Mailbox list --}}
    <section class="mb-list" aria-label="{{ $folderTitle }}">
        <form method="GET" action="{{ route('emails.index') }}" class="mb-head" role="search">
            <span class="mb-grow">{{ $folderTitle }}</span>
            @if($currentPreset)
                <input type="hidden" name="preset" value="{{ $currentPreset }}">
            @endif
            @if($hasNoClient)
                <input type="hidden" name="no_client" value="1">
            @endif
            <input type="search" name="search" placeholder="Search mail" aria-label="Search mail" value="{{ $filters['search'] ?? '' }}">
            <button type="button" class="mb-filter-btn" data-bs-toggle="collapse" data-bs-target="#filterCard" aria-label="Filters">
                <i class="bi bi-funnel"></i>@if($hasAdvancedFilters)<span class="ms-1">({{ implode(', ', $activeFilters) }})</span>@endif
            </button>
        </form>

        {{-- Bulk bar: replaces the list header bar while messages are selected --}}
        <div class="mb-bar mb-bulk d-none" id="bulkBar">
            <strong id="bulkCount">0 selected</strong>
            <button type="button" onclick="submitBulk('dismiss')">Archive</button>
            <button type="button" onclick="submitBulk('mark_read')">Mark read</button>
            <button type="button" onclick="submitBulk('mark_unread')">Mark unread</button>
            <button type="button" onclick="openBulkModal('link_ticket')">Add to ticket...</button>
            <button type="button" onclick="deselectAll()">Clear</button>
        </div>
        <div class="mb-bar" id="listBar">
            <input type="checkbox" class="form-check-input" id="selectAll" aria-label="Select all">
            <span class="mb-cnt">
                @if($defaultWindow)
                    {{ $windowLabel }}
                @else
                    {{ $folderNotes[$folder] ?? '' }}
                @endif
            </span>
        </div>
        <div class="text-center py-2 bg-light border-bottom small" id="selectAllBanner" style="display:none;">
            <span id="selectAllBannerText"></span>
        </div>

        {{-- Advanced filters (dates, read state, client) --}}
        <div class="collapse {{ $hasAdvancedFilters ? 'show' : '' }}" id="filterCard">
            <form method="GET" action="{{ route('emails.index') }}" class="mb-filters">
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
                    <div class="col-lg-3 col-md-3">
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
                    <div class="col-lg-2 col-md-2 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search"></i></button>
                        <a href="{{ route('emails.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg"></i></a>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="saveDefaults" title="Remember current filters as your default view">
                            <i class="bi bi-bookmark"></i>
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="resetDefaults" title="Clear saved default view">
                            <i class="bi bi-bookmark-x"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        @if($emails->isEmpty())
            <div class="text-center py-5 text-muted">
                <i class="bi bi-envelope-check" style="font-size: 2.5rem;"></i>
                <p class="mt-3 mb-0">
                    @if($folder === 'inbox' && !$hasAdvancedFilters && !$hasNoClient)
                        All caught up! Nothing in the Inbox.
                    @else
                        No emails found for the selected filters.
                    @endif
                </p>
            </div>
        @else
            <div id="mbRows">
            @foreach($emails as $email)
                @php
                    $isInbound = $email->direction === App\Enums\EmailDirection::Inbound;
                    $who = $isInbound ? $email->senderDisplay() : 'To: ' . $email->primaryRecipientDisplay();
                    $at = $email->received_at?->toAppTz();
                    $when = !$at ? '—' : ($at->isToday() ? $at->format('H:i') : ($at->isYesterday() ? 'Yesterday' : ($at->gt(now()->subDays(7)) ? $at->format('D') : $at->format('d M'))));
                @endphp
                <div class="mb-row {{ !$email->is_read ? 'unread' : '' }}" id="email-row-{{ $email->id }}"
                     data-email-id="{{ $email->id }}" data-pane-url="{{ route('emails.show', ['email' => $email, 'pane' => 1, 'return_to' => $returnTo]) }}">
                    <input type="checkbox" class="form-check-input email-checkbox" value="{{ $email->id }}" aria-label="Select">
                    <div class="mb-from">
                        <a href="{{ route('emails.show', $email) }}" class="mb-open"
                           title="{{ $isInbound ? 'From ' . $email->from_address . ' to ' . $email->primaryRecipientDisplay() : 'To ' . ($email->primaryRecipientAddress() ?? $email->primaryRecipientDisplay()) }}">{{ $who }}</a>
                        <div class="mb-chips">
                            @if($email->client)
                                <span class="mb-chip">{{ $email->client->name }}</span>
                            @elseif($isInbound)
                                <span class="mb-chip none">No client</span>
                            @endif
                        </div>
                    </div>
                    <div class="mb-text">
                        <span class="mb-subj">{{ Str::limit($email->subject, 100) }}</span>
                        @if($email->body_preview)
                            <span class="mb-snip"><span class="mb-dash">- </span>{{ Str::limit($email->body_preview, 140) }}</span>
                        @endif
                        @if($email->ticket)
                            <div class="mb-chips"><span class="mb-chip tkt"><x-ticket-badge :ticket="$email->ticket" /></span></div>
                        @endif
                    </div>
                    <div class="mb-when" title="{{ $at?->format('d M Y H:i T') }}">
                        {{ $when }}
                        @if($email->has_attachments)
                            <i class="bi bi-paperclip" title="Has attachments"></i>
                        @endif
                    </div>
                </div>
            @endforeach
            </div>
        @endif
        <div class="mb-foot">
            {{ number_format($emails->total()) }} {{ $emails->total() === 1 ? 'message' : 'messages' }}.@if($folder === 'inbox') Archive and Create ticket remove a message from Inbox; it then appears in Archive or Filed.@endif
            @if($emails->hasPages())
                <div class="mt-2">{{ $emails->links() }}</div>
            @endif
        </div>
    </section>

    {{-- Reading pane (desktop width only; below it rows open the full show page) --}}
    <section class="mb-reader" id="mbReader" aria-label="Reading pane" hidden></section>
</div>

{{-- Bulk Add to ticket (existing bulk link_ticket action) --}}
<div class="modal fade" id="bulkActionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="{{ route('emails.bulk-action') }}" id="bulkForm">
                @csrf
                <input type="hidden" name="action" id="bulkActionField">
                <input type="hidden" name="return_to" value="{{ $returnTo }}">
                <input type="hidden" name="ticket_id" id="bulkLinkTicketId">
                <div id="bulkEmailInputs"></div>
                <div class="modal-header">
                    <h5 class="modal-title" id="bulkModalTitle">Add to ticket</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p id="bulkModalBody" class="mb-2"></p>
                    <input type="text" class="form-control form-control-sm" id="bulkTicketSearch"
                           placeholder="Find an open ticket" aria-label="Find a ticket" autocomplete="off">
                    <div id="bulkTicketResults" class="list-group mt-2" style="max-height: 250px; overflow-y: auto;"></div>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('styles')
<style>
/* Mailbox (ROXZP7e5) - tokens per DESIGN.md; mockup design/mockups/emails/_mb.css */
.mb-shell { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: 16px; align-items: start; }
.mb-shell.has-pane { grid-template-columns: 210px minmax(0, 340px) minmax(0, 1fr); }
.mb-rail, .mb-list, .mb-reader { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden; }
.mb-rail a { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; padding: 8px 16px; color: var(--text-body, #374151); text-decoration: none; border-left: 3px solid transparent; }
.mb-rail a:hover { background: #f8fafc; }
.mb-rail a:focus-visible { outline: 2px solid var(--primary, #1a365d); outline-offset: -2px; }
.mb-rail a.on { background: #f8fafc; border-left-color: var(--primary, #1a365d); color: var(--primary, #1a365d); font-weight: 600; }
.mb-rail .mb-compose { display: block; margin: 16px; text-align: center; color: var(--primary, #1a365d); border: 1px solid var(--primary, #1a365d); border-radius: 8px; padding: 4px; font-weight: 600; }
.mb-rail .mb-compose:hover { background: var(--primary, #1a365d); color: #fff; }
.mb-cnt { font-size: 12px; color: var(--text-muted, #6b7280); }
.mb-cnt.hot { background: #ffc107; color: var(--primary-dark, #0f2440); border-radius: 50rem; padding: 0 8px; font-weight: 600; }
.mb-cnt-sub { flex-basis: 100%; font-size: 11px; color: var(--text-muted, #6b7280); font-weight: 400; }
.mb-sep { border-top: 1px solid #e5e7eb; margin: 4px 0; }
.mb-note { padding: 8px 16px; font-size: 12px; color: var(--text-muted, #6b7280); margin: 0; }
.mb-head { display: flex; align-items: center; gap: 8px; padding: 8px 16px; margin: 0; background: var(--primary, #1a365d); color: #fff; font-family: Montserrat, sans-serif; font-weight: 600; }
.mb-head .mb-grow { flex: 1; }
.mb-head input[type=search] { width: 260px; max-width: 45%; border: 1px solid #d1d5db; border-radius: 8px; padding: 3px 8px; font: inherit; font-family: Inter, sans-serif; font-weight: 400; }
.mb-head input[type=search]:focus { outline: none; border-color: var(--primary-light, #234179); box-shadow: 0 0 0 3px rgba(26,54,93,0.12); }
.mb-filter-btn { background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.6); border-radius: 8px; padding: 2px 8px; font-size: 13px; font-weight: 400; }
.mb-bar { display: flex; gap: 8px; align-items: center; padding: 8px 16px; border-bottom: 1px solid #e5e7eb; flex-wrap: wrap; }
.mb-bar.mb-bulk { background: var(--primary-dark, #0f2440); color: #fff; }
.mb-bulk button { background: transparent; color: #fff; border: 1px solid #fff; border-radius: 8px; padding: 3px 16px; font-size: 13px; }
.mb-bulk button:hover, .mb-bulk button:focus-visible { background: #fff; color: var(--primary-dark, #0f2440); }
.mb-filters { padding: 8px 16px; border-bottom: 1px solid #e5e7eb; }
.mb-row { display: grid; grid-template-columns: 22px 180px minmax(0, 1fr) 80px; gap: 8px; padding: 8px 16px; border-top: 1px solid #e5e7eb; align-items: start; cursor: pointer; }
.mb-row:hover { background: #f8fafc; }
.mb-row.sel { background: #eef3fb; }
.mb-row.cur { background: #f8fafc; box-shadow: inset 3px 0 0 var(--primary, #1a365d); }
.mb-row .mb-open { display: block; color: inherit; text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.mb-row.unread .mb-from, .mb-row.unread .mb-subj { font-weight: 700; color: var(--primary-dark, #0f2440); }
.mb-snip { color: var(--text-muted, #6b7280); }
.mb-when { text-align: right; color: var(--text-muted, #6b7280); font-size: 12px; white-space: nowrap; }
.mb-chips { margin-top: 2px; display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
.mb-chip { font-size: 11px; border-radius: 50rem; padding: 0 8px; border: 1px solid #e5e7eb; color: var(--primary, #1a365d); background: #fff; }
.mb-chip.none { color: #dc3545; border-color: #dc3545; }
.mb-chip.tkt { background: var(--primary, #1a365d); border-color: var(--primary, #1a365d); }
.mb-chip.tkt a, .mb-chip.tkt span { color: #fff; }
.mb-foot { padding: 8px 16px; font-size: 12px; color: var(--text-muted, #6b7280); border-top: 1px solid #e5e7eb; }
.mb-shell.has-pane .mb-row { grid-template-columns: 22px minmax(0, 1fr) 70px; }
.mb-shell.has-pane .mb-row .mb-from { grid-column: 2; }
.mb-shell.has-pane .mb-row .mb-text { grid-column: 2; grid-row: 2; }
.mb-shell.has-pane .mb-row .mb-when { grid-column: 3; grid-row: 1; }
.mb-shell.has-pane .mb-row .mb-snip { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.mb-shell.has-pane .mb-row .mb-dash { display: none; }
.mb-toolbar { display: flex; gap: 8px; flex-wrap: wrap; padding: 8px 16px; border-bottom: 1px solid #e5e7eb; }
.mb-meta { padding: 16px; border-bottom: 1px solid #e5e7eb; }
.mb-meta h2 { font-family: Montserrat, sans-serif; font-size: 1.1rem; color: var(--primary, #1a365d); margin: 0 0 4px; }
.mb-meta .mb-when { text-align: left; }
.mb-picker { margin: 0 16px 16px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
.mb-picker .form-control { border: 0; border-bottom: 1px solid #e5e7eb; border-radius: 0; }
.mb-body { padding: 16px; }
.mb-pane-foot { padding: 0 16px 16px; }
@media (max-width: 991.98px) {
    .mb-shell, .mb-shell.has-pane { grid-template-columns: 1fr; }
    .mb-reader { display: none !important; }
    .mb-row { grid-template-columns: 22px minmax(0, 1fr) 70px; }
    .mb-row .mb-text { grid-column: 2; grid-row: 2; }
    .mb-row .mb-when { grid-column: 3; grid-row: 1; }
}
</style>
@endpush

@push('scripts')
<script>
// HTML-escape values interpolated into innerHTML strings below (psa-ed1an). The
// textContent -> innerHTML idiom matches public/js/command-palette.js and
// public/js/softphone.js; used by the link-ticket typeahead render sites.
function escapeHtml(str) {
    var div = document.createElement('div');
    div.textContent = str == null ? '' : String(str);
    return div.innerHTML;
}

// Preference persistence (saved default view; old ?preset= values still resolve)
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
            localStorage.setItem(STORAGE_KEY, window.location.search || '?preset=inbox');
            this.innerHTML = '<i class="bi bi-check"></i>';
            var self = this;
            setTimeout(function() { self.innerHTML = '<i class="bi bi-bookmark"></i>'; }, 1500);
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

// ITIL taxonomy chip for ticket typeahead rows (psa-717bn): leaf in the row,
// full path on hover; empty string when the ticket has no category.
function ticketCategoryChip(t) {
    return t.category_path
        ? '<span class="badge bg-light text-dark border fw-normal me-1" title="' + escapeHtml(t.category_path).replace(/"/g, '&quot;') + '">'
            + escapeHtml(t.category_leaf || String(t.category_path).split(' / ').pop()) + '</span>'
        : '';
}

// "Add to ticket" picker search: open tickets only, the email's client first.
function searchOpenTickets(q, clientId) {
    var url = '/api/tickets/search?open=1&q=' + encodeURIComponent(q || '');
    if (clientId) url += '&client_first=' + encodeURIComponent(clientId);
    return fetch(url, { headers: { 'Accept': 'application/json' } }).then(function(r) { return r.json(); });
}

var DESKTOP = window.matchMedia('(min-width: 992px)');

// Reading pane: at desktop width a row opens in the pane; below it, the full show page.
(function() {
    var shell = document.getElementById('mbShell');
    var reader = document.getElementById('mbReader');
    var rows = document.getElementById('mbRows');
    if (!rows || !reader) return;

    function openRow(row) {
        if (!DESKTOP.matches) {
            window.location = row.querySelector('.mb-open').href;
            return;
        }
        fetch(row.dataset.paneUrl, { headers: { 'Accept': 'text/html' } })
            .then(function(r) { if (!r.ok) throw new Error(r.status); return r.text(); })
            .then(function(html) {
                reader.innerHTML = html;
                reader.hidden = false;
                shell.classList.add('has-pane');
                rows.querySelectorAll('.mb-row.cur').forEach(function(el) { el.classList.remove('cur'); });
                row.classList.add('cur');
                row.classList.remove('unread'); // opening marks it read, as the show page does
            })
            .catch(function() { window.location = row.querySelector('.mb-open').href; });
    }

    rows.addEventListener('click', function(e) {
        if (e.target.closest('.email-checkbox') || e.target.closest('.mb-chip.tkt a')) return;
        var row = e.target.closest('.mb-row');
        if (!row) return;
        if (e.target.closest('.mb-open') && (e.metaKey || e.ctrlKey || e.shiftKey)) return;
        e.preventDefault();
        openRow(row);
    });
})();

// Pane picker (single email -> existing link-ticket action)
(function() {
    var reader = document.getElementById('mbReader');
    if (!reader) return;
    var deb = null;

    function render(form, tickets) {
        var results = form.querySelector('[data-mb-picker-results]');
        results.innerHTML = tickets.map(function(t) {
            return '<button type="button" class="list-group-item list-group-item-action small py-1" data-ticket-id="' + Number(t.id) + '">'
                + '<strong>' + escapeHtml(t.display_id) + '</strong> '
                + '<span class="badge ' + escapeHtml(t.priority_class) + ' me-1">' + escapeHtml(t.priority) + '</span>'
                + ticketCategoryChip(t)
                + escapeHtml(t.subject)
                + (t.client_name ? ' <span class="text-muted">(' + escapeHtml(t.client_name) + ')</span>' : '')
                + ' <span class="text-muted">- ' + escapeHtml(t.status) + '</span>'
                + '</button>';
        }).join('') || '<div class="text-muted small p-2">No open tickets found</div>';
    }

    function load(form) {
        var q = form.querySelector('[data-mb-picker-q]').value.trim();
        var clientId = reader.querySelector('.mb-reader-inner').dataset.clientId;
        searchOpenTickets(q, clientId).then(function(tickets) { render(form, tickets); });
    }

    reader.addEventListener('click', function(e) {
        if (e.target.closest('[data-mb-picker-toggle]')) {
            var form = reader.querySelector('[data-mb-picker]');
            if (!form) return;
            form.classList.toggle('d-none');
            if (!form.classList.contains('d-none')) {
                form.querySelector('[data-mb-picker-q]').focus();
                load(form);
            }
            return;
        }
        var pick = e.target.closest('[data-ticket-id]');
        if (pick) {
            var f = pick.closest('[data-mb-picker]');
            f.querySelector('input[name=ticket_id]').value = pick.dataset.ticketId;
            f.submit();
        }
    });

    reader.addEventListener('input', function(e) {
        if (!e.target.matches('[data-mb-picker-q]')) return;
        var form = e.target.closest('[data-mb-picker]');
        clearTimeout(deb);
        deb = setTimeout(function() { load(form); }, 250);
    });
    reader.addEventListener('keydown', function(e) {
        if (e.target.matches('[data-mb-picker-q]') && e.key === 'Enter') e.preventDefault();
    });
})();

// Bulk actions: the bulk bar replaces the list header bar while messages are selected.
(function() {
    var selectedIds = new Set();
    var bar = document.getElementById('bulkBar');
    var listBar = document.getElementById('listBar');
    var countEl = document.getElementById('bulkCount');
    var selectAllEl = document.getElementById('selectAll');
    var banner = document.getElementById('selectAllBanner');
    var bannerText = document.getElementById('selectAllBannerText');
    if (!bar || !selectAllEl) return;

    var totalFilterCount = {{ $emails->total() }};
    var pageEmailCount = {{ $emails->count() }};

    function updateBar() {
        var n = selectedIds.size;
        countEl.textContent = n + ' selected';
        bar.classList.toggle('d-none', n === 0);
        listBar.classList.toggle('d-none', n > 0);
        document.querySelectorAll('.email-checkbox').forEach(function(cb) {
            cb.closest('.mb-row').classList.toggle('sel', cb.checked);
        });

        var allOnPage = n === pageEmailCount && pageEmailCount > 0;
        selectAllEl.indeterminate = n > 0 && n < pageEmailCount;
        selectAllEl.checked = allOnPage;

        if (banner) {
            if (allOnPage && totalFilterCount > pageEmailCount) {
                banner.style.display = '';
                bannerText.textContent = 'All ' + pageEmailCount + ' emails on this page are selected.';
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

    function fillIds(action) {
        document.getElementById('bulkActionField').value = action;
        var container = document.getElementById('bulkEmailInputs');
        container.innerHTML = '';
        selectedIds.forEach(function(id) {
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = 'email_ids[]'; input.value = id;
            container.appendChild(input);
        });
    }

    // Archive (existing bulk dismiss), Mark read, Mark unread: submit straight away.
    window.submitBulk = function(action) {
        if (selectedIds.size === 0) return;
        fillIds(action);
        document.getElementById('bulkForm').submit();
    };

    // Add to ticket... (existing bulk link_ticket): open-ticket picker in a modal.
    window.openBulkModal = function(action) {
        var count = selectedIds.size;
        if (count === 0) return;
        fillIds(action);
        document.getElementById('bulkModalBody').textContent = 'Add ' + count + ' email(s) to an open ticket:';
        var bulkSearch = document.getElementById('bulkTicketSearch');
        var bulkResults = document.getElementById('bulkTicketResults');
        bulkSearch.value = '';
        bulkResults.innerHTML = '';
        new bootstrap.Modal(document.getElementById('bulkActionModal')).show();
        setTimeout(function() { bulkSearch.focus(); }, 300);
    };

    var bulkSearch = document.getElementById('bulkTicketSearch');
    var bulkResults = document.getElementById('bulkTicketResults');
    var deb = null;
    bulkSearch.addEventListener('input', function() {
        clearTimeout(deb);
        var q = this.value.trim();
        if (q.length < 2) { bulkResults.innerHTML = ''; return; }
        deb = setTimeout(function() {
            searchOpenTickets(q, null).then(function(tickets) {
                bulkResults.innerHTML = tickets.map(function(t) {
                    return '<button type="button" class="list-group-item list-group-item-action small py-1" data-ticket-id="' + Number(t.id) + '">'
                        + '<strong>' + escapeHtml(t.display_id) + '</strong> '
                        + '<span class="badge ' + escapeHtml(t.priority_class) + ' me-1">' + escapeHtml(t.priority) + '</span>'
                        + ticketCategoryChip(t)
                        + escapeHtml(t.subject)
                        + (t.client_name ? ' <span class="text-muted">(' + escapeHtml(t.client_name) + ')</span>' : '')
                        + '</button>';
                }).join('') || '<div class="text-muted small p-2">No open tickets found</div>';
            });
        }, 250);
    });
    bulkSearch.addEventListener('keydown', function(e) { if (e.key === 'Enter') e.preventDefault(); });
    bulkResults.addEventListener('click', function(e) {
        var pick = e.target.closest('[data-ticket-id]');
        if (!pick) return;
        document.getElementById('bulkLinkTicketId').value = pick.dataset.ticketId;
        document.getElementById('bulkForm').submit();
    });
})();
</script>
@endpush
