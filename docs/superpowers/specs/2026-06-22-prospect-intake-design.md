# Prospect Intake — Design Spec

**Date:** 2026-06-22
**Status:** Draft for operator review
**Author:** Mayor (with a 4-perspective review panel: architecture, MSP-product, UX, skeptic)

## Problem

A new or potential client calls or leaves a voicemail. They don't exist in the PSA yet. Today an inbound call from an unrecognized number logs as a `PhoneCall` with `client_id`/`person_id` null and shows "—" on the Call Log; **a ticket cannot be created from it**, because every user-facing create path requires an existing client (`TicketStoreRequest.php:24` and `CallController::storeTicket` both `client_id => required|exists`). New-business inquiries have nowhere to land and can't be tracked — the tech's only option is to first create a full Client + contact through normal CRUD.

## Goal

Let a tech capture and track a request from a not-yet-customer in one fast, dedup-safe move that reuses the existing ticket/call/notification stack, with a clean promotion to a full client — and **without** polluting billing, integrations, the client portal, or AI triage with non-customers.

## Model — "Prospect" as a client lifecycle stage (two orthogonal axes)

A prospect is a real `Client` row, so `client_id` is stable from first contact and **no history is ever migrated on conversion**. This reuses the whole stack rather than building a parallel lead/ticket path, and matches how Autotask (Company Type: Lead/Prospect/Customer) and HaloPSA (Prospective status) model it.

- Add **`Client.stage`** enum: `Prospect | Active` (room to add `Lost`/`Disqualified` later). `NOT NULL`, DB default `active`, indexed.
- Keep **`Client.is_active`** with its *current* meaning: the operational on/off switch for a real client (suspended / non-pay / churned). **`stage` and `is_active` are orthogonal** — different questions, not two copies of one field. A prospect is `stage=Prospect, is_active=true` (a live not-yet-customer, *not* a churned client).
- The canonical "operational client" predicate becomes **`stage = Active AND is_active = true`**. Introduce `Client::scopeOperational()` (or redefine `scopeActive()` in one place) and point the meaningful call sites at it.

### No separate `opportunities` table (deliberate)

The review panel recommended a future `opportunities` table on the grounds that `client.stage` can't model an *Active* client who *also* has a new deal. **At small-MSP scale this is YAGNI:** a new-business inquiry becomes a *ticket*, and an upsell to an existing client is just *another ticket*, so `stage` is never asked to express "Active + open deal" and the multi-deal problem never arises. **The ticket is the opportunity.** If lightweight pipeline reporting is ever wanted, the additive path is a `Sales/Quote` ticket type + an optional value field that reuses the ticket list as the pipeline view — not a separate object. Not built now.

### Why not the alternatives

- *Nullable client on tickets:* the DB already allows it, but it pushes "no client" state into dozens of downstream consumers and gives prospects no home of their own.
- *Separate `leads` table:* cleaner separation, but forces a parallel ticket-attachment path and duplicates ticket/note/call machinery for a solo operator who lives in tickets. Its real wins — keeping non-customers out of the portal, billing, and triage — are captured here by the **gates** below instead.

## Capture flow (human-gated, search-existing-first)

No silent auto-creation. Auto-minting a Client+Person+Ticket per inbound voicemail would turn the queue into a spam folder and the `people` table (which is `Authenticatable`) into junk. Creation is always a deliberate, dedup-checked staff action.

1. **Queue, don't invent.** Unrecognized inbound calls/VMs keep logging as `PhoneCall` (null client/person) and surface in the **existing** "Needs Follow-Up" queue (`PhoneCall::scopeUnfollowedUp`) with a new **"Unknown caller"** facet (`client_id IS NULL AND followed_up_at IS NULL`). No second queue.
2. **Search-existing-first is the spine.** On the call-detail page and the create-ticket-from-call form, the unknown-caller path leads with the lookup that already exists on that page — call-history-by-number, `getCandidateCallers()`, and `ContactResolver` + `Person` email/phone matching — asking "this number/name matched these — use one?" **"+ New client '[CallerID]'"** appears only as the labeled fallback when nothing matches. (UX: this single inversion is what neutralizes the duplicate-client risk; the create control must never be the *first* thing a tech sees on an unresolved call.)
3. **One-action provision.** "+ New client" creates, atomically: `Client(stage=Prospect)` + a `Person` (the caller, seeded from `from_number`) + a `Ticket` pre-filled from `from_number` and the call's `call_summary`/transcript via the existing `buildTicketSuggestions`. Lands the tech on the ticket.
4. **Dedup guard (in v1, not deferred).** Before minting, normalize `from_number` (`App\Support\PhoneNumber`) and run the existing match machinery; if it resolves to an existing client or prospect, **attach to that** rather than create a duplicate. Repeat callers stack on one prospect.
5. **Spam path.** Unknown-caller rows get a one-click **"Not a lead / dismiss"** (sets `followed_up_at`, creates nothing) alongside the existing inline Block-caller, so junk leaves the queue without leaving a record.

## Gates — the critical safety carve-outs (all keyed on `stage = Prospect`)

These are what make prospect-as-Client safe; they are first-class, not afterthoughts.

- **Portal (security — was a ship-blocker).** A prospect's `Person` must never reach the client portal. The public self-service flow finds any `Person` with `is_active=true AND portal_enabled=false` and emails a signed access link that, when followed, sets a password and flips `portal_enabled=true` (`PortalAuthController::sendAccessLink` / `verifyAccess`). So `portal_enabled=false` alone is **not** enough. Provisioning sets `portal_enabled=false` + `password=null`, **and** `sendAccessLink` + `PortalAuthenticate` are gated so a contact whose client is `stage=Prospect` cannot request or obtain portal access. *(Invariant + feature test.)*
- **AI triage / notifications / wiki mining (cost + privacy).** `TicketObserver` dispatches `RunTriagePipeline` on *every* ticket create. Gate triage, new-ticket notifications, and wiki mining on `stage=Active`, so a prospect's transcript/PII is not auto-sent to the LLM and tokens aren't burned pre-conversion. Conversion re-enables them.
- **Billing auto-match.** Exclude `stage=Prospect` from `StripeSyncService::autoMatchClients()` and `QboSyncService` (via `scopeOperational`), so a prospect named like a real customer isn't silently fused to that customer's billing account.
- **SLA — no special-casing needed.** Deadlines (`due_at`/`response_due_at`) derive solely from a contract (`TicketService::createTicket`), and every breach path guards on those being non-null; a contract-less prospect ticket gets no deadlines for free.
- **Outbound integration sync — none fires.** `Client` has no observers and `createClient` dispatches nothing; every reconciler (Ninja/Tactical/CIPP/Comet/Huntress/QBO/Stripe) is gated on its integration-ID being non-null, which a prospect lacks. Documented as an invariant; `scopeOperational` keeps prospects out of the sweeps regardless.

## Convert to client (guided, history-preserving)

"Convert to client" on a prospect flips `stage` Prospect→Active. Because `client_id` never changes, all attached calls/tickets/notes stay put — zero data migration. The convert action then lands on a success screen that **prompts** (does not auto-run) the real onboarding steps using existing building blocks: assign `primary_tech_id`, create a `Contract`, provision RMM/M365. Who/when is logged for reporting. Conversion re-enables triage/notifications for future tickets.

## Wording

Internal stage value stays `Prospect` (accurate, industry-standard, operator's preference). In the UI, "Prospect" appears where it's a *status* badge; the create control reads **"+ New client"** / "Log as prospect," and the queue facet reads **"Unknown caller"** (the term the Call Log already uses). Trivially adjustable; operator's call.

## Phasing

- **v1 (this spec):** `stage` enum + orthogonal-axes scope refactor (`scopeOperational` + call-site audit); human-gated, search-first capture + phone dedup; the four gates (portal / triage+notifications+mining / billing auto-match / documented sync invariant); the "Unknown caller" follow-up facet + dismiss; guided convert.
- **v2:** AI Intake Card — auto-structure the voicemail transcript into company / caller / wants / urgency and surface the dup-guard inline on the card. Deferred until triage is stage-gated (v1).
- **v3 (only if ever needed):** lightweight ticket-as-opportunity reporting (a `Sales/Quote` ticket type + optional value). No separate object.

## Key risks & mitigations (from the review panel)

| Risk | Severity | Mitigation |
|------|----------|------------|
| Duplicate client (existing client from a new number; repeat callers) | High | Search-existing-first spine + phone dedup before minting (v1) |
| Prospect contact self-invites to the client portal | Blocker | Stage-gate `sendAccessLink` + `PortalAuthenticate`; `portal_enabled=false`, `password=null` (invariant + test) |
| Every prospect ticket auto-runs AI triage (PII to LLM, token burn) | Major | Gate triage/notifications/mining on `stage=Active` |
| `is_active` has ~30 direct readers that bypass the scope (billing, syncs) | Major | `scopeOperational` = `stage=Active AND is_active=true`; audit + repoint the dangerous readers (esp. Stripe/QBO auto-match) |
| Spam/robocalls pollute the queue + People table | Major | Human-gated creation + one-click dismiss; no auto-create |
| Convert feels hollow / untrusted | Med | Guided convert: flip stage, then prompt tech/contract/provisioning |

## Testing (feature tests)

- Provisioning invariant: a provisioned prospect `Person` is auth-inert — `portal_enabled=false`, `password=null`; `attempt()` and `sendAccessLink` both fail for a prospect's contact.
- Scope: a `stage=Prospect` client is excluded from operational pickers and from Stripe/QBO auto-match; a suspended (`stage=Active, is_active=false`) client is also excluded (orthogonality holds).
- Triage/notifications: creating a prospect ticket does NOT dispatch `RunTriagePipeline` or notifications; SLA fields stay null.
- Dedup: a second call from a known prospect's number attaches to the existing prospect rather than creating a duplicate.
- Convert: flip `stage`→Active preserves `client_id` and all attached history; future tickets on the converted client DO run triage.

## Open questions (for the operator)

1. Final wording of the create control and the stage badge ("Prospect" vs. plainer).
2. Do we want `Lost`/`Disqualified` prospect substates in v1, or defer until there's a reason?
3. Should the dedup guard auto-attach silently, or surface "looks like an existing client — attach?" for confirmation? (Recommend: confirm, to stay consistent with the human-gated principle.)
