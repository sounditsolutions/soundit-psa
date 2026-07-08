# Alerts Hub — list + detail/edit reshape (psa-87s0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.
> **Spec:** `docs/superpowers/specs/2026-07-05-alerts-hub-list-detail-reshape-design.md`. **Bead:** psa-87s0.

**Goal:** Reshape the Alerts Hub control surface into the MCP-tokens page pattern — a landing (worker banner + Activity feed + two clickable lists: Destinations, Routes) with per-item detail/edit pages and per-list Create buttons — with zero changes to the signal plane or schema.

**Architecture:** Presentation-only restructure of `AlertsHubController` + `resources/views/settings/alerts/*`. Existing action methods (`store`/`update`/`toggle`/`test`/`storeRoute`/`updateRoute`/`toggleRoute`) and all their masking/keep/audit/SSRF/test-send logic are **preserved**; new `show*`/`create*` read methods and views are **added**; create/edit/test redirect to the item's new detail page; toggle redirects `back()`. Old tab partials are deleted once the landing is rewritten.

**Tech Stack:** Laravel 12, Blade + Bootstrap 5.3 (CDN, no build step), PHPUnit + RefreshDatabase, MariaDB. Follows the `McpTokensController` / `resources/views/settings/mcp-tokens/{index,show,_state_badge}` in-repo pattern.

## Global Constraints (bind every task — verbatim from the spec)

- **Signal plane OFF-LIMITS:** no edits to `App\Services\Signals\*`, `App\Jobs\*Signal*`, or any `SignalHub::emit` feeder. **No schema changes** to `signal_*` tables/models.
- **Test-send relocated byte-identical:** `test()` + `deliverToSink()` + `markDeliveredIfStillPending()` + `markFailedIfStillPending()` keep their exact sink calls and `SignalDelivery`/destination-health writes.
- **Secret non-exposure preserved:** encrypted casts + `mask()` (host + last-4) + `SECRET_MASK` sentinel + `keepsExisting()`/`nullableTrim()` blank-means-keep. Never render a raw `address`/`wake_url`/`wake_secret`/`secret`; never log them (`changes()` redaction stays).
- **Validation preserved:** `SafeWebhookUrl` on `address`/`wake_url`; `wake_secret` required when `wake_url` set; email header-injection rejection; `type ∈ {webhook,email,mcp}`.
- **Audit preserved:** `SignalConfigLog::record(...)` on every create/update/toggle (actor-stamped).
- **Naming:** code is `Signal*` / `signal_*`; UI copy says "Alerts Hub". Never introduce `Alert*`-named code (that's the RMM domain).
- **Route ordering:** literal sub-paths (`.../create`) MUST be registered before the `{model}` wildcard (`.../{destination}`, `.../{route}`), or "create" binds as a model id.
- TDD throughout: failing test first. `vendor/bin/pint` clean before each commit. Verify per task with `php artisan test --filter=<Class>`; full suite green at the end.

## File Structure

- **Modify:** `routes/web.php` (add create + show GET routes, `:312-319` block), `app/Http/Controllers/Web/AlertsHubController.php` (add `createDestination`/`showDestination`/`createRoute`/`showRoute`; restructure `index`; flip redirects).
- **Create:** `resources/views/settings/alerts/_status_badge.blade.php`; `resources/views/settings/alerts/destinations/{_form,create,show}.blade.php`; `resources/views/settings/alerts/routes/{_form,create,show}.blade.php`.
- **Rewrite:** `resources/views/settings/alerts/index.blade.php` (landing).
- **Delete (after landing rewrite):** `resources/views/settings/alerts/destinations.blade.php`, `resources/views/settings/alerts/routes.blade.php` (old tab partials). **Keep:** `resources/views/settings/alerts/activity.blade.php` (included on landing).
- **Modify tests:** `tests/Feature/Signals/AlertsHub{Destinations,Routes,Activity}Test.php`. **Create tests:** `AlertsHubDestinationDetailTest.php`, `AlertsHubRouteDetailTest.php`.

Reference anchors (read at execution — do NOT reproduce verbatim): current create form `destinations.blade.php:1-75`; inline edit `destinations.blade.php:146-192`; route create form `routes.blade.php:74-108`; route inline edit `routes.blade.php:159-244`; activity `activity.blade.php:1-77`; token detail template `resources/views/settings/mcp-tokens/show.blade.php`.

---

### Task 1: Destination detail/edit page (`show`) + shared `_form` partial

Adds the destination detail page and makes edit/test/toggle land on it. The `_form` partial (created here) is reused by Task 2's create page.

**Files:**
- Modify: `routes/web.php` (add show route; flip nothing yet in routing)
- Modify: `app/Http/Controllers/Web/AlertsHubController.php` (add `showDestination`; flip `update`/`test` redirects to `...show`; flip `toggle` to `back()`)
- Create: `resources/views/settings/alerts/destinations/_form.blade.php`
- Create: `resources/views/settings/alerts/destinations/show.blade.php`
- Create: `resources/views/settings/alerts/_status_badge.blade.php`
- Test: `tests/Feature/Signals/AlertsHubDestinationDetailTest.php`; update `AlertsHubDestinationsTest.php`

**Interfaces:**
- Consumes: `SignalDestination`, `SignalDelivery` (with `event`), `$this->decorateDestination()`, `self::SECRET_MASK`, `McpToken::active()`.
- Produces: route name `settings.alerts.destinations.show` (GET `/settings/alerts/destinations/{destination}`); method `showDestination(SignalDestination $destination)`.

- [ ] **Step 1 — Failing test** `AlertsHubDestinationDetailTest`:
```php
public function test_show_renders_config_health_and_recent_deliveries_without_leaking_secrets(): void
{
    $dest = SignalDestination::create([
        'label' => 'Ops webhook', 'type' => 'webhook',
        'address' => 'https://93.184.216.34/hooks/super-secret-1234',
        'last_delivery_at' => now()->subMinutes(3), 'last_delivery_status' => 'delivered',
    ]);
    $event = SignalEvent::create(['type_key' => 'system.test', 'summary' => 'ping',
        'context' => [], 'occurred_at' => now()]);
    SignalDelivery::create(['event_id' => $event->id, 'destination_id' => $dest->id,
        'step_order' => 0, 'status' => 'delivered', 'delivered_at' => now()]);

    $this->actingAs($this->user)->get(route('settings.alerts.destinations.show', $dest))
        ->assertOk()
        ->assertSee('Ops webhook')
        ->assertSee('93.184.216.34')          // masked host shown
        ->assertSee('1234')                    // last-4 shown
        ->assertSee('delivered')               // health + recent delivery
        ->assertSee('All destinations')        // back-link
        ->assertDontSee('https://93.184.216.34/hooks/super-secret-1234'); // full secret never rendered
}
```
- [ ] **Step 2 — Run, verify FAIL** (`php artisan test --filter=AlertsHubDestinationDetailTest`): route/method/view missing.
- [ ] **Step 3 — Add route** in `routes/web.php` inside the alerts block, AFTER the `destinations` POST and BEFORE any future `destinations/create` (see Task 2):
```php
Route::get('/settings/alerts/destinations/{destination}', [AlertsHubController::class, 'showDestination'])->name('settings.alerts.destinations.show');
```
- [ ] **Step 4 — Add controller method** to `AlertsHubController`:
```php
public function showDestination(SignalDestination $destination)
{
    return view('settings.alerts.destinations.show', [
        'destination' => $this->decorateDestination($destination),
        'recentDeliveries' => SignalDelivery::query()
            ->where('destination_id', $destination->id)
            ->with('event')->latest()->limit(20)->get(),
        'mcpTokens' => McpToken::query()->active()->orderBy('label')->get(['label']),
        'secretMask' => self::SECRET_MASK,
    ]);
}
```
- [ ] **Step 5 — Create `_status_badge.blade.php`**: `@php($enabled = $enabled ?? false)` → `<span class="badge rounded-pill bg-{{ $enabled ? 'success' : 'secondary' }}-subtle text-{{ $enabled ? 'success' : 'secondary' }}-emphasis">{{ $enabled ? 'Enabled' : 'Disabled' }}</span>`.
- [ ] **Step 6 — Create `destinations/_form.blade.php`**: extract the field markup from the current create form (`destinations.blade.php:1-75`) — label, `type` select (webhook|email|mcp), the type-conditional `address` / `mcp_token_label` (select from `$mcpTokens`) / `wake_url` / `wake_secret` inputs, plus its show/hide-by-type JS. Parameterize: accept `$destination = null`; when set, prefill `label`/`type`/`enabled` and render `address`/`wake_url`/`wake_secret` inputs with `placeholder="{{ $destination->masked_* ?? $secretMask }}"` and empty `value` (blank-means-keep). The `<form>` element itself lives in the including view (create posts to `store`; show PUTs to `update`).
- [ ] **Step 7 — Create `destinations/show.blade.php`**: `@extends('layouts.app')`; back-link `<a href="{{ route('settings.alerts.index') }}"><i class="bi bi-arrow-left"></i> All destinations</a>`; flash alerts; header with `{{ $destination->label }}` + type badge + `@include('settings.alerts._status_badge', ['enabled' => $destination->enabled])` + an action cluster with the **Test send** form (`POST settings.alerts.destinations.test`) and **Toggle** form (`POST settings.alerts.destinations.toggle`, `@method` not needed) — both carrying `@csrf` and `->from` via a hidden nothing (browser referer suffices); **Config** card wrapping `<form method="POST" action="{{ route('settings.alerts.destinations.update', $destination) }}">@csrf @method('PUT') @include('settings.alerts.destinations._form', ['destination' => $destination, 'mcpTokens' => $mcpTokens, 'secretMask' => $secretMask])<button>Save</button></form>`; **Delivery health** card (`$destination->last_delivery_at?->toAppTz()->format('Y-m-d H:i')`, `last_delivery_status`, `last_error`); **Recent deliveries** card (`@forelse($recentDeliveries as $d)` row: `$d->created_at->toAppTz()...`, `$d->event?->type_key`, `$d->status`, `$d->error` `@empty` muted "No deliveries yet").
- [ ] **Step 8 — Flip redirects** in `AlertsHubController`: `update()` → `redirect()->route('settings.alerts.destinations.show', $destination)`; `test()` (both success and failure returns) → `...destinations.show`, `$destination`; `toggle()` → `redirect()->back()->with('success', ...)`.
- [ ] **Step 9 — Update `AlertsHubDestinationsTest`**: change the `update`/`test`/`toggle` `assertRedirect(route('settings.alerts.index'))` expectations — `update` → `assertRedirect(route('settings.alerts.destinations.show', $destination))`; each `test` case → `...destinations.show`, `$destination`; `toggle` cases → add `->from(route('settings.alerts.index'))` before the POST and `assertRedirect(route('settings.alerts.index'))` (back()). Keep every other assertion (DB, masking, config-log, throttle) unchanged.
- [ ] **Step 10 — Run, verify PASS** (`php artisan test --filter='AlertsHubDestinationDetailTest|AlertsHubDestinationsTest'`), `vendor/bin/pint --dirty`.
- [ ] **Step 11 — Commit** `psa-87s0 T1: destination detail/edit page + _form + status badge`.

---

### Task 2: Destination create page

**Files:**
- Modify: `routes/web.php` (add create GET route BEFORE the `{destination}` show route)
- Modify: `AlertsHubController` (add `createDestination`; flip `store` redirect → show)
- Create: `resources/views/settings/alerts/destinations/create.blade.php`
- Test: update `AlertsHubDestinationsTest` (store redirect); add a create-render test

**Interfaces:**
- Produces: route `settings.alerts.destinations.create` (GET `/settings/alerts/destinations/create`); method `createDestination()`.

- [ ] **Step 1 — Failing test** in `AlertsHubDestinationsTest`:
```php
public function test_create_page_renders_and_store_redirects_to_detail(): void
{
    $this->actingAs($this->user)->get(route('settings.alerts.destinations.create'))
        ->assertOk()->assertSee('New destination')->assertSee('All destinations');

    $this->actingAs($this->user)->post(route('settings.alerts.destinations.store'), [
        'label' => 'Ops webhook', 'type' => 'webhook', 'address' => 'https://93.184.216.34/hooks/abcd1234',
    ])->assertSessionHasNoErrors()
      ->assertRedirect(route('settings.alerts.destinations.show', SignalDestination::firstOrFail()));
}
```
Also update the existing `test_stores_webhook_destination_with_safe_url_and_config_log` and `test_blank_update_...` redirect expectations already handled in Task 1/here: change store's `assertRedirect(route('settings.alerts.index'))` → `assertRedirect(route('settings.alerts.destinations.show', SignalDestination::firstOrFail()))`.
- [ ] **Step 2 — Run, verify FAIL** (route/view missing; store still redirects to index).
- [ ] **Step 3 — Add route** BEFORE the show route from Task 1:
```php
Route::get('/settings/alerts/destinations/create', [AlertsHubController::class, 'createDestination'])->name('settings.alerts.destinations.create');
```
(Confirm ordering: `destinations/create` GET precedes `destinations/{destination}` GET in the file.)
- [ ] **Step 4 — Add controller method**:
```php
public function createDestination()
{
    return view('settings.alerts.destinations.create', [
        'mcpTokens' => McpToken::query()->active()->orderBy('label')->get(['label']),
        'secretMask' => self::SECRET_MASK,
    ]);
}
```
- [ ] **Step 5 — Create `destinations/create.blade.php`**: back-link; header "New destination"; Config card wrapping `<form method="POST" action="{{ route('settings.alerts.destinations.store') }}">@csrf @include('settings.alerts.destinations._form', ['destination' => null, 'mcpTokens' => $mcpTokens, 'secretMask' => $secretMask])<button>Create destination</button></form>`.
- [ ] **Step 6 — Flip `store()` redirect** → `redirect()->route('settings.alerts.destinations.show', $destination)->with('success', 'Destination created.')`.
- [ ] **Step 7 — Run, verify PASS**, `pint --dirty`.
- [ ] **Step 8 — Commit** `psa-87s0 T2: destination create page`.

---

### Task 3: Route detail/edit page (`show`) + shared route `_form` partial

**Files:**
- Modify: `routes/web.php` (add route show GET), `AlertsHubController` (add `showRoute`; flip `updateRoute` → show, `toggleRoute` → back())
- Create: `resources/views/settings/alerts/routes/_form.blade.php`, `resources/views/settings/alerts/routes/show.blade.php`
- Test: `AlertsHubRouteDetailTest.php`; update `AlertsHubRoutesTest.php`

**Interfaces:**
- Consumes: `SignalRoute` (with `steps.destination`), `SignalDelivery` (with `destination`+`event`), `$this->decorateRoute()`, `$this->eventTypeGroups()`, `routeDestinations` (id/label/type list).
- Produces: route `settings.alerts.routes.show` (GET `/settings/alerts/routes/{route}`); method `showRoute(SignalRoute $route)`.

- [ ] **Step 1 — Failing test** `AlertsHubRouteDetailTest`:
```php
public function test_show_renders_composer_and_recent_fires(): void
{
    $dest = SignalDestination::create(['label' => 'Ops', 'type' => 'webhook', 'address' => 'https://93.184.216.34/h/aaaa1111']);
    $route = SignalRoute::create(['label' => 'Ticket alerts', 'event_filter' => ['types' => ['ticket.created']], 'enabled' => true, 'cooldown_seconds' => 300]);
    SignalRouteStep::create(['route_id' => $route->id, 'step_order' => 1, 'destination_id' => $dest->id]);
    $event = SignalEvent::create(['type_key' => 'ticket.created', 'summary' => 'x', 'context' => [], 'occurred_at' => now()]);
    SignalDelivery::create(['event_id' => $event->id, 'route_id' => $route->id, 'destination_id' => $dest->id, 'step_order' => 1, 'status' => 'delivered', 'delivered_at' => now()]);

    $this->actingAs($this->user)->get(route('settings.alerts.routes.show', $route))
        ->assertOk()->assertSee('Ticket alerts')->assertSee('ticket.created')
        ->assertSee('Ops')->assertSee('delivered')->assertSee('All routes');
}
```
- [ ] **Step 2 — Run, verify FAIL.**
- [ ] **Step 3 — Add route** `Route::get('/settings/alerts/routes/{route}', [AlertsHubController::class, 'showRoute'])->name('settings.alerts.routes.show');` (create route added in Task 4 goes before this).
- [ ] **Step 4 — Add controller method**:
```php
public function showRoute(SignalRoute $route)
{
    return view('settings.alerts.routes.show', [
        'route' => $this->decorateRoute($route->load('steps.destination')),
        'routeDestinations' => SignalDestination::query()->orderBy('label')->get(['id', 'label', 'type']),
        'eventTypeGroups' => $this->eventTypeGroups(),
        'recentFires' => SignalDelivery::query()->where('route_id', $route->id)
            ->with(['destination', 'event'])->latest()->limit(20)->get(),
    ]);
}
```
- [ ] **Step 5 — Create `routes/_form.blade.php`**: extract from the current route create form (`routes.blade.php:74-108`) — event-type checkbox groups (from `$eventTypeGroups`), filters (categories / min_priority / client_ids), `cooldown_seconds`, and the ladder step editor (3 step slots with a `destination_id` select from `$routeDestinations`, `wait_for_ack_seconds`, `resolve_within_seconds`, `non_suppressible`, and the shared-`step_order` "simultaneous" checkbox idiom, `routes.blade.php:232`). Parameterize with `$route = null`; when set, prefill checked types/filters/cooldown and existing steps.
- [ ] **Step 6 — Create `routes/show.blade.php`**: back-link "All routes"; header `{{ $route->label }}` + status badge + Toggle form (`POST settings.alerts.routes.toggle`); Composer card wrapping `<form method="POST" action="{{ route('settings.alerts.routes.update', $route) }}">@csrf @method('PUT') @include('settings.alerts.routes._form', [...])<button>Save</button></form>`; Recent fires card (`@forelse($recentFires ...)`: when / `$d->event?->type_key` / `$d->destination?->label` / `step_order` / `status` `@empty` "No fires yet").
- [ ] **Step 7 — Flip redirects**: `updateRoute()` → `...routes.show`, `$route`; `toggleRoute()` → `back()`.
- [ ] **Step 8 — Update `AlertsHubRoutesTest`**: `updateRoute` redirect → `assertRedirect(route('settings.alerts.routes.show', $route))`; `toggleRoute` → `->from(route('settings.alerts.index'))` + back() expectation. Keep step/filter/cooldown/audit assertions.
- [ ] **Step 9 — Run, verify PASS**, `pint --dirty`.
- [ ] **Step 10 — Commit** `psa-87s0 T3: route detail/edit page + route _form`.

---

### Task 4: Route create page

**Files:** Modify `routes/web.php` (create GET before `{route}` show), `AlertsHubController` (`createRoute`; flip `storeRoute` → show). Create `resources/views/settings/alerts/routes/create.blade.php`. Test: update `AlertsHubRoutesTest`.

**Interfaces:** route `settings.alerts.routes.create` (GET `/settings/alerts/routes/create`); method `createRoute()`.

- [ ] **Step 1 — Failing test** in `AlertsHubRoutesTest`:
```php
public function test_route_create_page_renders_and_store_redirects_to_detail(): void
{
    $this->actingAs($this->user)->get(route('settings.alerts.routes.create'))
        ->assertOk()->assertSee('New route');

    $this->actingAs($this->user)->post(route('settings.alerts.routes.store'), [
        'label' => 'Ticket alerts', 'event_types' => ['ticket.created'], 'cooldown_seconds' => 300,
    ])->assertSessionHasNoErrors()
      ->assertRedirect(route('settings.alerts.routes.show', SignalRoute::firstOrFail()));
}
```
(Match the exact request field names the existing `storeRoute` validation expects — read `validatedRouteAttributes`/`storeRoute` at execution and mirror the current passing `AlertsHubRoutesTest` store payload.)
- [ ] **Step 2 — Run, verify FAIL.**
- [ ] **Step 3 — Add route** BEFORE the `{route}` show route: `Route::get('/settings/alerts/routes/create', [AlertsHubController::class, 'createRoute'])->name('settings.alerts.routes.create');`
- [ ] **Step 4 — Add controller method** returning `settings.alerts.routes.create` with `routeDestinations` + `eventTypeGroups`.
- [ ] **Step 5 — Create `routes/create.blade.php`** including `routes/_form` with `$route = null` → POST `storeRoute`.
- [ ] **Step 6 — Flip `storeRoute()` redirect** → `...routes.show`, `$route`.
- [ ] **Step 7 — Run, verify PASS**, `pint --dirty`.
- [ ] **Step 8 — Commit** `psa-87s0 T4: route create page`.

---

### Task 5: Landing restructure (two clickable lists + activity + banner) & delete old tab partials

**Files:** Modify `AlertsHubController::index()`; rewrite `resources/views/settings/alerts/index.blade.php`; delete `destinations.blade.php` + `routes.blade.php`; keep `activity.blade.php`. Update `AlertsHubActivityTest` / `AlertsHubRoutesTest` list assertions if needed.

**Interfaces:** `index()` returns the landing with `destinations` (decorated), `routes` (decorated with steps), `recentDeliveries`, `recentConfigLogs`, `hasStalePendingDelivery`. Drops `routeDestinations`/`eventTypeGroups`/`mcpTokens`/`secretMask` (moved to create/show).

- [ ] **Step 1 — Failing test** in `AlertsHubActivityTest` (or Destinations/Routes list test):
```php
public function test_landing_shows_two_clickable_lists_create_buttons_and_activity(): void
{
    $dest = SignalDestination::create(['label' => 'Ops webhook', 'type' => 'webhook', 'address' => 'https://93.184.216.34/h/aaaa1111']);
    $route = SignalRoute::create(['label' => 'Ticket alerts', 'event_filter' => ['types' => ['ticket.created']], 'enabled' => true]);

    $this->actingAs($this->user)->get(route('settings.alerts.index'))
        ->assertOk()
        ->assertSee('Destinations')->assertSee('Routes')->assertSee('Activity')
        ->assertSee(route('settings.alerts.destinations.create'), false)
        ->assertSee(route('settings.alerts.routes.create'), false)
        ->assertSee(route('settings.alerts.destinations.show', $dest), false)   // row links to detail
        ->assertSee(route('settings.alerts.routes.show', $route), false);
}
```
- [ ] **Step 2 — Run, verify FAIL** (old tabbed index has no create-page or show links).
- [ ] **Step 3 — Restructure `index()`**: keep `destinations`, `routes` (with `steps.destination`), `recentDeliveries`, `recentConfigLogs`, `hasStalePendingDelivery`; remove the four now-unused view vars.
- [ ] **Step 4 — Rewrite `index.blade.php`**: `@extends('layouts.app')`; page header "Alerts Hub"; worker banner (`@if($hasStalePendingDelivery)` — copy the existing markup from `activity.blade.php:1-8`); **Destinations** `card card-static` with a top-right `<a class="btn btn-primary" href="{{ route('settings.alerts.destinations.create') }}">New destination</a>` and a `thead-brand` table whose label cell is `<a href="{{ route('settings.alerts.destinations.show', $d) }}">` + `_status_badge` + masked address + last-delivery, `@forelse ... @empty` empty state; **Routes** `card card-static` with `New route` button and a table linking each row to `routes.show`; **Activity** section `@include('settings.alerts.activity')`.
- [ ] **Step 5 — Delete** `resources/views/settings/alerts/destinations.blade.php` and `routes.blade.php`. Grep confirms only `index.blade.php` `@include`d them; the new index no longer does.
- [ ] **Step 6 — Adjust `activity.blade.php`**: drop the leading worker-banner block (`:1-8`) now that the landing renders it above the lists (avoid a duplicate banner); keep Recent Deliveries + Config Changes.
- [ ] **Step 7 — Run, verify PASS** (`php artisan test --filter='AlertsHub'`), `pint --dirty`.
- [ ] **Step 8 — Commit** `psa-87s0 T5: landing = two clickable lists + activity + worker banner`.

---

## Final Verification
- [ ] Full suite green: `php artisan test`. Targeted: `php artisan test --filter='AlertsHub|Signal'`.
- [ ] `vendor/bin/pint --test` clean (whole project).
- [ ] **Signal-plane untouched:** `git diff main --stat` shows changes ONLY in `routes/web.php`, `AlertsHubController.php`, `resources/views/settings/alerts/*`, and `tests/Feature/Signals/*` — no `App/Services/Signals`, `App/Jobs`, or `App/Models/Signal*`.
- [ ] **Secret non-exposure:** grep the rendered detail/list/create pages in tests for raw `address`/`wake_url`/`wake_secret` — none present (assertDontSee locks in Task 1/existing index test).
- [ ] Live dev-server click-through (dev-login): landing → click a destination → detail (health + recent deliveries, masked secret) → edit + save → test-send; landing → click a route → composer + recent fires → edit; create flows for both. Restart daemons first (stale-daemon rule).
- [ ] PR to `main`, comment state on psa-87s0, nudge Mayor, **HOLD MERGE**. PR body: the reshape scope, the "signal-plane untouched" guarantee, the preserved-invariants list, and the locked decisions.
