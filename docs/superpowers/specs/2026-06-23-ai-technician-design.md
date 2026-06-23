# The AI Technician — Design

- **Date:** 2026-06-23
- **Status:** Draft for review
- **Author:** Charlie + Claude (brainstorm)
- **Driver:** Charlie travels to Scotland **Aug 1–15, 2026** (8h ahead, tight itinerary, availability deliberately minimized). Client commitments continue. Build window: **~30 days.** This must also be a durable capability that pays dividends for scalability and quality-of-life well past the trip.

## 1. Problem & Goal

While Charlie is away, Sound PSA must deliver excellent client service with thin, unreliable human coverage:

- **Charlie** — reachable async + for true emergencies only; protecting the itinerary is a hard requirement.
- **Justin** (subcontractor) — technically capable, lacks deep client knowledge, has PSA access, and **may himself be travelling in August**. The design may *leverage* Justin but must **not depend** on him.
- **The load-bearer is automation + an AI "Technician"** that works tickets inside the PSA like a staff member, with a human as a light-touch approver and an emergency backstop.

**Goal:** a named, **disclosed** AI Technician that owns a ticket end-to-end inside Sound PSA — triage, gather context, draft the reply in-house voice, propose a resolution and any actions — taking safe steps autonomously and routing anything risky to a human for one-tap approval over Microsoft Teams, with a hard emergency path and a full audit trail.

## 2. Non-Goals (v1)

- Not a free-roaming autonomous agent with unsupervised write access to client systems (see §4).
- Not impersonating Charlie or any human (see §6 — transparency is a hard rule).
- Not handling real-time phone/voice in v1 (ticket channels only: email + portal + RMM/webhook-sourced tickets).
- Not a client-facing self-service AI in the portal (a future sibling).
- Not replacing Charlie as the client relationship owner — it **augments** him (see §10).

## 3. Locked Decisions (from the brainstorm)

| # | Decision | Choice |
|---|----------|--------|
| Coverage | Who carries the load | Automation + AI Technician; Charlie async/emergency-only; Justin opportunistic, never depended on |
| Architecture | Where it runs | **PSA-native** pipeline; Teams as the human-in-the-loop. (Not a Gas City instance — see §3.1) |
| Autonomy | Default stance | **Tiered**: AUTO on safe/reversible/draft work; APPROVE on anything client-facing or state-changing; BLOCK on never-allowed |
| Disclosure | Client-facing identity | **Transparent.** Disclosed AI assistant; client may opt to let the AI help now or wait for a human. Never pretend to be a human. |
| Emergency | Detection + routing | AI judges; escalate **Justin → Charlie** via the shared "Day to Day" Teams chat; availability-aware |
| Trust ramp | Rollout posture | Start with **every** client-system change requiring approval; graduate proven low-risk reversible actions to auto over the 30 days |
| Client scope | Who it serves | **All clients** (the conservative action-tier is the safety net, not a narrow pilot) |

### 3.1 Why PSA-native, not a Gas City instance

Considered standing up a Gas City instance on prod to host autonomous agents. Rejected for the client-facing work because: (a) the top failure modes — wrong message, breaks a system — are best contained by **deterministic guardrails in the system of record**, not agent judgment; (b) it must run **unattended for two weeks** and the PSA's queue/scheduler is mature and already in prod, whereas Gas City is a dev/coordination plane with known operational friction; (c) the **30-day clock** favors reusing existing AI services + deploy pipeline; (d) baking it into the PSA keeps the capability **in the standalone-first product** (cloneable/brandable) rather than coupling autonomy to an external orchestrator. Gas City remains the right tool for **build-time orchestration** and as a **future escape-hatch** the PSA can escalate genuinely open-ended tickets to — still under the same approve-first guardrails.

## 4. Architecture

Six components; everything lives inside Sound PSA except the Teams face.

1. **Technician Loop (orchestrator)** — event-driven. Subscribes to `ticket.created`, `ticket.client_replied`, and RMM `alert→ticket` events; enqueues a job that owns that ticket through the pipeline. One ticket = one idempotent run; re-runs on new client activity.
2. **Pipeline (reuses existing AI)** — triage (`Triage` services) → gather cross-domain context (wiki/site-notes via mining, assets, contract + SLA terms, ticket history) → **"can I own this?"** assessment (routine/known-runbook vs. hands-on/complex/novel) → draft reply in house voice (`ReplyDraftService`) → propose resolution (`TicketResolutionDrafter`) → propose actions (via the assistant tool-executor pattern).
3. **Tier + Guardrail engine (safety core)** — for every proposed step, classify **AUTO** (safe/reversible) · **APPROVE** (client-facing send, status change, remote script, billing/anything touching a client system) · **BLOCK** (never), gated by a **confidence floor** (low confidence → human) and an **emergency detector**. Config-driven so the ramp (§8) is data, not code changes.
4. **Teams bridge** — extends the existing "Teammate" (Claude-in-Teams over the MCP staff surface) from *pull* to *push*: proactive approval requests + need-to-know reports into the "Day to Day" chat, and routes the human's reply (approve / edit / deny) back into the PSA to execute. Built on Graph proactive messaging + an approval callback (MCP tool or signed webhook).
5. **"Needs-you" cockpit + daily digest** — a mobile-friendly in-app queue of items awaiting a human (a filtered ticket view + an `awaiting_approval` state) and one daily Teams digest, so the human window is bounded and predictable.
6. **Audit + kill-switch** — every Technician action dispatched through the existing **audited action bus** as `actor_label: 'ai-technician'` (same bus triage uses as `'ai-triage'`), immutable. Global pause + per-client + per-action-type enablement flags.

### 4.1 Reused vs. net-new

- **Reuse:** `Triage/*`, `ReplyDraftService`, `TicketResolutionDrafter`, wiki mining + `WikiRedactor`, the assistant tool-executor, the audited action bus (`actor_label`), `WhoType`, the MCP staff surface (`McpStaffController`), `GraphClient` (Teams), the Laravel queue/scheduler, the PHPUnit harness, `deploy.sh`.
- **Net-new:** the Technician Loop + orchestration job; the Tier/Guardrail engine + config; the Teams **proactive push + approval callback**; the "needs-you" cockpit + digest; the emergency tripwire + escalation router; client-facing **disclosure/choice** rendering; per-integration **action execution adapters** (quarantine release, DNS allow/block, …) — phased, propose-only until built.

## 5. Per-Ticket Lifecycle (data flow)

1. Inbound (email / portal / RMM) → ticket created (existing).
2. Loop picks it up → triage + pull the client's cross-domain context.
3. **Emergency?** → fire the tripwire (§9): urgent escalation to the Day-to-Day chat (Justin → Charlie) + post a holding response to the client. Stop autonomous progression.
4. Otherwise → **auto-acknowledge** the client with a realistic ETA **and the AI-help choice** (§6), add internal triage notes, gather context. `[AUTO]`
5. Draft the substantive reply in house voice + propose a resolution and any recommended actions. `[AUTO draft]`
6. **Gate (Tier engine):** any client-facing send / status change / remote script / billing/client-system action → package to Teams for one-tap **approve · edit · deny**, with the draft, the *why*, and the context. `[APPROVE]`
7. Human taps → PSA executes via the audited bus + logs. Edit → execute the edited version. Deny → hold / re-route. The client already has the acknowledgment, so nothing feels neglected while it waits.
8. Need-to-know outcomes (auto-acks sent, approvals pending, actions taken, escalations) roll into the daily Teams digest.

## 6. Identity & Disclosure

**Hard rule: never present as a human.** Client-facing output from the Technician is clearly a **named, disclosed AI assistant** working *for* the Sound IT team — Charlie stays the named human relationship owner.

- **Client choice.** The auto-acknowledgment offers the client a clear option: let the *specially-designed Sound IT AI assistant* help now, or wait for a human. Default-and-fallback behavior (e.g., if no response, the AI continues to *prepare* the ticket but holds substantive sends per tier; or proceeds on routine items) is a **review decision** (§13).
- **Mechanics.** Builds on the existing AI-actor structure: Technician-authored notes are `WhoType::Agent` + an **AI-authored marker** (cf. `resolution_ai_drafted`), and client-facing rendering surfaces the disclosed persona + an easy "get a human" affordance. A per-client or per-contact preference ("always route me to a human") is honored.
- **Naming** (client-facing persona name) — a branding decision for Charlie (§13).

## 7. Tier & Guardrail Engine

The safety core. Every proposed step resolves to AUTO / APPROVE / BLOCK via config keyed on **action type**, with overrides per client and a global confidence floor.

- **AUTO (v1):** acknowledge inbound (templated, non-substantive), triage/route/tag, gather context, write internal notes, **draft** the reply + proposed resolution. Reversible, non-client-facing, or non-binding.
- **APPROVE (v1 — everything below requires a human tap):** send any substantive client reply; change ticket status / mark resolved; run a remote (Tactical) script; **any** action that changes a client system or touches billing.
- **BLOCK (never, even with approval):** destructive/irreversible data operations, changing billed amounts or contract terms, anything outside the defined action vocabulary. (Exact list = review decision.)
- **Confidence floor:** if triage/draft confidence is below threshold, or the situation is novel/ambiguous, force APPROVE regardless of tier.
- **Emergency detector:** §9 overrides everything.

The **ramp** (§8) moves specific action types from APPROVE → AUTO over time; it is a config/data change, never a code change.

## 8. Trust Ramp & v1 Phasing (the 30 days)

Start maximally conservative; widen autonomy as each capability is proven. Phases map to writing-plans build phases.

- **Phase 0 — Foundation:** Technician Loop, Tier/Guardrail engine + config, audited-bus integration, kill-switch, disclosure/AI-actor rendering. No client-facing autonomy yet (shadow mode: it drafts, a human sees everything).
- **Phase 1 — Acknowledge + draft + approve loop:** auto-acknowledge with the AI-help choice; autonomous triage/context/draft; **every** substantive send + action approve-first via Teams; the "needs-you" cockpit + daily digest. (This is the safe core that ships first.)
- **Phase 2 — Emergency tripwire + escalation:** AI emergency detection; Justin → Charlie routing via the Day-to-Day chat; availability-awareness; holding responses.
- **Phase 3 — Graduate minor auto-actions:** build execution adapters + auto-approval for proven low-risk reversible actions — **spam/quarantine release, DNS allow/block** first, then widen. Each action graduates only after it is observed reliable under approval.
- **Phase 4+ (roadmap, post-trip):** graduate low-risk *replies* to autonomous send; phone/real-time; deeper actions; client-portal AI sibling; Gas City escalation escape-hatch.

**Calendar intent:** Phases 0–2 (+ the first Phase-3 actions) live and trusted **before Aug 1**; the ramp continues during/after the trip via config.

## 9. Emergency Tripwire

- **Detection:** the AI judges whether a ticket is a true emergency (e.g., outage, active security incident, VIP escalation, SLA-critical), with criteria/examples in the prompt; the confidence floor errs toward escalation.
- **Routing:** post to the shared **"Day to Day" Teams chat** (where Charlie + Justin both live near-constantly): **Justin first if available, then Charlie** after a short no-ack interval. Availability = Teams presence (Graph) with a manual override toggle (review decision §13).
- **Client side:** an immediate holding response so the client knows it's seen and being escalated.
- **Rate-limited** so a storm can't spam the chat; de-duplicated per incident.

## 10. "Augment, Don't Commoditize" (positioning)

Directly addresses the fear that excellent AI service makes clients feel they don't need Charlie.

- The AI is explicitly an **assistant to the human team**, disclosed as such; Charlie remains the named owner and the human the client can always reach.
- The AI handles the **routine and the grunt work** and *prepares* the complex; **high-touch / relationship moments stay routed to the human.**
- Tone/voice presents "Sound IT" as a capable *team* (human + AI), reinforcing that the human expertise is the product the AI scales — not a thing the AI replaces.

## 11. Error Handling & Failure Modes

- **AI errs in a draft** → contained by APPROVE: nothing client-facing or state-changing goes out without a human tap; edits are first-class.
- **An executed action fails** → captured, the ticket flagged, a report posted to Teams; never silently swallowed.
- **Teams unreachable** → approvals fall back to the in-app cockpit + email; the Technician never auto-escalates an APPROVE item to AUTO because it couldn't reach a human.
- **No one approves** (both away) → items wait safely in the cockpit/queue with the client holding-acknowledged; aging pending-approvals surface in the digest and re-ping per policy; nothing times out into an autonomous send.
- **Emergency mis-classification** → false positives are cheap (a ping); false negatives mitigated by the conservative confidence floor + SLA-overdue safety net.
- **Kill-switch** → global pause halts all autonomous progression instantly; per-client / per-action flags scope it down without a deploy.
- **Idempotency** → the Loop is safe to re-run; actions are de-duplicated; every action is attributable on the audited bus.

## 12. Success Criteria

- Every inbound ticket **acknowledged** within minutes, with the AI-help choice, 24/7.
- For routine tickets, a **human-ready draft + proposed resolution/actions** waiting before a human looks — the human *approves*, rarely authors.
- Charlie's daily obligation is a **bounded, predictable Teams window** (minutes), not a queue dive.
- **Zero** un-approved client-facing messages or client-system actions in v1.
- **Zero** missed true emergencies (escalated + human-acked).
- No client interaction where the AI is mistaken for a human.
- Durable: the same machinery keeps reducing per-ticket human effort after the trip.

## 13. Open Decisions for Review

1. **Client-choice default/fallback** — if a client doesn't answer the AI-help offer, does the Technician (a) prepare only and hold all sends, (b) proceed on routine items, or (c) per-client setting? Recommended: (b) with per-client override.
2. **Persona name** — the client-facing AI name (e.g., "Sound IT Assistant" or a given name). Charlie's branding call.
3. **BLOCK list** — confirm the never-allowed action set.
4. **Justin availability signal** — Teams presence (Graph) vs. a manual on/off toggle vs. both. Recommended: presence + manual override.
5. **Emergency criteria** — confirm the working definition/examples the AI judges against.

## 14. Out of Scope / Roadmap

Autonomous substantive replies; phone/real-time; client-portal AI sibling; the Gas City open-ended-escalation escape-hatch; deeper/irreversible actions. All gated behind earned trust.

## 15. Testing Strategy

- **Unit (PHPUnit):** the Tier/Guardrail engine (every action → correct tier, ramp config respected, confidence floor, BLOCK enforcement); disclosure/AI-actor rendering; emergency classification harness with labeled fixtures; idempotency of the Loop.
- **Feature:** the per-ticket lifecycle end-to-end on sqlite — inbound → ack(+choice) → draft → APPROVE gate → execute-on-approve → audit entry; "no approve → safe hold"; kill-switch halts progression; Teams-unreachable fallback.
- **Audited-bus assertions:** every Technician action attributable to `actor_label: 'ai-technician'` and immutable.
- **Shadow mode (pre-trip):** run Phase 1 in shadow (drafts only, human sees all) against live dev to calibrate quality/voice/tier-correctness before any client-facing send — the same calibration loop used for the QA agent.
- **QA-agent design-audit** on the new cockpit screens.
