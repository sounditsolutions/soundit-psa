---
type: design
title: "Call Lifecycle Signal Family — Direction Attribute vs Suffixed Types (psa-cdv3)"
created: 2026-07-09
tags: [soundit-dev, psa, signals, alerts-hub, intake, plivo, call, direction, design, review-me]
status: awaiting-review
bead: psa-cdv3
related:
  - "psa-ip15"   # W1 intake emissions (E1–E4) — shipped the coarse call pair this doc extends
  - "psa-xcyo"   # AI Technician intake front-door — the eventual consumer of intake signals
  - "psa-loyz"   # Call-intake leg: deferred polish (post-merge)
  - "2026-07-05-alerts-hub-list-detail-reshape-design"   # same subsystem (routes/destinations UI)
  - "2026-06-29-ai-technician-call-intake-design-v4"     # call-intake pipeline this rides alongside
---

# Call Lifecycle Signal Family — Direction Attribute vs Suffixed Types (psa-cdv3)

**Purpose.** Decide how (and whether) to give the Alerts Hub a routable notion of **call
direction** and a richer **call lifecycle**, before any code is written. Charlie's 07-03 design
input asked to replace the coarse `intake.call_received` signal with an independently-routable
lifecycle family (`call.started/connected`, `call.ended`, `call.transcribed`) carrying
**direction (inbound/outbound) as a filterable attribute**. W1 (psa-ip15) shipped only a coarse
pair, with direction living as a *word in the summary string* rather than a structured attribute.
This doc records the ask, corrects a stale premise it was filed under, and recommends a path.

**This is a decisions document, not an implementation plan.** It answers the one open question on
bead `psa-cdv3` — *is the lifecycle family worth a grammar expansion, or do direction-suffixed
types fit the existing grammar more cheaply?* — with a recommendation grounded in the PSA source as
it stands on 2026-07-09. Where it names a class, method, table, or line, that symbol was read in the
tree (branch `polecat/psa-cdv3`, based on `origin/main` @ 31f368a), not assumed. A follow-up
`writing-plans` pass turns the chosen cut into TDD task steps.

**The one-sentence thesis.** *Direction is a cross-cutting **attribute** of an intake event, not a
part of the event's identity — so it belongs in the `event_filter` grammar next to `categories`
and `client_ids` (which already exist), and the full `started/connected/ended` lifecycle family
should be **deferred** until a consumer needs a call-start event, because nothing does today.*

---

## 1. Charlie's original ask (07-03) — recorded so it is not lost

This is the durable record the bead asks for. Paraphrased from the psa-cdv3 description and the
psa-ip15 lineage:

> Replace the coarse `intake.call_received` with an **independently-routable lifecycle family**:
> - `call.started` / `call.connected` — a call began / was answered
> - `call.ended` — the call finished
> - `call.transcribed` — a recording was transcribed
>
> …with **direction (inbound / outbound)** as a **filterable attribute**, so an operator can route
> (say) *inbound* calls to the on-call destination and *outbound* calls somewhere quieter — without
> the two collapsing into one signal.

**Charlie's own default trigger** (his framing: "for a transcription shop") is `call.transcribed`.
That default is **already served today** by `intake.call_transcribed` (see §2). So the *urgent*
slice of the ask shipped in W1; what remains is the direction attribute and the lifecycle split —
which is exactly why psa-cdv3 was filed as "not urgent, design first."

---

## 2. What W1 actually shipped (source-verified 2026-07-09)

W1 (psa-ip15, "Alerts-wake completion: intake emissions") shipped a **coarse pair**, both
reference-only and dormant (no seeded routes), under the `intake.` namespace:

| Catalog type | Emitted from | When it fires | Context passed | Direction today |
|---|---|---|---|---|
| `intake.call_received` | `PlivoWebhookController::emitCallReceived()` (`app/Http/Controllers/Api/PlivoWebhookController.php:57`) | On **hangup** (`:336`) and on **terminal `CallStatus`** (`:345`) | `['client_id' => …]` only | a **word in the summary**: `'inbound call received'` / `'outbound call received'` (`:63–64`) |
| `intake.call_transcribed` | `TranscriptionService::finalizeSuccessfulTranscription()` (`app/Services/TranscriptionService.php:314`) | After a successful transcription (`:322–323`) | `['client_id' => …]` only | not carried at all |

Catalog registration: `app/Services/Signals/SignalEventTypes.php:35–49` (`intake.call_received`,
`intake.call_transcribed`, both `routable => true`, `core => false`).

**Two facts that shape the decision:**

1. **`intake.call_received` is a misnomer — it fires at the *end* of a call, not the start.** Both
   emit sites are terminal branches (hangup / `completed|busy|failed|timeout|no-answer|cancel`).
   Semantically the shipped event is **`call.ended`**, not `call.started`. There is **no**
   call-start / call-answered emission anywhere today.

2. **Direction is a display string, not data.** `PlivoWebhookController.php:63` collapses the
   `CallDirection` enum to a word and concatenates it into the summary. Downstream consumers can
   *read* "inbound"/"outbound" only by string-matching a human-readable summary — they cannot
   **route** on it, because the router never inspects the summary (§4). The summary word is even
   test-locked: `tests/Feature/Signals/IntakeCallEmissionsTest.php:68,94,111` assert the exact
   strings `'inbound call received'` / `'outbound call received'`.

`PhoneCall.direction` is a first-class enum column (`app/Models/PhoneCall.php:49` casts it to
`App\Enums\CallDirection` — `inbound` / `outbound`), so the structured value is already sitting on
the model at emit time. It is simply being flattened into prose on the way into the signal.

---

## 3. The premise correction — the grammar is **not** "types only"

The bead was filed (recon 07-05) under this stated constraint:

> "…v1 matches **types only**, so direction-as-attribute also needs event_filter support or
> direction-suffixed types."

**That premise is stale.** The router already does structured **attribute matching** on the event
`context`, and has since **2026-07-02** — commit `83ae7e3` ("feat(signals): Alerts Hub phase A+B",
#117), **three days before** the 07-05 recon. Read `SignalRouter::matches()`
(`app/Services/Signals/SignalRouter.php:62–93`):

```php
$types = $filter['types'] ?? [];
if ($types !== 'all' && ! in_array($event->type_key, (array) $types, true)) return false;   // types
if (array_key_exists('categories', $filter))  { …context['category']… }                      // attribute
if (array_key_exists('min_priority', $filter)) { …context['priority']… }                     // attribute
if (array_key_exists('client_ids', $filter))   { …context['client_id']… }                    // attribute
```

So the grammar is **type match + three context-attribute filters** already. The route-builder
UI and validator mirror this exactly:

- Validator: `AlertsHubController::validatedRoutePayload()`
  (`app/Http/Controllers/Web/AlertsHubController.php:368–400`) accepts
  `event_filter.categories`, `event_filter.min_priority`, `event_filter.client_ids`.
- Operator form: `resources/views/settings/alerts/routes/_form.blade.php` renders **Events**
  checkboxes (`:19–45`) plus **Categories** / **Client IDs** / **Minimum Priority** filter inputs
  (`:50–64`).

**Consequence:** adding a `direction` filter is **not a grammar expansion** — it is a **fourth
clause of an existing grammar**, structurally identical to the `categories` / `client_ids` clauses
that already ship. The "cheaper because it fits the types-only grammar" argument for suffixed types
evaporates, because the thing it was cheaper *than* is a small, precedented, copy-the-neighbour
edit.

---

## 4. The decision: direction-as-**attribute** (recommended) vs direction-**suffixed types**

Two ways to make direction routable. They are mutually exclusive for the same dimension.

### Option A — Direction as a filterable attribute *(RECOMMENDED)*

Promote direction from summary-word to a structured `context['direction']` key, add a `directions`
clause to the `event_filter` grammar, and expose one **Direction** filter control on the route form.
Type identity stays clean: `intake.call_received` remains one type; direction is orthogonal.

Operator experience: *"route type = `intake.call_received`, Direction = `inbound`, → on-call
destination."* "All inbound intake events" is expressible as one filter across many types.

### Option B — Direction-suffixed types

Bake direction into the type key: `call.inbound.ended`, `call.outbound.ended`,
`call.inbound.transcribed`, `call.outbound.transcribed`, … Routing works with the *type* clause
alone — but the catalog and the operator's checkbox list carry the product of (lifecycle stages ×
directions).

### Trade-off table

| Axis | A — attribute | B — suffixed types |
|---|---|---|
| Grammar change | +1 clause in `matches()`, copy of `categories` (~8 lines) | none |
| Catalog size | +0 types (direction is data) | **×2 per lifecycle stage** — 4 types for today's pair, 6–8 if the family grows |
| Operator UI | +1 "Direction" filter input, next to Categories/Client IDs | checkbox list doubles; "any inbound" = check-N-boxes, and re-check when new ones are added |
| "Route all inbound events" | one filter, composes across types | **not expressible** in one rule |
| Type identity | stays semantic (`call.transcribed` = one concept) | cross-cutting dimension welded into identity (the anti-pattern `category`/`client_id` were *designed to avoid*) |
| Generalizes to email intake | **yes** — same `direction` key routes `intake.email_*` (Email also has a `direction`; today's emit drops it, `EmailService.php:301`) | no — would need a parallel `email.inbound.*` explosion |
| Redaction safety | `'inbound'`/`'outbound'` are safe literals; pass `WikiRedactor` untouched (`SignalHub.php:79`) | n/a |
| Payload/consumer churn | additive; summary word can stay (tests stay green) | new type keys any consumer must learn |

**Decision: Option A.** Direction is the textbook case for an attribute — a small, closed,
cross-cutting enum (`inbound`/`outbound`) that applies uniformly across *many* event types (calls
today, emails tomorrow). Baking it into type suffixes is precisely the modelling mistake the
existing `categories`/`client_ids` filters were built to prevent, it multiplies the catalog and the
operator's cognitive load, and it *still* can't answer "route everything inbound" in one rule. The
only advantage B ever had — "fits the types-only grammar" — was predicated on a premise that was
already false when the bead was filed (§3).

---

## 5. Scope decision: build the **attribute**, defer the **lifecycle family**

Charlie's ask has two separable halves. They should be sequenced, not shipped together.

### 5a. The direction attribute — **worth it, but pull-based, not now**

Real routing value (inbound vs outbound to different destinations), tiny precedented change (§6),
dormancy-safe. **But** there is **no live consumer** — the only seeded route is the disabled legacy
operator webhook, filtered to `agent.flag_attention` (`DefaultSignalRoutes.php:101`). Building
routing plumbing with zero route that uses it is motion without progress.

**Trigger to build:** the first time someone wants a route that treats inbound and outbound calls
differently — most likely inside **psa-xcyo** (the intake front-door), which is the natural first
consumer of intake signals. Build it *with* that consumer so the change is exercised end-to-end,
not speculatively. The shovel-ready spec is §6 so the future implementer starts from a plan, not a
blank page. This satisfies the bead's real charter: *file the decision so it is not silently lost.*

### 5b. The `started` / `connected` lifecycle family — **defer (YAGNI), do not build speculatively**

`call.started`/`call.connected` would require **net-new emit points** in the Plivo *answer* /
*ringing* paths (`PlivoWebhookController::handle()` `:351` and the ringing tail `:358`) — today
those branches emit nothing. That is new surface on the **native webhook path**, which the psa-ip15
invariant guards hardest (parallel-plane: "zero native-path behavior change; SignalHub::emit
chokepoint only"). We would take that risk for a capability **no consumer wants**:

- Charlie's default trigger is `call.transcribed` — already shipped.
- psa-xcyo routes on **transcription / voicemail**, i.e. *after* a call ends — it never needs a
  call-*start* event.
- The shipped `intake.call_received` (semantically `call.ended`) already answers "a call happened."

A call-start event only earns its keep with a **presence/live** consumer (a wallboard, "agent is on
a live call" state, real-time barge). None is on the roadmap. **Defer** until one is real. If/when
built, the same **attribute** decision (§4) applies to it verbatim — the family is
`intake.call_started` + direction attribute, never `call.inbound.started`.

**One naming note for that future:** the shipped events use the `intake.` namespace
(`intake.call_received`, `intake.call_transcribed`), whereas Charlie's sketch used a `call.` prefix.
Keep `intake.` — these are intake-plane events and the namespace already groups them in the
operator's picker. Renaming now would churn the catalog and any (future) seeded routes for zero
functional gain. Decide the family's names when the family is actually built.

---

## 6. Recommended change set (shovel-ready — for when a consumer lands, §5a)

Precise, additive, dormancy-preserving. Six touch-points, each mirroring existing code. **Do not
implement under psa-cdv3** (decisions-only, mirroring the psa-vei doc); this is the plan the
follow-up build bead executes.

1. **Allowlist** — `SignalHub.php:13` — add `'direction'` to `ALLOWED_CONTEXT_KEYS`. Without this,
   `sanitizeContext()` (`:70–93`) silently drops it. (1 line.)

2. **Emit — calls** — `PlivoWebhookController::emitCallReceived()` `:64` — add
   `'direction' => $call->direction?->value ?? 'inbound'` to the context array. **Keep** the
   existing summary word so `IntakeCallEmissionsTest.php:68,94,111` stay green (additive, not a
   rewrite). `TranscriptionService.php:323` — add the same `'direction' => $call->direction?->value`
   to the `intake.call_transcribed` context.

3. **Grammar** — `SignalRouter::matches()` `:90` — add a `directions` clause after `client_ids`,
   a direct copy of the `categories` block:
   ```php
   if (array_key_exists('directions', $filter)) {
       $direction = $event->context['direction'] ?? null;
       if ($direction === null || ! in_array((string) $direction, $filter['directions'], true)) {
           return false;
       }
   }
   ```

4. **Validator** — `AlertsHubController::validatedRoutePayload()` `:373` — add
   `'event_filter.directions' => ['nullable']`, then (`:397`) normalize + set
   `$filter['directions']` via the existing `normalizeTextList()` helper (mirror the `categories`
   block at `:387–390`). Optionally constrain values to `['inbound','outbound']`.

5. **Operator UI** — `_form.blade.php` `:56` — add a **Direction** control next to Categories /
   Client IDs: two checkboxes (Inbound / Outbound) or a small multiselect bound to
   `event_filter[directions][]`, pre-checked from `$route->event_filter['directions']`.

6. **Tests** — extend `tests/Feature/Signals/IntakeCallEmissionsTest.php`: assert
   `context['direction']` is `'inbound'` / `'outbound'` on both events (keep the existing summary
   assertions). Add a `SignalRouterTest` case: a route with `event_filter.directions=['inbound']`
   matches an inbound call event and **skips** an outbound one. This is the noise-guard proof that
   the new clause filters, not just stores.

**Invariants preserved:** parallel-plane (emit is still the only chokepoint; native webhook/transcribe
behavior byte-unchanged — context gains a key, control flow does not); dormant (no seeded route uses
`directions`; catalog gains **no** type); redaction (`'inbound'`/`'outbound'` are literals that pass
`WikiRedactor::scan`). Rough size: ~1 + 2 + 8 + 5 + ~10 blade + tests ≈ **a half-day PR**.

**Free generalization:** once `direction` is on the allowlist and in the grammar, wiring it into the
`intake.email_*` emits (`EmailService.php:301`, where `Email` already has an
`EmailDirection` — see `IntakeEmailEmissionsTest.php:44`) is a one-line-per-emit follow-on. Direction
becomes a uniform intake filter across channels — the payoff the attribute model buys and the
suffixed model cannot.

---

## 7. What we explicitly do **NOT** build now (anti-scope-creep)

- **No `call.started` / `call.connected` / `call.answered` emission.** No consumer; net-new native-path
  surface; defer to a real presence/live feature (§5b).
- **No catalog rename** `intake.call_* → call.*`. Namespace churn for zero function (§5b).
- **No direction-suffixed types**, ever, for this dimension (§4).
- **No route seeding.** Everything stays dormant until an operator (or psa-xcyo) authors a route.
- **No summary-word removal.** It stays as human-readable prose; the attribute is added *alongside*.
- **No new grammar operators** (ranges, negation, boolean combinations). `directions` is a plain
  set-membership match like `categories`. If a future need appears, that is its own design.

---

## 8. Open items for the plan (when §5a triggers)

1. **Value constraint:** should the validator hard-restrict `directions` to `{inbound, outbound}`,
   or stay free-text like `categories`? Lean **constrained** — it is a closed enum, and a typo'd
   `"outbund"` silently never matches.
2. **Unknown-caller calls:** `emitCallReceived` fires for callers with no `client_id`
   (`context` ends up `{}` after sanitize, since a null `client_id` is non-scalar and dropped,
   `SignalHub.php:75`). Direction is still a scalar, so it survives — a nice side benefit: these
   calls gain a routable dimension they otherwise lacked. Confirm a `directions`-only route catches
   them.
3. **Missing direction:** `$call->direction` is nullable at the DB layer. §6 defaults null →
   `'inbound'` to preserve today's ternary behavior (`PlivoWebhookController.php:63`). Confirm that
   is the desired default (vs omitting the key so it matches no direction filter).

---

## 9. Recommendation summary (for the bead)

1. **Model:** direction is an **attribute**, not a type suffix. (Option A, §4.)
2. **The bead's premise is stale:** the grammar already does attribute matching (category / priority
   / client_id, since #117 on 2026-07-02) — adding `direction` is a fourth precedented clause, not a
   grammar expansion. (§3.)
3. **Scope now:** **nothing to build under psa-cdv3** — it is decisions-only. Build the direction
   attribute (§6, ~half-day) **pull-based**, alongside the first consumer that needs to route inbound
   vs outbound — most likely **psa-xcyo**.
4. **Defer:** the `started`/`connected` lifecycle family until a live/presence consumer exists; the
   shipped `intake.call_received` (really `call.ended`) + `intake.call_transcribed` cover every
   consumer on the roadmap today. (§5b.)
5. **Bonus:** the attribute generalizes direction to email intake for near-free (§6).

A follow-up implementation bead carries the §6 change set, blocked on Charlie's acceptance of this
recommendation **and** a concrete first consumer.

---

## 10. Appendix — the emit → route → deliver chain, proven from source

```
emit site (observer / controller / service)
  → SignalHub::emit(typeKey, entity, summary, context)      app/Services/Signals/SignalHub.php:24
      • SignalEventTypes::has(typeKey)  — unknown types dropped                 :32
      • sanitizeSummary()   — WikiRedactor scan + 500-char cap                  :61
      • sanitizeContext()   — ALLOWLIST [category, priority, client_id,         :70
                              destination_id]; everything else dropped          :13–18
      • SignalEvent::create(...)   context stored as JSON column
             (migration 2026_07_02_100003_create_signal_events_tables.php)
      → RouteSignalEvent::dispatch(event->id)                                   :48
  → SignalRouter::route(event)                              app/Services/Signals/SignalRouter.php:17
      • SignalEventTypes::routable(type)  gate                                  :19
      • per enabled route: matches(route, event)                               :62
            types (:67) | categories (:71) | min_priority (:78) | client_ids (:85)
            ← ADD directions here (§6.3), mirroring categories
      • suppression: causal-depth>3 | >60/type/hr | cooldown                   :106
      → createDelivery → DeliverSignal::dispatch                               :137,158
```

Operator authoring path (where a Direction control lands, §6.5):
```
GET/POST settings/alerts/routes
  → AlertsHubController::validatedRoutePayload()   app/Http/Controllers/Web/AlertsHubController.php:364
        event_filter.types (required) | .categories | .min_priority | .client_ids
        ← ADD .directions here (§6.4)
  → view resources/views/settings/alerts/routes/_form.blade.php
        Events checkboxes :19–45 | Categories/Client IDs/Min Priority :50–64
        ← ADD Direction control here (§6.5)
```

Structured direction is already on the model at every emit site:
`PhoneCall.direction : CallDirection` (`app/Models/PhoneCall.php:49`),
`Email.direction : EmailDirection` (used in `tests/Feature/Signals/IntakeEmailEmissionsTest.php:44`).
The data exists; today it is flattened to prose (`PlivoWebhookController.php:63`) or dropped
(`EmailService.php:301`) on the way into the signal. The recommended change simply stops discarding
it.
