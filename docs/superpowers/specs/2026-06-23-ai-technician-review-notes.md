# AI Technician — Spec-Review Panel Notes & Change-List (2026-06-23)

Five-lens spec-review panel on `2026-06-23-ai-technician-design.md`. All grounded in the codebase. **Unanimous verdict: PROCEED-WITH-CHANGES.** The shape + safety philosophy hold; the items below feed a v2 spec revision.

| Lens | Verdict | Sharpest point |
|------|---------|----------------|
| Architect | PROCEED-WITH-CHANGES | "Audited action bus" / event model / write-path are net-new, mislabeled as reuse |
| Security (adversarial) | PROCEED-WITH-CHANGES | Approval authenticity + prompt *instruction* injection unaddressed |
| MSP-Operator | PROCEED-WITH-CHANGES | Approval volume breaks "minutes/day"; both-humans-down emergency gap |
| UX/Client | PROCEED-WITH-CHANGES | Client copy + approval card + cockpit are unspecified |
| Skeptic/Feasibility | PROCEED-WITH-CHANGES | Teams round-trip is the #1 risk; cut to a soak-tested trip-MVP |

## Operator decisions (locked this session)
- **Approval channel:** HYBRID — cockpit-first approval + Teams one-way notify is the always-on core; spike in-Teams one-tap approval (Bot Framework + signed identity-bound callback) in parallel; **hard ~July 20 go/no-go** to ship it or fall back to cockpit+notify.
- **Trip autonomy posture:** HOLD ALL substantive client sends (only the templated acknowledgment is autonomous). Resolves the §12 vs §13.1 contradiction toward "zero un-approved sends."
- **Auto-execute:** DEFER all client-system action execution (quarantine/DNS/scripts) until post-trip; propose-only during the trip.

## MUST-FIX before build (fold into v2 spec)

**A. Real chokepoint, not a "bus" (Architect C1, Security F3/F4, Skeptic #3) — #1 item.**
Build a `TechnicianActionGate`: the SOLE entry point for every side-effecting Technician action. It (a) classifies tier **server-side on the resolved action** (default-deny: anything outside the explicit AUTO allowlist is ≥APPROVE), (b) re-checks kill-switch + per-client/per-action flags immediately before execution, (c) stamps `actor_label:'ai-technician'`, (d) writes an **append-only** audit row (copy `TacticalActionLog`'s `updating`/`deleting` guards). The Loop holds NO direct ref to `EmailService`/`TicketService`/`TacticalActionService` — only the gate. Assert by test. Reclassify as NET-NEW; promote to Phase 0.

**B. Teams push + approval = net-new (Architect C2, Security F1, Skeptic #2).**
Not "extend the Teammate." Needs Azure Bot Framework + Adaptive Cards + a signed callback. Per the hybrid decision: cockpit is the always-on approval channel (authenticated PSA session = real identity); Teams is one-way notify for v1; the in-Teams approval is the parallel spike behind the July-20 gate. Approval (in either channel) is a signed, single-use, payload-bound grant (reuse/extend `TacticalActionConfirmToken`); the model NEVER executes on seeing "approved" in text.

**C. Correct the event model (Architect H1, Skeptic).**
No `ticket.client_replied` event exists; inbound is polling; replies are notes. Wire the Loop off `TicketObserver::created` (mirroring `RunTriagePipeline::dispatch`) + a NEW reply hook in `EmailService::linkEmailToTicket` (and the portal reply path), idempotency-guarded. Define coexistence with the existing `triage:review-open` cron. Do NOT build an event bus.

**D. Prompt INSTRUCTION-injection defense (Security F2).**
Fence every untrusted client-content segment on every Technician prompt (the codebase already does this in `TicketResolutionDrafter`/`ContextBuilder` — make it a requirement). Output injection/disclosure scan (`WikiRedactor::scan`) before any send/store; quarantine on violation. **Emergency severity = max(model judgment, rule-based signals)** — client text can't lower severity.

**E. Deterministic emergency backstop + both-humans-down path (Skeptic #4, Operator C3, Architect M3).**
A non-AI scheduled sweep: any ticket past a hard age/SLA threshold with no human touch pings the Day-to-Day chat regardless of AI classification (AI may RAISE emergencies; only deterministic rules are RELIED ON). Add a **max-hold time** + an honest client "we're working to reach a technician" message + a third-tier fallback when both Justin and Charlie are unreachable.

**F. Run-state machine + idempotency (Architect H3/M1, Skeptic #4/#5).**
A persistent `technician_runs` record (gathering/drafting/awaiting_approval/executing/done). Approval waits are PERSISTED STATE, never a sleeping job. Idempotency key (unique `ticket_id + action_type + content_hash`). `awaiting_approval` lives on the run, NOT on `TicketStatus`. Dedicated queue/worker so a backlog can't starve billing/email jobs. Kill-switch checked at the execution chokepoint (incl. in-flight); **fail-closed** everywhere.

**G. AI-Technician system user (Architect H2).** Provision a dedicated user (cf. `TriageConfig::systemUserId()`) for note attribution. Phase-0 primitive.

**H. Volume + storms (Operator C1/C4, UX gap 4).** Incident grouping (same client + alert signature within ~15 min → one group). Batched approvals (review N low-stakes drafts at once). A volume budget surfaced in the digest. Storm dedup is Phase 0/1, not later.

**I. Data-leakage coverage map + output scan (Security F5, Architect L3).** Name every context source the Technician feeds the model (assets, CIPP/M365, RMM, Tactical telemetry, contracts, prior tickets) and require input redaction on each; the richer context may need `ActionRedactor`-grade patterns, not just `WikiRedactor`. Scan client-facing output for secrets + cross-tenant PII. Redact the Teams-push payload too.

**J. Structural disclosure (Security F6, UX C4).** The disclosed-AI banner + "get a human" affordance are appended by the SENDING layer (template), not authored by the model, on every client-facing message; pre-send scan rejects any body lacking it or signing as a named human. Must render correctly in EMAIL (primary channel), not just the portal; AI-authored portal messages visually distinct (label + not color-only).

**K. Experience + copy (UX C1/C2/C5/C6/C7/C8, Operator C2/C5/C6).** Spec must include: the client acknowledgment + AI-help-choice copy (email + portal) and the reliable choice mechanic (signed link, not reply-keyword parsing); the canonical approval-card structure (send-text-first, fits a 390px screen, "Send this / Edit first / Hold it"); the edit path; the deny consequence (client side); digest timing (operator-timezone-aware) + de-dup of already-actioned items; the cockpit as a purpose-built approval queue (not a filtered ticket list); client-facing voice (warmer than the internal-console register); aging/interim-update behavior; suppress the AI-help choice for billing/security/outage categories.

**L. MSP-ops realities (Operator).** SLA-contract response timers feed the deterministic backstop; an on-call calendar for Justin (vs presence-guessing); a vendor-escalation draft path; state credential-store interaction (defer but acknowledge); after-hours ack language (next-business-day window); ticket-history/known-issue pattern matching to fast-track drafts; pending-client follow-up (in/out of scope).

**M. Phase-3 caps (Security F8).** When auto-actions eventually graduate: per-action blast-radius caps, no bulk release, DNS-allow stays APPROVE-sticky, operational-scope guards (no prospect/out-of-scope clients). All post-trip.

## Trust-proving (Skeptic #5) — adopt
Shadow-mode proves draft QUALITY, not trip SAFETY. Add: a continuous 10–14 day **unattended soak** before Aug 1; a **fire-drill checklist** (kill the worker → recovery + alert; expire the Graph token → graceful fallback; flip the kill-switch mid-run → in-flight halts); adversarial/injection ticket red-team; a **dead-man's-switch on the digest** (if no digest arrives, something's wrong). (Queue/scheduler survivability is largely confirmed — `routes/console.php` + the `soundit-psa-queue` systemd worker — but verify under the soak.)

## Open items needing Charlie's input (for v2)
- Third-tier emergency fallback (peer-MSP/mutual-aid? paid backfill? Charlie as final backstop + honest hold message?) + max-hold threshold.
- Justin's known available/unavailable dates for the on-call calendar.
- Which clients have contracted response-time SLAs (feeds the deterministic timers).
- (Still open from §13) persona name; client-choice default (now: hold-and-prepare for the trip); BLOCK-list membership; emergency criteria wording.
