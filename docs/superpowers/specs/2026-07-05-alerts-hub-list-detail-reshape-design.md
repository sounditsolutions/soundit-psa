# Alerts Hub — list + detail/edit reshape (psa-87s0) — Design

> **Bead:** psa-87s0 (Mayor GO 2026-07-05). Charlie UX direction (Discord 07-05): "make the Alerts page follow the MCP tokens page shape — list, a create button, clickable list items → an edit form per item."
> **Type:** pure control-surface UX reshape. **No behavior changes to the signal plane. No schema changes.**

## Goal

Reshape the **Alerts Hub** control surface (`/settings/alerts`, `AlertsHubController` + `resources/views/settings/alerts/*`) from its current single tabbed page (with inline create-forms and inline table-row edit-forms) into the **MCP-tokens page pattern**: a landing/overview with two clickable lists (**Destinations** and **Routes**), a **Create** button per list, and **per-item detail/edit pages** reached by clicking a row. The Activity feed and worker-liveness banner remain on the landing.

## Non-goals / hard boundaries

- **Signal plane is OFF-LIMITS** — no changes to `App\Services\Signals\*` (`SignalHub`, `SignalRouter`, `SignalDeliveryState`, sinks), `App\Jobs\*Signal*`, or any `SignalHub::emit` feeder. This is presentation-only.
- **No schema changes** — the `signal_*` tables and models are reused as-is (control surface reads/writes rows; it does not alter columns).
- **No new signal behavior** — the test-send action (`AlertsHubController::test()` + `deliverToSink()`/`markDeliveredIfStillPending()`/`markFailedIfStillPending()`) duplicates signal-plane semantics inline; it is **relocated, not rewritten** — its sink calls and delivery/health writes stay byte-for-byte identical.
- **No new destructive capability** — the current Hub has enable/disable **toggle** only (no delete). The reshape keeps toggle-only. (A delete affordance, if ever wanted, is a separate scoped decision — noted as a follow-up.)

## Source → target mapping

**Source (current Alerts Hub — control surface, safe to restructure):**
- `AlertsHubController` (single controller): `index()` loads everything; `store/update/toggle/test` (destinations); `storeRoute/updateRoute/toggleRoute` (routes). Masking/keep/audit/SSRF helpers live here. **No `show`/detail route.**
- Views: `index.blade.php` (3 `nav-tabs`) → `destinations.blade.php` / `routes.blade.php` / `activity.blade.php` partials. Create = left-card form; edit = a second inline `<tr>` edit-form per row.
- Routes: `settings.alerts.*` (`routes/web.php:312-319`). Tests: `tests/Feature/Signals/AlertsHub{Destinations,Routes,Activity}Test.php`.

**Target pattern (MCP-tokens — `McpTokensController` + `resources/views/settings/mcp-tokens/{index,show,_state_badge}`):**
- Flat `settings.{resource}.{action}` route names; `{model}` implicit-bound by id; **literal sub-paths registered before the `{model}` wildcard**.
- **List page skeleton:** header (title + subtitle + top-right primary create button) → flash → single `card card-static shadow-sm` → empty-state (centered icon + heading + muted `max-width:42ch` copy + create button) vs `thead-brand` table → name-anchor + trailing "Open" button linking to detail → dimmed inactive rows.
- **Detail page skeleton:** back-link to index (`btn btn-link` + `bi-arrow-left`) → contextual banners → icon-tile header (title + status badge + meta row + right-aligned action cluster) → sub-sections → cards with `card-header` + `bi` icon → `forelse` empty states → audit/activity table.
- Status pill: a small reusable partial driven by a state string (`_state_badge`).
- Thin controller; every mutation writes an audit row.
- Sidebar link already wired for both resources (`sidebar.blade.php`) — no change.

## Design

### Routes (`routes/web.php`, `settings.alerts.*`)

Keep all existing action routes. **Add** GET routes for create + detail, ordered so literal `create` precedes the `{model}` wildcard:

```
GET   /settings/alerts                               index            settings.alerts.index          (restructured landing)
GET   /settings/alerts/destinations/create           createDestination settings.alerts.destinations.create   (NEW, literal — before {destination})
POST  /settings/alerts/destinations                  store            settings.alerts.destinations.store     (redirect → show on success)
GET   /settings/alerts/destinations/{destination}    showDestination  settings.alerts.destinations.show      (NEW — detail/edit)
PUT   /settings/alerts/destinations/{destination}    update           settings.alerts.destinations.update    (redirect → show)
POST  /settings/alerts/destinations/{destination}/test    test        settings.alerts.destinations.test      (throttle:6,1; redirect → show)
POST  /settings/alerts/destinations/{destination}/toggle  toggle      settings.alerts.destinations.toggle    (redirect → show or back)
GET   /settings/alerts/routes/create                 createRoute      settings.alerts.routes.create          (NEW, literal — before {route})
POST  /settings/alerts/routes                         storeRoute       settings.alerts.routes.store           (redirect → route show)
GET   /settings/alerts/routes/{route}                showRoute        settings.alerts.routes.show            (NEW — detail/edit)
PUT   /settings/alerts/routes/{route}                updateRoute      settings.alerts.routes.update          (redirect → route show)
POST  /settings/alerts/routes/{route}/toggle         toggleRoute      settings.alerts.routes.toggle          (redirect → route show or back)
```

### Controller (`AlertsHubController`)

- **`index()`** — restructure: load the two lists (decorated destinations; decorated routes with `steps.destination`), the activity feed (recent deliveries last 100 with `destination`+`event`; recent config logs last 20), and the worker-banner flag (`hasStalePendingDelivery`). No longer loads per-item edit scaffolding.
- **`createDestination()` / `createRoute()`** (NEW) — return the create form views (event-type groups + `routeDestinations` for the route step pickers; `secretMask`).
- **`showDestination(SignalDestination $destination)`** (NEW) — decorated destination (masked fields) + delivery health (`last_delivery_at/status/last_error`) + **recent deliveries for this destination** (`SignalDelivery::where('destination_id', …)->with('event')->latest`) + the edit form context + the test-send button context.
- **`showRoute(SignalRoute $route)`** (NEW) — route with steps + event-type groups + filters + cooldown (the composer edit form context) + **recent fires for this route** (`SignalDelivery::where('route_id', …)->with(['destination','event'])->latest`).
- **`store` / `storeRoute`** — unchanged validation/normalization/audit; on success `redirect()->route(...show, $model)` instead of back to an index tab.
- **`update` / `updateRoute`** — unchanged (keep the existing PUT form-submit model, masking/keep/audit/SSRF preserved); redirect → show.
- **`test` / `toggle` / `toggleRoute`** — unchanged behavior; redirect → show (test/route-toggle) or back (list toggle). The `test()` sink/health-write helpers move with it, byte-identical.
- All masking (`mask`, `decorateDestination`, `SECRET_MASK`, `keepsExisting`, `nullableTrim`), SSRF (`SafeWebhookUrl`), audit (`SignalConfigLog::record`, secret-field redaction), and `wake_secret`-required-with-`wake_url` validation are **preserved unchanged** — only relocated across views.

### Views (`resources/views/settings/alerts/`)

- **`index.blade.php`** (landing) — restructure to a single stacked page (no longer three edit-heavy tabs):
  1. **Worker-liveness banner** (`@if($hasStalePendingDelivery)`) at top — unchanged semantics.
  2. **Destinations** `card card-static` — header with a top-right "New destination" button (→ `destinations.create`); `thead-brand` table (Label+type / Status+health / last delivery / Open) with the label as an anchor to `destinations.show` + trailing "Open" button; dimmed disabled rows; empty state.
  3. **Routes** `card card-static` — header with "New route" button (→ `routes.create`); table (Label / event types summary / steps count / enabled / Open) → `routes.show`; empty state.
  4. **Activity** — Recent Deliveries table (the "fires" feed) + Config Changes list, as today, kept as `@include('settings.alerts.activity')` on the landing (the partial is retained verbatim; only its surrounding tab chrome is removed).
- **`destinations/show.blade.php`** (NEW — detail/edit) — back-link → icon-tile header (label + type badge + enabled pill + action cluster: **Test send**, **Toggle enable/disable**) → **Config** card: the edit form (type-dependent fields; `address`/`wake_url`/`wake_secret` rendered as masked placeholders with blank-means-keep) → **Delivery health** card (last delivery at/status + `last_error`) → **Recent deliveries** card (`forelse` table: when / event / status / error).
- **`routes/show.blade.php`** (NEW — detail/edit) — back-link → header (label + enabled pill + Toggle) → **Composer** card: the edit form (event-type checkbox groups, filters, cooldown, ladder step editor incl. the shared-`step_order` "simultaneous" idiom) → **Recent fires** card (`forelse` table: when / event / destination / step / status).
- **`destinations/create.blade.php` + `routes/create.blade.php`** (NEW) — the create forms (the current left-card forms, promoted to their own pages, posting to `store`/`storeRoute`). To avoid duplication, the destination and route **form bodies are extracted to shared partials** (`destinations/_form.blade.php`, `routes/_form.blade.php`) included by both the create page and the show/edit page.
- **Status pill** — a small `_status_badge.blade.php` partial (enabled/disabled; and for destinations optionally the last-delivery health) reused in the lists and detail headers.
- `activity.blade.php` — retained (folded into the landing or kept as an `@include`).

## Locked decisions

1. **Secret idiom = keep existing blank-means-keep** (encrypted cast + `mask()` + `SECRET_MASK` + `keepsExisting()`), **not** the token hash-only/regenerate model. This is exactly the personas-roster non-exposure discipline the Mayor cited.
2. **Edit model = standard form-submit** (reuse `update`/`updateRoute`), not per-field AJAX auto-save — the bead says "an edit **form** per item."
3. **Create = dedicated create pages** (Alerts create needs real upfront fields: type/label/address), not the token "mint blank draft then configure."
4. **Toggle-only** (no new delete affordance).
5. **Landing = single stacked page** (banner + Destinations list + Routes list + Activity), not tabs — matches "activity stays alongside the two lists."
6. **DRY forms** — destination and route form bodies live in shared `_form` partials used by both create and edit.

## Invariants preserved (regression guard)

- Signal plane + schema untouched; parallel-plane behavior byte-identical.
- Secret masking / blank-means-keep / no raw secret in HTML or `signal_config_log`.
- `SafeWebhookUrl` at save + SSRF pin at request time on `address`/`wake_url`.
- `wake_secret` required whenever `wake_url` set; `last_error` = status/reason only.
- `SignalConfigLog::record` on every create/update/toggle (actor-stamped).
- `test()` sink + delivery/health-write semantics unchanged (relocated only).
- `SignalEventTypes` read-only (route builder reads the registry).

## Testing (TDD)

- **Update** `AlertsHubDestinationsTest` / `AlertsHubRoutesTest` / `AlertsHubActivityTest` for the new routing (store/update redirect → show; landing renders the two lists + activity + banner).
- **Add** `AlertsHubDestinationDetailTest` (show renders config + health + recent deliveries; masked secrets not leaked; test-send from detail works; toggle from detail works) and `AlertsHubRouteDetailTest` (show renders composer + recent fires; update persists steps/filters/cooldown).
- **Add** create-page tests (create form renders; store from create redirects to show).
- Full suite green; `vendor/bin/pint` clean.

## Out of scope / follow-up candidates

- Delete affordance for destinations/routes (currently toggle-only).
- Surfacing the MCP `signal_inbox` mailbox on a destination detail page (data exists; not requested).
- Any per-field AJAX auto-save polish (deliberately deferred in favor of the simpler form model).
