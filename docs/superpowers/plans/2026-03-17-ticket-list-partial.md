# Reusable Ticket List Partial Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract the ticket index content into a reusable Blade partial so the same full-featured ticket list (sorting, filtering, pagination, bulk actions) can be embedded on client detail pages (and later: people, assets, contracts) with a locked pre-filter.

**Architecture:** The ticket index view (`tickets/index.blade.php`) becomes a thin wrapper that `@include`s a new `tickets/_list.blade.php` partial. The partial accepts a `$listRoute` (where sort/filter URLs point), a `$prefilter` (locked filters hidden from UI), and all the existing data. Entity detail pages get a dedicated `tickets()` controller method + route that renders the detail page with the ticket list partial in the active tab. The `ticketSortUrl`/`ticketSortIcon` helpers move to a PHP helper file so they're available everywhere.

**Tech Stack:** Laravel 12, PHP 8.3, Blade, Bootstrap 5.3

**Bead:** psa-8vp

---

## File Structure

| File | Action | Purpose |
|------|--------|---------|
| `resources/views/tickets/_list.blade.php` | Create | Reusable ticket list partial (filters, table, bulk actions, styles, JS) |
| `resources/views/tickets/index.blade.php` | Modify | Thin wrapper: layout + page title + `@include('tickets._list')` |
| `app/Helpers/ticket_helpers.php` | Create | `ticketSortUrl()` and `ticketSortIcon()` functions |
| `composer.json` | Modify | Autoload the helpers file |
| `app/Http/Controllers/Web/ClientController.php` | Modify | Add `tickets()` method |
| `resources/views/clients/show.blade.php` | Modify | Replace simplified tickets tab with full list when `$activeTab === 'tickets'` |
| `routes/web.php` | Modify | Add `/clients/{client}/tickets` route |

---

## Chunk 1: Extract Partial and Helpers

### Task 1: Create ticket sort helper functions

**Files:**
- Create: `app/Helpers/ticket_helpers.php`
- Modify: `composer.json`

These two functions are currently defined inline in `tickets/index.blade.php` (lines 201-213) inside a `@php` block. They need to be globally available so both the ticket index and entity detail pages can use them.

- [ ] **Step 1: Create the helpers file**

Create `app/Helpers/ticket_helpers.php`:

```php
<?php

if (!function_exists('ticketSortUrl')) {
    function ticketSortUrl(string $col, string $currentSort, string $currentDir, array $defaultDirs): string
    {
        if ($currentSort === $col) {
            $dir = $currentDir === 'asc' ? 'desc' : 'asc';
        } else {
            $dir = $defaultDirs[$col] ?? 'asc';
        }
        return request()->fullUrlWithQuery(['sort' => $col, 'direction' => $dir, 'page' => null]);
    }
}

if (!function_exists('ticketSortIcon')) {
    function ticketSortIcon(string $col, string $currentSort, string $currentDir): string
    {
        if ($currentSort !== $col) return 'bi-chevron-expand';
        return $currentDir === 'asc' ? 'bi-chevron-up' : 'bi-chevron-down';
    }
}
```

- [ ] **Step 2: Register in composer.json autoload**

In `composer.json`, find the `"autoload"` section and add a `"files"` entry:

```json
"autoload": {
    "psr-4": { ... },
    "files": [
        "app/Helpers/ticket_helpers.php"
    ]
}
```

- [ ] **Step 3: Dump autoloader**

Run: `composer dump-autoload`

- [ ] **Step 4: Commit**

```bash
git add app/Helpers/ticket_helpers.php composer.json
git commit -m "Extract ticketSortUrl and ticketSortIcon into global helper functions"
```

---

### Task 2: Extract ticket list partial from index view

**Files:**
- Create: `resources/views/tickets/_list.blade.php`
- Modify: `resources/views/tickets/index.blade.php`

The partial will contain everything from the ticket index except the layout wrapper (`@extends`, `@section`), the page title heading, and the "New Ticket" button. It receives all the same variables plus `$listRoute` (the route name for generating filter/sort URLs) and `$prefilter` (array of locked filters to hide from UI).

- [ ] **Step 1: Create the partial**

Create `resources/views/tickets/_list.blade.php` containing:

1. **The assignee toggle + quick filter pills** (current index lines 18-86), but replace hardcoded `route('tickets.index', ...)` calls with `route($listRoute, array_merge($prefilter ?? [], ...))`. When `$prefilter` contains `client_id`, hide the client filter dropdown and the client column in the active-filters display.

2. **The filter card** (current index lines 88-182), with:
   - The `<form>` action uses `route($listRoute, $prefilter ?? [])`
   - Hidden inputs for each prefilter key/value
   - The client dropdown is wrapped in `@unless(isset($prefilter['client_id']))` ... `@endunless`

3. **The table, pagination, bulk actions, styles, and JS** (current index lines 184-667), unchanged except:
   - Remove the inline `ticketSortUrl()` and `ticketSortIcon()` function definitions (lines 201-213) — now global helpers
   - The sort URL helper already uses `request()->fullUrlWithQuery()` which works on any route
   - The filter defaults localStorage key includes the `$listRoute` to avoid cross-contamination: `'ticketListDefaults_' + listRoute`

The partial expects these variables (same as the current index view):
- `$tickets` — LengthAwarePaginator
- `$filters` — array of current filter values
- `$clients` — collection (for client filter dropdown)
- `$users` — collection (for reassign modal)
- `$statuses`, `$priorities`, `$types`, `$sources` — enum cases
- `$unassignedCount` — integer
- `$listRoute` — string route name (e.g., `'tickets.index'` or `'clients.tickets'`)
- `$prefilter` — array (e.g., `['client_id' => 5]`), default `[]`

- [ ] **Step 2: Replace index.blade.php content**

Replace `resources/views/tickets/index.blade.php` with a thin wrapper:

```blade
@extends('layouts.app')

@section('title', 'Tickets - PSA PSA')

@section('content')
<div class="row mb-3">
    <div class="col d-flex align-items-center justify-content-between">
        <h4 class="section-title mb-0">
            Tickets
            <span class="text-muted fw-normal" style="font-size: 0.85rem;">({{ $tickets->total() }})</span>
        </h4>
        <a href="{{ route('tickets.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Ticket
        </a>
    </div>
</div>

@include('tickets._list', ['listRoute' => 'tickets.index', 'prefilter' => []])
@endsection
```

- [ ] **Step 3: Verify ticket index still works**

Run dev server and verify `/tickets` renders identically to before — filters, sorting, pagination, bulk actions all function.

Run: `php artisan view:cache` to confirm Blade compilation.

- [ ] **Step 4: Commit**

```bash
git add resources/views/tickets/_list.blade.php resources/views/tickets/index.blade.php
git commit -m "Extract ticket list into reusable _list partial"
```

---

## Chunk 2: Wire Up Client Detail Page

### Task 3: Add `tickets()` method to ClientController

**Files:**
- Modify: `app/Http/Controllers/Web/ClientController.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Add the route**

In `routes/web.php`, after the existing `Route::get('/clients/{client}/activity', ...)` line (around line 110), add:

```php
Route::get('/clients/{client}/tickets', [ClientController::class, 'tickets'])->name('clients.tickets');
```

- [ ] **Step 2: Add the `tickets()` method to ClientController**

Add these imports at the top of `ClientController.php` if not already present:

```php
use App\Enums\TicketPriority;
use App\Enums\TicketSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\User;
use App\Services\TicketService;
```

Add this method to the controller:

```php
public function tickets(Request $request, Client $client)
{
    $filters = [
        'status' => $request->query('status'),
        'priority' => $request->query('priority'),
        'type' => $request->query('type'),
        'source' => $request->query('source'),
        'client_id' => (string) $client->id,
        'assignee_id' => $request->query('assignee_id', 'all'),
        'search' => $request->query('search'),
        'show_closed' => $request->boolean('show_closed'),
        'overdue' => $request->boolean('overdue'),
        'sort' => $request->query('sort', 'priority'),
        'direction' => $request->query('direction', 'asc'),
    ];

    $ticketService = app(TicketService::class);
    $tickets = $ticketService->getTicketList($filters);
    $unassignedCount = \App\Models\Ticket::open()->where('client_id', $client->id)->whereNull('assignee_id')->count();

    // Load the same client detail data as show()
    $client->load([
        'people' => fn ($q) => $q->active()
            ->orderByDesc('is_primary')
            ->orderBy('last_name')
            ->orderBy('first_name'),
        'siteNotesUpdatedBy',
        'credentialsUpdatedBy',
        'primaryTech',
        'reseller',
        'resellerChildren',
    ]);
    $client->loadCount('assets');

    $integrations = $this->integrationService->buildIntegrationsData($client);

    return view('clients.show', [
        'client' => $client,
        'assets' => collect(),
        'integrations' => $integrations,
        'activeTab' => 'tickets',
        'tickets' => $tickets,
        'ticketFilters' => $filters,
        'ticketUsers' => User::active()->orderBy('name')->get(['id', 'name']),
        'ticketClients' => Client::active()->orderBy('name')->get(['id', 'name']),
        'ticketStatuses' => TicketStatus::cases(),
        'ticketPriorities' => TicketPriority::cases(),
        'ticketTypes' => TicketType::cases(),
        'ticketSources' => TicketSource::cases(),
        'unassignedCount' => $unassignedCount,
    ]);
}
```

Note: `assignee_id` defaults to `'all'` (not the current user) since this is a client context — you want to see all of the client's tickets.

- [ ] **Step 3: Commit**

```bash
git add routes/web.php app/Http/Controllers/Web/ClientController.php
git commit -m "Add /clients/{client}/tickets route and controller method"
```

---

### Task 4: Update client detail view for embedded ticket list

**Files:**
- Modify: `resources/views/clients/show.blade.php`

- [ ] **Step 1: Update the Tickets tab nav to be a link when not active**

Find the Tickets tab `<li>` in the nav tabs (around line 83-87). Replace it so it either acts as a JS tab (showing the summary) or links to the full tickets route:

Replace the tickets tab button:

```blade
<li class="nav-item" role="presentation">
    @if(($activeTab ?? '') === 'tickets')
        <button class="nav-link active" type="button">
            Tickets @if(isset($tickets))<span class="text-muted">({{ $tickets->total() }})</span>@endif
        </button>
    @else
        <a class="nav-link" href="{{ route('clients.tickets', $client) }}">
            Tickets @if($openTickets->isNotEmpty())<span class="text-muted">({{ $openTickets->count() }})</span>@endif
        </a>
    @endif
</li>
```

- [ ] **Step 2: Update the tickets tab pane content**

Find the tickets tab pane `<div class="tab-pane fade" id="tickets">` (around line 289). Replace it:

```blade
{{-- Tickets Tab --}}
<div class="tab-pane fade {{ ($activeTab ?? '') === 'tickets' ? 'show active' : '' }}" id="tickets" role="tabpanel">
    @if(($activeTab ?? '') === 'tickets')
        @include('tickets._list', [
            'listRoute' => 'clients.tickets',
            'prefilter' => ['client' => $client->id],
            'filters' => $ticketFilters,
            'clients' => $ticketClients,
            'users' => $ticketUsers,
            'statuses' => $ticketStatuses,
            'priorities' => $ticketPriorities,
            'types' => $ticketTypes,
            'sources' => $ticketSources,
        ])
    @else
        {{-- Summary view (shown when navigating to /clients/{client} directly) --}}
        <div class="card shadow-sm card-static">
            <div class="card-header d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-ticket-perforated me-2"></i>Recent Tickets
                    @if($openTickets->isNotEmpty())
                        <span class="badge bg-light text-dark ms-1">{{ $openTickets->count() }} open</span>
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
```

- [ ] **Step 3: Guard the `@php` block that loads ticket summary data**

The `@php` block (around line 64-69) that queries `$openTickets`, `$closedTickets`, `$allRecent` should be wrapped so it only runs when NOT in tickets tab mode (to avoid loading unnecessary data):

```blade
@php
    $activeContracts = $client->contracts()->active()->withCount('profiles')->get();
    $clientLicenses = $client->licenses()->with('licenseType')->where('status', 'active')->get();
    @if(($activeTab ?? '') !== 'tickets')
        $openTickets = $client->tickets()->open()->orderByDesc('updated_at')->limit(5)->get();
        $closedTickets = $client->tickets()->closed()->orderByDesc('updated_at')->limit(3)->get();
        $allRecent = $openTickets->merge($closedTickets);
    @else
        $openTickets = collect();
        $closedTickets = collect();
        $allRecent = collect();
    @endif
@endphp
```

Wait — mixing `@php`/`@endphp` with `@if` inside the same block is invalid Blade syntax. Use pure PHP instead:

```blade
@php
    $activeContracts = $client->contracts()->active()->withCount('profiles')->get();
    $clientLicenses = $client->licenses()->with('licenseType')->where('status', 'active')->get();
    if (($activeTab ?? '') !== 'tickets') {
        $openTickets = $client->tickets()->open()->orderByDesc('updated_at')->limit(5)->get();
        $closedTickets = $client->tickets()->closed()->orderByDesc('updated_at')->limit(3)->get();
        $allRecent = $openTickets->merge($closedTickets);
    } else {
        $openTickets = collect();
        $closedTickets = collect();
        $allRecent = collect();
    }
@endphp
```

- [ ] **Step 4: Make other tabs into links when tickets tab is active**

When `$activeTab === 'tickets'`, the Overview and other tabs should link back to the client detail page (so clicking them navigates to `/clients/{client}` with the default tab). Update the Overview tab button:

```blade
<li class="nav-item" role="presentation">
    @if(($activeTab ?? '') === 'tickets')
        <a class="nav-link" href="{{ route('clients.show', $client) }}">Overview</a>
    @else
        <button class="nav-link active" id="overview-tab" data-bs-toggle="tab" data-bs-target="#overview" type="button" role="tab">Overview</button>
    @endif
</li>
```

Apply the same pattern to Activity, Assets, Notes & Creds, Licenses, and Integrations tabs — they become `<a>` tags pointing to `route('clients.show', $client)` when `$activeTab === 'tickets'`.

- [ ] **Step 5: Commit**

```bash
git add resources/views/clients/show.blade.php
git commit -m "Embed full ticket list partial on client detail tickets tab"
```

---

## Chunk 3: Partial Refinements and Verification

### Task 5: Build the `_list.blade.php` partial with prefilter support

This is the core extraction task. The partial is created in Task 2, but this task handles the prefilter-aware modifications that make it work in both contexts.

**Files:**
- Modify: `resources/views/tickets/_list.blade.php`

- [ ] **Step 1: Route-aware URL generation**

In the partial, all `route('tickets.index', ...)` calls must be replaced with `route($listRoute, array_merge($prefilter ?? [], ...))`.

Specifically:
- The assignee toggle button URLs (My Tickets / All / Unassigned)
- The quick filter pill URLs (Needs Action / Overdue / Waiting)
- The filter form `action` attribute
- The "Clear all filters" link
- The "Create a Ticket" link in the empty state (keep pointing to `route('tickets.create')`, but add `?client_id=X` when a client prefilter exists)

- [ ] **Step 2: Hide pre-filtered dimensions from the filter UI**

Wrap the client dropdown in the filter card with:
```blade
@unless(isset($prefilter['client_id']))
    <div class="col-lg-2 col-md-3">
        <select name="client_id" ...>...</select>
    </div>
@endunless
```

In the active-filters display, skip the client name when it's a prefilter:
```blade
@if(!empty($filters['client_id']) && !isset($prefilter['client_id'])) $activeFilters[] = ...; @endif
```

Hide the Client column from the table (`<th>` and `<td>`) when `$prefilter` contains `client_id`:
```blade
@unless(isset($prefilter['client_id']))
    <th class="{{ $currentSort === 'client' ? 'active-sort' : '' }}">...</th>
@endunless
```
And the corresponding `<td>`:
```blade
@unless(isset($prefilter['client_id']))
    <td class="small"><x-client-badge :client="$ticket->client" fallback="-" /></td>
@endunless
```

- [ ] **Step 3: Add prefilter hidden inputs to the filter form**

After the `<form>` tag and before the existing hidden inputs, add:
```blade
@foreach($prefilter ?? [] as $key => $value)
    <input type="hidden" name="{{ $key }}" value="{{ $value }}">
@endforeach
```

Wait — `client_id` is already a named filter. The prefilter should be passed as query parameters in the route, not as hidden form inputs. Since the `$listRoute` already includes the client param in the route (e.g., `/clients/{client}/tickets`), and the filter form posts to that route with `$prefilter` merged, the `client_id` will be part of the URL path, not a query param.

Actually, looking at this more carefully: the `TicketService::getTicketList()` expects `client_id` as a filter. The route is `/clients/{client}/tickets` and the controller explicitly sets `'client_id' => (string) $client->id` in the filters. So the filter form doesn't need to pass `client_id` at all — the controller handles it. The form just needs to submit to the correct route.

So for the filter form, the action should be:
```blade
<form method="GET" action="{{ route($listRoute, $prefilter ?? []) }}">
```

Where `$prefilter` for clients is `['client' => $client->id]` (route parameter name, not `client_id`). The controller method receives the `Client $client` via route model binding and sets `client_id` in the filters.

- [ ] **Step 4: Scope localStorage key per list context**

In the JS preference persistence section, change the storage key to include the route context:

```javascript
var STORAGE_KEY = 'ticketListDefaults_{{ $listRoute }}';
```

This prevents the standalone ticket index defaults from interfering with client-scoped defaults.

- [ ] **Step 5: Merge prefilter into bulk action filter inputs**

In the bulk action JS where `currentFilters` is built, ensure the prefilter's `client_id` is always included:

```javascript
var currentFilters = @json($jsFilters);
@if(isset($prefilter['client_id']))
    currentFilters['client_id'] = '{{ $prefilter['client_id'] }}';
@endif
```

Wait — `$jsFilters` is already built from `$filters` which already contains `client_id` from the controller. So this is already handled. No extra JS changes needed.

- [ ] **Step 6: Commit**

```bash
git add resources/views/tickets/_list.blade.php
git commit -m "Add prefilter support to ticket list partial (hide locked filters from UI)"
```

---

### Task 6: Manual verification

- [ ] **Step 1: Verify standalone ticket index**

Start dev server, log in, navigate to `/tickets`. Verify:
- All filters work (status, priority, type, source, client, search, show_closed, overdue)
- Sorting works on all columns
- Bulk actions work (select, modal, submit)
- Pagination works
- "Save as default" / "Reset default" works
- Page renders identically to before the extraction

- [ ] **Step 2: Verify client detail tickets tab**

Navigate to `/clients/{some-client-id}`. Verify:
- The Tickets tab shows as a link (not a JS tab toggle)
- Click it → navigates to `/clients/{id}/tickets`
- Full ticket list appears with filters, sorting, pagination, bulk actions
- Client dropdown is NOT shown in the filter bar
- Client column is NOT shown in the table
- Assignee toggle defaults to "All" (not "My Tickets")
- Quick filters (Needs Action, Overdue, Waiting) work
- Sorting works — URLs stay on `/clients/{id}/tickets?sort=...`
- Pagination stays on the client tickets page
- Clicking Overview tab navigates back to `/clients/{id}`
- The "View all tickets" link in the summary view (when navigating to `/clients/{id}` directly) points to `/clients/{id}/tickets`

- [ ] **Step 3: Verify other client detail tabs still work**

Navigate to `/clients/{id}` (Overview tab). Verify:
- Overview tab shows the simplified ticket summary (Recent Tickets card)
- Activity, Assets, Notes, Licenses, Integrations tabs all still work as JS tabs

- [ ] **Step 4: Kill dev server and commit any fixes**

```bash
fuser -k 8080/tcp
```

If any issues were found, fix and commit.

---

### Task 7: Deploy

- [ ] **Step 1: Deploy to VPS**

Use `/deploy` slash command.
