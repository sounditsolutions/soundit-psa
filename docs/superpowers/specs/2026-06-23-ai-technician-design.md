# The AI Technician — Design (v2)

- **Date:** 2026-06-23
- **Status:** Draft v2 — revised after the five-lens spec-review panel (see `2026-06-23-ai-technician-review-notes.md`). Supersedes v1.
- **Author:** Charlie + Claude (brainstorm + panel)
- **Forcing function:** Charlie travels Aug 1–15, 2026 (8h ahead, tight itinerary, availability minimized). Client commitments continue. Build window ~30 days.
- **But the product is general:** a **configurable coverage capability** any operator running Sound PSA can set up — not a one-off for this trip (see §2).

## 1. Problem & Goal

While an operator is away (or off-hours), Sound PSA should deliver excellent client service with thin human coverage. A named, **disclosed** AI Technician owns a ticket end-to-end inside the PSA — triage, gather context, draft the reply in house voice, propose a resolution and any actions — taking safe steps autonomously and routing anything client-facing or state-changing to a human for approval, with a deterministic emergency path and a full, immutable audit trail. The immediate need is Charlie's trip; the build is a durable scalability/quality-of-life capability.

## 2. Design Principles

1. **Configurable, not situational (NEW, governing).** Operators + roles, coverage windows (who is away when), the ordered escalation chain, availability signals, the AI-actor identity, SLA sources, thresholds, and per-action tiers are all **configuration/data — never hardcoded** to one operator's trip. "Charlie + Justin / Aug 1–15 / actor = Chet" is one *coverage profile*. Aligns with PRODUCT.md's standalone-first, cloneable thesis.
2. **Deterministic guardrails in the system of record.** The safety of state-changing actions never depends on AI judgment; a server-side gate classifies and enforces (§7).
3. **Disclosed and augmenting.** Never impersonate a human; the human stays the named relationship owner; the AI is explicitly an assistant (§6, §10).
4. **Fail-closed, killable, auditable.** Unreadable config, unverifiable approval, or an unknown action all *hold for a human*; a kill-switch stops everything; every action is attributable and immutable.

## 3. Locked Decisions

| Topic | Decision |
|-------|----------|
| Architecture | **PSA-native** pipeline; Teams as the human face. Not a Gas City instance (§3.1). |
| Autonomy | **Tiered**: AUTO on safe/reversible/draft; APPROVE on client-facing or state-changing; BLOCK on never. Enforced server-side on the *resolved* action (§7). |
| Disclosure | **Transparent.** Disclosed AI assistant = the configured AI-actor staff identity (e.g. "Chet"). Client may opt for a human. Never present as human. |
| Approval channel | **Hybrid.** Cockpit-first approval (authenticated PSA, mobile) + Teams one-way notify = the always-on core. In-Teams one-tap approval is a **parallel spike** behind a hard **~July 20 go/no-go**; fall back to cockpit+notify if not boringly reliable. |
| Trip autonomy posture | **Hold ALL substantive client sends** for the trip — only the templated acknowledgment is autonomous. (Resolves the v1 §12 vs §13.1 contradiction toward zero un-approved sends.) |
| Auto-execute | **Defer all** client-system action execution (quarantine/DNS/scripts) to post-trip; propose-only during the trip. |
| Emergency routing | AI *raises*; a deterministic non-AI sweep is the *relied-on* backstop. Configurable ordered escalation chain (Sound IT: Justin → Charlie; **no tertiary**). |
| SLAs | Read from existing **client-contract** SLA terms; the deterministic timer honors them. |
| AI-actor identity | **Reuse** the existing configurable "System User (AI Actor)" (the AI Triage integration setting; Sound IT = "Chet"). One AI staff identity across triage + technician. |
| Client scope | All clients (conservative tiers + per-client overrides are the safety net). |

### 3.1 Why PSA-native, not a Gas City instance
The top failure modes (wrong message / breaks a system) are best contained by deterministic guardrails in the system of record, not agent judgment; it must run unattended for two weeks on a mature, in-prod queue/scheduler (Gas City is a dev/coordination plane with known friction); the 30-day clock favors reusing the existing AI services + deploy pipeline; and baking it into the PSA keeps the capability in the standalone-first product. Gas City stays the tool for build-time orchestration and a future open-ended escalation escape-hatch (under the same guardrails).

## 4. Architecture (six components)

1. **The Loop (orchestrator).** Triggered off real seams — `TicketObserver::created` (mirroring `RunTriagePipeline::dispatch`) and a **new client-reply hook** in `EmailService::linkEmailToTicket` + the portal reply path (idempotency-guarded). RMM alert→ticket rides `created`. *No event bus is built* (not the house pattern). Defines coexistence with the existing `triage:review-open` cron (gate on it; don't double-process).
2. **The Pipeline (reuses existing AI).** triage (`Triage/*`) → gather cross-domain context (wiki/site-notes, assets, contract+SLA, history, prior-ticket pattern match) → a first-class **"can I own this?" classifier** (routine/known-runbook vs. hands-on/complex/novel) feeding the confidence signal → draft reply in house voice (`ReplyDraftService`) → propose resolution (`TicketResolutionDrafter`) → propose actions. Read-only context tools reuse the assistant tool-executor; **action proposal/execution is net-new** (§7).
3. **The TechnicianActionGate (safety chokepoint, NET-NEW — #1 build item).** The *sole* entry point for every side-effecting Technician action (send reply, set status, run script, integration action). The Loop holds **no** direct reference to `EmailService`/`TicketService`/`TacticalActionService` — only the gate (assert by test). For each action the gate: (a) classifies tier **server-side on the resolved action** (default-deny: anything outside the explicit AUTO allowlist is ≥APPROVE; BLOCK is a server denylist), (b) re-checks the kill-switch + per-client/per-action flags *immediately before* execution, (c) requires a valid signed approval grant for any non-AUTO action, (d) stamps the AI-actor identity + `actor_label:'ai-technician'`, (e) writes an **append-only** audit row (copy `TacticalActionLog`'s `updating`/`deleting` guards). Replaces v1's "audited action bus" language, which described a pattern, not a seam (`TacticalActionLog` is Tactical-only + immutable; `McpAuditLog` is mutable; replies/status had no actor label).
4. **The run-state machine (NET-NEW).** A persistent `technician_runs` record per ticket: `gathering → drafting → awaiting_approval → executing → done`. Approval waits are **persisted state, never a sleeping job**. Idempotency key (unique `ticket_id + action_type + content_hash`) prevents double-send under poll re-import / job retry. `awaiting_approval` lives on the *run*, **not** the `TicketStatus` enum (the cockpit derives a badge). A **dedicated queue + supervised worker** isolates Technician load from billing/email jobs.
5. **The Teams bridge + cockpit (hybrid).** Cockpit = a purpose-built mobile approval queue in the PSA (authenticated session = real approver identity), the always-on channel. Teams = one-way notify (digest, urgent emergency pings, reports) into the configured chat (Sound IT: "Day to Day") — `GraphClient.post()` is close to this today. In-Teams one-tap approval (Azure **Bot Framework** + Adaptive Cards + a **signed, identity-bound callback**) is the parallel spike behind the July-20 gate. Approvals (either channel) are **single-use, payload-bound, identity-bound signed grants** (reuse/extend `TacticalActionConfirmToken`); the model never executes on the strength of seeing "approved" in text.
6. **Audit + kill-switch + AI-actor identity.** Append-only audit (component 3). Global pause + per-client + per-action flags (`Setting`-backed; no deploy), re-checked at the chokepoint incl. in-flight; **fail-closed**. The AI-actor is the **reused** configurable System User (Chet); Technician notes are authored by that user, `WhoType::Agent` + an AI-authored marker (cf. `resolution_ai_drafted`).

## 5. Configuration Model (NEW — the generality layer)

All `Setting`/DB-backed, editable without a deploy:
- **Operators + roles** and an **ordered escalation chain** (N tiers; Sound IT: Justin→Charlie).
- **Coverage windows** (who is away when) + **availability** per operator: Teams presence (if `Presence.Read.All` consents) *advisory*, a manual "covering / not covering" toggle authoritative.
- **AI-actor identity:** the existing "System User (AI Actor)" selection.
- **Per-client overrides:** exclude entirely, always-route-to-human, client preference, tone.
- **Action tiers + ramp:** the AUTO/APPROVE/BLOCK map per action type (data, not code), plus per-action blast-radius caps for the eventual auto-actions.
- **Thresholds:** max-hold times, the deterministic emergency age/keyword rules, SLA source = client contract terms.
A *coverage profile* is just a named bundle of the above. The trip is one profile.

## 6. Per-Ticket Lifecycle

1. Inbound (email/portal/RMM) → ticket created.
2. Loop picks it up → triage + context, with **every untrusted client segment injection-fenced** in every prompt (§7).
3. **Emergency?** AI may raise it; independently, the deterministic sweep (§8) is the relied-on detector. On emergency → escalate per the configured chain + post a holding response. Stop autonomous progression.
4. Otherwise → **auto-acknowledge** the client (templated, non-substantive) with a realistic ETA + the AI-help choice (§9); internal triage notes; gather context. `[AUTO]`
5. Draft the substantive reply in house voice + propose a resolution/actions. `[AUTO draft]`
6. **Gate:** during the trip, **every** substantive send + every state-change is APPROVE — packaged to the cockpit (and, if the spike ships, Teams) with the send-text-first card (§9). `[APPROVE]`
7. Human approves via a signed grant → the gate executes + writes the append-only row. Edit → a new action row with the edited content-hash. Deny → hold/re-route (client sees an honest status). Client already has the acknowledgment.
8. Outcomes roll into the timezone-aware daily digest (§9), which also serves as a heartbeat.

## 7. Safety & Guardrails

- **Server-side tier + BLOCK enforcement (gate, §4.3):** classify on the *resolved* action, default-deny for AUTO; BLOCK is server-enforced; the model proposes, the server gates.
- **Prompt instruction-injection defense:** mandatory injection fences around every untrusted segment (client email/ticket bodies, prior replies) on **every** Technician prompt — the convention already used in `TicketResolutionDrafter`/`ContextBuilder`, made a requirement. An **output injection + disclosure scan** (`WikiRedactor::scan` or stricter) runs before any send/store; violations quarantine the draft out of the approve queue. **Emergency severity = max(model judgment, rule-based signals)** — client content can never *lower* severity.
- **Approval authenticity:** signed, single-use, payload-bound, identity-bound grants (extend `TacticalActionConfirmToken`); cockpit approval gets identity from the authenticated session; the in-Teams path must carry the real approver's AAD id (not the shared bot identity — psa-axy). If per-user identity can't be obtained for the Teams path before the go/no-go, that path does **not** ship.
- **Confidence floor:** combines the model's self-reported score with independent signals (no runbook match / novelty, ambiguous contact-or-asset resolution, SLA criticality) so injected "confidence: high" can't unlock AUTO.
- **Data-leakage coverage map:** every context source feeding the model is named and redaction-wired (assets, CIPP/M365, RMM, Tactical telemetry, contracts, prior tickets); the richer Technician context may need `ActionRedactor`-grade patterns, not just `WikiRedactor` (which deliberately preserves bare IDs). Client-facing output is leak-scanned (secrets + cross-tenant PII) before the approve queue; the **Teams push payload is redacted** on the way out.
- **Structural disclosure:** the disclosed-AI banner + "get a human" affordance are appended by the **sending layer** (template), not authored by the model, on every client-facing message; the pre-send scan rejects any body lacking it or signing off as a named human. Renders in **email** (primary) and the portal (visually distinct: label + not color-only).
- **Fail-closed + kill-switch** checked at the chokepoint, including in-flight (a flipped switch stops a queued approval from executing).

## 8. Emergency Model

- **Detection:** AI may raise an emergency, but the **relied-on** detector is a deterministic, non-AI scheduled sweep: any ticket past a hard age/keyword/SLA threshold with no human touch pings the configured chat regardless of AI classification. SLA thresholds read from client-contract terms.
- **Escalation:** the configured ordered chain (Sound IT: Justin → Charlie; no tertiary). Availability = manual toggle (authoritative) + presence (advisory). A no-ack interval advances the chain.
- **Both-unavailable:** no third human; the deterministic sweep keeps re-pinging and the client gets an honest max-hold message ("we're working to reach a technician"). Charlie remains the true backstop for genuine emergencies.
- **Storms:** incident grouping (same client + alert signature within ~15 min → one group) so a bad patch Tuesday is one item/one ping, not ten. Rate-limited + de-duped.

## 9. Experience & Copy

- **Client acknowledgment + AI-help choice:** plain-language, calm, *not* a chatbot greeting (DESIGN.md anti-reference). Drafted in the spec for both email + portal. The choice mechanic is a **signed one-click link** (not fragile reply-keyword parsing). **No-response default (trip):** prepare-and-hold — the AI drafts for human review, nothing substantive auto-sends; the ack makes this legible ("our assistant will prepare a draft for our team to review"). **Suppress the choice** for billing / security-incident / outage categories (a holding response instead).
- **Approval card/row:** send-text-first (the exact outgoing text at the top), then a 1–2 line "why," then collapsed context (client, SLA state, age); buttons "Send this / Edit first / Hold it"; fits a 390px screen; the word "approve" never appears without the full text above it. The **edit path** is inline (cockpit) / inline Adaptive Card field or a secure PSA deeplink (Teams).
- **Cockpit:** a purpose-built approval queue (not a filtered ticket list) — each row shows client, subject, the draft/action, age, SLA state, and approve/edit/deny without opening the ticket. It is the Teams-down safety net.
- **Digest:** operator-timezone-aware delivery; pending approvals (oldest first, age surfaced) + actions taken + emergencies fired; already-actioned items excluded; a **dead-man's-switch** (no digest = something's wrong → alert + email fallback).
- **Voice:** client-facing tone is warmer than the internal-console register; grounded per-client from ticket history; no filler/corporate hedging.
- **Deny + aging:** denied items get an honest client status; an approved send not completed within a threshold triggers an AUTO honest interim update ("still working on this").

## 10. Augment, Don't Commoditize

The AI is a disclosed *assistant*; the human stays the named owner and the client can always reach a human. The AI handles routine + grunt work and *prepares* the complex; high-touch/relationship moments route to the human; ETAs are honest. During the trip, **hold-all-sends means clients experience human-approved responses**, reinforcing "the human is the product" exactly when it's most fragile.

## 11. Volume & Scale

Incident/storm grouping (§8); batched approvals (review N low-stakes drafts at once); a volume budget surfaced in the digest (top-N by urgency, rest age visibly); the dedicated Technician queue/worker; defined interaction with the triage daily-token ceiling.

## 12. Phasing & the 30-Day Plan

- **Phase 0 — Foundation (the real critical path):** reuse the AI-actor System User; the **TechnicianActionGate** + append-only audit; the **run-state machine** + dedicated queue + idempotency; the **kill-switch** (fail-closed, chokepoint); the **config model**; corrected event seams (created + reply hook); **structural disclosure** rendering; the **redaction coverage map**. Plus **start the Teams-bridge spike** (Bot Framework).
- **Phase 1 — Safe core (ships first):** auto-ack + AI-help choice; autonomous triage/context/draft; **hold all substantive sends**; **cockpit approval** (always-on) + **Teams one-way notify** (digest + reports); injection fences + output scan. (This alone delivers safe value.)
- **Phase 2 — Emergency:** AI raise + **deterministic backstop** + configurable escalation chain + availability + storm grouping + honest max-hold.
- **Parallel — Teams approval spike → ~July 20 go/no-go:** ship in-Teams one-tap approval only if boringly reliable (incl. identity-bound signed callback); else fall back to cockpit+notify.
- **Phase 3+ (post-trip):** execution adapters with blast-radius caps; graduate auto-actions (release/allow stay APPROVE-sticky; operational-scope guards); autonomous low-risk sends; phone/real-time; client-portal AI sibling; Gas City escape-hatch.
- **Calendar:** Phases 0–2 + the soak (§13) **live and trusted before Aug 1**; the autonomy ramp continues via config during/after the trip.

## 13. Trust-Proving (before go-live)

Shadow-mode (drafts only, human reviews) proves *draft quality*; it does **not** prove *operational safety*. Add: a continuous **10–14 day unattended soak** before Aug 1 (Charlie deliberately hands-off for multi-day stretches); a **fire-drill checklist** (kill the worker → recovery + "worker down" alert reaches Teams; expire/rotate the Graph token → graceful fallback + alert; flip the kill-switch mid-run → in-flight halts); **adversarial/prompt-injection red-team** ticket bodies; the digest **dead-man's-switch**. Go/no-go gates: if any can't be demonstrated green by ~July 20, cut further (the Phase-1 safe core + a good auto-acknowledgment is the strictly-safer fallback). Queue/scheduler survivability is largely confirmed (`routes/console.php` + the `soundit-psa-queue` systemd worker) — verify under the soak.

## 14. Error Handling

AI errs in a draft → contained by APPROVE. Executed action fails → captured, ticket flagged, reported to Teams, optional honest client interim. Teams unreachable → cockpit + email fallback; never auto-escalate APPROVE→AUTO. No one approves → items wait safely (client holding-acknowledged), age into the digest, re-ping; nothing times out into an autonomous send. Emergency mis-classification → false positives cheap; false negatives caught by the deterministic sweep. Kill-switch → halts instantly incl. in-flight. Idempotency → the run is safe to re-run; actions de-duped by key. Unreadable config / unverifiable approval / unknown action → **hold for a human** (fail-closed).

## 15. Success Criteria

- Every inbound acknowledged within minutes, 24/7, with the AI-help choice.
- Routine tickets have a human-ready draft + proposed resolution/actions waiting; the human *approves*, rarely authors.
- The operator's daily obligation is a bounded, predictable window (minutes), supported by batching + the volume budget.
- **Zero** un-approved client-facing sends or client-system actions (trip).
- **Zero** missed true emergencies — guaranteed by the *deterministic* backstop, not AI judgment.
- No client interaction where the AI is mistaken for a human (structural disclosure).
- **Generality:** a different operator can stand up their own coverage profile (operators, chain, AI-actor, windows, SLAs) **without code changes.**

## 16. Out of Scope / Roadmap

Autonomous substantive replies; client-system action *execution* (all deferred past the trip, then graduated with caps); phone/real-time; client-portal AI sibling; the Gas City open-ended escalation escape-hatch.

## 17. Remaining Open Items

- **Persona name** = config (Sound IT: "Chet"); **BLOCK-list** membership; **emergency-criteria** wording; exact **max-hold** thresholds; the cockpit↔Teams approval-link UX detail. (All data/copy, not architecture.)

## 18. Testing Strategy

- **Unit (PHPUnit):** the gate (every action → correct server-side tier; default-deny; BLOCK enforced; kill-switch; fail-closed); the run-state machine + idempotency key; disclosure rendering (template-appended, present in email + portal); emergency classification + the deterministic sweep (labeled fixtures; severity = max-of-signals); config-driven coverage profiles.
- **Feature (sqlite):** lifecycle end-to-end — inbound → ack(+choice) → draft → APPROVE gate → execute-on-signed-approval → append-only audit row; "no approve → safe hold"; kill-switch halts incl. in-flight; Teams-unreachable → cockpit fallback; client-reply hook re-runs the Loop; storm grouping collapses duplicates.
- **Adversarial:** prompt-injection ticket bodies cannot mis-tier, drop disclosure, or lower emergency severity; output leak scan blocks secrets/cross-tenant PII.
- **Audit assertions:** every Technician action attributable to the AI-actor + `actor_label:'ai-technician'`, append-only.
- **Soak + fire-drills (§13)** before go-live. **QA-agent design-audit** on the cockpit + the client-facing copy.
</content>
