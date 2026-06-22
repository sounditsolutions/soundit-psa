# Prospect Intake — Design Spec

**Date:** 2026-06-22 · **Rev:** 2 (revised after a second review panel: arch / skeptic / MSP / UX)
**Status:** Draft for operator review
**Note on citations:** `file:line` references were verified by the review panel against `feat/prospect-intake`, but line numbers drift — treat them as "this symbol, near here," and re-confirm at implementation time.

## Problem

A new or potential client calls or leaves a voicemail. They don't exist in the PSA yet. An inbound call from an unrecognized number logs as a `PhoneCall` with `client_id`/`person_id` null and shows "—"; **a ticket cannot be created from it**, because every user-facing create path requires an existing client (`TicketStoreRequest` and `CallController::storeTicket` both `client_id => required|exists:clients,id`). New-business inquiries have nowhere to land.

## Goal

Let a tech capture and track a request from a not-yet-customer in one fast, dedup-safe move that reuses the existing ticket/call stack, with a clean promotion to a full client — **without** exposing a non-customer to the client portal, the billing/integration sweeps, or the AI pipeline.

## Model — "Prospect" as a client lifecycle stage (two orthogonal axes)

A prospect is a real `Client` row, so `client_id` is stable from first contact and **no history is migrated on conversion**. Matches Autotask Company Type (Lead/Prospect/Customer) and HaloPSA (Prospective).

- Add **`Client.stage`** = `App\Enums\ClientStage { Prospect, Active }` (room for `Lost`/`Disqualified` later — trivial with the string-backed approach below). New `App\Enums\ClientStage` does not exist yet; create it.
- Keep **`Client.is_active`** with its *current* meaning: operational on/off for a real client (suspended / non-pay / churned). **`stage` and `is_active` are orthogonal questions.** A prospect is `stage=Prospect, is_active=true` (a live not-yet-customer, not a churned client).

### Scope strategy (resolved — do NOT redefine `scopeActive`)

The panel split here; the resolution is the skeptic's + architect's, because redefining `scopeActive()` over-hides:

- **Leave `Client::scopeActive()` exactly as-is** (`where('is_active', true)`, `Client.php:187`). The **client-management list/search** (`ClientController::index`, `Client.php:46`) rides it and **must keep showing prospects** (else a freshly-created prospect vanishes — no way to view its tickets or click Convert). Prospects are `is_active=true`, so they remain visible there. Add a stage filter chip ("Prospects / Active / All").
- **Add `Client::scopeOperational()` = `where('stage', Active)->where('is_active', true)`** — the canonical "real customer" predicate. Point the **exclude-prospects** sites at it.
- **Audit raw readers, not just the scope.** A `scopeOperational` change reaches `Client::active()` callers but NOT the ~29 direct `->where('is_active', true)` sites — which are the dangerous ones. The spec's implementation step must enumerate and repoint these to `->operational()`:
  - **Pickers** (must exclude): `TicketController.php:56`, `CallController.php:82`, `PersonController`, `AssetController` create dropdowns.
  - **Billing roll-up** (direct `where`, inflates real invoices): `BillingService.php:161` (`countResellerLicensesByType`), `:514`, `:590`.
  - **Integration license-sync fleet** (all `whereNotNull('<vendor>_id')->where('is_active', true)`): CIPP (×5), Ninja backup (`NinjaBackupSyncService.php:196`), Zorus, ControlD, Printix, Servosity, Mesh, Comet, AppRiver, Huntress (`CippLicenseSyncService.php:22`, etc.). A prospect carrying any vendor ID would be swept.
  - **Inbound Huntress alert resolver**: `HuntressService.php:310/318` (a prospect could otherwise own a security incident + ticket).
  - **Billing auto-match** (the genuinely dangerous one the spec already found): `StripeSyncService.php:42`, `QboSyncService.php:47` — these are `Client::active()` so a `scopeOperational` repoint covers them; name-match would otherwise silently fuse a prospect to a real customer's billing account.
- **Reporting:** any customer-count / revenue KPI must use `scopeOperational`/`stage=Active`, never raw `is_active` (else prospects count as customers). Test that a prospect does not increment the customer count.
- **Note (don't over-claim):** `RecurringInvoiceProfile::scopeDue` gates on the *profile* + *contract*, never on Client `is_active`/`stage`. So "operational client" is **not** what keeps prospects out of the actual billing run — a prospect with an Active contract + due profile would invoice. v1-safe only because **convert is what creates the contract**, so a prospect never has one. State this explicitly.

## No separate `opportunities` table (deliberate)

At small-MSP scale a support ticket *is* the opportunity: a new-business inquiry becomes a ticket; an upsell to an existing client is just another ticket — so `stage` is never asked to express "Active + open deal" and the multi-deal problem never arises. **Known v1 limitation (state it):** because dedup attaches a repeat caller to the existing Active client, a current customer's *new* sales inquiry is captured as a ticket with no deal/opportunity marker — there is no pipeline for upsells in v1. The additive future path (if ever wanted) is a `Sales/Quote` ticket type + value field reusing the ticket list — not a separate object.

**Also state:** a prospect's ticket is, by design, an **inert ticket** — no triage, no SLA, no notifications, no mining (see Gates) — until conversion. This is intended, not a bug; a future reader should not "fix" the missing triage.

## Capture flow (human-gated, search-existing-first)

No silent auto-creation. Creation is always a deliberate, dedup-checked staff action — otherwise the queue becomes a spam folder and the `Authenticatable` `people` table fills with junk.

1. **Queue, don't invent.** Unrecognized inbound calls/VMs keep logging as `PhoneCall` (null client/person). They surface for triage via a new **"Unknown caller"** filter. **Predicate fix:** the existing `PhoneCall::scopeUnfollowedUp` (`PhoneCall.php:124`) is status-scoped (`status IN (Missed, Voicemail) AND followed_up_at IS NULL`), so it would **hide an *answered* unknown caller** (a live new-business call, status Completed/InProgress). The "Unknown caller" facet must surface unknown callers **regardless of answered/missed**: `client_id IS NULL AND followed_up_at IS NULL`, independent of `scopeUnfollowedUp`. Host it as a new option in the existing Call-Log status filter (`getRecentCalls` needs a new filter branch, `PhoneCallService.php:472`), not a second queue.
2. **Search-existing-first is the spine, and it is net-new UI.** The lookups the v1 draft cited do **not** serve this: `getCandidateCallers()` (`PhoneCallService.php:502`) is phone-exact only; `ContactResolver` operates on a *ticket's text*, not a caller's number, and isn't wired to the call page. So a real **client name/company search control must be built** on the unresolved-caller path (call-detail page and the create-ticket-from-call form), reusing the existing **`Client::scopeSearch()`** (`Client.php:192` — name/phone/email LIKE). It is the **first** control; results list existing clients (incl. prospects) to attach to; **"+ New client '[CallerID]'"** renders **below** results as the labeled fallback, only when nothing matches. Specify the zero-result state ("No match — + New client '[name]'") and the one-match suggest ("Looks like [Client] — attach?").
   - **This search box — not phone-dedup — is the real mitigation for the headline duplicate case** (an existing client calling from a *new* number is a phone-miss; only the human searching and attaching catches it). Emphasis matters: the create-new control must never be the first thing rendered on an unresolved call.
3. **One-action provision (only on the "+ New client" fallback).** Creates atomically: `Client(stage=Prospect)` + a `Person` (the caller) + a `Ticket`. **The Person's `phone` (or `mobile`) MUST be set to `PhoneNumber::normalize(from_number)`** — otherwise the next call from that number won't match and dedup silently fails. The ticket is seeded from the call; see the AI gate below for how (no LLM pre-fill for prospects).
4. **Dedup = always confirm (resolved).** Before any attach, normalize `from_number` (`PhoneNumber::normalize`, `PhoneNumber.php:12`) and look for an existing Person with that number (a small matcher must be **written** — none exists that maps a raw number to a Client/prospect; `getCandidateCallers` returns People and only helps if a Person already carries the number). Asymmetry: **phone-exact match → confirm** ("This number is already on [client/prospect X] — attach?"); **name/fuzzy match → suggest only, never auto-attach** (a wrong auto-attach to a real client is the same failure class as the Stripe fusion). Repeat callers thus stack on one prospect.
5. **Spam path.** Unknown-caller rows get a one-click **"Not a lead / dismiss"** (sets `followed_up_at`, creates nothing) beside the existing inline Block-caller (`show.blade.php:470`). A dismissed call must remain findable in the full Call Log (dismiss removes it from the facet, not from history).

## Gates — the safety mechanisms (all keyed on the client's `stage`)

These are first-class and must be **complete** — the v1 draft closed only a fraction of each.

### Portal (security — was the ship-blocker; the 2-gate fix was incomplete)

`portal_enabled` is flipped true, or a portal session obtained, by **~7 paths**, most public, none stage-aware today:
- `PortalAuthController::verifyAccess` flips `portal_enabled=true` (`:208`); guard checks `is_active`+`portal_enabled=false`, not stage. Public (`routes/portal.php:37`).
- **Password-reset chain (fully public, the scariest):** `sendResetLink` finds a Person by email alone (`:75`); `resetPassword` sets the password **and logs them in** (`:119`) without re-checking `portal_enabled`. So even with `password=null` provisioning, the public reset flow grants password + session.
- `login`'s `attempt([... 'is_active'=>true])` (`:38`) — `PortalAuthenticate` does NOT run on `POST /login`.
- Staff `PortalManagementController::invite` (`:57`, mints token + emails set-password link), `toggle` (`:96`), `impersonate` (`:161`) — only a `client_id` match, no stage check.
- `canHavePortal()` does not help — a seeded caller defaults `person_type=user`, which returns true.

**Fix (durable, single mechanism — preferred):** add `Person::canAccessPortal()` = `portal_enabled && is_active && client.stage === Active`, and **route every portal entry point through it** — ideally a constraint/global scope on the portal user provider + password broker so `login`, `sendResetLink`/`resetPassword`, and `verifyAccess` only ever see eligible People; plus an explicit stage check in the staff `invite`/`toggle`/`impersonate` actions (403/no-op for a prospect's contact); plus add `stage` to `PortalAuthenticate.php:16` as defense-in-depth. Provisioning invariant: **no path sets `portal_enabled=true` or grants a session for a Person whose client is `stage=Prospect`**; provisioned prospect People have `portal_enabled=false`, `password=null`. **Tests must cover `verifyAccess`, the reset-link chain, `login`, and staff `invite()` — not just `sendAccessLink` + `attempt()`.**

### AI pipeline (cost + non-customer PII) — two gated jobs AND the provision-time pre-fill

- **Provision-time pre-fill is a SECOND LLM path the v1 draft missed.** `buildTicketSuggestions` (`CallController.php:477`, called at form render `:248`) sends the call transcript to the LLM *before any ticket exists*. For prospect provisioning, **skip the AI pre-fill** (use `legacySubject`/defaults) or gate `buildTicketSuggestions` on stage. This is the actual "transcript-to-LLM during provisioning" path.
- **Triage + notifications:** `TicketObserver::created` (`:24`) calls `notifyTicketCreated` then dispatches `RunTriagePipeline` (`:35`), unconditionally (the recursion guard keys on `created_by`, which is a human tech for a prospect ticket — so triage *would* fire). Gate both on `stage=Active` (cleanest: early-out inside `RunTriagePipeline`). **Rationale correction:** `notifyTicketCreated` is *staff*-facing (`NotificationService.php:31` queries Users), so gating it is noise-reduction, not a privacy win — but still wanted.
- **Wiki mining is a SEPARATE hook.** It fires in `TicketObserver::updated` (`:63`) when a ticket goes terminal-with-resolution — a single gate in `created()` misses it. Early-out inside `MineTicketKnowledge` on `stage=Prospect` (covers both create- and update-triggered paths in one place).

### Billing / integrations / SLA / sync

- **Billing auto-match + the kind-(b) audit:** covered above (`scopeOperational` repoint of the ~29 raw readers, incl. Stripe/QBO).
- **SLA — no special-casing needed (CONFIRMED end-to-end).** `due_at`/`response_due_at` derive only from a contract (`TicketService::createTicket:48`); every breach path guards on non-null (`Ticket::scopeOverdue` `whereNotNull`, `checkSlaBreach` early-return, `RecalculateTicketSla` scoped to `whereNotNull('contract_id')`). A contract-less prospect ticket gets none for free.
- **Outbound integration sync — inert (CONFIRMED).** `Client` has no observer (`AppServiceProvider.php:114` registers none for Client), `ClientService::createClient` dispatches nothing, and every scheduled reconciler is query-gated on a non-null integration ID a prospect lacks. (Caveat: don't cite ID-gating as the *Stripe/QBO* mechanism — those are manual-only and select `Client::active()->whereNull(...)`; the `scopeOperational` repoint is what excludes prospects there.)

## Convert to client (guided, history-preserving, carries the request)

A **new controller action** (the existing `ClientController::update`/`ClientUpdateRequest` have no `stage` field, and `stage` must not be mass-fillable). It:
- Flips `stage` Prospect→Active (`client_id` never changes → all calls/tickets/notes stay attached; zero migration), and **re-enables** triage/notifications/mining for future tickets.
- **Defines the originating ticket's fate (decide in v1):** the prospect's existing ticket(s) stay open and, on conversion, become normal Active-client tickets (triage may run on the next relevant event; the spec should state whether triage is back-filled or only applies going forward — recommend: going forward only, to avoid a burst of retroactive AI runs).
- Lands on a success screen that **echoes the prospect's open tickets + captured request/notes inline** (the owner who closes the deal days later needs the original ask in front of them) and **prompts** (does not auto-run) the real onboarding: assign `primary_tech_id`, create a `Contract`, provision RMM/M365. Logs who/when.

## Wording

Internal stage value `Prospect`; the status **badge** reads "Prospect"; the create **control** reads **"+ New client"** (it creates a client — names what it does); the queue facet reads **"Unknown caller."** The "Prospect" badge must appear on the client/ticket header **immediately** after "+ New client" so the relabel reads as one continuous story.

## Migration (corrected — string column, not ENUM)

This repo uses zero `$table->enum()`; every enum is a `string` + PHP backed-enum cast, and tests run sqlite `:memory:` (no native ENUM). So:
- `$table->string('stage')->default('active')->index();` in a single migration — `NOT NULL DEFAULT 'active'` fills existing rows atomically on MariaDB *and* sqlite; **no separate backfill-then-tighten step.** `down()` = `dropColumn('stage')`.
- Add `'stage' => ClientStage::class` to `Client::casts()`; create `App\Enums\ClientStage`.
- `ClientFactory` gets a `prospect()` state for tests (it doesn't set `is_active` today and needs no other change).

## Phasing

- **v1 (this spec):** `ClientStage` + `scopeOperational` + the full kind-(b) repoint; the complete portal gate; the three AI gates (pre-fill + triage/notifications + mining); the search-first client-search control + confirm-dedup (with the written phone matcher + normalized-number seeding); the "Unknown caller" facet + reversible dismiss; guided history-carrying convert; reporting rule.
- **v2:** AI Intake Card (auto-structure the voicemail transcript) — deferred until the AI gates above ship.
- **v3 (only if ever needed):** `Sales/Quote` ticket-type pipeline reporting. No separate object.

## Key risks & mitigations

| Risk | Severity | Mitigation |
|------|----------|------------|
| Prospect contact gains portal access (self-invite, reset chain, staff invite, login, verifyAccess) | **Blocker** | `Person::canAccessPortal()` routed through all ~7 paths + provider/broker constraint; provisioning invariant; tests on reset-chain + invite + verifyAccess + login |
| Duplicate client (existing client from a new number) | High | Search-existing-first client-search control is the spine; create-new is the subordinate fallback |
| Repeat caller mints a duplicate prospect | High | Confirm-dedup on the **normalized** seeded number; written matcher |
| Prospect PII to the LLM (provision pre-fill, triage, mining) | High | Gate `buildTicketSuggestions` + `RunTriagePipeline` (created) + `MineTicketKnowledge` (updated) on stage |
| Prospect swept into billing roll-up / license syncs (~29 raw `is_active` readers) | Major | `scopeOperational` + enumerated repoint of raw readers |
| Prospect fused to a real customer by Stripe/QBO auto-match | Major | `scopeOperational` on the two auto-matchers |
| Prospect counted as a customer in KPIs | Major | Metrics use `stage=Active`; test |
| Spam/robocalls pollute queue + People | Major | Human-gated creation + one-click dismiss; no auto-create |
| Convert loses the captured request | High | Success screen echoes the prospect's tickets/notes |

## Testing (feature tests)

- **Portal invariant:** a provisioned prospect's `Person` is portal-inert — `portal_enabled=false`, `password=null`; and **each** of `login`, `sendResetLink`/`resetPassword`, `verifyAccess`, and staff `invite()` is rejected/no-op for a prospect's contact.
- **Scope:** a `stage=Prospect` client is excluded from `scopeOperational` (pickers, billing roll-up, license syncs, Stripe/QBO auto-match) but **included** in the client-management list (`scopeActive`); a suspended (`stage=Active, is_active=false`) client is excluded from both.
- **AI gates:** prospect provisioning makes **no** LLM call (no `buildTicketSuggestions`); a prospect ticket dispatches no `RunTriagePipeline` and no `notifyTicketCreated`; a prospect ticket resolved triggers no `MineTicketKnowledge`. SLA fields stay null.
- **Dedup:** provisioning seeds `Person.phone = PhoneNumber::normalize(from_number)`; a second call from that number resolves to the existing prospect (no duplicate); a new-number call from an existing client surfaces that client in the search control.
- **Convert:** flips `stage`→Active, preserves `client_id` + all attached history, re-enables triage for future tickets, and the success screen surfaces the prospect's open tickets.
- **Reporting:** creating a prospect does not increment the customer count.

## Open questions (for the operator)

1. Final wording ("Prospect" badge vs. plainer).
2. `Lost`/`Disqualified` substates in v1, or defer? (Recommend defer.)
3. v1 scope: the complete portal surface + the ~29-site `is_active` audit are non-negotiable safety, so v1 is larger than the original "core." Acceptable, or phase the lower-risk billing/license-sync repoints (kind-b, non-Stripe/QBO) into a fast-follow while shipping the security gates + capture/convert in v1?
